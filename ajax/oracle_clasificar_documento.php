<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; require_once '../includes/permisos_documentos.php';
try{
 seguridad_exigir_sesion($pdo,true,false,true); $u=(int)($_SESSION['id_usuario']??0); $e=(int)($_SESSION['id_empresa']??0); exigir_permiso_accion($pdo,$u,$e,'oracle_pagos');
 $tipo=strtoupper(trim((string)($_POST['tipo_documento']??''))); $id=(int)($_POST['id_documento']??0); $est=strtoupper(trim((string)($_POST['estatus']??'')));
 $permitidos=['PENSION','DEMANDA','NO_CONSIDERAR','MANUAL']; if(!in_array($tipo,['FACTURA','EXP_DETALLE'],true)||$id<=0||!in_array($est,$permitidos,true)) throw new RuntimeException('Clasificación no válida.');
 $obs=trim((string)($_POST['observaciones']??''));
 // Una clasificación manual de NO-CFDI sustituye cualquier liga UUID manual previa.
 $pdo->prepare("UPDATE oracle_conciliacion_uuid_manual SET activo=0,id_usuario=?,fecha_actualizacion=NOW() WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=?")->execute([$u?:null,$e,$tipo,$id]);
 $sql="INSERT INTO oracle_conciliacion_clasificaciones(id_empresa,tipo_documento,id_documento_origen,estatus_asignado,origen_clasificacion,observaciones,id_usuario) VALUES(?,?,?,?, 'MANUAL', ?,?) ON DUPLICATE KEY UPDATE estatus_asignado=VALUES(estatus_asignado),origen_clasificacion='MANUAL',observaciones=VALUES(observaciones),id_usuario=VALUES(id_usuario),fecha_actualizacion=NOW()";
 $pdo->prepare($sql)->execute([$e,$tipo,$id,$est,$obs?:null,$u?:null]);
 $tabla=$tipo==='FACTURA'?'oracle_facturas':'oracle_gastos'; $pdo->prepare("UPDATE {$tabla} SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$est,$id,$e]);
 if(!empty($_POST['crear_regla'])){
   $pat=trim((string)($_POST['patron']??'')); if($pat==='') throw new RuntimeException('Capture la palabra o frase para crear la regla.');
   $campo=strtoupper(trim((string)($_POST['campo_busqueda']??'TODOS'))); if(!in_array($campo,['CONCEPTO','DOCUMENTO','PROVEEDOR','TODOS'],true))$campo='TODOS';
   $modo=strtoupper(trim((string)($_POST['tipo_coincidencia']??'CONTIENE'))); if(!in_array($modo,['CONTIENE','INICIA','EXACTO'],true))$modo='CONTIENE';
   $global=!empty($_POST['regla_global']); $idEmpRegla=$global?null:$e;
   $pdo->prepare("INSERT INTO oracle_conciliacion_reglas(id_empresa,patron,campo_busqueda,tipo_coincidencia,estatus_asignado,prioridad,activo,observaciones,id_usuario) VALUES(?,?,?,?,?,100,1,?,?)")
       ->execute([$idEmpRegla,$pat,$campo,$modo,$est,'Creada desde clasificación manual',$u?:null]);
 }
 echo json_encode(['success'=>true],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){http_response_code(400);echo json_encode(['success'=>false,'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
