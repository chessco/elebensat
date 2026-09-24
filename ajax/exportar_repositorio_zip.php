<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/xml_respaldo.php';
require_once '../includes/pdf_cfdi_desde_xml.php';

@set_time_limit(0);
@ini_set('memory_limit', '256M');

function responder_error_zip(string $mensaje, int $codigo = 400): void {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $mensaje;
    exit;
}

function nombre_seguro_zip(string $texto): string {
    $texto = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $texto) ?? '';
    $texto = trim($texto, '._-');
    return $texto !== '' ? substr($texto, 0, 180) : 'SIN_NOMBRE';
}

function tipo_factura_para_nombre(array $row): string {
    $tipoSat = strtoupper(trim((string)($row['id_tipo_comprobante'] ?? '')));
    if (in_array($tipoSat, ['P', 'N', 'T'], true)) {
        return $tipoSat;
    }
    $mov = strtoupper(trim((string)($row['tipo_movimiento_empresa'] ?? '')));
    return in_array($mov, ['I', 'E'], true) ? $mov : ($tipoSat !== '' ? $tipoSat : 'CFDI');
}

try {
    if (!isset($_SESSION['id_empresa'])) {
        responder_error_zip('Acceso denegado.', 401);
    }
    if (!class_exists('ZipArchive')) {
        responder_error_zip('La extensión PHP ZipArchive no está habilitada en el servidor.', 500);
    }

    $idEmpresa = (int)$_SESSION['id_empresa'];
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'exportar');

    $exportarXml = filter_var($_POST['exportar_xml'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $exportarPdf = filter_var($_POST['exportar_pdf'] ?? '0', FILTER_VALIDATE_BOOLEAN);

    if (!$exportarXml && !$exportarPdf) {
        responder_error_zip('Debe seleccionar XML, PDF o ambos.');
    }
    if ($exportarXml) {
        exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'descargar_xml');
    }
    if ($exportarPdf) {
        exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'descargar_pdf');
    }

    $exportToken = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['export_token'] ?? ''));
    if (strlen($exportToken) > 80) $exportToken = substr($exportToken, 0, 80);

    $uuidsJson = (string)($_POST['uuids'] ?? '[]');
    $uuids = json_decode($uuidsJson, true);
    if (!is_array($uuids)) {
        responder_error_zip('Lista de UUID no válida.');
    }

    $limpios = [];
    foreach ($uuids as $uuid) {
        $uuid = strtoupper(trim((string)$uuid));
        if ($uuid !== '' && preg_match('/^[A-F0-9-]{20,64}$/', $uuid)) {
            $limpios[$uuid] = true;
        }
    }
    $uuids = array_keys($limpios);

    if (!$uuids) {
        responder_error_zip('No hay CFDI válidos para exportar.');
    }
    if (count($uuids) > 25000) {
        responder_error_zip('La exportación excede el máximo de 25,000 CFDI por operación.');
    }

    $permitidos = obtener_permisos_documentos($pdo, $idUsuario, $idEmpresa);
    if (!$permitidos) {
        responder_error_zip('No tiene tipos de documento autorizados para esta empresa.', 403);
    }

    $filas = [];
    foreach (array_chunk($uuids, 500) as $bloque) {
        $marcas = implode(',', array_fill(0, count($bloque), '?'));
        $sql = "SELECT
                    f.uuid,
                    f.fecha_emision,
                    f.folio,
                    f.id_tipo_comprobante,
                    f.tipo_movimiento_empresa,
                    d.ruta_xml,
                    d.nombre_archivo
                FROM facturas f
                LEFT JOIN facturas_datos d ON d.uuid = f.uuid
                WHERE f.id_empresa = ?
                  AND f.uuid IN ($marcas)";

        // La seguridad por tipo se aplica una sola vez por consulta/bloque,
        // evitando validar documento por documento.
        $paramsNombrados = [];
        $filtroTipos = sql_filtro_tipos_permitidos($permitidos, $paramsNombrados, 'f');

        // sql_filtro_tipos_permitidos usa parámetros nombrados para P/N/T.
        // Para mantener el IN posicional simple, sustituimos esos valores por literales
        // estrictamente controlados provenientes de la lista interna de tipos permitidos.
        foreach (['p' => 'P', 'n' => 'N', 't' => 'T'] as $k => $v) {
            $filtroTipos = str_replace(':perm_' . $k, $pdo->quote($v), $filtroTipos);
        }
        $sql .= ' AND ' . $filtroTipos;

        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$idEmpresa], $bloque));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $filas[strtoupper((string)$row['uuid'])] = $row;
        }
    }

    if (!$filas) {
        responder_error_zip('No se encontraron CFDI autorizados para exportar.', 404);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'sgksat_zip_');
    if ($tmp === false) {
        responder_error_zip('No fue posible crear el archivo temporal de exportación.', 500);
    }
    @unlink($tmp);
    $rutaZip = $tmp . '.zip';

    $zip = new ZipArchive();
    $abierto = $zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($abierto !== true) {
        @unlink($rutaZip);
        responder_error_zip('No fue posible crear el ZIP de exportación.', 500);
    }

    $xmlAgregados = 0;
    $pdfAgregados = 0;
    $pdfOmitidos = 0;
    $xmlFaltantes = 0;
    $errores = [];

    // Para PDF no se usa ruta_pdf/pdf_generado: se toma el XML respaldado en BD
    // y se crea una representación nueva con el mismo generador Adobe-safe del visor.
    $stXmlPdf = $pdo->prepare(
        "SELECT d.xml_base64
         FROM facturas_datos d
         INNER JOIN facturas f ON f.uuid = d.uuid
         WHERE d.uuid = ? AND f.id_empresa = ?
         LIMIT 1"
    );

    foreach ($uuids as $uuid) {
        if (!isset($filas[$uuid])) {
            continue;
        }
        $row = $filas[$uuid];
        $fecha = !empty($row['fecha_emision']) ? date('Ymd', strtotime((string)$row['fecha_emision'])) : 'SINFECHA';
        $tipo = tipo_factura_para_nombre($row);
        $folio = trim((string)($row['folio'] ?? ''));
        if ($folio === '') $folio = 'SF';
        $base = nombre_seguro_zip($fecha . '_' . $tipo . '_' . $folio . '_' . $uuid);

        if ($exportarXml) {
            $rutaXml = trim((string)($row['ruta_xml'] ?? ''));
            if ($rutaXml !== '' && is_file($rutaXml) && is_readable($rutaXml)) {
                $entrada = 'XML/' . $base . '.xml';
                if ($zip->addFile($rutaXml, $entrada)) {
                    // Modo STORE: evita comprimir archivo por archivo; prioriza velocidad.
                    if (method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName($entrada, ZipArchive::CM_STORE);
                    }
                    $xmlAgregados++;
                } else {
                    $xmlFaltantes++;
                    $errores[] = $uuid . ' [XML]: no fue posible agregar el archivo al ZIP.';
                }
            } else {
                $xmlFaltantes++;
                $errores[] = $uuid . ' [XML]: archivo físico no encontrado o no legible.';
            }
        }

        if ($exportarPdf) {
            try {
                $stXmlPdf->execute([$uuid, $idEmpresa]);
                $rowXml = $stXmlPdf->fetch(PDO::FETCH_ASSOC);
                if (!$rowXml || trim((string)($rowXml['xml_base64'] ?? '')) === '') {
                    throw new RuntimeException('No existe XML respaldado en la base.');
                }

                $xmlRaw = xml_respaldo_decodificar((string)$rowXml['xml_base64']);
                $reparado = false;
                $xmlRaw = xml_respaldo_reparar_cierre_final($xmlRaw, $reparado);
                $erroresXmlPdf = [];
                if (!xml_respaldo_es_valido($xmlRaw, $erroresXmlPdf)) {
                    throw new RuntimeException('XML inválido: ' . implode(' | ', array_slice($erroresXmlPdf, 0, 2)));
                }

                $pdfNuevo = pdf_cfdi_desde_xml($xmlRaw);
                if ($pdfNuevo === '' || strpos($pdfNuevo, '%PDF-') !== 0) {
                    throw new RuntimeException('El generador no produjo un PDF válido.');
                }

                $entrada = 'PDF/' . $base . '.pdf';
                if ($zip->addFromString($entrada, $pdfNuevo)) {
                    // El PDF ya viene construido; STORE evita recomprimir y acelera el ZIP.
                    if (method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName($entrada, ZipArchive::CM_STORE);
                    }
                    $pdfAgregados++;
                } else {
                    throw new RuntimeException('No fue posible agregar el PDF generado al ZIP.');
                }
                unset($pdfNuevo, $xmlRaw, $rowXml);
            } catch (Throwable $pdfEx) {
                $pdfOmitidos++;
                $errores[] = $uuid . ' [PDF]: ' . $pdfEx->getMessage();
            }
        }
    }

    $resumen = "EXPORTACION SGKSAT\r\n";
    $resumen .= 'CFDI solicitados: ' . count($uuids) . "\r\n";
    if ($exportarXml) {
        $resumen .= "XML incluidos: {$xmlAgregados}\r\n";
        $resumen .= "XML faltantes/error: {$xmlFaltantes}\r\n";
    }
    if ($exportarPdf) {
        $resumen .= "PDF incluidos: {$pdfAgregados}\r\n";
        $resumen .= "PDF omitidos/error al generar: {$pdfOmitidos}\r\n";
    }
    $resumen .= 'Generado: ' . date('Y-m-d H:i:s') . "\r\n";
    $zip->addFromString('resumen_exportacion.txt', $resumen);

    if ($errores) {
        $zip->addFromString('errores_exportacion.txt', implode("\r\n", $errores));
    }

    if (!$zip->close()) {
        @unlink($rutaZip);
        responder_error_zip('No fue posible finalizar el ZIP de exportación.', 500);
    }

    if (!is_file($rutaZip) || filesize($rutaZip) === 0) {
        @unlink($rutaZip);
        responder_error_zip('El ZIP generado quedó vacío.', 500);
    }

    // Señal al visor: el ZIP ya está terminado y la descarga va a iniciar.
    if ($exportToken !== '') {
        setcookie('sgksat_export_' . $exportToken, '1', [
            'expires' => time() + 300,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
    }

    $nombreZip = 'CFDI_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombreZip . '"');
    header('Content-Length: ' . filesize($rutaZip));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-SGKSAT-XML: ' . $xmlAgregados);
    header('X-SGKSAT-PDF: ' . $pdfAgregados);
    header('X-SGKSAT-PDF-Omitidos: ' . $pdfOmitidos);

    $fh = fopen($rutaZip, 'rb');
    if ($fh === false) {
        @unlink($rutaZip);
        responder_error_zip('No fue posible abrir el ZIP para descargar.', 500);
    }
    while (!feof($fh)) {
        echo fread($fh, 1024 * 1024);
        if (function_exists('ob_flush')) @ob_flush();
        flush();
    }
    fclose($fh);
    @unlink($rutaZip);
    exit;

} catch (Throwable $e) {
    seguridad_log_error($e, 'exportar_repositorio_zip'); responder_error_zip('No se pudo generar el archivo ZIP solicitado.', 500);
}
