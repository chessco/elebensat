<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    if (!isset($_SESSION['id_empresa'])) throw new Exception("Acceso denegado");
    
    $uuid = $_GET['uuid'] ?? '';
    if (empty($uuid)) throw new Exception("UUID no proporcionado");
    
    // Consulta de datos maestros
    $stmt = $pdo->prepare("SELECT f.*, d.xml_base64, e.nombre as em_nom, e.rfc as em_rfc, r.nombre as re_nom, r.rfc as re_rfc 
                           FROM facturas f 
                           JOIN facturas_datos d ON f.uuid = d.uuid
                           LEFT JOIN cat_emisores e ON f.id_emisor = e.id_emisor
                           LEFT JOIN cat_receptores r ON f.id_receptor = r.id_receptor
                           WHERE f.uuid = ? AND f.id_empresa = ?");
    exigir_permiso_factura_uuid($pdo, (int)$_SESSION['id_usuario'], (int)$_SESSION['id_empresa'], $uuid);
    exigir_permiso_accion($pdo, (int)$_SESSION['id_usuario'], (int)$_SESSION['id_empresa'], 'ver_xml_pdf');
    $stmt->execute([$uuid, (int)$_SESSION['id_empresa']]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$d) throw new Exception("Factura no encontrada");

    // Procesamiento XML
    $xml_raw = base64_decode($d['xml_base64']);
    $xml_clean = str_replace(['cfdi:', 'tfd:', 'pago20:', 'nomina12:'], '', $xml_raw); 
    // Nota: Eliminamos namespace pago20 para facilitar lectura si es un pago 2.0
    // Si fuera 1.0 habria que quitar pago10 tb, pero asumimos 4.0/2.0 mayormente
    $xml = @simplexml_load_string($xml_clean);

    // Datos Comunes
    $tfd = $xml->Complemento->TimbreFiscalDigital;
    $fecha_cert = (string)$tfd['FechaTimbrado'];
    $cp_emisor = (string)$xml['LugarExpedicion']; 
    $cp_receptor = (string)$xml->Receptor['DomicilioFiscalReceptor'];
    
    $sello_cfdi = (string)$xml['Sello'];
    $sello_sat  = (string)$tfd['SelloSAT'];
    $no_cert_sat = (string)$tfd['NoCertificadoSAT'];
    $fecha_emi = (string)$xml['Fecha'];
    
    $cadena_original = "||1.1|$uuid|$fecha_cert|".(string)$tfd['RfcProvCertif']."|$sello_cfdi|$no_cert_sat||";
    $url_sat = "https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id=$uuid&re={$d['em_rfc']}&rr={$d['re_rfc']}&tt={$d['total_xml']}&fe=".substr($sello_cfdi, -8);

    $tipo_comprobante = (string)$xml['TipoDeComprobante']; // I, E, P, N, T

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; color: #333; margin: 0; padding: 15px; font-size: 10px; background: #eaeff2; }
        .invoice-box { max-width: 850px; margin: auto; padding: 25px; background: #fff; border-top: 8px solid #1a237e; border-radius: 4px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .title { color: #1a237e; font-size: 18px; font-weight: bold; margin: 0; }
        .header-data { text-align: right; line-height: 1.3; }
        .highlight-uuid { color: #1a237e; font-size: 13px; font-weight: bold; font-family: 'Courier New', monospace; display: block; margin: 3px 0; }
        .section-title { background: #f8f9fa; color: #1a237e; font-weight: bold; padding: 5px 10px; margin: 12px 0 5px; border-left: 4px solid #1a237e; text-transform: uppercase; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background: #1a237e; color: #fff; text-align: left; padding: 6px; font-size: 9px; }
        td { padding: 6px; border: 1px solid #eee; vertical-align: top; }
        .tax-detail { margin-top: 4px; font-size: 8px; width: 100%; border: 1px dashed #bbb; border-collapse: collapse; background: #fcfcfc; }
        .tax-detail td { padding: 2px 5px; border: 1px solid #eee; }
        .grand-total { font-size: 13px; font-weight: bold; color: #1a237e; background: #f8f9fa; border-top: 2px solid #1a237e !important; }
        .sello-text { font-family: monospace; font-size: 7.5px; color: #555; word-break: break-all; background: #f9f9f9; padding: 4px; border: 1px solid #eee; line-height: 1.1; }
        .text-right { text-align: right; }
        
        /* Estilos especificos para Pagos */
        .pago-header { background: #e8eaf6; padding: 5px; border: 1px solid #c5cae9; margin-bottom: 5px; }
        .docto-rel-table th { background: #5c6bc0; }
        
        @media print { body { background: #fff; padding: 0; } .invoice-box { box-shadow: none; border: none; } button { display:none; } }
    </style>
</head>
<body>
    <div style="text-align: center; margin-bottom: 10px;">
        <button onclick="window.print()" style="padding: 10px 25px; background:#1a237e; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">IMPRIMIR COMPROBANTE</button>
    </div>

    <div class="invoice-box">
        <!-- HEADER COMUN -->
        <table width="100%" style="border:none;">
            <tr>
                <td style="border:none;"><p class="title">REPRESENTACIÓN IMPRESA CFDI 4.0</p>
                    <span style='font-size:12px; font-weight:bold; color:#555;'>
                        <?php 
                        if ($tipo_comprobante == 'P') echo 'COMPLEMENTO DE PAGOS';
                        elseif ($tipo_comprobante == 'N') echo 'RECIBO DE NÓMINA';
                        elseif ($tipo_comprobante == 'E') echo 'NOTA DE EGRESO';
                        else echo 'FACTURA DE INGRESO';
                        ?>
                    </span>
                </td>
                <td class="header-data" style="border:none;">
                    <b>FOLIO:</b> <?php echo $d['folio'] ?: 'S/F'; ?><br>
                    <b>FECHA EMISIÓN:</b> <?php echo $fecha_emi; ?><br>
                    <span style="font-size: 8px; color: #7f8c8d; font-weight: bold;">UUID FISCAL:</span>
                    <span class="highlight-uuid"><?php echo $uuid; ?></span>
                    <span style="font-size: 8px; color: #7f8c8d; font-weight: bold;">FECHA CERTIFICACIÓN:</span><br>
                    <span style="font-size:11px; font-weight:bold;"><?php echo $fecha_cert; ?></span>
                </td>
            </tr>
        </table>

        <div class="section-title">Emisor / Receptor</div>
        <table width="100%">
            <tr>
                <td width="50%"><strong><?php echo $d['em_nom']; ?></strong><br>RFC: <?php echo $d['em_rfc']; ?><br>Lugar Exp: <?php echo $cp_emisor; ?></td>
                <td width="50%"><strong><?php echo $d['re_nom']; ?></strong><br>RFC: <?php echo $d['re_rfc']; ?><br>CP Receptor: <?php echo $cp_receptor; ?></td>
            </tr>
        </table>

        <?php if ($tipo_comprobante == 'P'): ?>
            <!-- =========================== -->
            <!-- VISTA COMPLEMENTO DE PAGOS  -->
            <!-- =========================== -->
            
            <?php 
            // Buscamos el nodo de Pagos. Puede estar en Pagos (2.0)
            $pagosNode = $xml->Complemento->Pagos; 
            ?>
            
             <div class="section-title">Totales del Complemento</div>
             <table>
                <tr>
                    <th>Total Retenciones IVA</th>
                    <th>Total Retenciones ISR</th>
                    <th>Total Traslados IVA 16%</th>
                    <th>Total Traslados IVA 8%</th>
                    <th>Total Traslados Base 0%</th>
                    <th class="text-right">Monto Total Pagos</th>
                </tr>
                <tr>
                   <td>$<?php echo number_format((float)($pagosNode->Totales['TotalRetencionesIVA'] ?? 0), 2); ?></td>
                   <td>$<?php echo number_format((float)($pagosNode->Totales['TotalRetencionesISR'] ?? 0), 2); ?></td>
                   <td>$<?php echo number_format((float)($pagosNode->Totales['TotalTrasladosImpuestoIVA16'] ?? 0), 2); ?></td>
                   <td>$<?php echo number_format((float)($pagosNode->Totales['TotalTrasladosImpuestoIVA8'] ?? 0), 2); ?></td>
                   <td>$<?php echo number_format((float)($pagosNode->Totales['TotalTrasladosBaseIVA0'] ?? 0), 2); ?></td>
                   <td class="text-right" style="font-weight:bold; font-size:12px;">$<?php echo number_format((float)($pagosNode->Totales['MontoTotalPagos'] ?? 0), 2); ?></td>
                </tr>
             </table>

             <div class="section-title">Detalle de Pagos</div>
             <?php foreach ($pagosNode->Pago as $pago): ?>
                <div class="pago-header">
                    <table style="margin:0; border:none; background:transparent;">
                        <tr>
                            <td style="border:none;"><strong>Fecha Pago:</strong> <?php echo $pago['FechaPago']; ?></td>
                            <td style="border:none;"><strong>Forma Pago:</strong> <?php echo $pago['FormaDePagoP']; ?></td>
                            <td style="border:none;"><strong>Moneda:</strong> <?php echo $pago['MonedaP']; ?> (TC: <?php echo $pago['TipoCambioP']; ?>)</td>
                            <td style="border:none; text-align:right;"><strong>Monto:</strong> <span style="font-size:12px;">$<?php echo number_format((float)$pago['Monto'], 2); ?></span></td>
                        </tr>
                        <?php if(isset($pago['NumOperacion'])): ?>
                        <tr><td colspan="4" style="border:none; font-size:9px;"><b>Operación:</b> <?php echo $pago['NumOperacion']; ?> | <b>Cta Ord:</b> <?php echo $pago['CtaOrdenante']; ?> | <b>Cta Ben:</b> <?php echo $pago['CtaBeneficiario']; ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <div style="padding-left: 10px; margin-bottom: 15px;">
                    <table class="docto-rel-table">
                        <thead>
                            <tr>
                                <th>UUID Documento Relacionado</th>
                                <th width="30">Ser/Fol</th>
                                <th>Moneda</th>
                                <th>Método</th>
                                <th>Parc.</th>
                                <th class="text-right">Saldo Ant.</th>
                                <th class="text-right">Pagado</th>
                                <th class="text-right">Saldo Insoluto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pago->DoctoRelacionado as $doc): 
                               $taxesDR = $doc->ImpuestosDR;
                            ?>
                            <tr style="background:#fdfdfe;">
                                <td style="font-size:8px; font-family:monospace;">
                                    <?php echo $doc['IdDocumento']; ?>
                                    <div style="font-size:7px; color:#555; margin-top:2px;">
                                        ObjImp: <?php echo $doc['ObjetoImpDR']; ?> | Equiv: <?php echo $doc['EquivalenciaDR']; ?>
                                    </div>
                                </td>
                                <td><?php echo $doc['Serie'].'-'.$doc['Folio']; ?></td>
                                <td><?php echo $doc['MonedaDR']; ?></td>
                                <td><?php echo $doc['MetodoDePagoDR']; ?></td>
                                <td align="center"><?php echo $doc['NumParcialidad']; ?></td>
                                <td class="text-right">$<?php echo number_format((float)$doc['ImpSaldoAnt'], 2); ?></td>
                                <td class="text-right" style="font-weight:bold; background:#e8f5e9;">$<?php echo number_format((float)$doc['ImpPagado'], 2); ?></td>
                                <td class="text-right">$<?php echo number_format((float)$doc['ImpSaldoInsoluto'], 2); ?></td>
                            </tr>
                            <?php if(isset($taxesDR)): ?>
                            <tr>
                                <td colspan="8" style="padding:0;">
                                    <!-- TABLA DE IMPUESTOS PROPORCIONALES -->
                                    <table style="width:95%; margin:0 auto 5px auto; border:1px dashed #ccc; background:#fafafa;">
                                        <thead>
                                            <tr>
                                                <th style="background:#eee; color:#333; font-size:7px;">Tipo</th>
                                                <th style="background:#eee; color:#333; font-size:7px;">Base</th>
                                                <th style="background:#eee; color:#333; font-size:7px;">Impuesto</th>
                                                <th style="background:#eee; color:#333; font-size:7px;">TipoFactor</th>
                                                <th style="background:#eee; color:#333; font-size:7px;">Tasa/Cuota</th>
                                                <th style="background:#eee; color:#333; font-size:7px;">Importe</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // TrasladosDR
                                            if(isset($taxesDR->TrasladosDR->TrasladoDR)):
                                                foreach($taxesDR->TrasladosDR->TrasladoDR as $t): ?>
                                                <tr>
                                                    <td style="font-size:7px;">Traslado</td>
                                                    <td style="font-size:7px;">$<?php echo number_format((float)$t['BaseDR'], 2); ?></td>
                                                    <td style="font-size:7px;"><?php echo $t['ImpuestoDR']; ?></td>
                                                    <td style="font-size:7px;"><?php echo $t['TipoFactorDR']; ?></td>
                                                    <td style="font-size:7px;"><?php echo $t['TasaOCuotaDR']; ?></td>
                                                    <td style="font-size:7px;">$<?php echo number_format((float)$t['ImporteDR'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                            
                                            <?php 
                                            // RetencionesDR
                                            if(isset($taxesDR->RetencionesDR->RetencionDR)):
                                                foreach($taxesDR->RetencionesDR->RetencionDR as $r): ?>
                                                <tr>
                                                    <td style="font-size:7px;">Retención</td>
                                                    <td style="font-size:7px;">$<?php echo number_format((float)$r['BaseDR'], 2); ?></td>
                                                    <td style="font-size:7px;"><?php echo $r['ImpuestoDR']; ?></td>
                                                    <td style="font-size:7px;"><?php echo $r['TipoFactorDR']; ?></td>
                                                    <td style="font-size:7px;"><?php echo $r['TasaOCuotaDR']; ?></td>
                                                    <td style="font-size:7px;">$<?php echo number_format((float)$r['ImporteDR'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
             <?php endforeach; ?>

        <?php elseif ($tipo_comprobante == 'N'): ?>
            <!-- =========================== -->
            <!-- VISTA NÓMINA 1.2           -->
            <!-- =========================== -->
            <?php
            $nom = $xml->Complemento->Nomina;
            $emi = $nom->Emisor;
            $rec = $nom->Receptor; // Curp, NumSeguridadSocial, FechaInicioRelLaboral, Antigüedad, TipoContrato, etc.
            
            // Percepciones
            $percepciones = [];
            if(isset($nom->Percepciones->Percepcion)) foreach($nom->Percepciones->Percepcion as $p) $percepciones[] = $p;
            
            // Deducciones
            $deducciones = [];
            if(isset($nom->Deducciones->Deduccion)) foreach($nom->Deducciones->Deduccion as $d) $deducciones[] = $d;

            // Otros Pagos
            $otros = [];
            if(isset($nom->OtrosPagos->OtroPago)) foreach($nom->OtrosPagos->OtroPago as $o) $otros[] = $o;
            ?>

            <div class="section-title">Datos del Empleado y del Recibo</div>
            <table style="font-size:9px; margin-bottom:15px;">
                <tr>
                    <td width="25%"><b>No. Emp:</b> <?php echo $rec['NumEmpleado']; ?></td>
                    <td width="25%"><b>CURP:</b> <?php echo $rec['Curp']; ?></td>
                    <td width="25%"><b>NSS:</b> <?php echo $rec['NumSeguridadSocial']; ?></td>
                    <td width="25%"><b>Reg. Patrón:</b> <?php echo $emi['RegistroPatronal']; ?></td>
                </tr>
                <tr>
                    <td><b>Departamento:</b> <?php echo $rec['Departamento']; ?></td>
                    <td><b>Puesto:</b> <?php echo $rec['Puesto']; ?></td>
                    <td><b>Contrato:</b> <?php echo $rec['TipoContrato']; ?> | <b>Jornada:</b> <?php echo $rec['TipoJornada']; ?></td>
                    <td><b>Antigüedad:</b> <?php echo $rec['Antigüedad']; ?> | <b>Riesgo:</b> <?php echo $rec['RiesgoPuesto']; ?></td>
                </tr>
                <tr>
                    <td><b>Periodo:</b> <?php echo $rec['PeriodicidadPago']; ?></td>
                    <td><b>Inicio Pago:</b> <?php echo $nom['FechaInicialPago']; ?></td>
                    <td><b>Fin Pago:</b> <?php echo $nom['FechaFinalPago']; ?></td>
                    <td><b>Días Pag:</b> <?php echo $nom['NumDiasPagados']; ?></td>
                </tr>
                <tr>
                    <td><b>Banco:</b> <?php echo $rec['Banco']; ?></td>
                    <td colspan="2"><b>Cuenta:</b> <?php echo $rec['CuentaBancaria']; ?></td>
                    <td><b>SDI:</b> $<?php echo $rec['SalarioDiarioIntegrado']; ?></td>
                </tr>
            </table>

            <table width="100%" style="border:none;">
                <tr style="background:#1a237e; color:white;">
                    <th width="50%" style="font-size:10px; padding:5px;">PERCEPCIONES</th>
                    <th width="50%" style="font-size:10px; padding:5px;">DEDUCCIONES</th>
                </tr>
                <tr>
                    <td style="vertical-align:top; padding:0; border:1px solid #ccc;">
                        <table style="width:100%; border:none; margin:0;">
                            <tr style="background:#f5f5f5; font-weight:bold; font-size:8px;">
                                <td width="15%">Cve</td>
                                <td>Concepto</td>
                                <td width="20%" class="text-right">Importe</td>
                            </tr>
                            <?php foreach($percepciones as $p): ?>
                            <tr>
                                <td style="border-bottom:1px solid #eee;"><?php echo $p['Clave']; ?></td>
                                <td style="border-bottom:1px solid #eee;"><?php echo $p['Concepto']; ?></td>
                                <td style="border-bottom:1px solid #eee;" class="text-right">
                                    $<?php echo number_format((float)$p['ImporteGravado'] + (float)$p['ImporteExento'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(!empty($otros)): ?>
                            <tr style="background:#e8eaf6;"><td colspan="3" style="font-weight:bold; font-size:8px; padding:2px;">OTROS PAGOS</td></tr>
                            <?php foreach($otros as $o): ?>
                            <tr>
                                <td style="border-bottom:1px solid #eee;"><?php echo $o['Clave']; ?></td>
                                <td style="border-bottom:1px solid #eee;"><?php echo $o['Concepto']; ?></td>
                                <td style="border-bottom:1px solid #eee;" class="text-right">$<?php echo number_format((float)$o['Importe'], 2); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </table>
                    </td>
                    <td style="vertical-align:top; padding:0; border:1px solid #ccc;">
                        <table style="width:100%; border:none; margin:0;">
                            <tr style="background:#f5f5f5; font-weight:bold; font-size:8px;">
                                <td width="15%">Cve</td>
                                <td>Concepto</td>
                                <td width="20%" class="text-right">Importe</td>
                            </tr>
                            <?php foreach($deducciones as $d): ?>
                            <tr>
                                <td style="border-bottom:1px solid #eee;"><?php echo $d['Clave']; ?></td>
                                <td style="border-bottom:1px solid #eee;"><?php echo $d['Concepto']; ?></td>
                                <td style="border-bottom:1px solid #eee;" class="text-right">$<?php echo number_format((float)$d['Importe'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
            </table>

            <div style="background:#f8f9fa; padding:10px; border:1px solid #ddd; margin-top:10px;">
                <table style="width:100%; font-weight:bold; font-size:11px;">
                     <tr>
                         <td class="text-right" width="40%">Total Percepciones + Otros:</td>
                         <td width="10%">$<?php echo number_format((float)$nom['TotalPercepciones'] + (float)$nom['TotalOtrosPagos'], 2); ?></td>
                         <td class="text-right" width="40%">Total Deducciones:</td>
                         <td width="10%">$<?php echo number_format((float)$nom['TotalDeducciones'], 2); ?></td>
                     </tr>
                     <tr style="font-size:14px; color:#1a237e; border-top:2px solid #ccc;">
                         <td class="text-right" colspan="3" style="padding-top:5px;">NETO A PAGAR:</td>
                         <td style="padding-top:5px;">$<?php echo number_format((float)$xml['Total'], 2); ?></td>
                     </tr>
                </table>
            </div>

        <?php else: ?>
            <!-- =========================== -->
            <!-- VISTA ESTANDAR (I, E, N)    -->
            <!-- =========================== -->
            <div class="section-title">Conceptos e Impuestos Detallados</div>
            <table>
                <thead>
                    <tr>
                        <th>Clave P/S</th>
                        <th width="40">Cant.</th>
                        <th>Descripción / Impuestos por Partida</th>
                        <th class="text-right">Unitario</th>
                        <th class="text-right">Desc.</th>
                        <th class="text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (isset($xml->Conceptos->Concepto)): foreach($xml->Conceptos->Concepto as $c): ?>
                    <tr>
                        <td align="center"><?php echo $c['ClaveProdServ']; ?></td>
                        <td align="center"><?php echo $c['Cantidad']; ?></td>
                        <td>
                            <strong><?php echo $c['Descripcion']; ?></strong>
                            <table class="tax-detail">
                                <tr style="background:#f2f2f2; font-weight:bold;"><td>Tipo</td><td>Base</td><td>Impuesto</td><td>Tasa</td><td>Importe</td></tr>
                                <?php 
                                $has_iva_t = false;
                                if(isset($c->Impuestos->Traslados->Traslado)): 
                                    foreach($c->Impuestos->Traslados->Traslado as $t): $has_iva_t = true; ?>
                                    <tr><td>Traslado</td><td>$<?php echo number_format((float)$t['Base'],2); ?></td><td>IVA (002)</td><td><?php echo $t['TasaOCuota']; ?></td><td>$<?php echo number_format((float)$t['Importe'],2); ?></td></tr>
                                <?php endforeach; endif; 
                                if(!$has_iva_t): ?><tr><td>Traslado</td><td>$0.00</td><td>IVA (002)</td><td>0.000000</td><td>$0.00</td></tr><?php endif; ?>
                                
                                <?php 
                                $ret_iva = 0; $ret_isr = 0;
                                if(isset($c->Impuestos->Retenciones->Retencion)):
                                    foreach($c->Impuestos->Retenciones->Retencion as $r): 
                                        if((string)$r['Impuesto'] == '002') $ret_iva = (float)$r['Importe'];
                                        if((string)$r['Impuesto'] == '001') $ret_isr = (float)$r['Importe'];
                                    ?>
                                    <tr><td>Retención</td><td>$<?php echo number_format((float)$r['Base'],2); ?></td><td><?php echo (string)$r['Impuesto']=='002'?'IVA':'ISR'; ?></td><td><?php echo $r['TasaOCuota']; ?></td><td>$<?php echo number_format((float)$r['Importe'],2); ?></td></tr>
                                <?php endforeach; endif; 
                                if($ret_iva == 0): ?><tr><td>Retención</td><td>$0.00</td><td>IVA (002)</td><td>0.000000</td><td>$0.00</td></tr><?php endif; ?>
                                <?php if($ret_isr == 0): ?><tr><td>Retención</td><td>$0.00</td><td>ISR (001)</td><td>0.000000</td><td>$0.00</td></tr><?php endif; ?>
                            </table>
                        </td>
                        <td class="text-right">$<?php echo number_format((float)$c['ValorUnitario'], 2); ?></td>
                        <td class="text-right">$<?php echo number_format((float)($c['Descuento'] ?? 0), 2); ?></td>
                        <td class="text-right">$<?php echo number_format((float)$c['Importe'], 2); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            
       <?php endif; ?>

        <div class="cfdi-footer" style="margin-top: 20px;">
            <div style="display: flex; gap: 15px;">
                <div id="qrcode"></div>
                <div style="flex: 1;">
                    <span class="label-small">Sello Digital del CFDI:</span>
                    <div class="sello-text"><?php echo $sello_cfdi; ?></div>
                    <span class="label-small">Sello Digital del SAT:</span>
                    <div class="sello-text"><?php echo $sello_sat; ?></div>
                    <span class="label-small">Cadena Original del Complemento de Certificación:</span>
                    <div class="sello-text"><?php echo $cadena_original; ?></div>
                </div>
            </div>
            <p style="text-align:center; font-size:8px; color:#999; margin-top:10px;">Este documento es una representación impresa de un CFDI 4.0</p>
        </div>
    </div>
    <script>new QRCode(document.getElementById("qrcode"), { text: "<?php echo $url_sat; ?>", width: 110, height: 110, colorDark: "#1a237e" });</script>
</body>
</html>
<?php
} catch (Exception $e) { seguridad_log_error($e, 'generar1_pdf'); echo 'No se pudo generar el PDF.'; }
?>