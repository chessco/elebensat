<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_cruce_metadata');

if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
    http_response_code(401);
    echo json_encode(['draw'=>0,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'Sesión no válida.']);
    exit;
}

$idEmpresa = (int)$_SESSION['id_empresa'];
$modo = strtolower(trim((string)($_GET['modo'] ?? $_POST['modo'] ?? 'faltantes')));
if (!in_array($modo, ['faltantes','todos'], true)) $modo = 'faltantes';

$draw = (int)($_GET['draw'] ?? $_POST['draw'] ?? 1);
$start = max(0, (int)($_GET['start'] ?? $_POST['start'] ?? 0));
$length = (int)($_GET['length'] ?? $_POST['length'] ?? 25);
if ($length < 1) $length = 25;
if ($length > 250) $length = 250;

$search = '';
if (isset($_GET['search']['value'])) $search = trim((string)$_GET['search']['value']);
elseif (isset($_POST['search']['value'])) $search = trim((string)$_POST['search']['value']);

try {
    if ($modo === 'faltantes') {
        $baseFrom = "
            FROM cfdi_faltantes_sat x
            LEFT JOIN facturas f
              ON f.id_empresa = x.id_empresa
             AND f.uuid = x.uuid
            WHERE x.id_empresa = :empresa
              AND x.estatus_recuperacion <> 2
              AND f.uuid IS NULL
        ";
        $params = [':empresa'=>$idEmpresa];

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom");
        $st->execute($params);
        $recordsTotal = (int)$st->fetchColumn();

        $whereSearch = '';
        if ($search !== '') {
            $whereSearch = " AND (x.uuid LIKE :q OR x.rfc_emisor LIKE :q OR x.nombre_emisor LIKE :q OR x.rfc_receptor LIKE :q OR x.nombre_receptor LIKE :q OR x.estatus_sat LIKE :q)";
            $params[':q'] = '%'.$search.'%';
        }

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $whereSearch");
        $st->execute($params);
        $recordsFiltered = (int)$st->fetchColumn();

        $sql = "
            SELECT x.uuid, x.tipo, x.fecha_emision, x.rfc_emisor, x.nombre_emisor,
                   x.rfc_receptor, x.nombre_receptor, x.monto, x.estatus_sat,
                   x.fecha_detectado, 'FALTA XML' AS estado_cruce
            $baseFrom $whereSearch
            ORDER BY x.fecha_emision DESC, x.uuid
            LIMIT $start, $length
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $baseFrom = "
            FROM sat_metadata_cfdi m
            LEFT JOIN facturas f
              ON f.id_empresa = m.id_empresa
             AND f.uuid = m.uuid
            WHERE m.id_empresa = :empresa
        ";
        $params = [':empresa'=>$idEmpresa];

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom");
        $st->execute($params);
        $recordsTotal = (int)$st->fetchColumn();

        $whereSearch = '';
        if ($search !== '') {
            $whereSearch = " AND (m.uuid LIKE :q OR m.rfc_emisor LIKE :q OR m.nombre_emisor LIKE :q OR m.rfc_receptor LIKE :q OR m.nombre_receptor LIKE :q OR m.estatus_sat LIKE :q)";
            $params[':q'] = '%'.$search.'%';
        }

        $st = $pdo->prepare("SELECT COUNT(*) $baseFrom $whereSearch");
        $st->execute($params);
        $recordsFiltered = (int)$st->fetchColumn();

        $sql = "
            SELECT m.uuid, m.tipo, m.fecha_emision, m.rfc_emisor, m.nombre_emisor,
                   m.rfc_receptor, m.nombre_receptor, m.monto, m.estatus_sat,
                   m.fecha_ultima_revision AS fecha_detectado,
                   CASE WHEN f.uuid IS NULL THEN 'FALTA XML' ELSE 'EN VISOR' END AS estado_cruce
            $baseFrom $whereSearch
            ORDER BY m.fecha_emision DESC, m.uuid
            LIMIT $start, $length
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'uuid' => (string)($r['uuid'] ?? ''),
            'tipo' => ucfirst((string)($r['tipo'] ?? '')),
            'fecha' => !empty($r['fecha_emision']) ? date('d/m/Y', strtotime($r['fecha_emision'])) : '',
            'rfc_emisor' => (string)($r['rfc_emisor'] ?? ''),
            'emisor' => (string)($r['nombre_emisor'] ?? ''),
            'rfc_receptor' => (string)($r['rfc_receptor'] ?? ''),
            'receptor' => (string)($r['nombre_receptor'] ?? ''),
            'monto' => is_numeric($r['monto'] ?? null) ? (float)$r['monto'] : null,
            'estatus_sat' => (string)($r['estatus_sat'] ?? ''),
            'estado_cruce' => (string)($r['estado_cruce'] ?? ''),
            'detectado' => !empty($r['fecha_detectado']) ? date('d/m/Y H:i', strtotime($r['fecha_detectado'])) : ''
        ];
    }

    echo json_encode([
        'draw'=>$draw,
        'recordsTotal'=>$recordsTotal,
        'recordsFiltered'=>$recordsFiltered,
        'data'=>$data
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'draw'=>$draw,
        'recordsTotal'=>0,
        'recordsFiltered'=>0,
        'data'=>[],
        'error'=>'Error al consultar el cruce: '.$e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
