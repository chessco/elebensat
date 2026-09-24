<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
header('Content-Type: application/json; charset=utf-8');

function json_out(array $r, int $code=200): void {
    http_response_code($code);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    if($idUsuario<=0||$idEmpresa<=0) json_out(['success'=>false,'error'=>'Sesión no válida.'],401);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'ver_solicitudes_sat');

    if($_SERVER['REQUEST_METHOD']!=='POST') json_out(['success'=>false,'error'=>'Método no permitido.'],405);

    $idDescarga=(int)($_POST['id_descarga']??0);
    if($idDescarga<=0) json_out(['success'=>false,'error'=>'Solicitud inválida.'],400);

    $st=$pdo->prepare("SELECT id,id_solicitud FROM descargas_sat WHERE id=? AND id_empresa=? LIMIT 1");
    $st->execute([$idDescarga,$idEmpresa]);
    $descarga=$st->fetch(PDO::FETCH_ASSOC);
    if(!$descarga) json_out(['success'=>false,'error'=>'La solicitud no pertenece a la empresa activa.'],404);

    $st=$pdo->prepare("SELECT id,ruta_zip FROM descargas_sat_paquetes WHERE id_descarga_sat=? AND ruta_zip IS NOT NULL AND ruta_zip<>'' ORDER BY id");
    $st->execute([$idDescarga]);
    $paquetes=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$paquetes) json_out(['success'=>false,'error'=>'Esta solicitud no tiene paquetes ZIP guardados para reprocesar.'],409);

    // Detectar columnas disponibles para ser compatible con instalaciones anteriores.
    $cols=[];
    foreach($pdo->query("SHOW COLUMNS FROM descargas_sat_paquetes") as $c){$cols[$c['Field']]=true;}

    // IMPORTANTE PARA PROCESSWORKER:
    // - estatus=1 significa que el ZIP YA está descargado y disponible.
    // - estatus_proceso=0 lo regresa a la cola de extracción/procesamiento.
    // No se vuelve a solicitar ni descargar nada del SAT.
    $set=["estatus=1"];
    if(isset($cols['estatus_proceso'])) $set[]="estatus_proceso=0";
    foreach(['total_archivos','archivos_extraidos','archivos_procesados','archivos_duplicados','archivos_error','intentos_proceso'] as $c){
        if(isset($cols[$c])) $set[]="$c=0";
    }
    foreach(['fecha_inicio_proceso','fecha_fin_proceso','ultimo_archivo_procesado','mensaje_error','error_proceso','procesando_por'] as $c){
        if(isset($cols[$c])) $set[]="$c=NULL";
    }
    if(isset($cols['fecha_ultimo_avance'])) $set[]="fecha_ultimo_avance=NOW()";

    $sqlPaquete="UPDATE descargas_sat_paquetes SET ".implode(',',$set)." WHERE id=?";
    $updPaquete=$pdo->prepare($sqlPaquete);

    // Volver a poner en pendiente TODOS los archivos ya registrados del ZIP.
    // Esto es indispensable porque RegistrarArchivosExtraidosAsync conserva el
    // estatus anterior en ON DUPLICATE KEY; si no se resetea aquí, el Worker
    // extrae el ZIP pero no encuentra archivos pendientes para procesar.
    $updArchivos=$pdo->prepare(
        "UPDATE descargas_sat_archivos
         SET estatus=0, intentos=0, mensaje=NULL, uuid=NULL, fecha_procesado=NULL
         WHERE id_paquete=?"
    );

    $pdo->beginTransaction();
    $total=0;
    foreach($paquetes as $p){
        $idPaquete=(int)$p['id'];
        $updArchivos->execute([$idPaquete]);
        $updPaquete->execute([$idPaquete]);
        $total++;
    }
    $pdo->commit();

    json_out([
        'success'=>true,
        'message'=>"Listo: {$total} ZIP".($total===1?' fue marcado':' fueron marcados')." para reprocesar. El ProcessWorker los tomará en la siguiente vuelta.",
        'total_paquetes'=>$total,
        'id_descarga'=>$idDescarga
    ]);
} catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if(function_exists('seguridad_log_error')) seguridad_log_error($e,'reprocesar_zip_sat');
    json_out(['success'=>false,'error'=>$e->getMessage()],500);
}
