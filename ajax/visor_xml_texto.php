<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    if (empty($_SESSION['id_empresa'])) {
        throw new Exception('Acceso denegado');
    }

    $uuid = trim($_GET['uuid'] ?? '');
    if ($uuid === '') {
        throw new Exception('UUID no proporcionado');
    }

    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)$_SESSION['id_empresa'];
    exigir_permiso_factura_uuid($pdo, $idUsuario, $idEmpresa, $uuid);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_xml_pdf');

    $stmt = $pdo->prepare("SELECT d.xml_base64
        FROM facturas_datos d
        INNER JOIN facturas f ON f.uuid = d.uuid
        WHERE d.uuid = ? AND f.id_empresa = ?
        LIMIT 1");
    $stmt->execute([$uuid, $idEmpresa]);
    $xmlB64 = $stmt->fetchColumn();

    if (!$xmlB64) {
        throw new Exception('XML no encontrado en el respaldo');
    }

    $xml = base64_decode($xmlB64, true);
    if ($xml === false) {
        throw new Exception('El contenido almacenado no es Base64 válido');
    }

    // IMPORTANTE: aquí se entrega el contenido EXACTO guardado en la base.
    // No se valida, no se repara y no se parsea. Sirve para diagnóstico/copia.
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    seguridad_log_error($e, 'visor_xml_texto');
    echo 'No se pudo cargar el XML solicitado para diagnóstico.';
}
