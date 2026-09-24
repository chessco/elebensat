<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/conciliacion_financiera.php';

try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    $uuid=cf_normalizar_uuid($_GET['uuid']??'');
    $invoiceId=trim((string)($_GET['invoice_id']??''));

    if($invoiceId!==''){
        exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');
        $data=cf_detalle_oracle_factura($pdo,$idEmpresa,$invoiceId);
    }elseif($uuid!==''){
        exigir_permiso_factura_uuid($pdo,$idUsuario,$idEmpresa,$uuid);
        $data=cf_detalle_uuid($pdo,$idEmpresa,$uuid);
    }else{
        throw new RuntimeException('Falta UUID o InvoiceId.');
    }

    echo json_encode(['success'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    seguridad_log_error($e,'conciliacion_financiera_detalle');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
