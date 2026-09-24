<?php
require_once __DIR__ . '/XlsxSimpleWriter.php';

function rxr_paths(string $token): array {
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sgksat_xml_recibidos_' . $token;
    return [
        'meta' => $base . '.json',
        'sheet' => $base . '.sheet.xml',
        'xlsx' => $base . '.xlsx',
    ];
}
function rxr_valid_token(string $token): bool { return (bool)preg_match('/^[a-f0-9]{32}$/', $token); }
function rxr_clean_xml_text($v): string {
    $s = (string)($v ?? '');
    // Normalizar a UTF-8 válido. Algunas descripciones heredadas pueden contener bytes inválidos.
    if (function_exists('mb_convert_encoding')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    } elseif (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($x !== false) $s = $x;
    }
    // XML 1.0 no permite estos caracteres de control (aunque MySQL sí los puede almacenar).
    $s = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', '', $s) ?? '';
    return $s;
}
function rxr_xml($v): string {
    return htmlspecialchars(rxr_clean_xml_text($v), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
function rxr_col_name(int $n): string { $s=''; while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26);} return $s; }
function rxr_cell_inline(int $col, int $row, $value, int $style=0): string {
    $ref = rxr_col_name($col).$row;
    $styleAttr = $style ? ' s="'.$style.'"' : '';
    $value = (string)($value ?? '');
    return '<c r="'.$ref.'" t="inlineStr"'.$styleAttr.'><is><t xml:space="preserve">'.rxr_xml($value).'</t></is></c>';
}
function rxr_cell_num(int $col, int $row, $value, int $style=0): string {
    $ref=rxr_col_name($col).$row; $styleAttr=$style?' s="'.$style.'"':'';
    $v=is_numeric($value)?(string)(0+$value):'0';
    return '<c r="'.$ref.'"'.$styleAttr.'><v>'.$v.'</v></c>';
}
function rxr_headers(): array {
    return [
        'UUID','▼ Fecha Comprobante','Tipo Comprobante Desc','Serie','Folio','RFC Emisor','Nombre Emisor',
        'Clave Prod/Serv','Clave Prod/Serv Desc','Descripción','Cantidad','Valor Unitario','Importe','Importe Traslado','Total',
        'Moneda','Tipo Cambio','Referencia','Clave Impuesto Tras.','Clave Impuesto Tras. Desc.','Tasa Cuota Tras.','Forma Pago',
        'Método Pago','Clave Impuesto Ret.','Clave Impuesto Ret. Desc','Base Retención','Tasa Cuota Ret.','Importe Retención',
        'Estatus','Estado Pagado','Validez','Estado de Cancelación del Documento','Fecha de Cancelación del Documento',
        'Forma Pago Desc','Método Pago Desc','Uso CFDI','Uso CFDI Desc','Objeto Impuesto','Objeto Impuesto Desc','Tipo Factor Ret.',
        'Tipo Relación','UUID Relacionado','Tipo Relación Desc','Tipo Documento','Versión Comprobante'
    ];
}
function rxr_widths(): array {
    return [36.09765625,20,20.69921875,20.796875,34.796875,16.296875,24.09765625,13.8984375,48.59765625,46.8984375,
        13,13,14,15.09765625,15,7.796875,11.19921875,9.796875,18.09765625,23.09765625,14.59765625,10.69921875,12,
        17.3984375,21.8984375,13.59765625,13.8984375,16.296875,8.59765625,13,6.796875,32.69921875,31.8984375,30,
        26.69921875,8.3984375,36.8984375,14.69921875,39.19921875,14,12,36.09765625,49.09765625,14.59765625,18.8984375];
}
function rxr_tipo_desc(string $t): string {
    return ['I'=>'Ingreso','E'=>'Egreso','P'=>'Pago','N'=>'Nómina','T'=>'Traslado'][$t] ?? $t;
}
function rxr_objeto_desc(string $v): string {
    return ['01'=>'No objeto de impuesto.','02'=>'Sí objeto de impuesto.','03'=>'Sí objeto de impuesto y no obligado al desglose.','04'=>'Sí objeto de impuesto y no causa impuesto.'][$v] ?? '';
}
function rxr_rel_desc(string $v): string {
    return ['01'=>'Nota de crédito de los documentos relacionados','02'=>'Nota de débito de los documentos relacionados','03'=>'Devolución de mercancía sobre facturas o traslados previos','04'=>'Sustitución de los CFDI previos','05'=>'Traslados de mercancías facturados previamente','06'=>'Factura generada por los traslados previos','07'=>'CFDI por aplicación de anticipo'][$v] ?? '';
}
function rxr_impuesto_desc(string $v): string { return ['001'=>'ISR','002'=>'IVA','003'=>'IEPS'][$v] ?? ''; }
function rxr_normalize_xml(string $raw): ?SimpleXMLElement {
    if ($raw==='') return null;
    if (str_starts_with(ltrim($raw), '<')) $xmlRaw=$raw; else {
        $decoded=base64_decode($raw,true); if($decoded===false) return null; $xmlRaw=$decoded;
    }
    libxml_use_internal_errors(true);
    $xml=@simplexml_load_string($xmlRaw);
    if(!$xml){libxml_clear_errors();return null;}
    return $xml;
}
function rxr_children_ns(SimpleXMLElement $node, string $local): array {
    $out=[];
    foreach($node->children() as $c){ if($c->getName()===$local) $out[]=$c; }
    foreach($node->getNamespaces(true) as $ns){ foreach($node->children($ns) as $c){ if($c->getName()===$local) $out[]=$c; } }
    return $out;
}
function rxr_first_child(SimpleXMLElement $node, string $local): ?SimpleXMLElement {
    $a=rxr_children_ns($node,$local); return $a[0]??null;
}
function rxr_attr(SimpleXMLElement $n, string $name): string {
    foreach($n->attributes() as $k=>$v){ if(strcasecmp((string)$k,$name)===0) return (string)$v; }
    return '';
}
function rxr_conceptos(SimpleXMLElement $xml): array {
    $root=$xml; $conceptos=[];
    $cont=rxr_first_child($root,'Conceptos'); if(!$cont) return [];
    return rxr_children_ns($cont,'Concepto');
}
/**
 * Devuelve el desglose de impuestos por renglón para el reporte de auditoría.
 *
 * Regla compatible con el reporte histórico GKM:
 * - 2 traslados (ej. IVA + IEPS) => 2 renglones.
 * - 2 retenciones (ej. ISR + IVA retenido) => 2 renglones.
 * - Si un lado tiene un solo impuesto y el otro varios, el impuesto único se
 *   repite en cada renglón para conservar toda la información del concepto.
 * - Si no hay impuestos, devuelve un renglón vacío.
 */
function rxr_tax_rows(SimpleXMLElement $concepto): array {
    $traslados=[];
    $retenciones=[];

    $imp=rxr_first_child($concepto,'Impuestos');
    if($imp){
        $tras=rxr_first_child($imp,'Traslados');
        if($tras){
            foreach(rxr_children_ns($tras,'Traslado') as $t){
                $traslados[]=[
                    'codigo'=>rxr_attr($t,'Impuesto'),
                    'tasa'=>rxr_attr($t,'TasaOCuota'),
                    'importe'=>(float)(rxr_attr($t,'Importe')?:0),
                ];
            }
        }

        $ret=rxr_first_child($imp,'Retenciones');
        if($ret){
            foreach(rxr_children_ns($ret,'Retencion') as $t){
                $retenciones[]=[
                    'codigo'=>rxr_attr($t,'Impuesto'),
                    'base'=>(float)(rxr_attr($t,'Base')?:0),
                    'tasa'=>rxr_attr($t,'TasaOCuota'),
                    'importe'=>(float)(rxr_attr($t,'Importe')?:0),
                    'factor'=>rxr_attr($t,'TipoFactor'),
                ];
            }
        }
    }

    $n=max(1,count($traslados),count($retenciones));
    $rows=[];

    for($i=0;$i<$n;$i++){
        $tr=null;
        if(count($traslados)===1) $tr=$traslados[0];
        elseif(isset($traslados[$i])) $tr=$traslados[$i];

        $re=null;
        if(count($retenciones)===1) $re=$retenciones[0];
        elseif(isset($retenciones[$i])) $re=$retenciones[$i];

        $rows[]=[
            'tras_codigo'=>$tr['codigo']??'',
            'tras_tasa'=>$tr['tasa']??'',
            'tras_importe'=>$tr['importe']??0.0,
            'ret_codigo'=>$re['codigo']??'',
            'ret_base'=>$re['base']??0.0,
            'ret_tasa'=>$re['tasa']??'',
            'ret_importe'=>$re['importe']??0.0,
            'ret_factor'=>$re['factor']??'',
        ];
    }

    return $rows;
}

function rxr_taxes(SimpleXMLElement $concepto): array {
    $r=['tras_codigo'=>'','tras_tasa'=>'','tras_importe'=>0.0,'ret_codigo'=>'','ret_base'=>0.0,'ret_tasa'=>'','ret_importe'=>0.0,'ret_factor'=>''];
    $imp=rxr_first_child($concepto,'Impuestos'); if(!$imp) return $r;
    $tras=rxr_first_child($imp,'Traslados');
    if($tras){
        $codes=[];$rates=[];$importe=0.0;
        foreach(rxr_children_ns($tras,'Traslado') as $t){
            $c=rxr_attr($t,'Impuesto'); if($c!=='')$codes[$c]=1;
            $tc=rxr_attr($t,'TasaOCuota'); if($tc!=='')$rates[$tc]=1;
            $importe+=(float)(rxr_attr($t,'Importe')?:0);
        }
        $r['tras_codigo']=implode(',',array_keys($codes)); $r['tras_tasa']=implode(',',array_keys($rates)); $r['tras_importe']=$importe;
    }
    $ret=rxr_first_child($imp,'Retenciones');
    if($ret){
        $codes=[];$rates=[];$factors=[];$base=0.0;$importe=0.0;
        foreach(rxr_children_ns($ret,'Retencion') as $t){
            $c=rxr_attr($t,'Impuesto'); if($c!=='')$codes[$c]=1;
            $tc=rxr_attr($t,'TasaOCuota'); if($tc!=='')$rates[$tc]=1;
            $tf=rxr_attr($t,'TipoFactor'); if($tf!=='')$factors[$tf]=1;
            $base+=(float)(rxr_attr($t,'Base')?:0); $importe+=(float)(rxr_attr($t,'Importe')?:0);
        }
        $r['ret_codigo']=implode(',',array_keys($codes)); $r['ret_tasa']=implode(',',array_keys($rates)); $r['ret_factor']=implode(',',array_keys($factors)); $r['ret_base']=$base;$r['ret_importe']=$importe;
    }
    return $r;
}
function rxr_write_row($fh, int $rowNum, array $row): void {
    $numeric=[11,12,13,14,15,17,21,26,27,28];
    $xml='<row r="'.$rowNum.'">';
    for($i=1;$i<=45;$i++){
        $v=$row[$i-1]??'';
        if(in_array($i,$numeric,true) && $v!=='' && $v!==null && is_numeric($v)) $xml.=rxr_cell_num($i,$rowNum,$v,2);
        else $xml.=rxr_cell_inline($i,$rowNum,$v,0);
    }
    $xml.="</row>\n";
    fwrite($fh,$xml);
}
function rxr_build_xlsx(string $sheetFile, string $xlsxFile, int $lastRow): void {
    $finalXlsx = $xlsxFile;
    $tmpXlsx = $xlsxFile . '.part';
    @unlink($tmpXlsx);
    $xlsxFile = $tmpXlsx;
    $cols=''; foreach(rxr_widths() as $i=>$w){$n=$i+1;$cols.='<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';}
    // El sheet temporal ya contiene apertura hasta <sheetData> y filas; se completa aquí.
    file_put_contents($sheetFile,'</sheetData><autoFilter ref="A1:AS'.max(1,$lastRow).'"/></worksheet>',FILE_APPEND|LOCK_EX);

    $w=new XlsxSimpleWriter($xlsxFile);
    $w->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $w->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $w->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Filtro" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $w->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $w->addString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $w->addFile('xl/worksheets/sheet1.xml',$sheetFile);
    $w->close();

    // Validación mínima del contenedor ZIP antes de exponerlo al navegador.
    if (!is_file($xlsxFile) || filesize($xlsxFile) < 500) {
        @unlink($xlsxFile);
        throw new RuntimeException('El XLSX generado quedó incompleto.');
    }
    $fh = @fopen($xlsxFile, 'rb');
    $sig = $fh ? fread($fh, 4) : false;
    if ($fh) fclose($fh);
    if ($sig !== "PK\x03\x04") {
        @unlink($xlsxFile);
        throw new RuntimeException('El contenedor XLSX generado no es válido.');
    }
    @unlink($finalXlsx);
    if (!@rename($xlsxFile, $finalXlsx)) {
        @unlink($xlsxFile);
        throw new RuntimeException('No se pudo finalizar el archivo XLSX.');
    }
}
