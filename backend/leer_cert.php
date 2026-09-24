<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);

header('Content-Type: application/json');
if (!isset($_POST['b64']) || empty($_POST['b64'])) { echo json_encode(['success' => false]); exit; }

$data = base64_decode($_POST['b64']);
if (strpos($data, '-----BEGIN CERTIFICATE-----') === false) {
    $data = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($data), 64, "\n") . "-----END CERTIFICATE-----\n";
}
$info = openssl_x509_parse($data);
if ($info) {
    echo json_encode([
        'success' => true, 
        'creacion' => date('d/m/Y', $info['validFrom_time_t']),
        'vencimiento' => date('d/m/Y', $info['validTo_time_t'])
    ]);
} else {
    echo json_encode(['success' => false]);
}