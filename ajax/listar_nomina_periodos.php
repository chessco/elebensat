<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
try{
 seguridad_exigir_sesion($pdo,true,false,true);
 $u=(int)($_SESSION['id_usuario']??0);$e=(int)($_SESSION['id_empresa']??0);
 exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
 $anio=(int)($_GET['anio']??0);$periodo=(int)($_GET['periodo']??0);
 $desde=trim((string)($_GET['desde']??''));$hasta=trim((string)($_GET['hasta']??''));
 $tipo=strtoupper(trim((string)($_GET['tipo_nomina']??'')));
 $sql="SELECT id_importacion,anio,periodo,COALESCE(periodo_desde,periodo) periodo_desde,COALESCE(periodo_hasta,periodo) periodo_hasta,fecha_desde,fecha_hasta,tipo_nomina,referencia_nomina,total_empleados,total_percepciones,total_deducciones,total_neto,nombre_archivo,estatus,fecha_importacion FROM nomina_importaciones WHERE id_empresa=?";
 $p=[$e];
 $hayDesde=preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$desde);
 $hayHasta=preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$hasta);
 if($hayDesde || $hayHasta){
   if($hayDesde){$sql.=' AND fecha_hasta>=?';$p[]=$desde;}
   if($hayHasta){$sql.=' AND fecha_desde<=?';$p[]=$hasta;}
 }else{
   if($anio>0){$sql.=' AND anio=?';$p[]=$anio;}
   if($periodo>0){$sql.=' AND ? BETWEEN COALESCE(periodo_desde,periodo) AND COALESCE(periodo_hasta,periodo)';$p[]=$periodo;}
 }
 if($tipo!==''){$sql.=' AND tipo_nomina=?';$p[]=$tipo;}
 $sql.=' ORDER BY anio DESC,COALESCE(periodo_hasta,periodo) DESC,fecha_importacion DESC';
 $st=$pdo->prepare($sql);$st->execute($p);
 echo json_encode(['data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){seguridad_log_error($x,'listar_nomina_periodos');http_response_code(400);echo json_encode(['data'=>[],'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);}
