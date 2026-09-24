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

    $factura=strtoupper(trim((string)($_GET['factura']??'')));
    if($factura==='' || strpos($factura,'EXP')!==0){
        throw new RuntimeException('La factura indicada no es una reposición EXP válida.');
    }

    $st=$pdo->prepare(
        "SELECT
            g.oracle_expense_id AS oracle_invoice_id,
            COALESCE(NULLIF(g.reference_number,''),NULLIF(g.expense_reference,''),g.oracle_expense_id) AS invoice_number,
            DATE(COALESCE(g.creation_date,g.receipt_date)) AS invoice_date,
            g.merchant_name AS supplier,
            '' AS supplier_number,
            g.merchant_taxpayer_id AS supplier_tax_registration_number,
            g.receipt_amount AS invoice_amount,
            g.receipt_currency_code AS invoice_currency,
            COALESCE(NULLIF(g.expense_report_status,''),'GASTO') AS paid_status,
            'COMPROBANTE' AS estatus_factura,
            0 AS canceled_flag,NULL AS canceled_date,'' AS canceled_by,
            '' AS payment_number,'' AS payment_reference,NULL AS payment_date,
            'INCLUIDO EN REPOSICIÓN' AS payment_status,0 AS payment_reconciled_flag,
            NULL AS payment_clearing_date,NULL AS payment_clearing_value_date,0 AS payment_count,
            g.uuid_cfdi,g.description,g.expense_type,g.oracle_expense_report_id,g.oracle_expense_id,
            g.conciliado,g.estatus_conciliacion,g.fecha_conciliacion,
            CASE WHEN g.estatus_conciliacion='DIFERENCIA' THEN COALESCE((
                SELECT c.diferencia_importe
                  FROM conciliacion_financiera c
                 WHERE c.id_empresa=g.id_empresa
                   AND c.origen_pago='ORACLE'
                   AND c.tipo_documento_origen='EXP_DETALLE'
                   AND c.id_documento_origen=g.id
                 ORDER BY c.id DESC LIMIT 1
            ),0) ELSE 0 END AS diferencia_importe
         FROM oracle_gastos g
        WHERE g.id_empresa=?
          AND CONCAT('EXP',RIGHT(LPAD(TRIM(g.oracle_expense_report_id),15,'0'),12))=?
        ORDER BY COALESCE(g.creation_date,g.receipt_date),g.oracle_expense_id"
    );
    $st->execute([$idEmpresa,$factura]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'=>true,
        'factura'=>$factura,
        'total'=>count($rows),
        'data'=>$rows
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_detalle_reposicion');
    http_response_code(400);
    echo json_encode(['success'=>false,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
