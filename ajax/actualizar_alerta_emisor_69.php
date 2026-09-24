<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php'; require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php'; require_once __DIR__.'/../includes/sat_69_alertas.php';
try{
    seguridad_exigir_sesion($pdo,true); exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69'); sat69_crear_tabla_alertas($pdo);
    $id=(int)($_POST['id_emisor']??0);$accion=strtolower(trim((string)($_POST['accion']??'')));
    $uid=(int)($_SESSION['id_usuario']??0);$idEmpresa=(int)($_SESSION['id_empresa']??0);
    if($id<=0||$idEmpresa<=0) throw new RuntimeException('Emisor o empresa no válidos.');
    if($accion==='vista'){
        $st=$pdo->prepare("UPDATE sat_69_alertas_emisores SET estatus_seguimiento='VISTA',id_usuario_visto=?,fecha_visto=NOW() WHERE id_emisor=? AND id_empresa=? AND activa=1 AND estatus_seguimiento='NUEVA'");
        $st->execute([$uid,$id,$idEmpresa]);
    }elseif($accion==='atendida'){
        $st=$pdo->prepare("UPDATE sat_69_alertas_emisores SET estatus_seguimiento='ATENDIDA',id_usuario_atendio=?,fecha_atendido=NOW(),observaciones=? WHERE id_emisor=? AND id_empresa=? AND activa=1");
        $st->execute([$uid,trim((string)($_POST['observaciones']??'')),$id,$idEmpresa]);
    }elseif($accion==='reabrir'){
        $st=$pdo->prepare("UPDATE sat_69_alertas_emisores SET estatus_seguimiento='VISTA',id_usuario_atendio=NULL,fecha_atendido=NULL WHERE id_emisor=? AND id_empresa=? AND activa=1");
        $st->execute([$id,$idEmpresa]);
    }else throw new RuntimeException('Acción no válida.');
    echo json_encode(['success'=>true]);
}catch(Throwable $e){seguridad_log_error($e,'actualizar_alerta_emisor_69');http_response_code(500);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
