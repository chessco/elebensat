<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; require_once '../includes/permisos_documentos.php';
try{
 seguridad_exigir_sesion($pdo,true,false,true); $u=(int)($_SESSION['id_usuario']??0); $e=(int)($_SESSION['id_empresa']??0); exigir_permiso_accion($pdo,$u,$e,'oracle_pagos');
 $accion=strtolower(trim((string)($_REQUEST['accion']??'listar')));
 if($accion==='listar'){
   $st=$pdo->prepare("SELECT r.*,CASE WHEN r.id_empresa IS NULL THEN 'GLOBAL' ELSE 'EMPRESA' END alcance FROM oracle_conciliacion_reglas r WHERE r.id_empresa=? OR r.id_empresa IS NULL ORDER BY r.activo DESC,r.prioridad,r.id"); $st->execute([$e]);
   echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE); exit;
 }
 if($accion==='guardar'){
   $id=(int)($_POST['id']??0); $pat=trim((string)($_POST['patron']??'')); if($pat==='')throw new RuntimeException('Capture palabra o frase.');
   $campo=strtoupper(trim((string)($_POST['campo_busqueda']??'TODOS'))); if(!in_array($campo,['CONCEPTO','DOCUMENTO','PROVEEDOR','TODOS'],true))$campo='TODOS';
   $modo=strtoupper(trim((string)($_POST['tipo_coincidencia']??'CONTIENE'))); if(!in_array($modo,['CONTIENE','INICIA','EXACTO'],true))$modo='CONTIENE';
   $est=strtoupper(trim((string)($_POST['estatus']??'NO_CONSIDERAR'))); if(!in_array($est,['PENSION','DEMANDA','NO_CONSIDERAR','MANUAL'],true))throw new RuntimeException('Estatus no válido.');
   $global=!empty($_POST['global']); $idEmp=$global?null:$e; $prio=max(1,(int)($_POST['prioridad']??100));
   if($id>0){
     $chk=$pdo->prepare("SELECT id_empresa FROM oracle_conciliacion_reglas WHERE id=? AND (id_empresa=? OR id_empresa IS NULL)");$chk->execute([$id,$e]);if($chk->fetchColumn()===false)throw new RuntimeException('Regla no encontrada.');
     $pdo->prepare("UPDATE oracle_conciliacion_reglas SET id_empresa=?,patron=?,campo_busqueda=?,tipo_coincidencia=?,estatus_asignado=?,prioridad=?,id_usuario=?,fecha_actualizacion=NOW() WHERE id=?")->execute([$idEmp,$pat,$campo,$modo,$est,$prio,$u?:null,$id]);
   }else $pdo->prepare("INSERT INTO oracle_conciliacion_reglas(id_empresa,patron,campo_busqueda,tipo_coincidencia,estatus_asignado,prioridad,activo,id_usuario) VALUES(?,?,?,?,?,?,1,?)")->execute([$idEmp,$pat,$campo,$modo,$est,$prio,$u?:null]);
   echo json_encode(['success'=>true],JSON_UNESCAPED_UNICODE); exit;
 }
 $id=(int)($_POST['id']??0); if($id<=0)throw new RuntimeException('Regla no válida.');
 if($accion==='toggle'){$pdo->prepare("UPDATE oracle_conciliacion_reglas SET activo=1-activo,fecha_actualizacion=NOW() WHERE id=? AND (id_empresa=? OR id_empresa IS NULL)")->execute([$id,$e]);}
 elseif($accion==='borrar'){$pdo->prepare("DELETE FROM oracle_conciliacion_reglas WHERE id=? AND (id_empresa=? OR id_empresa IS NULL)")->execute([$id,$e]);}
 else throw new RuntimeException('Acción no válida.');
 echo json_encode(['success'=>true],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){http_response_code(400);echo json_encode(['success'=>false,'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
