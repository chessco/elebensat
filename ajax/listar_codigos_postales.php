<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales');

try {
$draw   = (int)($_GET['draw'] ?? 1);
$start  = max(0, (int)($_GET['start'] ?? 0));
$length = min(200, max(10, (int)($_GET['length'] ?? 25)));
$search = trim((string)($_GET['search']['value'] ?? ''));
$estimulo = isset($_GET['estimulo']) ? trim((string)$_GET['estimulo']) : '';

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(codigo_postal LIKE :q_cp OR estado LIKE :q_estado OR municipio LIKE :q_municipio OR localidad LIKE :q_localidad)';
    $like = '%' . $search . '%';
    $params[':q_cp'] = $like;
    $params[':q_estado'] = $like;
    $params[':q_municipio'] = $like;
    $params[':q_localidad'] = $like;
}
if (in_array($estimulo, ['0', '1', '2'], true)) {
    $conditions[] = 'estimulo_franja_fronteriza = :estimulo';
    $params[':estimulo'] = (int)$estimulo;
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$resumen = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(estimulo_franja_fronteriza = 1) AS norte,
    SUM(estimulo_franja_fronteriza = 2) AS sur,
    SUM(estimulo_franja_fronteriza = 0) AS no_aplica
    FROM sat_codigos_postales")->fetch(PDO::FETCH_ASSOC) ?: [];

$catalogo = $pdo->query("SELECT revision_catalogo, fecha_publicacion, fecha_sincronizacion
    FROM sat_codigos_postales
    ORDER BY fecha_sincronizacion DESC
    LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$total = (int)($resumen['total'] ?? 0);
$st = $pdo->prepare('SELECT COUNT(*) FROM sat_codigos_postales' . $where);
$st->execute($params);
$filtered = (int)$st->fetchColumn();

$orderMap = [
    0 => 'codigo_postal', 1 => 'estado', 2 => 'municipio', 3 => 'localidad',
    4 => 'estimulo_franja_fronteriza', 5 => 'fecha_inicio_vigencia',
    6 => 'fecha_fin_vigencia', 7 => 'fecha_sincronizacion'
];
$oi = (int)($_GET['order'][0]['column'] ?? 0);
$dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$order = $orderMap[$oi] ?? 'codigo_postal';

$sql = 'SELECT codigo_postal, estado, municipio, localidad, estimulo_franja_fronteriza,
               fecha_inicio_vigencia, fecha_fin_vigencia, fecha_sincronizacion
        FROM sat_codigos_postales' . $where . " ORDER BY $order $dir LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v, $k === ':estimulo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$st->bindValue(':lim', $length, PDO::PARAM_INT);
$st->bindValue(':off', $start, PDO::PARAM_INT);
$st->execute();

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $filtered,
    'data' => $st->fetchAll(PDO::FETCH_ASSOC),
    'resumen' => [
        'total' => (int)($resumen['total'] ?? 0),
        'norte' => (int)($resumen['norte'] ?? 0),
        'sur' => (int)($resumen['sur'] ?? 0),
        'no_aplica' => (int)($resumen['no_aplica'] ?? 0),
    ],
    'catalogo' => $catalogo,
], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'listar_codigos_postales');
    http_response_code(500);
    echo json_encode([
        'draw' => isset($draw) ? (int)$draw : 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'No se pudieron consultar los códigos postales.',
        // Detalle técnico solo al log.
        'detalle' => null,
    ], JSON_UNESCAPED_UNICODE);
}
