<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Laboratorio de Validación SAT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0d1117; color: #adbac7; padding: 30px; }
        .card { background-color: #1c2128; border: 1px solid #444c56; color: white; }
        pre { background: #000; color: #58a6ff; padding: 10px; border-radius: 5px; font-size: 0.8rem; }
        .status-ok { color: #238636; font-weight: bold; }
        .status-error { color: #da3633; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="card shadow-lg">
        <div class="card-header border-secondary text-center">
            <h4 class="text-info">DEPURADOR DE CERTIFICADOS Y LLAVES</h4>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label small">1. Archivo .CER</label>
                    <input type="file" name="file_cer" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label small">2. Archivo .KEY</label>
                    <input type="file" name="file_key" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">3. Password</label>
                    <input type="password" name="password" class="form-control form-control-sm" required>
                </div>
                <div class="col-12 text-center mt-4">
                    <button type="submit" name="testear" class="btn btn-primary btn-sm px-5">REALIZAR PRUEBA DE FUEGO</button>
                </div>
            </form>

            <div class="mt-4">
                <?php
                if (isset($_POST['testear'])) {
                    $cer_path = $_FILES['file_cer']['tmp_name'];
                    $key_path = $_FILES['file_key']['tmp_name'];
                    $pass = $_POST['password'];

                    echo "<h6 class='border-bottom border-secondary pb-2'>RESULTADOS DEL ANÁLISIS:</h6>";

                    // --- PASO 1: LEER CERTIFICADO ---
                    $cer_data = file_get_contents($cer_path);
                    // Forzar conversión DER a PEM (Parche para archivos del SAT)
                    if (strpos($cer_data, '-----BEGIN CERTIFICATE-----') === false) {
                        $cer_data = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cer_data), 64, "\n") . "-----END CERTIFICATE-----\n";
                    }
                    
                    $res_cert = openssl_x509_read($cer_data);
                    if ($res_cert) {
                        $info = openssl_x509_parse($res_cert);
                        echo "<li>Certificado: <span class='status-ok'>VÁLIDO</span></li>";
                        echo "<li>RFC: <b>" . ($info['subject']['serialNumber'] ?? 'N/A') . "</b></li>";
                        echo "<li>Nombre: <b>" . ($info['subject']['CN'] ?? 'N/A') . "</b></li>";
                        echo "<li>Vencimiento: <b>" . date('d/m/Y H:i:s', $info['validTo_time_t']) . "</b></li>";
                    } else {
                        echo "<li>Certificado: <span class='status-error'>INVÁLIDO</span></li>";
                    }

                    echo "<hr class='border-secondary'>";

                    // --- PASO 2: LEER LLAVE PRIVADA ---
                    $key_data = file_get_contents($key_path);

                    /*
                     * Las llaves .KEY de la e.firma del SAT normalmente vienen en
                     * DER PKCS#8 CIFRADO. No deben envolverse como "PRIVATE KEY",
                     * porque esa etiqueta corresponde a una llave PKCS#8 sin cifrar.
                     *
                     * Para un DER cifrado la etiqueta PEM correcta es:
                     *     -----BEGIN ENCRYPTED PRIVATE KEY-----
                     */
                    if (strpos($key_data, '-----BEGIN') === false) {
                        $key_data = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
                                  . chunk_split(base64_encode($key_data), 64, "\n")
                                  . "-----END ENCRYPTED PRIVATE KEY-----\n";
                    }

                    // Limpiar errores viejos de OpenSSL antes de intentar la llave.
                    while (openssl_error_string() !== false) {}

                    // Abrir/desencriptar la llave usando la contraseña capturada.
                    $res_key = openssl_pkey_get_private($key_data, $pass);

                    if ($res_key) {
                        echo "<li>Llave Privada: <span class='status-ok'>ABIERTA CORRECTAMENTE</span></li>";
                        
                        // --- PASO 3: VALIDAR MATCH ---
                        if (openssl_x509_check_private_key($res_cert, $res_key)) {
                            echo "<div class='alert alert-success mt-3'><b>✓ ÉXITO TOTAL:</b> El certificado y la llave son pareja y la contraseña es correcta.</div>";
                        } else {
                            echo "<div class='alert alert-warning mt-3'><b>! ADVERTENCIA:</b> La contraseña es correcta, pero la llave NO pertenece a este certificado.</div>";
                        }
                    } else {
                        echo "<li>Llave Privada: <span class='status-error'>FALLÓ AL ABRIR</span></li>";
                        echo "<div class='alert alert-danger mt-3 mb-2'>";
                        echo "<b>No se pudo descifrar la llave.</b> Verifique primero la contraseña. ";
                        echo "Si la contraseña es correcta, revise que el archivo corresponda realmente a la e.firma del SAT.";
                        echo "</div>";
                        echo "<p class='text-danger small mt-2'>ERRORES DE OPENSSL:</p>";
                        echo "<pre>";
                        while ($msg = openssl_error_string()) { echo $msg . "\n"; }
                        echo "</pre>";
                    }
                }
                ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>