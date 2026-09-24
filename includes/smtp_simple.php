<?php
/**
 * Cliente SMTP mínimo para pruebas de configuración.
 * Soporta conexión simple, STARTTLS, SSL/TLS implícito y AUTH LOGIN.
 * No sustituye a un mailer completo; se usa para validar la cuenta desde Empresas.
 */

function smtp_leer_respuesta($fp, array $codigosEsperados, int $timeoutSeg = 20): string
{
    stream_set_timeout($fp, $timeoutSeg);
    $respuesta = '';
    while (($linea = fgets($fp, 4096)) !== false) {
        $respuesta .= $linea;
        // Las respuestas multilínea llevan "250-" y terminan con "250 ".
        if (strlen($linea) >= 4 && $linea[3] === ' ') {
            break;
        }
    }
    if ($respuesta === '') {
        $meta = stream_get_meta_data($fp);
        throw new RuntimeException(!empty($meta['timed_out']) ? 'Tiempo de espera agotado al leer la respuesta SMTP.' : 'El servidor SMTP cerró la conexión sin responder.');
    }
    $codigo = (int)substr($respuesta, 0, 3);
    if (!in_array($codigo, $codigosEsperados, true)) {
        $limpia = trim(preg_replace('/\s+/', ' ', $respuesta));
        throw new RuntimeException("SMTP respondió {$codigo}: {$limpia}");
    }
    return $respuesta;
}

function smtp_comando($fp, string $comando, array $codigosEsperados): string
{
    if (fwrite($fp, $comando . "\r\n") === false) {
        throw new RuntimeException('No fue posible enviar un comando al servidor SMTP.');
    }
    return smtp_leer_respuesta($fp, $codigosEsperados);
}

function smtp_normalizar_direccion(string $correo): string
{
    $correo = trim($correo);
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('La dirección de correo no tiene un formato válido: ' . $correo);
    }
    return $correo;
}

function smtp_probar_y_enviar(array $cfg, string $destino): array
{
    $host = trim((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 0);
    $seguridad = strtoupper(trim((string)($cfg['security'] ?? 'STARTTLS')));
    $aut = !empty($cfg['auth']);
    $usuario = trim((string)($cfg['username'] ?? ''));
    $password = (string)($cfg['password'] ?? '');
    $from = smtp_normalizar_direccion((string)($cfg['from'] ?? ''));
    $fromName = trim((string)($cfg['from_name'] ?? ''));
    $replyTo = trim((string)($cfg['reply_to'] ?? ''));
    $destino = smtp_normalizar_direccion($destino);

    if ($host === '') throw new RuntimeException('Capture el servidor SMTP.');
    if ($port <= 0 || $port > 65535) throw new RuntimeException('El puerto SMTP no es válido.');
    if ($aut && ($usuario === '' || $password === '')) throw new RuntimeException('La autenticación SMTP está activa, pero falta usuario o contraseña.');
    if ($replyTo !== '') smtp_normalizar_direccion($replyTo);

    $transport = ($seguridad === 'SSL') ? 'ssl://' : 'tcp://';
    $errno = 0; $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]
    ]);
    $inicio = microtime(true);
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        throw new RuntimeException('No fue posible conectar con ' . $host . ':' . $port . '. ' . ($errstr ?: 'Revise red, firewall y configuración.'));
    }

    try {
        smtp_leer_respuesta($fp, [220]);
        $ehlo = smtp_comando($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($seguridad === 'STARTTLS') {
            if (stripos($ehlo, 'STARTTLS') === false) {
                throw new RuntimeException('El servidor no anunció soporte STARTTLS.');
            }
            smtp_comando($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No fue posible establecer el canal TLS con el servidor SMTP.');
            }
            $ehlo = smtp_comando($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        if ($aut) {
            if (stripos($ehlo, 'AUTH') === false) {
                throw new RuntimeException('El servidor SMTP no anunció mecanismos de autenticación.');
            }
            smtp_comando($fp, 'AUTH LOGIN', [334]);
            smtp_comando($fp, base64_encode($usuario), [334]);
            smtp_comando($fp, base64_encode($password), [235]);
        }

        smtp_comando($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_comando($fp, 'RCPT TO:<' . $destino . '>', [250, 251]);
        smtp_comando($fp, 'DATA', [354]);

        $nombreSeguro = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?=' : '';
        $asunto = '=?UTF-8?B?' . base64_encode('Prueba de correo - Visor XML Pro') . '?=';
        $headers = [];
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'From: ' . ($nombreSeguro !== '' ? $nombreSeguro . ' ' : '') . '<' . $from . '>';
        $headers[] = 'To: <' . $destino . '>';
        if ($replyTo !== '') $headers[] = 'Reply-To: <' . $replyTo . '>';
        $headers[] = 'Subject: ' . $asunto;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $cuerpo = '<html><body style="font-family:Arial,sans-serif">'
                . '<h3>Prueba de correo correcta</h3>'
                . '<p>Este mensaje fue enviado desde la configuración SMTP de <b>Visor XML Pro</b>.</p>'
                . '<p>Fecha: ' . htmlspecialchars(date('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Servidor: ' . htmlspecialchars($host . ':' . $port, ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>';

        $mensaje = implode("\r\n", $headers) . "\r\n\r\n" . $cuerpo;
        // Dot-stuffing requerido por SMTP.
        $mensaje = preg_replace('/(?m)^\./', '..', $mensaje);
        fwrite($fp, $mensaje . "\r\n.\r\n");
        smtp_leer_respuesta($fp, [250]);
        @smtp_comando($fp, 'QUIT', [221]);

        return [
            'success' => true,
            'elapsed_ms' => (int)round((microtime(true) - $inicio) * 1000),
            'message' => 'Conexión, autenticación y envío SMTP correctos.'
        ];
    } finally {
        if (is_resource($fp)) fclose($fp);
    }
}
