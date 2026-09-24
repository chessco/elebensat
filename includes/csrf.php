<?php
/** CSRF mínimo para formularios sensibles del portal. */
function csrf_token(): string
{
    if (!empty($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
        return $_SESSION['csrf_token'];
    }

    $abrioSesion = false;

    // seguridad_exigir_sesion() normalmente libera el lock.
    // Reabrimos solo unos milisegundos cuando hace falta crear el token.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        $abrioSesion = true;
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $token = $_SESSION['csrf_token'];

    if ($abrioSesion && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $token;
}

function csrf_validar(?string $token): bool
{
    $sesion = $_SESSION['csrf_token'] ?? '';
    return is_string($sesion) && $sesion !== '' && is_string($token) && hash_equals($sesion, $token);
}
