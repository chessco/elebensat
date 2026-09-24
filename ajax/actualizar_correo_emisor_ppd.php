<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $idEmisor  = (int)($_POST['id_emisor'] ?? 0);
    $correo    = trim((string)($_POST['correo'] ?? ''));

    if ($idEmpresa <= 0) throw new Exception('No hay empresa activa.');
    if ($idEmisor <= 0) throw new Exception('Emisor no válido.');
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('El correo del emisor no es válido.');
    }

    $st = $pdo->prepare("SELECT id_emisor, rfc, nombre FROM cat_emisores WHERE id_empresa=? AND id_emisor=? LIMIT 1");
    $st->execute([$idEmpresa, $idEmisor]);
    $emisor = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emisor) throw new Exception('El emisor no pertenece a la empresa activa.');

    $up = $pdo->prepare("UPDATE cat_emisores SET correo=:correo WHERE id_empresa=:empresa AND id_emisor=:emisor");
    $up->execute([
        ':correo' => $correo !== '' ? $correo : null,
        ':empresa' => $idEmpresa,
        ':emisor' => $idEmisor,
    ]);

    echo json_encode([
        'ok' => true,
        'id_emisor' => $idEmisor,
        'rfc' => (string)$emisor['rfc'],
        'nombre' => (string)$emisor['nombre'],
        'correo' => $correo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
