<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

try {
    // Igual que listar_sat_69.php, que ya funciona:
    // revalida sesión y superadmin desde la base, sin depender de un valor viejo en $_SESSION.
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69b');

    $draw   = (int)($_GET['draw'] ?? 1);
    $start  = max(0, (int)($_GET['start'] ?? 0));
    $length = min(200, max(10, (int)($_GET['length'] ?? 25)));
    $search = trim((string)($_GET['search']['value'] ?? ''));
    $situacion = trim((string)($_GET['situacion'] ?? ''));

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = '(rfc LIKE :q_rfc OR nombre_contribuyente LIKE :q_nombre OR situacion LIKE :q_situacion)';
        $like = '%' . $search . '%';
        $params[':q_rfc'] = $like;
        $params[':q_nombre'] = $like;
        $params[':q_situacion'] = $like;
    }

    $situacionesPermitidas = ['Definitivo', 'Presunto', 'Desvirtuado', 'Sentencia Favorable'];
    if (in_array($situacion, $situacionesPermitidas, true)) {
        $conditions[] = 'situacion = :situacion';
        $params[':situacion'] = $situacion;
    }

    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

    $control = $pdo->query(
        "SELECT fuente_url, leyenda_actualizacion, fecha_sincronizacion, total_registros
           FROM sat_69b_control
          WHERE id_control = 1
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($control['total_registros'] ?? 0);
    if ($total <= 0) {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM sat_69b_contribuyentes')->fetchColumn();
    }

    if ($where === '') {
        $filtered = $total;
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sat_69b_contribuyentes' . $where);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $filtered = (int)$st->fetchColumn();
    }

    // Resumen separado: si por cualquier motivo falla, no debe tirar la tabla.
    $resumen = ['total'=>$total,'definitivos'=>0,'presuntos'=>0,'desvirtuados'=>0,'sentencias'=>0];
    try {
        $r = $pdo->query(
            "SELECT
                SUM(CASE WHEN situacion='Definitivo' THEN 1 ELSE 0 END) definitivos,
                SUM(CASE WHEN situacion='Presunto' THEN 1 ELSE 0 END) presuntos,
                SUM(CASE WHEN situacion='Desvirtuado' THEN 1 ELSE 0 END) desvirtuados,
                SUM(CASE WHEN situacion='Sentencia Favorable' THEN 1 ELSE 0 END) sentencias
             FROM sat_69b_contribuyentes"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $resumen['definitivos'] = (int)($r['definitivos'] ?? 0);
        $resumen['presuntos'] = (int)($r['presuntos'] ?? 0);
        $resumen['desvirtuados'] = (int)($r['desvirtuados'] ?? 0);
        $resumen['sentencias'] = (int)($r['sentencias'] ?? 0);
    } catch (Throwable $ignorar) {}

    $orderMap = [
        0 => 'numero_sat', 1 => 'rfc', 2 => 'nombre_contribuyente', 3 => 'situacion',
        4 => 'oficio_presuncion_sat', 5 => 'fecha_sat_presuntos', 6 => 'oficio_presuncion_dof', 7 => 'fecha_dof_presuntos',
        8 => 'oficio_desvirtuado_sat', 9 => 'fecha_sat_desvirtuados', 10 => 'oficio_desvirtuado_dof', 11 => 'fecha_dof_desvirtuados',
        12 => 'oficio_definitivo_sat', 13 => 'fecha_sat_definitivos', 14 => 'oficio_definitivo_dof', 15 => 'fecha_dof_definitivos',
        16 => 'oficio_sentencia_sat', 17 => 'fecha_sat_sentencia', 18 => 'oficio_sentencia_dof', 19 => 'fecha_dof_sentencia',
        20 => 'fecha_sincronizacion'
    ];
    $oi = (int)($_GET['order'][0]['column'] ?? 0);
    $dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $order = $orderMap[$oi] ?? 'numero_sat';

    $sql = "SELECT numero_sat, rfc, nombre_contribuyente, situacion,
                   oficio_presuncion_sat, fecha_sat_presuntos, oficio_presuncion_dof, fecha_dof_presuntos,
                   oficio_desvirtuado_sat, fecha_sat_desvirtuados, oficio_desvirtuado_dof, fecha_dof_desvirtuados,
                   oficio_definitivo_sat, fecha_sat_definitivos, oficio_definitivo_dof, fecha_dof_definitivos,
                   oficio_sentencia_sat, fecha_sat_sentencia, oficio_sentencia_dof, fecha_dof_sentencia,
                   fecha_sincronizacion
              FROM sat_69b_contribuyentes
              {$where}
             ORDER BY {$order} {$dir}, rfc ASC
             LIMIT :lim OFFSET :off";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $length, PDO::PARAM_INT);
    $st->bindValue(':off', $start, PDO::PARAM_INT);
    $st->execute();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered,
        'data' => $st->fetchAll(PDO::FETCH_ASSOC),
        'resumen' => $resumen,
        'control' => $control,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    seguridad_log_error($e, 'listar_sat_69b');
    http_response_code(500);
    echo json_encode([
        'draw' => $draw ?? 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'No se pudo consultar el artículo 69-B. ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
