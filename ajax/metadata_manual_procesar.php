<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(60);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/metadata_manual.php';

function mm_tipo(string $rfcEmpresa, string $rfcEmisor, string $rfcReceptor): string {
    if ($rfcEmisor === $rfcEmpresa) return 'emitidos';
    if ($rfcReceptor === $rfcEmpresa) return 'recibidos';
    return '';
}

try {
    seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'ver_solicitudes_sat');
    $jobId = strtolower(trim((string)($_POST['job_id'] ?? '')));
    $job = metadata_manual_read_job($idEmpresa, $jobId);
    if ((int)($job['id_usuario'] ?? 0) !== $idUsuario && !usuario_es_superadmin($pdo, $idUsuario)) {
        throw new RuntimeException('No tiene permiso para procesar este archivo.');
    }

    if (!empty($job['cancelar'])) {
        $job['estado'] = 'cancelado';
        $job['fecha_fin'] = date('Y-m-d H:i:s');
        $job['mensaje'] = 'Procesamiento cancelado por el usuario.';
        metadata_manual_write_job($idEmpresa, $jobId, $job);
        echo json_encode(['success'=>true,'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (in_array((string)($job['estado'] ?? ''), ['completo','cancelado','error'], true)) {
        echo json_encode(['success'=>true,'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ruta = (string)($job['ruta'] ?? '');
    if (!is_file($ruta)) throw new RuntimeException('El archivo temporal de metadata ya no existe.');
    $fh = fopen($ruta, 'rb');
    if (!$fh) throw new RuntimeException('No fue posible abrir el archivo de metadata.');

    $header = fgetcsv($fh, 0, '~', '"', '\\');
    if (!is_array($header)) { fclose($fh); throw new RuntimeException('No se pudo leer el encabezado del metadata.'); }
    $map = metadata_manual_header_map($header);
    $headerOffset = ftell($fh);
    $offset = (int)($job['offset'] ?? 0);
    if ($offset > $headerOffset) fseek($fh, $offset);
    else $offset = $headerOffset;

    $rfcEmpresa = strtoupper((string)$job['rfc_empresa']);
    $limite = 500;
    $procesadosChunk = 0;

    $qFactura = $pdo->prepare('SELECT estatus_sat, fecha_cancelacion FROM facturas WHERE id_empresa=? AND uuid=? LIMIT 1');
    $qUpMeta = $pdo->prepare("\n        INSERT INTO sat_metadata_cfdi(\n            id_empresa,uuid,tipo,rfc_emisor,nombre_emisor,rfc_receptor,nombre_receptor,\n            pac_certifico,fecha_emision,fecha_certificacion_sat,monto,efecto_comprobante,\n            estatus_sat,fecha_cancelacion,id_paquete_ultima_revision,fecha_primera_deteccion,fecha_ultima_revision\n        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NOW(),NOW())\n        ON DUPLICATE KEY UPDATE\n            tipo=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.tipo,VALUES(tipo)),\n            rfc_emisor=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.rfc_emisor,VALUES(rfc_emisor)),\n            nombre_emisor=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.nombre_emisor,VALUES(nombre_emisor)),\n            rfc_receptor=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.rfc_receptor,VALUES(rfc_receptor)),\n            nombre_receptor=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.nombre_receptor,VALUES(nombre_receptor)),\n            pac_certifico=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.pac_certifico,VALUES(pac_certifico)),\n            fecha_emision=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.fecha_emision,VALUES(fecha_emision)),\n            fecha_certificacion_sat=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.fecha_certificacion_sat,VALUES(fecha_certificacion_sat)),\n            monto=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.monto,VALUES(monto)),\n            efecto_comprobante=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.efecto_comprobante,VALUES(efecto_comprobante)),\n            estatus_sat=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.estatus_sat,VALUES(estatus_sat)),\n            fecha_cancelacion=IF(UPPER(sat_metadata_cfdi.estatus_sat)='CANCELADO',sat_metadata_cfdi.fecha_cancelacion,COALESCE(VALUES(fecha_cancelacion),sat_metadata_cfdi.fecha_cancelacion)),\n            fecha_ultima_revision=NOW()\n    ");
    $qNotif = $pdo->prepare("\n        INSERT INTO notificaciones_sat(\n            id_empresa,uuid,tipo_alerta,estatus_anterior,estatus_nuevo,fecha_documento,\n            fecha_cancelacion_sat,fecha_deteccion,fecha_notificacion,estatus_notificacion,id_paquete_metadata\n        )\n        SELECT f.id_empresa,f.uuid,'CANCELACION',COALESCE(NULLIF(f.estatus_sat,''),'No registrado'),'Cancelado',\n               f.fecha_emision,?,NOW(),NOW(),'NUEVA',NULL\n        FROM facturas f\n        WHERE f.id_empresa=? AND f.uuid=? AND UPPER(COALESCE(f.estatus_sat,''))<>'CANCELADO'\n        ON DUPLICATE KEY UPDATE id=notificaciones_sat.id\n    ");
    $qCancel = $pdo->prepare("UPDATE facturas SET estatus_sat='Cancelado', fecha_cancelacion=COALESCE(?,fecha_cancelacion) WHERE id_empresa=? AND uuid=? AND UPPER(COALESCE(estatus_sat,''))<>'CANCELADO'");
    $qVigente = $pdo->prepare("UPDATE facturas SET estatus_sat='Vigente' WHERE id_empresa=? AND uuid=? AND UPPER(COALESCE(estatus_sat,'')) NOT IN ('CANCELADO','CANCELADA','0','VIGENTE','1')");
    $qFaltante = $pdo->prepare("\n        INSERT INTO cfdi_faltantes_sat(\n            id_empresa,uuid,tipo,rfc_emisor,nombre_emisor,rfc_receptor,nombre_receptor,fecha_emision,monto,\n            efecto_comprobante,estatus_sat,fecha_cancelacion,estatus_recuperacion,intentos,fecha_detectado,fecha_ultima_deteccion,id_paquete_metadata,mensaje\n        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,0,0,NOW(),NOW(),NULL,'Detectado por carga manual de Metadata SAT.')\n        ON DUPLICATE KEY UPDATE\n            tipo=VALUES(tipo),rfc_emisor=VALUES(rfc_emisor),nombre_emisor=VALUES(nombre_emisor),\n            rfc_receptor=VALUES(rfc_receptor),nombre_receptor=VALUES(nombre_receptor),fecha_emision=VALUES(fecha_emision),\n            monto=VALUES(monto),efecto_comprobante=VALUES(efecto_comprobante),estatus_sat=VALUES(estatus_sat),\n            fecha_cancelacion=COALESCE(VALUES(fecha_cancelacion),cfdi_faltantes_sat.fecha_cancelacion),\n            fecha_ultima_deteccion=NOW(),mensaje='Confirmado por carga manual de Metadata SAT.'\n    ");
    $qResolver = $pdo->prepare("UPDATE cfdi_faltantes_sat SET estatus_recuperacion=2, fecha_resuelto=COALESCE(fecha_resuelto,NOW()), mensaje='XML localizado en el visor.' WHERE id_empresa=? AND uuid=? AND estatus_recuperacion<>2");

    $pdo->beginTransaction();
    while ($procesadosChunk < $limite && ($row = fgetcsv($fh, 0, '~', '"', '\\')) !== false) {
        $procesadosChunk++;
        $job['leidos']++;
        try {
            $uuid = metadata_manual_uuid(metadata_manual_value($row,$map,'Uuid'));
            $rfcEmisor = strtoupper(metadata_manual_value($row,$map,'RfcEmisor'));
            $rfcReceptor = strtoupper(metadata_manual_value($row,$map,'RfcReceptor'));
            $tipo = mm_tipo($rfcEmpresa,$rfcEmisor,$rfcReceptor);
            if ($tipo === '') { $job['omitidos_empresa']++; continue; }
            $fechaCancelacion = metadata_manual_fecha(metadata_manual_value($row,$map,'FechaCancelacion'));
            $estatus = metadata_manual_estatus(metadata_manual_value($row,$map,'Estatus'), $fechaCancelacion);
            if ($uuid === '' || $estatus === '') { $job['errores']++; continue; }

            $job['validos']++;
            $nombreEmisor = metadata_manual_value($row,$map,'NombreEmisor');
            $nombreReceptor = metadata_manual_value($row,$map,'NombreReceptor');
            $pac = metadata_manual_value($row,$map,'PacCertifico');
            $fechaEmision = metadata_manual_fecha(metadata_manual_value($row,$map,'FechaEmision'));
            $fechaCert = metadata_manual_fecha(metadata_manual_value($row,$map,'FechaCertificacionSat'));
            $montoRaw = metadata_manual_value($row,$map,'Monto');
            $monto = is_numeric($montoRaw) ? (float)$montoRaw : null;
            $efecto = strtoupper(metadata_manual_value($row,$map,'EfectoComprobante'));

            $qUpMeta->execute([$idEmpresa,$uuid,$tipo,$rfcEmisor,$nombreEmisor,$rfcReceptor,$nombreReceptor,$pac,$fechaEmision,$fechaCert,$monto,$efecto,$estatus,$fechaCancelacion]);

            $qFactura->execute([$idEmpresa,$uuid]);
            $factura = $qFactura->fetch(PDO::FETCH_ASSOC);
            if ($factura) {
                $job['encontrados']++;
                $antes = strtoupper(trim((string)($factura['estatus_sat'] ?? '')));
                if ($estatus === 'Cancelado') {
                    $qNotif->execute([$fechaCancelacion,$idEmpresa,$uuid]);
                    $qCancel->execute([$fechaCancelacion,$idEmpresa,$uuid]);
                    if ($qCancel->rowCount() > 0) $job['cancelados_aplicados']++; else $job['sin_cambio']++;
                } else {
                    $qVigente->execute([$idEmpresa,$uuid]);
                    if ($qVigente->rowCount() > 0) $job['vigentes_aplicados']++; else $job['sin_cambio']++;
                }
                $qResolver->execute([$idEmpresa,$uuid]);
            } else {
                $job['no_encontrados']++;
                $qFaltante->execute([$idEmpresa,$uuid,$tipo,$rfcEmisor,$nombreEmisor,$rfcReceptor,$nombreReceptor,$fechaEmision,$monto,$efecto,$estatus,$fechaCancelacion]);
            }
        } catch (Throwable $rowError) {
            $job['errores']++;
        }
    }
    $pdo->commit();

    $pos = ftell($fh);
    $eof = feof($fh);
    fclose($fh);
    $job['offset'] = max($offset, (int)$pos);
    $job['bytes_procesados'] = min((int)$job['bytes_total'], (int)$job['offset']);

    // Leer de nuevo el job por si el usuario pidió cancelar mientras este bloque estaba trabajando.
    $latest = metadata_manual_read_job($idEmpresa, $jobId);
    if (!empty($latest['cancelar'])) {
        $job['cancelar'] = true;
        $job['estado'] = 'cancelado';
        $job['fecha_fin'] = date('Y-m-d H:i:s');
        $job['mensaje'] = 'Procesamiento cancelado por el usuario.';
    } elseif ($eof) {
        $job['estado'] = 'completo';
        $job['bytes_procesados'] = (int)$job['bytes_total'];
        $job['fecha_fin'] = date('Y-m-d H:i:s');
        $job['mensaje'] = 'Metadata procesada correctamente.';
    } else {
        $job['estado'] = 'procesando';
        $job['mensaje'] = 'Procesando metadata...';
    }
    metadata_manual_write_job($idEmpresa, $jobId, $job);
    echo json_encode(['success'=>true,'job'=>metadata_manual_public_job($job)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($idEmpresa, $jobId) && is_int($idEmpresa) && is_string($jobId) && preg_match('/^[a-f0-9]{32}$/',$jobId)) {
        try {
            $job = metadata_manual_read_job($idEmpresa,$jobId);
            $job['estado']='error';$job['fecha_fin']=date('Y-m-d H:i:s');$job['mensaje']='Error: '.$e->getMessage();
            metadata_manual_write_job($idEmpresa,$jobId,$job);
        } catch (Throwable $ignore) {}
    }
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
