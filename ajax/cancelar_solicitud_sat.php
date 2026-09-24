<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'ver_solicitudes_sat'); } catch (Throwable $e) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>$e->getMessage()]); exit; }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function responder(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
        responder(['success' => false, 'error' => 'La sesión ha terminado.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(['success' => false, 'error' => 'Método no permitido.'], 405);
    }

    $id = (int)($_POST['id'] ?? 0);
    $motivo = trim((string)($_POST['motivo_cancelacion'] ?? ''));
    if ($id <= 0) {
        responder(['success' => false, 'error' => 'Solicitud no válida.'], 422);
    }
    if ($motivo === '') {
        responder(['success' => false, 'error' => 'Capture el motivo de cancelación.'], 422);
    }
    if (mb_strlen($motivo) > 500) {
        responder(['success' => false, 'error' => 'El motivo no puede exceder 500 caracteres.'], 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE descargas_sat
         SET estatus = 4,
             fecha_cancelacion = NOW(),
             id_usuario_cancela = :id_usuario,
             motivo_cancelacion = :motivo,
             mensaje_sat = CASE
                 WHEN id_solicitud IS NULL OR id_solicitud = '' THEN 'Cancelada antes de enviarse al SAT'
                 ELSE CONCAT('Cancelada localmente. SAT ID: ', id_solicitud)
             END
         WHERE id = :id
           AND id_empresa = :id_empresa
           AND estatus IN (0,1)"
    );
    $stmt->execute([
        ':id_usuario' => (int)$_SESSION['id_usuario'],
        ':motivo' => $motivo,
        ':id' => $id,
        ':id_empresa' => (int)$_SESSION['id_empresa'],
    ]);

    if ($stmt->rowCount() !== 1) {
        responder(['success' => false, 'error' => 'La solicitud ya no puede cancelarse o no pertenece a la empresa activa.'], 409);
    }

    responder(['success' => true, 'message' => 'La solicitud quedó cancelada. No se eliminó el registro.']);
} catch (Throwable $e) {
    seguridad_log_error($e, 'cancelar_solicitud_sat'); responder(['success' => false, 'error' => 'No se pudo cancelar la solicitud SAT.'], 500);
}
