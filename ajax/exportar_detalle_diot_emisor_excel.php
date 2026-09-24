<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';

function hx($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function mn($v){ return number_format((float)$v,2,'.',','); }
try{
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot');
    $idEmpresa=(int)($_SESSION['id_empresa']??0); $idEmisor=(int)($_GET['id_emisor']??0); $rfc=strtoupper(trim((string)($_GET['rfc']??'')));
    $mes=(int)($_GET['mes']??date('m')); $anio=(int)($_GET['anio']??date('Y'));
    if($idEmisor<=0 && !$rfc) throw new InvalidArgumentException('Emisor no recibido.');
    [$inicio,$fin]=diot_periodo_limites($anio,$mes);
    $sqlE="SELECT id_emisor,rfc,nombre FROM cat_emisores WHERE id_empresa=? AND aplica_diot=1 AND ".($idEmisor>0?'id_emisor=?':'UPPER(TRIM(rfc))=UPPER(TRIM(?))')." LIMIT 1";
    $st=$pdo->prepare($sqlE);
    $st->execute([$idEmpresa,$idEmisor>0?$idEmisor:$rfc]); $e=$st->fetch(PDO::FETCH_ASSOC); if(!$e) throw new RuntimeException('Emisor no encontrado.');
    $sql="SELECT d.id_detalle,d.uuid_pago,d.uuid_relacionado,d.parcialidad,d.origen_pago,d.fecha_pago_sat,d.fecha_pago_banco,d.fecha_pago_usuario,d.fecha_aplicacion_fiscal,d.forma_pago,d.referencia,d.importe_aplicado,d.base_iva_16,d.iva_16,d.base_iva_8,d.iva_8,d.base_tasa_0,d.base_exento,d.base_no_objeto,d.iva_retenido,d.isr_retenido,d.ieps_otros,d.moneda_factura,d.tc_factura,d.moneda_dr,d.equivalencia_dr,f.serie,f.folio,f.fecha_emision,f.total_xml,f.moneda,f.tc_xml_factura
          FROM facturas_pagos_detalles d
          INNER JOIN facturas f
                  ON f.id_empresa=d.id_empresa
                 AND f.uuid=d.uuid_relacionado
          LEFT JOIN facturas fp
                  ON fp.id_empresa=d.id_empresa
                 AND fp.uuid = d.uuid_pago
          INNER JOIN cat_receptores r
                  ON r.id_empresa=f.id_empresa
                 AND r.id_receptor=f.id_receptor
          INNER JOIN empresas emp
                  ON emp.id_empresa=f.id_empresa
          WHERE d.id_empresa=?
            AND d.id_emisor=?
            AND d.fecha_aplicacion_fiscal>=?
            AND d.fecha_aplicacion_fiscal<?
            AND UPPER(TRIM(COALESCE(r.rfc,''))) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(COALESCE(emp.rfc,''))) COLLATE utf8mb4_unicode_ci
            AND UPPER(TRIM(COALESCE(f.estatus_sat,'Vigente'))) IN ('VIGENTE','1')
            AND COALESCE(f.excluir_diot,0)=0
            AND COALESCE(d.es_sustituido,0)=0
            AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
            AND (d.es_sintetico=1 OR COALESCE(fp.excluir_diot,0)=0)
          ORDER BY d.fecha_aplicacion_fiscal,d.uuid_relacionado,d.parcialidad";
    $st=$pdo->prepare($sql); $st->execute([$idEmpresa,(int)$e['id_emisor'],$inicio,$fin]); $sat=$st->fetchAll(PDO::FETCH_ASSOC);
    $sqlc="SELECT c.*,COALESCE(ch.tipo_documento,eg.tipo_documento) tipo_documento,COALESCE(ch.folio,eg.folio) folio_contpaq,COALESCE(ch.fecha,eg.fecha,c.fecha_pago_contpaq) fecha_movimiento,COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador) beneficiario,COALESCE(ch.referencia,eg.referencia) referencia_contpaq,COALESCE(ch.concepto,eg.concepto) concepto_contpaq,COALESCE(ch.num_pol,eg.num_pol) num_pol,COALESCE(ch.total,eg.total,c.importe_contpaq) total_movimiento FROM conciliacion_pagos_detalle c LEFT JOIN contpaq_cheques ch ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque LEFT JOIN contpaq_egresos eg ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso WHERE c.id_empresa=? AND c.rfc_emisor = ? AND c.fecha_pago_sat>=? AND c.fecha_pago_sat<? ORDER BY c.fecha_pago_contpaq,c.id_conciliacion";
    $st=$pdo->prepare($sqlc); $st->execute([$idEmpresa,(string)$e['rfc'],$inicio,$fin]); $cp=$st->fetchAll(PDO::FETCH_ASSOC);
    $safe=preg_replace('/[^A-Z0-9_-]/','_',$rfc);
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Auditoria_DIOT_'.$safe.'_'.sprintf('%02d',$mes).'_'.$anio.'.xls"');
    echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse;margin-bottom:20px}th{background:#1f5d7a;color:white}td,th{border:1px solid #777;padding:4px;font-size:10pt}.num{text-align:right}.sub{background:#d9edf7;font-weight:bold}</style></head><body>';
    echo '<h2>Auditoría DIOT por emisor</h2><p><b>Emisor:</b> '.hx($e['nombre']).'<br><b>RFC:</b> '.hx($e['rfc']).'<br><b>Periodo:</b> '.sprintf('%02d/%04d',$mes,$anio).'</p>';
    $tot=array_fill_keys(['importe_aplicado','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros'],0.0);
    foreach($sat as &$d){
        $monedaOrigen=$d['moneda_dr'] ?: ($d['moneda_factura'] ?: ($d['moneda'] ?: 'MXN'));
        $tcOrigen=(float)($d['tc_factura'] ?: ($d['tc_xml_factura'] ?: 1));
        $factorMxn=diot_factor_mxn($monedaOrigen,$tcOrigen);
        $d['moneda_origen']=strtoupper(trim((string)$monedaOrigen));
        $d['tc_origen']=$tcOrigen;
        foreach(array_keys($tot) as $k){
            $d[$k]=(float)($d[$k]??0)*$factorMxn;
            $tot[$k]+=$d[$k];
        }
    } unset($d);
    echo '<h3>Resumen fiscal</h3><table><tr>'; foreach($tot as $k=>$v) echo '<th>'.hx(str_replace('_',' ',strtoupper($k))).'</th>'; echo '</tr><tr>'; foreach($tot as $v) echo '<td class="num">'.mn($v).'</td>'; echo '</tr></table>';
    echo '<h3>Complementos y detalles de pago SAT</h3><table><tr><th>Fecha fiscal</th><th>Fecha SAT</th><th>Factura</th><th>UUID factura</th><th>UUID complemento</th><th>Parc.</th><th>Moneda origen</th><th>TC origen</th><th>Pago MXN</th><th>Base 16</th><th>IVA 16</th><th>Base 8</th><th>IVA 8</th><th>Tasa 0</th><th>Exento</th><th>No objeto</th><th>IVA ret.</th><th>ISR ret.</th><th>IEPS</th><th>Forma</th><th>Referencia</th></tr>';
    foreach($sat as $d){echo '<tr><td>'.hx($d['fecha_aplicacion_fiscal']).'</td><td>'.hx($d['fecha_pago_sat']).'</td><td>'.hx(trim(($d['serie']??'').' '.($d['folio']??''))).'</td><td>'.hx($d['uuid_relacionado']).'</td><td>'.hx($d['uuid_pago']).'</td><td>'.hx($d['parcialidad']).'</td><td>'.hx($d['moneda_origen']??'MXN').'</td><td class="num">'.mn($d['tc_origen']??1).'</td>';foreach(['importe_aplicado','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros'] as $k)echo '<td class="num">'.mn($d[$k]).'</td>';echo '<td>'.hx($d['forma_pago']).'</td><td>'.hx($d['referencia']).'</td></tr>';}
    echo '</table><h3>Movimientos CONTPAQ conciliados</h3><table><tr><th>Tipo</th><th>Folio</th><th>Fecha</th><th>Beneficiario</th><th>Total movimiento</th><th>Importe SAT</th><th>Aplicado</th><th>Diferencia</th><th>Dif. días</th><th>Póliza</th><th>Referencia</th><th>UUID factura</th><th>UUID REP</th><th>Estatus</th></tr>';
    foreach($cp as $c){echo '<tr><td>'.hx($c['tipo_documento']).'</td><td>'.hx($c['folio_contpaq']).'</td><td>'.hx($c['fecha_movimiento']).'</td><td>'.hx($c['beneficiario']).'</td><td class="num">'.mn($c['total_movimiento']).'</td><td class="num">'.mn($c['importe_sat']).'</td><td class="num">'.mn($c['importe_conciliado']).'</td><td class="num">'.mn($c['diferencia_importe']).'</td><td>'.hx($c['diferencia_dias']).'</td><td>'.hx($c['num_pol']).'</td><td>'.hx($c['referencia_contpaq']).'</td><td>'.hx($c['uuid_factura']).'</td><td>'.hx($c['uuid_rep_contpaq']).'</td><td>'.hx($c['estatus']).'</td></tr>';}
    echo '</table></body></html>';
}catch(Throwable $e){http_response_code(500);seguridad_log_error($e,'exportar_detalle_diot_emisor_excel');echo 'No se pudo generar la auditoría del emisor.';}
