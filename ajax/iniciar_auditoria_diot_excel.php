<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';

function ad_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ad_job_paths(string $token): array {
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sgksat_diot_audit_' . $token;
    return [$base . '.json', $base . '.xls'];
}
try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot');
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $mes=(int)($_POST['mes']??date('m')); $anio=(int)($_POST['anio']??date('Y'));
    diot_sincronizar_pagos_usuario_empresa($pdo,$idEmpresa);
    $rows=diot_resumen_desde_detalles($pdo,$idEmpresa,$anio,$mes);
    $token=bin2hex(random_bytes(16));
    [$metaFile,$xlsFile]=ad_job_paths($token);
    $emisores=[];
    foreach($rows as $r){ $emisores[]=['id_emisor'=>(int)($r['id_emisor']??0),'rfc'=>(string)($r['rfc']??''),'nombre'=>(string)($r['nombre']??'')]; }
    $meta=['token'=>$token,'id_usuario'=>$idUsuario,'id_empresa'=>$idEmpresa,'mes'=>$mes,'anio'=>$anio,'indice'=>0,'total'=>count($emisores),'emisores'=>$emisores,'creado'=>time(),'completo'=>false];
    file_put_contents($metaFile,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);

    $fh=fopen($xlsFile,'wb'); if(!$fh) throw new RuntimeException('No se pudo crear el archivo temporal.');
    fwrite($fh,"\xEF\xBB\xBF");
    fwrite($fh,'<html><head><meta charset="UTF-8"><style>body{font-family:Arial}table{border-collapse:collapse;margin-bottom:18px}td,th{border:1px solid #888;padding:3px;font-size:9pt}th{background:#1f5d7a;color:#fff}.titulo{background:#d9edf7;font-weight:bold;font-size:12pt}.sub{background:#eaf4f8;font-weight:bold}.num{text-align:right}.uuid{mso-number-format:"\\@"}</style></head><body>');
    fwrite($fh,'<h1>Auditoría completa DIOT por emisor</h1><p><b>Periodo:</b> '.sprintf('%02d/%04d',$mes,$anio).'<br><b>Emisores:</b> '.count($rows).'</p>');
    fclose($fh);
    echo json_encode(['status'=>'ok','token'=>$token,'total'=>count($emisores)],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
    http_response_code(500); seguridad_log_error($e,'iniciar_auditoria_diot_excel');
    echo json_encode(['status'=>'error','msg'=>'No se pudo iniciar la auditoría completa.'],JSON_UNESCAPED_UNICODE);
}
