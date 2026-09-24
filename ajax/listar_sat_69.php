<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

function sat69_upper(string $texto): string
{
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($texto, 'UTF-8')
        : strtoupper($texto);
}


try {
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69');

    $draw   = (int)($_GET['draw'] ?? 1);
    $start  = max(0, (int)($_GET['start'] ?? 0));
    $length = min(200, max(10, (int)($_GET['length'] ?? 25)));
    $search = trim((string)($_GET['busqueda'] ?? ($_GET['search']['value'] ?? '')));
    $tipo   = trim((string)($_GET['tipo'] ?? ''));
    $riesgo = trim((string)($_GET['riesgo'] ?? ''));

    $whereParts = [];
    $params = [];

    if ($search !== '') {
        $searchUpper = sat69_upper($search);
        $searchRfc   = preg_replace('/[^A-ZÑ&0-9]/u', '', $searchUpper) ?? '';
        $searchParts = [];

        // Búsqueda únicamente por prefijo:
        // - RFC que COMIENCE con los caracteres capturados.
        // - Nombre del contribuyente que COMIENCE con el texto capturado.
        // No busca coincidencias dentro del RFC ni dentro del nombre.
        if ($searchRfc !== '') {
            $searchParts[] = 'rfc LIKE :rfc_inicio';
            $params[':rfc_inicio'] = $searchRfc . '%';
        }

        $searchParts[] = 'nombre_contribuyente LIKE :nombre_inicio';
        $params[':nombre_inicio'] = $searchUpper . '%';

        $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    if ($tipo !== '') {
        $whereParts[] = 'tipo_publicacion = :tipo';
        $params[':tipo'] = $tipo;
    }

    if (in_array($riesgo, ['ALTO', 'MEDIO', 'INFORMATIVO'], true)) {
        $whereParts[] = 'nivel_riesgo = :riesgo';
        $params[':riesgo'] = $riesgo;
    }

    $where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

    // En el listado no se recalculan los indicadores globales ni los tipos.
    // Esas consultas se cargan por separado para que los primeros 25 registros aparezcan rápido.
    $ctl = $pdo->query(
        "SELECT fecha_sincronizacion, total_registros, archivos_procesados, leyenda_actualizacion
           FROM sat_69_control
          WHERE id_control = 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalGeneral = (int)($ctl['total_registros'] ?? 0);
    if ($totalGeneral <= 0) {
        $totalGeneral = (int)$pdo->query('SELECT COUNT(*) FROM sat_69_contribuyentes')->fetchColumn();
    }

    if ($where === '') {
        $filtered = $totalGeneral;
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sat_69_contribuyentes' . $where);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, PDO::PARAM_STR);
        }
        $st->execute();
        $filtered = (int)$st->fetchColumn();
    }

    $map = [
        0 => 'id_69',
        1 => 'rfc',
        2 => 'nombre_contribuyente',
        3 => 'tipo_publicacion',
        4 => 'nivel_riesgo',
        5 => 'fecha_publicacion',
        6 => 'detalle_resumen',
        7 => 'fecha_sincronizacion',
    ];

    $orderIndex = (int)($_GET['order'][0]['column'] ?? 0);
    $direction  = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $orderBy    = $map[$orderIndex] ?? 'id_69';

    // id_69 es PRIMARY KEY y unico; agregar RFC como segundo orden obliga a
    // MariaDB 10.4 a hacer un filesort innecesario sobre cientos de miles
    // de registros. Para la carga inicial se ordena solo por la PK.
    // En las demas columnas se conserva RFC como desempate estable.
    $orderSql = ($orderBy === 'id_69')
        ? "id_69 {$direction}"
        : "{$orderBy} {$direction}, rfc ASC";

    $sql = "SELECT id_69, rfc, nombre_contribuyente, tipo_publicacion, nivel_riesgo,
                   fecha_publicacion, detalle_resumen, fecha_sincronizacion
              FROM sat_69_contribuyentes
                   {$where}
             ORDER BY {$orderSql}
             LIMIT :lim OFFSET :off";

    $st = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $st->bindValue($key, $value, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $length, PDO::PARAM_INT);
    $st->bindValue(':off', $start, PDO::PARAM_INT);
    $st->execute();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalGeneral,
        'recordsFiltered' => $filtered,
        'data' => $st->fetchAll(PDO::FETCH_ASSOC),
        'control' => $ctl,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'listar_sat_69');
    http_response_code(500);
    echo json_encode([
        'draw' => $draw ?? 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'No se pudo consultar el artículo 69. ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
