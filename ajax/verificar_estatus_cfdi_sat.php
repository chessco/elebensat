<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';

function sat_json_error(string $mensaje, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['ok'=>false, 'error'=>$mensaje], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sat_valor_xpath(DOMXPath $xp, string $nombre): string {
    $n = $xp->query('//*[local-name()="' . $nombre . '"]');
    if (!$n || $n->length < 1) return '';
    return trim((string)$n->item(0)->textContent);
}

function sat_normalizar_estado(string $estado): string {
    $e = strtoupper(trim($estado));
    if (in_array($e, ['VIGENTE','1'], true)) return 'Vigente';
    if (in_array($e, ['CANCELADO','CANCELADA','0'], true)) return 'Cancelado';
    return trim($estado);
}

function sat_actualizar_cabecera(PDO $pdo, int $idVerificacion, int $idEmpresa, string $estadoSat = '', bool $cambio = false, bool $error = false): void {
    if ($idVerificacion <= 0 || $idEmpresa <= 0) return;

    $vigente = (!$error && strcasecmp($estadoSat, 'Vigente') === 0) ? 1 : 0;
    $cancelado = (!$error && strcasecmp($estadoSat, 'Cancelado') === 0) ? 1 : 0;
    $cambios = (!$error && $cambio) ? 1 : 0;
    $errores = $error ? 1 : 0;

    $sql = "UPDATE verificaciones_sat SET
                total_consultados = total_consultados + 1,
                total_vigentes = total_vigentes + ?,
                total_cancelados = total_cancelados + ?,
                total_cambios = total_cambios + ?,
                total_errores = total_errores + ?,
                ultimo_indice_procesado = total_consultados + 1
            WHERE id_verificacion=? AND id_empresa=? AND estatus_proceso='PROCESANDO'";
    $st = $pdo->prepare($sql);
    $st->execute([$vigente, $cancelado, $cambios, $errores, $idVerificacion, $idEmpresa]);
}

function sat_guardar_detalle(PDO $pdo, int $idVerificacion, int $idEmpresa, array $cfdi, array $datos): void {
    if ($idVerificacion <= 0 || !$cfdi) return;

    $sql = "INSERT INTO verificaciones_sat_detalle
        (id_verificacion,id_empresa,uuid,tipo_cfdi,serie,folio,fecha_emision,
         rfc_emisor,nombre_emisor,rfc_receptor,nombre_receptor,total,
         estatus_anterior,estatus_sat,cambio_estatus,codigo_estatus,es_cancelable,
         estatus_cancelacion,validacion_efos,fecha_consulta,resultado_consulta,mensaje_error,intentos)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?)
        ON DUPLICATE KEY UPDATE
          estatus_anterior=VALUES(estatus_anterior), estatus_sat=VALUES(estatus_sat),
          cambio_estatus=VALUES(cambio_estatus), codigo_estatus=VALUES(codigo_estatus),
          es_cancelable=VALUES(es_cancelable), estatus_cancelacion=VALUES(estatus_cancelacion),
          validacion_efos=VALUES(validacion_efos), fecha_consulta=NOW(),
          resultado_consulta=VALUES(resultado_consulta), mensaje_error=VALUES(mensaje_error),
          intentos=intentos+1";
    $st = $pdo->prepare($sql);
    $st->execute([
        $idVerificacion, $idEmpresa, (string)$cfdi['uuid'],
        $cfdi['tipo_cfdi'] ?? null, $cfdi['serie'] ?? null, $cfdi['folio'] ?? null,
        $cfdi['fecha_emision'] ?? null,
        $cfdi['rfc_emisor'] ?? null, $cfdi['nombre_emisor'] ?? null,
        $cfdi['rfc_receptor'] ?? null, $cfdi['nombre_receptor'] ?? null,
        isset($cfdi['total_xml']) ? (float)$cfdi['total_xml'] : null,
        $datos['estatus_anterior'] ?? null, $datos['estatus_sat'] ?? null,
        !empty($datos['cambio_estatus']) ? 1 : 0,
        $datos['codigo_estatus'] ?? null, $datos['es_cancelable'] ?? null,
        $datos['estatus_cancelacion'] ?? null, $datos['validacion_efos'] ?? null,
        $datos['resultado_consulta'] ?? 'OK', $datos['mensaje_error'] ?? null,
        max(1,(int)($datos['intentos'] ?? 1))
    ]);
}

$idVerificacion = 0;
$idEmpresa = 0;
$idUsuario = 0;
$uuid = '';
$cfdi = [];

try {
    seguridad_exigir_sesion($pdo, true);

    if (empty($_SESSION['id_empresa'])) throw new RuntimeException('No hay empresa activa.');
    $idEmpresa = (int)$_SESSION['id_empresa'];
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idVerificacion = max(0, (int)($_POST['id_verificacion'] ?? 0));
    $uuid = strtoupper(trim((string)($_POST['uuid'] ?? '')));

    if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
        sat_json_error('UUID inválido.');
    }

    exigir_permiso_factura_uuid($pdo, $idUsuario, $idEmpresa, $uuid);

    if ($idVerificacion > 0) {
        $v = $pdo->prepare("SELECT id_verificacion FROM verificaciones_sat WHERE id_verificacion=? AND id_empresa=? AND estatus_proceso='PROCESANDO' LIMIT 1");
        $v->execute([$idVerificacion,$idEmpresa]);
        if (!$v->fetchColumn()) sat_json_error('La corrida de verificación no existe o ya fue cerrada.',409);
    }

    $st = $pdo->prepare("SELECT f.uuid, f.estatus_sat, f.total_xml, f.id_tipo_comprobante AS tipo_cfdi,
                               f.serie, f.folio, f.fecha_emision,
                               e.rfc AS rfc_emisor, e.nombre AS nombre_emisor,
                               r.rfc AS rfc_receptor, r.nombre AS nombre_receptor
                        FROM facturas f
                        INNER JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor
                        INNER JOIN cat_receptores r ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor
                        WHERE f.id_empresa=? AND f.uuid=? LIMIT 1");
    $st->execute([$idEmpresa, $uuid]);
    $cfdi = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$cfdi) sat_json_error('No se encontró el CFDI en la empresa activa.', 404);

    $rfcEmisor = strtoupper(trim((string)$cfdi['rfc_emisor']));
    $rfcReceptor = strtoupper(trim((string)$cfdi['rfc_receptor']));
    $total = (float)$cfdi['total_xml'];
    if ($rfcEmisor === '' || $rfcReceptor === '') throw new RuntimeException('El CFDI no tiene RFC emisor/receptor completo.');

    $totalSat = number_format($total, 6, '.', '');
    $expresion = '?re=' . $rfcEmisor . '&rr=' . $rfcReceptor . '&tt=' . $totalSat . '&id=' . $uuid;
    $expresionXml = htmlspecialchars($expresion, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $soap = '<?xml version="1.0" encoding="utf-8"?>'
          . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
          . '<s:Body><Consulta xmlns="http://tempuri.org/">'
          . '<expresionImpresa>' . $expresionXml . '</expresionImpresa>'
          . '</Consulta></s:Body></s:Envelope>';

    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL no está habilitado en el servidor.');

    $ch = curl_init('https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soap,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://tempuri.org/IConsultaCFDIService/Consulta"',
            'Accept: text/xml'
        ],
        CURLOPT_USERAGENT => 'SGKSAT-VisorXMLPro/1.0'
    ]);

    $respuesta = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($respuesta === false || $respuesta === '') throw new RuntimeException('No hubo respuesta del SAT' . ($curlError ? ': ' . $curlError : '.'));
    if ($httpCode < 200 || $httpCode >= 300) throw new RuntimeException('El SAT respondió HTTP ' . $httpCode . '.');

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $okXml = $dom->loadXML($respuesta, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$okXml) throw new RuntimeException('El SAT devolvió una respuesta XML no válida.');

    $xp = new DOMXPath($dom);
    $codigo = sat_valor_xpath($xp, 'CodigoEstatus');
    $estadoRaw = sat_valor_xpath($xp, 'Estado');
    $esCancelable = sat_valor_xpath($xp, 'EsCancelable');
    $estatusCancelacion = sat_valor_xpath($xp, 'EstatusCancelacion');
    $validacionEfos = sat_valor_xpath($xp, 'ValidacionEFOS');

    $estadoSat = sat_normalizar_estado($estadoRaw);
    if ($estadoSat === '') throw new RuntimeException($codigo !== '' ? $codigo : 'El SAT no devolvió el estado del CFDI.');

    $anterior = sat_normalizar_estado((string)($cfdi['estatus_sat'] ?: 'Vigente'));
    $cambio = false;
    $actualizado = false;
    if (in_array($estadoSat, ['Vigente','Cancelado'], true)) {
        $cambio = strcasecmp($anterior, $estadoSat) !== 0;
        if ($cambio) {
            // Regla acordada: aquí SOLO cambia el estatus. La fecha real de cancelación
            // la completará posteriormente el proceso de metadata.
            $up = $pdo->prepare('UPDATE facturas SET estatus_sat=? WHERE id_empresa=? AND uuid=? LIMIT 1');
            $up->execute([$estadoSat, $idEmpresa, $uuid]);
            $actualizado = $up->rowCount() > 0;
        }
    }

    $resultadoConsulta = in_array($estadoSat, ['Vigente','Cancelado'], true) ? 'OK' : 'NO_ENCONTRADO';

    // La cabecera se actualiza EN MYSQL conforme avanza la corrida.
    // Así, si el navegador se cierra, el avance ya procesado no se pierde.
    sat_actualizar_cabecera($pdo, $idVerificacion, $idEmpresa, $estadoSat, $cambio, false);

    // Regla acordada: el DETALLE de la bitácora guarda únicamente CFDI
    // cuyo estatus realmente cambió (ej. Vigente -> Cancelado).
    if ($cambio) {
        sat_guardar_detalle($pdo,$idVerificacion,$idEmpresa,$cfdi,[
            'estatus_anterior'=>$anterior,
            'estatus_sat'=>$estadoSat,
            'cambio_estatus'=>true,
            'codigo_estatus'=>$codigo,
            'es_cancelable'=>$esCancelable,
            'estatus_cancelacion'=>$estatusCancelacion,
            'validacion_efos'=>$validacionEfos,
            'resultado_consulta'=>$resultadoConsulta,
            'mensaje_error'=>$resultadoConsulta==='OK' ? null : $codigo,
            'intentos'=>1
        ]);
    }

    echo json_encode([
        'ok'=>true,
        'uuid'=>$uuid,
        'estatus_anterior'=>$anterior,
        'estado_sat'=>$estadoSat,
        'cambio'=>$cambio,
        'actualizado'=>$actualizado,
        'codigo_estatus'=>$codigo,
        'es_cancelable'=>$esCancelable,
        'estatus_cancelacion'=>$estatusCancelacion,
        'validacion_efos'=>$validacionEfos
    ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    // Los errores se contabilizan en la CABECERA, pero no se insertan en el
    // detalle porque el detalle queda reservado exclusivamente para CAMBIOS.
    if ($idVerificacion > 0 && $idEmpresa > 0) {
        try { sat_actualizar_cabecera($pdo, $idVerificacion, $idEmpresa, '', false, true); } catch (Throwable $ignore) {}
    }
    seguridad_log_error($e, 'verificar_estatus_cfdi_sat');
    sat_json_error($e->getMessage() ?: 'Error al verificar el CFDI con el SAT.', 500);
}
