<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

function responder($ok, $datos = []) {
    echo json_encode(array_merge(['success' => $ok], $datos), JSON_UNESCAPED_UNICODE);
    exit;
}
function pemCertificado($derOPEM) {
    if (strpos($derOPEM, '-----BEGIN CERTIFICATE-----') !== false) return $derOPEM;
    return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($derOPEM), 64, "\n") . "-----END CERTIFICATE-----\n";
}
function candidatosLlavePem($contenido) {
    if (strpos($contenido, '-----BEGIN') !== false) return [$contenido];
    $b64 = chunk_split(base64_encode($contenido), 64, "\n");
    return [
        "-----BEGIN ENCRYPTED PRIVATE KEY-----\n{$b64}-----END ENCRYPTED PRIVATE KEY-----\n",
        "-----BEGIN PRIVATE KEY-----\n{$b64}-----END PRIVATE KEY-----\n",
        "-----BEGIN RSA PRIVATE KEY-----\n{$b64}-----END RSA PRIVATE KEY-----\n"
    ];
}
function obtenerRfcCertificado(array $info) {
    $candidatos = [];
    foreach (['x500UniqueIdentifier','serialNumber','UID'] as $k) {
        if (!empty($info['subject'][$k])) $candidatos[] = $info['subject'][$k];
    }
    if (!empty($info['name'])) $candidatos[] = $info['name'];
    foreach ($candidatos as $texto) {
        if (preg_match('/[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}/i', $texto, $m)) return strtoupper($m[0]);
    }
    return '';
}
function datosCertificado($certPem) {
    $cert = openssl_x509_read($certPem);
    if (!$cert) responder(false, ['error' => 'El archivo CER no es un certificado válido.']);
    $info = openssl_x509_parse($cert);
    if (!$info) responder(false, ['error' => 'No se pudo leer la información del certificado.']);
    return [$cert, $info];
}
function validarRfc($rfcEmpresa, $rfcCert) {
    $rfcEmpresa = strtoupper(trim($rfcEmpresa));
    if ($rfcEmpresa !== '' && $rfcCert !== '' && $rfcEmpresa !== $rfcCert) {
        responder(false, ['error' => "El RFC del certificado ({$rfcCert}) no coincide con la empresa ({$rfcEmpresa})."]);
    }
}

$accion = $_POST['accion'] ?? '';
$passwordPfx = (string)($_POST['password_pfx'] ?? '');
$rfcEmpresa = (string)($_POST['rfc_empresa'] ?? '');
$idEmpresa = (int)($_POST['id_empresa'] ?? 0);

if ($accion === 'generar') {
    $tipo = strtolower((string)($_POST['tipo'] ?? 'fiel'));

    $certB64 = (string)($_POST['cert_b64'] ?? '');
    $keyB64 = (string)($_POST['key_b64'] ?? '');
    $passwordKey = (string)($_POST['password_key'] ?? '');

    // Para una empresa ya guardada, si no llegaron archivos nuevos desde
    // pantalla, usamos lo que ya está almacenado. Así el usuario NO vuelve
    // a seleccionar CER/KEY para generar el PFX.
    if ($idEmpresa > 0 && ($certB64 === '' || $keyB64 === '' || $passwordKey === '')) {
        if ($tipo === 'fiel') {
            $stmt = $pdo->prepare(
                "SELECT certificado_fiel, llave_fiel, passfiel, rfc
                   FROM empresas
                  WHERE id_empresa=?"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT certificado_csd AS certificado_fiel,
                        llave_csd AS llave_fiel,
                        passcsd AS passfiel,
                        rfc
                   FROM empresas
                  WHERE id_empresa=?"
            );
        }
        $stmt->execute([$idEmpresa]);
        $guardados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guardados) {
            responder(false, ['error' => 'No se encontró la empresa.']);
        }

        $certB64 = (string)($guardados['certificado_fiel'] ?? '');
        $keyB64 = (string)($guardados['llave_fiel'] ?? '');
        $passwordKey = (string)($guardados['passfiel'] ?? '');

        if ($rfcEmpresa === '') {
            $rfcEmpresa = (string)($guardados['rfc'] ?? '');
        }
    }

    $certRaw = base64_decode($certB64, true);
    $keyRaw = base64_decode($keyB64, true);

    if ($certRaw === false || $keyRaw === false || $certRaw === '' || $keyRaw === '') {
        responder(false, ['error' => 'La empresa no tiene CER y KEY almacenados. Cárgalos y guarda primero la empresa.']);
    }
    if ($passwordKey === '') {
        responder(false, ['error' => 'La empresa no tiene almacenada la contraseña de la FIEL/llave privada.']);
    }

    $certPem = pemCertificado($certRaw);
    [$cert, $info] = datosCertificado($certPem);

    $privateKey = false;
    while (openssl_error_string() !== false) {}
    foreach (candidatosLlavePem($keyRaw) as $pem) {
        $privateKey = openssl_pkey_get_private($pem, $passwordKey);
        if ($privateKey) break;
    }

    if (!$privateKey) {
        responder(false, ['error' => 'No se pudo abrir la llave KEY almacenada. Revisa la contraseña de la FIEL.']);
    }
    if (!openssl_x509_check_private_key($cert, $privateKey)) {
        responder(false, ['error' => 'El CER y la KEY almacenados no corresponden entre sí.']);
    }

    $rfcCert = obtenerRfcCertificado($info);
    validarRfc($rfcEmpresa, $rfcCert);

    if (!empty($info['validTo_time_t']) && $info['validTo_time_t'] < time()) {
        responder(false, ['error' => 'El certificado está vencido.']);
    }

    // Regla funcional: al GENERAR, la contraseña del PFX será la misma
    // contraseña de la FIEL/KEY. El campo contraseña PFX se usa cuando el
    // usuario YA cuenta con un PFX externo.
    $passwordPfx = $passwordKey;

    $pfx = '';
    if (!openssl_pkcs12_export(
        $cert,
        $pfx,
        $privateKey,
        $passwordPfx,
        ['friendly_name' => $tipo]
    )) {
        responder(false, ['error' => 'OpenSSL no pudo construir el archivo PFX.']);
    }

    $guardado = false;

    // Si la empresa ya existe, el PFX generado queda almacenado de inmediato.
    if ($idEmpresa > 0) {
        if ($tipo === 'fiel') {
            $stmt = $pdo->prepare(
                "UPDATE empresas
                    SET pfx_fiel=?,
                        pass_pfx_fiel=?,
                        pfx_fiel_validado=0,
                        descarga_sat_automatica=0,
                        pfx_fiel_rfc=NULL,
                        pfx_fiel_fecha_inicio=NULL,
                        pfx_fiel_fecha_vencimiento=NULL,
                        pfx_fiel_ultima_validacion=NULL
                  WHERE id_empresa=?"
            );
        } else {
            $stmt = $pdo->prepare(
                "UPDATE empresas
                    SET pfx_csd=?,
                        pass_pfx_csd=?
                  WHERE id_empresa=?"
            );
        }
        $stmt->execute([base64_encode($pfx), $passwordPfx, $idEmpresa]);
        $guardado = true;
    }

    responder(true, [
        'pfx_b64' => base64_encode($pfx),
        // Se devuelve para que una empresa aún no guardada pueda conservarlo
        // al hacer GUARDAR EMPRESA.
        'password_pfx' => $passwordPfx,
        'guardado' => $guardado,
        'rfc' => $rfcCert,
        'creacion' => !empty($info['validFrom_time_t']) ? date('d/m/Y', $info['validFrom_time_t']) : '',
        'vencimiento' => !empty($info['validTo_time_t']) ? date('d/m/Y', $info['validTo_time_t']) : ''
    ]);
}

if ($accion === 'validar' || $accion === 'validar_activar') {
    if ($passwordPfx === '') responder(false, ['error' => 'Captura la contraseña del PFX.']);
    $pfx = base64_decode($_POST['pfx_b64'] ?? '', true);
    if ($pfx === false || $pfx === '') responder(false, ['error' => 'No se recibió el archivo PFX.']);
    $certs = [];
    if (!openssl_pkcs12_read($pfx, $certs, $passwordPfx)) responder(false, ['error' => 'El PFX o su contraseña no son válidos.']);
    if (empty($certs['cert']) || empty($certs['pkey'])) responder(false, ['error' => 'El PFX no contiene certificado y llave privada.']);
    [$cert, $info] = datosCertificado($certs['cert']);
    if (!openssl_x509_check_private_key($cert, $certs['pkey'])) responder(false, ['error' => 'La llave privada del PFX no coincide con el certificado.']);
    $rfcCert = obtenerRfcCertificado($info);
    validarRfc($rfcEmpresa, $rfcCert);
    $inicioIso = !empty($info['validFrom_time_t']) ? date('Y-m-d', $info['validFrom_time_t']) : null;
    $venceIso = !empty($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : null;
    if (!$venceIso) responder(false, ['error' => 'No se pudo determinar la fecha de vencimiento del PFX.']);
    if ($info['validTo_time_t'] < time()) responder(false, ['error' => 'El PFX de la FIEL está vencido desde ' . date('d/m/Y', $info['validTo_time_t']) . '.']);
    if (!empty($info['validFrom_time_t']) && $info['validFrom_time_t'] > time()) responder(false, ['error' => 'El certificado todavía no entra en vigencia.']);
    $dias = (int)floor(($info['validTo_time_t'] - strtotime(date('Y-m-d'))) / 86400);
    $ultima = date('Y-m-d H:i:s');

    if ($accion === 'validar_activar') {
        $idEmpresa = (int)($_POST['id_empresa'] ?? 0);
        if ($idEmpresa <= 0) responder(false, ['error' => 'No se recibió la empresa que se va a activar.']);
        $stmt = $pdo->prepare("UPDATE empresas SET pfx_fiel_validado=1, pfx_fiel_rfc=?, pfx_fiel_fecha_inicio=?, pfx_fiel_fecha_vencimiento=?, pfx_fiel_ultima_validacion=?, descarga_sat_automatica=1 WHERE id_empresa=?");
        $stmt->execute([$rfcCert, $inicioIso, $venceIso, $ultima, $idEmpresa]);
    }

    responder(true, [
        'rfc' => $rfcCert,
        'creacion' => $inicioIso ? date('d/m/Y', strtotime($inicioIso)) : '',
        'vencimiento' => $venceIso ? date('d/m/Y', strtotime($venceIso)) : '',
        'pfx_fiel_validado' => 1,
        'pfx_fiel_rfc' => $rfcCert,
        'pfx_fiel_fecha_inicio' => $inicioIso,
        'pfx_fiel_fecha_vencimiento' => $venceIso,
        'pfx_fiel_ultima_validacion' => $ultima,
        'pfx_fiel_dias_restantes' => $dias,
        'descarga_sat_automatica' => $accion === 'validar_activar' ? 1 : 0
    ]);
}
responder(false, ['error' => 'Operación no válida.']);
