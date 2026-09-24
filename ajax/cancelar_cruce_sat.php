<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/sat_cruce_job.php';

try {
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion_alguna($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),['consulta_alertas_69','consulta_alertas_69b']);
    $jobId = trim((string)($_POST['job_id'] ?? ''));
    if ($jobId === '') throw new InvalidArgumentException('Falta el identificador del proceso.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    sat_cruce_job_cancelar($jobId);
    echo json_encode([
        'success'=>true,
        'msg'=>'Cancelación solicitada. Se detendrá al terminar el bloque actual.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
