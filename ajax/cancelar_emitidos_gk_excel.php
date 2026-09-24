<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true); require_once '../includes/reporte_emitidos_gk.php';
try{
    $token=(string)($_POST['token']??''); if(!reg_valid_token($token))throw new RuntimeException('Token inválido.'); $p=reg_paths($token);
    if(is_file($p['meta'])){$m=json_decode((string)file_get_contents($p['meta']),true);if(is_array($m)&&((int)$m['id_usuario']!==(int)($_SESSION['id_usuario']??0)||(int)$m['id_empresa']!==(int)($_SESSION['id_empresa']??0)))throw new RuntimeException('Proceso no autorizado.');}
    reg_cleanup($p); echo json_encode(['status'=>'ok']);
}catch(Throwable $e){http_response_code(500);echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);}
