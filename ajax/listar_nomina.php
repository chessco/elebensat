<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $u=(int)($_SESSION['id_usuario']??0); $e=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    $anio=(int)($_GET['anio']??0);$periodo=(int)($_GET['periodo']??0);
    $desde=trim((string)($_GET['desde']??''));$hasta=trim((string)($_GET['hasta']??''));
    $tipo=strtoupper(trim((string)($_GET['tipo_nomina']??'')));
    $sql="SELECT d.id_detalle,d.numero_empleado,d.nombre,d.apellido_paterno,d.apellido_materno,d.rfc,d.total_percepciones,d.total_deducciones,d.neto,i.anio,i.periodo,COALESCE(i.periodo_desde,i.periodo) periodo_desde,COALESCE(i.periodo_hasta,i.periodo) periodo_hasta,i.fecha_desde,i.fecha_hasta,i.tipo_nomina,i.referencia_nomina,i.nombre_archivo,i.estatus,i.fecha_importacion FROM nomina_detalles d INNER JOIN nomina_importaciones i ON i.id_importacion=d.id_importacion WHERE d.id_empresa=?";
    $p=[$e];
    $hayDesde=preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde);$hayHasta=preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta);
    if($hayDesde || $hayHasta){
        if($hayDesde){$sql.=' AND i.fecha_hasta>=?';$p[]=$desde;}
        if($hayHasta){$sql.=' AND i.fecha_desde<=?';$p[]=$hasta;}
    }else{
        if($anio>0){$sql.=' AND i.anio=?';$p[]=$anio;}
        if($periodo>0){$sql.=' AND ? BETWEEN COALESCE(i.periodo_desde,i.periodo) AND COALESCE(i.periodo_hasta,i.periodo)';$p[]=$periodo;}
    }
    if($tipo!==''){$sql.=' AND i.tipo_nomina=?';$p[]=$tipo;}
    $sql.=' ORDER BY i.anio DESC,COALESCE(i.periodo_hasta,i.periodo) DESC,d.numero_empleado,d.rfc';
    $st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data'=>$rows],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){seguridad_log_error($x,'listar_nomina');http_response_code(400);echo json_encode(['data'=>[],'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
