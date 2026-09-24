<?php
/**
 * Fuente única para la DIOT: facturas_pagos_detalles.
 * Cada renglón del detalle representa una aplicación fiscal de pago.
 */


/**
 * Factor para expresar importes del CFDI/documento relacionado en MXN.
 * No modifica la información guardada; la conversión se hace al vuelo.
 *
 * Para PUE, moneda_dr/moneda_factura corresponde al CFDI y se usa tc_factura.
 * Para PPD, los importes fiscales del detalle están expresados en MonedaDR.
 * Si la factura tiene tc_banco_real capturado (>0), ese TC real de pago tiene
 * prioridad para DIOT; en caso contrario se conserva el TC fiscal existente
 * del detalle/factura (tc_factura / tc_xml_factura).
 */
function diot_factor_mxn($moneda, $tipoCambio): float {
    $moneda = strtoupper(trim((string)$moneda));
    if ($moneda === '' || $moneda === 'MXN' || $moneda === 'XXX') return 1.0;

    $tc = (float)$tipoCambio;
    return $tc > 0 ? $tc : 1.0;
}

function diot_periodo_limites(int $anio, int $mes): array {
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        throw new InvalidArgumentException('Periodo DIOT inválido.');
    }
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-d', strtotime($inicio . ' +1 month'));
    return [$inicio, $fin];
}

function diot_resumen_desde_detalles(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    // El RFC de la empresa se obtiene una sola vez. Antes se hacía JOIN a empresas
    // y se comparaban RFC con UPPER/TRIM/COLLATE por cada renglón del detalle.
    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') {
        return [];
    }

    /*
     * Fuente de la DIOT: cada aplicación fiscal registrada en
     * facturas_pagos_detalles cuya fecha_aplicacion_fiscal pertenezca
     * al periodo solicitado.
     *
     * No se vuelve a tomar la fecha de emisión de la factura ni se exige
     * que el detalle esté conciliado con CONTPAQ. El detalle ya contiene
     * el importe pagado y el desglose proporcional de impuestos.
     *
     * Se incluyen todos los egresos (facturas recibidas) y se acumulan
     * por emisor/proveedor.
     */
    // Los importes de facturas_pagos_detalles conservan la moneda del documento
    // relacionado. Para la DIOT se convierten a MXN al vuelo antes de agrupar.
    // La moneda fiscal del detalle es MonedaDR; si no existe, se usa moneda_factura/factura.
    $factorMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(NULLIF(d.moneda_dr,''),NULLIF(d.moneda_factura,''),NULLIF(f.moneda,''),'MXN'))) IN ('MXN','XXX') THEN 1
        ELSE COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)
    END)";

    // Proporción efectivamente pagada de la factura. Se usa sólo para calcular
    // el residuo Subtotal - bases cuando el emisor tiene activa la regla especial.
    $proporcionPagada = "(CASE
        WHEN COALESCE(d.proporcion_aplicada,0) > 0 THEN LEAST(1,GREATEST(0,d.proporcion_aplicada))
        WHEN COALESCE(f.total_xml,0) > 0 THEN LEAST(1,GREATEST(0,COALESCE(d.importe_aplicado,d.monto_pagado,0) / f.total_xml))
        ELSE 1
    END)";
    $residuoBaseNoObjeto = "GREATEST(0,
        COALESCE(f.subtotal,f.subtotal_xml,0) * {$proporcionPagada}
        - (COALESCE(d.base_iva_16,0)+COALESCE(d.base_iva_8,0)+COALESCE(d.base_tasa_0,0)+COALESCE(d.base_exento,0)+COALESCE(d.base_no_objeto,0))
    )";

    $sql = "SELECT
                f.id_emisor,
                e.rfc AS rfc,
                MAX(e.nombre) AS nombre,
                MAX(COALESCE(NULLIF(e.tipo_tercero,''),
                    CASE WHEN e.rfc='XEXX010101000' THEN '05' ELSE '04' END)) AS tipo_tercero,
                MAX(COALESCE(NULLIF(e.tipo_operacion,''),'85')) AS tipo_operacion,
                MAX(e.num_id_fiscal) AS num_id_fiscal,
                MAX(e.nombre_extranjero) AS nombre_extranjero,
                MAX(e.pais_residencia) AS pais_residencia,
                MAX(e.nacionalidad) AS nacionalidad,
                CASE
                    WHEN SUM(CASE WHEN COALESCE(NULLIF(f.efecto_fiscal_diot,''),'01')='01' THEN 1 ELSE 0 END) > 0
                    THEN '01' ELSE '02'
                END AS efecto_fiscal_diot,
                SUM(COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$factorMxn}) AS total_aplicado,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.base_iva_16,0) * {$factorMxn}
                         ELSE COALESCE(d.base_iva_16,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 END) AS b16,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.iva_16,0) * {$factorMxn} ELSE 0 END) AS iva16_directo,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=1
                         THEN COALESCE(d.iva_16,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 ELSE 0 END) AS iva16_prop_acred,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=1
                         THEN COALESCE(d.iva_16,0) * {$factorMxn} * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100 ELSE 0 END) AS iva16_no_acred_prop,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.iva_16,0) * {$factorMxn}
                         ELSE COALESCE(d.iva_16,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 END) AS iva16,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.base_iva_8,0) * {$factorMxn}
                         ELSE COALESCE(d.base_iva_8,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 END) AS b08,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.iva_8,0) * {$factorMxn} ELSE 0 END) AS iva08_directo,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=1
                         THEN COALESCE(d.iva_8,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 ELSE 0 END) AS iva08_prop_acred,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=1
                         THEN COALESCE(d.iva_8,0) * {$factorMxn} * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100 ELSE 0 END) AS iva08_no_acred_prop,
                SUM(CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=0
                         THEN COALESCE(d.iva_8,0) * {$factorMxn}
                         ELSE COALESCE(d.iva_8,0) * {$factorMxn} * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100 END) AS iva08,
                SUM(CASE WHEN COALESCE(e.aplica_diferencia_base_no_objeto,0)=1
                         THEN 0
                         ELSE COALESCE(d.base_tasa_0,0) * {$factorMxn}
                    END) AS b00,
                SUM(COALESCE(d.base_exento,0) * {$factorMxn}) AS b_exe,
                SUM(
                    COALESCE(d.base_no_objeto,0) * {$factorMxn}
                    + CASE WHEN COALESCE(e.aplica_diferencia_base_no_objeto,0)=1
                           THEN COALESCE(d.base_tasa_0,0) * {$factorMxn}
                           ELSE 0 END
                    + CASE WHEN COALESCE(f.iva_tratamiento_especial_diot,0)=1
                           THEN (COALESCE(d.base_iva_16,0)+COALESCE(d.base_iva_8,0)) * {$factorMxn}
                                * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100
                           ELSE 0 END
                    + CASE WHEN COALESCE(e.aplica_diferencia_base_no_objeto,0)=1
                           THEN ({$residuoBaseNoObjeto}) * {$factorMxn}
                           ELSE 0 END
                ) AS b_no_obj,
                SUM(COALESCE(d.iva_retenido,0) * {$factorMxn}) AS ret_iva,
                SUM(COALESCE(d.isr_retenido,0) * {$factorMxn}) AS ret_isr,
                SUM(COALESCE(d.ieps_otros,0) * {$factorMxn}) AS ieps_otros,
                COUNT(*) AS movimientos
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f
                    ON f.id_empresa=d.id_empresa
                   AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp
                    ON fp.id_empresa=d.id_empresa
                   AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            WHERE d.id_empresa=:id_empresa
              AND d.fecha_aplicacion_fiscal >= :inicio
              AND d.fecha_aplicacion_fiscal < :fin
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              -- DIOT: la fecha fiscal manda.
              -- Es egreso cuando el RFC receptor corresponde a la empresa activa.
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              -- Solo XML vigentes. Se acepta '1' por compatibilidad con registros históricos.
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              -- Exclusiones manuales de DIOT.
              AND COALESCE(f.excluir_diot,0)=0
              -- El proveedor/emisor debe estar habilitado para DIOT.
              AND COALESCE(e.aplica_diot,0)=1
            GROUP BY f.id_emisor,e.rfc
            HAVING ABS(SUM(COALESCE(d.base_iva_16,0) * {$factorMxn})) > 0.0001
                OR ABS(SUM(COALESCE(d.base_iva_8,0) * {$factorMxn})) > 0.0001
                OR ABS(SUM(COALESCE(d.base_tasa_0,0) * {$factorMxn})) > 0.0001
                OR ABS(SUM(COALESCE(d.base_exento,0) * {$factorMxn})) > 0.0001
                OR ABS(SUM(COALESCE(d.base_no_objeto,0) * {$factorMxn}
                    + CASE WHEN COALESCE(e.aplica_diferencia_base_no_objeto,0)=1 THEN ({$residuoBaseNoObjeto}) * {$factorMxn} ELSE 0 END)) > 0.0001
                OR ABS(SUM(COALESCE(d.iva_retenido,0) * {$factorMxn})) > 0.0001
                OR ABS(SUM(COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$factorMxn})) > 0.0001
            ORDER BY e.rfc ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa' => $idEmpresa,
        ':inicio' => $inicio,
        ':fin' => $fin,
        ':rfc_empresa' => $rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Detalle de pagos que forman parte de la DIOT del periodo seleccionado,
 * pero cuya factura fue emitida ANTES del inicio de ese periodo.
 *
 * Sirve como conciliacion visual para explicar facturas de meses anteriores
 * que aparecen en la DIOT actual porque su fecha de aplicacion fiscal cae
 * dentro del mes solicitado.
 */
function diot_pagos_facturas_anteriores(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];

    $factorMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(NULLIF(d.moneda_dr,''),NULLIF(d.moneda_factura,''),NULLIF(f.moneda,''),'MXN'))) IN ('MXN','XXX') THEN 1
        ELSE COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)
    END)";

    $sql = "SELECT
                e.rfc AS rfc,
                e.nombre AS nombre,
                f.serie,
                f.folio,
                f.uuid AS uuid_factura,
                f.fecha_emision,
                f.metodo_pago,
                f.moneda,
                d.uuid_pago,
                d.parcialidad,
                d.fecha_pago_sat,
                d.fecha_aplicacion_fiscal,
                d.forma_pago,
                COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$factorMxn} AS pago_mxn,
                COALESCE(d.base_iva_16,0) * {$factorMxn} AS base_iva_16_mxn,
                COALESCE(d.iva_16,0) * {$factorMxn} AS iva_16_mxn,
                COALESCE(d.base_iva_8,0) * {$factorMxn} AS base_iva_8_mxn,
                COALESCE(d.iva_8,0) * {$factorMxn} AS iva_8_mxn,
                COALESCE(d.base_tasa_0,0) * {$factorMxn} AS tasa_0_mxn,
                COALESCE(d.base_exento,0) * {$factorMxn} AS exento_mxn,
                COALESCE(d.base_no_objeto,0) * {$factorMxn} AS no_objeto_mxn,
                COALESCE(d.iva_retenido,0) * {$factorMxn} AS iva_retenido_mxn,
                COALESCE(d.isr_retenido,0) * {$factorMxn} AS isr_retenido_mxn,
                COALESCE(d.ieps_otros,0) * {$factorMxn} AS ieps_mxn
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f
                    ON f.id_empresa=d.id_empresa
                   AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp
                   ON fp.id_empresa=d.id_empresa
                  AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            WHERE d.id_empresa=:id_empresa
              AND d.fecha_aplicacion_fiscal>=:inicio
              AND d.fecha_aplicacion_fiscal<:fin
              AND f.fecha_emision<:inicio_factura
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
            ORDER BY d.fecha_aplicacion_fiscal ASC, f.fecha_emision ASC, e.rfc ASC, f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa'=>$idEmpresa,
        ':inicio'=>$inicio,
        ':fin'=>$fin,
        ':inicio_factura'=>$inicio,
        ':rfc_empresa'=>$rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * CFDI recibidos emitidos dentro del mes solicitado que al cierre de la
 * periodo solicitado aun conservaban saldo pendiente. Se calcula lo pagado
 * desde los detalles fiscales vigentes para no depender de un indicador
 * manual y se presenta como apoyo de conciliacion (cheques/pagos en transito).
 */
function diot_cfdi_mes_no_pagados(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];

    $factorFacturaMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(f.moneda,'MXN'))) IN ('MXN','XXX','') THEN 1
        ELSE COALESCE(NULLIF(f.tc_xml_factura,0),1)
    END)";

    $sql = "SELECT
                e.rfc AS rfc,
                e.nombre AS nombre,
                f.serie,
                f.folio,
                f.uuid,
                f.fecha_emision,
                f.metodo_pago,
                f.forma_pago,
                f.moneda,
                COALESCE(NULLIF(f.tc_xml_factura,0),1) AS tipo_cambio,
                f.total_xml,
                COALESCE(p.total_pagado,0) AS total_pagado,
                GREATEST(0, COALESCE(f.total_xml,0)-COALESCE(p.total_pagado,0)) AS saldo_pendiente,
                COALESCE(f.base_iva,0) AS base_iva,
                COALESCE(f.iva_16,0) AS iva_16,
                COALESCE(f.iva_fronterizo_xml,0) AS iva_8,
                COALESCE(f.iva_retenido,0) AS iva_retenido,
                COALESCE(f.isr_retenido,0) AS isr_retenido,
                COALESCE(f.total_xml,0) * {$factorFacturaMxn} AS total_mxn,
                COALESCE(p.total_pagado,0) * {$factorFacturaMxn} AS pagado_mxn,
                GREATEST(0, COALESCE(f.total_xml,0)-COALESCE(p.total_pagado,0)) * {$factorFacturaMxn} AS saldo_mxn
            FROM facturas f
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            LEFT JOIN (
                SELECT d.id_empresa,d.uuid_relacionado,
                       SUM(COALESCE(d.importe_aplicado,d.monto_pagado,0)) AS total_pagado
                FROM facturas_pagos_detalles d
                LEFT JOIN facturas fp
                       ON fp.id_empresa=d.id_empresa
                      AND fp.uuid=d.uuid_pago
                WHERE d.id_empresa=:id_empresa_det
                  AND d.fecha_aplicacion_fiscal<:fin_pago
                  AND COALESCE(d.es_sustituido,0)=0
                  AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
                GROUP BY d.id_empresa,d.uuid_relacionado
            ) p ON p.id_empresa=f.id_empresa AND p.uuid_relacionado=f.uuid
            WHERE f.id_empresa=:id_empresa
              AND f.fecha_emision>=:inicio
              AND f.fecha_emision<:fin
              AND UPPER(COALESCE(f.id_tipo_comprobante,'')) NOT IN ('P','N','T')
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
              -- Los CFDI de importe cero no representan un saldo pendiente real.
              AND (COALESCE(f.total_xml,0) * {$factorFacturaMxn}) > 0.0001
              /*
               * Si ya existe una aplicacion fiscal posterior al cierre del mes,
               * el CFDI deja de mostrarse en este bloque. Se explicara unicamente
               * en FACTURAS DEL MES DIOT PAGADAS EN FECHA POSTERIOR.
               */
              AND NOT EXISTS (
                    SELECT 1
                    FROM facturas_pagos_detalles dp
                    LEFT JOIN facturas fpp
                           ON fpp.id_empresa=dp.id_empresa
                          AND fpp.uuid=dp.uuid_pago
                    WHERE dp.id_empresa=f.id_empresa
                      AND dp.uuid_relacionado=f.uuid
                      AND dp.fecha_aplicacion_fiscal>=:fin_posterior
                      AND COALESCE(dp.es_sustituido,0)=0
                      AND (dp.es_sintetico=1 OR UPPER(TRIM(COALESCE(fpp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
                      AND (dp.es_sintetico=1 OR COALESCE(fpp.excluir_diot,0)=0)
              )
              -- Si existe una Fecha Efectiva de Pago manual, el CFDI ya no es
              -- un pendiente real. Si cae en el mismo periodo entra a la DIOT; si
              -- cae después, se explica en el bloque de pago posterior sin REP.
              AND f.fecha_pago_usuario IS NULL
              /*
               * Tolerancia del resumen de pendientes DIOT:
               * - Sin abonos: siempre se considera pendiente.
               * - Con al menos un abono: solo se considera pendiente cuando
               *   la diferencia restante, convertida a MXN, es MAYOR a $10.00.
               * Esto evita falsos pendientes por centavos/redondeos (ej. CFE $0.09).
               */
              AND (
                    COALESCE(p.total_pagado,0) <= 0
                    OR (GREATEST(0, COALESCE(f.total_xml,0)-COALESCE(p.total_pagado,0)) * {$factorFacturaMxn}) > 10.00
                  )
            ORDER BY f.fecha_emision ASC,e.rfc ASC,f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa_det'=>$idEmpresa,
        ':fin_pago'=>$fin,
        ':id_empresa'=>$idEmpresa,
        ':inicio'=>$inicio,
        ':fin'=>$fin,
        ':fin_posterior'=>$fin,
        ':rfc_empresa'=>$rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Detalles de pago de CFDI emitidos DENTRO del mes DIOT cuya fecha de
 * aplicacion fiscal cae DESPUES de terminar ese mes.
 *
 * Este bloque permite explicar facturas del mes que no formaron parte de la
 * DIOT de ese mismo periodo, pero que ya aparecen pagadas en un mes posterior.
 */
function diot_cfdi_mes_pagados_posterior(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];

    $factorMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(NULLIF(d.moneda_dr,''),NULLIF(d.moneda_factura,''),NULLIF(f.moneda,''),'MXN'))) IN ('MXN','XXX') THEN 1
        ELSE COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)
    END)";

    $sql = "SELECT
                e.rfc AS rfc,
                e.nombre AS nombre,
                f.serie,
                f.folio,
                f.uuid AS uuid_factura,
                f.fecha_emision,
                f.metodo_pago,
                f.moneda,
                d.uuid_pago,
                d.parcialidad,
                d.fecha_pago_sat,
                d.fecha_aplicacion_fiscal,
                d.forma_pago,
                COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$factorMxn} AS pago_mxn,
                COALESCE(d.base_iva_16,0) * {$factorMxn} AS base_iva_16_mxn,
                COALESCE(d.iva_16,0) * {$factorMxn} AS iva_16_mxn,
                COALESCE(d.base_iva_8,0) * {$factorMxn} AS base_iva_8_mxn,
                COALESCE(d.iva_8,0) * {$factorMxn} AS iva_8_mxn,
                COALESCE(d.base_tasa_0,0) * {$factorMxn} AS tasa_0_mxn,
                COALESCE(d.base_exento,0) * {$factorMxn} AS exento_mxn,
                COALESCE(d.base_no_objeto,0) * {$factorMxn} AS no_objeto_mxn,
                COALESCE(d.iva_retenido,0) * {$factorMxn} AS iva_retenido_mxn,
                COALESCE(d.isr_retenido,0) * {$factorMxn} AS isr_retenido_mxn,
                COALESCE(d.ieps_otros,0) * {$factorMxn} AS ieps_mxn
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f
                    ON f.id_empresa=d.id_empresa
                   AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp
                   ON fp.id_empresa=d.id_empresa
                  AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            WHERE d.id_empresa=:id_empresa
              AND f.fecha_emision>=:inicio_factura
              AND f.fecha_emision<:fin_factura
              AND d.fecha_aplicacion_fiscal>=:fin_periodo
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
            ORDER BY d.fecha_aplicacion_fiscal ASC, e.rfc ASC, f.fecha_emision ASC, f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa'=>$idEmpresa,
        ':inicio_factura'=>$inicio,
        ':fin_factura'=>$fin,
        ':fin_periodo'=>$fin,
        ':rfc_empresa'=>$rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * CFDI emitidos dentro del mes DIOT que tienen una fecha de pago capturada
 * por el usuario en un mes posterior, pero todavia NO cuentan con un
 * complemento de pago/REP fiscal relacionado para esa fecha posterior.
 *
 * Se muestran aparte de los pendientes reales y aparte de los pagos
 * posteriores con REP. Cuando llegue el complemento y se procese, el CFDI
 * deja de cumplir esta condicion y pasa al bloque de pagos posteriores.
 */
function diot_cfdi_mes_pago_usuario_sin_rep(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];

    $factorFacturaMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(f.moneda,'MXN'))) IN ('MXN','XXX','') THEN 1
        ELSE COALESCE(NULLIF(f.tc_xml_factura,0),1)
    END)";

    $sql = "SELECT
                e.rfc AS rfc,
                e.nombre AS nombre,
                f.serie,
                f.folio,
                f.uuid AS uuid_factura,
                f.fecha_emision,
                f.metodo_pago,
                f.forma_pago,
                f.moneda,
                COALESCE(NULLIF(f.tc_xml_factura,0),1) AS tipo_cambio,
                f.total_xml,
                f.fecha_pago_usuario,
                COALESCE(f.total_xml,0) * {$factorFacturaMxn} AS total_mxn,
                COALESCE(f.base_iva,0) * {$factorFacturaMxn} AS base_iva_16_mxn,
                COALESCE(f.iva_16,0) * {$factorFacturaMxn} AS iva_16_mxn,
                COALESCE(f.iva_fronterizo_xml,0) * {$factorFacturaMxn} AS iva_8_mxn,
                COALESCE(f.iva_retenido,0) * {$factorFacturaMxn} AS iva_retenido_mxn,
                COALESCE(f.isr_retenido,0) * {$factorFacturaMxn} AS isr_retenido_mxn
            FROM facturas f
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            WHERE f.id_empresa=:id_empresa
              AND f.fecha_emision>=:inicio_factura
              AND f.fecha_emision<:fin_factura
              AND f.fecha_pago_usuario>=:fin_periodo
              AND UPPER(COALESCE(f.id_tipo_comprobante,'')) NOT IN ('P','N','T')
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
              AND (COALESCE(f.total_xml,0) * {$factorFacturaMxn}) > 0.0001
              -- Si ya existe un detalle fiscal posterior vigente, ya tiene REP
              -- (o un detalle fiscal equivalente) y pertenece al bloque 3.
              AND NOT EXISTS (
                    SELECT 1
                    FROM facturas_pagos_detalles d
                    LEFT JOIN facturas fp
                           ON fp.id_empresa=d.id_empresa
                          AND fp.uuid=d.uuid_pago
                    WHERE d.id_empresa=f.id_empresa
                      AND d.uuid_relacionado=f.uuid
                      AND d.fecha_aplicacion_fiscal>=:fin_detalle
                      AND COALESCE(d.es_sustituido,0)=0
                      AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              )
            ORDER BY f.fecha_pago_usuario ASC,e.rfc ASC,f.fecha_emision ASC,f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa'=>$idEmpresa,
        ':inicio_factura'=>$inicio,
        ':fin_factura'=>$fin,
        ':fin_periodo'=>$fin,
        ':fin_detalle'=>$fin,
        ':rfc_empresa'=>$rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Detalle de aplicaciones fiscales incluidas en la DIOT cuya factura/documento
 * relacionado está expresado en moneda extranjera. Permite explicar de forma
 * transparente qué importes originales se convirtieron a MXN y con qué tipo
 * de cambio se integraron al periodo DIOT.
 */
function diot_pagos_moneda_extranjera(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];

    $monedaExpr = "UPPER(TRIM(COALESCE(NULLIF(d.moneda_dr,''),NULLIF(d.moneda_factura,''),NULLIF(f.moneda,''),'MXN')))";
    $tipoCambioExpr = "COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)";
    $tipoCambioFiscalExpr = "COALESCE(NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)";

    $sql = "SELECT
                e.rfc AS rfc,
                e.nombre AS nombre,
                f.serie,
                f.folio,
                f.uuid AS uuid_factura,
                f.fecha_emision,
                f.metodo_pago,
                {$monedaExpr} AS moneda_origen,
                {$tipoCambioExpr} AS tipo_cambio_aplicado,
                {$tipoCambioFiscalExpr} AS tipo_cambio_fiscal_original,
                COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),0) AS tc_banco_real,
                CASE WHEN COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),0)>0 THEN 1 ELSE 0 END AS usa_tc_real,
                d.uuid_pago,
                d.parcialidad,
                d.fecha_pago_sat,
                d.fecha_aplicacion_fiscal,
                UPPER(TRIM(COALESCE(NULLIF(d.moneda_pago_sat,''),''))) AS moneda_pago_sat,
                COALESCE(NULLIF(d.tc_pago_sat,0),0) AS tc_pago_sat,
                COALESCE(NULLIF(d.equivalencia_dr,0),1) AS equivalencia_dr,
                COALESCE(d.importe_aplicado,d.monto_pagado,0) AS pago_moneda_origen,
                COALESCE(d.base_iva_16,0) AS base_iva_16_origen,
                COALESCE(d.iva_16,0) AS iva_16_origen,
                COALESCE(d.base_iva_8,0) AS base_iva_8_origen,
                COALESCE(d.iva_8,0) AS iva_8_origen,
                COALESCE(d.iva_retenido,0) AS iva_retenido_origen,
                COALESCE(d.isr_retenido,0) AS isr_retenido_origen,
                COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$tipoCambioExpr} AS pago_mxn,
                COALESCE(d.base_iva_16,0) * {$tipoCambioExpr} AS base_iva_16_mxn,
                COALESCE(d.iva_16,0) * {$tipoCambioExpr} AS iva_16_mxn,
                COALESCE(d.base_iva_8,0) * {$tipoCambioExpr} AS base_iva_8_mxn,
                COALESCE(d.iva_8,0) * {$tipoCambioExpr} AS iva_8_mxn,
                COALESCE(d.iva_retenido,0) * {$tipoCambioExpr} AS iva_retenido_mxn,
                COALESCE(d.isr_retenido,0) * {$tipoCambioExpr} AS isr_retenido_mxn
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f
                    ON f.id_empresa=d.id_empresa
                   AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp
                   ON fp.id_empresa=d.id_empresa
                  AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e
                    ON e.id_emisor=f.id_emisor
                   AND e.id_empresa=f.id_empresa
            INNER JOIN cat_receptores r
                    ON r.id_receptor=f.id_receptor
                   AND r.id_empresa=f.id_empresa
            WHERE d.id_empresa=:id_empresa
              AND d.fecha_aplicacion_fiscal>=:inicio
              AND d.fecha_aplicacion_fiscal<:fin
              AND {$monedaExpr} NOT IN ('','MXN','XXX')
              AND {$tipoCambioExpr} > 0
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              AND r.rfc COLLATE utf8mb4_general_ci = CONVERT(:rfc_empresa USING utf8mb4) COLLATE utf8mb4_general_ci
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
            ORDER BY e.rfc ASC, f.fecha_emision ASC, f.folio ASC, d.fecha_aplicacion_fiscal ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa'=>$idEmpresa,
        ':inicio'=>$inicio,
        ':fin'=>$fin,
        ':rfc_empresa'=>$rfcEmpresa,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Facturas con porcentaje especial de IVA que sí forman parte del periodo DIOT.
 * El XML permanece intacto; este resumen documenta el reparto acreditable/no acreditable.
 */
function diot_facturas_tratamiento_iva_especial(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);
    $factorMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(NULLIF(d.moneda_dr,''),NULLIF(d.moneda_factura,''),NULLIF(f.moneda,''),'MXN'))) IN ('MXN','XXX') THEN 1
        ELSE COALESCE(NULLIF(NULLIF(f.tc_banco_real,0),1),NULLIF(d.tc_factura,0),NULLIF(f.tc_xml_factura,0),1)
    END)";

    $sql = "SELECT
                f.uuid AS uuid_factura, e.rfc, e.nombre, f.fecha_emision, f.serie, f.folio,
                f.metodo_pago, f.moneda,
                LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) AS porcentaje_acreditable,
                COALESCE(f.iva_motivo_tratamiento_diot,'') AS motivo,
                SUM(COALESCE(d.importe_aplicado,d.monto_pagado,0) * {$factorMxn}) AS pago_mxn,
                SUM(COALESCE(d.base_iva_16,0) * {$factorMxn}) AS base_iva_16_mxn,
                SUM(COALESCE(d.iva_16,0) * {$factorMxn}) AS iva_16_xml_mxn,
                SUM(COALESCE(d.iva_16,0) * {$factorMxn}
                    * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100) AS iva_16_acreditable_mxn,
                SUM(COALESCE(d.iva_16,0) * {$factorMxn}
                    * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100) AS iva_16_no_acreditable_mxn,
                SUM(COALESCE(d.base_iva_16,0) * {$factorMxn}
                    * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100) AS base_16_no_objeto_especial_mxn,
                SUM(COALESCE(d.base_iva_8,0) * {$factorMxn}) AS base_iva_8_mxn,
                SUM(COALESCE(d.iva_8,0) * {$factorMxn}) AS iva_8_xml_mxn,
                SUM(COALESCE(d.iva_8,0) * {$factorMxn}
                    * LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100))) / 100) AS iva_8_acreditable_mxn,
                SUM(COALESCE(d.iva_8,0) * {$factorMxn}
                    * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100) AS iva_8_no_acreditable_mxn,
                SUM(COALESCE(d.base_iva_8,0) * {$factorMxn}
                    * (100-LEAST(100,GREATEST(0,COALESCE(f.iva_porcentaje_acreditable_diot,100)))) / 100) AS base_8_no_objeto_especial_mxn
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f ON f.id_empresa=d.id_empresa AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor
            WHERE d.id_empresa=:id_empresa
              AND d.fecha_aplicacion_fiscal >= :inicio AND d.fecha_aplicacion_fiscal < :fin
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
              AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
              AND COALESCE(f.excluir_diot,0)=0
              AND COALESCE(e.aplica_diot,0)=1
              AND COALESCE(f.iva_tratamiento_especial_diot,0)=1
            GROUP BY f.uuid,e.rfc,e.nombre,f.fecha_emision,f.serie,f.folio,f.metodo_pago,f.moneda,
                     f.iva_porcentaje_acreditable_diot,f.iva_motivo_tratamiento_diot
            ORDER BY e.nombre,f.fecha_emision,f.folio";
    $st=$pdo->prepare($sql);
    $st->execute([':id_empresa'=>$idEmpresa, ':inicio'=>$inicio, ':fin'=>$fin]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function diot_fila_54(array $row): array {
    $c = array_fill(1, 54, '');
    for ($i=8; $i<=53; $i++) $c[$i] = '0';

    $tipoTercero = (string)($row['tipo_tercero'] ?? '04');
    $tipoOperacion = (string)($row['tipo_operacion'] ?? '85');
    $esExtranjero = ($tipoTercero === '05');

    $c[1] = $tipoTercero;
    $c[2] = $tipoOperacion ?: '85';
    $c[3] = $esExtranjero ? '' : (string)($row['rfc'] ?? '');
    $c[4] = $esExtranjero ? (string)($row['num_id_fiscal'] ?? '') : '';
    $c[5] = $esExtranjero ? (string)(($row['nombre_extranjero'] ?? '') ?: ($row['nombre'] ?? '')) : '';
    $c[6] = $esExtranjero ? (string)($row['pais_residencia'] ?? '') : '';
    $c[7] = $esExtranjero ? (string)($row['nacionalidad'] ?? '') : '';

    // Conservamos el mapeo de 54 columnas que ya usa el visor.
    // Tratamiento especial DIOT:
    // la parte acreditable conserva su base/IVA en las columnas normales;
    // la parte NO acreditable se reclasifica como valor de actos/actividades NO OBJETO (col.52).
    // Por este criterio ya no se informa IVA no acreditable proporcional en col.28/36.
    $c[8]  = (string)round((float)($row['b08'] ?? 0));
    $c[18] = (string)round(
        (float)($row['iva08_directo'] ?? 0) +
        (float)($row['iva08_prop_acred'] ?? 0)
    );
    $c[19] = '0';
    $c[28] = '0';

    $c[12] = (string)round((float)($row['b16'] ?? 0));
    $c[22] = (string)round(
        (float)($row['iva16_directo'] ?? 0) +
        (float)($row['iva16_prop_acred'] ?? 0)
    );
    $c[23] = '0';
    $c[36] = '0';

    $c[48] = (string)round((float)($row['ret_iva'] ?? 0));
    $c[50] = (string)round((float)($row['b_exe'] ?? 0));
    $c[51] = (string)round((float)($row['b00'] ?? 0));
    $c[52] = (string)round((float)($row['b_no_obj'] ?? 0));
    $c[54] = ((string)($row['efecto_fiscal_diot'] ?? '01') === '02') ? '02' : '01';

    return $c;
}

function diot_diagnostico_periodo(PDO $pdo, int $idEmpresa, int $anio, int $mes): array {
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $stEmpresa = $pdo->prepare("SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') return [];
    $sql = "SELECT
                COUNT(*) AS total_detalles_periodo,
                SUM(CASE WHEN r.rfc COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci THEN 1 ELSE 0 END) AS receptor_empresa,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1') THEN 1 ELSE 0 END) AS xml_vigentes,
                SUM(CASE WHEN COALESCE(f.excluir_diot,0)=0 THEN 1 ELSE 0 END) AS no_excluidos,
                SUM(CASE WHEN COALESCE(e.aplica_diot,0)=1 THEN 1 ELSE 0 END) AS emisores_aplican_diot,
                COUNT(DISTINCT CASE
                    WHEN r.rfc COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci
                     AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
                     AND COALESCE(f.excluir_diot,0)=0
                     AND COALESCE(e.aplica_diot,0)=1
                    THEN f.id_emisor END) AS emisores_candidatos
            FROM facturas_pagos_detalles d
            INNER JOIN facturas f
                    ON f.id_empresa=d.id_empresa
                   AND f.uuid=d.uuid_relacionado
            LEFT JOIN facturas fp
                    ON fp.id_empresa=d.id_empresa
                   AND fp.uuid=d.uuid_pago
            INNER JOIN cat_emisores e
                    ON e.id_empresa=f.id_empresa
                   AND e.id_emisor=f.id_emisor
            INNER JOIN cat_receptores r
                    ON r.id_empresa=f.id_empresa
                   AND r.id_receptor=f.id_receptor
            WHERE d.id_empresa=?
              AND d.fecha_aplicacion_fiscal>=?
              AND d.fecha_aplicacion_fiscal<?
              AND COALESCE(d.es_sustituido,0)=0
              AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
              -- Si el detalle viene de un REP/complemento real, también respeta su exclusión manual de DIOT.
              AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)";
    $st=$pdo->prepare($sql);
    $st->execute([$rfcEmpresa,$rfcEmpresa,$idEmpresa,$inicio,$fin]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

?>
