<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'ver_solicitudes_sat'); } catch (Throwable $e) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>$e->getMessage()]); exit; }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
        http_response_code(401);
        throw new RuntimeException('La sesión ha terminado.');
    }

    $idEmpresa = (int)$_SESSION['id_empresa'];
    $tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
    $paqueteTipo = strtolower(trim((string)($_GET['paquete_tipo'] ?? '')));
    $estatus = trim((string)($_GET['estatus'] ?? ''));
    $fechaInicio = trim((string)($_GET['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_GET['fecha_fin'] ?? ''));
    $buscar = trim((string)($_GET['buscar'] ?? ''));

    $sql = "SELECT d.id, d.id_solicitud, d.fecha_inicio, d.fecha_fin, d.tipo, d.paquete_tipo, d.estado_comprobante,
                   d.origen, d.motivo, d.observaciones, d.estatus, d.mensaje_sat,
                   d.fecha_registro, d.fecha_cancelacion, d.motivo_cancelacion,
                   COALESCE(u.nombre_real, u.usuario, '') AS usuario_solicita,
                   COALESCE(p.paquetes_total, 0) AS paquetes_total,
                   COALESCE(p.paquetes_zip_guardados, 0) AS paquetes_zip_guardados,
                   COALESCE(p.paquetes_procesados, 0) AS paquetes_procesados,
                   COALESCE(p.paquetes_error, 0) AS paquetes_error,
                   COALESCE(p.total_archivos, 0) AS total_archivos,
                   COALESCE(p.archivos_extraidos, 0) AS archivos_extraidos,
                   COALESCE(p.archivos_procesados, 0) AS archivos_procesados,
                   COALESCE(p.archivos_duplicados, 0) AS archivos_duplicados,
                   COALESCE(p.archivos_error, 0) AS archivos_error
            FROM descargas_sat d
            LEFT JOIN usuarios u ON u.id_usuario = d.id_usuario_solicita
            LEFT JOIN (
                SELECT id_descarga_sat,
                       COUNT(*) AS paquetes_total,
                       SUM(CASE WHEN ruta_zip IS NOT NULL AND ruta_zip <> '' THEN 1 ELSE 0 END) AS paquetes_zip_guardados,
                       SUM(CASE WHEN estatus = 3 THEN 1 ELSE 0 END) AS paquetes_procesados,
                       SUM(CASE WHEN estatus = 4 THEN 1 ELSE 0 END) AS paquetes_error,
                       SUM(total_archivos) AS total_archivos,
                       SUM(archivos_extraidos) AS archivos_extraidos,
                       SUM(archivos_procesados) AS archivos_procesados,
                       SUM(archivos_duplicados) AS archivos_duplicados,
                       SUM(archivos_error) AS archivos_error
                FROM descargas_sat_paquetes
                GROUP BY id_descarga_sat
            ) p ON p.id_descarga_sat = d.id
            WHERE d.id_empresa = :id_empresa";
    $params = [':id_empresa' => $idEmpresa];

    if (in_array($tipo, ['emitidos', 'recibidos'], true)) {
        $sql .= " AND d.tipo = :tipo";
        $params[':tipo'] = $tipo;
    }
    if (in_array($paqueteTipo, ['xml', 'metadata'], true)) {
        $sql .= " AND d.paquete_tipo = :paquete_tipo";
        $params[':paquete_tipo'] = $paqueteTipo;
    }
    if ($estatus !== '' && in_array((int)$estatus, [0,1,2,3,4], true)) {
        $sql .= " AND d.estatus = :estatus";
        $params[':estatus'] = (int)$estatus;
    }
    // Los filtros de fecha del visor corresponden a la FECHA DE CREACIÓN
    // de la solicitud, no al periodo fiscal solicitado al SAT.
    if ($fechaInicio !== '') {
        $sql .= " AND d.fecha_registro >= :fecha_registro_desde";
        $params[':fecha_registro_desde'] = $fechaInicio . ' 00:00:00';
    }
    if ($fechaFin !== '') {
        $sql .= " AND d.fecha_registro <= :fecha_registro_hasta";
        $params[':fecha_registro_hasta'] = $fechaFin . ' 23:59:59';
    }
    if ($buscar !== '') {
        $sql .= " AND (d.id_solicitud LIKE :buscar OR d.mensaje_sat LIKE :buscar OR d.motivo LIKE :buscar)";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    $sql .= " ORDER BY d.fecha_registro DESC, d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $estatusTexto = [0=>'Pendiente', 1=>'En proceso', 2=>'Terminada', 3=>'Error', 4=>'Cancelada'];
    $data = [];
    foreach ($registros as $row) {
        $estado = (int)$row['estatus'];
        $paquetesTotal = (int)($row['paquetes_total'] ?? 0);
        $paquetesZip = (int)($row['paquetes_zip_guardados'] ?? 0);
        $paquetesProcesados = (int)($row['paquetes_procesados'] ?? 0);
        $paquetesError = (int)($row['paquetes_error'] ?? 0);
        $totalArchivos = (int)($row['total_archivos'] ?? 0);
        $archivosExtraidos = (int)($row['archivos_extraidos'] ?? 0);
        $archivosProcesados = (int)($row['archivos_procesados'] ?? 0);
        $archivosDuplicados = (int)($row['archivos_duplicados'] ?? 0);
        $archivosError = (int)($row['archivos_error'] ?? 0);

        if ($paquetesTotal <= 0) {
            $descargaEstado = $estado === 3 ? 'error' : ($estado === 4 ? 'cancelada' : 'pendiente');
            $descargaTexto = $estado === 3 ? 'Sin ZIP' : ($estado === 4 ? 'Cancelada' : 'Esperando paquete');
        } elseif ($paquetesZip >= $paquetesTotal) {
            $descargaEstado = 'ok';
            $descargaTexto = $paquetesTotal === 1 ? 'ZIP guardado' : $paquetesZip . '/' . $paquetesTotal . ' ZIP guardados';
        } elseif ($paquetesError > 0) {
            $descargaEstado = 'error';
            $descargaTexto = $paquetesZip . '/' . $paquetesTotal . ' ZIP · ' . $paquetesError . ' error';
        } else {
            $descargaEstado = 'proceso';
            $descargaTexto = $paquetesZip . '/' . $paquetesTotal . ' ZIP guardados';
        }

        // Estado funcional del procesamiento. "Terminado" del SAT no implica que
        // todos los XML hayan terminado de pasar por el visor.
        $archivosAtendidos = $archivosProcesados + $archivosDuplicados + $archivosError;
        $archivosPendientes = max(0, $totalArchivos - $archivosAtendidos);
        $procesoCerrado = false;
        $procesoTitulo = '';

        if ($estado === 4) {
            $procesoEstado = 'cancelada';
            $procesoTexto = 'Cancelada';
            $procesoTitulo = 'Solicitud cancelada. No volverá a procesarse.';
            $procesoCerrado = true;
        } elseif ($estado === 3 && $paquetesTotal <= 0) {
            // Falló antes de obtener/guardar un paquete SAT. No existe ZIP ni XML
            // que ProcessWorker pueda recoger; se conserva únicamente como historial.
            $procesoEstado = 'cancelada';
            $procesoTexto = 'No se procesará';
            $procesoTitulo = trim((string)($row['mensaje_sat'] ?? '')) ?: 'Solicitud fallida sin paquete ZIP. No hay XML que procesar.';
            $procesoCerrado = true;
        } elseif ($paquetesTotal <= 0 || $totalArchivos <= 0) {
            $procesoEstado = 'pendiente';
            $procesoTexto = 'Pendiente';
            $procesoTitulo = 'Aún no hay archivos XML disponibles para procesar.';
        } elseif ($archivosAtendidos >= $totalArchivos) {
            $procesoCerrado = true;
            if ($archivosError > 0) {
                $procesoEstado = 'error';
                $procesoTexto = 'Completo con ' . number_format($archivosError) . ' error' . ($archivosError === 1 ? '' : 'es');
                $procesoTitulo = number_format($archivosProcesados) . ' procesados · ' . number_format($archivosDuplicados) . ' duplicados · ' . number_format($archivosError) . ' errores reales.';
            } else {
                $procesoEstado = 'ok';
                $procesoTexto = 'Completo · ' . number_format($archivosProcesados) . ' procesados';
                if ($archivosDuplicados > 0) {
                    $procesoTexto .= ' · ' . number_format($archivosDuplicados) . ' duplicados';
                }
                $procesoTitulo = number_format($archivosAtendidos) . '/' . number_format($totalArchivos) . ' XML atendidos. No quedan pendientes.';
            }
        } elseif ($archivosProcesados > 0 || $archivosDuplicados > 0 || $archivosError > 0 || $archivosExtraidos > 0) {
            $procesoEstado = $archivosError > 0 ? 'error' : 'proceso';
            $procesoTexto = number_format($archivosAtendidos) . '/' . number_format($totalArchivos) . ' atendidos';
            if ($archivosDuplicados > 0) {
                $procesoTexto .= ' · ' . number_format($archivosDuplicados) . ' duplicados';
            }
            if ($archivosError > 0) {
                $procesoTexto .= ' · ' . number_format($archivosError) . ' error' . ($archivosError === 1 ? '' : 'es');
            }
            $procesoTitulo = number_format($archivosPendientes) . ' XML pendientes por clasificar/procesar.';
        } else {
            $procesoEstado = 'pendiente';
            $procesoTexto = 'Pendiente · ' . number_format($totalArchivos) . ' archivos';
            $procesoTitulo = 'El paquete está disponible, pero todavía no inicia el procesamiento XML.';
        }

        $data[] = [
            'id' => (int)$row['id'],
            'id_solicitud' => $row['id_solicitud'] ?: 'Pendiente de envío',
            'fecha_inicio' => date('d/m/Y', strtotime($row['fecha_inicio'])),
            'fecha_fin' => date('d/m/Y', strtotime($row['fecha_fin'])),
            'tipo' => ucfirst((string)$row['tipo']),
            'paquete_tipo' => strtoupper((string)($row['paquete_tipo'] ?: 'xml')) .
                ((strtolower((string)($row['paquete_tipo'] ?? '')) === 'metadata' && strtolower((string)($row['estado_comprobante'] ?? 'todos')) === 'cancelado') ? ' · CANCELADOS' : ''),
            'estado_comprobante' => strtolower((string)($row['estado_comprobante'] ?? 'todos')),
            'origen' => ucfirst((string)($row['origen'] ?: 'automatica')),
            'usuario_solicita' => trim((string)$row['usuario_solicita']),
            'motivo' => trim((string)($row['motivo'] ?? '')),
            'estatus' => $estado,
            'estatus_texto' => $estatusTexto[$estado] ?? 'Desconocido',
            'descarga_estado' => $descargaEstado,
            'descarga_texto' => $descargaTexto,
            'paquetes_total' => $paquetesTotal,
            'paquetes_zip_guardados' => $paquetesZip,
            'proceso_estado' => $procesoEstado,
            'proceso_texto' => $procesoTexto,
            'proceso_titulo' => $procesoTitulo,
            'proceso_cerrado' => $procesoCerrado,
            'archivos_atendidos' => $archivosAtendidos,
            'archivos_pendientes' => $archivosPendientes,
            'total_archivos' => $totalArchivos,
            'archivos_procesados' => $archivosProcesados,
            'archivos_duplicados' => $archivosDuplicados,
            'archivos_error' => $archivosError,
            'mensaje_sat' => trim((string)($row['mensaje_sat'] ?? '')),
            'fecha_registro' => date('d/m/Y H:i:s', strtotime($row['fecha_registro'])),
            'fecha_cancelacion' => $row['fecha_cancelacion'] ? date('d/m/Y H:i:s', strtotime($row['fecha_cancelacion'])) : '',
            'motivo_cancelacion' => trim((string)($row['motivo_cancelacion'] ?? '')),
            'puede_cancelar' => in_array($estado, [0,1], true),
        ];
    }

    echo json_encode(['success'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    seguridad_log_error($e, 'listar_solicitudes_sat'); echo json_encode(['success'=>false, 'data'=>[], 'error'=>'No se pudieron consultar las solicitudes SAT.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
