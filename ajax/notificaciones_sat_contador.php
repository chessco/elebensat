<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
        http_response_code(401);
        throw new RuntimeException('Sesión no válida.');
    }

    // Copiamos lo necesario de la sesión y liberamos inmediatamente el lock.
    // Así este contador periódico no bloquea otros AJAX del sistema
    // (por ejemplo el catálogo de emisores).
    $idUsuario = (int)$_SESSION['id_usuario'];
    $idEmpresa = (int)$_SESSION['id_empresa'];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notificaciones_sat
         WHERE id_empresa = ?
           AND estatus_notificacion = 'NUEVA'"
    );
    $stmt->execute([$idEmpresa]);

    echo json_encode(
        ['success' => true, 'nuevas' => (int)$stmt->fetchColumn()],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    // Si el worker todavía no ha creado la tabla, la campana simplemente muestra cero.
    seguridad_log_error($e, 'notificaciones_sat_contador'); echo json_encode(['success'=>false,'nuevas'=>0,'error'=>'No se pudieron consultar las notificaciones.'],JSON_UNESCAPED_UNICODE);
}
