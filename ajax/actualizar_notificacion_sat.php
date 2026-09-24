<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try{
    if(empty($_SESSION['id_usuario'])||empty($_SESSION['id_empresa'])){http_response_code(401);throw new RuntimeException('Sesión no válida.');}
    exigir_permiso_accion($pdo,(int)$_SESSION['id_usuario'],(int)$_SESSION['id_empresa'],'ver_solicitudes_sat');
    $id=(int)($_POST['id']??0);$accion=strtolower(trim((string)($_POST['accion']??'')));$obs=trim((string)($_POST['observaciones']??''));
    if($id<=0)throw new RuntimeException('Notificación inválida.');
    $empresa=(int)$_SESSION['id_empresa'];$usuario=(int)$_SESSION['id_usuario'];
    if($accion==='vista'){
        $sql="UPDATE notificaciones_sat SET estatus_notificacion=IF(estatus_notificacion='NUEVA','VISTA',estatus_notificacion), id_usuario_visto=COALESCE(id_usuario_visto,?), fecha_visto=COALESCE(fecha_visto,NOW()) WHERE id=? AND id_empresa=?";
        $pdo->prepare($sql)->execute([$usuario,$id,$empresa]);
    }elseif($accion==='atendida'){
        $sql="UPDATE notificaciones_sat SET estatus_notificacion='ATENDIDA', id_usuario_visto=COALESCE(id_usuario_visto,?), fecha_visto=COALESCE(fecha_visto,NOW()), id_usuario_atendio=?, fecha_atendido=NOW(), observaciones=? WHERE id=? AND id_empresa=?";
        $pdo->prepare($sql)->execute([$usuario,$usuario,$obs,$id,$empresa]);
    }elseif($accion==='reabrir'){
        $sql="UPDATE notificaciones_sat SET estatus_notificacion='VISTA', id_usuario_atendio=NULL, fecha_atendido=NULL WHERE id=? AND id_empresa=?";
        $pdo->prepare($sql)->execute([$id,$empresa]);
    }else throw new RuntimeException('Acción no reconocida.');
    echo json_encode(['success'=>true]);
}catch(Throwable $e){seguridad_log_error($e, 'actualizar_notificacion_sat'); echo json_encode(['success'=>false,'error'=>'No se pudo actualizar la notificación.'],JSON_UNESCAPED_UNICODE);}
