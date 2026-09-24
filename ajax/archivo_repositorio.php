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
    $uuid = trim((string)($_GET['uuid'] ?? ''));
    $tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
    // Nombre amigable solicitado desde el visor. Solo afecta Content-Disposition;
    // nunca modifica el UUID, folio ni datos fiscales guardados.
    $nombreSolicitado = trim((string)($_GET['nombre'] ?? ''));

    if ($uuid === '') {
        throw new InvalidArgumentException('UUID no proporcionado.');
    }
    if (!in_array($tipo, ['xml', 'pdf'], true)) {
        throw new InvalidArgumentException('Tipo de archivo no válido.');
    }

    exigir_permiso_factura_uuid($pdo, $idUsuario, $idEmpresa, $uuid);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'exportar');
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, $tipo === 'xml' ? 'descargar_xml' : 'descargar_pdf');

    if ($tipo === 'xml') {
        $stmt = $pdo->prepare(
            "SELECT d.ruta_xml, d.nombre_archivo
             FROM facturas_datos d
             INNER JOIN facturas f ON f.uuid = d.uuid
             WHERE d.uuid = ? AND f.id_empresa = ?
             LIMIT 1"
        );
        $stmt->execute([$uuid, $idEmpresa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || trim((string)($row['ruta_xml'] ?? '')) === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'XML sin ruta física en el repositorio.';
            exit;
        }

        $ruta = trim((string)$row['ruta_xml']);
        if (!is_file($ruta) || !is_readable($ruta)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'XML físico no encontrado o sin permiso de lectura.';
            exit;
        }

        $nombre = trim((string)($row['nombre_archivo'] ?? ''));
        if ($nombre === '') {
            $nombre = $uuid . '.xml';
        }
        if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xml') {
            $nombre .= '.xml';
        }
        $mime = 'application/xml';
    } else {
        if ($nombreSolicitado !== '') {
            // Descarga manual PDF+XML del visor: NO reutilizar el PDF físico.
            // Se reconstruye un PDF nuevo en memoria usando el XML respaldado en la tabla.
            $stmt = $pdo->prepare(
                "SELECT d.xml_base64
                 FROM facturas_datos d
                 INNER JOIN facturas f ON f.uuid = d.uuid
                 WHERE d.uuid = ? AND f.id_empresa = ?
                 LIMIT 1"
            );
            $stmt->execute([$uuid, $idEmpresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || trim((string)($row['xml_base64'] ?? '')) === '') {
                http_response_code(404);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'No existe respaldo XML en la base para regenerar el PDF.';
                exit;
            }

            $xmlRaw = xml_respaldo_decodificar((string)$row['xml_base64']);
            $reparado = false;
            $xmlRaw = xml_respaldo_reparar_cierre_final($xmlRaw, $reparado);
            $erroresXml = [];
            if (!xml_respaldo_es_valido($xmlRaw, $erroresXml)) {
                throw new RuntimeException('El XML respaldado no es válido para regenerar el PDF. ' . implode(' | ', array_slice($erroresXml, 0, 2)));
            }

            $contenidoGenerado = pdf_cfdi_desde_xml($xmlRaw);
            $nombre = $uuid . '.pdf';
            $mime = 'application/pdf';
            $ruta = null;
            $pdfGeneradoEnMemoria = true;
        } else {
            // Resto de exportaciones: conservar comportamiento previo con el repositorio físico.
            $stmt = $pdo->prepare(
                "SELECT f.pdf_generado, f.ruta_pdf
                 FROM facturas f
                 WHERE f.uuid = ? AND f.id_empresa = ?
                 LIMIT 1"
            );
            $stmt->execute([$uuid, $idEmpresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $ruta = trim((string)($row['ruta_pdf'] ?? ''));
            if (!$row || (int)($row['pdf_generado'] ?? 0) !== 1 || $ruta === '' || !is_file($ruta) || !is_readable($ruta)) {
                http_response_code(204);
                header('X-SGKSAT-Archivo-Omitido: pdf-no-disponible');
                exit;
            }
            $nombre = $uuid . '.pdf';
            $mime = 'application/pdf';
            $pdfGeneradoEnMemoria = false;
        }
    }

    if ($nombreSolicitado !== '') {
        // Permitimos letras UTF-8 y espacios; eliminamos caracteres inválidos de Windows/HTTP.
        $baseSolicitada = preg_replace('/[<>:\"\/\\|?*\x00-\x1F]+/u', ' ', $nombreSolicitado);
        $baseSolicitada = preg_replace('/\s+/u', ' ', trim((string)$baseSolicitada));
        $baseSolicitada = mb_substr($baseSolicitada, 0, 180, 'UTF-8');
        if ($baseSolicitada !== '') {
            $nombre = $baseSolicitada . '.' . $tipo;
        }
    }

    if ($tipo === 'pdf' && !empty($pdfGeneradoEnMemoria)) {
        $tamano = strlen($contenidoGenerado);
    } else {
        $tamano = filesize($ruta);
        if ($tamano === false) {
            throw new RuntimeException('No fue posible obtener el tamaño del archivo físico.');
        }
    }

    // Evita que cualquier BOM, espacio o warning previo contamine el binario PDF.
    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: ' . $mime);
    $nombreUtf8 = basename($nombre);
    $nombreAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombreUtf8) ?: $nombreUtf8);
    if ($nombreAscii === '' || $nombreAscii === false) {
        $nombreAscii = $uuid . '.' . $tipo;
    }
    header('Content-Disposition: attachment; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nombreUtf8));
    header('Content-Length: ' . $tamano);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');

    if ($tipo === 'pdf' && !empty($pdfGeneradoEnMemoria)) {
        header('X-SGKSAT-PDF-Source: XML-DB-ADOBE-SAFE');
        echo $contenidoGenerado;
    } else {
        $fh = fopen($ruta, 'rb');
        if ($fh === false) {
            throw new RuntimeException('No fue posible abrir el archivo físico.');
        }
        fpassthru($fh);
        fclose($fh);
    }
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    }
    header('Content-Type: text/plain; charset=UTF-8');
    seguridad_log_error($e, 'archivo_repositorio'); echo 'No se pudo recuperar el archivo solicitado.';
}
