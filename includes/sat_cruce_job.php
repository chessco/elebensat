<?php
/**
 * Estado y cancelación de cruces SAT 69 / 69-B.
 * Usa archivos temporales para no requerir tablas ni privilegios CREATE.
 */

function sat_cruce_job_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gkvisor_sat_cruce_jobs';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta temporal para controlar el cruce SAT.');
    }
    return $dir;
}

function sat_cruce_job_id_limpio(string $jobId): string
{
    $jobId = preg_replace('/[^A-Za-z0-9_-]/', '', $jobId);
    if ($jobId === '' || strlen($jobId) > 80) {
        throw new InvalidArgumentException('Identificador de proceso inválido.');
    }
    return $jobId;
}

function sat_cruce_job_path(string $jobId): string
{
    return sat_cruce_job_dir() . DIRECTORY_SEPARATOR . sat_cruce_job_id_limpio($jobId) . '.json';
}

function sat_cruce_job_leer(string $jobId): array
{
    $path = sat_cruce_job_path($jobId);
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sat_cruce_job_guardar(string $jobId, array $data): void
{
    $path = sat_cruce_job_path($jobId);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $data['job_id'] = sat_cruce_job_id_limpio($jobId);
    $data['actualizado'] = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el estado temporal del cruce SAT.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo actualizar el estado temporal del cruce SAT.');
    }
}

function sat_cruce_job_iniciar(string $jobId, string $tipo, int $total): void
{
    sat_cruce_job_guardar($jobId, [
        'tipo' => $tipo,
        'estado' => 'procesando',
        'total' => max(0, $total),
        'procesados' => 0,
        'porcentaje' => 0,
        'cancelar' => false,
        'mensaje' => 'Preparando cruce de emisores...',
        'inicio' => date('Y-m-d H:i:s'),
    ]);
}

function sat_cruce_job_actualizar(string $jobId, int $procesados, int $total, string $mensaje = ''): void
{
    $data = sat_cruce_job_leer($jobId);
    if (!$data) return;
    $total = max(0, $total);
    $procesados = max(0, min($procesados, $total ?: $procesados));
    $data['procesados'] = $procesados;
    $data['total'] = $total;
    $data['porcentaje'] = $total > 0 ? min(100, (int)floor(($procesados * 100) / $total)) : 100;
    if ($mensaje !== '') $data['mensaje'] = $mensaje;
    sat_cruce_job_guardar($jobId, $data);
}

function sat_cruce_job_cancelar(string $jobId): void
{
    $data = sat_cruce_job_leer($jobId);
    if (!$data) {
        $data = [
            'estado' => 'cancelando',
            'total' => 0,
            'procesados' => 0,
            'porcentaje' => 0,
        ];
    }
    $data['cancelar'] = true;
    $data['estado'] = 'cancelando';
    $data['mensaje'] = 'Cancelando de forma segura...';
    sat_cruce_job_guardar($jobId, $data);
}

function sat_cruce_job_cancelacion_solicitada(string $jobId): bool
{
    $data = sat_cruce_job_leer($jobId);
    return !empty($data['cancelar']);
}

function sat_cruce_job_finalizar(string $jobId, string $estado, string $mensaje, array $extra = []): void
{
    $data = sat_cruce_job_leer($jobId);
    if (!$data) $data = [];
    $data = array_merge($data, $extra);
    $data['estado'] = $estado;
    $data['mensaje'] = $mensaje;
    if ($estado === 'terminado') {
        $data['porcentaje'] = 100;
        if (isset($data['total'])) $data['procesados'] = (int)$data['total'];
    }
    $data['fin'] = date('Y-m-d H:i:s');
    sat_cruce_job_guardar($jobId, $data);
}

class SatCruceCanceladoException extends RuntimeException {}
