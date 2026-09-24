<?php
/** Cifrado de credenciales SMTP. La llave vive fuera de htdocs. */
declare(strict_types=1);

function correo_obtener_llave(): string
{
    $valor = getenv('SGKSAT_SECRET_KEY');
    if (!is_string($valor) || trim($valor) === '') {
        $cfg = $GLOBALS['config'] ?? null;
        if (is_array($cfg) && isset($cfg['security']['app_key']) && is_string($cfg['security']['app_key'])) {
            $valor = $cfg['security']['app_key'];
        }
    }
    if (!is_string($valor) || trim($valor) === '') {
        throw new RuntimeException('No está configurada la llave de cifrado para SMTP.');
    }
    $valor = trim($valor);
    if (str_starts_with($valor, 'base64:')) {
        $bin = base64_decode(substr($valor, 7), true);
        if ($bin === false || strlen($bin) < 32) throw new RuntimeException('La llave base64 no es válida.');
        return substr($bin, 0, 32);
    }
    if (strlen($valor) < 20) throw new RuntimeException('La llave de cifrado es demasiado corta.');
    return hash('sha256', $valor, true);
}

function correo_cifrar(string $textoPlano): string
{
    if ($textoPlano === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($textoPlano, 'aes-256-gcm', correo_obtener_llave(), OPENSSL_RAW_DATA, $iv, $tag, 'SGKSAT-SMTP', 16);
    if ($cipher === false) throw new RuntimeException('No fue posible cifrar la contraseña SMTP.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function correo_descifrar(?string $valorCifrado): string
{
    $valorCifrado = (string)$valorCifrado;
    if ($valorCifrado === '') return '';
    if (!str_starts_with($valorCifrado, 'v1:')) throw new RuntimeException('Formato de contraseña SMTP no reconocido.');
    $raw = base64_decode(substr($valorCifrado, 3), true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('La contraseña SMTP cifrada está dañada.');
    $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',correo_obtener_llave(),OPENSSL_RAW_DATA,$iv,$tag,'SGKSAT-SMTP');
    if ($plain === false) throw new RuntimeException('No fue posible descifrar la contraseña SMTP.');
    return $plain;
}
