<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php'; require_once __DIR__.'/../includes/seguridad.php'; require_once __DIR__.'/../includes/permisos_documentos.php';
try{
 seguridad_exigir_sesion($pdo,true,false,true); $u=(int)($_SESSION['id_usuario']??0);$e=(int)($_SESSION['id_empresa']??0); exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
 $id=(int)($_GET['id_conciliacion']??0); if($id<=0) throw new RuntimeException('Conciliación no válida.');
 $h=$pdo->prepare("SELECT * FROM nomina_conciliaciones WHERE id_conciliacion=? AND id_empresa=? LIMIT 1");$h->execute([$id,$e]);$head=$h->fetch(PDO::FETCH_ASSOC);if(!$head)throw new RuntimeException('No se encontró la conciliación.');
 $d=$pdo->prepare("SELECT numero_empleado,rfc,nombre_completo,usa_desglose,percepciones_visor,percepciones_reporte,diferencia_percepciones,deducciones_visor,deducciones_reporte,diferencia_deducciones,total_visor,total_reporte,diferencia,documentos_visor,documentos_cancelados,periodos_reporte,estatus FROM nomina_conciliacion_detalles WHERE id_conciliacion=? AND id_empresa=? ORDER BY numero_empleado,rfc");$d->execute([$id,$e]);
 echo json_encode(['success'=>true,'resumen'=>$head,'data'=>$d->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){seguridad_log_error($x,'listar_nomina_conciliacion_detalle');http_response_code(400);echo json_encode(['success'=>false,'data'=>[],'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
