<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
$token=(string)($_POST['token']??'');
if(!preg_match('/^[a-f0-9]{32}$/',$token)){ echo json_encode(['status'=>'ok']); exit; }
$b=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sgksat_diot_audit_'.$token; $mf=$b.'.json'; $xf=$b.'.xls';
if(is_file($mf)){
    $m=json_decode((string)file_get_contents($mf),true);
    if(is_array($m) && (int)($m['id_usuario']??0)===(int)($_SESSION['id_usuario']??0) && (int)($m['id_empresa']??0)===(int)($_SESSION['id_empresa']??0)) { @unlink($mf); @unlink($xf); }
}
echo json_encode(['status'=>'ok']);
