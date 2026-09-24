<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);

header('Content-Type: application/json');
error_reporting(0);

$cert_b64 = $_POST['cert_b64'] ?? '';
$key_b64  = $_POST['key_b64'] ?? '';
$password = $_POST['password'] ?? '';
$validar_match = (int)($_POST['validar_match'] ?? 0);

// Preferir archivos binarios originales si vienen por multipart/form-data.
$cert_raw = '';
$key_raw = '';

if (!empty($_FILES['file_cer']['tmp_name']) && is_uploaded_file($_FILES['file_cer']['tmp_name'])) {
    $cert_raw = (string)file_get_contents($_FILES['file_cer']['tmp_name']);
} elseif ($cert_b64 !== '') {
    $tmp = base64_decode($cert_b64, true);
    if ($tmp !== false) $cert_raw = $tmp;
}

if ($validar_match === 1) {
    if (!empty($_FILES['file_key']['tmp_name']) && is_uploaded_file($_FILES['file_key']['tmp_name'])) {
        $key_raw = (string)file_get_contents($_FILES['file_key']['tmp_name']);
    } elseif ($key_b64 !== '') {
        $tmp = base64_decode($key_b64, true);
        if ($tmp !== false) $key_raw = $tmp;
    }
}

if ($cert_raw === '') {
    echo json_encode(['success' => false, 'error' => 'No hay certificado']);
    exit;
}

$cert_content = $cert_raw;
if (strpos($cert_content, '-----BEGIN CERTIFICATE-----') === false) {
    $cert_content = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert_content), 64, "\n") . "-----END CERTIFICATE-----\n";
}

$res_cert = openssl_x509_read($cert_content);
if (!$res_cert) {
    echo json_encode(['success' => false, 'error' => 'CER Inválido']);
    exit;
}

$info = openssl_x509_parse($res_cert);
$data = [
    'success' => true,
    'creacion' => date('d/m/Y', $info['validFrom_time_t']),
    'vencimiento' => date('d/m/Y', $info['validTo_time_t'])
];

// Solo si se solicita validar la pareja (Match)
if ($validar_match === 1) {
    if ($key_raw === '') {
        echo json_encode(['success' => false, 'error' => 'KEY inválida o vacía']);
        exit;
    }

    $key_content = $key_raw;
    if (strpos($key_content, '-----BEGIN') === false) {
        $key_content = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
                     . chunk_split(base64_encode($key_content), 64, "\n")
                     . "-----END ENCRYPTED PRIVATE KEY-----\n";
    }

    while (openssl_error_string() !== false) {}
    $res_key = openssl_pkey_get_private($key_content, $password);

    if (!$res_key) {
        $errores = [];
        while ($msg = openssl_error_string()) $errores[] = $msg;
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo abrir la llave KEY. Verifique el archivo y la contraseña.',
            'openssl' => $errores
        ]);
        exit;
    }

    if (!openssl_x509_check_private_key($res_cert, $res_key)) {
        echo json_encode(['success' => false, 'error' => 'El CER y la KEY no corresponden entre sí']);
        exit;
    }
}

echo json_encode($data);