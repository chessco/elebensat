<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');

    $idDescarga = (int)($_GET['id'] ?? 0);
    if ($idDescarga <= 0) {
        throw new RuntimeException('Solicitud inválida.');
    }

    $st = $pdo->prepare("SELECT id, id_solicitud, tipo, paquete_tipo, fecha_inicio, fecha_fin, origen
                        FROM descargas_sat
                        WHERE id=? AND id_empresa=? LIMIT 1");
    $st->execute([$idDescarga, $idEmpresa]);
    $sol = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sol) {
        http_response_code(404);
        throw new RuntimeException('La solicitud no pertenece a la empresa activa.');
    }

    $sqlResumen = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN a.estatus=1 THEN 1 ELSE 0 END) AS procesados,
        SUM(CASE WHEN a.estatus=2 OR (a.estatus=3 AND a.mensaje LIKE 'Duplicate entry%') THEN 1 ELSE 0 END) AS duplicados,
        SUM(CASE WHEN a.estatus=4 OR (a.estatus=3 AND (a.mensaje IS NULL OR a.mensaje NOT LIKE 'Duplicate entry%')) THEN 1 ELSE 0 END) AS errores,
        SUM(CASE WHEN a.estatus=0 THEN 1 ELSE 0 END) AS pendientes
      FROM descargas_sat_archivos a
      INNER JOIN descargas_sat_paquetes p ON p.id=a.id_paquete
      WHERE p.id_descarga_sat=?";
    $stR = $pdo->prepare($sqlResumen);
    $stR->execute([$idDescarga]);
    $resumen = $stR->fetch(PDO::FETCH_ASSOC) ?: [];

    $tipoFiltro = strtolower(trim((string)($_GET['tipo_detalle'] ?? 'todos')));
    $whereExtra = '';
    if ($tipoFiltro === 'duplicados') {
        $whereExtra = " AND (a.estatus=2 OR (a.estatus=3 AND a.mensaje LIKE 'Duplicate entry%'))";
    } elseif ($tipoFiltro === 'errores') {
        $whereExtra = " AND (a.estatus=4 OR (a.estatus=3 AND (a.mensaje IS NULL OR a.mensaje NOT LIKE 'Duplicate entry%')))";
    } elseif ($tipoFiltro === 'procesados') {
        $whereExtra = " AND a.estatus=1";
    } elseif ($tipoFiltro === 'pendientes') {
        $whereExtra = " AND a.estatus=0";
    }

    $sql = "SELECT a.id, a.nombre_archivo, a.uuid, a.estatus, a.intentos, a.mensaje,
                   a.fecha_registro, a.fecha_procesado, p.id_paquete_sat,
                   CASE
                     WHEN a.estatus=1 THEN 'Procesado'
                     WHEN a.estatus=2 OR (a.estatus=3 AND a.mensaje LIKE 'Duplicate entry%') THEN 'Duplicado'
                     WHEN a.estatus=4 THEN 'Rechazado'
                     WHEN a.estatus=3 THEN 'Error'
                     ELSE 'Pendiente'
                   END AS tipo_resultado,
                   COALESCE(NULLIF(a.uuid,''),
                     UPPER(SUBSTRING_INDEX(a.nombre_archivo,'.',1))) AS uuid_mostrar
            FROM descargas_sat_archivos a
            INNER JOIN descargas_sat_paquetes p ON p.id=a.id_paquete
            WHERE p.id_descarga_sat=? {$whereExtra}
            ORDER BY COALESCE(a.fecha_procesado,a.fecha_registro) DESC, a.id DESC
            LIMIT 5000";
    $stD = $pdo->prepare($sql);
    $stD->execute([$idDescarga]);
    $items = $stD->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$r) {
        if ((int)$r['estatus'] === 3 && str_starts_with((string)($r['mensaje'] ?? ''), 'Duplicate entry')) {
            $r['mensaje'] = 'Factura ya registrada. Duplicate entry detectado por MySQL; se considera duplicado, no error.';
        }
        $r['fecha'] = $r['fecha_procesado'] ? date('d/m/Y H:i:s', strtotime($r['fecha_procesado'])) : date('d/m/Y H:i:s', strtotime($r['fecha_registro']));
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'solicitud' => [
            'id' => (int)$sol['id'],
            'id_solicitud' => $sol['id_solicitud'] ?: 'Pendiente de envío',
            'tipo' => ucfirst((string)$sol['tipo']),
            'paquete_tipo' => strtoupper((string)$sol['paquete_tipo']),
            'fecha_inicio' => date('d/m/Y', strtotime($sol['fecha_inicio'])),
            'fecha_fin' => date('d/m/Y', strtotime($sol['fecha_fin'])),
            'origen' => ucfirst((string)$sol['origen']),
        ],
        'resumen' => [
            'total' => (int)($resumen['total'] ?? 0),
            'procesados' => (int)($resumen['procesados'] ?? 0),
            'duplicados' => (int)($resumen['duplicados'] ?? 0),
            'errores' => (int)($resumen['errores'] ?? 0),
            'pendientes' => (int)($resumen['pendientes'] ?? 0),
        ],
        'data' => $items,
        'limitado' => count($items) >= 5000,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    seguridad_log_error($e, 'detalle_procesamiento_sat');
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
