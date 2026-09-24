<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/sat_69b_alertas.php';

try {
    seguridad_exigir_sesion($pdo,true); exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69b');
    sat69b_crear_tabla_alertas($pdo);

    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    if($idEmpresa<=0) throw new RuntimeException('No hay empresa activa en la sesión.');

    $situacion=trim((string)($_GET['situacion']??''));
    $seg=strtoupper(trim((string)($_GET['seguimiento']??'')));
    $solo=(int)($_GET['solo_riesgo']??1)===1;

    $w=['a.activa=1','a.id_empresa=?']; $pa=[$idEmpresa];
    if($situacion!==''){$w[]='a.situacion=?';$pa[]=$situacion;}
    if(in_array($seg,['NUEVA','VISTA','ATENDIDA'],true)){$w[]='a.estatus_seguimiento=?';$pa[]=$seg;}
    if($solo)$w[]="a.situacion IN ('Presunto','Definitivo')";

    $sql="SELECT a.id_emisor,a.id_empresa,e.razon_social empresa,a.rfc,a.nombre_emisor,
                 a.situacion,a.fecha_publicacion_relevante,a.estatus_seguimiento,
                 a.fecha_primera_deteccion,a.fecha_ultima_deteccion
            FROM sat_69b_alertas_emisores a
            INNER JOIN empresas e ON e.id_empresa=a.id_empresa
           WHERE ".implode(' AND ',$w)."
           ORDER BY a.id_emisor,a.id_alerta";
    $st=$pdo->prepare($sql);$st->execute($pa);$rows=$st->fetchAll(PDO::FETCH_ASSOC);

    $gr=[];
    foreach($rows as $r){
        $k=(int)$r['id_emisor'];
        if(!isset($gr[$k]))$gr[$k]=[
            'id_emisor'=>$k,'id_empresa'=>$idEmpresa,'empresa'=>$r['empresa'],'rfc'=>$r['rfc'],'nombre_emisor'=>$r['nombre_emisor'],
            '_sit'=>[],'_fec'=>[],'_seg'=>[],'primera_deteccion'=>$r['fecha_primera_deteccion'],'ultima_revision'=>$r['fecha_ultima_deteccion']
        ];
        $gr[$k]['_sit'][(string)$r['situacion']]=true;
        if(!empty($r['fecha_publicacion_relevante']))$gr[$k]['_fec'][(string)$r['fecha_publicacion_relevante']]=true;
        $gr[$k]['_seg'][]=strtoupper((string)$r['estatus_seguimiento']);
        if(!empty($r['fecha_primera_deteccion'])&&$r['fecha_primera_deteccion']<$gr[$k]['primera_deteccion'])$gr[$k]['primera_deteccion']=$r['fecha_primera_deteccion'];
        if(!empty($r['fecha_ultima_deteccion'])&&$r['fecha_ultima_deteccion']>$gr[$k]['ultima_revision'])$gr[$k]['ultima_revision']=$r['fecha_ultima_deteccion'];
    }

    $tot=$pdo->prepare("SELECT COUNT(*) cfdi,COALESCE(SUM(total_xml),0) total FROM facturas WHERE id_emisor=? AND id_empresa=?");
    $data=[];$res=['nuevas'=>0,'vista'=>0,'atendidas'=>0,'riesgo'=>0,'total'=>0];
    $orden=['Definitivo'=>1,'Presunto'=>2,'Desvirtuado'=>3,'Sentencia Favorable'=>4];
    foreach($gr as $g){
        $sit=array_keys($g['_sit']);usort($sit,fn($a,$b)=>($orden[$a]??9)<=>($orden[$b]??9));
        $fec=array_keys($g['_fec']);sort($fec,SORT_STRING);
        $seguimiento=in_array('NUEVA',$g['_seg'],true)?'NUEVA':(in_array('VISTA',$g['_seg'],true)?'VISTA':'ATENDIDA');
        $tot->execute([$g['id_emisor'],$idEmpresa]);$x=$tot->fetch(PDO::FETCH_ASSOC)?:['cfdi'=>0,'total'=>0];
        $data[]=[
            'id_emisor'=>$g['id_emisor'],'id_empresa'=>$idEmpresa,'empresa'=>$g['empresa'],'rfc'=>$g['rfc'],'nombre_emisor'=>$g['nombre_emisor'],
            'situaciones'=>implode(' | ',$sit),'fechas_publicacion'=>implode(' | ',$fec),'seguimiento'=>$seguimiento,
            'primera_deteccion'=>$g['primera_deteccion'],'ultima_revision'=>$g['ultima_revision'],
            'cfdi'=>(int)$x['cfdi'],'total_cfdi'=>(float)$x['total']
        ];
        if($seguimiento==='NUEVA')$res['nuevas']++;elseif($seguimiento==='VISTA')$res['vista']++;else$res['atendidas']++;
        if(in_array('Definitivo',$sit,true)||in_array('Presunto',$sit,true))$res['riesgo']++;
    }
    $res['total']=count($data);
    usort($data,function($a,$b){$o=['NUEVA'=>1,'VISTA'=>2,'ATENDIDA'=>3];$c=($o[$a['seguimiento']]??9)<=>($o[$b['seguimiento']]??9);return $c?:strcasecmp($a['nombre_emisor'],$b['nombre_emisor']);});
    echo json_encode(['success'=>true,'data'=>$data,'resumen'=>$res],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    seguridad_log_error($e,'listar_alertas_emisores_69b');http_response_code(500);
    echo json_encode(['success'=>false,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
