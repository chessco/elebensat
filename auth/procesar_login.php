<?php
session_start();
require_once '../config/db.php';
require_once '../config/paths.php';
header('Content-Type: application/json; charset=utf-8');

function escribir_log($mensaje) {
    $logDir = sgksat_private_path('logs');
    if (!is_dir($logDir)) { @mkdir($logDir, 0770, true); }
    $archivo = $logDir . '/login_debug.log';
    $fecha = date('Y-m-d H:i:s');
    file_put_contents($archivo, "[$fecha] $mensaje" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$accion = $_POST['accion'] ?? '';

try {
    if ($accion === 'validar') {
        $userRaw = (string)($_POST['user'] ?? '');
        $passRaw = (string)($_POST['pass'] ?? '');

        escribir_log("Intento de login para usuario recibido.");

        $usuario = validar_usuario($userRaw);
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario inválido.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($passRaw, $user['password_hash'])) {
            escribir_log("Login rechazado para '$usuario'.");
            echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas.']);
            exit;
        }

        // Evita fijación de sesión después de autenticar correctamente.
        session_regenerate_id(true);
        $_SESSION['id_usuario'] = (int)$user['id_usuario'];
        $_SESSION['nombre_usuario'] = (string)$user['nombre_real'];
        $_SESSION['correo_usuario'] = (string)($user['correo'] ?? '');
        $_SESSION['es_superadmin'] = (int)$user['es_superadmin'];
        $_SESSION['id_zona'] = $user['id_zona'];
        $_SESSION['debe_cambiar_password'] = (int)($user['debe_cambiar_password'] ?? 0);
        unset($_SESSION['id_empresa'], $_SESSION['empresa_nombre']);

        escribir_log("Login correcto para '$usuario'.");

        // La contraseña temporal debe cambiarse ANTES de seleccionar empresa o entrar al portal.
        if (!empty($user['debe_cambiar_password'])) {
            echo json_encode([
                'success' => true,
                'force_password_change' => true,
                'redirect' => 'cuenta/cambiar_password.php'
            ]);
            exit;
        }

        if ((int)$user['es_superadmin'] === 1) {
            $stmtE = $pdo->query('SELECT id_empresa, razon_social, rfc FROM empresas WHERE activo=1 ORDER BY razon_social ASC');
        } else {
            $stmtE = $pdo->prepare('SELECT e.id_empresa, e.razon_social, e.rfc
                                    FROM empresas e
                                    INNER JOIN usuario_empresas ue ON e.id_empresa = ue.id_empresa
                                    WHERE ue.id_usuario = ? AND e.activo=1
                                    ORDER BY e.razon_social ASC');
            $stmtE->execute([(int)$user['id_usuario']]);
        }
        $empresas = $stmtE->fetchAll();

        if (count($empresas) > 0) {
            echo json_encode(['success' => true, 'empresas' => $empresas]);
        } else {
            escribir_log("Usuario '$usuario' validado pero sin empresas asignadas.");
            echo json_encode(['success' => false, 'error' => 'No tienes empresas vinculadas.']);
        }
        exit;
    }

    if ($accion === 'set_empresa') {
        $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
        $idEmp = (int)($_POST['id_empresa'] ?? 0);

        if ($idUsuario <= 0 || $idEmp <= 0) {
            echo json_encode(['success' => false, 'error' => 'Sesión o empresa no válida.']);
            exit;
        }

        // Si sigue pendiente el cambio obligatorio, no puede activar empresa por llamada directa.
        if (!empty($_SESSION['debe_cambiar_password'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Debes cambiar tu contraseña temporal antes de continuar.']);
            exit;
        }

        if (empty($_SESSION['es_superadmin'])) {
            $stPerm = $pdo->prepare('SELECT 1 FROM usuario_empresas WHERE id_usuario = ? AND id_empresa = ? LIMIT 1');
            $stPerm->execute([$idUsuario, $idEmp]);
            if (!$stPerm->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'No tienes acceso a esta empresa.']);
                exit;
            }
        }

        $stmt = $pdo->prepare('SELECT razon_social FROM empresas WHERE id_empresa = ? AND activo=1 LIMIT 1');
        $stmt->execute([$idEmp]);
        $emp = $stmt->fetch();

        if (!$emp) {
            echo json_encode(['success' => false, 'error' => 'Empresa no disponible.']);
            exit;
        }

        $_SESSION['id_empresa'] = $idEmp;
        $_SESSION['empresa_nombre'] = $emp['razon_social'];
        session_regenerate_id(true);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
} catch (PDOException $e) {
    escribir_log('Falla de PDO en login: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Servicio temporalmente no disponible. Intente nuevamente en unos minutos.']);
} catch (Throwable $e) {
    escribir_log('Falla inesperada en login: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Servicio temporalmente no disponible. Intente nuevamente en unos minutos.']);
}
