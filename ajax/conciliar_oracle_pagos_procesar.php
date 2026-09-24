<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/conciliacion_financiera.php';

function cfjob(PDO $pdo,string $job,int $empresa,int $usuario): array{
    $st=$pdo->prepare("SELECT * FROM conciliacion_financiera_jobs WHERE id_job=? AND id_empresa=? AND id_usuario=? LIMIT 1");
    $st->execute([$job,$empresa,$usuario]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new RuntimeException('Proceso de conciliación no encontrado.');
    return $r;
}

function sumar_estado(array &$inc,string $estado): void{
    switch(strtoupper($estado)){
        case 'CONCILIADO':
        case 'CONCILIADO_SIN_COMPLEMENTO':
        case 'PENSION':
        case 'DEMANDA':
        case 'NO_CONSIDERAR':
        case 'MANUAL':$inc['conciliados']++;break;
        case 'PARCIAL':$inc['parciales']++;break;
        case 'DIFERENCIA':$inc['diferencias']++;break;
        case 'SIN_XML':$inc['sin_xml']++;break;
        case 'SIN_UUID':$inc['sin_uuid']++;break;
        case 'CANCELADO':$inc['cancelados']++;break;
        default:$inc['pendientes']++;break;
    }
}

$job='';
$idUsuario=0;
$idEmpresa=0;
$jobCargado=false;

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $job=trim((string)($_POST['job']??''));
    if($job==='') throw new RuntimeException('Falta proceso.');
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    @set_time_limit(120);
    $j=cfjob($pdo,$job,$idEmpresa,$idUsuario);
    $jobCargado=true;

    if($j['estado']!=='PROCESANDO'){
        echo json_encode(['success'=>true,'job'=>$j,'done'=>true,'estado'=>$j['estado']],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if((int)$j['cancel_requested']===1){
        $pdo->prepare("UPDATE conciliacion_financiera_jobs SET estado='CANCELADO',fecha_fin=NOW(),fecha_ultima_actividad=NOW() WHERE id_job=?")->execute([$job]);
        $j=cfjob($pdo,$job,$idEmpresa,$idUsuario);
        echo json_encode(['success'=>true,'job'=>$j,'done'=>true,'estado'=>'CANCELADO'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $inc=['conciliados'=>0,'parciales'=>0,'diferencias'=>0,'pendientes'=>0,'sin_xml'=>0,'sin_uuid'=>0,'cancelados'=>0,'errores'=>0];
    $errores=[];
    // Lote mayor para reducir viajes AJAX sin arriesgar el timeout de PHP.
    // Si un documento falla, se registra y se sigue con los demás del lote.
    $lote=25;
    $fase=(string)$j['fase'];

    if($fase==='FACTURAS'){
        $offset=(int)$j['procesadas_facturas'];
        // invoice_date usa idx_oracle_factura_periodo. Evitamos UPPER/TRIM sobre invoice_number.
        $st=$pdo->prepare("SELECT *
                             FROM oracle_facturas
                            WHERE id_empresa=?
                              AND invoice_date>=? AND invoice_date<=?
                              AND (invoice_number IS NULL OR invoice_number NOT LIKE 'EXP%')
                            ORDER BY invoice_date,id
                            LIMIT {$lote} OFFSET {$offset}");
        $st->execute([$idEmpresa,$j['desde'],$j['hasta']]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $n=0;

        foreach($rows as $of){
            $jc=cfjob($pdo,$job,$idEmpresa,$idUsuario);
            if((int)$jc['cancel_requested']===1) break;
            try{
                $r=cf_conciliar_oracle_factura($pdo,$idEmpresa,$of,$idUsuario);
                sumar_estado($inc,(string)$r['estado']);
            }catch(Throwable $e){
                $inc['errores']++;
                $errores[]='Factura '.($of['invoice_number']??$of['oracle_invoice_id']??$of['id']).': '.$e->getMessage();
                seguridad_log_error($e,'conciliar_oracle_pagos_factura');
            }
            // El registro se considera revisado aunque haya dado error, para no ciclarse.
            $n++;
        }

        $nuevo=$offset+$n;
        $next=($nuevo>=(int)$j['total_facturas'])?'GASTOS':'FACTURAS';
        $sql="UPDATE conciliacion_financiera_jobs
                 SET procesadas_facturas=?,fase=?,
                     conciliados=conciliados+?,parciales=parciales+?,diferencias=diferencias+?,pendientes=pendientes+?,
                     sin_xml=sin_xml+?,sin_uuid=sin_uuid+?,cancelados=cancelados+?,errores=errores+?,
                     ultimo_error=COALESCE(?,ultimo_error),fecha_ultima_actividad=NOW()
               WHERE id_job=?";
        $pdo->prepare($sql)->execute([
            $nuevo,$next,$inc['conciliados'],$inc['parciales'],$inc['diferencias'],$inc['pendientes'],
            $inc['sin_xml'],$inc['sin_uuid'],$inc['cancelados'],$inc['errores'],
            $errores?substr(implode("\n",$errores),0,4000):null,$job
        ]);

    }elseif($fase==='GASTOS'){
        $offset=(int)$j['procesados_gastos'];
        // Usa idx_oracle_gasto_fecha (id_empresa,creation_date). La rama creation_date IS NULL
        // conserva compatibilidad con registros antiguos y evita DATE()/COALESCE() sobre la columna indexada.
        $st=$pdo->prepare("SELECT *
                             FROM oracle_gastos
                            WHERE id_empresa=?
                              AND (
                                   (creation_date>=? AND creation_date<DATE_ADD(?,INTERVAL 1 DAY))
                                   OR (creation_date IS NULL AND receipt_date>=? AND receipt_date<=?)
                              )
                            ORDER BY creation_date,id
                            LIMIT {$lote} OFFSET {$offset}");
        $st->execute([$idEmpresa,$j['desde'],$j['hasta'],$j['desde'],$j['hasta']]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $n=0;

        foreach($rows as $g){
            $jc=cfjob($pdo,$job,$idEmpresa,$idUsuario);
            if((int)$jc['cancel_requested']===1) break;
            try{
                $r=cf_conciliar_oracle_gasto($pdo,$idEmpresa,$g,$idUsuario);
                sumar_estado($inc,(string)$r['estado']);
            }catch(Throwable $e){
                $inc['errores']++;
                $errores[]='Gasto '.($g['oracle_expense_id']??$g['id']).': '.$e->getMessage();
                seguridad_log_error($e,'conciliar_oracle_pagos_gasto');
            }
            $n++;
        }

        $nuevo=$offset+$n;
        $next=($nuevo>=(int)$j['total_gastos'])?'CIERRE':'GASTOS';
        $sql="UPDATE conciliacion_financiera_jobs
                 SET procesados_gastos=?,fase=?,
                     conciliados=conciliados+?,parciales=parciales+?,diferencias=diferencias+?,pendientes=pendientes+?,
                     sin_xml=sin_xml+?,sin_uuid=sin_uuid+?,cancelados=cancelados+?,errores=errores+?,
                     ultimo_error=COALESCE(?,ultimo_error),fecha_ultima_actividad=NOW()
               WHERE id_job=?";
        $pdo->prepare($sql)->execute([
            $nuevo,$next,$inc['conciliados'],$inc['parciales'],$inc['diferencias'],$inc['pendientes'],
            $inc['sin_xml'],$inc['sin_uuid'],$inc['cancelados'],$inc['errores'],
            $errores?substr(implode("\n",$errores),0,4000):null,$job
        ]);

    }else{
        // Cierre/actualización de encabezados EXP. Si falla, el job queda PROCESANDO
        // para que el usuario pueda decidir si reintenta o cancela.
        cf_actualizar_exp_padres($pdo,$idEmpresa,$j['desde'],$j['hasta']);
        $pdo->prepare("UPDATE conciliacion_financiera_jobs SET estado='TERMINADO',fase='TERMINADO',fecha_fin=NOW(),fecha_ultima_actividad=NOW() WHERE id_job=?")->execute([$job]);
    }

    $j=cfjob($pdo,$job,$idEmpresa,$idUsuario);
    if((int)$j['cancel_requested']===1 && $j['estado']==='PROCESANDO'){
        $pdo->prepare("UPDATE conciliacion_financiera_jobs SET estado='CANCELADO',fecha_fin=NOW(),fecha_ultima_actividad=NOW() WHERE id_job=?")->execute([$job]);
        $j=cfjob($pdo,$job,$idEmpresa,$idUsuario);
    }

    $done=$j['estado']!=='PROCESANDO';
    echo json_encode([
        'success'=>true,
        'job'=>$j,
        'done'=>$done,
        'estado'=>$j['estado'],
        'had_errors'=>count($errores)>0,
        'batch_errors'=>$errores
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'conciliar_oracle_pagos_procesar');

    // Si el job ya estaba validado, NO se mata todo el proceso. Se registra la
    // incidencia y el frontend ofrece Continuar/Reintentar o Cancelar.
    if($jobCargado && $job!=='' && $idEmpresa>0 && $idUsuario>0){
        try{
            $pdo->prepare("UPDATE conciliacion_financiera_jobs
                              SET errores=errores+1,ultimo_error=?,fecha_ultima_actividad=NOW()
                            WHERE id_job=? AND id_empresa=? AND id_usuario=? AND estado='PROCESANDO'")
                ->execute([substr($e->getMessage(),0,4000),$job,$idEmpresa,$idUsuario]);
            $j=cfjob($pdo,$job,$idEmpresa,$idUsuario);
            echo json_encode([
                'success'=>true,
                'recoverable_error'=>true,
                'had_errors'=>true,
                'batch_errors'=>[$e->getMessage()],
                'error'=>$e->getMessage(),
                'job'=>$j,
                'done'=>false,
                'estado'=>$j['estado']
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }catch(Throwable $x){
            seguridad_log_error($x,'conciliar_oracle_pagos_procesar_recovery');
        }
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
