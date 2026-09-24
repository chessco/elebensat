<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

require_once __DIR__ . '/../includes/nomina_conciliacion_xlsx.php';

$tmp = null;
try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $id = (int)($_GET['id_conciliacion'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Conciliación no válida.');

    $st = $pdo->prepare("SELECT razon_social, rfc FROM empresas WHERE id_empresa=? LIMIT 1");
    $st->execute([$idEmpresa]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT * FROM nomina_conciliaciones WHERE id_conciliacion=? AND id_empresa=? LIMIT 1");
    $st->execute([$id, $idEmpresa]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) throw new RuntimeException('La conciliación no existe o no pertenece a la empresa activa.');

    $st = $pdo->prepare("SELECT numero_empleado,rfc,nombre_completo,usa_desglose,percepciones_visor,percepciones_reporte,diferencia_percepciones,deducciones_visor,deducciones_reporte,diferencia_deducciones,total_visor,total_reporte,diferencia,documentos_visor,documentos_cancelados,periodos_reporte,estatus
                         FROM nomina_conciliacion_detalles
                         WHERE id_conciliacion=? AND id_empresa=?
                         ORDER BY numero_empleado,rfc");
    $st->execute([$id, $idEmpresa]);
    $tmp = tempnam(sys_get_temp_dir(), 'ncx_export_');
    if ($tmp === false) throw new RuntimeException('No se pudo crear el archivo temporal.');
    // Construir por completo antes de enviar encabezados o bytes de descarga.
    ncx_build($tmp, $empresa, $h, $st);
    $st->closeCursor();
    clearstatcache(true, $tmp);
    $size = filesize($tmp);
    if (!$size || file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04") {
        throw new RuntimeException('No se pudo completar el archivo Excel.');
    }
    $desde = preg_replace('/[^0-9]/', '', substr((string)$h['fecha_desde'], 0, 10));
    $hasta = preg_replace('/[^0-9]/', '', substr((string)$h['fecha_hasta'], 0, 10));
    $nombre = 'Conciliacion_Nomina_'.$id.'_'.$desde.'_'.$hasta.'.xlsx';
    // Evitar que una salida previa o la compresión PHP alteren el ZIP/Content-Length.
    while (ob_get_level() > 0) ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    header('Content-Length: '.$size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
} catch (Throwable $e) {
    if (isset($pdo)) seguridad_log_error($e, 'exportar_nomina_conciliacion_excel');
    if (!headers_sent()) {
        if (http_response_code() < 400) http_response_code(400);
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo exportar la conciliación de nómina: '.$e->getMessage();
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
}
