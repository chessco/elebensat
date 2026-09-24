<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

try {
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69');

    $resumen = $pdo->query(
        "SELECT COUNT(*) total,
                SUM(nivel_riesgo='ALTO') alto,
                SUM(nivel_riesgo='MEDIO') medio,
                SUM(nivel_riesgo='INFORMATIVO') informativo,
                COUNT(DISTINCT rfc) rfcs
           FROM sat_69_contribuyentes"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $tipos = $pdo->query(
        "SELECT tipo_publicacion, COUNT(*) total
           FROM sat_69_contribuyentes
          GROUP BY tipo_publicacion
          ORDER BY tipo_publicacion"
    )->fetchAll(PDO::FETCH_ASSOC);

    $control = $pdo->query(
        "SELECT fecha_sincronizacion, total_registros, archivos_procesados, leyenda_actualizacion
           FROM sat_69_control
          WHERE id_control = 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'resumen' => array_map('intval', $resumen),
        'tipos' => $tipos,
        'control' => $control,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'resumen_sat_69');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cargar el resumen.'], JSON_UNESCAPED_UNICODE);
}
