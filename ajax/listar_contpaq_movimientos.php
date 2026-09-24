<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/csrf.php';

try {
    $u=seguridad_exigir_sesion($pdo,true);
    $idUsuario=(int)$u['id_usuario'];
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'contpaq_cheques');
    if(!csrf_validar($_POST['csrf_token']??null)) throw new RuntimeException('Token de seguridad inválido.');

    $tipo=strtoupper(trim((string)($_POST['tipo']??'C')))==='T'?'T':'C';
    $draw=(int)($_POST['draw']??0);
    $start=max(0,(int)($_POST['start']??0));
    $length=max(10,min(200,(int)($_POST['length']??25)));
    $search=trim((string)($_POST['search']['value']??''));
    $desde=trim((string)($_POST['fecha_desde']??''));
    $hasta=trim((string)($_POST['fecha_hasta']??''));

    $isCheque=$tipo==='C';
    $t=$isCheque?'contpaq_cheques':'contpaq_egresos';
    $a=$isCheque?'c':'e';
    $idCol=$isCheque?'id_contpaq_cheque':'id_contpaq_egreso';
    $join=$isCheque
        ? "LEFT JOIN contpaq_dispersiones_pagos d ON d.id_empresa=c.id_empresa AND d.tipo_origen='C' AND d.id_contpaq_cheque=c.id_contpaq_cheque"
        : "LEFT JOIN contpaq_dispersiones_pagos d ON d.id_empresa=e.id_empresa AND d.tipo_origen='T' AND d.id_contpaq_egreso=e.id_contpaq_egreso";

    $where=["$a.id_empresa=?"];
    $params=[$idEmpresa];
    if($desde!==''){ $where[]="$a.fecha>=?"; $params[]=$desde.' 00:00:00'; }
    if($hasta!==''){ $where[]="$a.fecha<=?"; $params[]=$hasta.' 23:59:59'; }
    if($search!==''){
        $where[]="(CAST($a.$idCol AS CHAR) LIKE ? OR $a.folio LIKE ? OR CAST($a.codigo_persona AS CHAR) LIKE ? OR $a.beneficiario_pagador LIKE ? OR CAST($a.total AS CHAR) LIKE ?)";
        $like='%'.$search.'%';
        array_push($params,$like,$like,$like,$like,$like);
    }
    $whereSql=implode(' AND ',$where);

    $st=$pdo->prepare("SELECT COUNT(*) FROM $t $a WHERE $a.id_empresa=?");
    $st->execute([$idEmpresa]);
    $recordsTotal=(int)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COUNT(*) FROM $t $a WHERE $whereSql");
    $st->execute($params);
    $recordsFiltered=(int)$st->fetchColumn();

    $orderMap=[0=>"$a.$idCol",1=>"$a.folio",2=>"$a.fecha",3=>"$a.codigo_persona",4=>"$a.beneficiario_pagador",5=>"$a.total",9=>"$a.fecha_ultima_sync"];
    $oi=(int)($_POST['order'][0]['column']??2);
    $dir=strtolower((string)($_POST['order'][0]['dir']??'asc'))==='desc'?'DESC':'ASC';
    $order=$orderMap[$oi]??"$a.fecha";

    $sql="SELECT $a.$idCol id_origen,$a.folio,$a.fecha,$a.codigo_persona,$a.beneficiario_pagador,
                 $a.total,$a.es_cancelado,$a.fecha_ultima_sync,
                 COUNT(d.id) dispersiones,
                 SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,'')) IN ('CONCILIADO','YA_CONCILIADO') THEN 1 ELSE 0 END) dispersiones_conciliadas,
                 SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,''))='PARCIAL' THEN 1 ELSE 0 END) dispersiones_parciales,
                 SUM(CASE WHEN UPPER(COALESCE(d.estatus_conciliacion,''))='ERROR' THEN 1 ELSE 0 END) dispersiones_error
            FROM $t $a $join
           WHERE $whereSql
           GROUP BY $a.id,$a.$idCol,$a.folio,$a.fecha,$a.codigo_persona,$a.beneficiario_pagador,$a.total,$a.es_cancelado,$a.fecha_ultima_sync
           ORDER BY $order $dir,$a.$idCol $dir
           LIMIT $start,$length";
    $st=$pdo->prepare($sql);
    $st->execute($params);
    $rows=[];
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $cancel=(int)$r['es_cancelado']===1;
        $total=(int)$r['dispersiones']; $conc=(int)$r['dispersiones_conciliadas'];
        $par=(int)$r['dispersiones_parciales']; $err=(int)$r['dispersiones_error'];
        if($cancel){$estado='CANCELADO';$color='danger';}
        elseif($total<=0){$estado='SIN DISPERSIÓN';$color='secondary';}
        elseif($err>0){$estado='ERROR';$color='danger';}
        elseif($par>0||($conc>0&&$conc<$total)){$estado='PARCIAL';$color='warning text-dark';}
        elseif($conc===$total){$estado='CONCILIADO';$color='success';}
        else{$estado='NO CONCILIADO';$color='secondary';}
        $rows[]=[
            'id_origen'=>(int)$r['id_origen'],'folio'=>(string)$r['folio'],'fecha'=>(string)$r['fecha'],
            'codigo_persona'=>(string)$r['codigo_persona'],'beneficiario_pagador'=>(string)$r['beneficiario_pagador'],
            'total'=>number_format((float)$r['total'],2),'fecha_ultima_sync'=>(string)$r['fecha_ultima_sync'],
            'cancelado_html'=>$cancel?'<span class="badge bg-danger">SÍ</span>':'<span class="badge bg-success">NO</span>',
            'dispersiones_html'=>$total>0?'<span class="badge bg-info text-dark">'.$total.'</span>':'0',
            'conciliacion_html'=>'<span class="badge bg-'.$color.'">'.$estado.'</span>'
        ];
    }
    echo json_encode(['draw'=>$draw,'recordsTotal'=>$recordsTotal,'recordsFiltered'=>$recordsFiltered,'data'=>$rows],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['draw'=>(int)($_POST['draw']??0),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
