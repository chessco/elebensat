<?php
session_start();header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';require_once '../includes/seguridad.php';seguridad_exigir_sesion($pdo,true);require_once '../includes/permisos_documentos.php';require_once '../includes/oracle_iva_conciliacion.php';
try{
 $u=(int)($_SESSION['id_usuario']??0);$e=(int)($_SESSION['id_empresa']??0);exigir_permiso_accion($pdo,$u,$e,'oracle_pagos');
 $anio=(int)($_POST['anio']??0);$mes=(int)($_POST['mes']??0);if($anio<2000||$anio>2100||$mes<1||$mes>12)throw new RuntimeException('Seleccione año y mes válidos.');
 if(empty($_FILES['archivo']['tmp_name'])||!is_uploaded_file($_FILES['archivo']['tmp_name']))throw new RuntimeException('Seleccione un archivo Excel.');
 $name=(string)($_FILES['archivo']['name']??'oracle.xlsx');if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new RuntimeException('El archivo debe ser XLSX.');
 $r=oic_importar_xlsx($pdo,$e,$anio,$mes,$_FILES['archivo']['tmp_name'],$name,$u);echo json_encode(['ok'=>true]+$r,JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){http_response_code(400);seguridad_log_error($x,'oracle_iva_subir');echo json_encode(['ok'=>false,'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
