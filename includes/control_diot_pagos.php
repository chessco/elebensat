<?php
/**
 * Control fiscal de pagos para DIOT.
 * La DIOT se alimenta de facturas_pagos_detalles.
 */

function diot_fecha($valor) {
    if (!$valor || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') return null;
    return substr((string)$valor, 0, 10);
}

function diot_foto_factura(PDO $pdo, $uuid, $idEmpresa) {
    $st = $pdo->prepare("SELECT f.uuid,f.id_empresa,f.id_emisor,e.rfc AS rfc_emisor,
                                f.total_xml,f.subtotal_xml,f.base_iva,f.iva_16,f.iva_8,
                                f.iva_exento,f.subtotal_no_objeto,f.iva_retenido,f.isr_retenido,f.ieps_otros,
                                f.base_iva_fronteriza_xml,f.forma_pago,f.fecha_emision,f.metodo_pago,f.moneda,f.tc_xml_factura
                         FROM facturas f
                         LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
                         WHERE f.uuid=? AND f.id_empresa=? LIMIT 1");
    $st->execute([$uuid,$idEmpresa]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function diot_prorratear_factura(array $f, $monto) {
    $total = (float)($f['total_xml'] ?? 0);
    $ratio = $total > 0 ? max(0, min(1, (float)$monto / $total)) : 0;
    $base8 = (float)($f['base_iva_fronteriza_xml'] ?? 0);
    $baseIva = (float)($f['base_iva'] ?? 0);
    $base16 = max(0, $baseIva - $base8);
    $exento = (float)($f['iva_exento'] ?? 0);
    $noObj = (float)($f['subtotal_no_objeto'] ?? 0);
    $subtotal = (float)($f['subtotal_xml'] ?? 0);
    $base0 = max(0, $subtotal - $base16 - $base8 - $exento - $noObj);

    return [
        'proporcion_aplicada'=>$ratio,
        'base_iva_16'=>$base16*$ratio,
        'iva_16'=>(float)($f['iva_16'] ?? 0)*$ratio,
        'base_iva_8'=>$base8*$ratio,
        'iva_8'=>(float)($f['iva_8'] ?? 0)*$ratio,
        'base_tasa_0'=>$base0*$ratio,
        'base_exento'=>$exento*$ratio,
        'base_no_objeto'=>$noObj*$ratio,
        'iva_retenido'=>(float)($f['iva_retenido'] ?? 0)*$ratio,
        'isr_retenido'=>(float)($f['isr_retenido'] ?? 0)*$ratio,
        'ieps_otros'=>(float)($f['ieps_otros'] ?? 0)*$ratio,
    ];
}

function diot_extraer_impuestos_dr($docto, ?array $factura, $monto) {
    $r = $factura ? diot_prorratear_factura($factura, $monto) : [
        'proporcion_aplicada'=>0,'base_iva_16'=>0,'iva_16'=>0,'base_iva_8'=>0,'iva_8'=>0,
        'base_tasa_0'=>0,'base_exento'=>0,'base_no_objeto'=>0,'iva_retenido'=>0,'isr_retenido'=>0,'ieps_otros'=>0
    ];

    if (!isset($docto->ImpuestosDR)) return $r;

    $encontroTraslado = false;
    if (isset($docto->ImpuestosDR->TrasladosDR->TrasladoDR)) {
        foreach ($docto->ImpuestosDR->TrasladosDR->TrasladoDR as $t) {
            $imp = (string)($t['ImpuestoDR'] ?? '');
            if ($imp !== '002') continue;
            $tipo = strtoupper((string)($t['TipoFactorDR'] ?? ''));
            $tasa = (float)($t['TasaOCuotaDR'] ?? 0);
            $base = (float)($t['BaseDR'] ?? 0);
            $importe = (float)($t['ImporteDR'] ?? 0);
            if ($tipo === 'EXENTO') {
                $r['base_exento'] = $base;
                $encontroTraslado = true;
            } elseif (abs($tasa-0.16) < 0.0002) {
                $r['base_iva_16']=$base; $r['iva_16']=$importe; $encontroTraslado=true;
            } elseif (abs($tasa-0.08) < 0.0002) {
                $r['base_iva_8']=$base; $r['iva_8']=$importe; $encontroTraslado=true;
            } elseif (abs($tasa) < 0.000001) {
                $r['base_tasa_0']=$base; $encontroTraslado=true;
            }
        }
    }

    if (isset($docto->ImpuestosDR->RetencionesDR->RetencionDR)) {
        $r['iva_retenido']=0; $r['isr_retenido']=0; $r['ieps_otros']=0;
        foreach ($docto->ImpuestosDR->RetencionesDR->RetencionDR as $t) {
            $imp = (string)($t['ImpuestoDR'] ?? '');
            $importe=(float)($t['ImporteDR'] ?? 0);
            if ($imp==='002') $r['iva_retenido'] += $importe;
            elseif ($imp==='001') $r['isr_retenido'] += $importe;
            elseif ($imp==='003') $r['ieps_otros'] += $importe;
        }
    }
    return $r;
}

function diot_upsert_detalle(PDO $pdo, array $d) {
    $sql = "INSERT INTO facturas_pagos_detalles
        (id_empresa,id_emisor,rfc_emisor,uuid_pago,uuid_relacionado,monto_pagado,importe_aplicado,parcialidad,
         saldo_anterior,saldo_insoluto,clave_detalle,aplicado,origen_pago,es_sintetico,
         moneda_factura,tc_factura,moneda_pago_sat,tc_pago_sat,moneda_dr,equivalencia_dr,
         fecha_pago_sat,fecha_pago_banco,fecha_pago_contpaq,moneda_contpaq,tc_contpaq,monto_conciliado_contpaq,saldo_por_conciliar,estatus_conciliacion_contpaq,
         fecha_pago_usuario,fecha_aplicacion_fiscal,forma_pago,referencia,proporcion_aplicada,
         base_iva_16,iva_16,base_iva_8,iva_8,base_tasa_0,base_exento,base_no_objeto,
         iva_retenido,isr_retenido,ieps_otros)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         id_empresa=VALUES(id_empresa),id_emisor=VALUES(id_emisor),rfc_emisor=VALUES(rfc_emisor),uuid_relacionado=VALUES(uuid_relacionado),
         monto_pagado=VALUES(monto_pagado),importe_aplicado=VALUES(importe_aplicado),
         parcialidad=VALUES(parcialidad),saldo_anterior=VALUES(saldo_anterior),
         saldo_insoluto=VALUES(saldo_insoluto),origen_pago=VALUES(origen_pago),
         es_sintetico=VALUES(es_sintetico),moneda_factura=VALUES(moneda_factura),tc_factura=VALUES(tc_factura),
         moneda_pago_sat=VALUES(moneda_pago_sat),tc_pago_sat=VALUES(tc_pago_sat),moneda_dr=VALUES(moneda_dr),equivalencia_dr=VALUES(equivalencia_dr),
         fecha_pago_sat=VALUES(fecha_pago_sat),fecha_pago_banco=COALESCE(fecha_pago_banco,VALUES(fecha_pago_banco)),
         fecha_pago_usuario=COALESCE(fecha_pago_usuario,VALUES(fecha_pago_usuario)),
         fecha_aplicacion_fiscal=COALESCE(fecha_pago_usuario,fecha_pago_contpaq,VALUES(fecha_aplicacion_fiscal)),
         forma_pago=VALUES(forma_pago),referencia=VALUES(referencia),
         proporcion_aplicada=VALUES(proporcion_aplicada),
         base_iva_16=VALUES(base_iva_16),iva_16=VALUES(iva_16),
         base_iva_8=VALUES(base_iva_8),iva_8=VALUES(iva_8),
         base_tasa_0=VALUES(base_tasa_0),base_exento=VALUES(base_exento),
         base_no_objeto=VALUES(base_no_objeto),iva_retenido=VALUES(iva_retenido),
         isr_retenido=VALUES(isr_retenido),ieps_otros=VALUES(ieps_otros)";
    $pdo->prepare($sql)->execute([
        $d['id_empresa'],$d['id_emisor'] ?? null,$d['rfc_emisor'] ?? null,$d['uuid_pago'],$d['uuid_relacionado'],$d['monto_pagado'],$d['importe_aplicado'],
        $d['parcialidad'],$d['saldo_anterior'],$d['saldo_insoluto'],$d['clave_detalle'],$d['aplicado'],
        $d['origen_pago'],$d['es_sintetico'],$d['moneda_factura'] ?? null,$d['tc_factura'] ?? null,
        $d['moneda_pago_sat'] ?? null,$d['tc_pago_sat'] ?? null,$d['moneda_dr'] ?? null,$d['equivalencia_dr'] ?? null,
        $d['fecha_pago_sat'],$d['fecha_pago_banco'],$d['fecha_pago_contpaq'] ?? null,$d['moneda_contpaq'] ?? null,$d['tc_contpaq'] ?? null,
        $d['monto_conciliado_contpaq'] ?? 0,$d['saldo_por_conciliar'] ?? (float)($d['importe_aplicado'] ?? $d['monto_pagado'] ?? 0),$d['estatus_conciliacion_contpaq'] ?? 'PENDIENTE',
        $d['fecha_pago_usuario'],$d['fecha_aplicacion_fiscal'],$d['forma_pago'],$d['referencia'],
        $d['proporcion_aplicada'],$d['base_iva_16'],$d['iva_16'],$d['base_iva_8'],$d['iva_8'],
        $d['base_tasa_0'],$d['base_exento'],$d['base_no_objeto'],$d['iva_retenido'],
        $d['isr_retenido'],$d['ieps_otros']
    ]);
}

function diot_crear_detalle_pue(PDO $pdo, array $factura, $fecha, $origen='PUE_EMISION') {
    $foto = diot_prorratear_factura($factura, (float)$factura['total_xml']);
    $uuid = strtoupper(trim($factura['uuid']));
    diot_upsert_detalle($pdo, array_merge($foto,[
        'id_empresa'=>(int)$factura['id_empresa'],
        'uuid_pago'=>$uuid,
        'uuid_relacionado'=>$uuid,
        'monto_pagado'=>(float)$factura['total_xml'],
        'importe_aplicado'=>(float)$factura['total_xml'],
        'parcialidad'=>1,'saldo_anterior'=>(float)$factura['total_xml'],'saldo_insoluto'=>0,
        'clave_detalle'=>hash('sha256','PUE|'.$uuid),
        'aplicado'=>1,'origen_pago'=>$origen,'es_sintetico'=>1,
        'moneda_factura'=>$factura['moneda'] ?? null,'tc_factura'=>$factura['tc_xml_factura'] ?? 1,
        'moneda_pago_sat'=>null,'tc_pago_sat'=>null,'moneda_dr'=>$factura['moneda'] ?? null,'equivalencia_dr'=>1,
        'fecha_pago_sat'=>null,'fecha_pago_banco'=>null,'fecha_pago_contpaq'=>null,'moneda_contpaq'=>null,'tc_contpaq'=>null,
        'monto_conciliado_contpaq'=>0,'saldo_por_conciliar'=>(float)$factura['total_xml'],'estatus_conciliacion_contpaq'=>'PENDIENTE',
        'fecha_pago_usuario'=>($origen==='USUARIO'?diot_fecha($fecha):null),
        'fecha_aplicacion_fiscal'=>diot_fecha($fecha),
        'forma_pago'=>$factura['forma_pago'] ?? null,'referencia'=>null
    ]));
}


/**
 * Sincroniza la Fecha Efectiva de Pago capturada manualmente.
 * REGLA PRINCIPAL: si existe fecha de usuario, ESA FECHA MANDA para DIOT y detallado.
 * - Sin REP real: crea/actualiza un detalle sintético USUARIO al 100%.
 * - Con REP real: elimina el sintético para no duplicar importes, pero conserva el REP
 *   y fuerza fecha_pago_usuario + fecha_aplicacion_fiscal del detalle real a la fecha
 *   manual. La fecha SAT del REP se conserva por separado para auditoría/comparación.
 */
function diot_sincronizar_pago_usuario_factura(PDO $pdo, string $uuid, int $idEmpresa): void {
    $uuid = strtoupper(trim($uuid));
    if ($uuid === '') return;

    $st = $pdo->prepare("SELECT f.*, e.rfc AS rfc_emisor
                         FROM facturas f
                         LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
                         WHERE UPPER(f.uuid)=UPPER(?) AND f.id_empresa=? LIMIT 1");
    $st->execute([$uuid,$idEmpresa]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return;

    $fechaUsuario = diot_fecha($f['fecha_pago_usuario'] ?? null);
    $tipo = strtoupper(trim((string)($f['id_tipo_comprobante'] ?? '')));

    // Si se borró la fecha manual, quitar solo el sintético de usuario.
    // Los REP reales permanecen y recuperan su fecha fiscal propia (banco/SAT).
    if (!$fechaUsuario || in_array($tipo,['P','N','T'],true)) {
        $pdo->prepare("DELETE FROM facturas_pagos_detalles
                       WHERE id_empresa=? AND UPPER(uuid_relacionado)=UPPER(?)
                         AND es_sintetico=1 AND UPPER(COALESCE(origen_pago,''))='USUARIO'")
            ->execute([$idEmpresa,$uuid]);

        if (!$fechaUsuario) {
            $pdo->prepare("UPDATE facturas_pagos_detalles d
                           LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND UPPER(fp.uuid)=UPPER(d.uuid_pago)
                           SET d.fecha_pago_usuario=NULL,
                               d.fecha_aplicacion_fiscal=COALESCE(d.fecha_pago_banco,d.fecha_pago_contpaq,d.fecha_pago_sat,DATE(fp.fecha_emision))
                           WHERE d.id_empresa=? AND UPPER(d.uuid_relacionado)=UPPER(?)
                             AND COALESCE(d.es_sintetico,0)=0")
                ->execute([$idEmpresa,$uuid]);
        }
        return;
    }

    $stReal = $pdo->prepare("SELECT COUNT(*)
                             FROM facturas_pagos_detalles d
                             LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND UPPER(fp.uuid)=UPPER(d.uuid_pago)
                             WHERE d.id_empresa=? AND UPPER(d.uuid_relacionado)=UPPER(?)
                               AND COALESCE(d.es_sintetico,0)=0
                               AND COALESCE(d.es_sustituido,0)=0
                               AND UPPER(TRIM(COALESCE(fp.estatus_sat,'Vigente'))) NOT IN ('CANCELADO','CANCELADA','0')");
    $stReal->execute([$idEmpresa,$uuid]);
    $tieneRepReal = ((int)$stReal->fetchColumn() > 0);

    if ($tieneRepReal) {
        // Evitar doble contabilización: el REP real aporta importes/impuestos.
        $pdo->prepare("DELETE FROM facturas_pagos_detalles
                       WHERE id_empresa=? AND UPPER(uuid_relacionado)=UPPER(?)
                         AND es_sintetico=1 AND UPPER(COALESCE(origen_pago,''))='USUARIO'")
            ->execute([$idEmpresa,$uuid]);

        // La fecha manual tiene prioridad sobre la fecha del REP para el periodo DIOT.
        // fecha_pago_sat NO se modifica; queda visible para detectar discrepancias.
        $pdo->prepare("UPDATE facturas_pagos_detalles d
                       LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND UPPER(fp.uuid)=UPPER(d.uuid_pago)
                       SET d.fecha_pago_usuario=?, d.fecha_aplicacion_fiscal=?
                       WHERE d.id_empresa=? AND UPPER(d.uuid_relacionado)=UPPER(?)
                         AND COALESCE(d.es_sintetico,0)=0
                         AND COALESCE(d.es_sustituido,0)=0
                         AND UPPER(TRIM(COALESCE(fp.estatus_sat,'Vigente'))) NOT IN ('CANCELADO','CANCELADA','0')")
            ->execute([$fechaUsuario,$fechaUsuario,$idEmpresa,$uuid]);
        return;
    }

    // Todavía no hay REP: usar el movimiento manual para que entre en DIOT/detallado.
    diot_crear_detalle_pue($pdo,$f,$fechaUsuario,'USUARIO');
}

/**
 * Backfill ligero para exportes/generación DIOT: garantiza que cualquier CFDI
 * con fecha efectiva manual tenga su detalle sintético, incluso si la fecha fue
 * capturada antes de instalar esta corrección.
 */
function diot_sincronizar_pagos_usuario_empresa(PDO $pdo, int $idEmpresa): void {
    if ($idEmpresa <= 0) return;
    $st = $pdo->prepare("SELECT uuid FROM facturas
                         WHERE id_empresa=?
                           AND fecha_pago_usuario IS NOT NULL
                           AND fecha_pago_usuario<>'0000-00-00'
                           AND UPPER(COALESCE(id_tipo_comprobante,'')) NOT IN ('P','N','T')");
    $st->execute([$idEmpresa]);
    while ($uuid = $st->fetchColumn()) {
        diot_sincronizar_pago_usuario_factura($pdo,(string)$uuid,$idEmpresa);
    }
}

/**
 * Recalcula acumulados y estado de una factura desde sus detalles fiscales de pago.
 * La factura queda como resumen; la DIOT se alimenta del detalle.
 */
function diot_recalcular_factura(PDO $pdo, string $uuid, int $idEmpresa): void {
    $uuid = strtoupper(trim($uuid));
    if ($uuid === '') return;

    $st = $pdo->prepare("SELECT total_xml,fecha_pago_usuario,metodo_pago,id_tipo_comprobante
                         FROM facturas
                         WHERE UPPER(uuid)=UPPER(?) AND id_empresa=? LIMIT 1");
    $st->execute([$uuid,$idEmpresa]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return;

    // Asegurar prioridad de la Fecha Efectiva de Pago antes de acumular.
    // Evitar recursión: esta función no es llamada desde diot_sincronizar_pago_usuario_factura.
    diot_sincronizar_pago_usuario_factura($pdo,$uuid,$idEmpresa);

    $st = $pdo->prepare("SELECT
                            COALESCE(SUM(COALESCE(d.importe_aplicado,d.monto_pagado,0)),0) AS total_pagado,
                            MAX(d.fecha_aplicacion_fiscal) AS ultima_fecha,
                            MAX(CASE WHEN d.es_sintetico=0 THEN d.fecha_pago_sat ELSE NULL END) AS ultima_fecha_pago_sat,
                            SUM(CASE WHEN d.es_sintetico=0 THEN 1 ELSE 0 END) AS detalles_sat
                         FROM facturas_pagos_detalles d
                         LEFT JOIN facturas fp
                           ON fp.id_empresa=d.id_empresa AND UPPER(fp.uuid)=UPPER(d.uuid_pago)
                         WHERE d.id_empresa=? AND UPPER(d.uuid_relacionado)=UPPER(?)
                           AND COALESCE(d.es_sustituido,0)=0
                           AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))");
    $st->execute([$idEmpresa,$uuid]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = round((float)($f['total_xml'] ?? 0),4);
    $pagado = round((float)($a['total_pagado'] ?? 0),4);
    // Evitar saldos negativos por diferencias mínimas de redondeo.
    $saldo = max(0, round($total - $pagado,4));
    $yaPago = ($total > 0 && $saldo <= 0.01) ? 1 : 0;
    $fechaUsuario = diot_fecha($f['fecha_pago_usuario'] ?? null);
    $fechaPagoSat = diot_fecha($a['ultima_fecha_pago_sat'] ?? null);
    $fechaFiscal = $fechaUsuario ?: diot_fecha($a['ultima_fecha'] ?? null);
    $tieneComplemento = ((int)($a['detalles_sat'] ?? 0) > 0) ? 1 : 0;

    $up = $pdo->prepare("UPDATE facturas SET
                            total_abonos=?,
                            saldo_pendiente=?,
                            ya_pago=?,
                            fecha_pago_sat=?,
                            fecha_aplicacion_fiscal=?,
                            tiene_complemento_pagos=?
                         WHERE UPPER(uuid)=UPPER(?) AND id_empresa=?");
    $up->execute([$pagado,$saldo,$yaPago,$fechaPagoSat,$fechaFiscal,$tieneComplemento,$uuid,$idEmpresa]);

    // Solo los detalles fiscalmente vigentes forman parte del acumulado.
    $pdo->prepare("UPDATE facturas_pagos_detalles d
                   LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND UPPER(fp.uuid)=UPPER(d.uuid_pago)
                   SET d.aplicado=CASE
                       WHEN COALESCE(d.es_sustituido,0)=0
                        AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
                       THEN 1 ELSE 0 END
                   WHERE d.id_empresa=? AND UPPER(d.uuid_relacionado)=UPPER(?)")
        ->execute([$idEmpresa,$uuid]);
}

?>
