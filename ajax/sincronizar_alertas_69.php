<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/sat_69_alertas.php';

try {
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69');
    $jobId = trim((string)($_POST['job_id'] ?? ''));
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa en la sesión.');
    if ($jobId === '') throw new InvalidArgumentException('Falta el identificador del proceso.');

    // Libera el lock de sesión para que el mismo usuario pueda consultar progreso
    // y pulsar Cancelar mientras este request sigue trabajando.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $resumen = sat69_cruzar_emisores($pdo, $jobId, $idEmpresa);
    echo json_encode(['success'=>true,'cancelled'=>false,'resumen'=>$resumen], JSON_UNESCAPED_UNICODE);
} catch (SatCruceCanceladoException $e) {
    echo json_encode([
        'success'=>true,
        'cancelled'=>true,
        'msg'=>'Cruce cancelado. No se guardaron cambios parciales.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    seguridad_log_error($e,'sincronizar_alertas_69');
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'error'=>'No se pudo cruzar artículo 69: '.$e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
