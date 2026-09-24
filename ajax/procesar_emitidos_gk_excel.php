<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php'; require_once '../includes/reporte_emitidos_gk.php';
try{
    $token=(string)($_POST['token']??''); if(!reg_valid_token($token))throw new RuntimeException('Token inválido.'); $p=reg_paths($token);
    if(!is_file($p['meta'])||!is_file($p['sheet_facturas'])||!is_file($p['sheet_notas']))throw new RuntimeException('El proceso ya no existe o fue cancelado.');
    $m=json_decode((string)file_get_contents($p['meta']),true); if(!is_array($m))throw new RuntimeException('Estado inválido.');
    if((int)$m['id_empresa']!==(int)($_SESSION['id_empresa']??0)||(int)$m['id_usuario']!==(int)($_SESSION['id_usuario']??0))throw new RuntimeException('Proceso no autorizado.');
    exigir_permiso_accion($pdo,(int)$m['id_usuario'],(int)$m['id_empresa'],'exportar'); if(!empty($m['cancelado']))throw new RuntimeException('El proceso fue cancelado.');
    if(!empty($m['completo'])){echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$m['procesados'],'total'=>$m['total'],'facturas'=>$m['renglones_facturas'],'notas'=>$m['renglones_notas'],'porcentaje'=>100]);exit;}

    $params=[(int)$m['id_empresa'],$m['inicio'],$m['fin_exclusiva']]; $key='';
    if(!empty($m['last_fecha'])&&!empty($m['last_uuid'])){$key=' AND (f.fecha_emision>? OR (f.fecha_emision=? AND f.uuid>?))';$params[]=$m['last_fecha'];$params[]=$m['last_fecha'];$params[]=$m['last_uuid'];}
    $sql="SELECT f.uuid,f.fecha_emision,f.id_tipo_comprobante,f.serie,f.folio,f.total_xml,f.subtotal_xml,f.moneda,f.tc_xml_factura,f.referencia_xml,f.forma_pago,f.metodo_pago,f.estatus_sat,f.version_cfdi,r.rfc rfc_receptor,r.nombre nombre_receptor,fd.xml_base64
          FROM facturas f
          LEFT JOIN cat_receptores r ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor
          LEFT JOIN facturas_datos fd ON fd.uuid=f.uuid
          WHERE f.id_empresa=? AND f.tipo_movimiento_empresa='I' AND UPPER(COALESCE(f.id_tipo_comprobante,'')) IN ('I','E')
            AND f.fecha_emision>=? AND f.fecha_emision<? $key ORDER BY f.fecha_emision,f.uuid LIMIT 35";
    $st=$pdo->prepare($sql);$st->execute($params);$docs=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$docs){
        reg_build_workbook($p,(int)$m['row_facturas'],(int)$m['row_notas']);
        $m['completo']=true;file_put_contents($p['meta'],json_encode($m,JSON_UNESCAPED_UNICODE),LOCK_EX);
        echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$m['procesados'],'total'=>$m['total'],'facturas'=>$m['renglones_facturas'],'notas'=>$m['renglones_notas'],'porcentaje'=>100]);exit;
    }

    $fhFact=fopen($p['sheet_facturas'],'ab');$fhNotas=fopen($p['sheet_notas'],'ab');if(!$fhFact||!$fhNotas)throw new RuntimeException('No se pudo continuar escribiendo el reporte de emitidos.');
    foreach($docs as $d){
        $actual=json_decode((string)file_get_contents($p['meta']),true);if(!empty($actual['cancelado'])){fclose($fhFact);fclose($fhNotas);throw new RuntimeException('El proceso fue cancelado.');}
        $xml=rxr_normalize_xml((string)($d['xml_base64']??''));$receptor=$xml?rxr_first_child($xml,'Receptor'):null;$conceptos=$xml?rxr_conceptos($xml):[];if(!$conceptos)$conceptos=[null];
        $tipo=strtoupper((string)($d['id_tipo_comprobante']??'I'));$esNota=$tipo==='E';$fh=$esNota?$fhNotas:$fhFact;$rowKey=$esNota?'row_notas':'row_facturas';$countKey=$esNota?'renglones_notas':'renglones_facturas';
        $fecha=!empty($d['fecha_emision'])?date('d/m/Y',strtotime($d['fecha_emision'])):'';$estatus=str_contains(strtoupper((string)($d['estatus_sat']??'')),'CANCEL')?'Cancelado':'Timbrado';
        $serie=$xml?(rxr_attr($xml,'Serie')?:(string)$d['serie']):(string)$d['serie'];$folio=$xml?(rxr_attr($xml,'Folio')?:(string)$d['folio']):(string)$d['folio'];
        $rfc=$receptor?rxr_attr($receptor,'Rfc'):(string)($d['rfc_receptor']??'');$nombre=$receptor?rxr_attr($receptor,'Nombre'):(string)($d['nombre_receptor']??'');$regimen=reg_regimen_receptor($receptor,(string)($d['xml_base64']??''));
        $subtotal=$xml?(rxr_attr($xml,'SubTotal')?:($d['subtotal_xml']??0)):($d['subtotal_xml']??0);$descuento=$xml?rxr_attr($xml,'Descuento'):'';$total=$xml?(rxr_attr($xml,'Total')?:($d['total_xml']??0)):($d['total_xml']??0);
        $moneda=$xml?(rxr_attr($xml,'Moneda')?:(string)$d['moneda']):(string)$d['moneda'];$tc=$xml?(rxr_attr($xml,'TipoCambio')?:($d['tc_xml_factura']??1)):($d['tc_xml_factura']??1);
        $forma=$xml?(rxr_attr($xml,'FormaPago')?:(string)$d['forma_pago']):(string)$d['forma_pago'];$metodo=$xml?(rxr_attr($xml,'MetodoPago')?:(string)$d['metodo_pago']):(string)$d['metodo_pago'];$version=$xml?(rxr_attr($xml,'Version')?:(string)$d['version_cfdi']):(string)$d['version_cfdi'];
        foreach($conceptos as $c)foreach(reg_tax_rows($c) as $tax){
            $codigo=(string)$tax['codigo'];$row=[$fecha,$serie,$folio,$rfc,$nombre,$regimen,$c?rxr_attr($c,'Descripcion'):'',$c?rxr_attr($c,'Cantidad'):'',$c?rxr_attr($c,'ValorUnitario'):'',$c?rxr_attr($c,'Importe'):'',$subtotal,$descuento,$total,$moneda,$tc,(string)($d['referencia_xml']??''),$estatus,strtoupper((string)$d['uuid']),$forma,$metodo,(string)$tax['factor'],$codigo,$codigo!==''?rxr_impuesto_desc($codigo):'',(string)$tax['tasa'],'CFDI',$version,$esNota?'Egreso':'Ingreso',''];
            $m[$rowKey]++;reg_write_row($fh,(int)$m[$rowKey],$row);$m[$countKey]++;
        }
        $m['procesados']++;$m['last_fecha']=$d['fecha_emision'];$m['last_uuid']=$d['uuid'];
    }
    fclose($fhFact);fclose($fhNotas);$completo=(int)$m['procesados']>=(int)$m['total'];
    if($completo){reg_build_workbook($p,(int)$m['row_facturas'],(int)$m['row_notas']);$m['completo']=true;}
    file_put_contents($p['meta'],json_encode($m,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);$pct=$m['total']?min(100,round(((int)$m['procesados']/(int)$m['total'])*100)):100;
    echo json_encode(['status'=>'ok','completo'=>$completo,'procesados'=>(int)$m['procesados'],'total'=>(int)$m['total'],'facturas'=>(int)$m['renglones_facturas'],'notas'=>(int)$m['renglones_notas'],'porcentaje'=>$pct],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);seguridad_log_error($e,'procesar_emitidos_gk_excel');echo json_encode(['status'=>'error','msg'=>$e->getMessage()?:'No se pudo procesar el reporte de emitidos.']);}
