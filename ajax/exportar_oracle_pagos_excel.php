<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/nomina_conciliacion_xlsx.php';

$tmp = null;
$tmpSheet = null;

function opx_fecha($v): string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}
function opx_col(int $n): string {
    $s='';
    while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26);}
    return $s;
}
function opx_num($v): string { return number_format((float)$v,2,'.',''); }
function opx_txt($v): string { return trim((string)($v ?? '')); }
function opx_uuid(array $r): string {
    $m=trim((string)($r['uuid_manual'] ?? ''));
    return $m!=='' ? $m : trim((string)($r['uuid_cfdi'] ?? ''));
}
function opx_row_values(array $r, ?array $padre=null): array {
    $esDetalle = $padre !== null;
    $estatusFactura = $esDetalle ? 'COMPROBANTE' : ((int)($r['canceled_flag'] ?? 0)===1 ? 'CANCELADA' : opx_txt($r['estatus_factura'] ?? 'VIGENTE'));
    $fechaCancelacion = $esDetalle ? '' : opx_txt($r['canceled_date'] ?? '');
    $p = $esDetalle ? $padre : $r;
    $estadoPago = !empty($p['payment_void_date']) ? 'ANULADO' : opx_txt($p['payment_status'] ?? ($p['paid_status'] ?? ''));
    if($esDetalle && $estadoPago==='') $estadoPago='INCLUIDO EN REPOSICIÓN';
    $numPago = opx_txt($p['payment_number'] ?? '');
    if($numPago==='') $numPago=opx_txt($p['payment_reference'] ?? '');
    $uuid = $esDetalle ? opx_txt($r['uuid_cfdi'] ?? '') : opx_uuid($r);
    return [
        opx_txt($r['oracle_invoice_id'] ?? ''),
        opx_txt($r['invoice_number'] ?? ''),
        $esDetalle ? opx_txt($padre['invoice_number'] ?? '') : '',
        opx_txt($r['invoice_date'] ?? ''),
        opx_txt($r['supplier'] ?? ''),
        opx_txt($r['supplier_tax_registration_number'] ?? ''),
        opx_num($r['invoice_amount'] ?? 0),
        opx_txt($r['invoice_currency'] ?? ''),
        $estatusFactura,
        $fechaCancelacion,
        $estadoPago,
        opx_txt($p['payment_date'] ?? ''),
        $numPago,
        opx_txt($p['payment_description'] ?? ''),
        (int)($p['payment_reconciled_flag'] ?? 0)===1 ? 'SI' : 'NO',
        opx_txt($p['payment_clearing_date'] ?? ''),
        opx_txt($p['payment_clearing_value_date'] ?? ''),
        (string)(int)($p['payment_count'] ?? 0),
        $uuid,
        opx_num($r['diferencia_importe'] ?? 0),
        opx_txt($r['estatus_conciliacion'] ?? 'PENDIENTE')
    ];
}

try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $anio=max(2000,min(2100,(int)($_GET['anio']??date('Y'))));
    $mes=max(1,min(12,(int)($_GET['mes']??date('n'))));
    $desdeGet=opx_fecha($_GET['desde']??'');
    $hastaGet=opx_fecha($_GET['hasta']??'');
    $estatus=strtoupper(trim((string)($_GET['estatus_conciliacion']??'TODOS')));

    if($desdeGet!=='' && $hastaGet!==''){
        if($desdeGet>$hastaGet) throw new RuntimeException('La fecha desde no puede ser mayor que la fecha hasta.');
        $desde=$desdeGet;
        $hastaEx=(new DateTimeImmutable($hastaGet))->modify('+1 day')->format('Y-m-d');
        $hastaLabel=$hastaGet;
    }else{
        $desde=sprintf('%04d-%02d-01',$anio,$mes);
        $hastaEx=(new DateTimeImmutable($desde))->modify('first day of next month')->format('Y-m-d');
        $hastaLabel=(new DateTimeImmutable($hastaEx))->modify('-1 day')->format('Y-m-d');
    }

    $where="f.id_empresa=? AND f.invoice_date>=? AND f.invoice_date<?";
    $params=[$idEmpresa,$desde,$hastaEx];
    if($estatus!=='' && $estatus!=='TODOS'){
        $where.=" AND COALESCE(NULLIF(TRIM(f.estatus_conciliacion),''),'PENDIENTE')=?";
        $params[]=$estatus;
    }

    $sql="SELECT f.id,f.oracle_invoice_id,f.invoice_number,f.invoice_date,f.supplier,f.supplier_number,
                 f.supplier_tax_registration_number,f.invoice_amount,f.invoice_currency,f.paid_status,f.uuid_cfdi,
                 f.canceled_flag,f.canceled_date,f.canceled_by,
                 f.payment_check_id,f.payment_number,f.payment_reference,f.payment_date,f.payment_status,
                 COALESCE((SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.payment_description),'') ORDER BY p.payment_date,p.id SEPARATOR ' | ')
                           FROM oracle_factura_pagos p WHERE p.id_empresa=f.id_empresa AND p.oracle_invoice_id=f.oracle_invoice_id),'') AS payment_description,
                 f.payment_reconciled_flag,f.payment_clearing_date,f.payment_clearing_value_date,
                 f.payment_amount,f.payment_currency_detail,f.payment_void_date,f.payment_count,
                 f.conciliado,f.estatus_conciliacion,f.fecha_conciliacion,
                 COALESCE((SELECT m.uuid FROM oracle_conciliacion_uuid_manual m WHERE m.id_empresa=f.id_empresa AND m.tipo_documento='FACTURA' AND m.id_documento_origen=f.id AND m.activo=1 LIMIT 1),'') AS uuid_manual,
                 CASE WHEN f.estatus_conciliacion='DIFERENCIA' THEN
                    CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN COALESCE((
                        SELECT SUM(COALESCE((SELECT c.diferencia_importe FROM conciliacion_financiera c
                            WHERE c.id_empresa=g.id_empresa AND c.origen_pago='ORACLE' AND c.tipo_documento_origen='EXP_DETALLE' AND c.id_documento_origen=g.id
                            ORDER BY c.id DESC LIMIT 1),0))
                        FROM oracle_gastos g WHERE g.id_empresa=f.id_empresa
                          AND CONCAT('EXP',RIGHT(LPAD(TRIM(g.oracle_expense_report_id),15,'0'),12))=UPPER(TRIM(f.invoice_number))
                    ),0) ELSE COALESCE((SELECT SUM(c.diferencia_importe) FROM conciliacion_financiera c
                        WHERE c.id_empresa=f.id_empresa AND c.origen_pago='ORACLE' AND c.tipo_documento_origen='FACTURA' AND c.id_documento_origen=f.id),0) END
                 ELSE 0 END AS diferencia_importe,
                 CASE WHEN f.canceled_flag=1 THEN 'CANCELADA' ELSE 'VIGENTE' END AS estatus_factura,
                 CASE WHEN UPPER(TRIM(f.invoice_number)) LIKE 'EXP%' THEN 1 ELSE 0 END AS es_reposicion
          FROM oracle_facturas f WHERE {$where}
          ORDER BY f.invoice_date ASC,f.oracle_invoice_id ASC";
    $st=$pdo->prepare($sql);$st->execute($params);
    $facturas=$st->fetchAll(PDO::FETCH_ASSOC);

    $stDetalle=$pdo->prepare("SELECT g.id,g.oracle_expense_id AS oracle_invoice_id,
              COALESCE(NULLIF(g.reference_number,''),NULLIF(g.expense_reference,''),g.oracle_expense_id) AS invoice_number,
              DATE(COALESCE(g.creation_date,g.receipt_date)) AS invoice_date,
              g.merchant_name AS supplier,g.merchant_taxpayer_id AS supplier_tax_registration_number,
              g.receipt_amount AS invoice_amount,g.receipt_currency_code AS invoice_currency,
              g.uuid_cfdi,g.conciliado,g.estatus_conciliacion,g.fecha_conciliacion,
              CASE WHEN g.estatus_conciliacion='DIFERENCIA' THEN COALESCE((SELECT c.diferencia_importe
                    FROM conciliacion_financiera c WHERE c.id_empresa=g.id_empresa AND c.origen_pago='ORACLE'
                    AND c.tipo_documento_origen='EXP_DETALLE' AND c.id_documento_origen=g.id ORDER BY c.id DESC LIMIT 1),0) ELSE 0 END AS diferencia_importe
          FROM oracle_gastos g WHERE g.id_empresa=?
            AND CONCAT('EXP',RIGHT(LPAD(TRIM(g.oracle_expense_report_id),15,'0'),12))=?
          ORDER BY COALESCE(g.creation_date,g.receipt_date),g.oracle_expense_id");

    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    $tmp=tempnam(sys_get_temp_dir(),'oracle_export_');
    $tmpSheet=tempnam(sys_get_temp_dir(),'oracle_sheet_');
    if($tmp===false||$tmpSheet===false) throw new RuntimeException('No se pudo crear el archivo temporal.');
    $fh=fopen($tmpSheet,'wb'); if(!$fh) throw new RuntimeException('No se pudo preparar el Excel.');

    $headers=['InvoiceId','Factura','Folio EXP','Fecha','Proveedor','RFC','Importe','Moneda','Estatus factura','Fecha cancelación','Estado pago','Fecha pago','No. pago','Concepto cheque / pago','Conciliado banco','Fecha conciliación','Fecha valor','# pagos','UUID','Diferencia $','Conciliación'];
    $widths=[18,22,24,14,34,18,16,12,18,18,18,16,16,42,18,18,16,10,40,16,22];
    ncx_write($fh,ncx_sheet_start($widths,true));
    $xml='<row r="1" ht="24" customHeight="1">'.ncx_cell('A1','Oracle pagos - detalle para conciliación',1).'</row>';
    ncx_write($fh,$xml);
    ncx_write($fh,'<row r="2">'.ncx_cell('A2','Periodo').ncx_cell('B2',$desde.' al '.$hastaLabel).ncx_cell('D2','Regla EXP').ncx_cell('E2','Se excluye el total del encabezado EXP y se exportan únicamente sus comprobantes detallados.').'</row>');
    $xml='<row r="4" ht="30" customHeight="1">';
    foreach($headers as $i=>$h)$xml.=ncx_cell(opx_col($i+1).'4',$h,2);
    ncx_write($fh,$xml.'</row>');

    $row=4;$totalFilas=0;$expOmitidos=0;$expDetalles=0;
    foreach($facturas as $f){
        if((int)$f['es_reposicion']===1){
            ++$expOmitidos;
            $stDetalle->execute([$idEmpresa,strtoupper(trim((string)$f['invoice_number']))]);
            foreach($stDetalle->fetchAll(PDO::FETCH_ASSOC) as $d){
                ++$row;++$totalFilas;++$expDetalles;
                $vals=opx_row_values($d,$f);
                $x='<row r="'.$row.'">';
                foreach($vals as $i=>$v){
                    $num=in_array($i,[6,19],true);
                    $style=$num?3:($i===18?8:0);
                    $x.=ncx_cell(opx_col($i+1).$row,$v,$style,$num);
                }
                ncx_write($fh,$x.'</row>');
            }
            continue;
        }
        ++$row;++$totalFilas;
        $vals=opx_row_values($f,null);
        $x='<row r="'.$row.'">';
        foreach($vals as $i=>$v){
            $num=in_array($i,[6,19],true);
            $style=$num?3:($i===18?8:0);
            $x.=ncx_cell(opx_col($i+1).$row,$v,$style,$num);
        }
        ncx_write($fh,$x.'</row>');
    }
    $last=max(4,$row);
    ncx_write($fh,'</sheetData><autoFilter ref="A4:U'.$last.'"/><mergeCells count="3"><mergeCell ref="A1:U1"/><mergeCell ref="B2:C2"/><mergeCell ref="E2:U2"/></mergeCells></worksheet>');
    fclose($fh);

    $zip=new XlsxSimpleWriter($tmp);
    $zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="ORACLE PAGOS" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addString('xl/styles.xml',ncx_styles());
    $zip->addFile('xl/worksheets/sheet1.xml',$tmpSheet);$zip->close();unset($zip);

    clearstatcache(true,$tmp);$size=filesize($tmp);
    if(!$size||file_get_contents($tmp,false,null,0,4)!=="PK\x03\x04") throw new RuntimeException('No se pudo completar el archivo Excel.');
    $nombre='ORACLE_PAGOS_DETALLADO_'.str_replace('-','',$desde).'_'.str_replace('-','',$hastaLabel).'.xlsx';
    while(ob_get_level()>0)ob_end_clean();@ini_set('zlib.output_compression','0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    header('Content-Length: '.$size);header('Cache-Control: no-store, no-cache, must-revalidate');header('X-Content-Type-Options: nosniff');
    readfile($tmp);
}catch(Throwable $e){
    if(isset($pdo)) seguridad_log_error($e,'exportar_oracle_pagos_excel');
    if(!headers_sent()){
        if(http_response_code()<400)http_response_code(400);
        header_remove('Content-Disposition');header_remove('Content-Length');header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo exportar Oracle pagos: '.$e->getMessage();
    }
}finally{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(is_string($tmpSheet)&&is_file($tmpSheet))@unlink($tmpSheet);
    if(is_string($tmp)&&is_file($tmp))@unlink($tmp);
}
