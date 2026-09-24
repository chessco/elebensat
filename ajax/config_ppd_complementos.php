<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);

function normalizar_correos_ppd(string $valor): string {
    $valor = trim($valor);
    if ($valor === '') return '';
    $partes = preg_split('/[;,]+/', $valor) ?: [];
    $out = [];
    foreach ($partes as $correo) {
        $correo = trim($correo);
        if ($correo === '') continue;
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Correo de copia no válido: ' . $correo);
        }
        $out[strtolower($correo)] = $correo;
    }
    return implode('; ', array_values($out));
}

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new Exception('No hay empresa activa.');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
        $correo = normalizar_correos_ppd((string)($_POST['correo_copia'] ?? ''));
        $modo = strtolower(trim((string)($_POST['modo_copia'] ?? 'individual')));
        if (!in_array($modo, ['individual','resumen'], true)) $modo = 'individual';

        $st = $pdo->prepare("UPDATE empresas SET correo_copia_complementos=:correo, modo_copia_complementos=:modo WHERE id_empresa=:empresa");
        $st->execute([
            ':correo' => $correo !== '' ? $correo : null,
            ':modo' => $modo,
            ':empresa' => $idEmpresa,
        ]);
    }

    $st = $pdo->prepare("SELECT COALESCE(correo_copia_complementos,'') AS correo_copia, COALESCE(modo_copia_complementos,'individual') AS modo_copia FROM empresas WHERE id_empresa=? LIMIT 1");
    $st->execute([$idEmpresa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Empresa no encontrada.');

    echo json_encode([
        'ok' => true,
        'correo_copia' => (string)$row['correo_copia'],
        'modo_copia' => in_array($row['modo_copia'], ['individual','resumen'], true) ? $row['modo_copia'] : 'individual',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
