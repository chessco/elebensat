<?php
/**
 * Seguridad central del portal.
 * Revalida en servidor que el usuario siga activo y que la empresa activa
 * realmente le pertenezca. No depende de que el menú esté oculto.
 */
function seguridad_responder_denegado(string $mensaje = 'Acceso no autorizado.', int $codigo = 403): void
{
    http_response_code($codigo);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($xhr || str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'error' => $mensaje, 'msg' => $mensaje], JSON_UNESCAPED_UNICODE);
    } else {
        echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

function seguridad_exigir_sesion(
    PDO $pdo,
    bool $requiereEmpresa = true,
    bool $permitirCambioPendiente = false,
    bool $mantenerSesionAbierta = false
): array
{
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    if ($idUsuario <= 0) {
        seguridad_responder_denegado('La sesión ha terminado. Inicie sesión nuevamente.', 401);
    }

    $st = $pdo->prepare('SELECT id_usuario, activo, es_superadmin, debe_cambiar_password FROM usuarios WHERE id_usuario = ? LIMIT 1');
    $st->execute([$idUsuario]);
    $usuario = $st->fetch(PDO::FETCH_ASSOC);
    if (!$usuario || (int)$usuario['activo'] !== 1) {
        $_SESSION = [];
        seguridad_responder_denegado('La sesión ya no es válida. Inicie sesión nuevamente.', 401);
    }

    $esSuper = (int)$usuario['es_superadmin'] === 1;
    $_SESSION['es_superadmin'] = $esSuper ? 1 : 0;
    $_SESSION['debe_cambiar_password'] = (int)($usuario['debe_cambiar_password'] ?? 0);

    // Un usuario con contraseña temporal no puede saltarse el cambio obligatorio
    // escribiendo directamente la URL de un módulo o backend.
    if (!$permitirCambioPendiente && !empty($usuario['debe_cambiar_password'])) {
        seguridad_responder_denegado('Debe cambiar su contraseña temporal antes de continuar.', 403);
    }

    if ($requiereEmpresa) {
        $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
        if ($idEmpresa <= 0) {
            seguridad_responder_denegado('Seleccione una empresa para continuar.', 401);
        }
        $st = $pdo->prepare('SELECT id_empresa, razon_social FROM empresas WHERE id_empresa = ? AND activo=1 LIMIT 1');
        $st->execute([$idEmpresa]);
        $empresa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$empresa) {
            unset($_SESSION['id_empresa'], $_SESSION['empresa_nombre']);
            seguridad_responder_denegado('La empresa activa ya no está disponible.', 403);
        }
        if (!$esSuper) {
            $st = $pdo->prepare('SELECT 1 FROM usuario_empresas WHERE id_usuario = ? AND id_empresa = ? LIMIT 1');
            $st->execute([$idUsuario, $idEmpresa]);
            if (!$st->fetchColumn()) {
                unset($_SESSION['id_empresa'], $_SESSION['empresa_nombre']);
                seguridad_responder_denegado('No tiene acceso a la empresa solicitada.', 403);
            }
        }
    }

    $resultado = ['id_usuario' => $idUsuario, 'es_superadmin' => $esSuper];

    // IMPORTANTE:
    // PHP bloquea la sesión mientras una petición la mantiene abierta.
    // La mayoría de los módulos solo necesita leerla durante la validación.
    // Cerrarla aquí evita que un AJAX largo bloquee Inicio, Emisores, Logout, etc.
    if (!$mantenerSesionAbierta && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $resultado;
}

function seguridad_exigir_superadmin(
    PDO $pdo,
    bool $requiereEmpresa = false,
    bool $mantenerSesionAbierta = false
): void
{
    $u = seguridad_exigir_sesion($pdo, $requiereEmpresa, false, $mantenerSesionAbierta);
    if (!$u['es_superadmin']) {
        seguridad_responder_denegado('Acceso reservado al administrador.', 403);
    }
}

/** Registra el detalle técnico sin exponerlo al navegador. */
function seguridad_log_error(Throwable $e, string $contexto = ''): void
{
    $prefijo = $contexto !== '' ? '[' . $contexto . '] ' : '';
    error_log('[Visor XML] ' . $prefijo . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
}

/** Respuesta genérica para errores internos. */
function seguridad_responder_error_interno(Throwable $e, string $mensaje = 'Ocurrió un error al procesar la solicitud.', bool $json = true): void
{
    seguridad_log_error($e);
    http_response_code(500);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'error' => $mensaje, 'msg' => $mensaje], JSON_UNESCAPED_UNICODE);
    } else {
        echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

