<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';

try {
    exigir_permiso_accion($pdo, (int)($_SESSION['id_usuario'] ?? 0), (int)($_SESSION['id_empresa'] ?? 0), 'generar_diot');

    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $idEmisor = (int)($_GET['id_emisor'] ?? 0);
    $rfc = strtoupper(trim((string)($_GET['rfc'] ?? '')));
    $mes = (int)($_GET['mes'] ?? date('m'));
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($idEmpresa <= 0 || ($idEmisor <= 0 && $rfc === '')) throw new InvalidArgumentException('Emisor no recibido.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    [$inicio, $fin] = diot_periodo_limites($anio, $mes);

    $sqlEmisor = "SELECT id_emisor,rfc,nombre,tipo_tercero,tipo_operacion,num_id_fiscal,nombre_extranjero,pais_residencia,nacionalidad,aplica_diot
                    FROM cat_emisores WHERE id_empresa=? AND aplica_diot=1 AND " . ($idEmisor > 0 ? "id_emisor=?" : "UPPER(TRIM(rfc))=UPPER(TRIM(?))") . " LIMIT 1";
    $st = $pdo->prepare($sqlEmisor);
    $st->execute([$idEmpresa, $idEmisor > 0 ? $idEmisor : $rfc]);
    $emisor = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emisor) throw new RuntimeException('El emisor no pertenece a la empresa activa.');

    $sqlSat = "SELECT d.id_detalle,d.uuid_pago,d.uuid_relacionado,d.parcialidad,d.origen_pago,
                      d.fecha_pago_sat,d.fecha_pago_banco,d.fecha_pago_usuario,d.fecha_aplicacion_fiscal,
                      d.forma_pago,d.referencia,d.monto_pagado,d.importe_aplicado,d.saldo_anterior,d.saldo_insoluto,
                      d.base_iva_16,d.iva_16,d.base_iva_8,d.iva_8,d.base_tasa_0,d.base_exento,d.base_no_objeto,
                      d.iva_retenido,d.isr_retenido,d.ieps_otros,
                      d.moneda_factura,d.tc_factura,d.moneda_dr,d.equivalencia_dr,
                      f.serie,f.folio,f.fecha_emision,f.total_xml,f.moneda,f.tc_xml_factura,f.estatus_sat,
                      COALESCE(d.monto_conciliado_contpaq,0) AS monto_conciliado_contpaq,
                      COALESCE(d.saldo_por_conciliar,0) AS saldo_por_conciliar,
                      COALESCE(d.estatus_conciliacion_contpaq,'PENDIENTE') AS estatus_conciliacion_contpaq
                 FROM facturas_pagos_detalles d
                 INNER JOIN facturas f
                         ON f.id_empresa = d.id_empresa
                        AND f.uuid = d.uuid_relacionado
                 LEFT JOIN facturas fp
                         ON fp.id_empresa=d.id_empresa
                        AND fp.uuid = d.uuid_pago
                 INNER JOIN cat_receptores r
                         ON r.id_empresa=f.id_empresa
                        AND r.id_receptor=f.id_receptor
                 INNER JOIN empresas emp
                         ON emp.id_empresa=f.id_empresa
                 WHERE d.id_empresa = ?
                   AND d.id_emisor = ?
                   AND d.fecha_aplicacion_fiscal >= ?
                   AND d.fecha_aplicacion_fiscal < ?
                   AND UPPER(TRIM(COALESCE(r.rfc,''))) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(COALESCE(emp.rfc,''))) COLLATE utf8mb4_unicode_ci
                   AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
                   AND COALESCE(f.excluir_diot,0) = 0
                   AND COALESCE(d.es_sustituido,0)=0
                   AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
                   AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
                ORDER BY d.fecha_aplicacion_fiscal,d.uuid_relacionado,d.parcialidad,d.id_detalle";
    $st = $pdo->prepare($sqlSat);
    $st->execute([$idEmpresa,(int)$emisor['id_emisor'],$inicio,$fin]);
    $sat = $st->fetchAll(PDO::FETCH_ASSOC);

    // CONTPAQ: consulta directa por empresa + RFC + fecha SAT.
    // Evita construir un IN() con todos los id_detalle del proveedor.
    $sqlC="SELECT c.id_conciliacion,c.id_detalle,c.tipo_origen,c.fecha_pago_sat,c.fecha_pago_contpaq,
                  c.importe_sat,c.importe_contpaq,c.importe_conciliado,c.diferencia_importe,c.diferencia_dias,
                  c.moneda_contpaq,c.tc_contpaq,c.uuid_factura,c.uuid_pago_sat,c.uuid_rep_contpaq,
                  c.origen_conciliacion,c.estatus,c.fecha_conciliacion,c.observaciones,c.rfc_emisor,
                  COALESCE(ch.tipo_documento,eg.tipo_documento) AS tipo_documento,
                  COALESCE(ch.folio,eg.folio) AS folio_contpaq,
                  COALESCE(ch.fecha,eg.fecha,c.fecha_pago_contpaq) AS fecha_movimiento,
                  COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador) AS beneficiario,
                  COALESCE(ch.persona_rfc,eg.persona_rfc,c.rfc_emisor) AS rfc_beneficiario,
                  COALESCE(ch.referencia,eg.referencia) AS referencia_contpaq,
                  COALESCE(ch.concepto,eg.concepto) AS concepto_contpaq,
                  COALESCE(ch.num_pol,eg.num_pol) AS num_pol,
                  COALESCE(ch.total,eg.total,c.importe_contpaq) AS total_movimiento,
                  CASE WHEN c.tipo_origen='C' THEN COALESCE(ch.es_cancelado,0) ELSE COALESCE(eg.es_cancelado,0) END AS es_cancelado
             FROM conciliacion_pagos_detalle c
             LEFT JOIN contpaq_cheques ch ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
             LEFT JOIN contpaq_egresos eg ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
            WHERE c.id_empresa=?
              AND c.rfc_emisor = ?
              AND c.fecha_pago_sat>=?
              AND c.fecha_pago_sat<?
            ORDER BY c.fecha_pago_contpaq,c.id_conciliacion";
    $st=$pdo->prepare($sqlC);
    $st->execute([$idEmpresa,(string)$emisor['rfc'],$inicio,$fin]);
    $contpaq=$st->fetchAll(PDO::FETCH_ASSOC);

    $moneyFields=['monto_pagado','importe_aplicado','saldo_anterior','saldo_insoluto','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros','monto_conciliado_contpaq','saldo_por_conciliar','total_xml'];
    $tot=['importe_aplicado'=>0,'base_iva_16'=>0,'iva_16'=>0,'base_iva_8'=>0,'iva_8'=>0,'base_tasa_0'=>0,'base_exento'=>0,'base_no_objeto'=>0,'iva_retenido'=>0,'isr_retenido'=>0,'ieps_otros'=>0,'conciliado_contpaq'=>0];
    $facturas=[]; $reps=[];
    foreach($sat as &$d){
        foreach($moneyFields as $f) $d[$f]=(float)($d[$f]??0);

        // Auditoría DIOT: todos los importes SAT se expresan en MXN.
        // El CFDI original no se modifica; aquí sólo se transforma la respuesta.
        $monedaOrigen = $d['moneda_dr'] ?: ($d['moneda_factura'] ?: ($d['moneda'] ?: 'MXN'));
        $tcOrigen = (float)($d['tc_factura'] ?: ($d['tc_xml_factura'] ?: 1));
        $factorMxn = diot_factor_mxn($monedaOrigen, $tcOrigen);
        $d['moneda_origen'] = strtoupper(trim((string)$monedaOrigen));
        $d['tc_origen'] = $tcOrigen;
        $d['factor_conversion_mxn'] = $factorMxn;
        $d['moneda_importes'] = 'MXN';
        foreach(['monto_pagado','importe_aplicado','saldo_anterior','saldo_insoluto','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros','total_xml'] as $campoMxn){
            $d[$campoMxn] = (float)$d[$campoMxn] * $factorMxn;
        }

        foreach(array_keys($tot) as $f) if(isset($d[$f])) $tot[$f]+=(float)$d[$f];
        $facturas[strtoupper((string)$d['uuid_relacionado'])]=1;
        if(!empty($d['uuid_pago'])) $reps[strtoupper((string)$d['uuid_pago'])]=1;
    } unset($d);
    foreach($contpaq as &$c){
        foreach(['importe_sat','importe_contpaq','importe_conciliado','diferencia_importe','tc_contpaq','total_movimiento'] as $f) $c[$f]=(float)($c[$f]??0);
        $c['diferencia_dias']=$c['diferencia_dias']===null?null:(int)$c['diferencia_dias'];
        $c['es_cancelado']=(int)($c['es_cancelado']??0);
        if(!$c['es_cancelado']) $tot['conciliado_contpaq']+=(float)$c['importe_conciliado'];
    } unset($c);
    $tot['diferencia_sat_contpaq']=round($tot['importe_aplicado']-$tot['conciliado_contpaq'],4);

    echo json_encode(['status'=>'ok','periodo'=>sprintf('%02d/%04d',$mes,$anio),'emisor'=>$emisor,
        'resumen'=>array_merge($tot,['facturas'=>count($facturas),'complementos_sat'=>count($reps),'detalles_sat'=>count($sat),'movimientos_contpaq'=>count($contpaq)]),
        'detalles_sat'=>$sat,'movimientos_contpaq'=>$contpaq], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e){
    http_response_code(400); seguridad_log_error($e,'detalle_diot_emisor');
    echo json_encode(['status'=>'error','msg'=>'No se pudo consultar la auditoría del emisor.'],JSON_UNESCAPED_UNICODE);
}
