<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/correo_crypto.php';
require_once '../includes/smtp_simple.php';

seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');

try {
    $idEmpresa = (int)($_POST['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('Primero guarde la empresa antes de probar el correo.');

    $st = $pdo->prepare('SELECT smtp_password_enc FROM empresas WHERE id_empresa=? LIMIT 1');
    $st->execute([$idEmpresa]);
    $enc = (string)($st->fetchColumn() ?: '');

    $passwordNueva = (string)($_POST['smtp_password'] ?? '');
    $password = $passwordNueva !== '' ? $passwordNueva : ($enc !== '' ? correo_descifrar($enc) : '');

    $destino = trim((string)($_POST['destino'] ?? ''));
    if ($destino === '') throw new RuntimeException('Capture el correo destino para la prueba.');

    $cfg = [
        'host' => trim((string)($_POST['smptp_correo'] ?? '')),
        'port' => (int)($_POST['puerto_correo'] ?? 0),
        'security' => strtoupper(trim((string)($_POST['seguridad_correo'] ?? 'STARTTLS'))),
        'auth' => (($_POST['usa_aut'] ?? '0') === '1'),
        'username' => trim((string)($_POST['smtp_usuario'] ?? '')),
        'password' => $password,
        'from' => trim((string)($_POST['correo_remitente'] ?? '')),
        'from_name' => trim((string)($_POST['nombre_remitente'] ?? '')),
        'reply_to' => trim((string)($_POST['reply_to_correo'] ?? '')),
    ];

    $resultado = smtp_probar_y_enviar($cfg, $destino);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'probar_correo_empresa');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
