<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/metadata_manual.php';

try {
    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');
    $jobId = strtolower(trim((string)($_POST['job_id'] ?? '')));
    $job = metadata_manual_read_job($idEmpresa, $jobId);
    if ((int)($job['id_usuario'] ?? 0) !== $idUsuario && !usuario_es_superadmin($pdo, $idUsuario)) {
        throw new RuntimeException('No tiene permiso para cancelar este proceso.');
    }
    if (in_array((string)($job['estado'] ?? ''), ['completo','cancelado','error'], true)) {
        echo json_encode(['success'=>true, 'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $job['cancelar'] = true;
    $job['mensaje'] = 'Cancelación solicitada por el usuario.';
    metadata_manual_write_job($idEmpresa, $jobId, $job);
    echo json_encode(['success'=>true, 'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
