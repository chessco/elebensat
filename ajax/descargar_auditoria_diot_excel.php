<?php
session_start(); require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
$token=(string)($_GET['token']??''); if(!preg_match('/^[a-f0-9]{32}$/',$token)){http_response_code(400);exit('Token inválido.');}
$b=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sgksat_diot_audit_'.$token; $mf=$b.'.json'; $xf=$b.'.xls';
if(!is_file($mf)||!is_file($xf)){http_response_code(404);exit('Archivo no disponible.');}
$m=json_decode((string)file_get_contents($mf),true);
if(!is_array($m)||(int)($m['id_usuario']??0)!==(int)($_SESSION['id_usuario']??0)||(int)($m['id_empresa']??0)!==(int)($_SESSION['id_empresa']??0)||empty($m['completo'])){http_response_code(403);exit('Archivo no autorizado o incompleto.');}
$name='Auditoria_DIOT_COMPLETA_'.sprintf('%02d',(int)$m['mes']).'_'.(int)$m['anio'].'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$name.'"'); header('Content-Length: '.filesize($xf)); header('Cache-Control: no-store');
readfile($xf); @unlink($xf); @unlink($mf);
