<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

try {
    seguridad_exigir_sesion($pdo, true, false, true);
    seguridad_exigir_superadmin($pdo, true);

    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');

    $idConciliacion = (int)($_POST['id_conciliacion'] ?? 0);
    if ($idConciliacion <= 0) {
        throw new RuntimeException('Conciliación inválida.');
    }

    $st = $pdo->prepare('SELECT id_conciliacion FROM nomina_conciliaciones WHERE id_conciliacion=? AND id_empresa=? LIMIT 1');
    $st->execute([$idConciliacion, $idEmpresa]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('La conciliación no existe o no pertenece a la empresa activa.');
    }

    $pdo->beginTransaction();

    $delDet = $pdo->prepare('DELETE FROM nomina_conciliacion_detalles WHERE id_conciliacion=? AND id_empresa=?');
    $delDet->execute([$idConciliacion, $idEmpresa]);
    $detalles = $delDet->rowCount();

    $delCab = $pdo->prepare('DELETE FROM nomina_conciliaciones WHERE id_conciliacion=? AND id_empresa=?');
    $delCab->execute([$idConciliacion, $idEmpresa]);
    if ($delCab->rowCount() !== 1) {
        throw new RuntimeException('No se pudo eliminar el encabezado de la conciliación.');
    }

    $pdo->commit();
    echo json_encode(['success'=>true,'id_conciliacion'=>$idConciliacion,'detalles_eliminados'=>$detalles], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('seguridad_log_error')) seguridad_log_error($e, 'nomina_conciliacion_borrar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
