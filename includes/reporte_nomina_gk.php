<?php
/** Reporte de detalle Nómina GK. Solo lectura; no modifica CFDI ni conciliaciones. */
require_once __DIR__.'/reporte_xml_recibidos.php';

function rng_formato(): array {
    static $v;
    return $v ??= json_decode(file_get_contents(__DIR__.'/nomina_gk/formato.json'), true, 512, JSON_THROW_ON_ERROR);
}
function rng_attr(?SimpleXMLElement $n, string $name): ?string {
    if ($n === null) return null;
    foreach ($n->attributes() as $k=>$v) if (strcasecmp($k,$name)===0) return trim((string)$v);
    return null;
}
function rng_child(?SimpleXMLElement $n, string $name): ?SimpleXMLElement {
    if ($n === null) return null;
    $a=$n->xpath('./*[local-name()="'.$name.'"]');
    return $a[0]??null;
}
function rng_date(?string $v): ?string {
    if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}/',$v)) return null;
    $s=substr($v,0,10); $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
    return $d && $d->format('Y-m-d')===$s ? $s : null;
}
function rng_num(?string $v): ?float { return $v!==null && $v!=='' && is_numeric($v) ? (float)$v : null; }
function rng_value($v) { return $v===null || $v==='' ? null : $v; }

function rng_parse(string $raw): ?SimpleXMLElement {
    $raw=ltrim($raw,"\xEF\xBB\xBF \r\n\t");
    if ($raw!=='' && $raw[0]!=='<') {
        $raw=base64_decode($raw,true);
        if ($raw===false) return null;
    }
    if ($raw==='' || strlen($raw)>16*1024*1024 || preg_match('/<!DOCTYPE|<!ENTITY/i',$raw)) return null;
    $prev=libxml_use_internal_errors(true);
    try {
        $x=simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET);
        return $x!==false && $x->getName()==='Comprobante' ? $x : null;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
}

function rng_load_xml(array $d, array &$issues): ?SimpleXMLElement {
    $candidates=[(string)($d['xml_base64']??'')];
    $ruta=trim((string)($d['ruta_xml']??''));
    // Solo archivos locales, nunca wrappers PHP ni descargas de red.
    if ($ruta!=='' && !str_contains($ruta,'://') && !str_starts_with($ruta,'\\\\') && !str_starts_with($ruta,'//') && is_file($ruta) && is_readable($ruta)) {
        $size=filesize($ruta);
        if ($size!==false && $size<=16*1024*1024) $candidates[]=(string)file_get_contents($ruta);
    }
    foreach ($candidates as $raw) {
        $x=rng_parse($raw); if ($x===null) continue;
        $t=rng_child(rng_child($x,'Complemento'),'TimbreFiscalDigital');
        $uuid=rng_attr($t,'UUID');
        if (!$uuid || strcasecmp($uuid,(string)$d['uuid'])!==0) continue;
        if (strtoupper(rng_attr($x,'TipoDeComprobante')??'')!=='N') continue;
        $mismatch=false;
        foreach (['Emisor'=>'rfc_emisor','Receptor'=>'rfc_receptor'] as $node=>$field) {
            $rfc=rng_attr(rng_child($x,$node),'Rfc');
            if (!empty($d[$field]) && (!$rfc || strcasecmp($rfc,trim($d[$field]))!==0)) $mismatch=true;
        }
        if ($mismatch) continue;
        return $x;
    }
    $issues[]='XML no disponible, inválido o no coincide con UUID/RFC/tipo del CFDI. Se conserva una fila con datos de la base; el detalle queda en blanco.';
    return null;
}

function rng_calendar(PDO $pdo, int $empresa): array {
    try {
        $q=$pdo->prepare('SELECT anio,fecha_inicio_periodo1,dias_periodo FROM nomina_periodo_config WHERE id_empresa=? ORDER BY anio');
        $q->execute([$empresa]);
        return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'warning'=>''];
    } catch (PDOException $e) {
        error_log('[Reporte nomina GK] Calendario no disponible: '.$e->getMessage());
        return ['rows'=>[],'warning'=>'No se pudo consultar nomina_periodo_config. El número de período queda en blanco; las fechas y el ejercicio sí se exportan.'];
    }
}

function rng_period_number(array $base, array $calendar): ?int {
    // No confundir PeriodicidadPago con el número interno, ni usar semana ISO.
    $days=['02'=>7,'03'=>14][$base[3]??'']??0;
    if (!$days || empty($base[4]) || empty($base[6]) || empty($base[7])) return null;
    $begin=new DateTimeImmutable($base[6]); $end=new DateTimeImmutable($base[7]);
    if ((int)$begin->diff($end)->format('%r%a')!==$days-1) return null;
    $candidates=[];
    foreach ($calendar as $cfg) {
        if ((int)($cfg['anio']??0)!==(int)$base[4] || (int)($cfg['dias_periodo']??0)!==$days) continue;
        $anchor=rng_date($cfg['fecha_inicio_periodo1']??null);
        if ($anchor===null) continue;
        $offset=(int)(new DateTimeImmutable($anchor))->diff($begin)->format('%r%a');
        if ($offset<0 || $offset%$days!==0) continue;
        $number=intdiv($offset,$days)+1;
        if ($number>(int)ceil(366/$days)) continue;
        $candidates[$number]=true;
    }
    return count($candidates)===1 ? (int)array_key_first($candidates) : null;
}

/** @return array{rows:array,na:array,issues:array} Índices de columnas basados en 1. */
function rng_document(array $d, array $calendar = []): array {
    $issues=[]; $x=rng_load_xml($d,$issues);
    $n=rng_child(rng_child($x,'Complemento'),'Nomina');
    if ($x!==null && ($n===null || rng_attr($n,'Version')!=='1.2')) {
        $issues[]='Complemento Nómina ausente o versión diferente de 1.2; revisar manualmente. No se inventa detalle.';
        $n=null;
    }
    $nr=rng_child($n,'Receptor'); $ne=rng_child($n,'Emisor');
    $r=rng_child($x,'Receptor'); $e=rng_child($x,'Emisor');
    $t=rng_child(rng_child($x,'Complemento'),'TimbreFiscalDigital');
    $base=array_fill(1,43,null);
    $base[1]=rng_attr($ne,'RegistroPatronal');
    $base[2]=rng_date(rng_attr($x,'Fecha')??($d['fecha_emision']??null));
    $base[3]=rng_attr($nr,'PeriodicidadPago')??rng_value($d['nomina_periodicidad_pago']??null);
    $base[9]=rng_attr($nr,'Departamento');
    $base[10]=rng_attr($nr,'NumEmpleado')??rng_value($d['nomina_num_empleado']??null);
    $base[11]=rng_attr($r,'Nombre')??rng_value($d['nombre_receptor']??null);
    $base[12]=rng_attr($nr,'NumSeguridadSocial');
    $base[13]=rng_date(rng_attr($n,'FechaPago')??($d['nomina_fecha_pago']??null));
    $base[14]=rng_num(rng_attr($x,'Total')??(isset($d['total_xml'])?(string)$d['total_xml']:null));
    $base[15]=$base[14];
    $status=trim((string)($d['estatus_sat']??''));
    $base[16]=$status!=='' ? (stripos($status,'cancel')!==false?'Cancelado':(strcasecmp($status,'Vigente')===0 && $t!==null?'Timbrado':$status)) : ($t!==null?'Timbrado':null);
    $base[18]=strtoupper((string)$d['uuid']);
    $base[23]=$base[13]!==null?(int)substr($base[13],0,4):null;
    $base[24]=rng_attr($nr,'Curp');
    $base[25]=rng_attr($r,'Rfc')??rng_value($d['rfc_receptor']??null);
    $base[26]=rng_attr($e,'Rfc')??rng_value($d['rfc_emisor']??null);
    $base[27]=rng_attr($x,'Version')??rng_value($d['version_cfdi']??null);
    $base[31]=rng_date(rng_attr($t,'FechaTimbrado'));
    $base[37]=$base[31]!==null?(int)substr($base[31],0,4):null;
    $base[38]=rng_date(rng_attr($n,'FechaInicialPago')??($d['nomina_fecha_inicial_pago']??null));
    $base[39]=rng_date(rng_attr($n,'FechaFinalPago')??($d['nomina_fecha_final_pago']??null));
    // Criterio solicitado: ejercicio por año de pago; fechas de período del XML.
    $base[6]=$base[38];
    $base[7]=$base[39];
    $fechaEjercicio=$base[13]??$base[7]??$base[6];
    $base[4]=$fechaEjercicio!==null?(int)substr($fechaEjercicio,0,4):null;
    $base[5]=rng_period_number($base,$calendar);
    $base[40]=$base[13]!==null?(int)substr($base[13],5,2):null;
    $base[41]=$base[2]!==null?(int)substr($base[2],0,4):null;
    $base[42]='CFDI'; $base[43]='Nómina';
    $per=rng_child($n,'Percepciones');
    $sep=rng_child($per,'SeparacionIndemnizacion'); $jub=rng_child($per,'JubilacionPensionRetiro');
    $used=['sep'=>false,'jub'=>false]; $rows=[]; $nas=[];
    foreach (['Percepciones'=>['Percepcion','TipoPercepcion'],'Deducciones'=>['Deduccion','TipoDeduccion'],'OtrosPagos'=>['OtroPago','TipoOtroPago']] as $container=>$cfg) {
        $parent=rng_child($n,$container);
        $nodes=$parent!==null ? $parent->xpath('./*[local-name()="'.$cfg[0].'"]') : [];
        foreach ($nodes as $node) {
            $row=$base; $na=[];
            $row[19]=rng_attr($node,'Clave'); $row[20]=rng_attr($node,'Concepto'); $row[28]=rng_attr($node,$cfg[1]);
            if ($container==='Percepciones') {
                $row[21]=rng_num(rng_attr($node,'ImporteGravado')); $row[22]=rng_num(rng_attr($node,'ImporteExento')); $na[30]=true;
            } else {
                $row[30]=rng_num(rng_attr($node,'Importe')); $na[21]=$na[22]=true;
            }
            $sub=rng_child($node,'SubsidioAlEmpleo');
            if ($container==='OtrosPagos' && $sub!==null) $row[34]=rng_num(rng_attr($sub,'SubsidioCausado')); else $na[34]=true;
            foreach ([32,33,35,36] as $col) $na[$col]=true;
            $which=$container==='Percepciones' ? (in_array($row[28],['022','023','025'],true)?'sep':(in_array($row[28],['039','044'],true)?'jub':'')) : '';
            $special=$which==='sep'?$sep:($which==='jub'?$jub:null);
            if ($which!=='' && !$used[$which] && $special!==null) {
                $used[$which]=true;
                foreach ([32=>'IngresoNoAcumulable',35=>'IngresoAcumulable'] as $col=>$attr) { $row[$col]=rng_num(rng_attr($special,$attr)); unset($na[$col]); }
                if ($which==='sep') foreach ([33=>'TotalPagado',36=>'UltimoSueldoMensOrd'] as $col=>$attr) { $row[$col]=rng_num(rng_attr($special,$attr)); unset($na[$col]); }
            }
            $rows[]=$row; $nas[]=$na;
        }
    }
    if (!$rows) {
        $rows[]=$base; $nas[]=[];
        if ($n!==null) $issues[]='El complemento no contiene Percepcion, Deduccion ni OtroPago; revisar el XML.';
    }
    if (($sep!==null && !$used['sep']) || ($jub!==null && !$used['jub'])) $issues[]='Hay un bloque especial sin percepción compatible; sus importes no se asignaron a un concepto arbitrario.';
    return ['rows'=>$rows,'na'=>$nas,'issues'=>$issues];
}

/** Mismos criterios aplicados por listar_facturas, restringidos al subconjunto N. */
function rng_filter(PDO $pdo,int $empresa,array $req): array {
    $where="f.id_empresa=:empresa AND f.id_tipo_comprobante='N'"; $params=[':empresa'=>$empresa];
    foreach (['inicio','fin'] as $key) {
        $v=trim((string)($req[$key]??''));
        if ($v!=='' && (strlen($v)!==10 || rng_date($v)!==$v)) throw new InvalidArgumentException('Fecha inválida.');
        if ($v==='') continue;
        $where.=$key==='inicio'?' AND f.fecha_emision>=:inicio':' AND f.fecha_emision<:fin';
        $params[':'.$key]=$key==='inicio'?$v:(new DateTimeImmutable($v))->modify('+1 day')->format('Y-m-d');
    }
    if (!empty($req['inicio']) && !empty($req['fin']) && $req['inicio']>$req['fin']) throw new InvalidArgumentException('Rango de fechas inválido.');
    $tipo=strtoupper(trim((string)($req['tipo']??'')));
    if ($tipo!=='' && $tipo!=='N') $where.=' AND 1=0';
    // Los dos filtros rápidos excluyen N en el visor; no se ignoran silenciosamente.
    if ((int)($req['ppd_sin_complemento']??0)===1 || (int)($req['pue_sin_pago_empresa']??0)===1) $where.=' AND 1=0';
    $metodo=strtoupper(trim((string)($req['metodo']??'')));
    if (in_array($metodo,['PUE','PPD'],true)) { $where.=' AND f.metodo_pago=:metodo'; $params[':metodo']=$metodo; }
    $search=trim((string)($req['busqueda']??'')); $type=strtolower(trim((string)($req['tipo_busqueda']??'')));
    if ($search!=='' && $type==='uuid') { $where.=' AND f.uuid LIKE :busqueda'; $params[':busqueda']=$search.'%'; }
    $catalogs=['rfc_emisor'=>['cat_emisores','rfc','id_emisor'],'nombre_emisor'=>['cat_emisores','nombre','id_emisor'],'rfc_receptor'=>['cat_receptores','rfc','id_receptor'],'nombre_receptor'=>['cat_receptores','nombre','id_receptor']];
    if ($search!=='' && isset($catalogs[$type])) {
        [$table,$field,$id]=$catalogs[$type];
        $q=$pdo->prepare("SELECT $id FROM $table WHERE id_empresa=? AND $field LIKE ? ORDER BY $field LIMIT 500");
        $q->execute([$empresa,$search.'%']); $ids=$q->fetchAll(PDO::FETCH_COLUMN); $marks=[];
        foreach ($ids as $i=>$value) { $k=':bus_'.$i; $marks[]=$k; $params[$k]=(int)$value; }
        $where.=$marks?' AND f.'.$id.' IN ('.implode(',',$marks).')':' AND 1=0';
    }
    return [$where,$params];
}

function rng_write_all($fh,string $s): void {
    $len=strlen($s); $offset=0;
    while ($offset<$len) { $n=fwrite($fh,substr($s,$offset)); if (!$n) throw new RuntimeException('No se pudo escribir el reporte; compruebe espacio en disco.'); $offset+=$n; }
}
function rng_atomic_json(string $file,array $value): void {
    $data=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $fh=fopen($file.'.tmp','wb'); if (!$fh) throw new RuntimeException('No se pudo guardar el avance.');
    try { rng_write_all($fh,$data); } finally { fclose($fh); }
    if (!rename($file.'.tmp',$file)) throw new RuntimeException('No se pudo confirmar el avance.');
}
function rng_row_xml(int $r,array $values,bool $detail=true,bool $header=false): string {
    $s='<row r="'.$r.'"'.(!$detail?' ht="64" customHeight="1"':'').'>'; $col=0;
    foreach ($values as $v) {
        $col++;
        if ($v===null || $v==='') { $s.='<c r="'.rxr_col_name($col).$r.'"/>'; continue; }
        if ($header) $s.=rxr_cell_inline($col,$r,$v,$detail?1:7);
        elseif ($detail && in_array($col,[2,6,7,13,31,38,39],true) && rng_date((string)$v)!==null) {
            $serial=(int)(new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable(substr($v,0,10)))->format('%r%a');
            $s.=rxr_cell_num($col,$r,$serial,5);
        } elseif ($detail && in_array($col,[14,15,21,22,30,32,33,34,35,36],true)) $s.=rxr_cell_num($col,$r,$v,4);
        elseif (is_int($v)||is_float($v)) $s.=rxr_cell_num($col,$r,$v);
        else $s.=rxr_cell_inline($col,$r,$v,$detail?0:6);
    }
    return $s."</row>\n";
}
function rng_sheet_open($fh,array $headers,array $widths,bool $detail=false): void {
    $cols=''; foreach ($widths as $i=>$w) { $c=$i+1; $cols.='<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>'; }
    rng_write_all($fh,'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$cols.'</cols><sheetData>'.rng_row_xml(1,$headers,$detail,true));
}
function rng_sheet_close($fh,int $rows,int $cols): void {
    rng_write_all($fh,'</sheetData><autoFilter ref="A1:'.rxr_col_name($cols).max(1,$rows).'"/></worksheet>'); fclose($fh);
}
function rng_styles(): string {
    $xml=file_get_contents(__DIR__.'/nomina_gk/styles.xml');
    // Normalizar el formato personalizado original (usaba un ID reservado de Excel).
    $xml=str_replace('numFmtId="43"','numFmtId="164"',$xml);
    $xml=str_replace('<numFmts count="1">','<numFmts count="2">',$xml);
    $xml=str_replace('</numFmts>','<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>',$xml);
    $xml=str_replace('<cellXfs count="4">','<cellXfs count="8">',$xml);
    return str_replace('</cellXfs>','<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs>',$xml);
}
function rng_xlsx(string $target,array $sheets): void {
    $w=new XlsxSimpleWriter($target.'.tmp');
    $types=''; $rels=''; $names=''; $i=0;
    foreach ($sheets as $name=>$file) {
        $i++; $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $rels.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        $names.='<sheet name="'.rxr_xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
        $w->addFile('xl/worksheets/sheet'.$i.'.xml',$file);
    }
    $rels.='<Relationship Id="rId'.($i+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $w->addString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
    $w->addString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $w->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$names.'</sheets></workbook>');
    $w->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    $w->addString('xl/styles.xml',rng_styles()); $w->close();
    if (!rename($target.'.tmp',$target)) throw new RuntimeException('No se pudo finalizar el Excel.');
}

function rng_finish(string $dir,array $m): void {
    $fmt=rng_formato();
    $fh=fopen($dir.'/detalle.sheet','wb'); rng_sheet_open($fh,$fmt['headers'],$fmt['widths'],true);
    for ($i=0;$i<$m['chunks'];$i++) {
        $in=fopen($dir.'/detail_'.$i.'.part','rb');
        if (!$in) throw new RuntimeException('Falta un bloque del reporte.');
        if (stream_copy_to_stream($in,$fh)===false) throw new RuntimeException('No se pudo unir el detalle.'); fclose($in);
    }
    rng_sheet_close($fh,$m['rows']+1,43);
    rng_xlsx($dir.'/detalle.xlsx',['Detalle nómina'=>$dir.'/detalle.sheet']);
    $fh=fopen($dir.'/resumen.sheet','wb');
    rng_sheet_open($fh,['Columna','Campo del formato','Disponibilidad','Fuente','Filas con dato','Filas sin dato','No aplica / no repetir','CFDI con dato faltante','Observación / información por obtener'],[10,31,23,60,17,17,25,27,95]);
    foreach ($fmt['mapping'] as $i=>$map) {
        $s=$m['stats'][$i+1];
        rng_write_all($fh,rng_row_xml($i+2,[rxr_col_name($i+1),$map['header'],$map['status'],$map['source'],$s['ok'],$s['missing'],$s['na'],$s['docs'],$map['note']],false));
    }
    rng_sheet_close($fh,44,9);
    $fh=fopen($dir.'/faltantes.sheet','wb');
    rng_sheet_open($fh,['UUID','RFC receptor','Nombre receptor','Campos faltantes (se conservaron en blanco)','Incidencia XML / detalle'],[39,17,40,110,100]);
    for ($i=0;$i<$m['chunks'];$i++) {
        $in=fopen($dir.'/issues_'.$i.'.part','rb');
        if (stream_copy_to_stream($in,$fh)===false) throw new RuntimeException('No se pudo unir el resumen.'); fclose($in);
    }
    rng_sheet_close($fh,$m['issue_rows']+1,5);
    $criteria=[
        ['Empresa',$m['empresa_nombre'].' ('.$m['empresa_rfc'].')'],
        ['Generado',date('Y-m-d H:i:s')],
        ['CFDI seleccionados',$m['total']],['CFDI procesados',$m['done']],['Renglones de detalle',$m['rows']],['CFDI con incidencia XML',$m['xml_issues']],
        ['Selección','CFDI N coincidentes con los filtros aplicados del visor; todos los registros, no solo la página. UUID congelados al iniciar; datos leídos durante la exportación.'],
        ['Fecha del filtro','Fecha de emisión del comprobante; no se cambia por FechaPago ni por el período de nómina.'],
        ['Granularidad','Una fila por Percepcion, Deduccion u OtroPago. Un XML inaccesible conserva una fila con la información disponible de la base.'],
        ['Total y Neto','Ambos son Comprobante@Total (neto fiscal). Se repiten por concepto: NO SUMAR filas sin agrupar primero por UUID. Neto no acredita un depósito bancario.'],
        ['Importes','No se inventan ceros. Un cero explícito del XML permanece numérico. Los campos ausentes o que no aplican quedan vacíos.'],
        ['Ejercicio','Año de FechaPago. Si falta una fecha de pago válida, se usa el año de FechaFinalPago y, en último caso, FechaInicialPago. No se sustituye por el año de emisión.'],
        ['Inicio / fin de período','F/G se llenan con FechaInicialPago y FechaFinalPago del XML o sus campos guardados en la base. Coinciden con AL/AM.'],
        ['Número de período','No es un atributo estándar del XML. Se calcula solo para periodicidad 02 (7 días) o 03 (14 días), usando el calendario explícito de empresa y ejercicio. Inicio y fin deben coincidir exactamente con un período completo.'],
        ['Períodos sin equivalencia','Sin calendario coincidente, períodos parciales, quincenales, mensuales, extraordinarios (99) y otras periodicidades quedan en blanco y se cuentan como faltantes. No se inventan números ISO, mensuales o folios.'],
        ['Calendario utilizado',!empty($m['calendar']['warning'])?$m['calendar']['warning']:(empty($m['calendar']['rows'])?'No hay calendario configurado para esta empresa. Los números de período quedan pendientes.':'Calendario configurado por empresa/año, leído al iniciar la exportación.')],
        ['Clave SAT Desc','Pendiente incorporar los catálogos SAT de cada grupo. Concepto no sustituye a la descripción oficial.'],
        ['Bloques especiales','Ingreso acumulable/no acumulable, TotalPagado y USMO se ponen solo una vez por bloque, en la primera percepción compatible. No se repiten en cada concepto.'],
        ['USMO','Se interpreta como UltimoSueldoMensOrd del XML. Confirmar con el contador que esa es la definición del encabezado original.'],
        ['Resumen campos','Filas con dato + Filas sin dato + No aplica/no repetir = total de filas de detalle. CFDI con dato faltante cuenta UUID distintos por columna.'],
        ['Estatus','Se conserva el estado guardado en el visor. No se hace consulta SAT en vivo; no se omiten cancelados si los filtros los incluyen.'],
        ['Base proporcionada','El SQL recibido contiene estructura, sin datos. La disponibilidad real se calcula al exportar en tu servidor.'],
        ['Formato','43 columnas, encabezados, orden, anchos y estilos del archivo reporte nomina gk.xlsx. Fechas y cantidades se guardan como valores Excel.'],
        ['Referencia técnica SAT',$fmt['source_sat']],
    ];
    foreach ($m['filters'] as $k=>$v) $criteria[]=['Filtro: '.$k,(string)$v];
    $fh=fopen($dir.'/criterios.sheet','wb'); rng_sheet_open($fh,['Criterio','Valor / explicación'],[30,150]);
    foreach ($criteria as $i=>$r) rng_write_all($fh,rng_row_xml($i+2,$r,false));
    rng_sheet_close($fh,count($criteria)+1,2);
    rng_xlsx($dir.'/resumen.xlsx',['Resumen campos'=>$dir.'/resumen.sheet','CFDI por revisar'=>$dir.'/faltantes.sheet','Criterios'=>$dir.'/criterios.sheet']);
}

function rng_job_dir(string $token): string {
    if (!rxr_valid_token($token)) throw new InvalidArgumentException('Token inválido.');
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'sgksat_nomina_gk_'.$token;
}
function rng_cleanup_old(): void {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'sgksat_nomina_gk_*',GLOB_ONLYDIR)?:[] as $dir) {
        $meta=$dir.'/meta.json';
        if (!is_file($meta) || filemtime($meta)>time()-86400) continue;
        $lock=fopen($dir.'/job.lock','c'); if (!$lock) continue;
        if (flock($lock,LOCK_EX|LOCK_NB)) {
            foreach (glob($dir.'/*')?:[] as $p) if (is_file($p) && basename($p)!=='job.lock') @unlink($p);
            flock($lock,LOCK_UN); fclose($lock); @unlink($dir.'/job.lock'); @rmdir($dir);
        } else fclose($lock);
    }
}
