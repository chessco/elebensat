<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)($_SESSION['id_usuario']??0);
    $e=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    $st=$pdo->prepare("SELECT id_conciliacion,anio,fecha_desde,fecha_hasta,total_empleados_reporte,total_empleados_visor,total_conciliados,total_diferencias,total_solo_reporte,total_solo_visor,total_cancelados,total_reporte,total_visor,diferencia,fecha_conciliacion FROM nomina_conciliaciones WHERE id_empresa=? ORDER BY id_conciliacion DESC LIMIT 100");
    $st->execute([$e]);
    echo json_encode(['data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){
    seguridad_log_error($x,'listar_nomina_conciliaciones');
    http_response_code(400);
    echo json_encode(['data'=>[],'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);
}
