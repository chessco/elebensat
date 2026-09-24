<?php
session_start();
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/nomina_fuente.php';
$tmp=null;
try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)$_SESSION['id_usuario']; $e=(int)$_SESSION['id_empresa'];
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    $action=(string)($_GET['accion']??'ver');
    if (!in_array($action,['ver','descargar'],true)) throw new RuntimeException('Acción no válida.');
    if ($action==='descargar') exigir_permiso_accion($pdo,$u,$e,'exportar');
    session_write_close();
    $id=(int)($_GET['id_importacion']??0);
    if ($id<=0) throw new RuntimeException('Importación no válida.');
    nf_require_schema($pdo);
    $source=nf_latest($pdo,$e,$id);
    if ($action==='descargar') {
        $tmp=tempnam(sys_get_temp_dir(),'nf_download_');
        if ($tmp===false) throw new RuntimeException('No se pudo crear el temporal.');
        nf_restore($pdo,$source,$tmp);
        $ext=strtolower($source['extension']);
        if (!in_array($ext,['xls','xlsx'],true)) throw new RuntimeException('Extensión de archivo no válida.');
        while(ob_get_level()>0) ob_end_clean();
        @ini_set('zlib.output_compression','0');
        header('Content-Type: '.($ext==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'application/vnd.ms-excel'));
        header('Content-Disposition: attachment; filename="Nomina_original_'.$id.'_'.$source['id_fuente'].'.'.$ext.'"');
        header('Content-Length: '.(int)$source['bytes_archivo']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($tmp);
    } else {
        // Siempre ligado a empresa/importación; nunca aceptar un id_fuente arbitrario.
        $page=max(1,(int)($_GET['pagina']??1)); $size=25;
        $pages=max(1,(int)ceil((int)$source['total_filas']/$size)); $page=min($page,$pages);
        $q=$pdo->prepare('SELECT renglon,clase,numero_empleado,rfc,valores_json FROM nomina_fuente_filas WHERE id_fuente=? ORDER BY renglon LIMIT '.$size.' OFFSET '.(($page-1)*$size));
        $q->execute([(int)$source['id_fuente']]); $rows=[];
        while($r=$q->fetch(PDO::FETCH_ASSOC)) {
            $r['valores']=json_decode($r['valores_json'],true,512,JSON_THROW_ON_ERROR); unset($r['valores_json']); $rows[]=$r;
        }
        $fields=nf_fields($pdo,$e,json_decode($source['columnas_json'],true,512,JSON_THROW_ON_ERROR));
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo nf_json(['success'=>true,'archivo'=>$source['nombre_archivo'],'hoja'=>$source['hoja'],'hash'=>$source['hash_archivo'],
            'total_columnas'=>(int)$source['total_columnas'],'total_filas'=>(int)$source['total_filas'],
            'pagina'=>$page,'paginas'=>$pages,'columnas'=>$fields['columnas'],'pendientes'=>$fields['pendientes'],'filas'=>$rows]);
    }
} catch (Throwable $ex) {
    seguridad_log_error($ex,'nomina_reporte_completo');
    if (!headers_sent()) {
        if (http_response_code()<400) http_response_code(400);
        header_remove('Content-Disposition'); header_remove('Content-Length');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>$ex instanceof PDOException?'No se pudo consultar el reporte guardado.':$ex->getMessage()],JSON_UNESCAPED_UNICODE);
    }
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
}
