<?php
/**
 * CIERRE DE SESIÓN SEGURO
 */
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 1. Vaciamos el array de sesión en el servidor
$_SESSION = array();

// 2. Destruimos la cookie de sesión en el navegador del usuario
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruimos la sesión en el servidor
session_destroy();

// 4. Redirigimos al index con un parámetro de limpieza
$qs = (isset($_GET['password_changed']) && $_GET['password_changed'] === '1') ? 'password_changed=1' : 'logout=1';
header('Location: ../index.php?' . $qs);
exit;