<?php
require_once __DIR__.'/nomina_conceptos.php';
/** Tablas simples, editables, con tipos reales y filtros. Escritas en flujo. */
final class NccSheet {
    public $fh; public int $row=1; public string $path; private int $cols;
    public function __construct(string $path,array $headers) {
        $this->path=$path;$this->cols=count($headers);$this->fh=fopen($path,'wb');
        if (!$this->fh) throw new RuntimeException('No se pudo crear la hoja del informe.');
        ncx_write($this->fh,'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>');
        foreach($headers as $i=>$h) ncx_write($this->fh,'<col min="'.($i+1).'" max="'.($i+1).'" width="'.(in_array($h,['Campo','Regla / observación','Motivo','Valor'],true)?65:23).'" customWidth="1"/>');
        ncx_write($this->fh,'</cols><sheetData><row r="1" ht="32" customHeight="1">');
        foreach($headers as $i=>$h) ncx_write($this->fh,ncx_cell(rxr_col_name($i+1).'1',$h,2));
        ncx_write($this->fh,'</row>');
    }
    public function add(array $values,array $numeric=[]): void {
        $r=++$this->row;if ($r>1048576) throw new RuntimeException('Se excedió el límite de filas de Excel.');
        $max=0;foreach($values as $v) if(is_string($v)) $max=max($max,strlen($v));
        $s='<row r="'.$r.'"'.($max>90?' ht="'.min(90,max(42,14*ceil($max/65))).'" customHeight="1"':'').'>';
        foreach($values as $i=>$v) {
            $num=in_array($i,$numeric,true) && ($v===null || is_numeric($v));
            $s.=ncx_cell(rxr_col_name($i+1).$r,$v,$num?3:(is_string($v)&&strlen($v)>90?9:0),$num);
        }
        ncx_write($this->fh,$s.'</row>');
    }
    public function close(): void {
        if (!$this->fh) return;
        ncx_write($this->fh,'</sheetData><autoFilter ref="A1:'.rxr_col_name($this->cols).$this->row.'"/></worksheet>');fclose($this->fh);$this->fh=null;
    }
    public function __destruct() {if ($this->fh) fclose($this->fh);}
}
/** ZIP deflate sólo para este informe. No cambia los exportadores existentes. */
final class NccZip {
    private $fh; private array $entries=[]; private int $offset=0;
    public function __construct(string $path) {$this->fh=fopen($path,'wb');if (!$this->fh) throw new RuntimeException('No se pudo crear el Excel.');}
    private function write(string $s): void {ncx_write($this->fh,$s);$this->offset+=strlen($s);}
    public function addString(string $name,string $s): void {
        $p=tempnam(sys_get_temp_dir(),'ncc_zip_');if ($p===false) throw new RuntimeException('No se pudo crear el temporal ZIP.');
        try {if(file_put_contents($p,$s)!==strlen($s)) throw new RuntimeException('No se pudo escribir ZIP.');$this->addFile($name,$p);} finally {@unlink($p);}
    }
    public function addFile(string $name,string $path): void {
        $tmp=tempnam(sys_get_temp_dir(),'ncc_deflate_');if ($tmp===false) throw new RuntimeException('No se pudo comprimir el Excel.');$in=null;$out=null;
        try {
            $in=fopen($path,'rb');$out=fopen($tmp,'wb');if (!$in || !$out) throw new RuntimeException('No se pudo abrir una parte del Excel.');
            $ctx=deflate_init(ZLIB_ENCODING_RAW,['level'=>6]);if (!$ctx) throw new RuntimeException('No se pudo iniciar la compresión.');
            while(!feof($in)) {$data=fread($in,262144);if ($data===false) throw new RuntimeException('No se pudo leer una parte del Excel.');$compressed=deflate_add($ctx,$data,ZLIB_NO_FLUSH);if ($compressed===false) throw new RuntimeException('Error comprimiendo Excel.');ncx_write($out,$compressed);}
            $last=deflate_add($ctx,'',ZLIB_FINISH);if ($last===false) throw new RuntimeException('Error cerrando compresión.');ncx_write($out,$last);fclose($in);$in=null;fclose($out);$out=null;
            $size=(int)filesize($path);$packed=(int)filesize($tmp);$crc=(int)hexdec(hash_file('crc32b',$path));$offset=$this->offset;$nl=strlen($name);$date=((max(1980,(int)date('Y'))-1980)<<9)|((int)date('n')<<5)|(int)date('j');$time=((int)date('G')<<11)|((int)date('i')<<5)|((int)date('s')>>1);
            $this->write(pack('VvvvvvVVVvv',0x04034b50,20,0,8,$time,$date,$crc,$packed,$size,$nl,0).$name);
            $in=fopen($tmp,'rb');while(!feof($in)) {$data=fread($in,262144);if ($data===false) throw new RuntimeException('Error leyendo Excel comprimido.');$this->write($data);}fclose($in);$in=null;
            $this->entries[]=compact('name','size','packed','crc','offset','time','date');
        } finally {if ($in) fclose($in);if ($out) fclose($out);@unlink($tmp);}
    }
    public function close(): void {
        if (!$this->fh) return;$start=$this->offset;
        foreach($this->entries as $e) $this->write(pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,8,$e['time'],$e['date'],$e['crc'],$e['packed'],$e['size'],strlen($e['name']),0,0,0,0,0,$e['offset']).$e['name']);
        $n=count($this->entries);$this->write(pack('VvvvvVVv',0x06054b50,0,0,$n,$n,$this->offset-$start,$start,0));fclose($this->fh);$this->fh=null;
    }
    public function __destruct(){if ($this->fh) fclose($this->fh);}
}
function ncc_zip(string $path,array $sheets): void {
    $zip=function_exists('deflate_init')?new NccZip($path):new XlsxSimpleWriter($path);$types='';$wb='';$rels='';$i=0;
    foreach($sheets as $name=>$s) {
        $s->close();$i++;$types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb.='<sheet name="'.ncx_xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
        $rels.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        $zip->addFile('xl/worksheets/sheet'.$i.'.xml',$s->path);
    }
    $zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
    $zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>'.$wb.'</sheets><calcPr calcMode="auto" fullCalcOnLoad="1"/></workbook>');
    $zip->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $styles=str_replace('<cellXfs count="9">','<cellXfs count="10">',ncx_styles());
    $styles=str_replace('</cellXfs>','<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>',$styles);
    $zip->addString('xl/styles.xml',$styles);$zip->close();
}
function ncc_docs(string $dir,int $chunks): Generator {
    for($i=0;$i<$chunks;$i++) {
        $data=json_decode((string)file_get_contents($dir.'/bloque_'.$i.'.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($data as $d) yield $d;
    }
}
function ncc_finish(string $dir,array $m): array {
    $w=json_decode((string)file_get_contents($dir.'/fuente.json'),true,512,JSON_THROW_ON_ERROR);
    $a=ncc_aggregate(ncc_docs($dir,$m['bloques']));$scope=$m['scope'];$columns=$w['columnas'];
    $sheets=[];$fh=null;
    try {
        $sheets['CONTROL']=new NccSheet($dir.'/control.xml',['Concepto','Valor']);
        $sheets['RESUMEN CAMPOS']=new NccSheet($dir.'/resumen.xml',['Columna','Campo','Regla / observación','Workbeat total','XML total parcial o completo','Diferencia XML - Workbeat','Coincide','Diferencia','Sin movimiento','Otros / pendientes']);
        $sheets['DETALLE']=new NccSheet($dir.'/detalle.xml',['RFC','No. empleado','Renglones Workbeat','Columna','Campo','Workbeat','XML parcial o completo','Diferencia XML - Workbeat','Estado','Regla / observación','CFDI incluidos','CFDI con incidencia']);
        $sheets['CFDI']=new NccSheet($dir.'/cfdi.xml',['UUID','RFC','Inicio período','Fin período','Fecha pago','Tipo nómina','Estado SAT registrado','Uso en informe','Motivo']);
        $sheets['CONCEPTOS XML']=new NccSheet($dir.'/conceptos.xml',['UUID','RFC','Uso en informe','Grupo','Clave','Tipo SAT','Concepto','Importe','Gravado','Exento','Subsidio causado','Campo maestro','Complemento']);
        $sum=[];foreach($columns as $c) $sum[$c['indice']]=['indice'=>$c['indice'],'columna'=>$c['letra'],'campo'=>$c['encabezado'],'regla'=>$c['plan']['regla'],'estados'=>[],'workbeat'=>null,'xml'=>null,'diferencia'=>null,'numero'=>$c['plan']['numero']];
        $fh=fopen($dir.'/detalle.jsonl','wb');if (!$fh) throw new RuntimeException('No se pudo escribir el detalle.');$count=0;$states=[];
        $rfcs=array_unique(array_merge(array_keys($w['empleados']),array_keys($a['empleados'])));sort($rfcs);
        foreach($rfcs as $rfc) {
            $we=$w['empleados'][$rfc]??null;$xe=$a['empleados'][$rfc]??null;
            foreach($columns as $c) {
                $d=ncc_compare($c,$we,$xe,$scope,$a['sin_rfc']>0);$d['rfc']=$rfc;$d['empleado']=implode(' | ',array_keys($we['numeros']??[]));$d['renglones']=implode(', ',$we['renglones']??[]);$d['indice']=$c['indice'];$d['cfdi']=$xe['validos']??0;$d['incidencias']=$xe['incidencias']??0;
                ncx_write($fh,nf_json($d)."\n");$count++;
                $sheets['DETALLE']->add([$rfc,$d['empleado'],$d['renglones'],$c['letra'],$d['campo'],$d['workbeat'],$d['xml'],$d['diferencia'],$d['estado'],$d['nota'],$d['cfdi'],$d['incidencias']],$d['numero']?[5,6,7,10,11]:[10,11]);
                $s=&$sum[$c['indice']];$s['estados'][$d['estado']]=($s['estados'][$d['estado']]??0)+1;$states[$d['estado']]=($states[$d['estado']]??0)+1;
                if ($d['numero']) foreach(['workbeat','xml','diferencia'] as $k) if (is_numeric($d[$k])) $s[$k]=round(($s[$k]??0)+(float)$d[$k],2);
                unset($s);
            }
        }
        fclose($fh);$fh=null;
        foreach($sum as &$s) {$s['diferencia']=$s['numero'] && $s['workbeat']!==null && $s['xml']!==null?round($s['xml']-$s['workbeat'],2):null;}unset($s);
        foreach($sum as $s) {$st=$s['estados'];$others=array_diff_key($st,array_flip(['COINCIDE','DIFERENCIA','SIN_MOVIMIENTO']));$sheets['RESUMEN CAMPOS']->add([$s['columna'],$s['campo'],$s['regla'],$s['workbeat'],$s['xml'],$s['diferencia'],$st['COINCIDE']??0,$st['DIFERENCIA']??0,$st['SIN_MOVIMIENTO']??0,implode('; ',array_map(fn($k,$v)=>$k.': '.$v,array_keys($others),$others))],[3,4,5,6,7,8]);}
        $known=[];foreach($columns as $c) if ($c['plan']['modo']==='concepto') $known[$c['plan']['codigo']]=$c['encabezado'];
        foreach($columns as $c)if($c['plan']['modo']==='configurado')foreach($c['plan']['definicion']['terminos'] as $term)foreach($term['selector']['ruta'] as $step)if(isset($step['filtros']['Clave']))$known[$step['filtros']['Clave']]=$c['encabezado'];
        $unmapped=[];
        foreach(ncc_docs($dir,$m['bloques']) as $d) {
            $sheets['CFDI']->add([$d['uuid'],$d['rfc'],$d['inicio'],$d['fin'],$d['pago'],$d['tipo'],$d['sat'],$d['estado'],$d['motivo']]);
            foreach($d['conceptos'] as $c) {
                if (!isset($known[$c['clave']])) $unmapped[$c['clave']]=true;
                $money=fn($v)=>$v===null?null:$v/1000000;
                $sheets['CONCEPTOS XML']->add([$d['uuid'],$d['rfc'],$d['estado'],$c['grupo'],$c['clave'],$c['tipo'],$c['concepto'],$money($c['importe']),$money($c['gravado']),$money($c['exento']),$money($c['subsidio_causado']),$known[$c['clave']]??'SIN REGLA / SIN COLUMNA',$c['complemento']??1],[7,8,9,10]);
            }
        }
        $warnings=$w['incidencias'];
        if ($unmapped) $warnings[]='Claves XML sin correspondencia activa: '.implode(', ',array_keys($unmapped));
        if (($a['estados']['INCIDENCIA']??0)>0) $warnings[]='Hay XML excluidos por incidencia. Los importes comparados pueden estar incompletos; consulte CFDI.';
        if (isset($states['COBERTURA_PARCIAL'])) $warnings[]='Hay empleados cuyos XML no cubren todo el rango del maestro. Puede ser falta de documentos o un período laboral parcial: requiere revisión.';
        if (isset($states['PENDIENTE_REGLA'])) $warnings[]='Hay campos conservados sin equivalencia XML validada. No se marcaron conciliados.';
        $control=[
            ['Informe','Conciliación por campos (adicional a la conciliación general por importe)'],['Empresa',$m['empresa_nombre']],['RFC emisor',$scope['rfc_emisor']],['Importación',$m['importacion']],['Versión fuente',$m['fuente']],['Archivo maestro',$m['archivo']],['SHA256 original',$m['hash']],['Generado',date('Y-m-d H:i:s')],['Reglas',ncc_rules()['version']],['Desde',$scope['desde']],['Hasta',$scope['hasta']],['Criterio de selección','FechaInicialPago dentro del rango; XML con FechaFinalPago fuera del rango se señalan sin prorratear. Se incluyen candidatos sin fecha procesada para revisar su XML.'],['Criterio SAT','Sólo VIGENTE registrado en el visor; no se consultó el SAT en línea. Cancelados y estatus sin confirmar no suman.'],['Referencia','Una sola importación y su última fuente al iniciar; no se suman semanales con acumulados.'],['Tolerancia','0.01 por RFC/campo en sumas monetarias. Número único: igualdad del valor normalizado hasta 6 decimales, sin sumarlo. Los ceros y celdas vacías sin movimiento se identifican aparte.'],['Totales','Predeterminados: TOTALPER = percepciones + otros pagos; TOTALDED = deducciones; NETO = Total del CFDI. Las relaciones configuradas pueden sustituirlos; consulte sus versiones abajo. No sumar campos distintos del resumen: hay bases e importes repetidos.'],['Alcance','Coincidir en campos evaluados no valida los pendientes ni garantiza que estén todos los CFDI del período.'],['CFDI seleccionados',$m['total']],['CFDI incluidos',$a['estados']['INCLUIDO']??0],['CFDI con incidencia',$a['estados']['INCIDENCIA']??0],['RFC maestro',count($w['empleados'])],['RFC XML',count($a['empleados'])],['Columnas maestro',count($columns)],['Comparaciones',$count]
        ];
        foreach($scope['reglas']??[] as $key=>$rule)$control[]=['Relación '.$key,'v'.$rule['revision'].' · usuario '.$rule['usuario'].' · '.$rule['fecha'].' · '.$rule['descripcion']];
        foreach($control as $row) $sheets['CONTROL']->add($row);
        foreach($a['periodos'] as $p=>$n) $sheets['CONTROL']->add(['Período XML '.$p,$n.' CFDI incluidos']);
        foreach($warnings as $v) $sheets['CONTROL']->add(['AVISO',$v]);
        ncc_zip($dir.'/informe.tmp.xlsx',$sheets);
        if (!rename($dir.'/informe.tmp.xlsx',$dir.'/informe.xlsx')) throw new RuntimeException('No se pudo confirmar el Excel.');
        $result=['columnas'=>array_values($sum),'estados'=>$states,'avisos'=>$warnings,'periodos'=>$a['periodos'],'cfdi_estados'=>$a['estados'],'comparaciones'=>$count,'rfc_maestro'=>count($w['empleados']),'rfc_xml'=>count($a['empleados']),'scope'=>$scope,'archivo'=>$m['archivo'],'fuente'=>$m['fuente']];
        rng_atomic_json($dir.'/resumen.json',$result);return $result;
    } finally {if ($fh) fclose($fh);foreach($sheets as $s) {$s->close();@unlink($s->path);}}
}
