<?php
session_start();
// La respuesta de DataTables debe ser JSON puro. Los errores se registran en log,
// no se imprimen como HTML dentro de la respuesta AJAX.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';

try {
    exigir_permiso_accion($pdo, (int)($_SESSION['id_usuario'] ?? 0), (int)($_SESSION['id_empresa'] ?? 0), 'generar_diot');

    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $mes = (int)($_GET['mes'] ?? date('m'));
    $anio = (int)($_GET['anio'] ?? date('Y'));

    // IMPORTANTE: liberar el candado del archivo de sesión ANTES de ejecutar
    // la consulta DIOT. Si una consulta tarda, el usuario puede navegar, volver
    // a entrar a DIOT o lanzar otra petición sin quedar esperando al session lock.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    diot_sincronizar_pagos_usuario_empresa($pdo, $idEmpresa);
    $rows = diot_resumen_desde_detalles($pdo, $idEmpresa, $anio, $mes);
    $salida = [];
    foreach ($rows as $row) {
        $c = diot_fila_54($row);
        $item=[];
        foreach ($c as $idx=>$val) $item['c'.str_pad((string)$idx,2,'0',STR_PAD_LEFT)]=$val;
        // Metadatos no visibles para abrir la auditoría del emisor desde la fila DIOT.
        $item['_id_emisor'] = (int)($row['id_emisor'] ?? 0);
        $item['_rfc'] = (string)($row['rfc'] ?? '');
        $item['_nombre'] = (string)($row['nombre'] ?? '');
        $salida[]=$item;
    }

    echo json_encode([
        'data'=>$salida,
        'meta'=>[
            'origen'=>'facturas_pagos_detalles',
            'periodo'=>sprintf('%04d-%02d',$anio,$mes),
            'proveedores'=>count($salida),
            'diagnostico'=>count($salida) ? null : diot_diagnostico_periodo($pdo,$idEmpresa,$anio,$mes)
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    seguridad_log_error($e, 'listar_diot_data');
    echo json_encode(['data'=>[], 'error'=>'No se pudo consultar la información DIOT.'], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
