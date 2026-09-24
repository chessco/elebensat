<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');

$accion = $_POST['accion'] ?? '';

if ($accion === 'guardar') {
    try {
        $id          = (int)($_POST['id_usuario'] ?? 0);
        $nombre      = trim((string)($_POST['nombre_real'] ?? ''));
        $usuarioRaw  = (string)($_POST['usuario'] ?? '');
        $correo      = strtolower(trim((string)($_POST['correo'] ?? '')));
        $idZonaRaw   = $_POST['id_zona'] ?? null;
        $passRaw     = (string)($_POST['password_raw'] ?? '');

        if ($nombre === '') {
            echo json_encode(['success' => false, 'error' => 'El nombre completo es obligatorio.']);
            exit;
        }

        $usuario = validar_usuario($usuarioRaw);
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario inválido (de 4 a 50 caracteres, solo letras minúsculas, números, punto y guion bajo).']);
            exit;
        }

        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Capture un correo electrónico válido.']);
            exit;
        }

        $valZona = empty($idZonaRaw) ? null : (int)$idZonaRaw;

        if ($id <= 0) {
            if ($passRaw === '') {
                echo json_encode(['success' => false, 'error' => 'La contraseña temporal es obligatoria para nuevos usuarios.']);
                exit;
            }
            if (strlen($passRaw) < 8 || strlen($passRaw) > 72) {
                echo json_encode(['success' => false, 'error' => 'La contraseña temporal debe tener entre 8 y 72 caracteres.']);
                exit;
            }

            $hash = password_hash($passRaw, PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuarios
                    (usuario, nombre_real, correo, password_hash, id_zona, es_admin_zona, es_superadmin, activo, debe_cambiar_password)
                    VALUES (?, ?, ?, ?, ?, 0, 0, 1, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$usuario, $nombre, $correo, $hash, $valZona]);
        } else {
            if ($passRaw !== '') {
                if (strlen($passRaw) < 8 || strlen($passRaw) > 72) {
                    echo json_encode(['success' => false, 'error' => 'La contraseña temporal debe tener entre 8 y 72 caracteres.']);
                    exit;
                }
                $hash = password_hash($passRaw, PASSWORD_DEFAULT);
                $sql = "UPDATE usuarios
                        SET nombre_real=?, usuario=?, correo=?, password_hash=?, id_zona=?,
                            debe_cambiar_password=1, fecha_cambio_password=NULL
                        WHERE id_usuario=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $usuario, $correo, $hash, $valZona, $id]);
            } else {
                $sql = "UPDATE usuarios SET nombre_real=?, usuario=?, correo=?, id_zona=? WHERE id_usuario=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $usuario, $correo, $valZona, $id]);
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            echo json_encode(['success' => false, 'error' => 'El usuario o correo electrónico ya existe.']);
        } else {
            seguridad_log_error($e, 'usuarios_operaciones');
            echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación de usuario.']);
        }
    }
    exit;
}

if ($accion === 'eliminar') {
    $id = (int)($_POST['id_usuario'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Usuario inválido.']);
        exit;
    }
    if ($id === (int)($_SESSION['id_usuario'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'No puedes eliminar tu propia cuenta.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id_usuario = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        seguridad_log_error($e, 'usuarios_eliminar');
        echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el usuario.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
