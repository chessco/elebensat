<?php
require_once __DIR__ . '/XlsxSimpleWriter.php';

// XLSX nativo: texto explícito para identificadores; números para importes.
function ncx_xml($value): string {
    $s = htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';
}
function ncx_cell(string $ref, $value, int $style = 0, bool $number = false): string {
    $start = '<c r="'.$ref.'" s="'.$style.'"';
    if ($value === null || $value === '') return $start.'/>';
    if ($number) {
        if (!is_numeric($value) || !is_finite((float)$value)) throw new RuntimeException('Importe no válido en '.$ref.'.');
        return $start.'><v>'.ncx_xml($value).'</v></c>';
    }
    // Incluso valores que empiezan por = se guardan como texto, no como fórmulas.
    return $start.' t="inlineStr"><is><t xml:space="preserve">'.ncx_xml($value).'</t></is></c>';
}
function ncx_date($value): ?int {
    $s = substr(trim((string)$value), 0, 10);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s
        ? (int)(new DateTimeImmutable('1899-12-30'))->diff($d)->format('%r%a') : null;
}
function ncx_date_label($value): string {
    return ncx_date($value) === null ? '' : (new DateTimeImmutable(substr((string)$value,0,10)))->format('d/m/Y');
}
function ncx_status($value): string {
    return ['SOLO_REPORTE'=>'SOLO REPORTE','SOLO_VISOR'=>'SOLO VISOR','CANCELADO_EN_VISOR'=>'CANCELADO EN VISOR'][strtoupper((string)$value)] ?? (string)$value;
}
function ncx_styles(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
 <numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00;[Red]-&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>
 <fonts count="5">
  <font><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="14"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><name val="Calibri"/></font>
  <font><b/><color rgb="FF008000"/><sz val="11"/><name val="Calibri"/></font>
  <font><b/><color rgb="FFC00000"/><sz val="11"/><name val="Calibri"/></font>
 </fonts>
 <fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9EAF7"/><bgColor indexed="64"/></patternFill></fill></fills>
 <borders count="2"><border/><border><bottom style="thin"><color rgb="FF9EB6C5"/></bottom></border></borders>
 <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
 <cellXfs count="9">
  <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
  <xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  <xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  <xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
 </cellXfs>
 <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';
}
function ncx_sheet_start(array $widths, bool $detail = false): string {
    $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0">';
    if ($detail) $s .= '<pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A6" sqref="A6"/>';
    $s .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ($widths as $i=>$w) $s .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
    return $s.'</cols><sheetData>';
}
function ncx_write($fh, string $data): void {
    if (fwrite($fh, $data) !== strlen($data)) throw new RuntimeException('No se pudo escribir el temporal de conciliación.');
}
/** Conserva los resultados guardados; no recalcula ni modifica la conciliación. */
function ncx_build(string $path, array $empresa, array $h, iterable $det): void {
    $summary = ncx_sheet_start([30,65]);
    $summary .= '<row r="1" ht="25" customHeight="1">'.ncx_cell('A1','Conciliación Nómina vs CFDI',1).'</row>';
    $rows = [
        ['Empresa',$empresa['razon_social']??'',0,false],
        ['RFC empresa',$empresa['rfc']??'',8,false],
        ['Concepto','Valor',2,false],
        ['Conciliación',(int)$h['id_conciliacion'],4,true],
        ['Rango desde',ncx_date($h['fecha_desde']),7,true],
        ['Rango hasta',ncx_date($h['fecha_hasta']),7,true],
        ['Empleados reporte',(int)$h['total_empleados_reporte'],4,true],
        ['Empleados visor',(int)$h['total_empleados_visor'],4,true],
        ['Conciliados',(int)$h['total_conciliados'],4,true],
        ['Con diferencia',(int)$h['total_diferencias'],4,true],
        ['Solo reporte',(int)$h['total_solo_reporte'],4,true],
        ['Solo visor',(int)$h['total_solo_visor'],4,true],
        ['Cancelados',(int)$h['total_cancelados'],4,true],
        ['Total reporte',$h['total_reporte'],3,true],
        ['Total visor',$h['total_visor'],3,true],
        ['Diferencia',$h['diferencia'],3,true],
        ['Fecha conciliación',$h['fecha_conciliacion'],0,false],
    ];
    foreach ($rows as $i=>[$label,$value,$style,$num]) {
        $r=$i+2;
        $summary .= '<row r="'.$r.'">'.ncx_cell('A'.$r,$label,$r===4?2:0).ncx_cell('B'.$r,$value,$style,$num).'</row>';
    }
    $summary .= '</sheetData><mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells></worksheet>';
    $tmp = tempnam(sys_get_temp_dir(),'ncx_sheet_');
    if ($tmp === false) throw new RuntimeException('No se pudo crear el temporal de conciliación.');
    $fh = null; $zip = null;
    try {
        $fh = fopen($tmp,'wb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el temporal de conciliación.');
        ncx_write($fh,ncx_sheet_start([16,20,42,19,19,19,19,19,19,19,19,19,18,16,26],true));
        ncx_write($fh,'<row r="1" ht="25" customHeight="1">'.ncx_cell('A1','Conciliación Nómina vs CFDI - Detalle',1).'</row>');
        foreach ([2=>['Empresa',$empresa['razon_social']??''],3=>['RFC empresa',$empresa['rfc']??''],4=>['Rango conciliado',ncx_date_label($h['fecha_desde']).' al '.ncx_date_label($h['fecha_hasta'])]] as $r=>[$label,$value]) {
            ncx_write($fh,'<row r="'.$r.'">'.ncx_cell('A'.$r,$label).ncx_cell('C'.$r,$value).'</row>');
        }
        $headers=['No. empleado','RFC','Nombre completo','Percep. visor','Percep. reporte','Dif. percep.','Deduc. visor','Deduc. reporte','Dif. deduc.','Neto visor','Neto reporte','Dif. neto','CFDI vigentes','Cancelados','Resultado'];
        $xml='<row r="5" ht="32" customHeight="1">';
        foreach ($headers as $i=>$v) $xml .= ncx_cell(chr(65+$i).'5',$v,2);
        ncx_write($fh,$xml.'</row>');
        $row=5;
        foreach ($det as $d) {
            if (++$row > 1048576) throw new RuntimeException('La conciliación supera el límite de filas de Excel.');
            $xml='<row r="'.$row.'">'.ncx_cell('A'.$row,$d['numero_empleado'],8).ncx_cell('B'.$row,$d['rfc'],8).ncx_cell('C'.$row,$d['nombre_completo']);
            foreach (['percepciones_visor','percepciones_reporte','diferencia_percepciones','deducciones_visor','deducciones_reporte','diferencia_deducciones','total_visor','total_reporte','diferencia'] as $i=>$key) {
                $value = $i<6 && (int)($d['usa_desglose']??0)!==1 ? null : ($d[$key]??null);
                // Mantener el redondeo de presentación del exportador anterior.
                if ($value !== null && $value !== '') {
                    if (!is_numeric($value)) throw new RuntimeException('Importe no válido en conciliación.');
                    $value=number_format((float)$value,2,'.','');
                }
                $xml .= ncx_cell(chr(68+$i).$row,$value,3,true);
            }
            $xml .= ncx_cell('M'.$row,(int)$d['documentos_visor'],4,true).ncx_cell('N'.$row,(int)$d['documentos_cancelados'],4,true);
            $xml .= ncx_cell('O'.$row,ncx_status($d['estatus']),strtoupper((string)$d['estatus'])==='CONCILIADO'?5:6);
            ncx_write($fh,$xml.'</row>');
        }
        ncx_write($fh,'</sheetData><autoFilter ref="A5:O'.$row.'"/><mergeCells count="7"><mergeCell ref="A1:O1"/><mergeCell ref="A2:B2"/><mergeCell ref="C2:O2"/><mergeCell ref="A3:B3"/><mergeCell ref="C3:O3"/><mergeCell ref="A4:B4"/><mergeCell ref="C4:O4"/></mergeCells></worksheet>');
        fclose($fh); $fh=null;
        $zip = new XlsxSimpleWriter($path);
        $zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="RESUMEN" sheetId="1" r:id="rId1"/><sheet name="DETALLE" sheetId="2" r:id="rId2"/></sheets><calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1"/></workbook>');
        $zip->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addString('xl/styles.xml',ncx_styles());
        $zip->addString('xl/worksheets/sheet1.xml',$summary);
        $zip->addFile('xl/worksheets/sheet2.xml',$tmp);
        $zip->close();
    } finally {
        if (is_resource($fh)) fclose($fh);
        unset($zip);
        @unlink($tmp);
    }
}
