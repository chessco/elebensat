<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';

try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'exportar');
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    $empresaNombre=(string)($_SESSION['empresa_nombre']??'');
    $mes=(int)($_GET['mes']??date('m'));
    $anio=(int)($_GET['anio']??date('Y'));
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) throw new RuntimeException('Periodo inválido.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $inicio=sprintf('%04d-%02d-01',$anio,$mes);
    $dt=new DateTime($inicio); $dt->modify('first day of next month'); $fin=$dt->format('Y-m-d');

    // Mismos bloques de conciliación que el Resumen DIOT.
    $pagosAnteriores=diot_pagos_facturas_anteriores($pdo,$idEmpresa,$anio,$mes);
    $cfdiPendientes=diot_cfdi_mes_no_pagados($pdo,$idEmpresa,$anio,$mes);
    $pagosPosteriores=diot_cfdi_mes_pagados_posterior($pdo,$idEmpresa,$anio,$mes);
    $pagosUsuarioSinRep=diot_cfdi_mes_pago_usuario_sin_rep($pdo,$idEmpresa,$anio,$mes);
    $facturasIvaEspecial=diot_facturas_tratamiento_iva_especial($pdo,$idEmpresa,$anio,$mes);
    $pagosMonedaExtranjera=diot_pagos_moneda_extranjera($pdo,$idEmpresa,$anio,$mes);

    // Detalle completo de facturas/egresos EMITIDOS en el periodo, aunque su fecha fiscal
    // esté dentro, fuera o vacía. Esto funciona como sábana de auditoría del mes.
    $sql="SELECT f.*, e.rfc rfc_emisor, e.nombre emisor, r.rfc rfc_receptor, r.nombre receptor,
                 (SELECT COUNT(*) FROM facturas_pagos_detalles pd WHERE pd.id_empresa=f.id_empresa AND pd.uuid_relacionado=f.uuid) numero_pagos
          FROM facturas f
          LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
          LEFT JOIN cat_receptores r ON r.id_receptor=f.id_receptor AND r.id_empresa=f.id_empresa
          WHERE f.id_empresa=:id
            AND f.tipo_movimiento_empresa='E'
            AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')
            AND COALESCE(f.excluir_diot,0)=0
            AND f.fecha_emision>=:ini AND f.fecha_emision<:fin
          ORDER BY f.fecha_emision,f.folio,f.uuid";
    $st=$pdo->prepare($sql); $st->execute([':id'=>$idEmpresa,':ini'=>$inicio.' 00:00:00',':fin'=>$fin.' 00:00:00']);
    $facturas=$st->fetchAll(PDO::FETCH_ASSOC);

    // CFDI marcados NO DIOT relacionados con el periodo. Se mantienen fuera de todos
    // los totales declarables y se muestran únicamente al final como control/auditoría.
    $sqlExcluidos="SELECT f.*, e.rfc rfc_emisor, e.nombre emisor
                  FROM facturas f
                  LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
                  WHERE f.id_empresa=:id
                    AND COALESCE(f.excluir_diot,0)=1
                    AND (
                         (f.fecha_aplicacion_fiscal>=:ini AND f.fecha_aplicacion_fiscal<:fin)
                         OR (f.fecha_emision>=:ini AND f.fecha_emision<:fin)
                    )
                  ORDER BY COALESCE(f.fecha_aplicacion_fiscal,f.fecha_emision),f.fecha_emision,f.folio,f.uuid";
    $stEx=$pdo->prepare($sqlExcluidos);
    $stEx->execute([':id'=>$idEmpresa,':ini'=>$inicio.' 00:00:00',':fin'=>$fin.' 00:00:00']);
    $excluidosDiot=$stEx->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment;filename="CFDI_Auditoria_DIOT_'.sprintf('%02d',$mes).'_'.$anio.'.xls"');

    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $f=static fn($v)=>$v?date('d/m/Y',strtotime($v)):'-';
    $n=static fn($v)=>number_format((float)$v,2,'.','');

    echo '<html><head><meta charset="UTF-8"><style>
      body{font-family:Calibri,Arial,sans-serif;font-size:11px} td{border:1px solid #a6a6a6;vertical-align:top;white-space:nowrap}
      .titulo{background:#1f4e78;color:#fff;font-weight:bold;font-size:15px}.subtitulo{background:#ddebf7;font-weight:bold}
      .enc{background:#176b91;color:#fff;font-weight:bold}.alerta{background:#fff2cc}.ok{background:#e2f0d9}.fuera{background:#fce4d6}
    </style></head><body>';
    echo '<table>';
    echo '<tr><td colspan="36" class="titulo">SÁBANA COMPLETA DE AUDITORÍA - DETALLE DE FACTURAS / DIOT</td></tr>';
    echo '<tr><td colspan="36"><b>Empresa:</b> '.$h($empresaNombre).'</td></tr>';
    echo '<tr><td colspan="36"><b>Periodo de emisión:</b> '.sprintf('%02d/%04d',$mes,$anio).' &nbsp; | &nbsp; <b>Facturas:</b> '.count($facturas).'</td></tr>';
    echo '<tr><td colspan="36" class="subtitulo">Primera sección: todos los egresos emitidos en el mes. Los importes monetarios se muestran en MXN; moneda y tipos de cambio originales se conservan para auditoría.</td></tr>';
    echo '<tr class="enc"><td>#</td><td>Tipo</td><td>Estatus SAT</td><td>UUID</td><td>RFC proveedor</td><td>Proveedor</td><td>Serie</td><td>Folio</td><td>Fecha emisión</td><td>Fecha fiscal</td><td>Estatus periodo DIOT</td><td>Método</td><td>Forma</td><td>Moneda</td><td>TC factura</td><td>TC real pago</td><td>Factor MXN</td><td>Total MXN</td><td>Subtotal MXN</td><td>Base IVA MXN</td><td>IVA trasladado MXN</td><td>Base IVA 8 MXN</td><td>IVA 8 MXN</td><td>Base ret. IVA MXN</td><td>IVA retenido MXN</td><td>Base ret. ISR MXN</td><td>ISR retenido MXN</td><td>Fecha pago SAT</td><td>Fecha pago banco</td><td>Fecha pago usuario</td><td>Núm. pagos</td><td>% IVA acreditable</td><td>Tratamiento IVA especial</td><td>Motivo tratamiento</td><td>Saldo pendiente MXN</td><td>Conciliado</td></tr>';

    $tot=['total'=>0,'subtotal'=>0,'base'=>0,'iva'=>0,'base8'=>0,'iva8'=>0,'retiva'=>0,'retisr'=>0,'saldo'=>0];
    $i=1; $tsIni=strtotime($inicio); $tsFin=strtotime($fin);
    foreach($facturas as $r){
        $factor=diot_factor_mxn($r['moneda']??'MXN',$r['tc_xml_factura']??1);
        $fechaFiscal=$r['fecha_aplicacion_fiscal']??null;
        if(!$fechaFiscal || str_starts_with((string)$fechaFiscal,'0000-00-00')) $estatus='SIN FECHA FISCAL';
        else { $tf=strtotime($fechaFiscal); $estatus=($tf!==false&&$tf>=$tsIni&&$tf<$tsFin)?'EN PERIODO':'FUERA DE PERIODO'; }
        $cl=$estatus==='EN PERIODO'?'ok':($estatus==='FUERA DE PERIODO'?'fuera':'alerta');
        $total=(float)($r['total_xml']??0)*$factor; $subtotal=(float)($r['subtotal_xml']??0)*$factor;
        $base=(float)($r['base_iva']??0)*$factor; $iva=(float)($r['iva_traslado']??0)*$factor;
        $base8=(float)($r['base_iva_fronteriza_xml']??0)*$factor; $iva8=(float)($r['iva_fronterizo_xml']??0)*$factor;
        $briva=(float)($r['base_ret_iva']??0)*$factor; $riva=(float)($r['iva_retenido']??0)*$factor;
        $brisr=(float)($r['base_ret_isr']??0)*$factor; $risr=(float)($r['isr_retenido']??0)*$factor; $saldo=(float)($r['saldo_pendiente']??0)*$factor;
        $tot['total']+=$total;$tot['subtotal']+=$subtotal;$tot['base']+=$base;$tot['iva']+=$iva;$tot['base8']+=$base8;$tot['iva8']+=$iva8;$tot['retiva']+=$riva;$tot['retisr']+=$risr;$tot['saldo']+=$saldo;
        echo '<tr><td>'.$i++.'</td><td>'.$h($r['tipo_movimiento_empresa']??'E').'</td><td>'.$h($r['estatus_sat']??'Vigente').'</td><td style="mso-number-format:\@">'.$h($r['uuid']??'').'</td><td>'.$h($r['rfc_emisor']??'').'</td><td>'.$h($r['emisor']??'').'</td><td>'.$h($r['serie']??'').'</td><td>'.$h($r['folio']??'').'</td><td>'.$f($r['fecha_emision']??null).'</td><td>'.$f($fechaFiscal).'</td><td class="'.$cl.'">'.$estatus.'</td><td>'.$h($r['metodo_pago']??'').'</td><td>'.$h($r['forma_pago']??'').'</td><td>'.$h($r['moneda']??'MXN').'</td><td>'.$n($r['tc_xml_factura']??1).'</td><td>'.$n($r['tc_banco_real']??0).'</td><td>'.$n($factor).'</td><td>'.$n($total).'</td><td>'.$n($subtotal).'</td><td>'.$n($base).'</td><td>'.$n($iva).'</td><td>'.$n($base8).'</td><td>'.$n($iva8).'</td><td>'.$n($briva).'</td><td>'.$n($riva).'</td><td>'.$n($brisr).'</td><td>'.$n($risr).'</td><td>'.$f($r['fecha_pago_sat']??null).'</td><td>'.$f($r['fecha_pago_banco']??null).'</td><td>'.$f($r['fecha_pago_usuario']??null).'</td><td>'.(int)($r['numero_pagos']??0).'</td><td>'.$n($r['iva_porcentaje_acreditable_diot']??100).'%</td><td>'.((int)($r['iva_tratamiento_especial_diot']??0)?'SI':'NO').'</td><td>'.$h($r['iva_motivo_tratamiento_diot']??'').'</td><td>'.$n($saldo).'</td><td>'.((int)($r['esta_conciliado']??0)?'SI':'NO').'</td></tr>';
    }
    if(!$facturas) echo '<tr><td colspan="36" style="text-align:center">No hay facturas de egreso emitidas en el periodo.</td></tr>';
    echo '<tr style="font-weight:bold;background:#d9eaf7"><td colspan="17">TOTAL FACTURAS ('.count($facturas).')</td><td>'.$n($tot['total']).'</td><td>'.$n($tot['subtotal']).'</td><td>'.$n($tot['base']).'</td><td>'.$n($tot['iva']).'</td><td>'.$n($tot['base8']).'</td><td>'.$n($tot['iva8']).'</td><td></td><td>'.$n($tot['retiva']).'</td><td></td><td>'.$n($tot['retisr']).'</td><td colspan="7"></td><td>'.$n($tot['saldo']).'</td><td></td></tr>';
    echo '</table>';

    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 1:
    // Facturas de meses anteriores que SI forman parte de la DIOT actual
    // porque su fecha de aplicacion fiscal cae dentro del periodo.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="18" style="background:#1f4e78;color:#fff;font-size:14px;font-weight:bold">'
       . 'FACTURAS DE MESES ANTERIORES PAGADAS EN EL MES DIOT'
       . '</td></tr>';
    echo '<tr><td colspan="18" style="background:#ddebf7">'
       . 'Estos CFDI fueron emitidos antes de '.htmlspecialchars(sprintf('01/%02d/%04d',$mes,$anio),ENT_QUOTES,'UTF-8')
       . ', pero su fecha fiscal de pago pertenece al periodo DIOT seleccionado. Ya están contemplados en la DIOT.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#d9eaf7">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td>'
       . '<td>UUID factura</td><td>Fecha fiscal pago</td><td>Fecha SAT</td><td>UUID pago/REP</td><td>Parc.</td>'
       . '<td>Pago MXN</td><td>Base 16 MXN</td><td>IVA 16 MXN</td><td>Base 8 MXN</td><td>IVA 8 MXN</td>'
       . '<td>IVA retenido</td><td>ISR retenido</td></tr>';

    $totAnt=['pago'=>0,'b16'=>0,'iva16'=>0,'b8'=>0,'iva8'=>0,'retiva'=>0,'retisr'=>0];
    $iAnt=1;
    foreach($pagosAnteriores as $r){
        $totAnt['pago']+=(float)$r['pago_mxn'];
        $totAnt['b16']+=(float)$r['base_iva_16_mxn'];
        $totAnt['iva16']+=(float)$r['iva_16_mxn'];
        $totAnt['b8']+=(float)$r['base_iva_8_mxn'];
        $totAnt['iva8']+=(float)$r['iva_8_mxn'];
        $totAnt['retiva']+=(float)$r['iva_retenido_mxn'];
        $totAnt['retisr']+=(float)$r['isr_retenido_mxn'];
        echo '<tr>';
        echo '<td>'.$iAnt++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_factura'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_aplicacion_fiscal']?date('d/m/Y',strtotime($r['fecha_aplicacion_fiscal'])):'-').'</td>';
        echo '<td>'.($r['fecha_pago_sat']?date('d/m/Y',strtotime($r['fecha_pago_sat'])):'-').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_pago'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.(int)$r['parcialidad'].'</td>';
        echo '<td>'.number_format((float)$r['pago_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_8_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_8_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_retenido_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['isr_retenido_mxn'],2,'.','').'</td>';
        echo '</tr>';
    }
    if (!$pagosAnteriores) {
        echo '<tr><td colspan="18" style="text-align:center">No hay facturas de meses anteriores pagadas fiscalmente en este periodo.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#bdd7ee">'
       . '<td colspan="11">TOTAL DEL GRUPO ('.count($pagosAnteriores).' detalle(s))</td>'
       . '<td>'.number_format($totAnt['pago'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['b16'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['iva16'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['b8'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['iva8'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['retiva'],2,'.','').'</td>'
       . '<td>'.number_format($totAnt['retisr'],2,'.','').'</td></tr>';
    echo '</table>';

    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 2:
    // CFDI emitidos en el mes DIOT que aun tienen saldo pendiente.
    // Funcionan como lista de pagos/cheques en transito para el siguiente mes.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="14" style="background:#7f6000;color:#fff;font-size:14px;font-weight:bold">'
       . 'CFDI DEL MES DIOT CON SALDO PENDIENTE DE PAGO'
       . '</td></tr>';
    echo '<tr><td colspan="14" style="background:#fff2cc">'
       . 'CFDI recibidos y vigentes emitidos en el mes solicitado que al cierre del periodo todavía no estaban totalmente pagados según los detalles fiscales cargados hasta ese mes. '
       . 'Este bloque sirve como apoyo para identificar pagos o cheques en tránsito que podrían aparecer en DIOT de meses posteriores.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#ffe699">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td><td>UUID</td>'
       . '<td>Método</td><td>Moneda</td><td>Total MXN</td><td>Pagado MXN</td><td>Saldo MXN</td><td>IVA 16 CFDI</td><td>IVA retenido CFDI</td></tr>';

    $totPen=['total'=>0,'pagado'=>0,'saldo'=>0];
    $iPen=1;
    foreach($cfdiPendientes as $r){
        $totPen['total']+=(float)$r['total_mxn'];
        $totPen['pagado']+=(float)$r['pagado_mxn'];
        $totPen['saldo']+=(float)$r['saldo_mxn'];
        $factor = (strtoupper(trim((string)$r['moneda']))==='' || in_array(strtoupper(trim((string)$r['moneda'])),['MXN','XXX'],true)) ? 1.0 : (float)$r['tipo_cambio'];
        if ($factor <= 0) $factor=1.0;
        echo '<tr>';
        echo '<td>'.$iPen++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['metodo_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['moneda']?:'MXN'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.number_format((float)$r['total_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['pagado_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['saldo_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16']*$factor,2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_retenido']*$factor,2,'.','').'</td>';
        echo '</tr>';
    }
    if (!$cfdiPendientes) {
        echo '<tr><td colspan="14" style="text-align:center">No hay CFDI del mes con saldo pendiente.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#ffd966">'
       . '<td colspan="9">TOTAL PENDIENTE ('.count($cfdiPendientes).' CFDI)</td>'
       . '<td>'.number_format($totPen['total'],2,'.','').'</td>'
       . '<td>'.number_format($totPen['pagado'],2,'.','').'</td>'
       . '<td>'.number_format($totPen['saldo'],2,'.','').'</td><td></td><td></td></tr>';
    echo '</table>';

    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 3:
    // CFDI emitidos dentro del mes DIOT que se pagaron fiscalmente despues.
    // Explica por que NO pertenecen a la DIOT del mes de emision y en que
    // fecha/periodo posterior deben aparecer.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="19" style="background:#548235;color:#fff;font-size:14px;font-weight:bold">'
       . 'FACTURAS DEL MES DIOT PAGADAS EN FECHA POSTERIOR'
       . '</td></tr>';
    echo '<tr><td colspan="19" style="background:#e2f0d9">'
       . 'Estos CFDI fueron emitidos dentro del periodo DIOT seleccionado, pero su fecha fiscal de pago es posterior al cierre del mes. '
       . 'Por lo tanto no forman parte de la DIOT del mes de emisión; este detalle indica cuándo quedaron pagados y en qué periodo posterior deben considerarse.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#c6e0b4">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td>'
       . '<td>UUID factura</td><td>Fecha fiscal pago</td><td>Periodo pago</td><td>Fecha SAT</td><td>UUID pago/REP</td><td>Parc.</td>'
       . '<td>Pago MXN</td><td>Base 16 MXN</td><td>IVA 16 MXN</td><td>Base 8 MXN</td><td>IVA 8 MXN</td>'
       . '<td>IVA retenido</td><td>ISR retenido</td></tr>';

    $totPost=['pago'=>0,'b16'=>0,'iva16'=>0,'b8'=>0,'iva8'=>0,'retiva'=>0,'retisr'=>0];
    $iPost=1;
    foreach($pagosPosteriores as $r){
        $totPost['pago']+=(float)$r['pago_mxn'];
        $totPost['b16']+=(float)$r['base_iva_16_mxn'];
        $totPost['iva16']+=(float)$r['iva_16_mxn'];
        $totPost['b8']+=(float)$r['base_iva_8_mxn'];
        $totPost['iva8']+=(float)$r['iva_8_mxn'];
        $totPost['retiva']+=(float)$r['iva_retenido_mxn'];
        $totPost['retisr']+=(float)$r['isr_retenido_mxn'];
        $periodoPago = $r['fecha_aplicacion_fiscal'] ? date('m/Y',strtotime($r['fecha_aplicacion_fiscal'])) : '-';
        echo '<tr>';
        echo '<td>'.$iPost++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_factura'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_aplicacion_fiscal']?date('d/m/Y',strtotime($r['fecha_aplicacion_fiscal'])):'-').'</td>';
        echo '<td>'.$periodoPago.'</td>';
        echo '<td>'.($r['fecha_pago_sat']?date('d/m/Y',strtotime($r['fecha_pago_sat'])):'-').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_pago'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.(int)$r['parcialidad'].'</td>';
        echo '<td>'.number_format((float)$r['pago_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_8_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_8_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_retenido_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['isr_retenido_mxn'],2,'.','').'</td>';
        echo '</tr>';
    }
    if (!$pagosPosteriores) {
        echo '<tr><td colspan="19" style="text-align:center">No hay CFDI del mes con pagos fiscales registrados en periodos posteriores.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#a9d18e">'
       . '<td colspan="12">TOTAL PAGADO POSTERIORMENTE ('.count($pagosPosteriores).' detalle(s))</td>'
       . '<td>'.number_format($totPost['pago'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['b16'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['iva16'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['b8'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['iva8'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['retiva'],2,'.','').'</td>'
       . '<td>'.number_format($totPost['retisr'],2,'.','').'</td></tr>';
    echo '</table>';

    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 4:
    // CFDI del mes DIOT con fecha de pago manual posterior al cierre,
    // pero todavía sin complemento de pago/REP fiscal relacionado.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="16" style="background:#7030a0;color:#fff;font-size:14px;font-weight:bold">'
       . 'FACTURAS PAGADAS EN MES POSTERIOR, PENDIENTES DE COMPLEMENTO DE PAGO'
       . '</td></tr>';
    echo '<tr><td colspan="16" style="background:#e4dfec">'
       . 'CFDI emitidos dentro del periodo DIOT seleccionado que ya tienen una fecha de pago registrada en un mes posterior, '
       . 'pero todavía no cuentan con un complemento de pago (REP) fiscal relacionado. No se consideran pendientes de pago; '
       . 'quedan en seguimiento hasta recibir el REP. Cuando el complemento sea recibido y procesado, pasarán automáticamente al bloque de facturas pagadas en fecha posterior.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#d9d2e9">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td>'
       . '<td>UUID factura</td><td>Método</td><td>Moneda</td><td>Total MXN</td><td>Fecha pago registrada</td><td>Periodo pago</td>'
       . '<td>Origen</td><td>Estado REP</td><td>IVA 16 CFDI</td><td>IVA retenido CFDI</td></tr>';

    $totSinRep=['total'=>0,'iva16'=>0,'retiva'=>0];
    $iSinRep=1;
    foreach($pagosUsuarioSinRep as $r){
        $totSinRep['total']+=(float)$r['total_mxn'];
        $totSinRep['iva16']+=(float)$r['iva_16_mxn'];
        $totSinRep['retiva']+=(float)$r['iva_retenido_mxn'];
        $periodoPago = $r['fecha_pago_usuario'] ? date('m/Y',strtotime($r['fecha_pago_usuario'])) : '-';
        echo '<tr>';
        echo '<td>'.$iSinRep++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_factura'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['metodo_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['moneda']?:'MXN'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.number_format((float)$r['total_mxn'],2,'.','').'</td>';
        echo '<td>'.($r['fecha_pago_usuario']?date('d/m/Y',strtotime($r['fecha_pago_usuario'])):'-').'</td>';
        echo '<td>'.$periodoPago.'</td>';
        echo '<td>PAGO USUARIO</td>';
        echo '<td>PENDIENTE DE REP</td>';
        echo '<td>'.number_format((float)$r['iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_retenido_mxn'],2,'.','').'</td>';
        echo '</tr>';
    }
    if (!$pagosUsuarioSinRep) {
        echo '<tr><td colspan="16" style="text-align:center">No hay facturas con pago posterior registrado y complemento de pago pendiente.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#b4a7d6">'
       . '<td colspan="9">TOTAL PAGO REGISTRADO SIN REP ('.count($pagosUsuarioSinRep).' CFDI)</td>'
       . '<td>'.number_format($totSinRep['total'],2,'.','').'</td>'
       . '<td colspan="4"></td>'
       . '<td>'.number_format($totSinRep['iva16'],2,'.','').'</td>'
       . '<td>'.number_format($totSinRep['retiva'],2,'.','').'</td></tr>';
    echo '</table>';


    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 5:
    // Facturas con tratamiento especial de IVA para DIOT.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="17" style="background:#c65911;color:#fff;font-size:14px;font-weight:bold">'
       . 'FACTURAS CON TRATAMIENTO ESPECIAL DE IVA EN DIOT'
       . '</td></tr>';
    echo '<tr><td colspan="17" style="background:#fce4d6">'
       . 'El CFDI conserva sus importes originales. Para estas facturas se aplicó un porcentaje de IVA acreditable; '
       . 'la parte restante del valor/base se informa como operación No objeto, de acuerdo con el tratamiento DIOT capturado por el usuario.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#f4b183">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td><td>UUID factura</td>'
       . '<td>Método</td><td>Moneda</td><td>Pago MXN</td><td>Base 16 MXN</td><td>IVA XML 16%</td><td>% acreditable</td>'
       . '<td>IVA acreditable</td><td>Base No objeto</td><td>Base 8 / IVA 8 especial</td><td>Motivo / criterio</td></tr>';
    $totEsp=['pago'=>0,'base16'=>0,'ivaXml'=>0,'ivaAcred'=>0,'baseNoObj'=>0];
    $iEsp=1;
    foreach($facturasIvaEspecial as $r){
        $totEsp['pago']+=(float)$r['pago_mxn'];
        $totEsp['base16']+=(float)$r['base_iva_16_mxn'];
        $totEsp['ivaXml']+=(float)$r['iva_16_xml_mxn'];
        $totEsp['ivaAcred']+=(float)$r['iva_16_acreditable_mxn'];
        $totEsp['baseNoObj']+=(float)($r['base_16_no_objeto_especial_mxn'] ?? 0)+(float)($r['base_8_no_objeto_especial_mxn'] ?? 0);
        echo '<tr>';
        echo '<td>'.$iEsp++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_factura'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['metodo_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['moneda']?:'MXN'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.number_format((float)$r['pago_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16_xml_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['porcentaje_acreditable'],2,'.','').'%</td>';
        echo '<td>'.number_format((float)$r['iva_16_acreditable_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)($r['base_16_no_objeto_especial_mxn'] ?? 0)+(float)($r['base_8_no_objeto_especial_mxn'] ?? 0),2,'.','').'</td>';
        echo '<td>Base: '.number_format((float)$r['base_iva_8_mxn'],2,'.','').' / IVA: '.number_format((float)$r['iva_8_xml_mxn'],2,'.','').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['motivo']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '</tr>';
    }
    if (!$facturasIvaEspecial) {
        echo '<tr><td colspan="17" style="text-align:center">No hay facturas con tratamiento especial de IVA en este periodo.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#ed7d31;color:#fff">'
       . '<td colspan="9">TOTAL TRATAMIENTO ESPECIAL ('.count($facturasIvaEspecial).' CFDI)</td>'
       . '<td>'.number_format($totEsp['pago'],2,'.','').'</td>'
       . '<td>'.number_format($totEsp['base16'],2,'.','').'</td>'
       . '<td>'.number_format($totEsp['ivaXml'],2,'.','').'</td><td></td>'
       . '<td>'.number_format($totEsp['ivaAcred'],2,'.','').'</td>'
       . '<td>'.number_format($totEsp['ivaNoAcred'],2,'.','').'</td><td colspan="2"></td></tr>';
    echo '</table>';


    // -----------------------------------------------------------------
    // RESUMEN DE CONCILIACION 6:
    // Aplicaciones DIOT de facturas/documentos en moneda extranjera.
    // Muestra importes originales, tipo de cambio utilizado y conversión MXN.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="26" style="background:#1f4e78;color:#fff;font-size:14px;font-weight:bold">'
       . 'FACTURAS EN MONEDA EXTRANJERA INCLUIDAS EN LA DIOT'
       . '</td></tr>';
    echo '<tr><td colspan="26" style="background:#ddebf7">'
       . 'Aplicaciones fiscales incluidas en la DIOT cuyo CFDI/documento relacionado está expresado en moneda extranjera. '
       . 'Se muestra el importe en la moneda original y el tipo de cambio utilizado para la DIOT. Si se capturó un TC real de pago, éste tiene prioridad para la conversión; el TC fiscal original y el TC del REP se conservan como datos de conciliación.'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#9dc3e6">'
       . '<td>#</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha factura</td><td>Serie</td><td>Folio</td><td>UUID factura</td>'
       . '<td>Método</td><td>Moneda factura</td><td>Fecha fiscal DIOT</td><td>UUID pago/REP</td><td>Parc.</td><td>TC usado DIOT</td>'
       . '<td>TC fiscal original</td><td>TC real pago</td><td>Moneda pago REP</td><td>TC pago REP</td><td>Equivalencia DR</td><td>Diferencia TC real vs REP</td>'
       . '<td>Pago moneda origen</td><td>Base 16 origen</td><td>IVA 16 origen</td><td>Pago MXN DIOT</td><td>Base 16 MXN</td><td>IVA 16 MXN</td><td>Observación</td></tr>';

    $totMe=['pago_origen'=>0,'b16_origen'=>0,'iva16_origen'=>0,'pago_mxn'=>0,'b16_mxn'=>0,'iva16_mxn'=>0];
    $iMe=1;
    foreach($pagosMonedaExtranjera as $r){
        $totMe['pago_origen']+=(float)$r['pago_moneda_origen'];
        $totMe['b16_origen']+=(float)$r['base_iva_16_origen'];
        $totMe['iva16_origen']+=(float)$r['iva_16_origen'];
        $totMe['pago_mxn']+=(float)$r['pago_mxn'];
        $totMe['b16_mxn']+=(float)$r['base_iva_16_mxn'];
        $totMe['iva16_mxn']+=(float)$r['iva_16_mxn'];
        $moneda=htmlspecialchars((string)$r['moneda_origen'],ENT_QUOTES,'UTF-8');
        $tieneRep = trim((string)($r['uuid_pago'] ?? '')) !== '';
        $monedaPagoRaw = strtoupper(trim((string)($r['moneda_pago_sat'] ?? '')));
        $tcPago = (float)($r['tc_pago_sat'] ?? 0);
        $tcDiot = (float)($r['tipo_cambio_aplicado'] ?? 0);
        $tcFiscalOriginal = (float)($r['tipo_cambio_fiscal_original'] ?? 0);
        $tcReal = (float)($r['tc_banco_real'] ?? 0);
        $usaTcReal = (int)($r['usa_tc_real'] ?? 0) === 1;
        $mismaMonedaRep = $tieneRep && $monedaPagoRaw !== '' && strtoupper(trim((string)$r['moneda_origen'])) === $monedaPagoRaw;
        $difTc = ($usaTcReal && $mismaMonedaRep && $tcPago > 0 && $tcReal > 0) ? ($tcReal - $tcPago) : null;
        echo '<tr>';
        echo '<td>'.$iMe++.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['rfc'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)$r['nombre'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.($r['fecha_emision']?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid_factura'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['metodo_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.$moneda.'</td>';
        echo '<td>'.($r['fecha_aplicacion_fiscal']?date('d/m/Y',strtotime($r['fecha_aplicacion_fiscal'])):'-').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)($r['uuid_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.(int)$r['parcialidad'].'</td>';
        echo '<td>'.number_format($tcDiot,6,'.','').($usaTcReal ? ' (REAL)' : '').'</td>';
        echo '<td>'.number_format($tcFiscalOriginal,6,'.','').'</td>';
        echo '<td>'.($usaTcReal ? number_format($tcReal,6,'.','') : '-').'</td>';
        echo '<td>'.($tieneRep ? htmlspecialchars(($monedaPagoRaw !== '' ? $monedaPagoRaw : '-'),ENT_QUOTES,'UTF-8') : 'SIN REP').'</td>';
        echo '<td>'.($tieneRep && $tcPago>0 ? number_format($tcPago,6,'.','') : 'SIN REP').'</td>';
        echo '<td>'.($tieneRep ? number_format((float)($r['equivalencia_dr'] ?? 1),6,'.','') : '-').'</td>';
        echo '<td>'.($difTc !== null ? number_format($difTc,6,'.','') : '-').'</td>';
        echo '<td>'.number_format((float)$r['pago_moneda_origen'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_16_origen'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16_origen'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['pago_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['base_iva_16_mxn'],2,'.','').'</td>';
        echo '<td>'.number_format((float)$r['iva_16_mxn'],2,'.','').'</td>';
        if ($usaTcReal) {
            $obs = 'INCLUIDA EN DIOT - SE USO TC REAL DE PAGO';
            if ($tieneRep && $difTc !== null && abs($difTc) > 0.000001) {
                $obs .= ' - TC REAL DIFIERE DEL REP';
            }
        } elseif ($tieneRep) {
            $obs = 'INCLUIDA EN DIOT - REP LOCALIZADO - SE USO TC FISCAL ORIGINAL';
        } else {
            $obs = 'INCLUIDA EN DIOT - SIN TC REAL / SIN REP';
        }
        echo '<td>'.htmlspecialchars($obs,ENT_QUOTES,'UTF-8').'</td>';
        echo '</tr>';
    }
    if (!$pagosMonedaExtranjera) {
        echo '<tr><td colspan="26" style="text-align:center">No hay aplicaciones en moneda extranjera incluidas en la DIOT de este periodo.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#5b9bd5;color:#fff">'
       . '<td colspan="19">TOTAL MONEDA EXTRANJERA ('.count($pagosMonedaExtranjera).' detalle(s))</td>'
       . '<td>'.number_format($totMe['pago_origen'],2,'.','').'</td>'
       . '<td>'.number_format($totMe['b16_origen'],2,'.','').'</td>'
       . '<td>'.number_format($totMe['iva16_origen'],2,'.','').'</td>'
       . '<td>'.number_format($totMe['pago_mxn'],2,'.','').'</td>'
       . '<td>'.number_format($totMe['b16_mxn'],2,'.','').'</td>'
       . '<td>'.number_format($totMe['iva16_mxn'],2,'.','').'</td><td></td></tr>';
    echo '</table>';

    // -----------------------------------------------------------------
    // CONTROL FINAL: CFDI marcados expresamente como NO DIOT.
    // No participan en ningún total anterior; esta tabla es sólo evidencia.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="12" style="background:#bf9000;color:#fff;font-size:14px;font-weight:bold">CFDI EXCLUIDOS DE LA DIOT - CONTROL Y AUDITORÍA</td></tr>';
    echo '<tr><td colspan="12" style="background:#fff2cc">Estos CFDI fueron marcados por el usuario como NO DIOT. Se muestran únicamente para control y NO forman parte de los importes ni totales declarables del reporte.</td></tr>';
    echo '<tr style="font-weight:bold;background:#ffe699"><td>#</td><td>Tipo CFDI</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha emisión</td><td>Fecha fiscal</td><td>Serie</td><td>Folio</td><td>UUID</td><td>Método</td><td>Total</td><td>Estatus SAT</td></tr>';
    $iEx=1; $cntFactura=0; $cntPago=0; $cntNota=0; $cntOtro=0;
    foreach($excluidosDiot as $r){
        $tipoEx=strtoupper(trim((string)($r['id_tipo_comprobante']??'')));
        if($tipoEx==='P'){ $etiqueta='COMPLEMENTO PAGO'; $cntPago++; }
        elseif($tipoEx==='E'){ $etiqueta='NOTA / EGRESO'; $cntNota++; }
        elseif(in_array($tipoEx,['I',''],true)){ $etiqueta='FACTURA'; $cntFactura++; }
        else { $etiqueta=$tipoEx?:'OTRO'; $cntOtro++; }
        echo '<tr style="background:#fff8e1">';
        echo '<td>'.$iEx++.'</td><td>'.$h($etiqueta).'</td><td>'.$h($r['rfc_emisor']??'').'</td><td>'.$h($r['emisor']??'').'</td>';
        echo '<td>'.$f($r['fecha_emision']??null).'</td><td>'.$f($r['fecha_aplicacion_fiscal']??null).'</td><td>'.$h($r['serie']??'').'</td><td>'.$h($r['folio']??'').'</td>';
        echo '<td style="mso-number-format:\\@">'.$h($r['uuid']??'').'</td><td>'.$h($r['metodo_pago']??'').'</td><td>'.$n($r['total_xml']??0).'</td><td>'.$h($r['estatus_sat']??'').'</td></tr>';
    }
    if(!$excluidosDiot) echo '<tr><td colspan="12" style="text-align:center">No hay CFDI marcados como NO DIOT relacionados con este periodo.</td></tr>';
    echo '<tr style="font-weight:bold;background:#ffd966"><td colspan="12">TOTAL EXCLUIDOS: '.count($excluidosDiot).' | Facturas: '.$cntFactura.' | Complementos de pago: '.$cntPago.' | Notas/Egresos: '.$cntNota.' | Otros: '.$cntOtro.'</td></tr>';
    echo '</table>';

    echo '</body></html>';
} catch(Throwable $e){
    http_response_code(500);
    seguridad_log_error($e, 'exportar_facturas_auditoria_diot_excel'); echo 'No se pudo generar la sábana de auditoría de facturas.';
}
