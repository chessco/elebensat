<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/conciliacion_financiera.php';

try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $desde=trim((string)($_POST['desde']??''));
    $hasta=trim((string)($_POST['hasta']??''));
    $desde=cf_fecha($desde); $hasta=cf_fecha($hasta);
    if(!$desde||!$hasta) throw new RuntimeException('Capture fecha desde y hasta.');
    if($desde>$hasta) throw new RuntimeException('La fecha desde no puede ser mayor que la fecha hasta.');

    cf_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS conciliacion_financiera_jobs (
        id_job CHAR(32) NOT NULL PRIMARY KEY,
        id_empresa INT NOT NULL,id_usuario INT NOT NULL,desde DATE NOT NULL,hasta DATE NOT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'PROCESANDO',fase VARCHAR(20) NOT NULL DEFAULT 'FACTURAS',
        total_facturas INT NOT NULL DEFAULT 0,total_gastos INT NOT NULL DEFAULT 0,
        procesadas_facturas INT NOT NULL DEFAULT 0,procesados_gastos INT NOT NULL DEFAULT 0,
        conciliados INT NOT NULL DEFAULT 0,parciales INT NOT NULL DEFAULT 0,diferencias INT NOT NULL DEFAULT 0,
        pendientes INT NOT NULL DEFAULT 0,sin_xml INT NOT NULL DEFAULT 0,sin_uuid INT NOT NULL DEFAULT 0,
        cancelados INT NOT NULL DEFAULT 0,errores INT NOT NULL DEFAULT 0,cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
        ultimo_error TEXT DEFAULT NULL,fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,fecha_fin DATETIME DEFAULT NULL,
        KEY idx_cfjob_empresa_estado (id_empresa,estado,fecha_inicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $q=$pdo->prepare("SELECT COUNT(*) FROM oracle_facturas WHERE id_empresa=? AND invoice_date>=? AND invoice_date<=? AND (invoice_number IS NULL OR invoice_number NOT LIKE 'EXP%')");
    $q->execute([$idEmpresa,$desde,$hasta]);
    $totalFact=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM oracle_gastos WHERE id_empresa=? AND ((creation_date>=? AND creation_date<DATE_ADD(?,INTERVAL 1 DAY)) OR (creation_date IS NULL AND receipt_date>=? AND receipt_date<=?))");
    $q->execute([$idEmpresa,$desde,$hasta,$desde,$hasta]);
    $totalGast=(int)$q->fetchColumn();

    $job=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO conciliacion_financiera_jobs
      (id_job,id_empresa,id_usuario,desde,hasta,estado,fase,total_facturas,total_gastos)
      VALUES (?,?,?,?,?,'PROCESANDO','FACTURAS',?,?)")
      ->execute([$job,$idEmpresa,$idUsuario,$desde,$hasta,$totalFact,$totalGast]);

    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    echo json_encode(['success'=>true,'job'=>$job,'desde'=>$desde,'hasta'=>$hasta,'total_facturas'=>$totalFact,'total_gastos'=>$totalGast],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    seguridad_log_error($e,'conciliar_oracle_pagos_iniciar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
