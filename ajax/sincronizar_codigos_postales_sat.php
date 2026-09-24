<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('memory_limit', '1024M');

// Libera la sesión para permitir que las peticiones de estado/cancelación entren mientras corre la importación.
session_write_close();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales');

const JOB_PREFIX = 'cp_sat_';

function fail(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['status' => 'error', 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function jobsDir(): string {
    $dir = sgksat_private_path('catalogos_sat/jobs');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de control de sincronización.');
    }
    return $dir;
}

function cleanJobId(?string $value): string {
    $value = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
    if ($value === '' || strlen($value) < 12 || strlen($value) > 80) {
        throw new InvalidArgumentException('Identificador de proceso inválido.');
    }
    return $value;
}

function jobPath(string $jobId): string {
    return jobsDir() . '/' . JOB_PREFIX . $jobId . '.json';
}

function readJob(string $jobId): array {
    $path = jobPath($jobId);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function writeJob(string $jobId, array $changes): array {
    $path = jobPath($jobId);
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('No se pudo guardar el avance de la sincronización.');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear el archivo de avance.');
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $current = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($current)) {
            $current = [];
        }
        // Nunca perder una solicitud de cancelación por una actualización concurrente.
        if (!empty($current['cancel_requested'])) {
            $changes['cancel_requested'] = true;
        }
        $data = array_merge($current, $changes, ['updated_at' => date('c')]);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        return $data;
    } finally {
        fclose($fp);
    }
}

function isCancelled(string $jobId): bool {
    return !empty(readJob($jobId)['cancel_requested']);
}

function checkCancelled(string $jobId): void {
    if (isCancelled($jobId)) {
        throw new RuntimeException('__CANCELLED__');
    }
}

function normalDate($v): ?string {
    if ($v === null || trim((string)$v) === '') return null;
    if (is_numeric($v)) {
        $ts = ((float)$v - 25569) * 86400;
        return gmdate('Y-m-d', (int)$ts);
    }
    $v = str_replace('.', '/', trim((string)$v));
    foreach (['d/m/Y', 'Y-m-d', 'm/d/Y'] as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d) return $d->format('Y-m-d');
    }
    return null;
}

function getUrlCatalogo(): ?string {
    $page = 'https://www.sat.gob.mx/minisitio/Factura/emite_quenecesitoparafacturar.htm';
    $ctx = stream_context_create([
        'http' => ['timeout' => 35, 'user_agent' => 'Mozilla/5.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    $html = @file_get_contents($page, false, $ctx);
    if (!$html) return null;
    if (preg_match('~https?://[^"\']+/catCFDI_V_4_[0-9]+\.xls~i', $html, $m)) {
        return html_entity_decode($m[0]);
    }
    if (preg_match('~href=["\']([^"\']*catCFDI_V_4_[0-9]+\.xls)["\']~i', $html, $m)) {
        return str_starts_with($m[1], 'http') ? $m[1] : 'https://www.sat.gob.mx/' . ltrim($m[1], '/');
    }
    return null;
}

function downloadFile(string $url, string $dest, string $jobId): bool {
    $ch = curl_init($url);
    $fh = fopen($dest, 'wb');
    if (!$fh) return false;

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($resource, float $downloadSize, float $downloaded) use ($jobId) {
            if (isCancelled($jobId)) return 1;
            $percent = $downloadSize > 0 ? min(10, (int)round(($downloaded / $downloadSize) * 10)) : 3;
            writeJob($jobId, [
                'status' => 'running',
                'stage' => 'download',
                'percent' => $percent,
                'message' => 'Descargando el catálogo oficial del SAT…',
                'detail' => $downloadSize > 0
                    ? number_format($downloaded / 1048576, 1) . ' de ' . number_format($downloadSize / 1048576, 1) . ' MB'
                    : number_format($downloaded / 1048576, 1) . ' MB descargados'
            ]);
            return 0;
        }
    ]);

    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($errno === CURLE_ABORTED_BY_CALLBACK && isCancelled($jobId)) {
        throw new RuntimeException('__CANCELLED__');
    }
    return $ok && $http >= 200 && $http < 300 && is_file($dest) && filesize($dest) > 10000;
}

function quoteSqlValue(PDO $pdo, $value): string {
    if ($value === null || $value === '') return 'NULL';
    return $pdo->quote((string)$value);
}

function normalizeRangeRows($values, int $rowCount, int $columnCount = 8): array {
    if ($rowCount <= 0) return [];

    // Cuando Excel devuelve un valor escalar (solo una celda).
    if (!is_array($values) && !is_object($values)) {
        return $rowCount === 1 ? [[$values]] : [];
    }

    // En algunas versiones de PHP/COM, Value2 llega como arreglo PHP normal.
    if (is_array($values)) {
        $first = reset($values);
        if ($rowCount === 1 && !is_array($first)) {
            return [array_values($values)];
        }
        $rows = [];
        foreach ($values as $row) {
            if (is_array($row)) {
                $rows[] = array_values($row);
            } elseif (is_object($row) && $row instanceof Traversable) {
                $rows[] = iterator_to_array($row, false);
            } else {
                $rows[] = [$row];
            }
        }
        return $rows;
    }

    /*
     * PHP 8 suele entregar Range->Value2 como com_safearray_proxy, no como array.
     * El proxy se consulta con índices 1-based: $values[$fila][$columna].
     * La llamada COM grande ya ocurrió al obtener Value2; estos accesos recorren
     * el SAFEARRAY en memoria y evitan volver a consultar Excel celda por celda.
     */
    $rows = [];
    for ($r = 1; $r <= $rowCount; $r++) {
        $row = [];
        for ($c = 1; $c <= $columnCount; $c++) {
            $cell = null;
            try {
                $cell = $values[$r][$c];
            } catch (Throwable $e1) {
                // Compatibilidad con proveedores COM que exponen índices 0-based.
                try {
                    $cell = $values[$r - 1][$c - 1];
                } catch (Throwable $e2) {
                    $cell = null;
                }
            }
            $row[] = $cell;
        }
        $rows[] = $row;
    }
    return $rows;
}

function executeUpsertBlock(PDO $pdo, array $rows, string $revision, ?string $pub, string $name): int {
    if (!$rows) return 0;

    $values = [];
    foreach ($rows as $r) {
        $values[] = '(' . implode(',', [
            quoteSqlValue($pdo, $r[0]),
            quoteSqlValue($pdo, $r[1] ?: null),
            quoteSqlValue($pdo, $r[2] ?: null),
            quoteSqlValue($pdo, $r[3] ?: null),
            (string)(int)$r[4],
            quoteSqlValue($pdo, normalDate($r[5])),
            quoteSqlValue($pdo, normalDate($r[6])),
            quoteSqlValue($pdo, $r[7] ?: null),
            quoteSqlValue($pdo, $revision ?: null),
            quoteSqlValue($pdo, $pub),
            quoteSqlValue($pdo, $name),
            'NOW()'
        ]) . ')';
    }

    $sql = 'INSERT INTO sat_codigos_postales
        (codigo_postal,estado,municipio,localidad,estimulo_franja_fronteriza,fecha_inicio_vigencia,fecha_fin_vigencia,descripcion_huso_horario,revision_catalogo,fecha_publicacion,archivo_origen,fecha_sincronizacion)
        VALUES ' . implode(',', $values) . '
        ON DUPLICATE KEY UPDATE
        estado=VALUES(estado),municipio=VALUES(municipio),localidad=VALUES(localidad),
        estimulo_franja_fronteriza=VALUES(estimulo_franja_fronteriza),
        fecha_inicio_vigencia=VALUES(fecha_inicio_vigencia),fecha_fin_vigencia=VALUES(fecha_fin_vigencia),
        descripcion_huso_horario=VALUES(descripcion_huso_horario),revision_catalogo=VALUES(revision_catalogo),
        fecha_publicacion=VALUES(fecha_publicacion),archivo_origen=VALUES(archivo_origen),fecha_sincronizacion=NOW()';

    $pdo->exec($sql);
    return count($rows);
}

function importExcelByBlocks(string $file, string $jobId, PDO $pdo, string $name): array {
    if (!class_exists('COM')) {
        throw new RuntimeException('La extensión COM de PHP no está habilitada. Active extension=php_com_dotnet.dll en php.ini y reinicie Apache.');
    }

    writeJob($jobId, [
        'status' => 'running', 'stage' => 'excel', 'percent' => 10,
        'message' => 'Abriendo el archivo de Excel…', 'detail' => 'Preparando lectura e importación por bloques'
    ]);

    $excel = new COM('Excel.Application');
    $excel->Visible = false;
    $excel->DisplayAlerts = false;
    $book = null;
    $blockSize = 1000;

    try {
        checkCancelled($jobId);
        $book = $excel->Workbooks->Open(realpath($file), 0, true);
        $sheetNames = ['c_CodigoPostal_Parte_1', 'c_CodigoPostal_Parte_2'];
        $sheetTotals = [];
        $totalRows = 0;

        foreach ($sheetNames as $sheetName) {
            $sheet = $book->Worksheets($sheetName);
            $last = max(7, (int)$sheet->UsedRange->Rows->Count);
            $sheetTotals[$sheetName] = $last;
            $totalRows += max(0, $last - 7);
            unset($sheet);
        }

        $meta = $book->Worksheets('c_CodigoPostal_Parte_1');
        $revision = trim((string)$meta->Cells(3, 3)->Text);
        $publicacion = normalDate($meta->Cells(3, 4)->Value);
        unset($meta);

        $pdo->beginTransaction();
        $processed = 0;
        $valid = 0;
        $blocks = 0;

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            checkCancelled($jobId);
            $sheet = $book->Worksheets($sheetName);
            $last = $sheetTotals[$sheetName];
            $sheetRows = max(0, $last - 7);
            $sheetProcessed = 0;

            for ($startRow = 8; $startRow <= $last; $startRow += $blockSize) {
                checkCancelled($jobId);
                $endRow = min($last, $startRow + $blockSize - 1);
                $countRows = $endRow - $startRow + 1;

                // Una sola llamada COM por bloque, en vez de 8 llamadas por cada fila.
                $range = $sheet->Range("A{$startRow}:H{$endRow}");
                $values = $range->Value2;
                unset($range);
                $matrix = normalizeRangeRows($values, $countRows, 8);
                unset($values);

                $upsertRows = [];
                foreach ($matrix as $row) {
                    $row = array_pad($row, 8, null);
                    $cp = trim((string)$row[0]);
                    if (!preg_match('/^\d{5}$/', $cp)) continue;
                    $upsertRows[] = [
                        $cp,
                        trim((string)$row[1]),
                        trim((string)$row[2]),
                        trim((string)$row[3]),
                        trim((string)$row[4]),
                        $row[5],
                        $row[6],
                        trim((string)$row[7])
                    ];
                }

                checkCancelled($jobId);
                $valid += executeUpsertBlock($pdo, $upsertRows, $revision, $publicacion, $name);
                $blocks++;
                $processed += $countRows;
                $sheetProcessed += $countRows;

                writeJob($jobId, [
                    'status' => 'running', 'stage' => 'database',
                    'sheet' => $sheetIndex + 1, 'sheets_total' => 2,
                    'sheet_rows' => $sheetRows, 'sheet_processed' => min($sheetRows, $sheetProcessed),
                    'processed' => min($totalRows, $processed), 'total' => $totalRows,
                    'blocks' => $blocks, 'block_size' => $blockSize,
                    'percent' => 10 + (int)floor((min($totalRows, $processed) / max(1, $totalRows)) * 89),
                    'message' => 'Leyendo e importando hoja ' . ($sheetIndex + 1) . ' de 2 por bloques',
                    'detail' => number_format($valid) . ' códigos válidos · bloque ' . number_format($blocks)
                ]);
                unset($matrix, $upsertRows);
            }
            unset($sheet);
        }

        checkCancelled($jobId);
        $book->Close(false);
        $excel->Quit();
        return [$valid, $revision, $publicacion, $blocks, $pdo];
    } catch (Throwable $e) {
        try { if ($book) $book->Close(false); } catch (Throwable $x) {}
        try { $excel->Quit(); } catch (Throwable $x) {}
        throw $e;
    }
}

$action = $_POST['action'] ?? 'start';
try {
    $jobId = cleanJobId($_POST['job_id'] ?? '');
} catch (Throwable $e) {
    fail($e->getMessage(), 400);
}

if ($action === 'status') {
    $job = readJob($jobId);
    if (!$job) fail('No se encontró el proceso solicitado.', 404);
    echo json_encode(['status' => 'ok', 'job' => $job], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel') {
    $job = writeJob($jobId, [
        'cancel_requested' => true,
        'message' => 'Cancelando el proceso…',
        'detail' => 'Espere mientras se detiene de forma segura.'
    ]);
    echo json_encode(['status' => 'ok', 'msg' => 'Se solicitó la cancelación.', 'job' => $job], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'start') fail('Acción no válida.', 400);

$inicio = date('Y-m-d H:i:s');
$url = null;
$file = null;
$name = null;
writeJob($jobId, [
    'status' => 'running', 'stage' => 'starting', 'percent' => 0,
    'message' => 'Iniciando sincronización…', 'detail' => 'Preparando el proceso',
    'cancel_requested' => false, 'started_at' => date('c')
]);

try {
    checkCancelled($jobId);
    if (!empty($_FILES['archivo_excel']['tmp_name'])) {
        $file = $_FILES['archivo_excel']['tmp_name'];
        $name = basename($_FILES['archivo_excel']['name']);
        writeJob($jobId, [
            'stage' => 'upload', 'percent' => 10,
            'message' => 'Archivo recibido', 'detail' => 'Comenzando lectura del Excel'
        ]);
    } else {
        writeJob($jobId, [
            'stage' => 'locating', 'percent' => 1,
            'message' => 'Buscando la versión vigente en el SAT…', 'detail' => ''
        ]);
        $url = getUrlCatalogo();
        checkCancelled($jobId);
        if (!$url) {
            throw new RuntimeException('No fue posible localizar la liga vigente del catálogo en el portal del SAT. Puede cargar el Excel manualmente.');
        }
        $dir = sgksat_private_path('catalogos_sat');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de descarga.');
        }
        $name = basename(parse_url($url, PHP_URL_PATH));
        $file = $dir . '/' . $name;
        if (!downloadFile($url, $file, $jobId)) {
            throw new RuntimeException('El SAT no respondió o no entregó un archivo válido. El catálogo anterior se conservó sin cambios.');
        }
    }

    checkCancelled($jobId);
    [$count, $revision, $pub, $blocks] = importExcelByBlocks($file, $jobId, $pdo, $name);
    if (!$count) throw new RuntimeException('No se encontraron registros válidos en las hojas c_CodigoPostal_Parte_1 y Parte_2.');

    checkCancelled($jobId);
    $pdo->prepare("INSERT INTO sat_catalogos_bitacora
        (tipo_catalogo,archivo,url_origen,revision_catalogo,fecha_publicacion,registros_leidos,estatus,mensaje,fecha_inicio,fecha_fin)
        VALUES('c_CodigoPostal',?,?,?,?,?,'OK','Sincronización completada',?,NOW())")
        ->execute([$name, $url, $revision, $pub, $count, $inicio]);
    $pdo->commit();

    writeJob($jobId, [
        'status' => 'completed', 'stage' => 'completed', 'percent' => 100,
        'message' => 'Sincronización terminada',
        'detail' => number_format($count) . ' códigos postales procesados en ' . number_format($blocks) . ' bloques',
        'processed' => $count, 'total' => $count, 'finished_at' => date('c')
    ]);
    echo json_encode([
        'status' => 'ok',
        'msg' => "Catálogo actualizado correctamente: $count códigos postales procesados en $blocks bloques.",
        'registros' => $count,
        'revision' => $revision,
        'bloques' => $blocks,
        'job_id' => $jobId
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $cancelled = $e->getMessage() === '__CANCELLED__';
    $message = $cancelled
        ? 'La sincronización fue cancelada. No se aplicaron cambios incompletos.'
        : $e->getMessage();

    try {
        $pdo->prepare("INSERT INTO sat_catalogos_bitacora
            (tipo_catalogo,archivo,url_origen,estatus,mensaje,fecha_inicio,fecha_fin)
            VALUES('c_CodigoPostal',?,?,'" . ($cancelled ? "CANCELADO" : "ERROR") . "',?,?,NOW())")
            ->execute([$name, $url, $message, $inicio]);
    } catch (Throwable $x) {}

    writeJob($jobId, [
        'status' => $cancelled ? 'cancelled' : 'error',
        'stage' => $cancelled ? 'cancelled' : 'error',
        'message' => $cancelled ? 'Proceso cancelado' : 'No se pudo sincronizar',
        'detail' => $message,
        'finished_at' => date('c')
    ]);

    fail($message, $cancelled ? 409 : 500, ['cancelled' => $cancelled, 'job_id' => $jobId]);
}
