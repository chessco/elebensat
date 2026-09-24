<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/csrf.php';
require_once '../includes/conciliacion_contpaq_pagos.php';

try {
    @set_time_limit(90);
    $u=seguridad_exigir_sesion($pdo,true);
    $idUsuario=(int)$u['id_usuario'];
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'contpaq_cheques');
    if(!csrf_validar($_POST['csrf_token']??null)) throw new RuntimeException('Token de seguridad inválido. Recargue la pantalla.');
    if($idEmpresa<=0) throw new RuntimeException('No hay empresa activa.');

    $accion=(string)($_POST['accion']??'init');
    $periodo=trim((string)($_POST['periodo']??''));
    [$desde,$hasta]=cp_periodo_limites($periodo);

    if($accion==='init'){
        // Inicio ligero: solo valida estructura y cuenta el periodo.
        // Las cancelaciones/reversiones se revisan por dispersión durante cada paso,
        // evitando recorrer todas las conciliaciones antes de mostrar el primer avance.
        $tInit=microtime(true);
        cp_schema_conciliacion($pdo);
        $tSchema=microtime(true);
        $total=cp_total_pendiente_periodo($pdo,$idEmpresa,$desde,$hasta);
        $tTotal=microtime(true);
        echo json_encode([
            'success'=>true,'periodo'=>$periodo,'desde'=>$desde,'hasta'=>$hasta,
            'total'=>$total,
            'canceladas_retiradas'=>0,
            'movimientos_cancelados_retirados'=>0,
            'cfdi_cancelados_retirados'=>0,
            'facturas_canceladas_detectadas'=>0,
            'rep_cancelados_detectados'=>0,
            'tiempos_preparacion'=>[
                'validar_estructura'=>round($tSchema-$tInit,6),
                'contar_dispersiones'=>round($tTotal-$tSchema,6),
                'total'=>round($tTotal-$tInit,6)
            ]
        ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if($accion==='step'){
        $cursor=max(0,(int)($_POST['cursor']??0));
        $inicio=microtime(true);
        $inicioTexto=date('Y-m-d H:i:s');
        try {
            $pdo->beginTransaction();
            $r=cp_conciliar_periodo_bloque($pdo,$idEmpresa,$idUsuario,$desde,$hasta,$cursor,1);
            $pdo->commit();
        } catch (Throwable $pasoError) {
            if($pdo->inTransaction()) $pdo->rollBack();
            $st=$pdo->prepare("SELECT id_contpaq_dispersion,uuid,uuid_rep,fecha_pago,total_pago,total_pago_comprobante
                                FROM contpaq_dispersiones_pagos
                               WHERE id_empresa=? AND fecha_pago>=? AND fecha_pago<DATE_ADD(?, INTERVAL 1 DAY)
                                 AND id_contpaq_dispersion>?
                               ORDER BY id_contpaq_dispersion LIMIT 1");
            $st->execute([$idEmpresa,$desde,$hasta,$cursor]);
            $d=$st->fetch(PDO::FETCH_ASSOC);
            if(!$d){
                $r=['done'=>true,'cursor'=>$cursor,'procesadas'=>0,'revisadas'=>0,'creadas'=>0,'parciales'=>0,
                    'sin_coincidencia'=>0,'ya_conciliadas'=>0,'facturas'=>0,'errores'=>0,'canceladas'=>0,
                    'resultado'=>'FINALIZADO','mensaje'=>'Sin dispersiones pendientes.','tiempos'=>[]];
            }else{
                $idDisp=(int)$d['id_contpaq_dispersion'];
                cp_marcar_dispersion_estado($pdo,$idEmpresa,$idDisp,'ERROR',mb_substr($pasoError->getMessage(),0,1000),$idUsuario,0);
                seguridad_log_error($pasoError,'conciliar_contpaq_pagos_dispersion_'.$idDisp);
                $r=['done'=>false,'cursor'=>$idDisp,'procesadas'=>1,'revisadas'=>1,'creadas'=>0,'parciales'=>0,
                    'sin_coincidencia'=>1,'ya_conciliadas'=>0,'facturas'=>0,'errores'=>1,'canceladas'=>0,
                    'resultado'=>'ERROR','mensaje'=>$pasoError->getMessage(),'id_dispersion'=>$idDisp,'uuid'=>$d['uuid'],
                    'uuid_rep'=>$d['uuid_rep'],'fecha_pago'=>cp_fecha($d['fecha_pago']),
                    'importe'=>(float)($d['total_pago'] ?: $d['total_pago_comprobante']),
                    'tiempos'=>['error'=>round(microtime(true)-$inicio,6)]];
            }
        }
        $r['hora_inicio']=$inicioTexto;
        $r['hora_fin']=date('Y-m-d H:i:s');
        $r['segundos_peticion']=round(microtime(true)-$inicio,6);
        echo json_encode(['success'=>true,'periodo'=>$periodo]+$r,
            JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    throw new RuntimeException('Acción de conciliación no reconocida.');
} catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    http_response_code(500);
    seguridad_log_error($e,'conciliar_contpaq_pagos');
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
