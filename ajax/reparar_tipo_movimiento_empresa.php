<?php
error_reporting(0);
@set_time_limit(300);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, true);

try {
    if (!isset($_SESSION['id_empresa'])) {
        throw new Exception('Sin empresa activa en la sesión.');
    }

    $idEmpresa = (int)$_SESSION['id_empresa'];

    $stEmpresa = $pdo->prepare('SELECT rfc FROM empresas WHERE id_empresa = ?');
    $stEmpresa->execute([$idEmpresa]);
    $rfcEmpresa = strtoupper(trim((string)$stEmpresa->fetchColumn()));
    if ($rfcEmpresa === '') {
        throw new Exception('La empresa activa no tiene RFC configurado.');
    }

    $sql = "UPDATE facturas f
            INNER JOIN cat_emisores e ON e.id_emisor = f.id_emisor
            INNER JOIN cat_receptores r ON r.id_receptor = f.id_receptor
            SET f.tipo_movimiento_empresa = CASE
                WHEN UPPER(TRIM(e.rfc)) = :rfc_emisor THEN 'I'
                WHEN UPPER(TRIM(r.rfc)) = :rfc_receptor THEN 'E'
                ELSE NULL
            END
            WHERE f.id_empresa = :id_empresa";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':rfc_emisor' => $rfcEmpresa,
        ':rfc_receptor' => $rfcEmpresa,
        ':id_empresa' => $idEmpresa
    ]);

    $resumen = $pdo->prepare("SELECT
        COUNT(*) total,
        SUM(tipo_movimiento_empresa='I' AND UPPER(COALESCE(id_tipo_comprobante,'')) NOT IN ('P','N','T')) ingresos,
        SUM(tipo_movimiento_empresa='E' AND UPPER(COALESCE(id_tipo_comprobante,'')) NOT IN ('P','N','T')) egresos,
        SUM(UPPER(COALESCE(id_tipo_comprobante,''))='P') pagos,
        SUM(UPPER(COALESCE(id_tipo_comprobante,''))='N') nominas,
        SUM(UPPER(COALESCE(id_tipo_comprobante,''))='T') traslados,
        SUM((tipo_movimiento_empresa IS NULL OR tipo_movimiento_empresa='')
            AND UPPER(COALESCE(id_tipo_comprobante,'')) NOT IN ('P','N','T')) sin_clasificar
        FROM facturas WHERE id_empresa=?");
    $resumen->execute([$idEmpresa]);
    $datos = $resumen->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 'ok',
        'mensaje' => 'Clasificación actualizada correctamente.',
        'empresa_rfc' => $rfcEmpresa,
        'filas_actualizadas' => $st->rowCount(),
        'resumen' => $datos
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    seguridad_log_error($e, 'reparar_tipo_movimiento_empresa');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'No se pudo completar la reparación.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
