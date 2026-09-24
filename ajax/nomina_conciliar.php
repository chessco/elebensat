<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

function nc_fecha_valida(string $v): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); }
function nc_rfc(string $v): string { return strtoupper(trim($v)); }
function nc_money($v): float { return round((float)$v, 2); }

try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'nomina_archivo');

    $fechaDesde=trim((string)($_POST['fecha_desde']??''));
    $fechaHasta=trim((string)($_POST['fecha_hasta']??''));
    if(!nc_fecha_valida($fechaDesde) || !nc_fecha_valida($fechaHasta) || $fechaDesde>$fechaHasta) {
        throw new RuntimeException('Capture correctamente el rango de fechas.');
    }

    // Seleccionamos nóminas cuyo periodo real se cruce con el rango solicitado.
    // Esto permite manejar igual una semanal y un acumulado 26-30.
    $whereImp='i.id_empresa=? AND i.fecha_desde<=? AND i.fecha_hasta>=?';
    $paramsImp=[$idEmpresa,$fechaHasta,$fechaDesde];

    $stRango=$pdo->prepare("SELECT COUNT(*) importaciones,
                                   MIN(i.fecha_desde) fecha_desde,
                                   MAX(i.fecha_hasta) fecha_hasta,
                                   MIN(COALESCE(i.periodo_desde,i.periodo)) periodo_desde,
                                   MAX(COALESCE(i.periodo_hasta,i.periodo)) periodo_hasta,
                                   COUNT(DISTINCT i.tipo_nomina) tipos,
                                   MAX(i.tipo_nomina) tipo_nomina
                            FROM nomina_importaciones i WHERE $whereImp");
    $stRango->execute($paramsImp);
    $rango=$stRango->fetch(PDO::FETCH_ASSOC)?:[];
    $importacionesSeleccionadas=(int)($rango['importaciones']??0);
    if($importacionesSeleccionadas===0) throw new RuntimeException('No hay nóminas importadas que correspondan al rango seleccionado.');

    // El rango REAL de conciliación sale de las importaciones encontradas.
    // Ese mismo rango se usa contra FechaInicialPago del CFDI.
    $fechaDesdeReal=(string)$rango['fecha_desde'];
    $fechaHastaReal=(string)$rango['fecha_hasta'];
    $periodoDesde=(int)($rango['periodo_desde']??0) ?: null;
    $periodoHasta=(int)($rango['periodo_hasta']??0) ?: null;
    $tipoNomina=((int)($rango['tipos']??0)===1) ? (string)($rango['tipo_nomina']??'') : 'MIXTA';
    $anio=(int)substr($fechaDesdeReal,0,4);

    // Reporte importado por RFC. Si TOTALPER/TOTALDED vienen informados,
    // además del neto se comparará el desglose completo.
    $sqlRep="SELECT UPPER(TRIM(d.rfc)) rfc,
                    MAX(d.numero_empleado) numero_empleado,
                    MAX(TRIM(CONCAT_WS(' ',NULLIF(d.nombre,''),NULLIF(d.apellido_paterno,''),NULLIF(d.apellido_materno,'')))) nombre_completo,
                    ROUND(SUM(COALESCE(d.total_percepciones,0)),2) percepciones_reporte,
                    ROUND(SUM(COALESCE(d.total_deducciones,0)),2) deducciones_reporte,
                    ROUND(SUM(d.neto),2) total_reporte,
                    MAX(CASE WHEN ABS(COALESCE(d.total_percepciones,0))+ABS(COALESCE(d.total_deducciones,0))>0 THEN 1 ELSE 0 END) usa_desglose,
                    COUNT(DISTINCT d.id_importacion) periodos_reporte
             FROM nomina_detalles d
             INNER JOIN nomina_importaciones i ON i.id_importacion=d.id_importacion
             WHERE $whereImp
             GROUP BY UPPER(TRIM(d.rfc))";
    $st=$pdo->prepare($sqlRep); $st->execute($paramsImp);
    $reporte=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $rfc=nc_rfc((string)$r['rfc']);
        if($rfc!=='') $reporte[$rfc]=$r;
    }

    // CFDI Nómina: se usa FechaInicialPago para identificar el periodo trabajado.
    // Sólo los VIGENTES participan en importes; cancelados se cuentan para diagnóstico.
    $sqlSat="SELECT UPPER(TRIM(r.rfc)) rfc,
                    MAX(r.nombre) nombre_visor,
                    MAX(f.nomina_num_empleado) numero_empleado_visor,
                    ROUND(SUM(CASE WHEN UPPER(COALESCE(f.estatus_sat,'VIGENTE'))='VIGENTE' THEN COALESCE(f.nomina_total_percepciones,0) ELSE 0 END),2) percepciones_visor,
                    ROUND(SUM(CASE WHEN UPPER(COALESCE(f.estatus_sat,'VIGENTE'))='VIGENTE' THEN COALESCE(f.nomina_total_deducciones,0) ELSE 0 END),2) deducciones_visor,
                    ROUND(SUM(CASE WHEN UPPER(COALESCE(f.estatus_sat,'VIGENTE'))='VIGENTE' THEN COALESCE(f.total_xml,0) ELSE 0 END),2) total_visor,
                    SUM(CASE WHEN UPPER(COALESCE(f.estatus_sat,'VIGENTE'))='VIGENTE' THEN 1 ELSE 0 END) documentos_visor,
                    SUM(CASE WHEN UPPER(COALESCE(f.estatus_sat,'VIGENTE'))='CANCELADO' THEN 1 ELSE 0 END) documentos_cancelados
             FROM facturas f
             INNER JOIN cat_receptores r ON r.id_receptor=f.id_receptor
             WHERE f.id_empresa=?
               AND f.id_tipo_comprobante='N'
               AND f.nomina_fecha_inicial_pago BETWEEN ? AND ?
             GROUP BY UPPER(TRIM(r.rfc))";
    $paramsSat=[$idEmpresa,$fechaDesdeReal,$fechaHastaReal];
    $st=$pdo->prepare($sqlSat); $st->execute($paramsSat);
    $visor=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $rfc=nc_rfc((string)$r['rfc']);
        if($rfc!=='') $visor[$rfc]=$r;
    }

    $rfcs=array_values(array_unique(array_merge(array_keys($reporte),array_keys($visor))));
    sort($rfcs,SORT_STRING);
    $filas=[];
    $k=['conciliados'=>0,'diferencias'=>0,'solo_reporte'=>0,'solo_visor'=>0,'cancelados'=>0];
    $totalReporte=0.0; $totalVisor=0.0;
    $pReporte=0.0; $pVisor=0.0; $dReporte=0.0; $dVisor=0.0;

    foreach($rfcs as $rfc){
        $rp=$reporte[$rfc]??null; $sv=$visor[$rfc]??null;
        $usaDesglose=(int)($rp['usa_desglose']??0);
        $pr=nc_money($rp['percepciones_reporte']??0); $pv=nc_money($sv['percepciones_visor']??0);
        $dr=nc_money($rp['deducciones_reporte']??0);   $dv=nc_money($sv['deducciones_visor']??0);
        $tr=nc_money($rp['total_reporte']??0);         $tv=nc_money($sv['total_visor']??0);
        $difP=nc_money($pv-$pr); $difD=nc_money($dv-$dr); $dif=nc_money($tv-$tr);
        $docs=(int)($sv['documentos_visor']??0); $canc=(int)($sv['documentos_cancelados']??0);
        $estatus='DIFERENCIA';
        $cuadraNeto=abs($dif)<=0.01;
        $cuadraDesglose=!$usaDesglose || (abs($difP)<=0.01 && abs($difD)<=0.01);

        if($rp && $sv && $docs===0 && $canc>0){ $estatus='CANCELADO_EN_VISOR'; $k['diferencias']++; }
        elseif($rp && $sv && $cuadraNeto && $cuadraDesglose){ $estatus='CONCILIADO'; $k['conciliados']++; }
        elseif($rp && !$sv){ $estatus='SOLO_REPORTE'; $k['solo_reporte']++; }
        elseif(!$rp && $sv){ $estatus='SOLO_VISOR'; $k['solo_visor']++; }
        else { $k['diferencias']++; }

        if($canc>0) $k['cancelados'] += $canc;
        $totalReporte += $tr; $totalVisor += $tv;
        if($usaDesglose){ $pReporte += $pr; $pVisor += $pv; $dReporte += $dr; $dVisor += $dv; }
        $filas[]=[
            'numero_empleado'=>(string)($rp['numero_empleado']??$sv['numero_empleado_visor']??''),
            'rfc'=>$rfc,'nombre_completo'=>(string)($rp['nombre_completo']??$sv['nombre_visor']??''),
            'usa_desglose'=>$usaDesglose,
            'percepciones_visor'=>$pv,'percepciones_reporte'=>$pr,'diferencia_percepciones'=>$difP,
            'deducciones_visor'=>$dv,'deducciones_reporte'=>$dr,'diferencia_deducciones'=>$difD,
            'total_visor'=>$tv,'total_reporte'=>$tr,'diferencia'=>$dif,
            'documentos_visor'=>$docs,'documentos_cancelados'=>$canc,
            'periodos_reporte'=>(int)($rp['periodos_reporte']??0),'estatus'=>$estatus
        ];
    }

    $totalReporte=nc_money($totalReporte); $totalVisor=nc_money($totalVisor); $difTotal=nc_money($totalVisor-$totalReporte);
    $pReporte=nc_money($pReporte); $pVisor=nc_money($pVisor); $dReporte=nc_money($dReporte); $dVisor=nc_money($dVisor);

    $pdo->beginTransaction();
    $ins=$pdo->prepare("INSERT INTO nomina_conciliaciones
        (id_empresa,anio,modo,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta,tipo_nomina,total_empleados_reporte,total_empleados_visor,total_conciliados,total_diferencias,total_solo_reporte,total_solo_visor,total_cancelados,total_reporte,total_visor,diferencia,id_usuario)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$idEmpresa,$anio,'RANGO',$periodoDesde,$periodoHasta,$fechaDesdeReal,$fechaHastaReal,$tipoNomina,count($reporte),count($visor),$k['conciliados'],$k['diferencias'],$k['solo_reporte'],$k['solo_visor'],$k['cancelados'],$totalReporte,$totalVisor,$difTotal,$idUsuario]);
    $idConc=(int)$pdo->lastInsertId();

    $det=$pdo->prepare("INSERT INTO nomina_conciliacion_detalles
        (id_conciliacion,id_empresa,numero_empleado,rfc,nombre_completo,usa_desglose,percepciones_visor,percepciones_reporte,diferencia_percepciones,deducciones_visor,deducciones_reporte,diferencia_deducciones,total_visor,total_reporte,diferencia,documentos_visor,documentos_cancelados,periodos_reporte,estatus)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach($filas as $f){
        $det->execute([$idConc,$idEmpresa,$f['numero_empleado'],$f['rfc'],$f['nombre_completo'],$f['usa_desglose'],$f['percepciones_visor'],$f['percepciones_reporte'],$f['diferencia_percepciones'],$f['deducciones_visor'],$f['deducciones_reporte'],$f['diferencia_deducciones'],$f['total_visor'],$f['total_reporte'],$f['diferencia'],$f['documentos_visor'],$f['documentos_cancelados'],$f['periodos_reporte'],$f['estatus']]);
    }
    $pdo->commit();

    echo json_encode([
        'success'=>true,'id_conciliacion'=>$idConc,
        'rango'=>['desde'=>$fechaDesdeReal,'hasta'=>$fechaHastaReal,'periodos'=>$importacionesSeleccionadas,'periodo_desde'=>$periodoDesde,'periodo_hasta'=>$periodoHasta,'tipo_nomina'=>$tipoNomina],
        'resumen'=>[
            'empleados_reporte'=>count($reporte),'empleados_visor'=>count($visor),
            'conciliados'=>$k['conciliados'],'diferencias'=>$k['diferencias'],'solo_reporte'=>$k['solo_reporte'],'solo_visor'=>$k['solo_visor'],'cancelados'=>$k['cancelados'],
            'percepciones_reporte'=>$pReporte,'percepciones_visor'=>$pVisor,'diferencia_percepciones'=>nc_money($pVisor-$pReporte),
            'deducciones_reporte'=>$dReporte,'deducciones_visor'=>$dVisor,'diferencia_deducciones'=>nc_money($dVisor-$dReporte),
            'total_reporte'=>$totalReporte,'total_visor'=>$totalVisor,'diferencia'=>$difTotal
        ],
        'data'=>$filas
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($e,'nomina_conciliar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
