<?php
/** Motor simple para variables de plantillas de correo. */
declare(strict_types=1);

function correo_variables_permitidas(): array
{
    return ['uuid','fecha_factura','serie','folio_factura','cheque','fecha_cheque','importe','moneda','emisor','rfc_emisor','forma_pago','metodo_pago','correo_proveedor','empresa'];
}

function correo_renderizar_plantilla(string $texto, array $datos, array &$faltantes = []): string
{
    $faltantes = [];
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function($m) use ($datos, &$faltantes) {
        $k = strtolower($m[1]);
        $v = $datos[$k] ?? null;
        if ($v === null || trim((string)$v) === '') {
            $faltantes[] = $k;
            return $m[0];
        }
        return (string)$v;
    }, $texto) ?? $texto;
}

function correo_validar_configuracion_empresa(array $empresa): array
{
    $faltan = [];
    foreach ([
        'smptp_correo' => 'Servidor SMTP',
        'puerto_correo' => 'Puerto SMTP',
        'correo_remitente' => 'Correo remitente',
    ] as $campo => $etiqueta) {
        if (trim((string)($empresa[$campo] ?? '')) === '') $faltan[] = $etiqueta;
    }
    if ((int)($empresa['usa_aut'] ?? 0) === 1) {
        if (trim((string)($empresa['smtp_usuario'] ?? '')) === '') $faltan[] = 'Usuario SMTP';
        if (trim((string)($empresa['smtp_password_enc'] ?? '')) === '') $faltan[] = 'Contraseña SMTP';
    }
    return $faltan;
}
