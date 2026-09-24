<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/metadata_manual.php';

try {
    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');

    if ($idUsuario <= 0 || $idEmpresa <= 0) throw new RuntimeException('Sesión no válida.');
    if (empty($_FILES['archivo_metadata']) || !is_array($_FILES['archivo_metadata'])) {
        throw new RuntimeException('Seleccione el archivo TXT de metadata del SAT.');
    }

    $f = $_FILES['archivo_metadata'];
    $uploadError = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $erroresUpload = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede upload_max_filesize de PHP (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño permitido por el formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo llegó incompleto al servidor.',
            UPLOAD_ERR_NO_FILE    => 'No se recibió ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP no tiene carpeta temporal configurada para cargas (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'PHP no pudo escribir el archivo temporal en disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la carga del archivo.',
        ];
        throw new RuntimeException(($erroresUpload[$uploadError] ?? 'Error de carga PHP código ' . $uploadError . '.') . ' Código=' . $uploadError);
    }
    $nombre = basename((string)($f['name'] ?? 'metadata.txt'));
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'txt') {
        throw new RuntimeException('El archivo debe estar desempacado y tener extensión .txt.');
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('El archivo está vacío.');
    if ($size > 250 * 1024 * 1024) throw new RuntimeException('El archivo excede el máximo permitido de 250 MB.');

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('PHP reportó la carga como correcta, pero el archivo temporal no está disponible. tmp_name=' . ($tmp !== '' ? basename($tmp) : '(vacío)'));
    }
    $fh = @fopen($tmp, 'rb');
    if (!$fh) throw new RuntimeException('No fue posible abrir el archivo recibido.');
    $header = fgetcsv($fh, 0, '~', '"', '\\');
    fclose($fh);
    if (!is_array($header)) throw new RuntimeException('No fue posible leer el encabezado del TXT.');
    metadata_manual_header_map($header);

    $st = $pdo->prepare('SELECT UPPER(TRIM(rfc)) FROM empresas WHERE id_empresa=? LIMIT 1');
    $st->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$st->fetchColumn()));
    if ($rfcEmpresa === '') throw new RuntimeException('La empresa activa no tiene RFC configurado.');

    $jobId = bin2hex(random_bytes(16));
    $dest = metadata_manual_txt_path($idEmpresa, $jobId);
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('No fue posible guardar el archivo en la carpeta privada de SGKSAT.');
    }

    $job = [
        'job_id' => $jobId,
        'id_empresa' => $idEmpresa,
        'id_usuario' => $idUsuario,
        'rfc_empresa' => $rfcEmpresa,
        'archivo_original' => $nombre,
        'ruta' => $dest,
        'estado' => 'procesando',
        'cancelar' => false,
        'offset' => 0,
        'header_leido' => false,
        'bytes_total' => (int)filesize($dest),
        'bytes_procesados' => 0,
        'leidos' => 0,
        'validos' => 0,
        'encontrados' => 0,
        'cancelados_aplicados' => 0,
        'vigentes_aplicados' => 0,
        'sin_cambio' => 0,
        'no_encontrados' => 0,
        'omitidos_empresa' => 0,
        'errores' => 0,
        'mensaje' => 'Procesando metadata...',
        'fecha_inicio' => date('Y-m-d H:i:s'),
        'fecha_fin' => '',
    ];
    metadata_manual_write_job($idEmpresa, $jobId, $job);

    echo json_encode(['success'=>true, 'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
