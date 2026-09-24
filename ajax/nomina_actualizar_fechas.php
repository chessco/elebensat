<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

function fecha_valida_nomina(string $v): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    return $d && $d->format('Y-m-d')===$v;
}

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)($_SESSION['id_usuario']??0);$e=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    $id=(int)($_POST['id_importacion']??0);
    $desde=trim((string)($_POST['fecha_desde']??''));$hasta=trim((string)($_POST['fecha_hasta']??''));
    if($id<=0) throw new RuntimeException('Importación no válida.');
    if(!fecha_valida_nomina($desde)||!fecha_valida_nomina($hasta)) throw new RuntimeException('Capture fechas válidas.');
    if($desde>$hasta) throw new RuntimeException('La fecha inicial no puede ser mayor que la fecha final.');

    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT id_importacion,id_empresa,anio,periodo,COALESCE(periodo_desde,periodo) periodo_desde,COALESCE(periodo_hasta,periodo) periodo_hasta,tipo_nomina,fecha_desde,fecha_hasta FROM nomina_importaciones WHERE id_importacion=? AND id_empresa=? FOR UPDATE');
    $st->execute([$id,$e]);$r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new RuntimeException('La nómina no existe o no pertenece a la empresa activa.');

    $dup=$pdo->prepare('SELECT id_importacion FROM nomina_importaciones WHERE id_empresa=? AND anio=? AND COALESCE(periodo_desde,periodo)=? AND COALESCE(periodo_hasta,periodo)=? AND tipo_nomina=? AND fecha_desde=? AND fecha_hasta=? AND id_importacion<>? LIMIT 1');
    $dup->execute([$e,$r['anio'],$r['periodo_desde'],$r['periodo_hasta'],$r['tipo_nomina'],$desde,$hasta,$id]);
    if($dup->fetchColumn()) throw new RuntimeException('Ya existe otra importación con el mismo tipo, periodos y rango de fechas.');

    $up=$pdo->prepare('UPDATE nomina_importaciones SET fecha_desde=?,fecha_hasta=?,fecha_actualizacion=NOW() WHERE id_importacion=? AND id_empresa=?');
    $up->execute([$desde,$hasta,$id,$e]);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Rango de fechas actualizado correctamente.','fecha_desde'=>$desde,'fecha_hasta'=>$hasta],JSON_UNESCAPED_UNICODE);
}catch(Throwable $x){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    seguridad_log_error($x,'nomina_actualizar_fechas');http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE);
}
