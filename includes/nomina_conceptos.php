<?php
// Informe adicional. No escribe ni recalcula nomina_conciliaciones / nomina_detalles.
require_once __DIR__.'/nomina_fuente.php';
require_once __DIR__.'/nomina_config_campos.php';
require_once __DIR__.'/reporte_nomina_gk.php';
require_once __DIR__.'/nomina_conciliacion_xlsx.php';

function ncc_rules(): array {
    static $r=null;
    return $r??=json_decode((string)file_get_contents(__DIR__.'/nomina_conceptos_reglas.json'),true,512,JSON_THROW_ON_ERROR);
}
function ncc_dir(string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new InvalidArgumentException('Identificador de informe inválido.');
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'sgksat_nomina_conceptos_'.$token;
}
function ncc_clean(): void {
    foreach(glob(sys_get_temp_dir().'/sgksat_nomina_conceptos_*',GLOB_ONLYDIR)?:[] as $dir) {
        if (!is_file($dir.'/meta.json') || filemtime($dir.'/meta.json')>time()-86400) continue;
        $lock=fopen($dir.'/job.lock','c');
        if ($lock && flock($lock,LOCK_EX|LOCK_NB)) {
            foreach(glob($dir.'/*')?:[] as $f) if (is_file($f) && basename($f)!=='job.lock') @unlink($f);
            flock($lock,LOCK_UN);fclose($lock);@unlink($dir.'/job.lock');@rmdir($dir);
        } elseif ($lock) fclose($lock);
    }
}
function ncc_norm($v): string {
    return strtoupper(trim(preg_replace('/\s+/u',' ',(string)($v??''))??''));
}
/** 6 decimales enteros; redondeo a centavos sólo al comparar/exportar. */
function ncc_units($v): ?int {
    if ($v===null || $v==='') return null;
    if (!is_numeric($v) || !is_finite((float)$v) || abs((float)$v)>10000000000) throw new RuntimeException('Valor numérico inválido o fuera de rango.');
    return (int)round((float)$v*1000000);
}
function ncc_money(int $v): float { return round($v/1000000,2); }
function ncc_plan(array $c,string $issuer): array {
    $key=$c['clave_campo'];$code=str_starts_with($key,'CODIGO:')?substr($key,7):'';
    $p=['modo'=>'pendiente','codigo'=>$code,'numero'=>false,'regla'=>'Sin equivalencia XML validada; requiere revisión del contador.'];
    if (($c['estado_al_importar']??'')!=='ACTIVO') {
        $p['regla']=($c['estado_al_importar']??'')==='AMBIGUO'?'Encabezado vacío o repetido: no se mezclaron columnas.':'Campo conservado; falta dar de alta y definir su equivalencia XML.';
        return $p;
    }
    if ($key==='ENCABEZADO:ANO') return array_merge($p,['modo'=>'atributo','atributo'=>'Ejercicio','regla'=>'Año de FechaPago del complemento Nómina. Si hay varios años se requiere revisión.']);
    $attrs=['ENCABEZADO:ID EMPLEADO'=>'NumEmpleado','ENCABEZADO:RFC'=>'Rfc','ENCABEZADO:CURP'=>'Curp','ENCABEZADO:NO SEGURO SOCIAL'=>'NumSeguridadSocial'];
    if (isset($attrs[$key])) return array_merge($p,['modo'=>'atributo','atributo'=>$attrs[$key],'regla'=>'Comparación de texto con Receptor@'.$attrs[$key].'; sin quitar ceros iniciales.']);
    $totals=['TOTALPER'=>'TotalPercepciones + TotalOtrosPagos','TOTALDED'=>'TotalDeducciones','NETO'=>'Comprobante@Total'];
    if (isset($totals[$code])) return array_merge($p,['modo'=>'total','numero'=>true,'regla'=>'Suma de '.$totals[$code].'. Referencia explícita: revisar tratamientos especiales de Workbeat.']);
    $r=ncc_rules();
    if (ncc_norm($issuer)===ncc_norm($r['rfc_emisor']) && isset($r['conceptos'][$code])) {
        $rule=$r['conceptos'][$code];
        return array_merge($p,['modo'=>'concepto','numero'=>true,'grupo'=>$rule['grupo'],'tipo'=>$rule['tipo'],
            'regla'=>$rule['grupo'].'@Clave='.$code.'; tipo SAT '.$rule['tipo'].'; '.($rule['grupo']==='Percepcion'?'ImporteGravado + ImporteExento':'Importe').'.'.(in_array($code,['D019','I017'],true)?' SubsidioCausado se conserva en evidencia; NO se sustituye por Importe.':'')]);
    }
    return $p;
}
function ncc_workbook(array $columns,iterable $rows,string $issuer,array $overrides=[]): array {
    $result=['columnas'=>[],'empleados'=>[],'incidencias'=>[]];
    foreach($columns as $c) {$c['plan']=ncc_plan($c,$issuer);if(isset($overrides[$c['clave_campo']]) && ($c['estado_al_importar']??'')!=='AMBIGUO')$c['plan']=ncm_plan($c['plan'],$overrides[$c['clave_campo']]);$result['columnas'][]=$c;}
    foreach($rows as $row) {
        if (!in_array($row['clase'],['EMPLEADO','ADICIONAL'],true)) continue;
        $rfc=ncc_norm($row['rfc']);
        if ($rfc==='') {$result['incidencias'][]='Renglón '.$row['renglon'].' sin RFC; no se asignó a otro empleado.';continue;}
        $a=&$result['empleados'][$rfc];
        if (!$a) $a=['rfc'=>$rfc,'numeros'=>[],'renglones'=>[],'valores'=>[]];
        $num=(string)($row['numero_empleado']??'');if ($num!=='') $a['numeros'][$num]=true;
        $a['renglones'][]=(int)$row['renglon'];
        $values=$row['valores']??json_decode($row['valores_json'],true,512,JSON_THROW_ON_ERROR);
        foreach($result['columnas'] as $c) {
            $i=$c['indice'];$v=$values[$i]??null;
            $cell=&$a['valores'][$i];if (!$cell) $cell=['sum'=>0,'count'=>0,'textos'=>[],'error'=>false];
            if ($v===null || $v==='') {unset($cell);continue;}
            if($c['plan']['modo']==='configurado' && !$c['plan']['numero']) {try{$v=ncm_text($v,$c['plan']['definicion']['operacion'],true);}catch(Throwable $ex){$cell['error']=true;}}
            $cell['textos'][(string)$v]=true;
            if ($c['plan']['numero']) {
                try {$cell['sum']+=ncc_units($v);$cell['count']++;} catch(Throwable $ex) {$cell['error']=true;}
            }
            unset($cell);
        }
        unset($a);
    }
    return $result;
}
/** Interpreta XML seguro; conserva evidencia, nunca repara el timbre ni inventa cierres. */
function ncc_document(array $d,array $scope): array {
    $o=['uuid'=>strtoupper($d['uuid']),'rfc'=>ncc_norm($d['rfc_receptor']??''),'estado'=>'INCIDENCIA','motivo'=>'','conceptos'=>[],'totales'=>[],'atributos'=>[],'inicio'=>null,'fin'=>null,'pago'=>null,'tipo'=>'','sat'=>ncc_norm($d['estatus_sat']??'')];
    $raw=(string)($d['xml_base64']??'');
    // Algunos exportadores anteponen BOM dos veces, incluso dentro de base64.
    $trim=ltrim($raw,"\xEF\xBB\xBF \r\n\t");
    if ($trim!=='' && $trim[0]!=='<') {$decoded=base64_decode($trim,true);if ($decoded!==false) $d['xml_base64']=ltrim($decoded,"\xEF\xBB\xBF \r\n\t");}
    $issues=[];$x=rng_load_xml($d,$issues);
    if (!$x) {$o['motivo']='XML ausente, cortado, inválido o distinto del UUID/RFC registrado. No se usó como cero.';return $o;}
    $ns=$x->xpath('./*[local-name()="Complemento"]/*[local-name()="Nomina"]');
    if (!$ns) {$o['motivo']='No se encontró complemento Nómina.';return $o;}
    $o['complementos']=count($ns);
    $n=$ns[0];$nr=rng_child($n,'Receptor');$r=rng_child($x,'Receptor');
    $o['rfc']=ncc_norm(rng_attr($r,'Rfc'));$em=ncc_norm(rng_attr(rng_child($x,'Emisor'),'Rfc'));
    if ($em!==ncc_norm($scope['rfc_emisor'])) {$o['motivo']='El emisor no coincide con la empresa activa.';return $o;}
    $o['inicio']=rng_date(rng_attr($n,'FechaInicialPago'));$o['fin']=rng_date(rng_attr($n,'FechaFinalPago'));$o['pago']=rng_date(rng_attr($n,'FechaPago'));$o['tipo']=rng_attr($n,'TipoNomina')??'';
    if (!$o['inicio'] || !$o['fin'] || $o['inicio']>$o['fin'] || !$o['rfc']) {$o['motivo']='Falta RFC o período trabajado válido.';return $o;}
    if ($o['inicio']<$scope['desde'] || $o['inicio']>$scope['hasta']) {$o['estado']='FUERA_RANGO';$o['motivo']='FechaInicialPago fuera del rango elegido; excluido.';return $o;}
    if ($o['sat']==='CANCELADO') {$o['estado']='CANCELADO';$o['motivo']='No suma importes; cancelado en el visor.';return $o;}
    if ($o['sat']!=='VIGENTE') {$o['motivo']='Estatus SAT sin confirmar como VIGENTE; excluido de importes.';return $o;}
    if ($o['fin']>$scope['hasta']) {$o['motivo']='El período termina fuera del rango; no se prorrateó.';return $o;}
    if (rng_attr($n,'Version')!=='1.2') {$o['motivo']='Versión de Nómina no validada para este cruce.';return $o;}
    $o['atributos']=['Rfc'=>$o['rfc'],'Ejercicio'=>$o['pago']?substr($o['pago'],0,4):null];
    foreach(['NumEmpleado','Curp','NumSeguridadSocial'] as $k) $o['atributos'][$k]=rng_attr($nr,$k);
    try {
        $sums=['Percepcion'=>0,'Deduccion'=>0,'OtroPago'=>0];
        foreach ($ns as $ni=>$n) {
        if (rng_attr($n,'Version')!=='1.2' || rng_date(rng_attr($n,'FechaInicialPago'))!==$o['inicio'] || rng_date(rng_attr($n,'FechaFinalPago'))!==$o['fin'] || rng_date(rng_attr($n,'FechaPago'))!==$o['pago']) throw new RuntimeException('Complementos de nómina con versiones o fechas diferentes; requieren revisión por separado.');
        $nr2=rng_child($n,'Receptor');
        foreach(['NumEmpleado','Curp','NumSeguridadSocial'] as $ak) { $av=rng_attr($nr2,$ak); if ($av!==null && $o['atributos'][$ak]!==null && ncc_norm($av)!==ncc_norm($o['atributos'][$ak])) throw new RuntimeException('Datos de receptor diferentes entre complementos.'); if ($o['atributos'][$ak]===null) $o['atributos'][$ak]=$av; }
        $part=['Percepcion'=>0,'Deduccion'=>0,'OtroPago'=>0];
        foreach(['Percepciones'=>'Percepcion','Deducciones'=>'Deduccion','OtrosPagos'=>'OtroPago'] as $parent=>$group) {
            $node=rng_child($n,$parent);
            foreach($node!==null?($node->xpath('./*[local-name()="'.$group.'"]')?:[]):[] as $c) {
                $code=ncc_norm(rng_attr($c,'Clave'));$typ=rng_attr($c,'Tipo'.$group);if ($group==='Percepcion') $typ=rng_attr($c,'TipoPercepcion');
                if ($code==='' || !$typ) throw new RuntimeException('Concepto sin Clave o tipo SAT.');
                $g=$group==='Percepcion'?ncc_units(rng_attr($c,'ImporteGravado')):null;
                $e=$group==='Percepcion'?ncc_units(rng_attr($c,'ImporteExento')):null;
                $v=$group==='Percepcion'?($g===null||$e===null?null:$g+$e):ncc_units(rng_attr($c,'Importe'));
                if ($v===null || $v<0) throw new RuntimeException('Importe obligatorio ausente o negativo en '.$code.'.');
                $part[$group]+=$v;
                $o['conceptos'][]=['complemento'=>$ni+1,'clave'=>$code,'grupo'=>$group,'tipo'=>$typ,'concepto'=>rng_attr($c,'Concepto'),'importe'=>$v,'gravado'=>$g,'exento'=>$e,'subsidio_causado'=>ncc_units(rng_attr(rng_child($c,'SubsidioAlEmpleo'),'SubsidioCausado'))];
            }
        }
        foreach(['TotalPercepciones'=>'Percepcion','TotalDeducciones'=>'Deduccion','TotalOtrosPagos'=>'OtroPago'] as $attr=>$group) {
            $v=ncc_units(rng_attr($n,$attr));
            if ($v===null) {if ($part[$group]!==0) throw new RuntimeException('Falta '.$attr.'.');$v=0;}
            if (abs($v-$part[$group])>10000) throw new RuntimeException($attr.' no coincide con sus conceptos.');
        }
        foreach($part as $g=>$v) $sums[$g]+=$v;
        }
        $net=ncc_units(rng_attr($x,'Total'));if ($net===null) throw new RuntimeException('Falta Total del comprobante.');
        if (abs($net-($sums['Percepcion']+$sums['OtroPago']-$sums['Deduccion']))>10000) throw new RuntimeException('Total del comprobante no coincide con percepciones + otros pagos - deducciones.');
        $o['totales']=['TOTALPER'=>$sums['Percepcion']+$sums['OtroPago'],'TOTALDED'=>$sums['Deduccion'],'NETO'=>$net];
        $o['estado']='INCLUIDO';$o['motivo']='Vigente; período completo dentro del rango.';
        $o['configurados']=[];foreach($scope['reglas']??[] as $key=>$rule)if($rule['estado']==='RELACIONADO')$o['configurados'][$key]=ncm_eval($x,$rule);
    } catch(Throwable $ex) {$o['estado']='INCIDENCIA';$o['motivo']=$ex->getMessage();}
    return $o;
}
function ncc_aggregate(iterable $docs): array {
    $out=['empleados'=>[],'periodos'=>[],'estados'=>[],'sin_rfc'=>0,'claves'=>[]];$seen=[];
    foreach($docs as $d) {
        if (isset($seen[$d['uuid']])) continue;$seen[$d['uuid']]=true;
        $out['estados'][$d['estado']]=($out['estados'][$d['estado']]??0)+1;
        if ($d['estado']==='FUERA_RANGO') continue;
        $rfc=$d['rfc'];if ($rfc==='') {$out['sin_rfc']++;continue;}
        $a=&$out['empleados'][$rfc];if (!$a) $a=['validos'=>0,'incidencias'=>0,'cancelados'=>0,'uuid'=>[],'periodos'=>[],'conceptos'=>[],'totales'=>['TOTALPER'=>0,'TOTALDED'=>0,'NETO'=>0],'atributos'=>[]];
        if ($d['estado']==='CANCELADO') {$a['cancelados']++;unset($a);continue;}
        if ($d['estado']!=='INCLUIDO') {$a['incidencias']++;unset($a);continue;}
        $a['validos']++;$a['uuid'][]=$d['uuid'];$pk=$d['inicio'].' / '.$d['fin'];$a['periodos'][$pk]=[$d['inicio'],$d['fin']];$out['periodos'][$pk]=($out['periodos'][$pk]??0)+1;
        foreach($d['totales'] as $k=>$v) $a['totales'][$k]+=$v;
        foreach($d['atributos'] as $k=>$v) {if ($v===null || $v==='') $a['atributos'][$k]['faltan']=true;else $a['atributos'][$k]['valores'][(string)$v]=true;}
        foreach($d['configurados']??[] as $key=>$v){
            $cv=&$a['configurados'][$key];if(!$cv)$cv=['sum'=>0,'textos'=>[],'faltan'=>0,'errores'=>[],'encontrados'=>0];
            $cv['sum']+=$v['sum'];$cv['faltan']+=$v['faltan'];$cv['encontrados']+=$v['encontrados'];$cv['errores']=array_values(array_unique(array_merge($cv['errores'],$v['errores'])));$cv['textos']+=$v['textos'];unset($cv);
        }
        foreach($d['conceptos'] as $c) {
            $k=$c['clave'];$signature=$c['grupo'].'|'.$c['tipo'];$a['conceptos'][$k]['sum']=($a['conceptos'][$k]['sum']??0)+$c['importe'];$a['conceptos'][$k]['firmas'][$signature]=true;
            $out['claves'][$k][$signature]=true;
        }
        unset($a);
    }
    return $out;
}
function ncc_covered(array $periods,string $from,string $to): bool {
    $ranges=array_values($periods);usort($ranges,fn($a,$b)=>strcmp($a[0],$b[0]));$next=$from;
    foreach($ranges as [$a,$b]) {
        if ($b<$next) continue;
        if ($a>$next) return false;
        $next=(new DateTimeImmutable($b))->modify('+1 day')->format('Y-m-d');if ($next>$to) return true;
    }
    return false;
}
function ncc_compare(array $c,?array $w,?array $x,array $scope,bool $unknown=false): array {
    $p=$c['plan'];$cell=$w['valores'][$c['indice']]??['sum'=>0,'count'=>0,'textos'=>[],'error'=>false];
    $out=['columna'=>$c['letra'],'campo'=>$c['encabezado'],'clave'=>$c['clave_campo'],'workbeat'=>$p['numero']?($cell['count']?ncc_money($cell['sum']):null):implode(' | ',array_keys($cell['textos'])),'xml'=>null,'diferencia'=>null,'estado'=>'PENDIENTE_REGLA','nota'=>$p['regla'],'numero'=>$p['numero']];
    if ($p['modo']==='informativo') {$out['estado']='NO_DISPONIBLE_XML';if($cell['error'])$out['workbeat']=implode(' | ',array_keys($cell['textos']));return $out;}
    if ($p['modo']==='pendiente') return $out;
    if ($cell['error']) {$out['estado']='DATO_INVALIDO';$out['workbeat']=implode(' | ',array_keys($cell['textos']));$out['nota'].=' Valor Workbeat incompatible con la operación elegida.';return $out;}
    if (!$x || !$x['validos']) {$out['estado']=($x['incidencias']??0)>0?'XML_INCOMPLETO':'SIN_XML_VIGENTE';return $out;}
    if ($p['modo']==='configurado') {
        $cv=$x['configurados'][$c['clave_campo']]??null;
        if(!$cv || $cv['faltan'] || $cv['errores']){$out['estado']='REVISAR_EQUIVALENCIA';$out['nota'].=' '.implode(' ', $cv['errores']??[]).' Faltan datos de la relación configurada; no se asumió cero.';return $out;}
        if($p['numero']){$value=$cv['sum'];$out['xml']=ncc_money($value);$out['diferencia']=round(($value-$cell['sum'])/1000000,2);}
        else{$xa=array_keys($cv['textos']);$wa=array_keys($cell['textos']);$out['xml']=implode(' | ',$xa);if(!$w){$out['estado']='SOLO_XML';return $out;}if(count($xa)!==1||count($wa)!==1){$out['estado']='DATOS_MULTIPLES';return $out;}$equal=ncc_norm($wa[0])===ncc_norm($xa[0]);}
    } elseif ($p['modo']==='concepto') {
        $concept=$x['conceptos'][$p['codigo']]??null;
        if ($concept && array_keys($concept['firmas'])!==[$p['grupo'].'|'.$p['tipo']]) {$out['estado']='REVISAR_EQUIVALENCIA';$out['nota'].=' La clave aparece en otro grupo/tipo SAT.';return $out;}
        $value=$concept['sum']??0;$out['xml']=ncc_money($value);$out['diferencia']=round(($value-$cell['sum'])/1000000,2);
    } elseif ($p['modo']==='total') {
        $value=$x['totales'][$p['codigo']];$out['xml']=ncc_money($value);$out['diferencia']=round(($value-$cell['sum'])/1000000,2);
    } else {
        $v=$x['atributos'][$p['atributo']]??[];$out['xml']=implode(' | ',array_keys($v['valores']??[]));
        if (!empty($v['faltan']) || !$out['xml']) {$out['estado']='DATO_XML_FALTANTE';return $out;}
        if (!$w) {$out['estado']='SOLO_XML';return $out;}
        $wa=array_keys($cell['textos']);$xa=array_keys($v['valores']??[]);
        if (count($wa)!==1 || count($xa)!==1) {$out['estado']='DATOS_MULTIPLES';return $out;}
        $equal=ncc_norm($wa[0])===ncc_norm($xa[0]);
    }
    if (!$w) {$out['estado']='SOLO_XML';$out['diferencia']=null;return $out;}
    if ($unknown || $x['incidencias']>0) {$out['estado']='XML_INCOMPLETO';$out['nota'].=' Importe XML parcial: hay documentos excluidos por incidencia.';return $out;}
    if (!ncc_covered($x['periodos'],$scope['desde'],$scope['hasta'])) {$out['estado']='COBERTURA_PARCIAL';$out['nota'].=' Los períodos XML no cubren todo el rango del maestro; revisar altas/bajas y documentos faltantes.';return $out;}
    if ($p['numero']) {
        $out['estado']=abs($out['diferencia'])<=0.010001?($value===0 && $cell['sum']===0?'SIN_MOVIMIENTO':'COINCIDE'):'DIFERENCIA';
        if (!$cell['count']) $out['nota'].=' Celda vacía del maestro tratada como sin movimiento (0), conservada en blanco.';
    } else $out['estado']=$equal?'COINCIDE':'DIFERENCIA';
    return $out;
}
