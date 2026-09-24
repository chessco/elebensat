<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);

function ppd_fecha($v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}

function ppd_fecha_filtro($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    foreach (['Y-m-d','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    return '';
}

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new Exception('No hay empresa activa.');

    $inicio = ppd_fecha_filtro($_POST['inicio'] ?? $_GET['inicio'] ?? '');
    $fin    = ppd_fecha_filtro($_POST['fin'] ?? $_GET['fin'] ?? '');
    if ($inicio === '' || $fin === '') throw new Exception('Seleccione un rango de fechas válido.');
    if ($inicio > $fin) throw new Exception('La fecha inicial no puede ser mayor a la final.');

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $finExclusivo = (new DateTime($fin))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $sql = "SELECT
                f.uuid,
                f.serie,
                f.folio,
                f.fecha_emision,
                f.total_xml AS importe,
                f.moneda,
                f.forma_pago,
                f.metodo_pago,
                f.folio_pago_origen AS cheque,
                COALESCE(f.fecha_pago_origen, f.fecha_ultimo_pago_contpaq, f.fecha_pago_banco, f.fecha_pago_usuario) AS fecha_cheque,
                e.id_emisor,
                e.rfc AS rfc_emisor,
                e.nombre AS emisor,
                COALESCE(e.correo,'') AS correo_emisor
            FROM facturas f
            LEFT JOIN cat_emisores e
              ON e.id_empresa=f.id_empresa
             AND e.id_emisor=f.id_emisor
            WHERE f.id_empresa=:empresa
              AND f.fecha_emision >= :inicio
              AND f.fecha_emision < :fin
              AND f.tipo_movimiento_empresa='E'
              AND f.metodo_pago='PPD'
              /* Solo facturas realmente pagadas: si no hay cheque/pago o fecha de pago, todavía no se solicita complemento. */
              AND TRIM(COALESCE(f.folio_pago_origen,'')) <> ''
              AND COALESCE(f.fecha_pago_origen, f.fecha_ultimo_pago_contpaq, f.fecha_pago_banco, f.fecha_pago_usuario) IS NOT NULL
              AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')
              AND NOT EXISTS (
                    SELECT 1
                    FROM facturas_pagos_detalles pdx
                    WHERE pdx.id_empresa=f.id_empresa
                      AND pdx.uuid_relacionado=f.uuid
              )
            ORDER BY f.fecha_emision ASC, e.nombre ASC, f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':empresa'=>$idEmpresa,
        ':inicio'=>$inicio . ' 00:00:00',
        ':fin'=>$finExclusivo,
    ]);

    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $serie = trim((string)($r['serie'] ?? ''));
        $folio = trim((string)($r['folio'] ?? ''));
        $factura = trim($serie . ($serie !== '' && $folio !== '' ? '-' : '') . $folio);
        if ($factura === '') $factura = 'S/F';

        $correo = trim((string)($r['correo_emisor'] ?? ''));
        $rows[] = [
            'id_emisor' => (int)($r['id_emisor'] ?? 0),
            'uuid' => (string)($r['uuid'] ?? ''),
            'factura' => $factura,
            'serie' => $serie,
            'folio' => $folio,
            'fecha_factura' => ppd_fecha($r['fecha_emision'] ?? ''),
            'cheque' => trim((string)($r['cheque'] ?? '')),
            'fecha_cheque' => ppd_fecha($r['fecha_cheque'] ?? ''),
            'rfc_emisor' => trim((string)($r['rfc_emisor'] ?? '')),
            'emisor' => trim((string)($r['emisor'] ?? '')),
            'importe' => (float)($r['importe'] ?? 0),
            'moneda' => trim((string)($r['moneda'] ?? '')),
            'correo_emisor' => $correo,
            'correo_valido' => ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) ? 1 : 0,
            'forma_pago' => trim((string)($r['forma_pago'] ?? '')),
            'metodo_pago' => trim((string)($r['metodo_pago'] ?? '')),
        ];
    }

    echo json_encode([
        'ok'=>true,
        'inicio'=>$inicio,
        'fin'=>$fin,
        'total'=>count($rows),
        'data'=>$rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
