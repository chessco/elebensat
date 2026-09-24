<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php'; require_once '../includes/reporte_xml_recibidos.php';
try{
    $token=(string)($_POST['token']??''); if(!rxr_valid_token($token)) throw new RuntimeException('Token inválido.');
    $p=rxr_paths($token); if(!is_file($p['meta'])||!is_file($p['sheet'])) throw new RuntimeException('El proceso ya no existe o fue cancelado.');
    $m=json_decode((string)file_get_contents($p['meta']),true); if(!is_array($m)) throw new RuntimeException('Estado inválido.');
    if((int)$m['id_empresa']!==(int)($_SESSION['id_empresa']??0)||(int)$m['id_usuario']!==(int)($_SESSION['id_usuario']??0)) throw new RuntimeException('Proceso no autorizado.');
    exigir_permiso_accion($pdo,(int)$m['id_usuario'],(int)$m['id_empresa'],'exportar');
    if(!empty($m['cancelado'])) throw new RuntimeException('El proceso fue cancelado.');
    if(!empty($m['completo'])){echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$m['procesados'],'total'=>$m['total'],'renglones'=>$m['renglones'],'porcentaje'=>100]);exit;}

    $params=[(int)$m['id_empresa'],$m['inicio'],$m['fin_exclusiva']];
    $key='';
    if(!empty($m['last_fecha'])&&!empty($m['last_uuid'])){$key=" AND (f.fecha_emision > ? OR (f.fecha_emision = ? AND f.uuid > ?))";$params[]=$m['last_fecha'];$params[]=$m['last_fecha'];$params[]=$m['last_uuid'];}
    $sql="SELECT f.uuid,f.fecha_emision,f.id_tipo_comprobante,f.serie,f.folio,f.total_xml,f.moneda,f.tc_xml_factura,f.referencia_xml,f.forma_pago,f.metodo_pago,f.uso_cfdi,f.estatus_sat,f.fecha_cancelacion,f.ya_pago,f.version_cfdi,e.rfc rfc_emisor,e.nombre nombre_emisor,fd.xml_base64,fp.descripcion forma_desc,mp.descripcion metodo_desc,uc.descripcion uso_desc FROM facturas f JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor LEFT JOIN facturas_datos fd ON fd.uuid=f.uuid LEFT JOIN cat_formas_pago fp ON fp.id_forma=f.forma_pago LEFT JOIN cat_metodos_pago mp ON mp.id_metodo=f.metodo_pago LEFT JOIN cat_uso_cfdi uc ON uc.id_uso=f.uso_cfdi WHERE f.id_empresa=? AND f.tipo_movimiento_empresa='E' AND f.fecha_emision>=? AND f.fecha_emision<? $key ORDER BY f.fecha_emision,f.uuid LIMIT 35";
    $st=$pdo->prepare($sql);$st->execute($params);$docs=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$docs){
        rxr_build_xlsx($p['sheet'],$p['xlsx'],(int)$m['row_num']);$m['completo']=true;file_put_contents($p['meta'],json_encode($m,JSON_UNESCAPED_UNICODE),LOCK_EX);
        echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$m['procesados'],'total'=>$m['total'],'renglones'=>$m['renglones'],'porcentaje'=>100]);exit;
    }
    // ------------------------------------------------------------
    // Catálogo SAT c_ClaveProdServ
    // La columna "Clave Prod/Serv Desc" del reporte ya existía,
    // pero se dejaba vacía. Para evitar una consulta por concepto,
    // reunimos primero las claves del bloque actual (máx. 35 CFDI)
    // y hacemos una sola consulta IN al catálogo SAT.
    // ------------------------------------------------------------
    $prodServClaves=[];
    foreach($docs as $dCat){
        $xmlCat=rxr_normalize_xml((string)($dCat['xml_base64']??''));
        if(!$xmlCat) continue;
        foreach(rxr_conceptos($xmlCat) as $cCat){
            $claveCat=trim(rxr_attr($cCat,'ClaveProdServ'));
            if($claveCat!=='' && preg_match('/^\d{8}$/',$claveCat)){
                $prodServClaves[$claveCat]=1;
            }
        }
    }

    $prodServMap=[];
    if($prodServClaves){
        $claves=array_keys($prodServClaves);
        $marks=implode(',',array_fill(0,count($claves),'?'));
        $stProd=$pdo->prepare(
            "SELECT clave_prod_serv, descripcion
             FROM sat_productos_servicios
             WHERE clave_prod_serv IN ($marks)"
        );
        $stProd->execute($claves);
        while($rp=$stProd->fetch(PDO::FETCH_ASSOC)){
            $prodServMap[(string)$rp['clave_prod_serv']]=(string)$rp['descripcion'];
        }
    }

    $fh=fopen($p['sheet'],'ab'); if(!$fh) throw new RuntimeException('No se pudo continuar escribiendo el reporte.');
    foreach($docs as $d){
        if(is_file($p['meta'])){ $mm=json_decode((string)file_get_contents($p['meta']),true); if(!empty($mm['cancelado'])){fclose($fh);throw new RuntimeException('El proceso fue cancelado.');} }
        $xml=rxr_normalize_xml((string)($d['xml_base64']??''));
        $relations=[];$tipoRel='';
        if($xml){
            $relNode=rxr_first_child($xml,'CfdiRelacionados');
            if($relNode){$tipoRel=rxr_attr($relNode,'TipoRelacion');foreach(rxr_children_ns($relNode,'CfdiRelacionado') as $rr){$u=rxr_attr($rr,'UUID');if($u!=='')$relations[]=$u;}}
            $conceptos=rxr_conceptos($xml);
        } else $conceptos=[];
        if(!$conceptos){$conceptos=[null];}
        foreach($conceptos as $c){
            $tipo=(string)($d['id_tipo_comprobante']??'');
            $fecha=!empty($d['fecha_emision'])?date('d/m/Y',strtotime($d['fecha_emision'])):'';
            $estatus=strtoupper((string)($d['estatus_sat']??'Vigente'));
            $cancelado=str_contains($estatus,'CANCEL');
            $obj=$c?rxr_attr($c,'ObjetoImp'):'';

            $claveProdServ=$c?trim(rxr_attr($c,'ClaveProdServ')):'';
            $claveProdServDesc=($claveProdServ!=='' && isset($prodServMap[$claveProdServ]))
                ? $prodServMap[$claveProdServ]
                : '';

            // Auditoría: un renglón por impuesto. Si el concepto trae IVA + IEPS
            // se generan 2 renglones. Si trae ISR + IVA retenido, también 2.
            // Esto replica la presentación del reporte histórico GKM.
            $taxRows=$c?rxr_tax_rows($c):[[
                'tras_codigo'=>'','tras_tasa'=>'','tras_importe'=>0,
                'ret_codigo'=>'','ret_base'=>0,'ret_tasa'=>'','ret_importe'=>0,'ret_factor'=>''
            ]];

            foreach($taxRows as $tax){
                $trasCode=(string)$tax['tras_codigo'];
                $retCode=(string)$tax['ret_codigo'];

                $row=[
                    strtoupper((string)$d['uuid']),$fecha,rxr_tipo_desc($tipo),(string)$d['serie'],(string)$d['folio'],(string)$d['rfc_emisor'],(string)$d['nombre_emisor'],
                    $claveProdServ,$claveProdServDesc, $c?rxr_attr($c,'Descripcion'):'', $c?(rxr_attr($c,'Cantidad')?:0):'', $c?(rxr_attr($c,'ValorUnitario')?:0):'', $c?(rxr_attr($c,'Importe')?:0):'',
                    $tax['tras_importe'],(float)($d['total_xml']??0),(string)($d['moneda']??''),(float)($d['tc_xml_factura']??1),(string)($d['referencia_xml']??''),
                    $trasCode,$trasCode!==''?rxr_impuesto_desc($trasCode):'',$tax['tras_tasa'],(string)$d['forma_pago'],(string)$d['metodo_pago'],
                    $retCode,$retCode!==''?rxr_impuesto_desc($retCode):'',$tax['ret_base'],$tax['ret_tasa'],$tax['ret_importe'],
                    $cancelado?'Cancelado':'Timbrado',!empty($d['ya_pago'])?'Pagado':'Pendiente','OK',$cancelado?'Cancelado':'Vigente',!empty($d['fecha_cancelacion'])?date('d/m/Y H:i:s',strtotime($d['fecha_cancelacion'])):'',
                    (string)($d['forma_desc']??''),(string)($d['metodo_desc']??''),(string)($d['uso_cfdi']??''),(string)($d['uso_desc']??''),$obj,rxr_objeto_desc($obj),$tax['ret_factor'],
                    $tipoRel,implode(', ',$relations),rxr_rel_desc($tipoRel),'CFDI',(string)($d['version_cfdi']??'')
                ];
                $m['row_num']++; rxr_write_row($fh,(int)$m['row_num'],$row); $m['renglones']++;
            }
        }
        $m['procesados']++; $m['last_fecha']=$d['fecha_emision']; $m['last_uuid']=$d['uuid'];
    }
    fclose($fh);
    $completo=(int)$m['procesados'] >= (int)$m['total'];
    if($completo){rxr_build_xlsx($p['sheet'],$p['xlsx'],(int)$m['row_num']);$m['completo']=true;}
    file_put_contents($p['meta'],json_encode($m,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);
    $pct=$m['total']?min(100,round(((int)$m['procesados']/(int)$m['total'])*100)):100;
    echo json_encode(['status'=>'ok','completo'=>$completo,'procesados'=>(int)$m['procesados'],'total'=>(int)$m['total'],'renglones'=>(int)$m['renglones'],'porcentaje'=>$pct],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);seguridad_log_error($e,'procesar_xml_recibidos_excel');echo json_encode(['status'=>'error','msg'=>$e->getMessage()?:'No se pudo procesar el reporte.']);}
