<?php
error_reporting(0);
@set_time_limit(0);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, true);

function attrRaiz(string $raw, string $name): string {
    $dom=new DOMDocument(); if(!@$dom->loadXML($raw, LIBXML_NONET|LIBXML_NOBLANKS)) return '';
    $root=$dom->documentElement; if(!$root) return '';
    foreach($root->attributes ?? [] as $a) if(strcasecmp($a->localName,$name)===0) return trim($a->value);
    return '';
}
function attrNodo(string $raw,string $node,string $name): string {
    $dom=new DOMDocument(); if(!@$dom->loadXML($raw, LIBXML_NONET|LIBXML_NOBLANKS)) return '';
    $xp=new DOMXPath($dom); $ns=$xp->query('//*[local-name()="'.$node.'"]');
    if(!$ns || !$ns->length) return '';
    foreach($ns->item(0)->attributes ?? [] as $a) if(strcasecmp($a->localName,$name)===0) return trim($a->value);
    return '';
}
try {
    if(empty($_SESSION['id_empresa'])) throw new Exception('Sin empresa activa');
    $idEmpresa=(int)$_SESSION['id_empresa'];
    $limite=max(1,min(1000,(int)($_GET['limite'] ?? 500)));
    $sql="SELECT f.uuid, fd.xml_base64
          FROM facturas f INNER JOIN facturas_datos fd ON fd.uuid=f.uuid
          WHERE f.id_empresa=? AND (
            f.version_cfdi IS NULL OR f.version_cfdi='' OR
            f.forma_pago IS NULL OR f.forma_pago='' OR
            f.uso_cfdi IS NULL OR f.uso_cfdi='' OR
            f.lugar_expedicion_xml IS NULL OR f.lugar_expedicion_xml=''
          ) LIMIT $limite";
    $st=$pdo->prepare($sql); $st->execute([$idEmpresa]);
    $up=$pdo->prepare("UPDATE facturas SET
        version_cfdi=CASE WHEN ?<>'' THEN ? ELSE version_cfdi END,
        forma_pago=CASE WHEN ?<>'' THEN ? ELSE forma_pago END,
        uso_cfdi=CASE WHEN ?<>'' THEN ? ELSE uso_cfdi END,
        lugar_expedicion_xml=CASE WHEN ?<>'' THEN ? ELSE lugar_expedicion_xml END
        WHERE uuid=? AND id_empresa=?");
    $procesados=$actualizados=$errores=0;
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $procesados++; $raw=base64_decode((string)$r['xml_base64'],true);
        if($raw===false || $raw===''){ $errores++; continue; }
        $ver=attrRaiz($raw,'Version'); if($ver==='') $ver=attrRaiz($raw,'version');
        $forma=attrRaiz($raw,'FormaPago');
        $uso=attrNodo($raw,'Receptor','UsoCFDI');
        $cp=attrRaiz($raw,'LugarExpedicion');
        $up->execute([$ver,$ver,$forma,$forma,$uso,$uso,$cp,$cp,$r['uuid'],$idEmpresa]);
        if($up->rowCount()>0) $actualizados++;
    }
    echo json_encode(['status'=>'ok','procesados'=>$procesados,'actualizados'=>$actualizados,'errores'=>$errores,
      'mensaje'=>'Recarga el Visor. Si quedan registros pendientes, ejecuta nuevamente este reparador.']);
} catch(Throwable $e){ http_response_code(500); seguridad_log_error($e, 'reparar_campos_cfdi'); echo json_encode(['status'=>'error','msg'=>'No se pudo completar la reparación.']); }
