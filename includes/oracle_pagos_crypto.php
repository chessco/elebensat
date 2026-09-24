<?php
declare(strict_types=1);

/**
 * Cifrado exclusivo para ORACLE PAGOS.
 * Reutiliza únicamente la llave maestra externa del sistema.
 * NO usa ni modifica empresa_contpaq_config.
 */
function oracle_pagos_llave(): string
{
    $valor = getenv('SGKSAT_SECRET_KEY');

    if (!is_string($valor) || trim($valor) === '') {
        $cfg = $GLOBALS['config'] ?? null;
        if (is_array($cfg) && isset($cfg['security']['app_key']) && is_string($cfg['security']['app_key'])) {
            $valor = $cfg['security']['app_key'];
        }
    }

    if (!is_string($valor) || trim($valor) === '') {
        throw new RuntimeException('No está configurada la llave maestra de cifrado del Visor.');
    }

    $valor = trim($valor);
    if (str_starts_with($valor, 'base64:')) {
        $bin = base64_decode(substr($valor, 7), true);
        if ($bin === false || strlen($bin) < 32) {
            throw new RuntimeException('La llave maestra base64 no es válida.');
        }
        return substr($bin, 0, 32);
    }

    if (strlen($valor) < 20) {
        throw new RuntimeException('La llave maestra configurada es demasiado corta.');
    }

    return hash('sha256', $valor, true);
}

function oracle_pagos_cifrar(string $plain): string
{
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL no está disponible en PHP.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        oracle_pagos_llave(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'VISOR-ORACLE-PAGOS',
        16
    );

    if ($cipher === false) {
        throw new RuntimeException('No fue posible cifrar la contraseña Oracle.');
    }

    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function oracle_pagos_descifrar(?string $encrypted): string
{
    $encrypted = (string)$encrypted;
    if ($encrypted === '') return '';

    if (!str_starts_with($encrypted, 'v1:')) {
        throw new RuntimeException('Formato de contraseña Oracle no reconocido.');
    }

    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('La contraseña cifrada Oracle está dañada.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        oracle_pagos_llave(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'VISOR-ORACLE-PAGOS'
    );

    if ($plain === false) {
        throw new RuntimeException('No fue posible descifrar la contraseña Oracle.');
    }
    return $plain;
}
