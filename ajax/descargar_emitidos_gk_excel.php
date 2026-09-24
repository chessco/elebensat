<?php
ob_start(); session_start();
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php'; require_once '../includes/reporte_emitidos_gk.php';
try{
    $token=(string)($_GET['token']??''); if(!reg_valid_token($token))throw new RuntimeException('Token inválido.'); $p=reg_paths($token);
    if(!is_file($p['meta'])||!is_file($p['xlsx']))throw new RuntimeException('El archivo no está disponible.');
    $m=json_decode((string)file_get_contents($p['meta']),true); if(!is_array($m)||empty($m['completo']))throw new RuntimeException('El reporte todavía no ha terminado.');
    if((int)$m['id_usuario']!==(int)($_SESSION['id_usuario']??0)||(int)$m['id_empresa']!==(int)($_SESSION['id_empresa']??0))throw new RuntimeException('Proceso no autorizado.');
    exigir_permiso_accion($pdo,(int)$m['id_usuario'],(int)$m['id_empresa'],'exportar');
    $size=filesize($p['xlsx']); if($size===false||$size<4)throw new RuntimeException('El Excel generado está vacío.');
    while(ob_get_level()>0)ob_end_clean(); @ini_set('zlib.output_compression','Off');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Emitidos_GK_'.$m['inicio'].'_a_'.$m['fin'].'.xlsx"');
    header('Content-Length: '.$size); header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); readfile($p['xlsx']); exit;
}catch(Throwable $e){while(ob_get_level()>0)ob_end_clean();http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();exit;}
