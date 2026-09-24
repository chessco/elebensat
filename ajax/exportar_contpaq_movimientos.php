<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/csrf.php';


function cp_json_ci(?string $json, string $key, $default = '') {
    if (!$json) return $default;
    $a = json_decode($json, true);
    if (!is_array($a)) return $default;
    foreach ($a as $k => $v) {
        if (strcasecmp((string)$k, $key) === 0) return $v;
    }
    return $default;
}

function cp_txt($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'SI' : 'NO';
    if (is_scalar($v)) return trim((string)$v);
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

try {
    $u = seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)$u['id_usuario'];
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'contpaq_cheques');
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Token de seguridad inválido.');
    }

    $tipo = strtoupper(trim((string)($_POST['tipo'] ?? 'C'))) === 'T' ? 'T' : 'C';
    $desde = trim((string)($_POST['fecha_desde'] ?? ''));
    $hasta = trim((string)($_POST['fecha_hasta'] ?? ''));
    $search = trim((string)($_POST['buscar'] ?? ''));

    $isCheque = $tipo === 'C';
    $t = $isCheque ? 'contpaq_cheques' : 'contpaq_egresos';
    $a = $isCheque ? 'c' : 'e';
    $idCol = $isCheque ? 'id_contpaq_cheque' : 'id_contpaq_egreso';
    $join = $isCheque
        ? "LEFT JOIN contpaq_dispersiones_pagos d ON d.id_empresa=c.id_empresa AND d.tipo_origen='C' AND d.id_contpaq_cheque=c.id_contpaq_cheque"
        : "LEFT JOIN contpaq_dispersiones_pagos d ON d.id_empresa=e.id_empresa AND d.tipo_origen='T' AND d.id_contpaq_egreso=e.id_contpaq_egreso";

    $where = ["$a.id_empresa=?"];
    $params = [$idEmpresa];
    if ($desde !== '') { $where[] = "$a.fecha>=?"; $params[] = $desde . ' 00:00:00'; }
    if ($hasta !== '') { $where[] = "$a.fecha<=?"; $params[] = $hasta . ' 23:59:59'; }
    if ($search !== '') {
        $where[] = "(CAST($a.$idCol AS CHAR) LIKE ? OR $a.folio LIKE ? OR CAST($a.codigo_persona AS CHAR) LIKE ? OR $a.beneficiario_pagador LIKE ? OR CAST($a.total AS CHAR) LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT $a.$idCol id_origen,$a.tipo_documento,$a.folio,$a.fecha,$a.codigo_persona,$a.persona_rfc,$a.beneficiario_pagador,
                   $a.total,$a.referencia,$a.concepto,$a.es_cancelado,$a.es_anticipo,$a.num_pol,$a.id_poliza,$a.guid,$a.datos_origen_json,$a.fecha_ultima_sync,
                   COUNT(d.id) dispersiones,
                   SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,'')) IN ('CONCILIADO','YA_CONCILIADO') THEN 1 ELSE 0 END) dispersiones_conciliadas,
                   SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,''))='PARCIAL' THEN 1 ELSE 0 END) dispersiones_parciales,
                   SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,''))='ERROR' THEN 1 ELSE 0 END) dispersiones_error
              FROM $t $a $join
             WHERE $whereSql
             GROUP BY $a.id,$a.$idCol,$a.tipo_documento,$a.folio,$a.fecha,$a.codigo_persona,$a.persona_rfc,$a.beneficiario_pagador,$a.total,$a.referencia,$a.concepto,$a.es_cancelado,$a.es_anticipo,$a.num_pol,$a.id_poliza,$a.guid,$a.datos_origen_json,$a.fecha_ultima_sync
             ORDER BY $a.fecha ASC,$a.$idCol ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $nombre = ($isCheque ? 'CHEQUES' : 'EGRESOS') . '_CONTPAQ_' . ($desde ?: 'TODOS') . '_' . ($hasta ?: 'TODOS') . '.csv';
    $nombre = preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // BOM para que Excel abra acentos correctamente.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'ID CONTPAQ','Tipo documento','Folio','Fecha','Código','RFC','Beneficiario','Total',
        'Referencia','Concepto','Cancelado','Es anticipo','Núm. póliza','ID póliza','GUID',
        'Tiene CFD','Núm. asociaciones','Dispersiones','Conciliadas','Parciales','Errores',
        'Estatus conciliación','Última sync'
    ], ',');

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $cancel = (int)$r['es_cancelado'] === 1;
        $totalDisp = (int)$r['dispersiones'];
        $conc = (int)$r['dispersiones_conciliadas'];
        $par = (int)$r['dispersiones_parciales'];
        $err = (int)$r['dispersiones_error'];

        if ($cancel) $estado = 'CANCELADO';
        elseif ($totalDisp <= 0) $estado = 'SIN DISPERSIÓN';
        elseif ($err > 0) $estado = 'ERROR';
        elseif ($par > 0 || ($conc > 0 && $conc < $totalDisp)) $estado = 'PARCIAL';
        elseif ($conc === $totalDisp) $estado = 'CONCILIADO';
        else $estado = 'NO CONCILIADO';

        $tieneCfd = cp_json_ci($r['datos_origen_json'] ?? null, 'TieneCFD', '');
        $numAsoc = cp_json_ci($r['datos_origen_json'] ?? null, 'NumAsoc', '');

        fputcsv($out, [
            (int)$r['id_origen'],
            (string)$r['tipo_documento'],
            (string)$r['folio'],
            (string)$r['fecha'],
            (string)$r['codigo_persona'],
            (string)$r['persona_rfc'],
            (string)$r['beneficiario_pagador'],
            number_format((float)$r['total'], 2, '.', ''),
            (string)$r['referencia'],
            (string)$r['concepto'],
            $cancel ? 'SI' : 'NO',
            (int)$r['es_anticipo'] === 1 ? 'SI' : 'NO',
            (string)$r['num_pol'],
            (string)$r['id_poliza'],
            (string)$r['guid'],
            cp_txt($tieneCfd),
            cp_txt($numAsoc),
            $totalDisp,
            $conc,
            $par,
            $err,
            $estado,
            (string)$r['fecha_ultima_sync'],
        ], ',');
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No fue posible exportar: ' . $e->getMessage();
}
