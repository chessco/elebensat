<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/paths.php';

if (!function_exists('metadata_manual_dir')) {
    function metadata_manual_dir(int $idEmpresa): string
    {
        $candidatos = [
            sgksat_private_path('metadata_manual/empresa_' . $idEmpresa),
            rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/SGKSAT/metadata_manual/empresa_' . $idEmpresa,
        ];
        $errores = [];
        foreach ($candidatos as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                $errores[] = $dir . ' (no se pudo crear)';
                continue;
            }
            $prueba = $dir . '/.write_test_' . getmypid();
            if (@file_put_contents($prueba, 'ok') === false) {
                $errores[] = $dir . ' (sin permiso de escritura)';
                continue;
            }
            @unlink($prueba);
            return $dir;
        }
        throw new RuntimeException('No hay una carpeta escribible para metadata manual. Revisar permisos de Apache/PHP. Intentos: ' . implode(' | ', $errores));
    }
}

if (!function_exists('metadata_manual_job_path')) {
    function metadata_manual_job_path(int $idEmpresa, string $jobId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Identificador de proceso no válido.');
        }
        return metadata_manual_dir($idEmpresa) . '/' . $jobId . '.json';
    }
}

if (!function_exists('metadata_manual_txt_path')) {
    function metadata_manual_txt_path(int $idEmpresa, string $jobId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Identificador de proceso no válido.');
        }
        return metadata_manual_dir($idEmpresa) . '/' . $jobId . '.txt';
    }
}

if (!function_exists('metadata_manual_read_job')) {
    function metadata_manual_read_job(int $idEmpresa, string $jobId): array
    {
        $path = metadata_manual_job_path($idEmpresa, $jobId);
        if (!is_file($path)) {
            throw new RuntimeException('No se encontró el proceso de metadata.');
        }
        $raw = file_get_contents($path);
        $job = json_decode((string)$raw, true);
        if (!is_array($job)) {
            throw new RuntimeException('El estado del proceso de metadata no es válido.');
        }
        return $job;
    }
}

if (!function_exists('metadata_manual_write_job')) {
    function metadata_manual_write_job(int $idEmpresa, string $jobId, array $job): void
    {
        $path = metadata_manual_job_path($idEmpresa, $jobId);
        $tmp = $path . '.tmp';
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('No fue posible guardar el avance del proceso.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('No fue posible confirmar el avance del proceso.');
        }
    }
}

if (!function_exists('metadata_manual_normalizar_header')) {
    function metadata_manual_normalizar_header(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = strtolower(trim($value));
        $value = str_replace([' ', '_', '-'], '', $value);
        return $value;
    }
}

if (!function_exists('metadata_manual_header_map')) {
    function metadata_manual_header_map(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $map[metadata_manual_normalizar_header((string)$name)] = (int)$i;
        }
        foreach (['uuid','rfcemisor','rfcreceptor','estatus'] as $required) {
            if (!array_key_exists($required, $map)) {
                throw new RuntimeException('El TXT no tiene el formato de Metadata SAT esperado. Falta la columna ' . $required . '.');
            }
        }
        return $map;
    }
}

if (!function_exists('metadata_manual_value')) {
    function metadata_manual_value(array $row, array $map, string $name): string
    {
        $key = metadata_manual_normalizar_header($name);
        if (!isset($map[$key])) return '';
        return trim((string)($row[$map[$key]] ?? ''));
    }
}

if (!function_exists('metadata_manual_fecha')) {
    function metadata_manual_fecha(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $ts = strtotime($value);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('metadata_manual_uuid')) {
    function metadata_manual_uuid(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/', $value) ? $value : '';
    }
}

if (!function_exists('metadata_manual_estatus')) {
    function metadata_manual_estatus(string $value, ?string $fechaCancelacion = null): string
    {
        $v = strtoupper(trim($value));
        if ($fechaCancelacion !== null || in_array($v, ['0','CANCELADO','CANCELADA'], true)) return 'Cancelado';
        if (in_array($v, ['1','VIGENTE'], true)) return 'Vigente';
        return '';
    }
}

if (!function_exists('metadata_manual_public_job')) {
    function metadata_manual_public_job(array $job): array
    {
        $total = max(1, (int)($job['bytes_total'] ?? 1));
        $done = max(0, min($total, (int)($job['bytes_procesados'] ?? 0)));
        return [
            'job_id' => (string)($job['job_id'] ?? ''),
            'estado' => (string)($job['estado'] ?? 'pendiente'),
            'archivo' => (string)($job['archivo_original'] ?? ''),
            'porcentaje' => round(($done / $total) * 100, 1),
            'leidos' => (int)($job['leidos'] ?? 0),
            'validos' => (int)($job['validos'] ?? 0),
            'encontrados' => (int)($job['encontrados'] ?? 0),
            'cancelados_aplicados' => (int)($job['cancelados_aplicados'] ?? 0),
            'vigentes_aplicados' => (int)($job['vigentes_aplicados'] ?? 0),
            'sin_cambio' => (int)($job['sin_cambio'] ?? 0),
            'no_encontrados' => (int)($job['no_encontrados'] ?? 0),
            'omitidos_empresa' => (int)($job['omitidos_empresa'] ?? 0),
            'errores' => (int)($job['errores'] ?? 0),
            'mensaje' => (string)($job['mensaje'] ?? ''),
            'fecha_inicio' => (string)($job['fecha_inicio'] ?? ''),
            'fecha_fin' => (string)($job['fecha_fin'] ?? ''),
        ];
    }
}
