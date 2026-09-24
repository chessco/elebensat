<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/xml_respaldo.php';

try {
    if (empty($_SESSION['id_empresa'])) {
        throw new Exception('Acceso denegado');
    }

    $uuid = trim($_GET['uuid'] ?? '');
    if ($uuid === '') {
        throw new Exception('UUID no proporcionado');
    }
    exigir_permiso_factura_uuid($pdo, (int)($_SESSION['id_usuario'] ?? 0), (int)$_SESSION['id_empresa'], $uuid);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)$_SESSION['id_empresa'], isset($_GET['descargar']) ? 'descargar_xml' : 'ver_xml_pdf');

    $stmt = $pdo->prepare("SELECT d.xml_base64
        FROM facturas_datos d
        INNER JOIN facturas f ON f.uuid = d.uuid
        WHERE d.uuid = ? AND f.id_empresa = ?
        LIMIT 1");
    $stmt->execute([$uuid, (int) $_SESSION['id_empresa']]);
    $xmlB64 = $stmt->fetchColumn();

    if (!$xmlB64) {
        throw new Exception('XML no encontrado en el respaldo');
    }

    $xml = xml_respaldo_decodificar((string)$xmlB64);
    $xmlReparadoPresentacion = false;

    // Reparación directa y conservadora del cierre FINAL del nodo raíz.
    // Caso real detectado en producción: </cfdi:Comproban
    // No modifica la BD: sólo corrige la copia enviada al navegador/PDF.
    $xml = rtrim($xml);
    $cierresPosibles = [
        '</cfdi:Comproban',
        '</cfdi:Comprobant',
        '</cfdi:Comprobante',
        '</Comproban',
        '</Comprobant',
        '</Comprobante'
    ];
    foreach ($cierresPosibles as $cierreIncompleto) {
        $largo = strlen($cierreIncompleto);
        if ($largo > 0 && strlen($xml) >= $largo && strcasecmp(substr($xml, -$largo), $cierreIncompleto) === 0) {
            $prefijoCfdi = stripos($cierreIncompleto, 'cfdi:') !== false;
            $xml = substr($xml, 0, -$largo) . ($prefijoCfdi ? '</cfdi:Comprobante>' : '</Comprobante>');
            $xmlReparadoPresentacion = true;
            break;
        }
    }

    // Respaldo adicional para cualquier variante de cierre truncado compatible.
    if (!$xmlReparadoPresentacion) {
        $xml = xml_respaldo_reparar_cierre_final($xml, $xmlReparadoPresentacion);
    }

    // El navegador intenta interpretar application/xml. Si sigue mal formado, mejor
    // mostrar un diagnóstico entendible en vez del error crudo "token no cerrado".
    $erroresXml = [];
    if (!xml_respaldo_es_valido($xml, $erroresXml)) {
        http_response_code(422);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>XML incompleto</title>';
        echo '<body style="font-family:Arial,sans-serif;background:#f5f7fa;padding:30px"><div style="max-width:850px;margin:auto;background:#fff;border-left:5px solid #dc3545;padding:20px">';
        echo '<h3 style="margin-top:0;color:#b02a37">XML almacenado incompleto o mal formado</h3>';
        echo '<p><b>UUID:</b> ' . htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p>El registro sí existe en <code>facturas_datos.xml_base64</code>, pero el contenido no forma un XML válido.</p>';
        if ($erroresXml) echo '<p style="color:#666">' . htmlspecialchars(implode(' | ', array_slice($erroresXml,0,3)), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><a href="generar_pdf.php?uuid=' . rawurlencode($uuid) . '">Intentar representación PDF desde el respaldo</a></p>';
        echo '</div></body></html>';
        exit;
    }

    $nombre = preg_replace('/[^A-Za-z0-9_-]/', '_', $uuid) . '.xml';

    // MUY IMPORTANTE:
    // Limpiar cualquier salida accidental de archivos incluidos (BOM/espacios).
    // Si se manda Content-Length sólo con strlen($xml) y hubo bytes previos,
    // el navegador corta exactamente esos bytes del FINAL del XML.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    if ($xmlReparadoPresentacion) header('X-SGKSAT-XML-Reparado-Presentacion: cierre-final');
    if (isset($_GET['descargar'])) {
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $nombre . '"');
    }
    // NO enviar Content-Length manual. Apache/PHP calcularán la respuesta completa.
    echo $xml;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    seguridad_log_error($e, 'visor_xml'); echo 'No se pudo abrir el XML solicitado.';
}
