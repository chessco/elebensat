<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales');

try {
    $draw = (int)($_GET['draw'] ?? 1);
    $start = max(0,(int)($_GET['start'] ?? 0));
    $length = min(200,max(10,(int)($_GET['length'] ?? 25)));
    $search = trim((string)($_GET['search']['value'] ?? ''));
    $iva = trim((string)($_GET['iva'] ?? ''));
    $ieps = trim((string)($_GET['ieps'] ?? ''));

    $where=[]; $params=[];
    if ($search !== '') {
        $where[]='(clave_prod_serv LIKE :q1 OR descripcion LIKE :q2)';
        $like='%'.$search.'%'; $params[':q1']=$like; $params[':q2']=$like;
    }
    if ($iva !== '') { $where[]='incluir_iva_trasladado = :iva'; $params[':iva']=$iva; }
    if ($ieps !== '') { $where[]='incluir_ieps_trasladado = :ieps'; $params[':ieps']=$ieps; }
    $w=$where?' WHERE '.implode(' AND ',$where):'';

    $resumen=$pdo->query("SELECT COUNT(*) total,
        SUM(UPPER(COALESCE(incluir_iva_trasladado,'')) NOT IN ('','NO')) con_iva,
        SUM(UPPER(COALESCE(incluir_ieps_trasladado,'')) NOT IN ('','NO')) con_ieps,
        SUM(fecha_fin_vigencia IS NULL OR fecha_fin_vigencia >= CURDATE()) vigentes
        FROM sat_productos_servicios")->fetch(PDO::FETCH_ASSOC)?:[];
    $catalogo=$pdo->query("SELECT revision_catalogo,fecha_publicacion,fecha_sincronizacion,archivo_origen
        FROM sat_productos_servicios ORDER BY fecha_sincronizacion DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];

    $total=(int)($resumen['total']??0);
    $st=$pdo->prepare('SELECT COUNT(*) FROM sat_productos_servicios'.$w); $st->execute($params); $filtered=(int)$st->fetchColumn();
    $orderMap=[0=>'clave_prod_serv',1=>'descripcion',2=>'incluir_iva_trasladado',3=>'incluir_ieps_trasladado',4=>'complemento_debe_incluir',5=>'fecha_inicio_vigencia',6=>'fecha_fin_vigencia',7=>'fecha_sincronizacion'];
    $oi=(int)($_GET['order'][0]['column']??0); $dir=strtolower((string)($_GET['order'][0]['dir']??'asc'))==='desc'?'DESC':'ASC';
    $order=$orderMap[$oi]??'clave_prod_serv';
    $sql='SELECT clave_prod_serv,descripcion,incluir_iva_trasladado,incluir_ieps_trasladado,complemento_debe_incluir,fecha_inicio_vigencia,fecha_fin_vigencia,estimulo_franja_fronteriza,fecha_sincronizacion FROM sat_productos_servicios'.$w." ORDER BY $order $dir LIMIT :lim OFFSET :off";
    $st=$pdo->prepare($sql);
    foreach($params as $k=>$v)$st->bindValue($k,$v,PDO::PARAM_STR);
    $st->bindValue(':lim',$length,PDO::PARAM_INT); $st->bindValue(':off',$start,PDO::PARAM_INT); $st->execute();
    echo json_encode(['draw'=>$draw,'recordsTotal'=>$total,'recordsFiltered'=>$filtered,'data'=>$st->fetchAll(PDO::FETCH_ASSOC),'resumen'=>[
        'total'=>$total,'con_iva'=>(int)($resumen['con_iva']??0),'con_ieps'=>(int)($resumen['con_ieps']??0),'vigentes'=>(int)($resumen['vigentes']??0)
    ],'catalogo'=>$catalogo],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
    seguridad_log_error($e,'listar_productos_servicios_sat');
    http_response_code(500);
    echo json_encode(['draw'=>$draw??0,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'No se pudo consultar el catálogo de productos y servicios.'],JSON_UNESCAPED_UNICODE);
}
