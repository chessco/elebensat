<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $uuid = strtoupper(trim((string)($_GET['uuid'] ?? '')));
    if ($idEmpresa <= 0) throw new RuntimeException('Sin empresa activa.');
    if ($uuid === '') throw new InvalidArgumentException('UUID no recibido.');
    exigir_permiso_factura_uuid($pdo, (int)$_SESSION['id_usuario'], $idEmpresa, $uuid);

    $st = $pdo->prepare("SELECT f.total_xml, f.saldo_pendiente, f.serie, f.folio, f.fecha_emision, f.estatus_sat,
                               f.iva_tratamiento_especial_diot, f.iva_porcentaje_acreditable_diot, f.iva_motivo_tratamiento_diot,
                               e.rfc AS rfc_emisor, e.nombre AS emisor
                        FROM facturas f
                        LEFT JOIN cat_emisores e ON e.id_emisor = f.id_emisor
                        WHERE f.id_empresa=? AND f.uuid=?
                        LIMIT 1");
    $st->execute([$idEmpresa, $uuid]);
    $factura = $st->fetch(PDO::FETCH_ASSOC);
    if (!$factura) throw new RuntimeException('La factura no pertenece a la empresa activa o no existe.');

    $sql = "SELECT d.id_detalle, d.uuid_pago, d.uuid_relacionado, d.monto_pagado, d.importe_aplicado,
                   d.parcialidad, d.saldo_anterior, d.saldo_insoluto, d.origen_pago, d.es_sintetico,
                   d.fecha_pago_sat, d.fecha_pago_banco, d.fecha_pago_usuario, d.fecha_aplicacion_fiscal, d.es_sustituido, d.uuid_sustituto,
                   d.forma_pago, d.referencia, d.proporcion_aplicada,
                   d.base_iva_16, d.iva_16, d.base_iva_8, d.iva_8, d.base_tasa_0, d.base_exento,
                   d.base_no_objeto, d.iva_retenido, d.isr_retenido, d.ieps_otros, fp.estatus_sat estatus_pago_sat,
                   COALESCE(fp.excluir_diot,0) AS pago_excluir_diot,
                   CASE WHEN EXISTS(SELECT 1 FROM cfdi_relaciones cr WHERE cr.id_empresa=d.id_empresa AND cr.tipo_relacion='04' AND cr.uuid_origen=d.uuid_pago) THEN 1 ELSE 0 END es_sustituto_rel
            FROM facturas_pagos_detalles d
            LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND fp.uuid=d.uuid_pago
            WHERE d.id_empresa=? AND d.uuid_relacionado=?
            ORDER BY COALESCE(d.fecha_aplicacion_fiscal,d.fecha_pago_usuario,d.fecha_pago_banco,d.fecha_pago_sat) ASC,
                     d.parcialidad ASC, d.id_detalle ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$idEmpresa, $uuid]);
    $detalles = $st->fetchAll(PDO::FETCH_ASSOC);

    $fmtFecha = static function ($v) {
        if (!$v || $v === '0000-00-00') return null;
        $ts = strtotime($v);
        return $ts ? date('d/m/Y', $ts) : $v;
    };

    $idsDetalle = array_values(array_filter(array_map(static fn($r) => (int)($r['id_detalle'] ?? 0), $detalles)));
    $aplicacionesPorDetalle = [];
    $idsConciliacionContpaqVistos = [];
    if ($idsDetalle) {
        $marcas = implode(',', array_fill(0, count($idsDetalle), '?'));
        $sqlContpaq = "SELECT c.id_conciliacion,c.id_detalle,c.tipo_origen,c.id_contpaq_dispersion,
                              c.id_contpaq_cheque,c.id_contpaq_egreso,c.fecha_pago_contpaq,
                              c.moneda_contpaq,c.tc_contpaq,c.importe_contpaq,c.importe_conciliado,
                              c.diferencia_importe,c.diferencia_dias,c.estatus,c.origen_conciliacion,
                              c.uuid_rep_contpaq,c.fecha_conciliacion,c.observaciones,
                              d.uuid AS uuid_factura_contpaq,d.uuid_rep,d.num_nodo_pago,d.guid_ref,
                              d.total_pago,d.total_pago_comprobante,d.tipo_cambio AS tc_dispersion,
                              COALESCE(ch.tipo_documento,eg.tipo_documento) AS tipo_documento,
                              COALESCE(ch.folio,eg.folio) AS folio,
                              COALESCE(ch.fecha,eg.fecha) AS fecha_movimiento,
                              COALESCE(ch.codigo_moneda,eg.codigo_moneda,d.moneda_contpaq) AS moneda_movimiento,
                              COALESCE(ch.tipo_cambio,eg.tipo_cambio,d.tipo_cambio) AS tc_movimiento,
                              COALESCE(ch.total,eg.total) AS total_movimiento,
                              COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador) AS beneficiario,
                              COALESCE(ch.persona_rfc,eg.persona_rfc) AS rfc_beneficiario,
                              COALESCE(ch.referencia,eg.referencia) AS referencia_contpaq,
                              COALESCE(ch.concepto,eg.concepto) AS concepto_contpaq,
                              COALESCE(ch.num_pol,eg.num_pol) AS num_pol,
                              COALESCE(ch.id_poliza,eg.id_poliza) AS id_poliza,
                              COALESCE(ch.guid,eg.guid,d.guid_ref) AS guid_movimiento,
                              CASE WHEN c.tipo_origen='C' THEN COALESCE(ch.es_cancelado,0) ELSE COALESCE(eg.es_cancelado,0) END AS es_cancelado
                         FROM conciliacion_pagos_detalle c
                         INNER JOIN contpaq_dispersiones_pagos d
                           ON d.id_empresa=c.id_empresa AND d.id_contpaq_dispersion=c.id_contpaq_dispersion
                         LEFT JOIN contpaq_cheques ch
                           ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
                         LEFT JOIN contpaq_egresos eg
                           ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
                        WHERE c.id_empresa=? AND c.id_detalle IN ($marcas)
                        ORDER BY c.id_detalle,c.fecha_pago_contpaq,c.id_conciliacion";
        $stContpaq = $pdo->prepare($sqlContpaq);
        $stContpaq->execute(array_merge([$idEmpresa], $idsDetalle));
        while ($a = $stContpaq->fetch(PDO::FETCH_ASSOC)) {
            $a['fecha_pago_contpaq'] = $fmtFecha($a['fecha_pago_contpaq'] ?? null);
            $a['fecha_movimiento'] = $fmtFecha($a['fecha_movimiento'] ?? null);
            $a['fecha_conciliacion'] = $a['fecha_conciliacion'] ? date('d/m/Y H:i', strtotime($a['fecha_conciliacion'])) : null;
            foreach (['tc_contpaq','importe_contpaq','importe_conciliado','diferencia_importe','total_pago',
                      'total_pago_comprobante','tc_dispersion','tc_movimiento','total_movimiento'] as $campo) {
                $a[$campo] = (float)($a[$campo] ?? 0);
            }
            $a['diferencia_dias'] = $a['diferencia_dias'] === null ? null : (int)$a['diferencia_dias'];
            $a['es_cancelado'] = (int)($a['es_cancelado'] ?? 0);
            $idConc = (int)($a['id_conciliacion'] ?? 0);
            if ($idConc > 0) $idsConciliacionContpaqVistos[$idConc] = true;
            $aplicacionesPorDetalle[(int)$a['id_detalle']][] = $a;
        }
    }

    // Respaldo por UUID de factura. Después de reprocesar complementos puede cambiar
    // id_detalle aunque la conciliación siga correctamente ligada al UUID de la factura.
    // La consulta principal por id_detalle se conserva por velocidad y este segundo paso
    // usa el índice idx_conciliacion_factura_fecha para recuperar relaciones históricas.
    $sqlContpaqUuid = "SELECT c.id_conciliacion,c.id_detalle,c.tipo_origen,c.id_contpaq_dispersion,
                              c.id_contpaq_cheque,c.id_contpaq_egreso,c.fecha_pago_contpaq,
                              c.moneda_contpaq,c.tc_contpaq,c.importe_contpaq,c.importe_conciliado,
                              c.diferencia_importe,c.diferencia_dias,c.estatus,c.origen_conciliacion,
                              c.uuid_rep_contpaq,c.fecha_conciliacion,c.observaciones,
                              d.uuid AS uuid_factura_contpaq,d.uuid_rep,d.num_nodo_pago,d.guid_ref,
                              d.total_pago,d.total_pago_comprobante,d.tipo_cambio AS tc_dispersion,
                              COALESCE(ch.tipo_documento,eg.tipo_documento) AS tipo_documento,
                              COALESCE(ch.folio,eg.folio) AS folio,
                              COALESCE(ch.fecha,eg.fecha) AS fecha_movimiento,
                              COALESCE(ch.codigo_moneda,eg.codigo_moneda,d.moneda_contpaq) AS moneda_movimiento,
                              COALESCE(ch.tipo_cambio,eg.tipo_cambio,d.tipo_cambio) AS tc_movimiento,
                              COALESCE(ch.total,eg.total) AS total_movimiento,
                              COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador) AS beneficiario,
                              COALESCE(ch.persona_rfc,eg.persona_rfc) AS rfc_beneficiario,
                              COALESCE(ch.referencia,eg.referencia) AS referencia_contpaq,
                              COALESCE(ch.concepto,eg.concepto) AS concepto_contpaq,
                              COALESCE(ch.num_pol,eg.num_pol) AS num_pol,
                              COALESCE(ch.id_poliza,eg.id_poliza) AS id_poliza,
                              COALESCE(ch.guid,eg.guid,d.guid_ref) AS guid_movimiento,
                              CASE WHEN c.tipo_origen='C' THEN COALESCE(ch.es_cancelado,0) ELSE COALESCE(eg.es_cancelado,0) END AS es_cancelado
                         FROM conciliacion_pagos_detalle c
                         INNER JOIN contpaq_dispersiones_pagos d
                           ON d.id_empresa=c.id_empresa AND d.id_contpaq_dispersion=c.id_contpaq_dispersion
                         LEFT JOIN contpaq_cheques ch
                           ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
                         LEFT JOIN contpaq_egresos eg
                           ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
                        WHERE c.id_empresa=? AND c.uuid_factura=?
                        ORDER BY c.fecha_pago_contpaq,c.id_conciliacion";
    $stContpaqUuid = $pdo->prepare($sqlContpaqUuid);
    $stContpaqUuid->execute([$idEmpresa, $uuid]);
    while ($a = $stContpaqUuid->fetch(PDO::FETCH_ASSOC)) {
        $idConc = (int)($a['id_conciliacion'] ?? 0);
        if ($idConc > 0 && isset($idsConciliacionContpaqVistos[$idConc])) continue;
        $a['fecha_pago_contpaq'] = $fmtFecha($a['fecha_pago_contpaq'] ?? null);
        $a['fecha_movimiento'] = $fmtFecha($a['fecha_movimiento'] ?? null);
        $a['fecha_conciliacion'] = $a['fecha_conciliacion'] ? date('d/m/Y H:i', strtotime($a['fecha_conciliacion'])) : null;
        foreach (['tc_contpaq','importe_contpaq','importe_conciliado','diferencia_importe','total_pago',
                  'total_pago_comprobante','tc_dispersion','tc_movimiento','total_movimiento'] as $campo) {
            $a[$campo] = (float)($a[$campo] ?? 0);
        }
        $a['diferencia_dias'] = $a['diferencia_dias'] === null ? null : (int)$a['diferencia_dias'];
        $a['es_cancelado'] = (int)($a['es_cancelado'] ?? 0);
        $idD = (int)($a['id_detalle'] ?? 0);
        if ($idD > 0 && in_array($idD, $idsDetalle, true)) {
            $aplicacionesPorDetalle[$idD][] = $a;
        } elseif ($idsDetalle) {
            // Si el id_detalle quedó viejo tras un reproceso, asociar por UUID al primer
            // detalle fiscal visible para no ocultar una conciliación real existente.
            $aplicacionesPorDetalle[(int)$idsDetalle[0]][] = $a;
        }
        if ($idConc > 0) $idsConciliacionContpaqVistos[$idConc] = true;
    }

    $aplicacionesFinPorDetalle = [];
    $aplicacionesFinSinDetalle = [];
    if ($idsDetalle) {
        $marcasFin = implode(',', array_fill(0, count($idsDetalle), '?'));

        // IMPORTANTE: se separaron las búsquedas por id_detalle y por UUID.
        // El OR anterior impedía que MySQL aprovechara de forma consistente
        // idx_cf_empresa_detalle e idx_cf_empresa_uuid.
        $sqlFinDetalle = "SELECT c.* FROM conciliacion_financiera c
                          WHERE c.id_empresa=? AND c.id_detalle IN ($marcasFin)
                          ORDER BY c.id_detalle,c.fecha_pago,c.id";
        $stFinDetalle = $pdo->prepare($sqlFinDetalle);
        $stFinDetalle->execute(array_merge([$idEmpresa], $idsDetalle));
        while ($a = $stFinDetalle->fetch(PDO::FETCH_ASSOC)) {
            foreach (['importe_documento','importe_pago','importe_aplicado','diferencia_importe'] as $campo) {
                $a[$campo] = (float)($a[$campo] ?? 0);
            }
            $a['conciliado_banco'] = (int)($a['conciliado_banco'] ?? 0);
            $a['fecha_pago'] = $fmtFecha($a['fecha_pago'] ?? null);
            $a['fecha_conciliacion_banco'] = $fmtFecha($a['fecha_conciliacion_banco'] ?? null);
            $a['fecha_valor'] = $fmtFecha($a['fecha_valor'] ?? null);
            $a['fecha_conciliacion'] = !empty($a['fecha_conciliacion']) ? date('d/m/Y H:i', strtotime($a['fecha_conciliacion'])) : null;
            $idD = (int)($a['id_detalle'] ?? 0);
            if ($idD > 0) $aplicacionesFinPorDetalle[$idD][] = $a;
        }

        // Respaldo completo por UUID. No limitar a id_detalle IS NULL: si un complemento
        // fue reprocesado, la conciliación puede conservar un id_detalle histórico y seguir
        // siendo válida por uuid_factura. Se deduplica contra lo ya obtenido por id_detalle.
        $idsFinVistos = [];
        foreach ($aplicacionesFinPorDetalle as $listaFin) {
            foreach ($listaFin as $filaFin) {
                $idFin = (int)($filaFin['id'] ?? 0);
                if ($idFin > 0) $idsFinVistos[$idFin] = true;
            }
        }
        $stFinUuid = $pdo->prepare("SELECT c.* FROM conciliacion_financiera c
                                   WHERE c.id_empresa=? AND c.uuid_factura=?
                                   ORDER BY c.fecha_pago,c.id");
        $stFinUuid->execute([$idEmpresa, $uuid]);
        while ($a = $stFinUuid->fetch(PDO::FETCH_ASSOC)) {
            $idFin = (int)($a['id'] ?? 0);
            if ($idFin > 0 && isset($idsFinVistos[$idFin])) continue;
            foreach (['importe_documento','importe_pago','importe_aplicado','diferencia_importe'] as $campo) {
                $a[$campo] = (float)($a[$campo] ?? 0);
            }
            $a['conciliado_banco'] = (int)($a['conciliado_banco'] ?? 0);
            $a['fecha_pago'] = $fmtFecha($a['fecha_pago'] ?? null);
            $a['fecha_conciliacion_banco'] = $fmtFecha($a['fecha_conciliacion_banco'] ?? null);
            $a['fecha_valor'] = $fmtFecha($a['fecha_valor'] ?? null);
            $a['fecha_conciliacion'] = !empty($a['fecha_conciliacion']) ? date('d/m/Y H:i', strtotime($a['fecha_conciliacion'])) : null;
            $idD = (int)($a['id_detalle'] ?? 0);
            if ($idD > 0 && in_array($idD, $idsDetalle, true)) {
                $aplicacionesFinPorDetalle[$idD][] = $a;
            } else {
                $aplicacionesFinSinDetalle[] = $a;
            }
            if ($idFin > 0) $idsFinVistos[$idFin] = true;
        }
    } else {
        $stFin = $pdo->prepare("SELECT * FROM conciliacion_financiera WHERE id_empresa=? AND uuid_factura=? ORDER BY fecha_pago,id");
        $stFin->execute([$idEmpresa,$uuid]);
        while ($a = $stFin->fetch(PDO::FETCH_ASSOC)) {
            foreach (['importe_documento','importe_pago','importe_aplicado','diferencia_importe'] as $campo) $a[$campo]=(float)($a[$campo]??0);
            $a['conciliado_banco']=(int)($a['conciliado_banco']??0);
            $a['fecha_pago']=$fmtFecha($a['fecha_pago']??null);
            $a['fecha_conciliacion_banco']=$fmtFecha($a['fecha_conciliacion_banco']??null);
            $a['fecha_valor']=$fmtFecha($a['fecha_valor']??null);
            $aplicacionesFinSinDetalle[]=$a;
        }
    }

    $totalAplicado = 0.0;
    $totalExcluidoDiot = 0.0;
    $movimientosExcluidosDiot = 0;
    $totalContpaq = 0.0;
    $movimientosContpaq = 0;
    foreach ($detalles as &$d) {
        $idDet = (int)($d['id_detalle'] ?? 0);
        $d['aplicaciones_contpaq'] = $aplicacionesPorDetalle[$idDet] ?? [];
        $d['aplicaciones_financieras'] = $aplicacionesFinPorDetalle[$idDet] ?? [];
        $d['total_conciliado_contpaq'] = 0.0;
        foreach ($d['aplicaciones_contpaq'] as $ap) {
            $d['total_conciliado_contpaq'] += (float)($ap['importe_conciliado'] ?? 0);
            $movimientosContpaq++;
        }
        $totalContpaq += $d['total_conciliado_contpaq'];
        $esSustituido=(int)($d['es_sustituido']??0)===1;
        $estatusPagoSat = strtoupper(trim((string)($d['estatus_pago_sat'] ?? '')));
        // Un complemento solo se considera inválido cuando está explícitamente cancelado.
        // Vacío/NULL significa que aún no tenemos metadata de estatus y NO debe sacar
        // al sustituto vigente de los cálculos.
        $pagoVigente = ((int)($d['es_sintetico']??0)===1)
            || !in_array($estatusPagoSat, ['CANCELADO','CANCELADA','0'], true);
        $pagoExcluidoDiot = (int)($d['pago_excluir_diot'] ?? 0) === 1;
        // Si el CFDI de pago/complemento fue marcado como NO DIOT, se conserva visible
        // para auditoría pero no se considera como una segunda aplicación de la factura.
        $d['excluido_diot'] = $pagoExcluidoDiot ? 1 : 0;
        $d['cuenta_saldo']=(!$esSustituido && $pagoVigente && !$pagoExcluidoDiot) ? 1 : 0;
        $d['estatus_complemento']=$esSustituido?'SUSTITUIDO':(((int)($d['es_sustituto_rel']??0)===1)?'SUSTITUTO':'VIGENTE');

        // Misma presentación fiscal que usa la generación DIOT para facturas
        // con tratamiento especial de IVA. Conservamos además los importes XML.
        foreach (['base_iva_16','iva_16','base_iva_8','iva_8','base_no_objeto'] as $campoXml) {
            $d[$campoXml.'_xml'] = (float)($d[$campoXml] ?? 0);
        }
        $d['iva_tratamiento_especial_diot'] = (int)($factura['iva_tratamiento_especial_diot'] ?? 0);
        $d['iva_porcentaje_acreditable_diot'] = (float)($factura['iva_porcentaje_acreditable_diot'] ?? 100);
        $d['iva_motivo_tratamiento_diot'] = (string)($factura['iva_motivo_tratamiento_diot'] ?? '');
        if ($d['iva_tratamiento_especial_diot'] === 1) {
            $pct = max(0.0, min(100.0, $d['iva_porcentaje_acreditable_diot'])) / 100.0;
            $noAcred = 1.0 - $pct;
            $base16Original = (float)($d['base_iva_16'] ?? 0);
            $iva16Original  = (float)($d['iva_16'] ?? 0);
            $base8Original  = (float)($d['base_iva_8'] ?? 0);
            $iva8Original   = (float)($d['iva_8'] ?? 0);
            $d['base_iva_16'] = $base16Original * $pct;
            $d['iva_16']      = $iva16Original * $pct;
            $d['base_iva_8']  = $base8Original * $pct;
            $d['iva_8']       = $iva8Original * $pct;
            $d['base_no_objeto'] = (float)($d['base_no_objeto'] ?? 0)
                + ($base16Original * $noAcred)
                + ($base8Original * $noAcred);
            $d['iva_16_no_acreditable'] = $iva16Original * $noAcred;
            $d['iva_8_no_acreditable']  = $iva8Original * $noAcred;
        } else {
            $d['iva_16_no_acreditable'] = 0.0;
            $d['iva_8_no_acreditable']  = 0.0;
        }

        $importeDetalle = (float)($d['importe_aplicado'] ?? $d['monto_pagado'] ?? 0);
        if ($d['cuenta_saldo']) {
            $totalAplicado += $importeDetalle;
        } elseif ($pagoExcluidoDiot && !$esSustituido && $pagoVigente) {
            $totalExcluidoDiot += $importeDetalle;
            $movimientosExcluidosDiot++;
        }
        foreach (['fecha_pago_sat','fecha_pago_banco','fecha_pago_usuario','fecha_aplicacion_fiscal','fecha_pago_origen','fecha_conciliacion_banco_origen','fecha_valor_origen'] as $campo) {
            $d[$campo] = $fmtFecha($d[$campo] ?? null);
        }
        foreach (['monto_pagado','importe_aplicado','saldo_anterior','saldo_insoluto','proporcion_aplicada',
                  'base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto',
                  'iva_retenido','isr_retenido','ieps_otros','total_conciliado_contpaq'] as $campo) {
            $d[$campo] = (float)($d[$campo] ?? 0);
        }
    }
    unset($d);

    $totalFactura = (float)($factura['total_xml'] ?? 0);
    $saldoCalculado = max(0, $totalFactura - $totalAplicado);
    $totalFinanciero = 0.0;
    $movimientosFinancieros = 0;
    $estadosFin = [];
    foreach ($detalles as $dFin) {
        foreach (($dFin['aplicaciones_financieras'] ?? []) as $apFin) {
            $totalFinanciero += (float)($apFin['importe_aplicado'] ?? 0);
            $movimientosFinancieros++;
            $estadosFin[] = strtoupper((string)($apFin['estatus_conciliacion'] ?? 'PENDIENTE'));
        }
    }
    foreach ($aplicacionesFinSinDetalle as $apFin) {
        $movimientosFinancieros++;
        $estadosFin[] = strtoupper((string)($apFin['estatus_conciliacion'] ?? 'PENDIENTE'));
    }
    $estadoFinanciero = 'PENDIENTE';
    $prioridadFin = ['DIFERENCIA'=>100,'SIN_XML'=>90,'SIN_DETALLE'=>80,'SIN_PAGO'=>70,'PENDIENTE'=>60,'PARCIAL'=>50,'CONCILIADO_SIN_COMPLEMENTO'=>45,'CANCELADO'=>40,'CONCILIADO'=>10];
    $maxFin = -1;
    foreach ($estadosFin as $eFin) { $pr=$prioridadFin[$eFin] ?? 60; if($pr>$maxFin){$maxFin=$pr;$estadoFinanciero=$eFin;} }


    echo json_encode([
        'status' => 'ok',
        'factura' => [
            'emisor' => $factura['emisor'] ?: '-',
            'rfc_emisor' => $factura['rfc_emisor'] ?: '-',
            'serie' => $factura['serie'] ?: '-',
            'folio' => $factura['folio'] ?: 'S/F',
            'fecha_emision' => $fmtFecha($factura['fecha_emision'] ?? null),
            'estatus_sat' => $factura['estatus_sat'] ?: 'Vigente'
        ],
        'resumen' => [
            'total_factura' => $totalFactura,
            'total_aplicado' => $totalAplicado,
            'total_excluido_diot' => $totalExcluidoDiot,
            'movimientos_excluidos_diot' => $movimientosExcluidosDiot,
            'saldo' => $saldoCalculado,
            'movimientos' => count($detalles),
            'total_contpaq' => $totalContpaq,
            'diferencia_contpaq' => round($totalAplicado - $totalContpaq, 4),
            'movimientos_contpaq' => $movimientosContpaq,
            'total_financiero' => $totalFinanciero,
            'diferencia_financiera' => round($totalAplicado - $totalFinanciero,4),
            'movimientos_financieros' => $movimientosFinancieros,
            'estatus_conciliacion_financiera' => $estadoFinanciero
        ],
        'relaciones_financieras_sin_detalle' => $aplicacionesFinSinDetalle,
        'detalles' => $detalles
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    seguridad_log_error($e, 'listar_detalles_pago_factura'); echo json_encode(['status'=>'error','msg'=>'No se pudieron consultar los pagos de la factura.'], JSON_UNESCAPED_UNICODE);
}
