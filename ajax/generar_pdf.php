<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/xml_respaldo.php';
require_once '../includes/pdf_cfdi_desde_xml.php';

try {
    if (!isset($_SESSION['id_empresa'])) {
        throw new RuntimeException('Acceso denegado.');
    }

    $idEmpresa = (int)$_SESSION['id_empresa'];
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $uuid = strtoupper(trim((string)($_GET['uuid'] ?? '')));
    $esExportacion = isset($_GET['exportar']);

    if ($uuid === '') {
        throw new InvalidArgumentException('UUID no proporcionado.');
    }

    exigir_permiso_factura_uuid($pdo, $idUsuario, $idEmpresa, $uuid);
    if ($esExportacion) {
        exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'descargar_pdf');
        exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'exportar');
    } else {
        exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_xml_pdf');
    }

    // IMPORTANTE: este visor NO usa f.ruta_pdf ni f.pdf_generado.
    // Siempre reconstruye una representación nueva a partir del XML respaldado en BD.
    $stmt = $pdo->prepare(
        "SELECT f.folio, f.serie, d.xml_base64
         FROM facturas f
         INNER JOIN facturas_datos d ON d.uuid = f.uuid
         WHERE f.uuid = ? AND f.id_empresa = ?
         LIMIT 1"
    );
    $stmt->execute([$uuid, $idEmpresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || trim((string)($row['xml_base64'] ?? '')) === '') {
        throw new RuntimeException('No existe respaldo XML en la base para generar el PDF.');
    }

    $xmlRaw = xml_respaldo_decodificar((string)$row['xml_base64']);
    $reparado = false;
    $xmlRaw = xml_respaldo_reparar_cierre_final($xmlRaw, $reparado);

    $erroresXml = [];
    if (!xml_respaldo_es_valido($xmlRaw, $erroresXml)) {
        throw new RuntimeException(
            'El XML almacenado no es válido para generar el PDF. ' .
            implode(' | ', array_slice($erroresXml, 0, 2))
        );
    }

    $pdf = pdf_cfdi_desde_xml($xmlRaw);
    if ($pdf === '' || strpos($pdf, '%PDF-') !== 0) {
        throw new RuntimeException('El generador no produjo un PDF válido.');
    }

    $serie = trim((string)($row['serie'] ?? ''));
    $folio = trim((string)($row['folio'] ?? ''));
    $base = trim(($serie !== '' ? $serie . '-' : '') . $folio, '- ');
    if ($base === '') $base = $uuid;
    $base = preg_replace('/[<>:\"\/\\|?*\x00-\x1F]+/u', '_', $base) ?: $uuid;
    $nombre = 'CFDI_' . $base . '.pdf';

    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-SGKSAT-PDF-Source: XML-DB-ADOBE-SAFE');

    $disp = $esExportacion ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    echo $pdf;
    exit;
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    }
    header('Content-Type: text/plain; charset=UTF-8');
    seguridad_log_error($e, 'generar_pdf');
    echo 'No se pudo generar el PDF.';
}
