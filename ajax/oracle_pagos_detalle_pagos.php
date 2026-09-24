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

    $invoiceId=trim((string)($_GET['invoice_id']??''));
    if($invoiceId==='') throw new RuntimeException('Falta InvoiceId.');

    $st=$pdo->prepare(
        'SELECT check_id,invoice_payment_id,payment_number,payment_reference,payment_date,payment_amount,
                invoice_payment_amount,payment_currency,payment_description,payee,payment_status,invoice_payment_status,reconciled_flag,
                clearing_date,clearing_value_date,clearing_amount,payment_method_code,payment_type,
                void_date,accounting_date
           FROM oracle_factura_pagos
          WHERE id_empresa=? AND oracle_invoice_id=?
          ORDER BY payment_date,check_id'
    );
    $st->execute([$idEmpresa,$invoiceId]);
    echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_detalle_pagos');
    http_response_code(400);
    echo json_encode(['success'=>false,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
