<?php
/**
 * Cifrado de secretos CONTPAQi.
 *
 * La llave NO se guarda en MySQL ni dentro de htdocs.
 * Se toma, en este orden, de:
 *   1) variable de entorno SGKSAT_SECRET_KEY
 *   2) C:\SGKSAT\config\database.php -> ['security']['app_key']
 *
 * Formato recomendado de llave: base64:<32 bytes aleatorios en base64>
 */
declare(strict_types=1);

function contpaq_obtener_llave(): string
{
    $valor = getenv('SGKSAT_SECRET_KEY');

    if (!is_string($valor) || trim($valor) === '') {
        $cfg = $GLOBALS['config'] ?? null;
        if (is_array($cfg) && isset($cfg['security']['app_key']) && is_string($cfg['security']['app_key'])) {
            $valor = $cfg['security']['app_key'];
        }
    }

    if (!is_string($valor) || trim($valor) === '') {
        throw new RuntimeException(
            'No está configurada la llave de cifrado SGKSAT_SECRET_KEY / security.app_key.'
        );
    }

    $valor = trim($valor);
    if (str_starts_with($valor, 'base64:')) {
        $bin = base64_decode(substr($valor, 7), true);
        if ($bin === false || strlen($bin) < 32) {
            throw new RuntimeException('La llave base64 configurada no es válida.');
        }
        return substr($bin, 0, 32);
    }

    // Permite una frase larga; se deriva una llave binaria de 256 bits.
    if (strlen($valor) < 20) {
        throw new RuntimeException('La llave de cifrado configurada es demasiado corta.');
    }
    return hash('sha256', $valor, true);
}

function contpaq_cifrar(string $textoPlano): string
{
    if ($textoPlano === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL no está disponible en PHP.');
    }

    $key = contpaq_obtener_llave();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $textoPlano,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'SGKSAT-CONTPAQ',
        16
    );

    if ($cipher === false) {
        throw new RuntimeException('No fue posible cifrar la contraseña CONTPAQi.');
    }

    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function contpaq_descifrar(?string $valorCifrado): string
{
    $valorCifrado = (string)$valorCifrado;
    if ($valorCifrado === '') {
        return '';
    }
    if (!str_starts_with($valorCifrado, 'v1:')) {
        throw new RuntimeException('Formato de contraseña CONTPAQi no reconocido.');
    }

    $raw = base64_decode(substr($valorCifrado, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('La contraseña cifrada CONTPAQi está dañada.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        contpaq_obtener_llave(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'SGKSAT-CONTPAQ'
    );

    if ($plain === false) {
        throw new RuntimeException('No fue posible descifrar la contraseña CONTPAQi.');
    }
    return $plain;
}
