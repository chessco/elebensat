<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);


$idUsuario = (int)$_SESSION['id_usuario'];
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69b');

// Libera el bloqueo de la sesión PHP para que estado/cancelar puedan ejecutarse
// mientras la sincronización larga sigue trabajando.
session_write_close();

const SAT_69B_URL = 'https://wu1agsprosta001.blob.core.windows.net/agsc-publicaciones/Datos_abiertos/Documents_AGAFF/Listado_completo_69-B.csv';

function responder(array $data, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function crearTablas69B(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sat_69b_contribuyentes (
        id_69b int(11) NOT NULL AUTO_INCREMENT,
        numero_sat int(11) DEFAULT NULL,
        rfc varchar(20) NOT NULL,
        nombre_contribuyente varchar(255) NOT NULL,
        situacion varchar(50) NOT NULL,
        oficio_presuncion_sat text DEFAULT NULL,
        fecha_sat_presuntos varchar(100) DEFAULT NULL,
        oficio_presuncion_dof text DEFAULT NULL,
        fecha_dof_presuntos varchar(100) DEFAULT NULL,
        oficio_desvirtuado_sat text DEFAULT NULL,
        fecha_sat_desvirtuados varchar(100) DEFAULT NULL,
        oficio_desvirtuado_dof text DEFAULT NULL,
        fecha_dof_desvirtuados varchar(100) DEFAULT NULL,
        oficio_definitivo_sat text DEFAULT NULL,
        fecha_sat_definitivos varchar(100) DEFAULT NULL,
        oficio_definitivo_dof text DEFAULT NULL,
        fecha_dof_definitivos varchar(100) DEFAULT NULL,
        oficio_sentencia_sat text DEFAULT NULL,
        fecha_sat_sentencia varchar(100) DEFAULT NULL,
        oficio_sentencia_dof text DEFAULT NULL,
        fecha_dof_sentencia varchar(100) DEFAULT NULL,
        fecha_sincronizacion datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id_69b),
        KEY idx_sat_69b_rfc (rfc),
        KEY idx_sat_69b_situacion (situacion),
        KEY idx_sat_69b_nombre (nombre_contribuyente),
        KEY idx_sat_69b_numero (numero_sat)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sat_69b_control (
        id_control tinyint(1) NOT NULL,
        fuente_url varchar(500) NOT NULL,
        leyenda_actualizacion varchar(255) DEFAULT NULL,
        fecha_sincronizacion datetime NOT NULL,
        total_registros int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id_control)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sat_69b_procesos (
        id_proceso varchar(64) NOT NULL,
        id_usuario int(11) NOT NULL,
        estado varchar(20) NOT NULL DEFAULT 'pendiente',
        etapa varchar(80) NOT NULL DEFAULT 'Preparando',
        porcentaje tinyint(3) unsigned NOT NULL DEFAULT 0,
        registros_procesados int(11) NOT NULL DEFAULT 0,
        total_registros int(11) NOT NULL DEFAULT 0,
        cancelar tinyint(1) NOT NULL DEFAULT 0,
        mensaje varchar(500) DEFAULT NULL,
        fecha_inicio datetime NOT NULL DEFAULT current_timestamp(),
        fecha_actualizacion datetime NOT NULL DEFAULT current_timestamp(),
        fecha_fin datetime DEFAULT NULL,
        PRIMARY KEY (id_proceso),
        KEY idx_sat_69b_proc_usuario (id_usuario),
        KEY idx_sat_69b_proc_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sat_69b_staging (
        id_staging bigint(20) NOT NULL AUTO_INCREMENT,
        id_proceso varchar(64) NOT NULL,
        numero_sat int(11) DEFAULT NULL,
        rfc varchar(20) NOT NULL,
        nombre_contribuyente varchar(255) NOT NULL,
        situacion varchar(50) NOT NULL,
        oficio_presuncion_sat text DEFAULT NULL,
        fecha_sat_presuntos varchar(100) DEFAULT NULL,
        oficio_presuncion_dof text DEFAULT NULL,
        fecha_dof_presuntos varchar(100) DEFAULT NULL,
        oficio_desvirtuado_sat text DEFAULT NULL,
        fecha_sat_desvirtuados varchar(100) DEFAULT NULL,
        oficio_desvirtuado_dof text DEFAULT NULL,
        fecha_dof_desvirtuados varchar(100) DEFAULT NULL,
        oficio_definitivo_sat text DEFAULT NULL,
        fecha_sat_definitivos varchar(100) DEFAULT NULL,
        oficio_definitivo_dof text DEFAULT NULL,
        fecha_dof_definitivos varchar(100) DEFAULT NULL,
        oficio_sentencia_sat text DEFAULT NULL,
        fecha_sat_sentencia varchar(100) DEFAULT NULL,
        oficio_sentencia_dof text DEFAULT NULL,
        fecha_dof_sentencia varchar(100) DEFAULT NULL,
        PRIMARY KEY (id_staging),
        KEY idx_sat_69b_staging_proceso (id_proceso),
        KEY idx_sat_69b_staging_rfc (rfc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function actualizarProceso(PDO $pdo, string $id, string $estado, string $etapa, int $porcentaje, int $procesados = 0, int $total = 0, ?string $mensaje = null, bool $fin = false): void
{
    $sql = "UPDATE sat_69b_procesos
            SET estado=:estado, etapa=:etapa, porcentaje=:porcentaje,
                registros_procesados=:procesados, total_registros=:total,
                mensaje=:mensaje, fecha_actualizacion=NOW()" . ($fin ? ", fecha_fin=NOW()" : "") . "
            WHERE id_proceso=:id";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':estado' => $estado,
        ':etapa' => $etapa,
        ':porcentaje' => max(0, min(100, $porcentaje)),
        ':procesados' => max(0, $procesados),
        ':total' => max(0, $total),
        ':mensaje' => $mensaje,
        ':id' => $id,
    ]);
}

function estaCancelado(PDO $pdo, string $id): bool
{
    $st = $pdo->prepare('SELECT cancelar FROM sat_69b_procesos WHERE id_proceso=?');
    $st->execute([$id]);
    return (int)$st->fetchColumn() === 1;
}

function marcarCancelado(PDO $pdo, string $id, int $procesados = 0, int $total = 0): void
{
    actualizarProceso($pdo, $id, 'cancelado', 'Cancelado por el usuario', 0, $procesados, $total, 'Proceso cancelado. El catálogo anterior se conservó sin cambios.', true);
    $st = $pdo->prepare('DELETE FROM sat_69b_staging WHERE id_proceso=?');
    $st->execute([$id]);
}

function descargarArchivo(PDO $pdo, string $idProceso, string $url, string $destino): void
{
    actualizarProceso($pdo, $idProceso, 'ejecutando', 'Descargando archivo del SAT', 8, 0, 0, 'Conectando con el SAT...');
    $fp = fopen($destino, 'wb');
    if (!$fp) {
        throw new RuntimeException('No fue posible crear el archivo temporal.');
    }

    if (function_exists('curl_init')) {
        $ultimoPct = -1;
        $ultimaConsulta = 0.0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'SGKSAT-VisorXMLPro/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($pdo, $idProceso, &$ultimoPct, &$ultimaConsulta) {
                $ahora = microtime(true);
                if (($ahora - $ultimaConsulta) >= 0.35) {
                    $ultimaConsulta = $ahora;
                    if (estaCancelado($pdo, $idProceso)) {
                        return 1;
                    }
                }
                if ($downloadSize > 0) {
                    $rel = min(1, $downloaded / $downloadSize);
                    $pct = 8 + (int)floor($rel * 27); // 8..35
                    if ($pct !== $ultimoPct) {
                        $ultimoPct = $pct;
                        actualizarProceso($pdo, $idProceso, 'ejecutando', 'Descargando archivo del SAT', $pct, (int)$downloaded, (int)$downloadSize, 'Descargando listado oficial...');
                    }
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (estaCancelado($pdo, $idProceso)) {
            @unlink($destino);
            throw new RuntimeException('__CANCELADO__');
        }
        if (!$ok || $http < 200 || $http >= 300) {
            @unlink($destino);
            throw new RuntimeException('El SAT no respondió correctamente' . ($http ? " (HTTP $http)" : '') . ($err ? ": $err" : '.'));
        }
    } else {
        fclose($fp);
        $ctx = stream_context_create(['http' => ['timeout' => 180, 'user_agent' => 'SGKSAT-VisorXMLPro/1.0']]);
        $datos = @file_get_contents($url, false, $ctx);
        if (estaCancelado($pdo, $idProceso)) {
            @unlink($destino);
            throw new RuntimeException('__CANCELADO__');
        }
        if ($datos === false || strlen($datos) < 100) {
            @unlink($destino);
            throw new RuntimeException('No fue posible descargar el archivo del SAT.');
        }
        file_put_contents($destino, $datos);
    }

    if (!is_file($destino) || filesize($destino) < 100) {
        @unlink($destino);
        throw new RuntimeException('El archivo descargado del SAT está vacío o incompleto.');
    }
    actualizarProceso($pdo, $idProceso, 'ejecutando', 'Archivo descargado', 35, 0, 0, 'Descarga terminada. Leyendo CSV...');
}

function aUtf8($valor): string
{
    $valor = (string)$valor;
    if ($valor === '') return '';
    if (function_exists('mb_check_encoding') && !mb_check_encoding($valor, 'UTF-8')) {
        $valor = mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $valor);
}

function limpiarValor($valor): ?string
{
    $valor = trim(aUtf8($valor));
    return $valor === '' ? null : $valor;
}

function ejecutarSincronizacion(PDO $pdo, string $idProceso): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'sat69b_');
    if ($tmp === false) throw new RuntimeException('No se pudo crear archivo temporal.');

    try {
        $pdo->prepare('DELETE FROM sat_69b_staging WHERE id_proceso=?')->execute([$idProceso]);
        descargarArchivo($pdo, $idProceso, SAT_69B_URL, $tmp);

        if (estaCancelado($pdo, $idProceso)) throw new RuntimeException('__CANCELADO__');

        $fh = fopen($tmp, 'rb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el archivo descargado.');
        $tamArchivo = max(1, (int)filesize($tmp));
        $leyenda = '';
        $headerEncontrado = false;
        $linea = 0;
        $insertados = 0;
        $ultimoPct = -1;

        $sqlStage = "INSERT INTO sat_69b_staging (
            id_proceso, numero_sat, rfc, nombre_contribuyente, situacion,
            oficio_presuncion_sat, fecha_sat_presuntos, oficio_presuncion_dof, fecha_dof_presuntos,
            oficio_desvirtuado_sat, fecha_sat_desvirtuados, oficio_desvirtuado_dof, fecha_dof_desvirtuados,
            oficio_definitivo_sat, fecha_sat_definitivos, oficio_definitivo_dof, fecha_dof_definitivos,
            oficio_sentencia_sat, fecha_sat_sentencia, oficio_sentencia_dof, fecha_dof_sentencia
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $insStage = $pdo->prepare($sqlStage);

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $linea++;
            if (!$row) continue;
            $primero = trim(aUtf8($row[0] ?? ''));
            if ($linea === 1) $leyenda = $primero;

            if (!$headerEncontrado) {
                if (strcasecmp($primero, 'No') === 0 && isset($row[1]) && strtoupper(trim((string)$row[1])) === 'RFC') {
                    $headerEncontrado = true;
                }
                continue;
            }

            $numero = filter_var(trim((string)($row[0] ?? '')), FILTER_VALIDATE_INT);
            $rfc = strtoupper(trim(aUtf8($row[1] ?? '')));
            $rfc = preg_replace('/\s+/', '', $rfc);
            $nombre = limpiarValor($row[2] ?? null);
            $situacion = limpiarValor($row[3] ?? null);
            if ($rfc === '' || $nombre === null || $situacion === null) continue;

            $registro = [$idProceso, $numero === false ? null : (int)$numero, $rfc, $nombre, $situacion];
            for ($i = 4; $i <= 19; $i++) $registro[] = limpiarValor($row[$i] ?? null);
            $insStage->execute($registro);
            $insertados++;

            if (($insertados % 75) === 0) {
                if (estaCancelado($pdo, $idProceso)) {
                    fclose($fh);
                    throw new RuntimeException('__CANCELADO__');
                }
                $pos = max(0, (int)ftell($fh));
                $pct = 35 + (int)floor(min(1, $pos / $tamArchivo) * 45); // 35..80
                if ($pct !== $ultimoPct) {
                    $ultimoPct = $pct;
                    actualizarProceso($pdo, $idProceso, 'ejecutando', 'Leyendo y preparando registros', $pct, $insertados, 0, number_format($insertados) . ' registros preparados');
                }
            }
        }
        fclose($fh);

        if (!$headerEncontrado) throw new RuntimeException('El formato del archivo del SAT cambió: no se encontró el encabezado esperado.');
        if ($insertados < 100) throw new RuntimeException('El archivo del SAT contiene muy pocos registros; no se reemplazó el catálogo actual.');
        if (estaCancelado($pdo, $idProceso)) throw new RuntimeException('__CANCELADO__');

        actualizarProceso($pdo, $idProceso, 'ejecutando', 'Validando información', 84, $insertados, $insertados, 'Archivo validado. Preparando actualización final...');

        // El catálogo visible solo se toca al final, dentro de una transacción.
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM sat_69b_contribuyentes');
            $copiar = $pdo->prepare("INSERT INTO sat_69b_contribuyentes (
                numero_sat, rfc, nombre_contribuyente, situacion,
                oficio_presuncion_sat, fecha_sat_presuntos, oficio_presuncion_dof, fecha_dof_presuntos,
                oficio_desvirtuado_sat, fecha_sat_desvirtuados, oficio_desvirtuado_dof, fecha_dof_desvirtuados,
                oficio_definitivo_sat, fecha_sat_definitivos, oficio_definitivo_dof, fecha_dof_definitivos,
                oficio_sentencia_sat, fecha_sat_sentencia, oficio_sentencia_dof, fecha_dof_sentencia, fecha_sincronizacion
            ) SELECT numero_sat, rfc, nombre_contribuyente, situacion,
                oficio_presuncion_sat, fecha_sat_presuntos, oficio_presuncion_dof, fecha_dof_presuntos,
                oficio_desvirtuado_sat, fecha_sat_desvirtuados, oficio_desvirtuado_dof, fecha_dof_desvirtuados,
                oficio_definitivo_sat, fecha_sat_definitivos, oficio_definitivo_dof, fecha_dof_definitivos,
                oficio_sentencia_sat, fecha_sat_sentencia, oficio_sentencia_dof, fecha_dof_sentencia, NOW()
              FROM sat_69b_staging WHERE id_proceso=? ORDER BY id_staging");
            $copiar->execute([$idProceso]);

            $ctl = $pdo->prepare("INSERT INTO sat_69b_control
                (id_control, fuente_url, leyenda_actualizacion, fecha_sincronizacion, total_registros)
                VALUES (1, :url, :leyenda, NOW(), :total)
                ON DUPLICATE KEY UPDATE fuente_url=VALUES(fuente_url), leyenda_actualizacion=VALUES(leyenda_actualizacion),
                                        fecha_sincronizacion=VALUES(fecha_sincronizacion), total_registros=VALUES(total_registros)");
            $ctl->execute([
                ':url' => SAT_69B_URL,
                ':leyenda' => function_exists('mb_substr') ? mb_substr($leyenda, 0, 255) : substr($leyenda, 0, 255),
                ':total' => $insertados,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        actualizarProceso($pdo, $idProceso, 'ejecutando', 'Limpiando temporales', 96, $insertados, $insertados, 'Catálogo actualizado. Finalizando...');
        $pdo->prepare('DELETE FROM sat_69b_staging WHERE id_proceso=?')->execute([$idProceso]);

        // Al terminar el catálogo oficial, cruza automáticamente los RFC contra los emisores locales.
        $mensajeCruce = '';
        try {
            require_once __DIR__ . '/../includes/sat_69b_alertas.php';
            $cruce = sat69b_cruzar_emisores($pdo);
            $mensajeCruce = ' · Emisores coincidentes: ' . number_format((int)$cruce['total_emisores']) . ' (a revisar: ' . number_format((int)$cruce['emisores_riesgo']) . ')';
        } catch (Throwable $eCruce) {
            // No invalida la descarga oficial si solamente falla el cruce; queda disponible el botón manual para reintentar.
            seguridad_log_error($eCruce, 'sincronizar_sat_69b_cruce_emisores');
            $mensajeCruce = ' · El catálogo quedó actualizado, pero el cruce de emisores requiere reintento manual.';
        }

        actualizarProceso($pdo, $idProceso, 'completado', 'Sincronización terminada', 100, $insertados, $insertados, 'Listado 69-B sincronizado correctamente. Registros: ' . number_format($insertados) . $mensajeCruce, true);
    } finally {
        @unlink($tmp);
    }
}

try {
    crearTablas69B($pdo);
    $accion = strtolower(trim((string)($_POST['accion'] ?? $_GET['accion'] ?? 'iniciar')));

    if ($accion === 'iniciar') {
        // Evita arrancar dos sincronizaciones al mismo tiempo.
        $pdo->exec("UPDATE sat_69b_procesos SET estado='error', mensaje='Proceso anterior interrumpido.', fecha_fin=NOW(), fecha_actualizacion=NOW()
                    WHERE estado IN ('pendiente','ejecutando') AND fecha_actualizacion < (NOW() - INTERVAL 30 MINUTE)");
        $st = $pdo->query("SELECT id_proceso FROM sat_69b_procesos WHERE estado IN ('pendiente','ejecutando') ORDER BY fecha_inicio DESC LIMIT 1");
        $activo = $st->fetchColumn();
        if ($activo) {
            responder(['status' => 'busy', 'msg' => 'Ya existe una sincronización 69-B en proceso.', 'id_proceso' => $activo], 409);
        }

        $idProceso = bin2hex(random_bytes(16));
        $st = $pdo->prepare("INSERT INTO sat_69b_procesos (id_proceso,id_usuario,estado,etapa,porcentaje,mensaje) VALUES (?,?,'pendiente','Preparando',1,'Proceso creado')");
        $st->execute([$idProceso, $idUsuario]);
        responder(['status' => 'ok', 'id_proceso' => $idProceso]);
    }

    $idProceso = trim((string)($_POST['id_proceso'] ?? $_GET['id_proceso'] ?? ''));
    if ($idProceso === '') responder(['status' => 'error', 'msg' => 'Falta id_proceso.'], 400);

    $own = $pdo->prepare('SELECT id_proceso FROM sat_69b_procesos WHERE id_proceso=? AND id_usuario=?');
    $own->execute([$idProceso, $idUsuario]);
    if (!$own->fetchColumn()) responder(['status' => 'error', 'msg' => 'Proceso no encontrado o sin permiso.'], 404);

    if ($accion === 'estado') {
        $st = $pdo->prepare('SELECT id_proceso, estado, etapa, porcentaje, registros_procesados, total_registros, cancelar, mensaje, fecha_inicio, fecha_actualizacion, fecha_fin FROM sat_69b_procesos WHERE id_proceso=?');
        $st->execute([$idProceso]);
        responder(['status' => 'ok', 'proceso' => $st->fetch(PDO::FETCH_ASSOC)]);
    }

    if ($accion === 'cancelar') {
        $st = $pdo->prepare("UPDATE sat_69b_procesos SET cancelar=1, mensaje='Cancelación solicitada por el usuario', fecha_actualizacion=NOW() WHERE id_proceso=? AND estado IN ('pendiente','ejecutando')");
        $st->execute([$idProceso]);
        responder(['status' => 'ok', 'msg' => 'Cancelación solicitada.']);
    }

    if ($accion === 'ejecutar') {
        $st = $pdo->prepare("UPDATE sat_69b_procesos SET estado='ejecutando', etapa='Iniciando sincronización', porcentaje=3, fecha_actualizacion=NOW() WHERE id_proceso=? AND estado='pendiente'");
        $st->execute([$idProceso]);
        if ($st->rowCount() === 0) responder(['status' => 'error', 'msg' => 'El proceso ya fue iniciado o terminó.'], 409);

        try {
            ejecutarSincronizacion($pdo, $idProceso);
            responder(['status' => 'ok', 'msg' => 'Proceso terminado.']);
        } catch (Throwable $e) {
            if ($e->getMessage() === '__CANCELADO__' || estaCancelado($pdo, $idProceso)) {
                marcarCancelado($pdo, $idProceso);
                responder(['status' => 'cancelado', 'msg' => 'Proceso cancelado. El catálogo anterior se conservó.']);
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare('DELETE FROM sat_69b_staging WHERE id_proceso=?')->execute([$idProceso]);
            actualizarProceso($pdo, $idProceso, 'error', 'Error', 0, 0, 0, 'No se pudo sincronizar: ' . $e->getMessage(), true);
            seguridad_log_error($e, 'sincronizar_sat_69b');
            responder(['status' => 'error', 'msg' => 'No se pudo sincronizar el listado 69-B. ' . $e->getMessage()], 500);
        }
    }

    responder(['status' => 'error', 'msg' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($e, 'sincronizar_sat_69b_general');
    responder(['status' => 'error', 'msg' => 'Error en sincronización 69-B. ' . $e->getMessage()], 500);
}
