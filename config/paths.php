<?php
/**
 * SGKSAT - Rutas privadas fuera de htdocs.
 * No contiene credenciales.
 * Se puede cambiar la base con la variable de entorno SGKSAT_BASE_DIR.
 */
declare(strict_types=1);

if (!function_exists('sgksat_base_dir')) {
    function sgksat_base_dir(): string
    {
        $base = getenv('SGKSAT_BASE_DIR');
        if (!is_string($base) || trim($base) === '') {
            $base = 'C:/SGKSAT';
        }
        return rtrim(str_replace('\\', '/', trim($base)), '/');
    }
}

if (!function_exists('sgksat_private_path')) {
    function sgksat_private_path(string $relative = ''): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return sgksat_base_dir() . ($relative !== '' ? '/' . $relative : '');
    }
}
