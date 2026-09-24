<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/nomina_conciliacion_xlsx.php';

$tmp = null;
$tmpSheet = null;
try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');

    $idConciliacion = (int)($_GET['id_conciliacion'] ?? 0);
    $rfc = strtoupper(trim((string)($_GET['rfc'] ?? '')));
    if ($idConciliacion <= 0 || $rfc === '') throw new RuntimeException('Empleado o conciliación no válidos.');

    $st = $pdo->prepare("SELECT id_conciliacion,id_empresa,anio,fecha_desde,fecha_hasta,fecha_conciliacion
                         FROM nomina_conciliaciones
                         WHERE id_conciliacion=? AND id_empresa=? LIMIT 1");
    $st->execute([$idConciliacion, $idEmpresa]);
    $conc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conc) throw new RuntimeException('No se encontró la conciliación.');

    $st = $pdo->prepare("SELECT numero_empleado,rfc,nombre_completo,usa_desglose,
                                percepciones_visor,percepciones_reporte,diferencia_percepciones,
                                deducciones_visor,deducciones_reporte,diferencia_deducciones,
                                total_visor,total_reporte,diferencia,documentos_visor,
                                documentos_cancelados,estatus
                         FROM nomina_conciliacion_detalles
                         WHERE id_conciliacion=? AND id_empresa=? AND UPPER(TRIM(rfc))=? LIMIT 1");
    $st->execute([$idConciliacion, $idEmpresa, $rfc]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) throw new RuntimeException('No se encontró el empleado en esta conciliación.');

    $st = $pdo->prepare("SELECT f.uuid,f.serie,f.folio,f.fecha_emision,f.estatus_sat,f.fecha_cancelacion,
                                f.nomina_tipo_nomina,f.nomina_fecha_pago,f.nomina_fecha_inicial_pago,
                                f.nomina_fecha_final_pago,f.nomina_num_dias_pagados,f.nomina_periodicidad_pago,
                                f.nomina_num_empleado,f.nomina_total_percepciones,f.nomina_total_deducciones,
                                f.nomina_total_otros_pagos,f.subtotal_xml,f.total_xml,f.moneda,
                                r.rfc rfc_receptor,r.nombre nombre_receptor,
                                e.rfc rfc_emisor,e.nombre nombre_emisor
                         FROM facturas f
                         INNER JOIN cat_receptores r ON r.id_receptor=f.id_receptor
                         LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor
                         WHERE f.id_empresa=?
                           AND f.id_tipo_comprobante='N'
                           AND UPPER(TRIM(r.rfc))=?
                           AND f.nomina_fecha_inicial_pago BETWEEN ? AND ?
                         ORDER BY f.nomina_fecha_inicial_pago,f.nomina_fecha_pago,f.fecha_emision,f.uuid");
    $st->execute([$idEmpresa, $rfc, $conc['fecha_desde'], $conc['fecha_hasta']]);
    $cfdis = $st->fetchAll(PDO::FETCH_ASSOC);

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $tmp = tempnam(sys_get_temp_dir(), 'nce_export_');
    $tmpSheet = tempnam(sys_get_temp_dir(), 'nce_sheet_');
    if ($tmp === false || $tmpSheet === false) throw new RuntimeException('No se pudo crear el archivo temporal.');

    $fh = fopen($tmpSheet, 'wb');
    if (!$fh) throw new RuntimeException('No se pudo preparar el Excel.');

    // 17 columnas: datos completos para cotejar directamente contra el portal SAT.
    $widths = [38,13,20,24,24,16,22,16,18,16,20,28,20,28,20,20,20,20];
    ncx_write($fh, ncx_sheet_start($widths, true));
    ncx_write($fh, '<row r="1" ht="25" customHeight="1">'.ncx_cell('A1','Conciliación Nómina - CFDI por empleado',1).'</row>');
    ncx_write($fh, '<row r="2">'.ncx_cell('A2','Empleado').ncx_cell('C2',($emp['numero_empleado'] ?? '').' - '.($emp['nombre_completo'] ?? '')).'</row>');
    ncx_write($fh, '<row r="3">'.ncx_cell('A3','RFC receptor').ncx_cell('C3',$rfc,8).ncx_cell('F3','Resultado').ncx_cell('G3',ncx_status($emp['estatus'] ?? '')).'</row>');
    ncx_write($fh, '<row r="4">'.ncx_cell('A4','Rango conciliado').ncx_cell('C4',ncx_date_label($conc['fecha_desde']).' al '.ncx_date_label($conc['fecha_hasta'])).ncx_cell('F4','Neto reporte').ncx_cell('G4',number_format((float)$emp['total_reporte'],2,'.',''),3,true).ncx_cell('H4','Neto visor').ncx_cell('I4',number_format((float)$emp['total_visor'],2,'.',''),3,true).ncx_cell('J4','Diferencia').ncx_cell('K4',number_format((float)$emp['diferencia'],2,'.',''),3,true).'</row>');

    $headers = ['UUID','Estatus','Serie/Folio','Fecha emisión','Periodo trabajado','Fecha pago','Tipo nómina','No. empleado','Periodicidad','Días pagados','RFC receptor','Nombre receptor','RFC emisor','Nombre emisor','Percepciones','Deducciones','Otros pagos','Neto CFDI'];
    // Añadimos una columna más a las anchuras para Neto CFDI.
    $xml = '<row r="5" ht="32" customHeight="1">';
    foreach ($headers as $i => $h) {
        $col = '';
        $n = $i + 1;
        while ($n > 0) { $n--; $col = chr(65 + ($n % 26)) . $col; $n = intdiv($n, 26); }
        $xml .= ncx_cell($col.'5', $h, 2);
    }
    ncx_write($fh, $xml.'</row>');

    $row = 5;
    foreach ($cfdis as $c) {
        ++$row;
        $tipo = ($c['nomina_tipo_nomina'] ?? '') === 'O' ? 'ORDINARIA' : (($c['nomina_tipo_nomina'] ?? '') === 'E' ? 'EXTRAORDINARIA' : ($c['nomina_tipo_nomina'] ?? ''));
        $sf = trim((string)($c['serie'] ?? ''));
        $folio = trim((string)($c['folio'] ?? ''));
        $sf = trim($sf . ($sf !== '' && $folio !== '' ? ' / ' : '') . $folio);
        $periodo = ncx_date_label($c['nomina_fecha_inicial_pago'] ?? '').' - '.ncx_date_label($c['nomina_fecha_final_pago'] ?? '');
        $vals = [
            [$c['uuid'] ?? '',8,false], [$c['estatus_sat'] ?? '',0,false], [$sf,0,false],
            [ncx_date($c['fecha_emision'] ?? ''),7,true], [$periodo,0,false], [ncx_date($c['nomina_fecha_pago'] ?? ''),7,true],
            [$tipo,0,false], [$c['nomina_num_empleado'] ?? '',8,false], [$c['nomina_periodicidad_pago'] ?? '',8,false],
            [$c['nomina_num_dias_pagados'] ?? '',0,false], [$c['rfc_receptor'] ?? '',8,false], [$c['nombre_receptor'] ?? '',0,false],
            [$c['rfc_emisor'] ?? '',8,false], [$c['nombre_emisor'] ?? '',0,false],
            [number_format((float)($c['nomina_total_percepciones'] ?? 0),2,'.',''),3,true],
            [number_format((float)($c['nomina_total_deducciones'] ?? 0),2,'.',''),3,true],
            [number_format((float)($c['nomina_total_otros_pagos'] ?? 0),2,'.',''),3,true],
            [number_format((float)($c['total_xml'] ?? 0),2,'.',''),3,true],
        ];
        $xml = '<row r="'.$row.'">';
        foreach ($vals as $i => [$v,$style,$num]) {
            $col=''; $n=$i+1;
            while($n>0){$n--; $col=chr(65+($n%26)).$col; $n=intdiv($n,26);}
            $xml .= ncx_cell($col.$row,$v,$style,$num);
        }
        ncx_write($fh,$xml.'</row>');
    }
    $last = max(5, $row);
    ncx_write($fh, '</sheetData><autoFilter ref="A5:R'.$last.'"/><mergeCells count="7"><mergeCell ref="A1:R1"/><mergeCell ref="A2:B2"/><mergeCell ref="C2:R2"/><mergeCell ref="A3:B3"/><mergeCell ref="C3:E3"/><mergeCell ref="A4:B4"/><mergeCell ref="C4:E4"/></mergeCells></worksheet>');
    fclose($fh);

    $zip = new XlsxSimpleWriter($tmp);
    $zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="CFDI EMPLEADO" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addString('xl/styles.xml', ncx_styles());
    $zip->addFile('xl/worksheets/sheet1.xml', $tmpSheet);
    $zip->close();
    unset($zip);

    clearstatcache(true, $tmp);
    $size = filesize($tmp);
    if (!$size || file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04") throw new RuntimeException('No se pudo completar el archivo Excel.');

    $rfcFile = preg_replace('/[^A-Z0-9]/i','',$rfc);
    $desde = preg_replace('/[^0-9]/','',substr((string)$conc['fecha_desde'],0,10));
    $hasta = preg_replace('/[^0-9]/','',substr((string)$conc['fecha_hasta'],0,10));
    $nombre = 'Nomina_CFDI_'.$rfcFile.'_'.$desde.'_'.$hasta.'.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    @ini_set('zlib.output_compression','0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    header('Content-Length: '.$size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
} catch (Throwable $e) {
    if (isset($pdo)) seguridad_log_error($e, 'exportar_nomina_conciliacion_empleado_excel');
    if (!headers_sent()) {
        if (http_response_code() < 400) http_response_code(400);
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo exportar el detalle de nómina del empleado: '.$e->getMessage();
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_string($tmpSheet) && is_file($tmpSheet)) @unlink($tmpSheet);
    if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
}
