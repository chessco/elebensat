<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_cruce_metadata');

if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no válida.']);
    exit;
}


$idEmpresa = (int)$_SESSION['id_empresa'];

try {
    $pdo->beginTransaction();

    // 1) Crear alerta SOLO cuando existe un cambio real hacia Cancelado.
    //    La llave única de notificaciones_sat evita duplicar una cancelación ya notificada.
    $sqlNotif = "
        INSERT INTO notificaciones_sat (
            id_empresa, uuid, tipo_alerta, estatus_anterior, estatus_nuevo,
            fecha_documento, fecha_cancelacion_sat, fecha_deteccion,
            fecha_notificacion, estatus_notificacion, id_paquete_metadata
        )
        SELECT
            f.id_empresa,
            f.uuid,
            'CANCELACION',
            COALESCE(NULLIF(f.estatus_sat,''), 'No registrado'),
            'Cancelado',
            f.fecha_emision,
            m.fecha_cancelacion,
            NOW(),
            NOW(),
            'NUEVA',
            m.id_paquete_ultima_revision
        FROM facturas f
        INNER JOIN sat_metadata_cfdi m
            ON m.id_empresa = f.id_empresa
           AND m.uuid = f.uuid
        WHERE f.id_empresa = :empresa
          AND UPPER(COALESCE(m.estatus_sat,'')) = 'CANCELADO'
          AND UPPER(COALESCE(f.estatus_sat,'')) <> 'CANCELADO'
        ON DUPLICATE KEY UPDATE id = notificaciones_sat.id
    ";
    $st = $pdo->prepare($sqlNotif);
    $st->execute([':empresa' => $idEmpresa]);
    $notificacionesNuevas = $st->rowCount();

    // 2) Actualizar estatus SAT de CFDI existentes SOLO si realmente cambió.
    //    Metadata vieja sin cambios no genera ningún movimiento.
    $sqlUpdate = "
        UPDATE facturas f
        INNER JOIN sat_metadata_cfdi m
            ON m.id_empresa = f.id_empresa
           AND m.uuid = f.uuid
        SET
            f.estatus_sat = m.estatus_sat,
            f.fecha_cancelacion = CASE
                WHEN UPPER(COALESCE(m.estatus_sat,'')) = 'CANCELADO'
                    THEN COALESCE(m.fecha_cancelacion, f.fecha_cancelacion)
                ELSE f.fecha_cancelacion
            END
        WHERE f.id_empresa = :empresa
          AND UPPER(COALESCE(f.estatus_sat,'')) <> UPPER(COALESCE(m.estatus_sat,''))
    ";
    $st = $pdo->prepare($sqlUpdate);
    $st->execute([':empresa' => $idEmpresa]);
    $estatusActualizados = $st->rowCount();

    // 3) Registrar únicamente UUID presentes en metadata cuyo XML NO existe en facturas.
    //    La llave única empresa+UUID hace idempotente el cruce: no duplica faltantes.
    $sqlFaltantes = "
        INSERT INTO cfdi_faltantes_sat (
            id_empresa, uuid, tipo, rfc_emisor, nombre_emisor,
            rfc_receptor, nombre_receptor, fecha_emision, monto,
            efecto_comprobante, estatus_sat, fecha_cancelacion,
            estatus_recuperacion, intentos, fecha_detectado,
            fecha_ultima_deteccion, id_paquete_metadata, mensaje
        )
        SELECT
            m.id_empresa,
            m.uuid,
            m.tipo,
            m.rfc_emisor,
            m.nombre_emisor,
            m.rfc_receptor,
            m.nombre_receptor,
            m.fecha_emision,
            m.monto,
            m.efecto_comprobante,
            m.estatus_sat,
            m.fecha_cancelacion,
            0,
            0,
            NOW(),
            NOW(),
            m.id_paquete_ultima_revision,
            'Detectado por cruce manual Metadata vs CFDI.'
        FROM sat_metadata_cfdi m
        LEFT JOIN facturas f
            ON f.id_empresa = m.id_empresa
           AND f.uuid = m.uuid
        WHERE m.id_empresa = :empresa
          AND f.uuid IS NULL
        ON DUPLICATE KEY UPDATE
            tipo = VALUES(tipo),
            rfc_emisor = VALUES(rfc_emisor),
            nombre_emisor = VALUES(nombre_emisor),
            rfc_receptor = VALUES(rfc_receptor),
            nombre_receptor = VALUES(nombre_receptor),
            fecha_emision = VALUES(fecha_emision),
            monto = VALUES(monto),
            efecto_comprobante = VALUES(efecto_comprobante),
            estatus_sat = VALUES(estatus_sat),
            fecha_cancelacion = COALESCE(VALUES(fecha_cancelacion), cfdi_faltantes_sat.fecha_cancelacion),
            fecha_ultima_deteccion = NOW(),
            id_paquete_metadata = VALUES(id_paquete_metadata),
            estatus_recuperacion = IF(cfdi_faltantes_sat.estatus_recuperacion = 2, 2, cfdi_faltantes_sat.estatus_recuperacion),
            mensaje = IF(cfdi_faltantes_sat.estatus_recuperacion = 2,
                         cfdi_faltantes_sat.mensaje,
                         'Confirmado por cruce manual Metadata vs CFDI.')
    ";
    $st = $pdo->prepare($sqlFaltantes);
    $st->execute([':empresa' => $idEmpresa]);

    // 4) Si un faltante anterior ya tiene XML en facturas, cerrarlo para que NO aparezca.
    $sqlResolver = "
        UPDATE cfdi_faltantes_sat x
        INNER JOIN facturas f
            ON f.id_empresa = x.id_empresa
           AND f.uuid = x.uuid
        SET
            x.estatus_recuperacion = 2,
            x.fecha_resuelto = COALESCE(x.fecha_resuelto, NOW()),
            x.mensaje = 'XML localizado en el visor.'
        WHERE x.id_empresa = :empresa
          AND x.estatus_recuperacion <> 2
    ";
    $st = $pdo->prepare($sqlResolver);
    $st->execute([':empresa' => $idEmpresa]);
    $faltantesResueltos = $st->rowCount();

    // 5) Conteo final: solo faltantes reales y todavía sin XML.
    $sqlCount = "
        SELECT COUNT(*)
        FROM cfdi_faltantes_sat x
        LEFT JOIN facturas f
            ON f.id_empresa = x.id_empresa
           AND f.uuid = x.uuid
        WHERE x.id_empresa = :empresa
          AND x.estatus_recuperacion <> 2
          AND f.uuid IS NULL
    ";
    $st = $pdo->prepare($sqlCount);
    $st->execute([':empresa' => $idEmpresa]);
    $faltantes = (int)$st->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'status' => 'ok',
        'msg' => 'Cruce Metadata vs CFDI terminado.',
        'faltantes' => $faltantes,
        'estatus_actualizados' => $estatusActualizados,
        'notificaciones_nuevas' => $notificacionesNuevas,
        'faltantes_resueltos' => $faltantesResueltos
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'msg' => 'Error al ejecutar el cruce: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
