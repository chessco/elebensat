<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

try {
    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa.');
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');

    $tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
    if (!in_array($tipo, ['', 'emitidos', 'recibidos'], true)) $tipo = '';
    $xml = strtolower(trim((string)($_GET['xml'] ?? '')));
    if (!in_array($xml, ['', 'si', 'no'], true)) $xml = '';
    $desde = trim((string)($_GET['desde'] ?? ''));
    $hasta = trim((string)($_GET['hasta'] ?? ''));
    $buscar = trim((string)($_GET['buscar'] ?? ''));

    $where = ["m.id_empresa = :empresa", "UPPER(COALESCE(m.estatus_sat,'')) IN ('CANCELADO','CANCELADA','0')"];
    $params = [':empresa' => $idEmpresa];
    if ($tipo !== '') { $where[] = 'LOWER(m.tipo) = :tipo'; $params[':tipo'] = $tipo; }
    if ($desde !== '') { $where[] = 'DATE(COALESCE(m.fecha_cancelacion,m.fecha_emision)) >= :desde'; $params[':desde'] = $desde; }
    if ($hasta !== '') { $where[] = 'DATE(COALESCE(m.fecha_cancelacion,m.fecha_emision)) <= :hasta'; $params[':hasta'] = $hasta; }
    if ($xml === 'si') $where[] = "d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'') <> ''";
    elseif ($xml === 'no') $where[] = "(d.uuid IS NULL OR COALESCE(d.xml_base64,'') = '')";

    if ($buscar !== '') {
        $where[] = "(m.uuid LIKE :q_uuid OR m.rfc_emisor LIKE :q_rfc_emisor OR m.nombre_emisor LIKE :q_nombre_emisor OR m.rfc_receptor LIKE :q_rfc_receptor OR m.nombre_receptor LIKE :q_nombre_receptor OR CAST(m.monto AS CHAR) LIKE :q_monto)";
        $q = '%' . $buscar . '%';
        $params[':q_uuid'] = $q;
        $params[':q_rfc_emisor'] = $q;
        $params[':q_nombre_emisor'] = $q;
        $params[':q_rfc_receptor'] = $q;
        $params[':q_nombre_receptor'] = $q;
        $params[':q_monto'] = $q;
    }

    $sql = "SELECT m.uuid,m.tipo,m.fecha_emision,m.fecha_cancelacion,m.rfc_emisor,m.nombre_emisor,
                   m.rfc_receptor,m.nombre_receptor,m.monto,m.efecto_comprobante,m.fecha_ultima_revision,
                   CASE WHEN f.uuid IS NULL THEN 0 ELSE 1 END AS factura_en_visor,
                   CASE WHEN d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'')<>'' THEN 1 ELSE 0 END AS xml_disponible
            FROM sat_metadata_cfdi m
            LEFT JOIN facturas f ON f.id_empresa=m.id_empresa AND f.uuid=m.uuid
            LEFT JOIN facturas_datos d ON d.uuid=f.uuid
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(m.fecha_cancelacion,m.fecha_emision) DESC, m.uuid";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $empresaNombre = trim((string)($_SESSION['empresa_nombre'] ?? 'empresa'));
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $empresaNombre);
    $filename = 'cancelados_sat_' . trim($safe, '_') . '_' . date('Ymd_His') . '.csv';

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['UUID','TIPO','FECHA EMISION','FECHA CANCELACION','RFC EMISOR','EMISOR','RFC RECEPTOR','RECEPTOR','MONTO','EFECTO','XML EN VISOR','REVISADO'], ',', '"', '');

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $estadoXml = ((int)($r['xml_disponible'] ?? 0) === 1) ? 'Con XML' : (((int)($r['factura_en_visor'] ?? 0) === 1) ? 'Registro sin XML' : 'Sin XML');
        fputcsv($out, [
            (string)($r['uuid'] ?? ''), ucfirst((string)($r['tipo'] ?? '')),
            !empty($r['fecha_emision']) ? date('d/m/Y H:i', strtotime($r['fecha_emision'])) : '',
            !empty($r['fecha_cancelacion']) ? date('d/m/Y H:i', strtotime($r['fecha_cancelacion'])) : '',
            (string)($r['rfc_emisor'] ?? ''), (string)($r['nombre_emisor'] ?? ''),
            (string)($r['rfc_receptor'] ?? ''), (string)($r['nombre_receptor'] ?? ''),
            is_numeric($r['monto'] ?? null) ? number_format((float)$r['monto'], 2, '.', '') : '',
            (string)($r['efecto_comprobante'] ?? ''), $estadoXml,
            !empty($r['fecha_ultima_revision']) ? date('d/m/Y H:i', strtotime($r['fecha_ultima_revision'])) : ''
        ], ',', '"', '');
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    seguridad_log_error($e, 'exportar_cancelados_sat');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No fue posible exportar los CFDI cancelados.';
}
