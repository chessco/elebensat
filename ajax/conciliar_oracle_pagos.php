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
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $desde=trim((string)($_POST['desde']??''));
    $hasta=trim((string)($_POST['hasta']??''));
    if($desde===''||$hasta==='') throw new RuntimeException('Capture fecha desde y hasta.');

    @set_time_limit(0);
    $r=cf_conciliar_oracle_rango($pdo,$idEmpresa,$desde,$hasta,$idUsuario);
    echo json_encode(['success'=>true,'resumen'=>$r],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    seguridad_log_error($e,'conciliar_oracle_pagos');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
