<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';

function out_error(string $m, int $http=400): void {
    http_response_code($http);
    echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    seguridad_exigir_sesion($pdo, true);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    if ($idEmpresa <= 0) out_error('No hay empresa activa.');

    $total = max(0, (int)($_POST['total_cfdi'] ?? 0));
    if ($total <= 0) out_error('No hay CFDI para iniciar la verificación.');

    $filtros = (string)($_POST['filtros_json'] ?? '{}');
    json_decode($filtros, true);
    if (json_last_error() !== JSON_ERROR_NONE) $filtros = '{}';

    $st = $pdo->prepare("INSERT INTO verificaciones_sat
        (id_empresa,id_usuario,origen,fecha_inicio,filtros_json,total_cfdi,estatus_proceso)
        VALUES (?,?, 'MANUAL', NOW(), ?, ?, 'PROCESANDO')");
    $st->execute([$idEmpresa, $idUsuario ?: null, $filtros, $total]);

    echo json_encode([
        'ok'=>true,
        'id_verificacion'=>(int)$pdo->lastInsertId(),
        'total_cfdi'=>$total
    ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'iniciar_verificacion_sat');
    out_error($e->getMessage() ?: 'No se pudo iniciar la bitácora de verificación.', 500);
}
