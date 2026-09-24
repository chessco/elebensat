<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';

function ad_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ad_n($v){ return number_format((float)$v, 2, '.', ','); }
function ad_fecha($v){
    $v=trim((string)$v);
    if($v==='') return '-';
    $t=strtotime($v);
    return $t ? date('d/m/Y',$t) : $v;
}
function ad_job_paths(string $token): array {
    $b=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sgksat_diot_audit_'.$token;
    return [$b.'.json',$b.'.xls'];
}
function ad_meta(string $token): array {
    if(!preg_match('/^[a-f0-9]{32}$/',$token)) throw new InvalidArgumentException('Token inválido.');
    [$mf,$xf]=ad_job_paths($token);
    if(!is_file($mf)||!is_file($xf)) throw new RuntimeException('El proceso ya no existe o fue cancelado.');
    $m=json_decode((string)file_get_contents($mf),true);
    if(!is_array($m)) throw new RuntimeException('Estado de proceso inválido.');
    return $m;
}

try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot');

    $token=(string)($_POST['token']??'');
    $m=ad_meta($token);
    [$mf,$xf]=ad_job_paths($token);

    if((int)$m['id_usuario']!==(int)($_SESSION['id_usuario']??0) || (int)$m['id_empresa']!==(int)($_SESSION['id_empresa']??0)) {
        throw new RuntimeException('Proceso no autorizado.');
    }

    if(!empty($m['completo'])) {
        echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$m['total'],'total'=>$m['total'],'porcentaje'=>100]);
        exit;
    }

    $idx=(int)$m['indice'];
    $total=(int)$m['total'];
    if($idx >= $total) {
        file_put_contents($xf,'</body></html>',FILE_APPEND|LOCK_EX);
        $m['completo']=true;
        file_put_contents($mf,json_encode($m,JSON_UNESCAPED_UNICODE),LOCK_EX);
        echo json_encode(['status'=>'ok','completo'=>true,'procesados'=>$total,'total'=>$total,'porcentaje'=>100]);
        exit;
    }

    $em=$m['emisores'][$idx];
    $idEmpresa=(int)$m['id_empresa'];
    $idEmisor=(int)$em['id_emisor'];
    $rfc=strtoupper(trim((string)$em['rfc']));
    [$inicio,$fin]=diot_periodo_limites((int)$m['anio'],(int)$m['mes']);

    // Mismo emisor y mismas llaves que usa la ventana "Auditoría DIOT por emisor".
    $st=$pdo->prepare("SELECT id_emisor,rfc,nombre,tipo_tercero,tipo_operacion,num_id_fiscal,nombre_extranjero,pais_residencia,nacionalidad
                         FROM cat_emisores
                        WHERE id_empresa=? AND id_emisor=? LIMIT 1");
    $st->execute([$idEmpresa,$idEmisor]);
    $e=$st->fetch(PDO::FETCH_ASSOC) ?: $em;
    $rfc=strtoupper(trim((string)($e['rfc']??$rfc)));

    // SAT: exactamente la misma consulta base del detalle DIOT rápido.
    $sqlSat="SELECT d.id_detalle,d.uuid_pago,d.uuid_relacionado,d.parcialidad,d.origen_pago,
                   d.fecha_pago_sat,d.fecha_pago_banco,d.fecha_pago_usuario,d.fecha_aplicacion_fiscal,
                   d.forma_pago,d.referencia,d.monto_pagado,d.importe_aplicado,d.saldo_anterior,d.saldo_insoluto,
                   d.base_iva_16,d.iva_16,d.base_iva_8,d.iva_8,d.base_tasa_0,d.base_exento,d.base_no_objeto,
                   d.iva_retenido,d.isr_retenido,d.ieps_otros,
                   d.moneda_factura,d.tc_factura,d.moneda_dr,d.equivalencia_dr,
                   f.serie,f.folio,f.fecha_emision,f.total_xml,f.moneda,f.tc_xml_factura,f.estatus_sat,
                   COALESCE(d.monto_conciliado_contpaq,0) AS monto_conciliado_contpaq,
                   COALESCE(d.saldo_por_conciliar,0) AS saldo_por_conciliar,
                   COALESCE(d.estatus_conciliacion_contpaq,'PENDIENTE') AS estatus_conciliacion_contpaq
              FROM facturas_pagos_detalles d
              INNER JOIN facturas f
                      ON f.id_empresa=d.id_empresa
                     AND f.uuid=d.uuid_relacionado
              LEFT JOIN facturas fp
                     ON fp.id_empresa=d.id_empresa
                    AND fp.uuid=d.uuid_pago
             WHERE d.id_empresa=?
               AND d.id_emisor=?
               AND d.fecha_aplicacion_fiscal>=?
               AND d.fecha_aplicacion_fiscal<?
               AND COALESCE(d.aplicado,1)=1
               AND COALESCE(f.excluir_diot,0)=0
               -- Un complemento marcado como NO_INCLUIR tampoco debe alimentar la auditoría/DIOT.
               AND (COALESCE(d.es_sintetico,0)=1 OR COALESCE(fp.excluir_diot,0)=0)
               AND (COALESCE(d.es_sintetico,0)=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
               AND UPPER(COALESCE(f.estatus_sat,'Vigente'))='VIGENTE'
               AND UPPER(COALESCE(f.tipo_movimiento_empresa,''))='E'
               AND UPPER(COALESCE(f.id_tipo_comprobante,'I'))='I'
             ORDER BY d.fecha_aplicacion_fiscal,d.uuid_relacionado,d.parcialidad,d.id_detalle";
    $st=$pdo->prepare($sqlSat);
    $st->execute([$idEmpresa,(int)$e['id_emisor'],$inicio,$fin]);
    $sat=$st->fetchAll(PDO::FETCH_ASSOC);

    // CONTPAQ: misma lógica de la ventana, por empresa + RFC + periodo fiscal SAT.
    $sqlC="SELECT c.id_conciliacion,c.id_detalle,c.tipo_origen,c.fecha_pago_sat,c.fecha_pago_contpaq,
                  c.importe_sat,c.importe_contpaq,c.importe_conciliado,c.diferencia_importe,c.diferencia_dias,
                  c.moneda_contpaq,c.tc_contpaq,c.uuid_factura,c.uuid_pago_sat,c.uuid_rep_contpaq,
                  c.origen_conciliacion,c.estatus,c.fecha_conciliacion,c.observaciones,c.rfc_emisor,
                  COALESCE(ch.tipo_documento,eg.tipo_documento) AS tipo_documento,
                  COALESCE(ch.folio,eg.folio) AS folio_contpaq,
                  COALESCE(ch.fecha,eg.fecha,c.fecha_pago_contpaq) AS fecha_movimiento,
                  COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador) AS beneficiario,
                  COALESCE(ch.persona_rfc,eg.persona_rfc,c.rfc_emisor) AS rfc_beneficiario,
                  COALESCE(ch.referencia,eg.referencia) AS referencia_contpaq,
                  COALESCE(ch.concepto,eg.concepto) AS concepto_contpaq,
                  COALESCE(ch.num_pol,eg.num_pol) AS num_pol,
                  COALESCE(ch.total,eg.total,c.importe_contpaq) AS total_movimiento,
                  CASE WHEN c.tipo_origen='C' THEN COALESCE(ch.es_cancelado,0) ELSE COALESCE(eg.es_cancelado,0) END AS es_cancelado
             FROM conciliacion_pagos_detalle c
             LEFT JOIN contpaq_cheques ch
                    ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
             LEFT JOIN contpaq_egresos eg
                    ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
            WHERE c.id_empresa=?
              AND UPPER(TRIM(c.rfc_emisor))=UPPER(TRIM(?))
              AND c.fecha_pago_sat>=?
              AND c.fecha_pago_sat<?
            ORDER BY c.fecha_pago_contpaq,c.id_conciliacion";
    $st=$pdo->prepare($sqlC);
    $st->execute([$idEmpresa,$rfc,$inicio,$fin]);
    $cp=$st->fetchAll(PDO::FETCH_ASSOC);

    // Mismos indicadores de la ventana de auditoría.
    $tot=[
        'importe_aplicado'=>0,'base_iva_16'=>0,'iva_16'=>0,'base_iva_8'=>0,'iva_8'=>0,
        'base_tasa_0'=>0,'base_exento'=>0,'base_no_objeto'=>0,'iva_retenido'=>0,'isr_retenido'=>0,
        'ieps_otros'=>0,'conciliado_contpaq'=>0
    ];
    $facturas=[]; $reps=[];
    foreach($sat as &$d){
        $monedaOrigen=$d['moneda_dr'] ?: ($d['moneda_factura'] ?: ($d['moneda'] ?: 'MXN'));
        $tcOrigen=(float)($d['tc_factura'] ?: ($d['tc_xml_factura'] ?: 1));
        $factorMxn=diot_factor_mxn($monedaOrigen,$tcOrigen);
        $d['moneda_origen']=strtoupper(trim((string)$monedaOrigen));
        $d['tc_origen']=$tcOrigen;
        foreach(['importe_aplicado','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros'] as $k){
            $d[$k]=(float)($d[$k]??0)*$factorMxn;
            $tot[$k]+=$d[$k];
        }
        $facturas[strtoupper((string)$d['uuid_relacionado'])]=1;
        if(!empty($d['uuid_pago'])) $reps[strtoupper((string)$d['uuid_pago'])]=1;
    } unset($d);
    foreach($cp as $c){
        if(!(int)($c['es_cancelado']??0)) $tot['conciliado_contpaq']+=(float)($c['importe_conciliado']??0);
    }
    $diferencia=round($tot['importe_aplicado']-$tot['conciliado_contpaq'],4);

    $periodo=sprintf('%02d/%04d',(int)$m['mes'],(int)$m['anio']);
    $nombre=(string)($e['nombre']??'');

    // Un bloque por emisor, diseñado para quedar como la misma auditoría de pantalla.
    $html='';
    if($idx>0) $html.='<div style="page-break-before:always"></div>';
    $html.='<div class="titulo">Auditoría DIOT por emisor</div>';
    $html.='<div class="emisor"><b>'.ad_h($nombre).'</b> &nbsp; - &nbsp; RFC '.ad_h($rfc).' &nbsp; - &nbsp; Periodo '.ad_h($periodo).'</div>';

    $html.='<table class="resumen">';
    $html.='<tr><th>Facturas</th><th>Complementos SAT</th><th>Detalles SAT</th><th>Pago SAT</th><th>CONTPAQ conciliado</th><th>Diferencia</th></tr>';
    $html.='<tr><td class="num">'.count($facturas).'</td><td class="num">'.count($reps).'</td><td class="num">'.count($sat).'</td><td class="num">$'.ad_n($tot['importe_aplicado']).'</td><td class="num">$'.ad_n($tot['conciliado_contpaq']).'</td><td class="num">$'.ad_n($diferencia).'</td></tr>';
    $html.='<tr><th>Base 16 / IVA 16</th><th>Base 8 / IVA 8</th><th>Tasa 0</th><th>Exento</th><th>No objeto</th><th>Retenciones</th></tr>';
    $html.='<tr><td class="num">$'.ad_n($tot['base_iva_16']).' / $'.ad_n($tot['iva_16']).'</td><td class="num">$'.ad_n($tot['base_iva_8']).' / $'.ad_n($tot['iva_8']).'</td><td class="num">$'.ad_n($tot['base_tasa_0']).'</td><td class="num">$'.ad_n($tot['base_exento']).'</td><td class="num">$'.ad_n($tot['base_no_objeto']).'</td><td class="num">$'.ad_n($tot['iva_retenido']).' IVA / $'.ad_n($tot['isr_retenido']).' ISR</td></tr>';
    $html.='</table>';

    $html.='<div class="sub">Complementos y detalles de pago SAT</div>';
    $html.='<table><tr><th>Fecha fiscal</th><th>Fecha SAT</th><th>Factura</th><th>UUID factura</th><th>UUID complemento</th><th>Parc.</th><th>Moneda origen</th><th>TC origen</th><th>Pago MXN</th><th>Base 16</th><th>IVA 16</th><th>Base 8</th><th>IVA 8</th><th>Tasa 0</th><th>Exento</th><th>No objeto</th><th>IVA ret.</th><th>ISR ret.</th><th>IEPS</th><th>Forma</th><th>Referencia</th><th>Estado CONTPAQ</th></tr>';
    if(!$sat){
        $html.='<tr><td colspan="22">Sin detalles SAT en el periodo.</td></tr>';
    } else {
        foreach($sat as $d){
            $factura=trim((string)($d['serie']??'').' '.(string)($d['folio']??''));
            if($factura==='') $factura='S/F';
            $html.='<tr>';
            $html.='<td>'.ad_h(ad_fecha($d['fecha_aplicacion_fiscal']??'')).'</td>';
            $html.='<td>'.ad_h(ad_fecha($d['fecha_pago_sat']??'')).'</td>';
            $html.='<td>'.ad_h($factura).'</td>';
            $html.='<td class="uuid">'.ad_h($d['uuid_relacionado']??'').'</td>';
            $html.='<td class="uuid">'.ad_h($d['uuid_pago']??'').'</td>';
            $html.='<td>'.ad_h($d['parcialidad']??'').'</td>';
            $html.='<td>'.ad_h($d['moneda_origen']??'MXN').'</td>';
            $html.='<td class="num">'.ad_n($d['tc_origen']??1).'</td>';
            foreach(['importe_aplicado','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros'] as $k){
                $html.='<td class="num">$'.ad_n($d[$k]??0).'</td>';
            }
            $html.='<td>'.ad_h($d['forma_pago']??'').'</td>';
            $html.='<td>'.ad_h(($d['referencia']??'')!==''?$d['referencia']:'-').'</td>';
            $html.='<td>'.ad_h($d['estatus_conciliacion_contpaq']??'PENDIENTE').'</td>';
            $html.='</tr>';
        }
        $html.='<tr class="totales"><td colspan="8"><b>TOTALES MXN</b></td>';
        foreach(['importe_aplicado','base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento','base_no_objeto','iva_retenido','isr_retenido','ieps_otros'] as $k){
            $html.='<td class="num"><b>$'.ad_n($tot[$k]).'</b></td>';
        }
        $html.='<td colspan="3"></td></tr>';
    }
    $html.='</table>';

    $html.='<div class="sub">Aplicaciones CONTPAQ conciliadas</div>';
    $html.='<table><tr><th>Tipo</th><th>Folio</th><th>Fecha</th><th>Beneficiario</th><th>Total movimiento</th><th>Importe SAT</th><th>Aplicado</th><th>Diferencia</th><th>Dif. días</th><th>Póliza</th><th>Referencia</th><th>UUID factura</th><th>UUID REP</th><th>Estatus</th></tr>';
    if(!$cp){
        $html.='<tr><td colspan="14">Los detalles SAT existen, pero no tienen movimiento CONTPAQ conciliado.</td></tr>';
    } else {
        foreach($cp as $c){
            $estado=(int)($c['es_cancelado']??0)?'CANCELADO':(string)($c['estatus']??'');
            $html.='<tr>';
            $html.='<td>'.ad_h($c['tipo_documento']??$c['tipo_origen']??'').'</td>';
            $html.='<td>'.ad_h($c['folio_contpaq']??'').'</td>';
            $html.='<td>'.ad_h(ad_fecha($c['fecha_movimiento']??'')).'</td>';
            $html.='<td>'.ad_h($c['beneficiario']??'').'</td>';
            $html.='<td class="num">$'.ad_n($c['total_movimiento']??0).'</td>';
            $html.='<td class="num">$'.ad_n($c['importe_sat']??0).'</td>';
            $html.='<td class="num">$'.ad_n($c['importe_conciliado']??0).'</td>';
            $html.='<td class="num">$'.ad_n($c['diferencia_importe']??0).'</td>';
            $html.='<td>'.ad_h($c['diferencia_dias']??'').'</td>';
            $html.='<td>'.ad_h($c['num_pol']??'').'</td>';
            $html.='<td>'.ad_h(($c['referencia_contpaq']??'')!==''?$c['referencia_contpaq']:'-').'</td>';
            $html.='<td class="uuid">'.ad_h($c['uuid_factura']??'').'</td>';
            $html.='<td class="uuid">'.ad_h(($c['uuid_rep_contpaq']??'')!==''?$c['uuid_rep_contpaq']:'-').'</td>';
            $html.='<td>'.ad_h($estado).'</td>';
            $html.='</tr>';
        }
    }
    $html.='</table><br>';

    file_put_contents($xf,$html,FILE_APPEND|LOCK_EX);

    $m['indice']=$idx+1;
    $completo=$m['indice'] >= $total;
    if($completo){
        file_put_contents($xf,'</body></html>',FILE_APPEND|LOCK_EX);
        $m['completo']=true;
    }
    file_put_contents($mf,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);

    $procesados=(int)$m['indice'];
    $pct=$total?round(($procesados/$total)*100):100;
    echo json_encode([
        'status'=>'ok','completo'=>$completo,'procesados'=>$procesados,'total'=>$total,'porcentaje'=>$pct,
        'emisor'=>['nombre'=>$nombre,'rfc'=>$rfc]
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

} catch(Throwable $e){
    http_response_code(500);
    seguridad_log_error($e,'procesar_auditoria_diot_excel');
    echo json_encode(['status'=>'error','msg'=>'No se pudo procesar el siguiente emisor de la auditoría.'],JSON_UNESCAPED_UNICODE);
}
