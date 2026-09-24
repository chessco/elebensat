<?php
session_start();

// Este endpoint se ejecuta por lotes cortos para evitar 504 de nginx y picos de memoria.
// Siempre intenta responder JSON, incluso si PHP termina con un error fatal.
ob_start();
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Error fatal al reprocesar nómina: ' . $e['message']
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/nomina_cfdi.php';

function reproceso_nomina_responder(array $data, int $http = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // Evita que PHP corte un lote por su propio max_execution_time.
    // nginx ya no debe alcanzar su timeout porque cada llamada procesa pocos CFDI.
    @set_time_limit(0);

    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);

    if ($idEmpresa <= 0) {
        throw new RuntimeException('No hay empresa activa.');
    }
    if (!usuario_puede_ver_tipo($pdo, $idUsuario, $idEmpresa, 'N')) {
        throw new RuntimeException('No tienes permiso para consultar CFDI de nómina en la empresa activa.');
    }

    $accion = strtolower(trim((string)($_POST['accion'] ?? 'paso')));

    // Se conserva por compatibilidad, pero el visor nuevo YA NO lo usa.
    // Cuenta solamente facturas tipo N; no une ni lee xml_base64.
    if ($accion === 'contar') {
        $st = $pdo->prepare(
            "SELECT COUNT(*)
               FROM facturas
              WHERE id_empresa=?
                AND id_tipo_comprobante='N'"
        );
        $st->execute([$idEmpresa]);
        reproceso_nomina_responder(['ok' => true, 'total' => (int)$st->fetchColumn()]);
    }

    $ultimoUuid = strtoupper(trim((string)($_POST['ultimo_uuid'] ?? '')));

    // Lotes deliberadamente pequeños. El límite máximo evita que desde el navegador
    // se mande accidentalmente una petición pesada que vuelva a provocar 504.
    $limite = max(5, min(25, (int)($_POST['limite'] ?? 10)));

    // 1) Primero obtenemos SOLO UUIDs. No cargamos blobs XML en esta consulta.
    // La paginación por UUID evita OFFSET y permite avanzar de forma estable.
    $sql = "SELECT uuid
              FROM facturas
             WHERE id_empresa=?
               AND id_tipo_comprobante='N'";
    $params = [$idEmpresa];
    if ($ultimoUuid !== '') {
        $sql .= ' AND uuid>?';
        $params[] = $ultimoUuid;
    }
    $sql .= ' ORDER BY uuid ASC LIMIT ' . $limite;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $uuids = $st->fetchAll(PDO::FETCH_COLUMN);

    if (!$uuids) {
        reproceso_nomina_responder([
            'ok' => true,
            'revisados' => 0,
            'procesados' => 0,
            'errores' => 0,
            'sin_xml' => 0,
            'ultimo_uuid' => $ultimoUuid,
            'terminado' => true,
            'mensajes' => []
        ]);
    }

    // 2) Leemos únicamente los XML de este lote. En ningún momento se carga el histórico completo.
    $ph = implode(',', array_fill(0, count($uuids), '?'));
    $stXml = $pdo->prepare("SELECT uuid, xml_base64 FROM facturas_datos WHERE uuid IN ($ph)");
    $stXml->execute($uuids);

    $xmlPorUuid = [];
    while ($r = $stXml->fetch(PDO::FETCH_ASSOC)) {
        $xmlPorUuid[strtoupper((string)$r['uuid'])] = (string)($r['xml_base64'] ?? '');
    }
    $stXml->closeCursor();

    $revisados = 0;
    $procesados = 0;
    $errores = 0;
    $sinXml = 0;
    $ultimo = $ultimoUuid;
    $mensajes = [];

    foreach ($uuids as $uuidOriginal) {
        $uuid = strtoupper(trim((string)$uuidOriginal));
        if ($uuid === '') {
            continue;
        }

        $ultimo = $uuid;
        $revisados++;

        try {
            $base64 = $xmlPorUuid[$uuid] ?? '';
            if ($base64 === '') {
                $sinXml++;
                if (count($mensajes) < 10) {
                    $mensajes[] = $uuid . ': no tiene XML guardado en facturas_datos.';
                }
                continue;
            }

            $raw = base64_decode($base64, true);
            unset($base64);

            if ($raw === false || $raw === '') {
                throw new RuntimeException('XML Base64 inválido o vacío.');
            }

            $datos = nomina_cfdi_desde_xml_raw($raw);
            unset($raw);

            if ($datos === null) {
                throw new RuntimeException('No se encontró complemento Nómina 1.2.');
            }

            nomina_cfdi_guardar($pdo, $idEmpresa, $uuid, $datos);
            unset($datos);
            $procesados++;
        } catch (Throwable $e) {
            $errores++;
            if (count($mensajes) < 10) {
                $mensajes[] = $uuid . ': ' . $e->getMessage();
            }
        }
    }

    // Libera el lote antes de responder al navegador.
    unset($xmlPorUuid, $uuids);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    reproceso_nomina_responder([
        'ok' => true,
        'revisados' => $revisados,
        'procesados' => $procesados,
        'errores' => $errores,
        'sin_xml' => $sinXml,
        'ultimo_uuid' => $ultimo,
        'terminado' => $revisados < $limite,
        'mensajes' => $mensajes
    ]);

} catch (Throwable $e) {
    try {
        seguridad_log_error($e, 'reprocesar_nomina_cfdi');
    } catch (Throwable $ignorar) {
        // No permitir que un error del log rompa la respuesta JSON.
    }

    reproceso_nomina_responder([
        'ok' => false,
        'error' => $e->getMessage()
    ], 400);
}
