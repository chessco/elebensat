<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/sat_69_alertas.php';

try {
    seguridad_exigir_sesion($pdo, true);
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69');
    sat69_crear_tabla_alertas($pdo);

    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa en la sesión.');

    $tipo = trim((string)($_GET['tipo'] ?? ''));
    $seg  = strtoupper(trim((string)($_GET['seguimiento'] ?? '')));
    $solo = (int)($_GET['solo_riesgo'] ?? 1) === 1;

    $where = ['a.activa=1', 'a.id_empresa=?'];
    $params = [$idEmpresa];

    if ($tipo !== '') { $where[]='a.tipo_publicacion=?'; $params[]=$tipo; }
    if (in_array($seg,['NUEVA','VISTA','ATENDIDA'],true)) { $where[]='a.estatus_seguimiento=?'; $params[]=$seg; }
    if ($solo) $where[]="a.nivel_riesgo IN ('ALTO','MEDIO')";

    $sql = "SELECT a.id_emisor,a.id_empresa,e.razon_social empresa,a.rfc,a.nombre_emisor,
                   a.tipo_publicacion,a.nivel_riesgo,a.estatus_seguimiento,a.fecha_primera_deteccion
              FROM sat_69_alertas_emisores a
              INNER JOIN empresas e ON e.id_empresa=a.id_empresa
             WHERE ".implode(' AND ',$where)."
             ORDER BY a.id_emisor,a.id_alerta";

    $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    $grupos=[];
    foreach($rows as $r){
        $k=(int)$r['id_emisor'];
        if(!isset($grupos[$k])) $grupos[$k]=[
            'id_emisor'=>(int)$r['id_emisor'],'id_empresa'=>$idEmpresa,'empresa'=>$r['empresa'],
            'rfc'=>$r['rfc'],'nombre_emisor'=>$r['nombre_emisor'],
            '_pub'=>[],'_niv'=>[],'_seg'=>[],'primera_deteccion'=>$r['fecha_primera_deteccion']
        ];
        $grupos[$k]['_pub'][(string)$r['tipo_publicacion']]=true;
        $grupos[$k]['_niv'][(string)$r['nivel_riesgo']]=true;
        $grupos[$k]['_seg'][]=strtoupper((string)$r['estatus_seguimiento']);
        if(!empty($r['fecha_primera_deteccion']) && $r['fecha_primera_deteccion']<$grupos[$k]['primera_deteccion'])
            $grupos[$k]['primera_deteccion']=$r['fecha_primera_deteccion'];
    }

    $tot=$pdo->prepare("SELECT COUNT(*) cfdi,COALESCE(SUM(total_xml),0) total FROM facturas WHERE id_emisor=? AND id_empresa=?");
    $data=[]; $res=['nuevas'=>0,'vista'=>0,'atendidas'=>0,'riesgo'=>0,'alto'=>0];
    foreach($grupos as $g){
        $pub=array_keys($g['_pub']); sort($pub,SORT_NATURAL|SORT_FLAG_CASE);
        $niv=array_keys($g['_niv']); usort($niv,fn($a,$b)=>(['ALTO'=>1,'MEDIO'=>2,'INFORMATIVO'=>3][$a]??9)<=>(['ALTO'=>1,'MEDIO'=>2,'INFORMATIVO'=>3][$b]??9));
        $seguimiento=in_array('NUEVA',$g['_seg'],true)?'NUEVA':(in_array('VISTA',$g['_seg'],true)?'VISTA':'ATENDIDA');
        $tot->execute([$g['id_emisor'],$idEmpresa]); $x=$tot->fetch(PDO::FETCH_ASSOC)?:['cfdi'=>0,'total'=>0];

        $r=[
            'id_emisor'=>$g['id_emisor'],'id_empresa'=>$idEmpresa,'empresa'=>$g['empresa'],'rfc'=>$g['rfc'],
            'nombre_emisor'=>$g['nombre_emisor'],'publicaciones'=>implode(' | ',$pub),'niveles'=>implode(' | ',$niv),
            'seguimiento'=>$seguimiento,'primera_deteccion'=>$g['primera_deteccion'],
            'cfdi'=>(int)$x['cfdi'],'total_cfdi'=>(float)$x['total']
        ];
        $data[]=$r;
        if($seguimiento==='NUEVA')$res['nuevas']++; elseif($seguimiento==='VISTA')$res['vista']++; else$res['atendidas']++;
        if(in_array('ALTO',$niv,true)||in_array('MEDIO',$niv,true))$res['riesgo']++;
        if(in_array('ALTO',$niv,true))$res['alto']++;
    }

    usort($data,function($a,$b){$o=['NUEVA'=>1,'VISTA'=>2,'ATENDIDA'=>3];$c=($o[$a['seguimiento']]??9)<=>($o[$b['seguimiento']]??9);return $c?:strcasecmp($a['nombre_emisor'],$b['nombre_emisor']);});
    echo json_encode(['success'=>true,'data'=>$data,'resumen'=>$res],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

} catch(Throwable $e){
    seguridad_log_error($e,'listar_alertas_emisores_69');
    http_response_code(500);
    echo json_encode(['success'=>false,'data'=>[],'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
