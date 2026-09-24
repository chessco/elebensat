<?php
session_start();
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';

function xlsXml($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function xlsMoney($v): string { return number_format((float)$v, 2, '.', ''); }
function xlsFecha($v): string {
    $v=trim((string)$v); if($v==='') return '';
    $ts=strtotime($v); return $ts ? date('d/m/Y H:i:s',$ts) : $v;
}
function contieneBusqueda(array $r,string $buscar): bool {
    if($buscar==='') return true;
    $texto=mb_strtolower(implode(' ',array_map(fn($v)=>(string)$v,$r)),'UTF-8');
    return mb_strpos($texto,mb_strtolower($buscar,'UTF-8'))!==false;
}

require_once __DIR__.'/../includes/sat_69_alertas.php';
$logTag='exportar_alertas_emisores_69_excel';
try {
    seguridad_exigir_sesion($pdo,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0); $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'consulta_alertas_69');
    sat69_crear_tabla_alertas($pdo);
    if($idEmpresa<=0) throw new RuntimeException('No hay empresa activa en la sesión.');

    $tipo=trim((string)($_GET['tipo']??''));
    $seg=strtoupper(trim((string)($_GET['seguimiento']??'')));
    $solo=(int)($_GET['solo_riesgo']??1)===1;
    $buscar=trim((string)($_GET['buscar']??''));

    $where=['a.activa=1','a.id_empresa=?']; $params=[$idEmpresa];
    if($tipo!==''){ $where[]='a.tipo_publicacion=?'; $params[]=$tipo; }
    if(in_array($seg,['NUEVA','VISTA','ATENDIDA'],true)){ $where[]='a.estatus_seguimiento=?'; $params[]=$seg; }
    if($solo) $where[]="a.nivel_riesgo IN ('ALTO','MEDIO')";
    $sql="SELECT a.id_emisor,e.razon_social empresa,a.rfc,a.nombre_emisor,a.tipo_publicacion,a.nivel_riesgo,a.estatus_seguimiento,a.fecha_primera_deteccion FROM sat_69_alertas_emisores a INNER JOIN empresas e ON e.id_empresa=a.id_empresa WHERE ".implode(' AND ',$where)." ORDER BY a.id_emisor,a.id_alerta";
    $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $gr=[];
    foreach($rows as $r){
        $k=(int)$r['id_emisor'];
        if(!isset($gr[$k])) $gr[$k]=['id_emisor'=>$k,'empresa'=>$r['empresa'],'rfc'=>$r['rfc'],'nombre_emisor'=>$r['nombre_emisor'],'_pub'=>[],'_niv'=>[],'_seg'=>[],'primera_deteccion'=>$r['fecha_primera_deteccion']];
        $gr[$k]['_pub'][(string)$r['tipo_publicacion']]=true; $gr[$k]['_niv'][(string)$r['nivel_riesgo']]=true; $gr[$k]['_seg'][]=strtoupper((string)$r['estatus_seguimiento']);
        if(!empty($r['fecha_primera_deteccion']) && (empty($gr[$k]['primera_deteccion']) || $r['fecha_primera_deteccion']<$gr[$k]['primera_deteccion'])) $gr[$k]['primera_deteccion']=$r['fecha_primera_deteccion'];
    }
    $tot=$pdo->prepare("SELECT COUNT(*) cfdi,COALESCE(SUM(total_xml),0) total FROM facturas WHERE id_emisor=? AND id_empresa=?");
    $data=[];
    foreach($gr as $g){
        $pub=array_keys($g['_pub']); sort($pub,SORT_NATURAL|SORT_FLAG_CASE);
        $niv=array_keys($g['_niv']); usort($niv,fn($a,$b)=>(['ALTO'=>1,'MEDIO'=>2,'INFORMATIVO'=>3][$a]??9)<=>(['ALTO'=>1,'MEDIO'=>2,'INFORMATIVO'=>3][$b]??9));
        $seguimiento=in_array('NUEVA',$g['_seg'],true)?'NUEVA':(in_array('VISTA',$g['_seg'],true)?'VISTA':'ATENDIDA');
        $tot->execute([$g['id_emisor'],$idEmpresa]); $x=$tot->fetch(PDO::FETCH_ASSOC)?:['cfdi'=>0,'total'=>0];
        $r=['seguimiento'=>$seguimiento,'empresa'=>$g['empresa'],'rfc'=>$g['rfc'],'nombre_emisor'=>$g['nombre_emisor'],'publicaciones'=>implode(' | ',$pub),'niveles'=>implode(' | ',$niv),'cfdi'=>(int)$x['cfdi'],'total_cfdi'=>(float)$x['total'],'primera_deteccion'=>$g['primera_deteccion']];
        if(contieneBusqueda($r,$buscar)) $data[]=$r;
    }
    usort($data,function($a,$b){$o=['NUEVA'=>1,'VISTA'=>2,'ATENDIDA'=>3];$c=($o[$a['seguimiento']]??9)<=>($o[$b['seguimiento']]??9);return $c?:strcasecmp($a['nombre_emisor'],$b['nombre_emisor']);});
    $st=$pdo->prepare("SELECT razon_social FROM empresas WHERE id_empresa=? LIMIT 1");$st->execute([$idEmpresa]);$empresaNombre=(string)($st->fetchColumn()?:'');
    $titulo='Emisores con riesgos del artículo 69'; $subtitulo='Cruce de emisores contra publicaciones del artículo 69';
    $descripcionFiltros='Publicación: '.($tipo?:'Todas').' | Seguimiento: '.($seg?:'Todos').' | Solo riesgo alto/medio: '.($solo?'Sí':'No').($buscar!==''?' | Buscar: '.$buscar:'');
    $encabezados=['SEGUIMIENTO','EMPRESA','RFC','EMISOR','PUBLICACIÓN SAT','NIVEL','CFDI','IMPORTE CFDI','DETECTADO'];
    $campoSituacion='publicaciones'; $campoNivel='niveles'; $archivo='Emisores_Riesgo_Articulo_69_'.date('Ymd_His').'.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$archivo.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Font ss:FontName="Calibri" ss:Size="10"/></Style>
  <Style ss:ID="Titulo"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="Subtitulo"><Font ss:Italic="1" ss:Color="#666666"/></Style>
  <Style ss:ID="Encabezado"><Font ss:Bold="1"/><Alignment ss:Horizontal="Center"/><Interior ss:Pattern="Solid" ss:Color="#D9EAF7"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="Moneda"><NumberFormat ss:Format="$#,##0.00;[Red]-$#,##0.00"/></Style>
  <Style ss:ID="Numero"><NumberFormat ss:Format="0"/></Style>
 </Styles>
 <Worksheet ss:Name="EMISORES">
  <Table>
   <Column ss:Width="95"/><Column ss:Width="170"/><Column ss:Width="105"/><Column ss:Width="220"/><Column ss:Width="210"/><Column ss:Width="95"/><Column ss:Width="75"/><Column ss:Width="105"/><Column ss:Width="135"/>
   <Row><Cell ss:MergeAcross="8" ss:StyleID="Titulo"><Data ss:Type="String"><?= xlsXml($titulo) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="8" ss:StyleID="Subtitulo"><Data ss:Type="String"><?= xlsXml($subtitulo) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="8"><Data ss:Type="String">Empresa activa: <?= xlsXml($empresaNombre) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="8"><Data ss:Type="String">Filtros: <?= xlsXml($descripcionFiltros) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="8"><Data ss:Type="String">Registros exportados: <?= count($data) ?></Data></Cell></Row>
   <Row>
<?php foreach($encabezados as $h): ?>    <Cell ss:StyleID="Encabezado"><Data ss:Type="String"><?= xlsXml($h) ?></Data></Cell>
<?php endforeach; ?>   </Row>
<?php foreach($data as $r): ?>
   <Row>
    <Cell><Data ss:Type="String"><?= xlsXml($r['seguimiento']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml($r['empresa']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml($r['rfc']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml($r['nombre_emisor']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml($r[$campoSituacion]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml($r[$campoNivel] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="Numero"><Data ss:Type="Number"><?= (int)$r['cfdi'] ?></Data></Cell>
    <Cell ss:StyleID="Moneda"><Data ss:Type="Number"><?= xlsMoney($r['total_cfdi']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= xlsXml(xlsFecha($r['primera_deteccion'])) ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>6</SplitHorizontal><TopRowBottomPane>6</TopRowBottomPane></WorksheetOptions>
 </Worksheet>
</Workbook>
<?php
} catch(Throwable $e) {
    if(isset($pdo)) seguridad_log_error($e,$logTag);
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo exportar: '.$e->getMessage();
}
