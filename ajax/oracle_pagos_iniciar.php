<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/oracle_pagos_rest.php';

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $anio=(int)($_POST['anio']??0);
    $mes=(int)($_POST['mes']??0);
    if($anio<2000||$anio>2100||$mes<1||$mes>12) throw new RuntimeException('Mes o año inválido.');

    // El usuario puede importar todo el mes o un rango parcial, pero siempre
    // dentro del mes/año seleccionado.
    $desde=trim((string)($_POST['desde']??''));
    $hasta=trim((string)($_POST['hasta']??''));

    $inicioMes=sprintf('%04d-%02d-01',$anio,$mes);
    $finMes=date('Y-m-t',strtotime($inicioMes));

    if($desde==='') $desde=$inicioMes;
    if($hasta==='') $hasta=$finMes;

    $dDesde=DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
    $dHasta=DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
    if(!$dDesde || $dDesde->format('Y-m-d')!==$desde) throw new RuntimeException('Fecha DESDE inválida.');
    if(!$dHasta || $dHasta->format('Y-m-d')!==$hasta) throw new RuntimeException('Fecha HASTA inválida.');
    if($desde>$hasta) throw new RuntimeException('La fecha DESDE no puede ser mayor que HASTA.');
    if($desde<$inicioMes || $desde>$finMes || $hasta<$inicioMes || $hasta>$finMes){
        throw new RuntimeException('El rango de importación debe permanecer dentro del mes seleccionado.');
    }

    $cfg=oracle_pagos_config($pdo,$idEmpresa);
    $base=oracle_pagos_api_base($cfg);
    $unidad=(string)$cfg['unidad_negocio'];

    // TEMPORAL / solicitado: la división Granos se identifica por RFC fijo y
    // conserva la consulta histórica de /expenses. Cualquier otra empresa
    // configurada en este módulo se trata como división Molinos.
    $RFC_DIVISION_GRANOS='FSO0311035U8';
    $stEmp=$pdo->prepare('SELECT UPPER(TRIM(rfc)) FROM empresas WHERE id_empresa=? LIMIT 1');
    $stEmp->execute([$idEmpresa]);
    $rfcEmpresa=(string)$stEmp->fetchColumn();
    $esGranos=($rfcEmpresa===$RFC_DIVISION_GRANOS);

    $q1="BusinessUnit={$unidad};InvoiceDate>={$desde} and <= {$hasta}";
    $u1=$base.'/invoices?'.http_build_query([
        'q'=>$q1,'limit'=>1,'offset'=>0,'totalResults'=>'true'
    ],'','&',PHP_QUERY_RFC3986);
    $r1=oracle_pagos_ok(oracle_pagos_get($cfg,$u1),'Invoices');

    $totalFact=oracle_pagos_total($r1);
    $totalGast=null;

    if($esGranos){
        // GRanos / Ferropuerto: lógica original que ya está probada.
        $q2="BusinessUnit={$unidad};CreationDate>={$desde}T00:00:00 and <= {$hasta}T23:59:59";
        $u2=$base.'/expenses?'.http_build_query([
            'q'=>$q2,'limit'=>1,'offset'=>0,'totalResults'=>'true'
        ],'','&',PHP_QUERY_RFC3986);
        $r2=oracle_pagos_ok(oracle_pagos_get($cfg,$u2),'Expenses');
        $totalGast=oracle_pagos_total($r2);
    }
    // Molinos: el total de movimientos se conoce al recorrer las invoiceLines
    // de las reposiciones EXP, por eso se deja NULL al iniciar.

    // Si Oracle no entrega totalResults, dejamos NULL. El meter seguirá mostrando
    // procesados y barra animada hasta conocer el fin por hasMore.
    $job=bin2hex(random_bytes(16));

    $pdo->prepare(
        'INSERT INTO oracle_pagos_sync_jobs
            (id_job,id_empresa,id_usuario,anio,mes,desde,hasta,estado,fase,total_facturas,total_gastos)
         VALUES (?,?,?,?,?,?,?,"PROCESANDO","FACTURAS",?,?)'
    )->execute([$job,$idEmpresa,$idUsuario,$anio,$mes,$desde,$hasta,$totalFact,$totalGast]);

    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    echo json_encode([
        'success'=>true,
        'job'=>$job,
        'unidad'=>$unidad,
        'desde'=>$desde,
        'hasta'=>$hasta,
        'total_facturas'=>$totalFact,
        'total_gastos'=>$totalGast,
        'base_url'=>oracle_pagos_base_url(),
        'division_oracle'=>$esGranos?'GRANOS':'MOLINOS'
    ],JSON_UNESCAPED_UNICODE);

}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_iniciar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
