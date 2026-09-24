<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/control_diot_pagos.php';

function fecha_detalle_valida($v): ?string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idDetalle = (int)($_POST['id_detalle'] ?? 0);
    $fecha = fecha_detalle_valida($_POST['fecha_usuario'] ?? null);

    if ($idEmpresa <= 0) throw new RuntimeException('Sesión expirada.');
    if ($idDetalle <= 0) throw new InvalidArgumentException('Detalle de pago inválido.');

    $st = $pdo->prepare("SELECT d.*, DATE(f.fecha_emision) fecha_emision
                         FROM facturas_pagos_detalles d
                         INNER JOIN facturas f ON f.id_empresa=d.id_empresa AND UPPER(f.uuid)=UPPER(d.uuid_relacionado)
                         WHERE d.id_detalle=? AND d.id_empresa=? LIMIT 1");
    $st->execute([$idDetalle, $idEmpresa]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new RuntimeException('No se encontró el detalle de pago.');

    $uuid = strtoupper((string)$d['uuid_relacionado']);
    exigir_permiso_factura_uuid($pdo, $idUsuario, $idEmpresa, $uuid);

    if ($fecha) {
        $fechaFiscal = $fecha;
    } else {
        $origen = strtoupper(trim((string)($d['origen_pago'] ?? '')));
        if ($origen === 'PUE_EMISION') {
            $fechaFiscal = fecha_detalle_valida($d['fecha_emision'] ?? null);
        } else {
            $fechaFiscal = fecha_detalle_valida($d['fecha_pago_banco'] ?? null)
                ?: fecha_detalle_valida($d['fecha_pago_sat'] ?? null);
        }
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE facturas_pagos_detalles
                   SET fecha_pago_usuario=?, fecha_aplicacion_fiscal=?
                   WHERE id_detalle=? AND id_empresa=?")
        ->execute([$fecha, $fechaFiscal, $idDetalle, $idEmpresa]);

    $st = $pdo->prepare("SELECT COUNT(*) FROM facturas_pagos_detalles
                         WHERE id_empresa=? AND UPPER(uuid_relacionado)=UPPER(?)");
    $st->execute([$idEmpresa, $uuid]);
    $numDetalles = (int)$st->fetchColumn();

    if ($numDetalles <= 1) {
        // Si solo existe un movimiento, cabecera y detalle representan la misma aplicación.
        $pdo->prepare("UPDATE facturas
                       SET fecha_pago_usuario=?, fecha_aplicacion_fiscal=?
                       WHERE id_empresa=? AND UPPER(uuid)=UPPER(?)")
            ->execute([$fecha, $fechaFiscal, $idEmpresa, $uuid]);
    } else {
        // Con parcialidades ya no existe una única fecha manual de factura.
        // La cabecera queda como resumen y su fecha fiscal se recalcula desde los detalles.
        $pdo->prepare("UPDATE facturas SET fecha_pago_usuario=NULL
                       WHERE id_empresa=? AND UPPER(uuid)=UPPER(?)")
            ->execute([$idEmpresa, $uuid]);
        diot_recalcular_factura($pdo, $uuid, $idEmpresa);
    }

    $pdo->commit();
    echo json_encode([
        'status'=>'ok',
        'uuid'=>$uuid,
        'movimientos'=>$numDetalles,
        'fecha_usuario'=>$fecha,
        'fecha_fiscal'=>$fechaFiscal
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    seguridad_log_error($e, 'actualizar_fecha_detalle_pago'); echo json_encode(['status'=>'error','msg'=>'No se pudo actualizar la fecha del pago.'], JSON_UNESCAPED_UNICODE);
}
?>
