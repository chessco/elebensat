<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
header('Content-Type: application/json; charset=utf-8');

$sesion = seguridad_exigir_sesion($pdo, false, true, true);
$idUsuario = (int)$sesion['id_usuario'];

if (!csrf_validar($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'La sesión del formulario expiró. Recargue la página.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    if ($accion === 'actualizar_correo') {
        $correo = strtolower(trim((string)($_POST['correo'] ?? '')));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Capture un correo electrónico válido.']);
            exit;
        }

        $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE correo = ? AND id_usuario <> ? LIMIT 1');
        $st->execute([$correo, $idUsuario]);
        if ($st->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Ese correo ya está asociado a otro usuario.']);
            exit;
        }

        $st = $pdo->prepare('UPDATE usuarios SET correo = ? WHERE id_usuario = ?');
        $st->execute([$correo, $idUsuario]);
        $_SESSION['correo_usuario'] = $correo;
        echo json_encode(['success' => true, 'msg' => 'Correo actualizado correctamente.']);
        exit;
    }

    if ($accion === 'cambiar_password') {
        $actual = (string)($_POST['password_actual'] ?? '');
        $nueva = (string)($_POST['password_nueva'] ?? '');
        $confirmacion = (string)($_POST['password_confirmacion'] ?? '');

        if ($actual === '' || $nueva === '' || $confirmacion === '') {
            echo json_encode(['success' => false, 'error' => 'Complete los tres campos de contraseña.']);
            exit;
        }
        if ($nueva !== $confirmacion) {
            echo json_encode(['success' => false, 'error' => 'La nueva contraseña y su confirmación no coinciden.']);
            exit;
        }
        if (strlen($nueva) < 8 || strlen($nueva) > 72) {
            echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener entre 8 y 72 caracteres.']);
            exit;
        }

        $st = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id_usuario = ? AND activo = 1 LIMIT 1');
        $st->execute([$idUsuario]);
        $hashActual = $st->fetchColumn();
        if (!is_string($hashActual) || !password_verify($actual, $hashActual)) {
            echo json_encode(['success' => false, 'error' => 'La contraseña actual no es correcta.']);
            exit;
        }
        if (password_verify($nueva, $hashActual)) {
            echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe ser diferente de la actual.']);
            exit;
        }

        $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
        $st = $pdo->prepare('UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 0, fecha_cambio_password = NOW() WHERE id_usuario = ?');
        $st->execute([$nuevoHash, $idUsuario]);

        $_SESSION['debe_cambiar_password'] = 0;
        session_regenerate_id(true);
        echo json_encode(['success' => true, 'msg' => 'Contraseña actualizada correctamente.']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        echo json_encode(['success' => false, 'error' => 'Ese correo ya está asociado a otro usuario.']);
    } else {
        seguridad_log_error($e, 'cuenta_operaciones');
        echo json_encode(['success' => false, 'error' => 'No fue posible actualizar la cuenta.']);
    }
} catch (Throwable $e) {
    seguridad_log_error($e, 'cuenta_operaciones');
    echo json_encode(['success' => false, 'error' => 'No fue posible actualizar la cuenta.']);
}
