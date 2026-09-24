<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';
$columnasDiot = require '../includes/diot_columnas.php';

try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot');
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    $empresaNombre=(string)($_SESSION['empresa_nombre']??'');
    $mes=(int)($_GET['mes']??date('m'));
    $anio=(int)($_GET['anio']??date('Y'));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    diot_sincronizar_pagos_usuario_empresa($pdo,$idEmpresa);
    $data=diot_resumen_desde_detalles($pdo,$idEmpresa,$anio,$mes);
    $pagosAnteriores=diot_pagos_facturas_anteriores($pdo,$idEmpresa,$anio,$mes);
    $cfdiPendientes=diot_cfdi_mes_no_pagados($pdo,$idEmpresa,$anio,$mes);
    $pagosPosteriores=diot_cfdi_mes_pagados_posterior($pdo,$idEmpresa,$anio,$mes);
    $pagosUsuarioSinRep=diot_cfdi_mes_pago_usuario_sin_rep($pdo,$idEmpresa,$anio,$mes);
    $facturasIvaEspecial=diot_facturas_tratamiento_iva_especial($pdo,$idEmpresa,$anio,$mes);
    $pagosMonedaExtranjera=diot_pagos_moneda_extranjera($pdo,$idEmpresa,$anio,$mes);

    // CFDI marcados manualmente como NO INCLUIR que pertenecen al periodo por
    // fecha de emisión o porque intervienen en una aplicación fiscal del mes.
    // Se reportan únicamente como control/auditoría: NO afectan los totales DIOT.
    $inicioExcluidos = sprintf('%04d-%02d-01', $anio, $mes);
    $finExcluidos = date('Y-m-d', strtotime($inicioExcluidos . ' +1 month'));
    $sqlExcluidos = "SELECT
            fx.id_tipo_comprobante AS tipo_cfdi,
            fx.tipo_movimiento_empresa AS tipo_movimiento,
            fx.uuid,
            fx.fecha_emision,
            fx.serie,
            fx.folio,
            fx.metodo_pago,
            fx.moneda,
            fx.total_xml,
            fx.estatus_sat,
            e.rfc AS rfc_emisor,
            e.nombre AS proveedor,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM facturas_pagos_detalles dx
                    WHERE dx.id_empresa=fx.id_empresa
                      AND (dx.uuid_relacionado=fx.uuid OR dx.uuid_pago=fx.uuid)
                      AND dx.fecha_aplicacion_fiscal>=:inicio_rel
                      AND dx.fecha_aplicacion_fiscal<:fin_rel
                      AND COALESCE(dx.es_sustituido,0)=0
                ) THEN 'EXCLUIDO - INTERVIENE EN APLICACION FISCAL DEL PERIODO'
                ELSE 'EXCLUIDO MANUALMENTE - CFDI DEL PERIODO'
            END AS motivo_exclusion
        FROM facturas fx
        LEFT JOIN cat_emisores e
               ON e.id_empresa=fx.id_empresa
              AND e.id_emisor=fx.id_emisor
        WHERE fx.id_empresa=:id_empresa
          AND COALESCE(fx.excluir_diot,0)=1
          AND (
                (fx.fecha_emision>=:inicio_emision AND fx.fecha_emision<:fin_emision)
                OR EXISTS (
                    SELECT 1 FROM facturas_pagos_detalles d2
                    WHERE d2.id_empresa=fx.id_empresa
                      AND (d2.uuid_relacionado=fx.uuid OR d2.uuid_pago=fx.uuid)
                      AND d2.fecha_aplicacion_fiscal>=:inicio_det
                      AND d2.fecha_aplicacion_fiscal<:fin_det
                      AND COALESCE(d2.es_sustituido,0)=0
                )
          )
        ORDER BY fx.fecha_emision ASC, e.rfc ASC, fx.folio ASC";
    $stExcluidos = $pdo->prepare($sqlExcluidos);
    $stExcluidos->execute([
        ':id_empresa'=>$idEmpresa,
        ':inicio_rel'=>$inicioExcluidos,
        ':fin_rel'=>$finExcluidos,
        ':inicio_emision'=>$inicioExcluidos,
        ':fin_emision'=>$finExcluidos,
        ':inicio_det'=>$inicioExcluidos,
        ':fin_det'=>$finExcluidos,
    ]);
    $cfdiExcluidos = $stExcluidos->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment;filename="DIOT_Resumen_'.sprintf('%02d',$mes).'_'.$anio.'.xls"');

    echo '<html><head><meta charset="UTF-8"><style>
        .datos-tercero{background:#6e975e;color:#fff;font-weight:bold}.valor-actos{background:#acb8bf;color:#000;font-weight:bold}
        .encabezado-detalle{background:#acb8bf;font-size:10px;word-wrap:break-word}.encabezado-detalle-alt{background:#6e975e;font-size:10px;color:#fff}
        td{border:1px solid #000;vertical-align:top}
    </style></head><body><table>';
    echo '<tr><td colspan="6"><b>Empresa:</b> '.htmlspecialchars($empresaNombre,ENT_QUOTES,'UTF-8').'</td></tr>';
    echo '<tr><td colspan="6"><b>Periodo DIOT:</b> '.sprintf('%02d/%04d',$mes,$anio).' - Fuente: detalle fiscal de pagos</td></tr><tr></tr>';

    echo '<tr><td></td>';
    for($i=1;$i<=7;$i++) {
        echo '<td class="datos-tercero">'.($i===1?'DATOS DEL TERCERO':'').'</td>';
        if($i===3) echo '<td class="datos-tercero">NOMBRE EMISOR</td>';
    }
    for($i=8;$i<=17;$i++) echo '<td class="valor-actos">'.($i===8?'VALOR DE ACTOS O ACTIVIDADES':'').'</td>';
    for($i=18;$i<=27;$i++) echo '<td class="datos-tercero">'.($i===18?'IVA ACREDITABLE':'').'</td>';
    for($i=28;$i<=47;$i++) echo '<td class="valor-actos">'.($i===28?'IVA NO ACREDITABLE':'').'</td>';
    for($i=48;$i<=54;$i++) echo '<td class="datos-tercero">'.($i===48?'DATOS ADICIONALES':'').'</td>';
    echo '</tr>';

    echo '<tr style="height:180px"><td>#</td>';
    for($i=1;$i<=54;$i++){
        $clase=($i<=7||($i>=18&&$i<=27)||$i>=48)?'encabezado-detalle':'encabezado-detalle-alt';
        if($i>=28&&$i<=47) $clase='encabezado-detalle-alt';
        $corto=$columnasDiot[$i]['corto']??"Columna $i";
        $desc=$columnasDiot[$i]['descripcion']??"Columna $i";
        echo '<td class="'.$clase.'" width="140"><b>Col. '.$i.' - '.htmlspecialchars($corto,ENT_QUOTES,'UTF-8').'</b><br><br>'.nl2br(htmlspecialchars($desc,ENT_QUOTES,'UTF-8')).'</td>';
        if($i===3) {
            echo '<td class="encabezado-detalle" width="260"><b>Nombre emisor</b><br><br>Columna auxiliar para revisión. No forma parte del TXT DIOT.</td>';
        }
    }
    echo '</tr><tr style="background:#e2efda;text-align:center"><td>#</td>';
    for($i=1;$i<=54;$i++) { echo '<td>'.$i.'</td>'; if($i===3) echo '<td>Nombre</td>'; }
    echo '</tr>';

    $n=1;
    foreach($data as $row){
        $c=diot_fila_54($row);
        echo '<tr><td>'.$n++.'</td>';
        for($i=1;$i<=54;$i++) {
            echo '<td>'.$c[$i].'</td>';
            if($i===3) echo '<td>'.htmlspecialchars((string)($row['nombre'] ?? ''),ENT_QUOTES,'UTF-8').'</td>';
        }
        echo '</tr>';
    }
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
    // RESUMEN FINAL DE CONTROL:
    // CFDI excluidos manualmente de la DIOT. Se muestran para que el
    // contador identifique qué facturas, notas o complementos NO sumaron.
    // -----------------------------------------------------------------
    echo '<br><table>';
    echo '<tr><td colspan="12" style="background:#9c0006;color:#fff;font-size:14px;font-weight:bold">'
       . 'CFDI EXCLUIDOS DE LA DIOT - CONTROL Y AUDITORIA'
       . '</td></tr>';
    echo '<tr><td colspan="12" style="background:#ffc7ce;color:#9c0006">'
       . 'Los siguientes CFDI están marcados como NO INCLUIR. Se conservan en este resumen únicamente para control y trazabilidad; '
       . '<b>NO forman parte de los importes, totales ni del TXT de la DIOT.</b>'
       . '</td></tr>';
    echo '<tr style="font-weight:bold;background:#f4cccc">'
       . '<td>#</td><td>Tipo</td><td>RFC proveedor</td><td>Proveedor</td><td>Fecha CFDI</td><td>Serie</td><td>Folio</td>'
       . '<td>UUID</td><td>Método</td><td>Total CFDI</td><td>Estatus SAT</td><td>Motivo / control</td></tr>';

    $conteoExcluidos=['FACTURA'=>0,'NOTA'=>0,'PAGO'=>0,'OTRO'=>0];
    $iExc=1;
    foreach($cfdiExcluidos as $r){
        $tipoRaw=strtoupper(trim((string)($r['tipo_cfdi']??'')));
        if($tipoRaw==='P') { $tipoTexto='COMPLEMENTO DE PAGO'; $conteoExcluidos['PAGO']++; }
        elseif($tipoRaw==='E') { $tipoTexto='NOTA DE CREDITO / EGRESO'; $conteoExcluidos['NOTA']++; }
        elseif($tipoRaw==='I') { $tipoTexto='FACTURA / INGRESO'; $conteoExcluidos['FACTURA']++; }
        else { $tipoTexto=$tipoRaw!=='' ? $tipoRaw : 'OTRO'; $conteoExcluidos['OTRO']++; }
        echo '<tr>';
        echo '<td>'.$iExc++.'</td>';
        echo '<td><b>'.htmlspecialchars($tipoTexto,ENT_QUOTES,'UTF-8').'</b></td>';
        echo '<td>'.htmlspecialchars((string)($r['rfc_emisor']??'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['proveedor']??'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.(!empty($r['fecha_emision'])?date('d/m/Y',strtotime($r['fecha_emision'])):'-').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['serie']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['folio']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td style="mso-number-format:\@">'.htmlspecialchars((string)$r['uuid'],ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['metodo_pago']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.number_format((float)($r['total_xml']??0),2,'.','').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['estatus_sat']?:'-'),ENT_QUOTES,'UTF-8').'</td>';
        echo '<td>'.htmlspecialchars((string)($r['motivo_exclusion']??'NO INCLUIR EN DIOT'),ENT_QUOTES,'UTF-8').'</td>';
        echo '</tr>';
    }
    if(!$cfdiExcluidos){
        echo '<tr><td colspan="12" style="text-align:center">No hay CFDI excluidos manualmente relacionados con este periodo DIOT.</td></tr>';
    }
    echo '<tr style="font-weight:bold;background:#ea9999">'
       . '<td colspan="12">TOTAL EXCLUIDOS: '.count($cfdiExcluidos)
       . ' | Facturas: '.$conteoExcluidos['FACTURA']
       . ' | Notas: '.$conteoExcluidos['NOTA']
       . ' | Complementos de pago: '.$conteoExcluidos['PAGO']
       . ' | Otros: '.$conteoExcluidos['OTRO'].'</td></tr>';
    echo '</table>';

    echo '</body></html>';
} catch(Throwable $e){
    http_response_code(500);
    seguridad_log_error($e, 'exportar_diot_excel'); echo 'No se pudo generar el archivo Excel DIOT.';
}
