<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);

function ppd_xls_xml($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function ppd_xls_fecha($v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}
function ppd_xls_fecha_filtro($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    foreach (['Y-m-d','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    return '';
}
function ppd_xls_factura(array $r): string {
    $serie = trim((string)($r['serie'] ?? ''));
    $folio = trim((string)($r['folio'] ?? ''));
    $factura = trim($serie . ($serie !== '' && $folio !== '' ? '-' : '') . $folio);
    return $factura !== '' ? $factura : 'S/F';
}

try {
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa.');

    $inicio = ppd_xls_fecha_filtro($_GET['inicio'] ?? '');
    $fin    = ppd_xls_fecha_filtro($_GET['fin'] ?? '');
    $buscar = trim((string)($_GET['buscar'] ?? ''));

    if ($inicio === '' || $fin === '') throw new RuntimeException('Seleccione un rango de fechas válido.');
    if ($inicio > $fin) throw new RuntimeException('La fecha inicial no puede ser mayor a la final.');

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $finExclusivo = (new DateTime($fin))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $whereBuscar = '';
    $params = [
        ':empresa' => $idEmpresa,
        ':inicio' => $inicio . ' 00:00:00',
        ':fin' => $finExclusivo,
    ];
    if ($buscar !== '') {
        $whereBuscar = " AND (COALESCE(e.rfc,'') LIKE :buscar OR COALESCE(e.nombre,'') LIKE :buscar)";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    $sql = "SELECT
                f.uuid,
                f.serie,
                f.folio,
                f.fecha_emision,
                f.total_xml AS importe,
                f.moneda,
                f.folio_pago_origen AS cheque,
                COALESCE(f.fecha_pago_origen, f.fecha_ultimo_pago_contpaq, f.fecha_pago_banco, f.fecha_pago_usuario) AS fecha_cheque,
                e.rfc AS rfc_emisor,
                e.nombre AS emisor,
                COALESCE(e.correo,'') AS correo_emisor
            FROM facturas f
            LEFT JOIN cat_emisores e
              ON e.id_empresa=f.id_empresa
             AND e.id_emisor=f.id_emisor
            WHERE f.id_empresa=:empresa
              AND f.fecha_emision >= :inicio
              AND f.fecha_emision < :fin
              AND f.tipo_movimiento_empresa='E'
              AND f.metodo_pago='PPD'
              AND TRIM(COALESCE(f.folio_pago_origen,'')) <> ''
              AND COALESCE(f.fecha_pago_origen, f.fecha_ultimo_pago_contpaq, f.fecha_pago_banco, f.fecha_pago_usuario) IS NOT NULL
              AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')
              AND NOT EXISTS (
                    SELECT 1
                    FROM facturas_pagos_detalles pdx
                    WHERE pdx.id_empresa=f.id_empresa
                      AND pdx.uuid_relacionado=f.uuid
              )
              {$whereBuscar}
            ORDER BY f.fecha_emision ASC, e.nombre ASC, f.folio ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $empresaNombre = '';
    $stEmp = $pdo->prepare('SELECT razon_social FROM empresas WHERE id_empresa=? LIMIT 1');
    $stEmp->execute([$idEmpresa]);
    $empresaNombre = (string)($stEmp->fetchColumn() ?: '');

    $archivo = 'PPD_Sin_Complemento_' . $inicio . '_a_' . $fin . '_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Font ss:FontName="Calibri" ss:Size="10"/></Style>
  <Style ss:ID="Titulo"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="Subtitulo"><Font ss:Italic="1" ss:Color="#666666"/></Style>
  <Style ss:ID="Encabezado"><Font ss:Bold="1"/><Alignment ss:Horizontal="Center"/><Interior ss:Pattern="Solid" ss:Color="#D9EAF7"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="Moneda"><NumberFormat ss:Format="#,##0.00"/></Style>
 </Styles>
 <Worksheet ss:Name="PPD SIN COMPLEMENTO">
  <Table>
   <Column ss:Width="220"/><Column ss:Width="95"/><Column ss:Width="85"/><Column ss:Width="85"/><Column ss:Width="85"/><Column ss:Width="105"/><Column ss:Width="220"/><Column ss:Width="100"/><Column ss:Width="65"/><Column ss:Width="210"/>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Titulo"><Data ss:Type="String">PPD SIN COMPLEMENTO</Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Subtitulo"><Data ss:Type="String">Empresa: <?= ppd_xls_xml($empresaNombre) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String">Rango: <?= ppd_xls_xml($inicio) ?> al <?= ppd_xls_xml($fin) ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String">Filtro RFC / Emisor: <?= ppd_xls_xml($buscar !== '' ? $buscar : 'Todos') ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String">Registros exportados: <?= count($rows) ?></Data></Cell></Row>
   <Row>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">UUID</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">FACTURA</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">FECHA FACTURA</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">CHEQUE</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">FECHA CHEQUE</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">RFC</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">EMISOR</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">IMPORTE</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">MONEDA</Data></Cell>
    <Cell ss:StyleID="Encabezado"><Data ss:Type="String">CORREO EMISOR</Data></Cell>
   </Row>
<?php foreach ($rows as $r): ?>
   <Row>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['uuid'] ?? '') ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml(ppd_xls_factura($r)) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml(ppd_xls_fecha($r['fecha_emision'] ?? '')) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['cheque'] ?? '') ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml(ppd_xls_fecha($r['fecha_cheque'] ?? '')) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['rfc_emisor'] ?? '') ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['emisor'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="Moneda"><Data ss:Type="Number"><?= number_format((float)($r['importe'] ?? 0), 2, '.', '') ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['moneda'] ?? '') ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= ppd_xls_xml($r['correo_emisor'] ?? '') ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>6</SplitHorizontal><TopRowBottomPane>6</TopRowBottomPane></WorksheetOptions>
 </Worksheet>
</Workbook>
<?php
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo exportar: ' . $e->getMessage();
}
