<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; require_once '../includes/seguridad.php'; seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php'; require_once '../includes/reporte_xml_recibidos.php';
try{
    $idEmpresa=(int)($_SESSION['id_empresa']??0); $idUsuario=(int)($_SESSION['id_usuario']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'exportar');
    $inicio=trim((string)($_POST['inicio']??'')); $fin=trim((string)($_POST['fin']??''));
    $d1=DateTime::createFromFormat('Y-m-d',$inicio); $d2=DateTime::createFromFormat('Y-m-d',$fin);
    if(!$d1||!$d2||$d1->format('Y-m-d')!==$inicio||$d2->format('Y-m-d')!==$fin||$inicio>$fin) throw new RuntimeException('Rango de fechas inválido.');
    $finEx=(clone $d2)->modify('+1 day')->format('Y-m-d');
    $st=$pdo->prepare("SELECT COUNT(*) FROM facturas f WHERE f.id_empresa=? AND f.tipo_movimiento_empresa='E' AND f.fecha_emision>=? AND f.fecha_emision<?");
    $st->execute([$idEmpresa,$inicio,$finEx]); $total=(int)$st->fetchColumn();
    if($total<=0){echo json_encode(['status'=>'sin_datos','msg'=>'No hay CFDI recibidos en el rango seleccionado.']);exit;}
    $token=bin2hex(random_bytes(16)); $p=rxr_paths($token);
    $meta=['token'=>$token,'id_empresa'=>$idEmpresa,'id_usuario'=>$idUsuario,'inicio'=>$inicio,'fin'=>$fin,'fin_exclusiva'=>$finEx,'total'=>$total,'procesados'=>0,'renglones'=>0,'last_fecha'=>null,'last_uuid'=>null,'row_num'=>1,'cancelado'=>false,'completo'=>false,'creado'=>time()];
    file_put_contents($p['meta'],json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);
    $fh=fopen($p['sheet'],'wb'); if(!$fh) throw new RuntimeException('No se pudo crear el temporal del reporte.');
    $cols=''; foreach(rxr_widths() as $i=>$w){$n=$i+1;$cols.='<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';}
    fwrite($fh,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$cols.'</cols><sheetData>');
    $h='<row r="1" ht="30" customHeight="1">'; foreach(rxr_headers() as $i=>$v)$h.=rxr_cell_inline($i+1,1,$v,1); $h.="</row>\n"; fwrite($fh,$h); fclose($fh);
    echo json_encode(['status'=>'ok','token'=>$token,'total'=>$total],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);seguridad_log_error($e,'iniciar_xml_recibidos_excel');echo json_encode(['status'=>'error','msg'=>$e->getMessage()?:'No se pudo iniciar el reporte.']);}
