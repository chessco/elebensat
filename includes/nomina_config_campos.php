<?php
require_once __DIR__.'/nomina_fuente.php';
require_once __DIR__.'/XlsxSimpleReader.php';
require_once __DIR__.'/XlsBiff8Reader.php';
function ncm_schema(PDO $pdo,bool $required=true): bool {
    try {foreach(['nomina_config_xml_muestras','nomina_config_xml_campos','nomina_config_reglas','nomina_config_reglas_historial'] as $t) $pdo->query('SELECT 1 FROM '.$t.' LIMIT 0');return true;}
    catch(PDOException $e) {if (!$required && (in_array((string)$e->getCode(),['42S02','42P01'],true)||str_contains($e->getMessage(),'no such table')))return false;throw new RuntimeException('Falta instalar sql/nomina_configuracion_campos_v2.sql en la base del visor.',0,$e);}
}
function ncm_load(PDO $pdo,int $empresa): array {
    if (!ncm_schema($pdo,false)) return [];
    $q=$pdo->prepare('SELECT r.*,c.clave_campo,c.tipo_valor FROM nomina_config_reglas r JOIN nomina_campos c ON c.id_campo=r.id_campo AND c.id_empresa=r.id_empresa WHERE r.id_empresa=?');$q->execute([$empresa]);$out=[];
    while($r=$q->fetch(PDO::FETCH_ASSOC)) {$d=json_decode($r['definicion_json'],true,512,JSON_THROW_ON_ERROR);$d['revision']=(int)$r['version_regla'];$d['id_regla']=(int)$r['id_regla'];$d['usuario']=(int)$r['id_usuario'];$d['fecha']=$r['fecha_actualizacion'];$out[$r['clave_campo']]=$d;}
    return $out;
}
function ncm_plan(array $base,array $rule): array {
    if ($rule['estado']==='PREDETERMINADO') return $base;
    $base['numero']=($rule['tipo_workbeat']??'TEXTO')==='NUMERO';$base['revision']=$rule['revision']??0;$base['definicion']=$rule;
    $base['regla']='Configuración v'.($rule['revision']??0).': '.$rule['descripcion'];
    $base['modo']=['RELACIONADO'=>'configurado','INFORMATIVO'=>'informativo','PENDIENTE'=>'pendiente'][$rule['estado']]??'pendiente';
    return $base;
}
/** Enumeración sin depender del prefijo nomina12/cfdi elegido por el emisor. */
function ncm_children(SimpleXMLElement $x,array $uris): array {
    $out=[];foreach($uris as $uri)foreach($x->children($uri) as $child)$out[]=[$uri,$child];return $out;
}
function ncm_discover(SimpleXMLElement $root): array {
    $uris=array_values(array_unique(array_merge([''],array_values($root->getDocNamespaces(true)))));$found=[];$nodes=0;
    $walk=function(SimpleXMLElement $node,array $path,int $depth) use (&$walk,&$found,&$nodes,$uris) {
        if (++$nodes>20000 || $depth>16) throw new RuntimeException('XML demasiado complejo para configurar campos.');
        $children=ncm_children($node,$uris);$attrs=[];
        foreach($node->attributes() as $name=>$value) if (!in_array(strtolower($name),['sello','sellocfd','sellosat','certificado','nocertificado','nocertificadosat'],true)) $attrs[$name]=(string)$value;
        if (!$children && trim((string)$node)!=='') $attrs['#texto']=trim((string)$node);
        foreach($attrs as $attr=>$value) {
            $selector=['ruta'=>$path,'atributo'=>$attr];$key=hash('sha256',nf_json($selector));$label='Comprobante';
            foreach($path as $step) {$label.='/'.$step['nombre'];if($step['filtros'])$label.='['.implode(', ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($step['filtros']),$step['filtros'])).']';}
            $label.=$attr==='#texto'?'/texto':'@'.$attr;
            if(!isset($found[$key]))$found[$key]=['hash'=>$key,'selector'=>$selector,'etiqueta'=>$label,'ejemplos'=>[],'tipo'=>is_numeric($value)&&!preg_match('/^(Clave|Tipo|Codigo|NumEmpleado|NumSeguridadSocial|LugarExpedicion|Banco|Cuenta|NoCertificado)/i',$attr)?'NUMERO':'TEXTO'];
            if(count($found[$key]['ejemplos'])<3 && !in_array($value,$found[$key]['ejemplos'],true))$found[$key]['ejemplos'][]=strlen($value)>200?(preg_replace('/[\x80-\xFF]+$/','',substr($value,0,197))??'').'...':$value;
            if(count($found)>3000)throw new RuntimeException('El XML tiene demasiados campos distintos.');
        }
        foreach($children as [$uri,$child]) {
            $filters=[];
            foreach(['Clave','TipoPercepcion','TipoDeduccion','TipoOtroPago','TipoHoras','TipoIncapacidad','Impuesto','TipoFactor','TasaOCuota'] as $a) { $v=rng_attr($child,$a);if($v!==null)$filters[$a]=$v; }
            $walk($child,array_merge($path,[['ns'=>$uri,'nombre'=>$child->getName(),'filtros'=>$filters]]),$depth+1);
        }
    };
    $walk($root,[],0);return array_values($found);
}
function ncm_parse_sample(string $raw,string $issuer): SimpleXMLElement {
    // Sólo XML literal, no bases64 ni URLs, DTD o entidades externas.
    $raw=ltrim($raw,"\xEF\xBB\xBF \r\n\t");
    if(strlen($raw)>2*1024*1024 || !str_starts_with($raw,'<'))throw new RuntimeException('Sube un archivo XML de nómina de hasta 2 MB.');
    $x=rng_parse($raw);
    if($x===null || rng_attr($x,'TipoDeComprobante')!=='N' || !($x->xpath('./*[local-name()="Complemento"]/*[local-name()="Nomina"]')?:[]))throw new RuntimeException('No es un XML completo de CFDI nómina. No se reparó ni registró.');
    if(ncc_norm(rng_attr(rng_child($x,'Emisor'),'Rfc'))!==ncc_norm($issuer))throw new RuntimeException('El XML pertenece a otro emisor. Selecciona la empresa correspondiente.');
    return $x;
}
function ncm_store_xml(PDO $pdo,int $empresa,int $user,string $filename,string $raw,string $issuer): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Se requiere transacción.');
    $x=ncm_parse_sample($raw,$issuer);$fields=ncm_discover($x);$hash=hash('sha256',$raw);
    $q=$pdo->prepare('SELECT id_muestra FROM nomina_config_xml_muestras WHERE id_empresa=? AND hash_archivo=?');$q->execute([$empresa,$hash]);$id=(int)$q->fetchColumn();$new=0;
    if(!$id){
        $q=$pdo->prepare('INSERT INTO nomina_config_xml_muestras(id_empresa,hash_archivo,nombre_archivo,uuid,rfc_receptor,fecha_pago,xml_texto,id_usuario) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([$empresa,$hash,$filename,rng_attr(rng_child(rng_child($x,'Complemento'),'TimbreFiscalDigital'),'UUID'),rng_attr(rng_child($x,'Receptor'),'Rfc'),rng_attr(rng_child(rng_child($x,'Complemento'),'Nomina'),'FechaPago'),$raw,$user]);$id=(int)$pdo->lastInsertId();
    }
    foreach($fields as $f){
        $q=$pdo->prepare('SELECT id_xml_campo FROM nomina_config_xml_campos WHERE id_empresa=? AND selector_hash=?');$q->execute([$empresa,$f['hash']]);
        if(!$q->fetchColumn()){$q=$pdo->prepare('INSERT INTO nomina_config_xml_campos(id_empresa,selector_hash,selector_json,etiqueta,ejemplos_json,tipo_sugerido,id_muestra) VALUES(?,?,?,?,?,?,?)');$q->execute([$empresa,$f['hash'],nf_json($f['selector']),$f['etiqueta'],nf_json($f['ejemplos']),$f['tipo'],$id]);$new++;}
    }
    return ['id_muestra'=>$id,'campos_detectados'=>count($fields),'campos_nuevos'=>$new];
}
/** Navega estructuras ya descubiertas: no ejecuta XPath ni expresiones del usuario. */
function ncm_select(SimpleXMLElement $root,array $selector): array {
    $nodes=[$root];$conflict=false;
    // Una Clave que reaparece en otro grupo no es un movimiento ausente de cero.
    foreach($selector['ruta'] as $step)if(isset($step['filtros']['Clave']) && in_array($step['nombre'],['Percepcion','Deduccion','OtroPago'],true)){
        foreach($root->xpath('//*[local-name()="Percepcion" or local-name()="Deduccion" or local-name()="OtroPago"]')?:[] as $concept)
            if(rng_attr($concept,'Clave')===$step['filtros']['Clave'] && $concept->getName()!==$step['nombre'])$conflict=true;
    }
    if(count($selector['ruta'])>16)throw new RuntimeException('Ruta XML inválida.');
    foreach($selector['ruta'] as $step){
        $next=[];foreach($nodes as $node){
            foreach($node->children($step['ns']) as $child){
                if($child->getName()!==$step['nombre'])continue;$ok=true;
                foreach($step['filtros'] as $a=>$value)if(rng_attr($child,$a)!==$value){$ok=false;break;}
                if($ok)$next[]=$child;
                elseif(isset($step['filtros']['Clave']) && rng_attr($child,'Clave')===$step['filtros']['Clave'])$conflict=true;
            }
            // Un cambio de namespace no se trata como ausencia de movimiento.
            foreach(array_unique(array_values($node->getDocNamespaces(true))) as $ns)if($ns!==$step['ns'])foreach($node->children($ns) as $child)if($child->getName()===$step['nombre'])$conflict=true;
        }$nodes=$next;
    }
    $values=[];$missing=false;
    foreach($nodes as $node){$v=$selector['atributo']==='#texto'?trim((string)$node):rng_attr($node,$selector['atributo']);if($v===null||$v==='')$missing=true;else$values[]=$v;}
    return ['valores'=>$values,'nodos'=>count($nodes),'falta_atributo'=>$missing,'conflicto'=>$conflict];
}
function ncm_allow_zero(array $selector): bool {
    foreach($selector['ruta'] as $s)if(isset($s['filtros']['Clave']) && in_array($s['nombre'],['Percepcion','Deduccion','OtroPago'],true))return true;
    return false;
}
function ncm_text($value,string $mode,bool $excel=false): string {
    if($mode==='TEXTO')return (string)$value;
    if($mode==='NUMERO_UNICO'){
        $units=ncc_units($value);$abs=abs($units);$fraction=rtrim(str_pad((string)($abs%1000000),6,'0',STR_PAD_LEFT),'0');
        return ($units<0?'-':'').intdiv($abs,1000000).($fraction!==''?'.'.$fraction:'');
    }
    if($excel && $mode==='ANIO' && preg_match('/^\d{4}$/',(string)$value))return (string)$value;
    if($excel && is_numeric($value)){
        $n=(float)$value;if($n<1||$n>2958465)throw new RuntimeException('Fecha Excel fuera de rango.');
        $date=(new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$n.' days')->format('Y-m-d');
    }else $date=rng_date((string)$value);
    if(!$date)throw new RuntimeException('No es una fecha válida.');return $mode==='ANIO'?substr($date,0,4):$date;
}
function ncm_eval(SimpleXMLElement $x,array $rule): array {
    $o=['sum'=>0,'textos'=>[],'faltan'=>0,'encontrados'=>0,'errores'=>[],'terminos'=>[]];
    foreach($rule['terminos'] as $term){
        $pick=ncm_select($x,$term['selector']);$o['terminos'][]=count($pick['valores']);$o['encontrados']+=count($pick['valores']);
        if($pick['conflicto']){$o['errores'][]='La clave cambió de grupo, tipo SAT o namespace respecto a la configuración.';continue;}
        if($pick['falta_atributo'] || (!$pick['valores'] && ($rule['ausencia']!=='CERO' || $pick['nodos']>0))){$o['faltan']++;continue;}
        foreach($pick['valores'] as $v){try{
            if($rule['operacion']==='SUMA')$o['sum']+=ncc_units($v)*$term['signo'];
            else $o['textos'][ncm_text($v,$rule['operacion'])]=true;
        }catch(Throwable $ex){$o['errores'][]='Valor XML incompatible con '.$rule['operacion'].': '.$ex->getMessage();}}
    }
    $o['errores']=array_values(array_unique($o['errores']));return $o;
}
/** Resolución por ID y empresa. Nunca aceptar rutas XML ni SQL desde el navegador. */
function ncm_definition(PDO $pdo,int $empresa,array $input): array {
    $state=(string)($input['estado']??'');if(!in_array($state,['RELACIONADO','INFORMATIVO','PENDIENTE','PREDETERMINADO'],true))throw new RuntimeException('Estado de relación inválido.');
    $type=(string)($input['tipo_workbeat']??'TEXTO');if(!in_array($type,['NUMERO','TEXTO'],true))throw new RuntimeException('Tipo de campo inválido.');
    $def=['version'=>1,'estado'=>$state,'tipo_workbeat'=>$type,'operacion'=>'TEXTO','ausencia'=>'PENDIENTE','terminos'=>[],'descripcion'=>['INFORMATIVO'=>'Informativo; no disponible en XML. No se compara.','PENDIENTE'=>'Pendiente de definir equivalencia.','PREDETERMINADO'=>'Regla predeterminada del sistema.','RELACIONADO'=>''][$state]];
    if($state!=='RELACIONADO')return $def;
    $op=(string)($input['operacion']??'');if(!in_array($op,['SUMA','TEXTO','NUMERO_UNICO','FECHA','ANIO'],true))throw new RuntimeException('Operación no permitida.');
    $terms=$input['terminos']??[];if(!is_array($terms)||!count($terms)||count($terms)>12||($op!=='SUMA'&&count($terms)!==1))throw new RuntimeException('Selecciona 1 campo para texto/fecha o hasta 12 para sumar/restar.');
    $absence=(string)($input['ausencia']??'PENDIENTE');if(!in_array($absence,['PENDIENTE','CERO'],true)||($op!=='SUMA'&&$absence==='CERO'))throw new RuntimeException('Tratamiento de ausencias inválido.');
    $def['operacion']=$op;$def['ausencia']=$absence;$def['tipo_workbeat']=$op==='SUMA'?'NUMERO':'TEXTO';$seen=[];$labels=[];
    foreach($terms as $term){
        $id=(int)($term['id_xml_campo']??0);$sign=(int)($term['signo']??1);if($id<1||isset($seen[$id])||!in_array($sign,[-1,1],true)||($op!=='SUMA'&&$sign!==1))throw new RuntimeException('Campo XML repetido o signo inválido.');$seen[$id]=true;
        $q=$pdo->prepare('SELECT * FROM nomina_config_xml_campos WHERE id_empresa=? AND id_xml_campo=?');$q->execute([$empresa,$id]);$f=$q->fetch(PDO::FETCH_ASSOC);if(!$f)throw new RuntimeException('El campo XML no pertenece a la empresa activa.');
        $selector=json_decode($f['selector_json'],true,512,JSON_THROW_ON_ERROR);if($absence==='CERO'&&!ncm_allow_zero($selector))throw new RuntimeException('Cero por ausencia sólo se permite para conceptos identificados por Clave.');
        $def['terminos'][]=['id_xml_campo'=>$id,'selector'=>$selector,'etiqueta'=>$f['etiqueta'],'signo'=>$sign];$labels[]=($sign<0?'−':'+').' '.$f['etiqueta'];
    }
    $def['descripcion']=$op.': '.implode(' ',$labels).'; ausencia: '.$absence;return $def;
}
function ncm_probe(PDO $pdo,int $empresa,array $def,array $ids): array {
    if($def['estado']!=='RELACIONADO')return ['muestras'=>[],'apta'=>true,'aviso'=>$def['estado']==='PREDETERMINADO'?'Se volverá a utilizar la regla predeterminada del sistema, si existe para este campo y empresa.':'Este campo se conservará sin comparación XML, con el estado elegido.'];
    $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids||count($ids)>10)throw new RuntimeException('Selecciona de 1 a 10 XML de ejemplo para probar la relación.');
    $found=array_fill(0,count($def['terminos']),0);$rows=[];$bad=false;
    foreach($ids as $id){
        $q=$pdo->prepare('SELECT * FROM nomina_config_xml_muestras WHERE id_empresa=? AND id_muestra=?');$q->execute([$empresa,$id]);$s=$q->fetch(PDO::FETCH_ASSOC);if(!$s)throw new RuntimeException('XML de ejemplo no autorizado.');
        $x=rng_parse($s['xml_texto']);if($x===null)throw new RuntimeException('La muestra guardada no es legible.');$r=ncm_eval($x,$def);foreach($r['terminos'] as $i=>$n)$found[$i]+=$n;
        if($r['errores'])$bad=true;
        $rows[]=['id_muestra'=>$id,'archivo'=>$s['nombre_archivo'],'rfc'=>$s['rfc_receptor'],'pago'=>$s['fecha_pago'],'valor'=>$def['operacion']==='SUMA'?$r['sum']/1000000:implode(' | ',array_keys($r['textos'])),'faltan'=>$r['faltan'],'encontrados'=>$r['encontrados'],'errores'=>$r['errores']];
    }
    return ['muestras'=>$rows,'apta'=>!$bad&&!in_array(0,$found,true),'aviso'=>'Prueba de lectura de ejemplos, no conciliación del acumulado. Cada término debe aparecer en al menos una muestra; las ausencias seguirán la regla elegida.'];
}
function ncm_save(PDO $pdo,int $empresa,int $user,int $campo,int $expected,array $def,string $comment): int {
    if(!$pdo->inTransaction())throw new RuntimeException('Se requiere transacción.');
    if(trim($comment)===''||strlen($comment)>500)throw new RuntimeException('Indica un motivo de hasta 500 caracteres.');
    $q=$pdo->prepare('SELECT id_campo FROM nomina_campos WHERE id_empresa=? AND id_campo=? FOR UPDATE');$q->execute([$empresa,$campo]);if(!$q->fetchColumn())throw new RuntimeException('Campo no autorizado.');
    $q=$pdo->prepare('SELECT * FROM nomina_config_reglas WHERE id_empresa=? AND id_campo=?');$q->execute([$empresa,$campo]);$r=$q->fetch(PDO::FETCH_ASSOC);$version=(int)($r['version_regla']??0);
    if($expected!==$version)throw new RuntimeException('Otro usuario cambió esta relación. Actualiza la lista y vuelve a revisarla.');$version++;$json=nf_json($def);
    if($r){$id=(int)$r['id_regla'];$q=$pdo->prepare('UPDATE nomina_config_reglas SET version_regla=?,definicion_json=?,id_usuario=?,comentario=?,fecha_actualizacion=CURRENT_TIMESTAMP WHERE id_regla=?');$q->execute([$version,$json,$user,$comment,$id]);}
    else{$q=$pdo->prepare('INSERT INTO nomina_config_reglas(id_empresa,id_campo,version_regla,definicion_json,id_usuario,comentario) VALUES(?,?,?,?,?,?)');$q->execute([$empresa,$campo,$version,$json,$user,$comment]);$id=(int)$pdo->lastInsertId();}
    $q=$pdo->prepare('INSERT INTO nomina_config_reglas_historial(id_regla,version_regla,definicion_json,id_usuario,comentario) VALUES(?,?,?,?,?)');$q->execute([$id,$version,$json,$user,$comment]);
    $q=$pdo->prepare("UPDATE nomina_campos SET estado='ACTIVO',tipo_valor=?,id_usuario_alta=COALESCE(id_usuario_alta,?),fecha_alta=COALESCE(fecha_alta,CURRENT_TIMESTAMP) WHERE id_empresa=? AND id_campo=?");$q->execute([$def['tipo_workbeat'],$user,$empresa,$campo]);
    return $version;
}
/** Cabeceras sólo para configurar. Nunca inserta ni reemplaza empleados/totales. */
function ncm_excel(string $path,string $ext,string $sheet='',int $header=0): array {
    if(!in_array($ext,['xls','xlsx'],true))throw new RuntimeException('Usa XLS o XLSX.');
    if($ext==='xlsx'){
        // Comprobar tamaño real con descompresión acotada antes del lector común.
        $raw=file_get_contents($path);$pos=strrpos($raw,"PK\x05\x06");if($pos===false)throw new RuntimeException('XLSX inválido.');
        $end=unpack('vdisk/vcdisk/vn1/vn/Vsize/Voffset',substr($raw,$pos+4,16));$p=$end['offset'];$expanded=0;
        if($end['n']>3000)throw new RuntimeException('El XLSX tiene demasiadas partes.');
        for($i=0;$i<$end['n'];$i++){
            if(substr($raw,$p,4)!=="PK\x01\x02")throw new RuntimeException('Directorio XLSX inválido.');
            if($p+46>strlen($raw))throw new RuntimeException('Directorio XLSX truncado.');
            $v=unpack('Vpacked/Vsize/vname/vextra/vcomment',substr($raw,$p+20,14));$expanded+=$v['size'];
            if($expanded>32*1024*1024 || $v['size']>16*1024*1024)throw new RuntimeException('Usa una muestra de nómina más pequeña para configurar encabezados.');
            $flags=unpack('v',substr($raw,$p+8,2))[1];$method=unpack('v',substr($raw,$p+10,2))[1];$off=unpack('V',substr($raw,$p+42,4))[1];
            if(($flags&1)||!in_array($method,[0,8],true)||$off+30>strlen($raw)||substr($raw,$off,4)!=="PK\x03\x04")throw new RuntimeException('Parte XLSX cifrada, inválida o no soportada.');
            $lens=unpack('vname/vextra',substr($raw,$off+26,4));$start=$off+30+$lens['name']+$lens['extra'];
            if($start+$v['packed']>strlen($raw))throw new RuntimeException('Parte XLSX truncada.');
            $packed=substr($raw,$start,$v['packed']);$part=$method===0?$packed:@gzinflate($packed,16*1024*1024);
            if($part===false || strlen($part)!==$v['size'] || hash('crc32b',$part)!==bin2hex(strrev(substr($raw,$p+16,4))))throw new RuntimeException('El XLSX tiene una parte dañada o demasiado grande.');
            unset($packed,$part);
            $p+=46+$v['name']+$v['extra']+$v['comment'];
        }unset($raw);
    }
    $reader=$ext==='xlsx'?new XlsxSimpleReader($path):new XlsBiff8Reader($path);$names=$reader->getSheetNames();
    if($sheet==='' && count($names)>1)return ['hojas'=>$names,'requiere_hoja'=>true];
    if($sheet==='')$sheet=(string)($names[0]??'');if(!in_array($sheet,$names,true))throw new RuntimeException('Selecciona una hoja válida.');$rows=$reader->readSheet($sheet);
    if(!$header){$best=-1;$defaults=nf_field_defaults();foreach($rows as $r=>$values){if($r>=80)break;$score=0;foreach($values as $v){$key=nf_field_key((string)$v);if(isset($defaults[$key]))$score+=3;if(str_starts_with($key,'CODIGO:'))$score+=2;if(in_array($key,['ENCABEZADO:ID EMPLEADO','ENCABEZADO:RFC'],true))$score+=10;}if($score>$best){$best=$score;$header=$r+1;}}if($best<6)throw new RuntimeException('No identifiqué los encabezados. Indica su número de renglón y vuelve a analizar.');}
    if(!isset($rows[$header-1])||$header<1||$header>1000)throw new RuntimeException('Renglón de encabezados inválido.');
    $cols=[];$max=max(array_keys($rows[$header-1]))+1;if($max>1000)throw new RuntimeException('La configuración admite hasta 1,000 columnas.');
    for($i=0;$i<$max;$i++){$label=trim((string)($rows[$header-1][$i]??''));$examples=[];for($r=$header;$r<min($header+10,count($rows)+10);$r++){ $v=$rows[$r][$i]??null;if($v!==null&&$v!==''&&!in_array($v,$examples,true))$examples[]=$v;if(count($examples)===3)break; }$cols[]=['indice'=>$i,'letra'=>rxr_col_name($i+1),'encabezado'=>$label,'ejemplos'=>$examples];}
    return ['hojas'=>$names,'hoja'=>$sheet,'fila_encabezado'=>$header,'columnas'=>$cols,'requiere_hoja'=>false];
}
