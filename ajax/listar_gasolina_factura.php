<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $uuid = strtoupper(trim($_GET['uuid'] ?? ''));
    if ($idEmpresa <= 0 || $uuid === '') throw new Exception('Solicitud inválida.');

    exigir_permiso_factura_uuid($pdo,$idUsuario,$idEmpresa,$uuid);

    $st = $pdo->prepare("SELECT
            g.uuid,g.version,g.tipo_operacion,g.numero_cuenta,g.subtotal_combustible,g.total_combustible,
            g.iva_total,g.ieps_total,g.fecha_pago,g.total_pagado,g.saldo_pendiente,g.ya_pago,g.observaciones,
            f.fecha_emision,f.serie,f.folio,f.subtotal_xml,f.total_xml,f.base_iva,f.iva_traslado,
            e.rfc AS rfc_emisor,e.nombre AS emisor
        FROM facturas_gasolina g
        INNER JOIN facturas f ON f.uuid=g.uuid AND f.id_empresa=g.id_empresa
        LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor
        WHERE g.uuid=? AND g.id_empresa=? LIMIT 1");
    $st->execute([$uuid,$idEmpresa]);
    $cab = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cab) throw new Exception('La factura no tiene detalle de combustible procesado.');

    $stDet = $pdo->prepare("SELECT
            d.id_detalle,d.identificador,d.fecha_operacion,d.rfc_gasolinera,
            COALESCE(NULLIF(e.nombre,''),d.rfc_gasolinera) AS nombre_gasolinera,
            d.clave_estacion,d.tipo_combustible,d.unidad,d.nombre_combustible,d.folio_operacion,
            d.cantidad,d.valor_unitario,d.importe,d.iva,d.ieps,d.total_impuestos,d.total_operacion
        FROM facturas_gasolina_detalles d
        LEFT JOIN cat_emisores e ON e.id_empresa=d.id_empresa AND e.rfc=d.rfc_gasolinera
        WHERE d.uuid=? AND d.id_empresa=?
        ORDER BY d.fecha_operacion,d.id_detalle");
    $stDet->execute([$uuid,$idEmpresa]);
    $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC);

    $stImp = $pdo->prepare("SELECT i.id_detalle,i.impuesto,i.tasa_cuota,i.importe
        FROM facturas_gasolina_impuestos i
        INNER JOIN facturas_gasolina_detalles d ON d.id_detalle=i.id_detalle
        WHERE d.uuid=? AND d.id_empresa=? ORDER BY d.fecha_operacion,d.id_detalle,i.id_impuesto");
    $stImp->execute([$uuid,$idEmpresa]);
    $impuestos = $stImp->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'cabecera'=>$cab,'detalles'=>$detalles,'impuestos'=>$impuestos], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    seguridad_log_error($e, 'listar_gasolina_factura'); echo json_encode(['ok'=>false,'error'=>'No se pudo consultar la información de gasolina.'], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
