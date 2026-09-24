<?php
/*
 * Descarga XLSX de detalle XML recibidos.
 * IMPORTANTE: se usa buffer desde el primer byte para impedir que espacios,
 * saltos de linea o BOM de archivos incluidos contaminen el ZIP/XLSX.
 */
ob_start();

session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/reporte_xml_recibidos.php';

try {
    $token = (string)($_GET['token'] ?? '');
    if (!rxr_valid_token($token)) {
        throw new RuntimeException('Token inválido.');
    }

    $p = rxr_paths($token);
    if (!is_file($p['meta']) || !is_file($p['xlsx'])) {
        throw new RuntimeException('El archivo no está disponible.');
    }

    $m = json_decode((string)file_get_contents($p['meta']), true);
    if (!is_array($m) || empty($m['completo'])) {
        throw new RuntimeException('El reporte todavía no ha terminado.');
    }

    if (
        (int)$m['id_usuario'] !== (int)($_SESSION['id_usuario'] ?? 0) ||
        (int)$m['id_empresa'] !== (int)($_SESSION['id_empresa'] ?? 0)
    ) {
        throw new RuntimeException('Proceso no autorizado.');
    }

    exigir_permiso_accion(
        $pdo,
        (int)$m['id_usuario'],
        (int)$m['id_empresa'],
        'exportar'
    );

    $size = filesize($p['xlsx']);
    if ($size === false || $size < 4) {
        throw new RuntimeException('El XLSX generado está vacío o incompleto.');
    }

    // Validación rápida: un XLSX válido debe iniciar exactamente con PK.
    $fh = fopen($p['xlsx'], 'rb');
    if (!$fh) {
        throw new RuntimeException('No se pudo leer el XLSX generado.');
    }
    $magic = fread($fh, 4);
    fclose($fh);
    if ($magic === false || substr($magic, 0, 2) !== 'PK') {
        throw new RuntimeException('El XLSX generado no tiene una estructura ZIP válida.');
    }

    $nombre = 'XML_recibidos_'.$m['inicio'].'_a_'.$m['fin'].'.xlsx';

    // CRITICO: eliminar TODO lo que cualquier include haya enviado antes.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Evitar que PHP/Apache modifique la salida binaria.
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: '.$size);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    $in  = fopen($p['xlsx'], 'rb');
    if (!$out || !$in) {
        throw new RuntimeException('No se pudo iniciar la descarga del archivo.');
    }

    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    exit;

} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}
