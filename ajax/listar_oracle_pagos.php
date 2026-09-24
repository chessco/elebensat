<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $anio=(int)($_GET['anio']??date('Y'));
    $mes=(int)($_GET['mes']??date('n'));
    $tipo=strtolower((string)($_GET['tipo']??'facturas'));
    $estatusConciliacion=strtoupper(trim((string)($_GET['estatus_conciliacion']??'TODOS')));
    $estatusPermitidos=['TODOS','PENDIENTE','CONCILIADO','CONCILIADO_SIN_COMPLEMENTO','PARCIAL','DIFERENCIA','SIN_XML','SIN_UUID','SIN_DETALLE','SIN_PAGO','CANCELADO','PENSION','DEMANDA','NO_CONSIDERAR','MANUAL'];
    if(!in_array($estatusConciliacion,$estatusPermitidos,true)) $estatusConciliacion='TODOS';

    $desdeGet=trim((string)($_GET['desde']??''));
    $hastaGet=trim((string)($_GET['hasta']??''));
    $validaFecha=static function(string $f): bool {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f);
        return $d!==false && $d->format('Y-m-d')===$f;
    };

    if($validaFecha($desdeGet) && $validaFecha($hastaGet)){
        if($desdeGet>$hastaGet) throw new RuntimeException('La fecha desde no puede ser mayor que la fecha hasta.');
        $desde=$desdeGet;
        $hasta=(new DateTimeImmutable($hastaGet))->modify('+1 day')->format('Y-m-d');
    }else{
        $desde=sprintf('%04d-%02d-01',$anio,$mes);
        $hasta=(new DateTimeImmutable($desde))->modify('first day of next month')->format('Y-m-d');
    }

    $filtroEstatus='';
    $params=[$idEmpresa,$desde,$hasta];
    if($estatusConciliacion!=='TODOS'){
        $filtroEstatus=" AND COALESCE(NULLIF(TRIM(estatus_conciliacion),''),'PENDIENTE')=?";
        $params[]=$estatusConciliacion;
    }

    if($tipo==='gastos'){
        $st=$pdo->prepare(
            "SELECT id,oracle_expense_report_id,oracle_expense_id,creation_date,person_name,merchant_name,
                    expense_type,receipt_amount,receipt_currency_code,expense_report_status,merchant_taxpayer_id,expense_reference,reference_number,uuid_cfdi,
                    COALESCE((SELECT m.uuid FROM oracle_conciliacion_uuid_manual m WHERE m.id_empresa=oracle_gastos.id_empresa AND m.tipo_documento='EXP_DETALLE' AND m.id_documento_origen=oracle_gastos.id AND m.activo=1 LIMIT 1),'') AS uuid_manual,
                    conciliado,estatus_conciliacion,fecha_conciliacion,
                    COALESCE((SELECT c.origen_clasificacion FROM oracle_conciliacion_clasificaciones c WHERE c.id_empresa=oracle_gastos.id_empresa AND c.tipo_documento='EXP_DETALLE' AND c.id_documento_origen=oracle_gastos.id LIMIT 1),'') AS origen_clasificacion,
                    CASE WHEN estatus_conciliacion='DIFERENCIA' THEN COALESCE((
                        SELECT c.diferencia_importe
                          FROM conciliacion_financiera c
                         WHERE c.id_empresa=oracle_gastos.id_empresa
                           AND c.origen_pago='ORACLE'
                           AND c.tipo_documento_origen='EXP_DETALLE'
                           AND c.id_documento_origen=oracle_gastos.id
                         ORDER BY c.id DESC LIMIT 1
                    ),0) ELSE 0 END AS diferencia_importe
               FROM oracle_gastos
              WHERE id_empresa=? AND creation_date>=? AND creation_date<?".$filtroEstatus."
              ORDER BY creation_date DESC,oracle_expense_report_id,oracle_expense_id"
        );
    }else{
        $filtroEstatusFactura='';
        if($estatusConciliacion!=='TODOS'){
            $filtroEstatusFactura=" AND COALESCE(NULLIF(TRIM(f.estatus_conciliacion),''),'PENDIENTE')=?";
        }
        $st=$pdo->prepare(
            "SELECT f.id,f.oracle_invoice_id,f.invoice_number,f.invoice_date,f.supplier,f.supplier_number,
                    f.supplier_tax_registration_number,f.invoice_amount,f.invoice_currency,f.paid_status,f.uuid_cfdi,
                    f.canceled_flag,f.canceled_date,f.canceled_by,
                    f.payment_check_id,f.payment_number,f.payment_reference,f.payment_date,f.payment_status,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.payment_description),'') ORDER BY p.payment_date,p.id SEPARATOR ' | ')
                          FROM oracle_factura_pagos p
                         WHERE p.id_empresa=f.id_empresa
                           AND p.oracle_invoice_id=f.oracle_invoice_id
                    ),'') AS payment_description,
                    f.payment_reconciled_flag,f.payment_clearing_date,f.payment_clearing_value_date,
                    f.payment_amount,f.payment_currency_detail,f.payment_void_date,f.payment_count,
                    f.conciliado,f.estatus_conciliacion,f.fecha_conciliacion,
                    COALESCE((SELECT m.uuid FROM oracle_conciliacion_uuid_manual m WHERE m.id_empresa=f.id_empresa AND m.tipo_documento='FACTURA' AND m.id_documento_origen=f.id AND m.activo=1 LIMIT 1),'') AS uuid_manual,
                    COALESCE((SELECT c.origen_clasificacion FROM oracle_conciliacion_clasificaciones c WHERE c.id_empresa=f.id_empresa AND c.tipo_documento='FACTURA' AND c.id_documento_origen=f.id LIMIT 1),'') AS origen_clasificacion,
                    CASE WHEN f.estatus_conciliacion='DIFERENCIA' THEN
                        CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN COALESCE((
                            SELECT SUM(COALESCE((
                                SELECT c.diferencia_importe
                                  FROM conciliacion_financiera c
                                 WHERE c.id_empresa=g.id_empresa
                                   AND c.origen_pago='ORACLE'
                                   AND c.tipo_documento_origen='EXP_DETALLE'
                                   AND c.id_documento_origen=g.id
                                 ORDER BY c.id DESC LIMIT 1
                            ),0))
                              FROM oracle_gastos g
                             WHERE g.id_empresa=f.id_empresa
                               AND CONCAT('EXP',RIGHT(LPAD(TRIM(g.oracle_expense_report_id),15,'0'),12))=UPPER(TRIM(f.invoice_number))
                        ),0) ELSE COALESCE((
                            SELECT SUM(c.diferencia_importe)
                              FROM conciliacion_financiera c
                             WHERE c.id_empresa=f.id_empresa
                               AND c.origen_pago='ORACLE'
                               AND c.tipo_documento_origen='FACTURA'
                               AND c.id_documento_origen=f.id
                        ),0) END
                    ELSE 0 END AS diferencia_importe,
                    CASE WHEN f.canceled_flag=1 THEN 'CANCELADA' ELSE 'VIGENTE' END AS estatus_factura,
                    CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN 1 ELSE 0 END AS es_reposicion,
                    CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN 'REPOSICIÓN' ELSE f.paid_status END AS estatus_mostrar,
                    CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN (
                        SELECT COUNT(*)
                          FROM oracle_gastos g
                         WHERE g.id_empresa=f.id_empresa
                           AND CONCAT('EXP',RIGHT(LPAD(TRIM(g.oracle_expense_report_id),15,'0'),12))=UPPER(TRIM(f.invoice_number))
                    ) ELSE 0 END AS detalle_count
               FROM oracle_facturas f
              WHERE f.id_empresa=? AND f.invoice_date>=? AND f.invoice_date<?".$filtroEstatusFactura."
              ORDER BY f.invoice_date DESC,f.oracle_invoice_id"
        );
    }
    $st->execute($params);
    echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'listar_oracle_pagos');
    http_response_code(400);
    echo json_encode(['success'=>false,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
