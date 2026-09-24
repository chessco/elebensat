<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php'; require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php'; require_once __DIR__.'/../includes/sat_69b_alertas.php';
try{
    seguridad_exigir_sesion($pdo,true); exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69b'); sat69b_crear_tabla_alertas($pdo);
    $id=(int)($_GET['id_emisor']??0);$idEmpresa=(int)($_SESSION['id_empresa']??0);
    if($id<=0||$idEmpresa<=0) throw new RuntimeException('Emisor o empresa no válidos.');
    $st=$pdo->prepare("SELECT a.id_empresa,a.rfc,a.nombre_emisor,e.razon_social empresa FROM sat_69b_alertas_emisores a INNER JOIN empresas e ON e.id_empresa=a.id_empresa WHERE a.id_emisor=? AND a.id_empresa=? AND a.activa=1 LIMIT 1");
    $st->execute([$id,$idEmpresa]);$em=$st->fetch(PDO::FETCH_ASSOC);if(!$em)throw new RuntimeException('No se encontró alerta activa en la empresa seleccionada.');
    $st=$pdo->prepare("SELECT id_alerta,situacion,nombre_sat,fecha_publicacion_relevante,estatus_seguimiento,fecha_primera_deteccion,fecha_ultima_deteccion,observaciones FROM sat_69b_alertas_emisores WHERE id_emisor=? AND id_empresa=? AND activa=1 ORDER BY FIELD(situacion,'Definitivo','Presunto','Desvirtuado','Sentencia Favorable'),id_alerta");
    $st->execute([$id,$idEmpresa]);$sat=$st->fetchAll(PDO::FETCH_ASSOC);
    $st=$pdo->prepare("SELECT uuid,serie,folio,fecha_emision,total_xml,estatus_sat,id_tipo_comprobante,tipo_movimiento_empresa FROM facturas WHERE id_emisor=? AND id_empresa=? ORDER BY fecha_emision DESC");
    $st->execute([$id,$idEmpresa]);
    echo json_encode(['success'=>true,'emisor'=>$em,'sat'=>$sat,'cfdi'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){seguridad_log_error($e,'detalle_alerta_emisor_69b');http_response_code(500);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
