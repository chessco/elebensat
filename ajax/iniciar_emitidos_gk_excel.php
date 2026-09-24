<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php'; require_once '../includes/reporte_emitidos_gk.php';
try{
    $idEmpresa=(int)($_SESSION['id_empresa']??0); $idUsuario=(int)($_SESSION['id_usuario']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'exportar');
    $inicio=trim((string)($_POST['inicio']??'')); $fin=trim((string)($_POST['fin']??''));
    $d1=DateTime::createFromFormat('Y-m-d',$inicio); $d2=DateTime::createFromFormat('Y-m-d',$fin);
    if(!$d1||!$d2||$d1->format('Y-m-d')!==$inicio||$d2->format('Y-m-d')!==$fin||$inicio>$fin)throw new RuntimeException('Rango de fechas inválido.');
    $finEx=(clone $d2)->modify('+1 day')->format('Y-m-d');
    $st=$pdo->prepare("SELECT COUNT(*) FROM facturas f WHERE f.id_empresa=? AND f.tipo_movimiento_empresa='I' AND UPPER(COALESCE(f.id_tipo_comprobante,'')) IN ('I','E') AND f.fecha_emision>=? AND f.fecha_emision<?");
    $st->execute([$idEmpresa,$inicio,$finEx]); $total=(int)$st->fetchColumn();
    if($total<=0){echo json_encode(['status'=>'sin_datos','msg'=>'No hay facturas ni notas de crédito emitidas en el rango seleccionado.']);exit;}
    $token=bin2hex(random_bytes(16)); $p=reg_paths($token);
    $m=['token'=>$token,'id_empresa'=>$idEmpresa,'id_usuario'=>$idUsuario,'inicio'=>$inicio,'fin'=>$fin,'fin_exclusiva'=>$finEx,
        'total'=>$total,'procesados'=>0,'renglones_facturas'=>0,'renglones_notas'=>0,'row_facturas'=>1,'row_notas'=>1,
        'last_fecha'=>null,'last_uuid'=>null,'cancelado'=>false,'completo'=>false,'creado'=>time()];
    file_put_contents($p['meta'],json_encode($m,JSON_UNESCAPED_UNICODE),LOCK_EX);
    reg_open_sheet($p['sheet_facturas'],'facturas'); reg_open_sheet($p['sheet_notas'],'notas');
    echo json_encode(['status'=>'ok','token'=>$token,'total'=>$total],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);seguridad_log_error($e,'iniciar_emitidos_gk_excel');echo json_encode(['status'=>'error','msg'=>$e->getMessage()?:'No se pudo iniciar el reporte de emitidos.']);}
