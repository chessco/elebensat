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

    $job=trim((string)($_POST['job']??''));
    if($job==='') throw new RuntimeException('Proceso inválido.');

    $st=$pdo->prepare(
        'UPDATE oracle_pagos_sync_jobs
            SET cancel_requested=1
          WHERE id_job=? AND id_empresa=? AND id_usuario=? AND estado="PROCESANDO"'
    );
    $st->execute([$job,$idEmpresa,$idUsuario]);

    echo json_encode([
        'success'=>true,
        'message'=>'Cancelación solicitada. El proceso se detendrá al terminar el registro actual.'
    ],JSON_UNESCAPED_UNICODE);

}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_cancelar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
