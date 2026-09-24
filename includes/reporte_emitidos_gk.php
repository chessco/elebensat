<?php
require_once __DIR__ . '/reporte_xml_recibidos.php';

function reg_valid_token(string $token): bool { return (bool)preg_match('/^[a-f0-9]{32}$/', $token); }

function reg_paths(string $token): array {
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sgksat_emitidos_gk_' . $token;
    return [
        'meta'=>$base.'.json', 'sheet_facturas'=>$base.'_facturas.sheet.xml', 'sheet_notas'=>$base.'_notas.sheet.xml',
        'xlsx'=>$base.'.xlsx'
    ];
}

function reg_cleanup(array $paths): void { foreach ($paths as $path) if (is_string($path) && is_file($path)) @unlink($path); }

function reg_headers(): array {
    return [
        '▼ Fecha Comprobante','Serie','Folio','RFC Receptor','Nombre Receptor','Régimen Fiscal','Descripción','Cantidad',
        'Valor Unitario','Importe','Subtotal','Total Descuento','Total','Moneda','Tipo Cambio','Referencia','Estatus','UUID',
        'Forma Pago','Método Pago','Tipo Factor Tras.','Clave Impuesto Tras.','Clave Impuesto Tras. Desc.','Tasa Cuota Tras.',
        'Tipo Documento','Versión Comprobante','Tipo Comprobante Desc','IdPago'
    ];
}

function reg_widths(string $kind): array {
    $w=[21.5546875,5.6640625,8,17.44140625,63.6640625,14.109375,59.5546875,13.109375,13.33203125,13.109375,
        15.109375,15.33203125,15.109375,8.33203125,12,20.6640625,10.109375,38.5546875,11.33203125,12.6640625,
        15.33203125,19.44140625,24.6640625,15,15.5546875,20.5546875,22.33203125,7];
    if($kind==='notas'){$w[6]=62.6640625;$w[7]=11.5546875;$w[8]=13.44140625;$w[11]=15.44140625;$w[12]=14.109375;$w[15]=10.5546875;}
    return $w;
}

function reg_open_sheet(string $path,string $kind): void {
    $fh=fopen($path,'wb'); if(!$fh) throw new RuntimeException('No se pudo crear el temporal del reporte de emitidos.');
    $cols=''; foreach(reg_widths($kind) as $i=>$width){$n=$i+1;$cols.='<col min="'.$n.'" max="'.$n.'" width="'.$width.'" customWidth="1"/>';}
    fwrite($fh,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr baseColWidth="10" defaultRowHeight="14.4"/><cols>'.$cols.'</cols><sheetData>');
    $row='<row r="1">'; foreach(reg_headers() as $i=>$header)$row.=rxr_cell_inline($i+1,1,$header,1); fwrite($fh,$row."</row>\n"); fclose($fh);
}

function reg_tax_rows(?SimpleXMLElement $concept): array {
    if(!$concept)return [['factor'=>'','codigo'=>'','tasa'=>'']]; $rows=[];
    $imp=rxr_first_child($concept,'Impuestos'); $tras=$imp?rxr_first_child($imp,'Traslados'):null;
    if($tras)foreach(rxr_children_ns($tras,'Traslado') as $t)$rows[]=['factor'=>rxr_attr($t,'TipoFactor'),'codigo'=>rxr_attr($t,'Impuesto'),'tasa'=>rxr_attr($t,'TasaOCuota')];
    return $rows?:[['factor'=>'','codigo'=>'','tasa'=>'']];
}

function reg_catalogo_regimenes(): array {
    return [
        '601'=>'General de Ley Personas Morales',
        '603'=>'Personas Morales con Fines no Lucrativos',
        '605'=>'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606'=>'Arrendamiento',
        '607'=>'Régimen de Enajenación o Adquisición de Bienes',
        '608'=>'Demás ingresos',
        '610'=>'Residentes en el Extranjero sin Establecimiento Permanente en México',
        '611'=>'Ingresos por Dividendos (socios y accionistas)',
        '612'=>'Personas Físicas con Actividades Empresariales y Profesionales',
        '614'=>'Ingresos por intereses',
        '615'=>'Régimen de los ingresos por obtención de premios',
        '616'=>'Sin obligaciones fiscales',
        '620'=>'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621'=>'Incorporación Fiscal',
        '622'=>'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623'=>'Opcional para Grupos de Sociedades',
        '624'=>'Coordinados',
        '625'=>'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626'=>'Régimen Simplificado de Confianza'
    ];
}

function reg_xml_raw(string $stored): string {
    $stored=trim($stored);
    if($stored==='')return '';
    if(str_starts_with($stored,'<'))return $stored;
    $decoded=base64_decode($stored,true);
    return $decoded===false?'':$decoded;
}

function reg_regimen_receptor(?SimpleXMLElement $receptor,string $xmlStored): string {
    $codigo=$receptor?trim(rxr_attr($receptor,'RegimenFiscalReceptor')):'';
    if($codigo===''){
        $raw=reg_xml_raw($xmlStored);
        if($raw!==''&&preg_match('/\\bRegimenFiscalReceptor\\s*=\\s*(["\'])\\s*([0-9]{3})\\s*\\1/i',$raw,$match))$codigo=$match[2];
    }
    $codigo=preg_replace('/[^0-9]/','',$codigo)??'';
    if(strlen($codigo)!==3)return '';
    $catalogo=reg_catalogo_regimenes();
    return isset($catalogo[$codigo])?$codigo:$codigo;
}

function reg_write_row($fh,int $rowNum,array $row): void {
    $numeric=[8,9,10,11,12,13,15,24,28]; $xml='<row r="'.$rowNum.'">';
    for($i=1;$i<=28;$i++){$v=$row[$i-1]??'';$xml.=(in_array($i,$numeric,true)&&$v!==''&&$v!==null&&is_numeric($v))?rxr_cell_num($i,$rowNum,$v,2):rxr_cell_inline($i,$rowNum,$v,0);}
    fwrite($fh,$xml."</row>\n");
}

function reg_close_sheet(string $sheetFile,int $lastRow): void {
    file_put_contents($sheetFile,'</sheetData><autoFilter ref="A1:AB'.max(1,$lastRow).'"/><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>',FILE_APPEND|LOCK_EX);
}

function reg_build_workbook(array $paths,int $lastRowFacturas,int $lastRowNotas): void {
    reg_close_sheet($paths['sheet_facturas'],$lastRowFacturas);
    reg_close_sheet($paths['sheet_notas'],$lastRowNotas);
    $part=$paths['xlsx'].'.part'; @unlink($part); $w=new XlsxSimpleWriter($part);
    $w->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $w->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $w->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Facturas" sheetId="1" r:id="rId1"/><sheet name="Notas de credito" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $w->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $w->addString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><sz val="11"/><color indexed="12"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="justify" vertical="center"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $w->addFile('xl/worksheets/sheet1.xml',$paths['sheet_facturas']);
    $w->addFile('xl/worksheets/sheet2.xml',$paths['sheet_notas']);
    $w->close();
    if(!is_file($part)||filesize($part)<1000)throw new RuntimeException('El Excel de emitidos quedó incompleto.');
    @unlink($paths['xlsx']);
    if(!@rename($part,$paths['xlsx']))throw new RuntimeException('No se pudo finalizar el Excel de emitidos.');
}
