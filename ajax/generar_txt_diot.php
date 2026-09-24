<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';

try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot');
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    $mes=(int)($_GET['mes']??date('m'));
    $anio=(int)($_GET['anio']??date('Y'));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    diot_sincronizar_pagos_usuario_empresa($pdo,$idEmpresa);
    $data=diot_resumen_desde_detalles($pdo,$idEmpresa,$anio,$mes);

    $filename='DIOT_'.$anio.'_'.sprintf('%02d',$mes).'.txt';
    header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    foreach($data as $row){
        $c=diot_fila_54($row);
        // Exactamente 54 campos, separados por |, conservando vacíos.
        echo implode('|', array_values($c))."\r\n";
    }
} catch(Throwable $e){
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    seguridad_log_error($e, 'generar_txt_diot'); echo 'No se pudo generar el archivo DIOT.';
}
