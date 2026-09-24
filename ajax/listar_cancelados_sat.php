<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

$draw = (int)($_GET['draw'] ?? $_POST['draw'] ?? 1);
try {
    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa.');
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');

    $start = max(0, (int)($_GET['start'] ?? $_POST['start'] ?? 0));
    $length = (int)($_GET['length'] ?? $_POST['length'] ?? 25);
    if ($length < 1) $length = 25;
    if ($length > 250) $length = 250;

    $tipo = strtolower(trim((string)($_GET['tipo'] ?? $_POST['tipo'] ?? '')));
    if (!in_array($tipo, ['', 'emitidos', 'recibidos'], true)) $tipo = '';
    $xml = strtolower(trim((string)($_GET['xml'] ?? $_POST['xml'] ?? '')));
    if (!in_array($xml, ['', 'si', 'no'], true)) $xml = '';
    $desde = trim((string)($_GET['desde'] ?? $_POST['desde'] ?? ''));
    $hasta = trim((string)($_GET['hasta'] ?? $_POST['hasta'] ?? ''));

    $search = '';
    if (isset($_GET['search']['value'])) $search = trim((string)$_GET['search']['value']);
    elseif (isset($_POST['search']['value'])) $search = trim((string)$_POST['search']['value']);

    $where = ["m.id_empresa = :empresa", "UPPER(COALESCE(m.estatus_sat,'')) IN ('CANCELADO','CANCELADA','0')"];
    $params = [':empresa' => $idEmpresa];
    if ($tipo !== '') { $where[] = 'LOWER(m.tipo) = :tipo'; $params[':tipo'] = $tipo; }
    if ($desde !== '') { $where[] = 'DATE(COALESCE(m.fecha_cancelacion,m.fecha_emision)) >= :desde'; $params[':desde'] = $desde; }
    if ($hasta !== '') { $where[] = 'DATE(COALESCE(m.fecha_cancelacion,m.fecha_emision)) <= :hasta'; $params[':hasta'] = $hasta; }
    if ($xml === 'si') $where[] = "d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'') <> ''";
    elseif ($xml === 'no') $where[] = "(d.uuid IS NULL OR COALESCE(d.xml_base64,'') = '')";

    $from = "
        FROM sat_metadata_cfdi m
        LEFT JOIN facturas f ON f.id_empresa=m.id_empresa AND f.uuid=m.uuid
        LEFT JOIN facturas_datos d ON d.uuid=f.uuid
    ";
    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $st = $pdo->prepare("SELECT COUNT(*) FROM sat_metadata_cfdi m WHERE m.id_empresa=? AND UPPER(COALESCE(m.estatus_sat,'')) IN ('CANCELADO','CANCELADA','0')");
    $st->execute([$idEmpresa]);
    $recordsTotal = (int)$st->fetchColumn();

    $whereFiltrado = $where;
    $paramsFiltrado = $params;
    if ($search !== '') {
        // PDO/MySQL con prepares nativos no permite reutilizar el mismo placeholder
        // varias veces en la misma sentencia. DataTables manda esta búsqueda al servidor,
        // por eso la tabla cargaba inicialmente pero fallaba al escribir en Buscar.
        $whereFiltrado[] = "(m.uuid LIKE :q_uuid
            OR m.rfc_emisor LIKE :q_rfc_emisor
            OR m.nombre_emisor LIKE :q_nombre_emisor
            OR m.rfc_receptor LIKE :q_rfc_receptor
            OR m.nombre_receptor LIKE :q_nombre_receptor
            OR CAST(m.monto AS CHAR) LIKE :q_monto)";
        $q = '%' . $search . '%';
        $paramsFiltrado[':q_uuid'] = $q;
        $paramsFiltrado[':q_rfc_emisor'] = $q;
        $paramsFiltrado[':q_nombre_emisor'] = $q;
        $paramsFiltrado[':q_rfc_receptor'] = $q;
        $paramsFiltrado[':q_nombre_receptor'] = $q;
        $paramsFiltrado[':q_monto'] = $q;
    }
    $whereFiltradoSql = ' WHERE ' . implode(' AND ', $whereFiltrado);

    $st = $pdo->prepare("SELECT COUNT(*) $from $whereFiltradoSql");
    $st->execute($paramsFiltrado);
    $recordsFiltered = (int)$st->fetchColumn();

    $orderMap = [
        0=>'m.uuid',
        1=>'m.tipo',
        2=>'m.fecha_emision',
        3=>'m.fecha_cancelacion',
        4=>'m.rfc_emisor',
        5=>'m.nombre_emisor',
        6=>'m.rfc_receptor',
        7=>'m.nombre_receptor',
        8=>'m.monto',
        9=>"CASE WHEN d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'')<>'' THEN 1 ELSE 0 END",
        10=>'m.fecha_ultima_revision'
    ];
    $orderCol = (int)($_GET['order'][0]['column'] ?? $_POST['order'][0]['column'] ?? 3);
    $orderDir = strtolower((string)($_GET['order'][0]['dir'] ?? $_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderBy = $orderMap[$orderCol] ?? 'm.fecha_cancelacion';

    $sql = "
        SELECT m.uuid,m.tipo,m.rfc_emisor,m.nombre_emisor,m.rfc_receptor,m.nombre_receptor,
               m.fecha_emision,m.fecha_cancelacion,m.monto,m.efecto_comprobante,m.fecha_ultima_revision,
               CASE WHEN f.uuid IS NULL THEN 0 ELSE 1 END AS factura_en_visor,
               CASE WHEN d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'')<>'' THEN 1 ELSE 0 END AS xml_disponible
        $from $whereFiltradoSql
        ORDER BY $orderBy $orderDir, m.uuid
        LIMIT $start,$length
    ";
    $st = $pdo->prepare($sql);
    $st->execute($paramsFiltrado);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // KPIs de los filtros estructurados (sin la búsqueda libre de DataTables).
    $st = $pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN d.uuid IS NOT NULL AND COALESCE(d.xml_base64,'')<>'' THEN 1 ELSE 0 END) con_xml,
        SUM(CASE WHEN d.uuid IS NULL OR COALESCE(d.xml_base64,'')='' THEN 1 ELSE 0 END) sin_xml,
        SUM(CASE WHEN LOWER(m.tipo)='emitidos' THEN 1 ELSE 0 END) emitidos,
        SUM(CASE WHEN LOWER(m.tipo)='recibidos' THEN 1 ELSE 0 END) recibidos
        $from $whereSql");
    $st->execute($params);
    $resumen = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'uuid'=>(string)($r['uuid']??''),
            'tipo'=>ucfirst((string)($r['tipo']??'')),
            'fecha_emision'=>!empty($r['fecha_emision'])?date('d/m/Y H:i',strtotime($r['fecha_emision'])):'',
            'fecha_cancelacion'=>!empty($r['fecha_cancelacion'])?date('d/m/Y H:i',strtotime($r['fecha_cancelacion'])):'',
            'rfc_emisor'=>(string)($r['rfc_emisor']??''),
            'nombre_emisor'=>(string)($r['nombre_emisor']??''),
            'rfc_receptor'=>(string)($r['rfc_receptor']??''),
            'nombre_receptor'=>(string)($r['nombre_receptor']??''),
            'monto'=>is_numeric($r['monto']??null)?(float)$r['monto']:null,
            'efecto'=>(string)($r['efecto_comprobante']??''),
            'revisado'=>!empty($r['fecha_ultima_revision'])?date('d/m/Y H:i',strtotime($r['fecha_ultima_revision'])):'',
            'factura_en_visor'=>(int)($r['factura_en_visor']??0),
            'xml_disponible'=>(int)($r['xml_disponible']??0),
        ];
    }

    echo json_encode([
        'draw'=>$draw,
        'recordsTotal'=>$recordsTotal,
        'recordsFiltered'=>$recordsFiltered,
        'data'=>$data,
        'resumen'=>[
            'total'=>(int)($resumen['total']??0),
            'con_xml'=>(int)($resumen['con_xml']??0),
            'sin_xml'=>(int)($resumen['sin_xml']??0),
            'emitidos'=>(int)($resumen['emitidos']??0),
            'recibidos'=>(int)($resumen['recibidos']??0),
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    seguridad_log_error($e, 'listar_cancelados_sat');
    echo json_encode(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'No fue posible consultar los CFDI cancelados.'], JSON_UNESCAPED_UNICODE);
}
