<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
        http_response_code(401);
        throw new RuntimeException('Sesión no válida.');
    }
    exigir_permiso_accion($pdo,(int)$_SESSION['id_usuario'],(int)$_SESSION['id_empresa'],'ver_solicitudes_sat');

    $idEmpresa=(int)$_SESSION['id_empresa'];
    $estado=strtoupper(trim((string)($_GET['estado']??'')));
    $buscar=trim((string)($_GET['buscar']??''));

    $sql="SELECT n.id,n.uuid,n.tipo_alerta,n.estatus_anterior,n.estatus_nuevo,
                 n.fecha_documento,n.fecha_cancelacion_sat,n.fecha_deteccion,n.fecha_notificacion,
                 n.estatus_notificacion,n.fecha_visto,n.fecha_atendido,n.observaciones,
                 COALESCE(uv.nombre_real,uv.usuario,'') AS usuario_visto,
                 COALESCE(ua.nombre_real,ua.usuario,'') AS usuario_atendio,
                 m.tipo,m.rfc_emisor,m.nombre_emisor,m.rfc_receptor,m.nombre_receptor,m.monto,m.efecto_comprobante,
                 f.serie,f.folio,f.fecha_emision AS fecha_factura,f.estatus_sat AS estatus_factura,
                 f.fecha_cancelacion AS fecha_cancelacion_factura,f.fecha_aplicacion_fiscal,
                 COALESCE(f.ya_pago,0) AS ya_pago,COALESCE(f.esta_conciliado,0) AS esta_conciliado,
                 COALESCE(f.tiene_complemento_pagos,0) AS tiene_complemento_pagos,
                 COALESCE(f.excluir_diot,0) AS excluir_diot,
                 CASE WHEN f.uuid IS NULL THEN 0 ELSE 1 END AS xml_en_visor
          FROM notificaciones_sat n
          LEFT JOIN sat_metadata_cfdi m ON m.id_empresa=n.id_empresa AND m.uuid=n.uuid
          LEFT JOIN facturas f ON f.id_empresa=n.id_empresa AND f.uuid=n.uuid
          LEFT JOIN usuarios uv ON uv.id_usuario=n.id_usuario_visto
          LEFT JOIN usuarios ua ON ua.id_usuario=n.id_usuario_atendio
          WHERE n.id_empresa=:empresa";
    $params=[':empresa'=>$idEmpresa];
    if (in_array($estado,['NUEVA','VISTA','ATENDIDA'],true)) {
        $sql.=" AND n.estatus_notificacion=:estado";
        $params[':estado']=$estado;
    }
    if ($buscar!=='') {
        $sql.=" AND (n.uuid LIKE :buscar OR m.rfc_emisor LIKE :buscar OR m.nombre_emisor LIKE :buscar OR m.rfc_receptor LIKE :buscar OR m.nombre_receptor LIKE :buscar OR f.serie LIKE :buscar OR f.folio LIKE :buscar)";
        $params[':buscar']='%'.$buscar.'%';
    }
    $sql.=" ORDER BY CASE n.estatus_notificacion WHEN 'NUEVA' THEN 0 WHEN 'VISTA' THEN 1 ELSE 2 END, n.fecha_deteccion DESC, n.id DESC";
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);

    $fmt=function($v,$conHora=false){if(!$v)return ''; $t=strtotime($v); return $t?date($conHora?'d/m/Y H:i:s':'d/m/Y',$t):'';};
    $data=[];
    foreach($rows as $r){
        $procesos=[];
        if(!(int)$r['xml_en_visor']){
            $procesos[]=['texto'=>'XML faltante','tipo'=>'danger'];
        } else {
            $procesos[]=['texto'=>'En visor','tipo'=>'success'];
            if(!empty($r['fecha_aplicacion_fiscal']) && !(int)$r['excluir_diot']) $procesos[]=['texto'=>'DIOT '.$fmt($r['fecha_aplicacion_fiscal']),'tipo'=>'warning'];
            if((int)$r['ya_pago']===1) $procesos[]=['texto'=>'Pagada','tipo'=>'info'];
            if((int)$r['esta_conciliado']===1) $procesos[]=['texto'=>'Conciliada','tipo'=>'primary'];
            if((int)$r['tiene_complemento_pagos']===1) $procesos[]=['texto'=>'Con complemento','tipo'=>'secondary'];
            if((int)$r['excluir_diot']===1) $procesos[]=['texto'=>'Excluida DIOT','tipo'=>'secondary'];
        }
        $factura=trim((string)($r['serie']??''));
        $folio=trim((string)($r['folio']??''));
        $factura=trim($factura.' '.$folio);
        if($factura==='') $factura='S/F';
        $contraparte=(strtolower((string)($r['tipo']??''))==='emitidos')
            ? trim((string)($r['nombre_receptor']??''))
            : trim((string)($r['nombre_emisor']??''));
        $data[]=[
            'id'=>(int)$r['id'],'uuid'=>$r['uuid'],'factura'=>$factura,'tipo'=>ucfirst((string)($r['tipo']??'')),
            'fecha_documento'=>$fmt($r['fecha_factura']?:$r['fecha_documento']),
            'fecha_cancelacion'=>$fmt($r['fecha_cancelacion_factura']?:$r['fecha_cancelacion_sat'],true),
            'fecha_aviso'=>$fmt($r['fecha_notificacion'],true),'estado'=>$r['estatus_notificacion'],
            'contraparte'=>$contraparte,'rfc_emisor'=>$r['rfc_emisor'],'rfc_receptor'=>$r['rfc_receptor'],
            'monto'=>$r['monto']!==null?(float)$r['monto']:null,'procesos'=>$procesos,
            'fecha_visto'=>$fmt($r['fecha_visto'],true),'usuario_visto'=>$r['usuario_visto'],
            'fecha_atendido'=>$fmt($r['fecha_atendido'],true),'usuario_atendio'=>$r['usuario_atendio'],
            'observaciones'=>$r['observaciones']??''
        ];
    }

    $c=$pdo->prepare("SELECT estatus_notificacion,COUNT(*) total FROM notificaciones_sat WHERE id_empresa=? GROUP BY estatus_notificacion");
    $c->execute([$idEmpresa]);$res=['NUEVA'=>0,'VISTA'=>0,'ATENDIDA'=>0];
    foreach($c->fetchAll(PDO::FETCH_ASSOC) as $x)$res[strtoupper($x['estatus_notificacion'])]=(int)$x['total'];
    echo json_encode(['success'=>true,'data'=>$data,'resumen'=>$res],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e){
    seguridad_log_error($e, 'listar_notificaciones_sat'); echo json_encode(['success'=>false,'data'=>[],'resumen'=>['NUEVA'=>0,'VISTA'=>0,'ATENDIDA'=>0],'error'=>'No se pudieron consultar las notificaciones.'],JSON_UNESCAPED_UNICODE);
}
