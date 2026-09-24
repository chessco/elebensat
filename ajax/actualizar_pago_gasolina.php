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
    $uuid = strtoupper(trim($_POST['uuid'] ?? ''));
    $fecha = trim($_POST['fecha_pago'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    if ($idEmpresa <= 0 || $uuid === '') throw new Exception('Solicitud inválida.');

    exigir_permiso_factura_uuid($pdo,$idUsuario,$idEmpresa,$uuid);

    if ($fecha !== '') {
        $dt = DateTime::createFromFormat('Y-m-d',$fecha);
        if (!$dt || $dt->format('Y-m-d') !== $fecha) throw new Exception('Fecha de pago inválida.');
    }

    $st = $pdo->prepare("SELECT total_combustible FROM facturas_gasolina WHERE uuid=? AND id_empresa=? LIMIT 1");
    $st->execute([$uuid,$idEmpresa]);
    $total = $st->fetchColumn();
    if ($total === false) throw new Exception('No se encontró el estado de cuenta de combustible.');
    $total = (float)$total;

    if ($fecha !== '') {
        $pdo->prepare("UPDATE facturas_gasolina SET fecha_pago=?,total_pagado=total_combustible,saldo_pendiente=0,ya_pago=1,observaciones=?,fecha_actualizacion=NOW() WHERE uuid=? AND id_empresa=?")
            ->execute([$fecha,$observaciones ?: null,$uuid,$idEmpresa]);
    } else {
        $pdo->prepare("UPDATE facturas_gasolina SET fecha_pago=NULL,total_pagado=0,saldo_pendiente=total_combustible,ya_pago=0,observaciones=?,fecha_actualizacion=NOW() WHERE uuid=? AND id_empresa=?")
            ->execute([$observaciones ?: null,$uuid,$idEmpresa]);
    }

    echo json_encode(['ok'=>true,'msg'=>$fecha !== '' ? 'Pago de gasolina marcado como aplicado.' : 'Pago de gasolina regresado a pendiente.']);
} catch (Throwable $e) {
    http_response_code(400);
    seguridad_log_error($e, 'actualizar_pago_gasolina'); echo json_encode(['ok'=>false,'error'=>'No se pudo actualizar el pago de gasolina.'], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
