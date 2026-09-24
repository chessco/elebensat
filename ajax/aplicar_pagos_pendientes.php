<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/control_diot_pagos.php';

try {
    exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'procesar_pagos');
    if (empty($_SESSION['id_empresa'])) throw new Exception('Sin empresa activa.');
    $idEmpresa=(int)$_SESSION['id_empresa'];

    $stCfg=$pdo->prepare("SELECT criterio_fecha_pue FROM empresas WHERE id_empresa=?");
    $stCfg->execute([$idEmpresa]);
    $criterio=strtoupper((string)$stCfg->fetchColumn());
    if (!in_array($criterio,['EMISION','PAGO'],true)) $criterio='EMISION';

    $pdo->beginTransaction();

    // 1) Completar foto fiscal y fechas de todos los detalles de complementos SAT.
    $sql="SELECT d.id_detalle,d.uuid_pago,d.uuid_relacionado,d.monto_pagado,
                 d.fecha_pago_usuario,d.fecha_pago_banco,d.fecha_pago_sat AS fecha_pago_sat_detalle,
                 fr.fecha_pago_usuario AS fecha_pago_usuario_factura,
                 fp.fecha_pago_sat AS fecha_pago_sat_padre,fp.fecha_emision AS fecha_emision_pago,
                 fp.forma_pago AS forma_pago_pago
          FROM facturas_pagos_detalles d
          INNER JOIN facturas fp ON UPPER(fp.uuid)=UPPER(d.uuid_pago) AND fp.id_empresa=d.id_empresa
          LEFT JOIN facturas fr ON UPPER(fr.uuid)=UPPER(d.uuid_relacionado) AND fr.id_empresa=d.id_empresa
          WHERE fp.id_empresa=? AND UPPER(fp.id_tipo_comprobante)='P'";
    $st=$pdo->prepare($sql); $st->execute([$idEmpresa]);
    $detalles=$st->fetchAll(PDO::FETCH_ASSOC);

    $upd=$pdo->prepare("UPDATE facturas_pagos_detalles SET
        id_empresa=?,id_emisor=?,rfc_emisor=?,importe_aplicado=?,origen_pago='COMPLEMENTO_SAT',es_sintetico=0,
        fecha_pago_sat=?,fecha_pago_usuario=?,fecha_aplicacion_fiscal=?,forma_pago=?,
        proporcion_aplicada=?,base_iva_16=?,iva_16=?,base_iva_8=?,iva_8=?,
        base_tasa_0=?,base_exento=?,base_no_objeto=?,iva_retenido=?,isr_retenido=?,ieps_otros=?
        WHERE id_detalle=?");

    $procesados=0;
    $afectadas=[];
    foreach($detalles as $d){
        $f=diot_foto_factura($pdo,$d['uuid_relacionado'],$idEmpresa);
        if(!$f) continue;
        $foto=diot_prorratear_factura($f,(float)$d['monto_pagado']);
        // La fecha fiscal SAT debe ser Pago.FechaPago ya guardada en el detalle.
        // Solo para históricos incompletos se usa el encabezado del REP como respaldo.
        $fechaSat=diot_fecha($d['fecha_pago_sat_detalle'])
            ?: diot_fecha($d['fecha_pago_sat_padre'])
            ?: diot_fecha($d['fecha_emision_pago']);
        // La Fecha Efectiva de Pago de la factura capturada por el usuario tiene
        // prioridad incluso cuando ya exista un REP real. La fecha SAT se conserva
        // por separado para auditoría y para detectar diferencias.
        $fechaUsuario=diot_fecha($d['fecha_pago_usuario_factura']) ?: diot_fecha($d['fecha_pago_usuario']);
        $fechaFiscal=$fechaUsuario ?: diot_fecha($d['fecha_pago_banco']) ?: $fechaSat;
        $upd->execute([
            $idEmpresa,$f['id_emisor'] ?? null,$f['rfc_emisor'] ?? null,(float)$d['monto_pagado'],$fechaSat,$fechaUsuario,$fechaFiscal,$d['forma_pago_pago'],
            $foto['proporcion_aplicada'],$foto['base_iva_16'],$foto['iva_16'],$foto['base_iva_8'],$foto['iva_8'],
            $foto['base_tasa_0'],$foto['base_exento'],$foto['base_no_objeto'],$foto['iva_retenido'],
            $foto['isr_retenido'],$foto['ieps_otros'],$d['id_detalle']
        ]);
        $procesados++;
        $afectadas[strtoupper((string)$d['uuid_relacionado'])]=1;
    }

    // Reaplicar explícitamente la prioridad de la fecha manual después de procesar REP.
    foreach(array_keys($afectadas) as $uuidFactura){
        diot_sincronizar_pago_usuario_factura($pdo,$uuidFactura,$idEmpresa);
    }

    // 2) PUE: si empresa usa EMISION, crear detalle sintético al 100%.
    // Si usa PAGO, solo se crea cuando exista fecha manual/banco/SAT.
    $stPue=$pdo->prepare("SELECT * FROM facturas
                         WHERE id_empresa=? AND UPPER(COALESCE(metodo_pago,''))='PUE'
                           AND UPPER(COALESCE(id_tipo_comprobante,''))='I'
                           AND estatus_sat='Vigente' AND excluir_diot=0");
    $stPue->execute([$idEmpresa]);
    $pues=$stPue->fetchAll(PDO::FETCH_ASSOC);
    $pueCreadas=0;

    foreach($pues as $f){
        $fechaUsuario=diot_fecha($f['fecha_pago_usuario'] ?? null);
        $fechaBanco=diot_fecha($f['fecha_pago_banco'] ?? null);
        $fechaSat=diot_fecha($f['fecha_pago_sat'] ?? null);
        $fechaEmision=diot_fecha($f['fecha_emision'] ?? null);

        if($fechaUsuario){
            diot_crear_detalle_pue($pdo,$f,$fechaUsuario,'USUARIO');
            $fechaFiscal=$fechaUsuario;
            $pueCreadas++;
        } elseif($criterio==='EMISION'){
            diot_crear_detalle_pue($pdo,$f,$fechaEmision,'PUE_EMISION');
            $fechaFiscal=$fechaEmision;
            $pueCreadas++;
        } elseif($fechaBanco || $fechaSat){
            $fechaFiscal=$fechaBanco ?: $fechaSat;
            $origen=$fechaBanco ? 'BANCO' : 'COMPLEMENTO_SAT';
            diot_crear_detalle_pue($pdo,$f,$fechaFiscal,$origen);
            $pueCreadas++;
        } else {
            $fechaFiscal=null;
            $pdo->prepare("DELETE FROM facturas_pagos_detalles
                           WHERE id_empresa=? AND UPPER(uuid_relacionado)=UPPER(?) AND es_sintetico=1")
                ->execute([$idEmpresa,$f['uuid']]);
        }

        $pdo->prepare("UPDATE facturas SET fecha_aplicacion_fiscal=? WHERE uuid=? AND id_empresa=?")
            ->execute([$fechaFiscal,$f['uuid'],$idEmpresa]);
    }

    // 3) Recalcular acumulados de factura desde detalles reales/sintéticos.
    $sqlAgg="SELECT UPPER(uuid_relacionado) uuid_relacionado,
                   SUM(COALESCE(importe_aplicado,monto_pagado,0)) total_pagado,
                   MAX(fecha_aplicacion_fiscal) ultima_fecha,
                   MAX(CASE WHEN es_sintetico=0 THEN fecha_pago_sat ELSE NULL END) ultima_fecha_pago_sat,
                   COUNT(*) numero_abonos
            FROM facturas_pagos_detalles
            WHERE id_empresa=?
            GROUP BY UPPER(uuid_relacionado)";
    $stAgg=$pdo->prepare($sqlAgg); $stAgg->execute([$idEmpresa]);
    $grupos=$stAgg->fetchAll(PDO::FETCH_ASSOC);

    $updF=$pdo->prepare("UPDATE facturas SET
        total_abonos=?,
        saldo_pendiente=GREATEST(COALESCE(total_xml,0)-?,0),
        ya_pago=CASE WHEN (COALESCE(total_xml,0)-?)<=0.01 THEN 1 ELSE 0 END,
        fecha_pago_sat=?,
        fecha_aplicacion_fiscal=COALESCE(fecha_pago_usuario,?)
        WHERE UPPER(uuid)=? AND id_empresa=?");
    $actualizadas=0;
    foreach($grupos as $g){
        $pagado=round((float)$g['total_pagado'],4);
        $updF->execute([$pagado,$pagado,$pagado,$g['ultima_fecha_pago_sat'],$g['ultima_fecha'],$g['uuid_relacionado'],$idEmpresa]);
        $actualizadas += $updF->rowCount() ? 1 : 0;
    }

    // Las facturas con al menos un detalle SAT quedan marcadas.
    $pdo->prepare("UPDATE facturas f SET tiene_complemento_pagos=1
                   WHERE f.id_empresa=? AND EXISTS(
                      SELECT 1 FROM facturas_pagos_detalles d
                      WHERE d.id_empresa=f.id_empresa AND UPPER(d.uuid_relacionado)=UPPER(f.uuid)
                        AND d.es_sintetico=0
                   )")->execute([$idEmpresa]);

    // Asegurar que todos los detalles históricos de la empresa conserven la referencia directa al emisor.
    // Esto evita recorrer facturas + catálogo de emisores cada vez que DIOT agrupa por proveedor.
    $pdo->prepare("UPDATE facturas_pagos_detalles d
                   INNER JOIN facturas f ON f.id_empresa=d.id_empresa AND f.uuid=d.uuid_relacionado
                   INNER JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor
                   SET d.id_emisor=f.id_emisor, d.rfc_emisor=e.rfc
                   WHERE d.id_empresa=?
                     AND (d.id_emisor IS NULL OR d.id_emisor<>f.id_emisor
                          OR d.rfc_emisor IS NULL OR d.rfc_emisor<>e.rfc)")->execute([$idEmpresa]);

    $pdo->prepare("UPDATE facturas_pagos_detalles SET aplicado=1 WHERE id_empresa=?")->execute([$idEmpresa]);

    $pdo->commit();

    echo json_encode([
        'status'=>'ok',
        'msg'=>"Control DIOT actualizado: {$procesados} detalles SAT recalculados, {$pueCreadas} PUE aplicadas y {$actualizadas} facturas acumuladas.",
        'criterio_pue'=>$criterio,
        'detalles_sat'=>$procesados,
        'pue_aplicadas'=>$pueCreadas,
        'facturas_actualizadas'=>$actualizadas
    ],JSON_UNESCAPED_UNICODE);

} catch(Throwable $e){
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    seguridad_log_error($e, 'aplicar_pagos_pendientes'); echo json_encode(['status'=>'error','msg'=>'No se pudieron aplicar los pagos pendientes.'],JSON_UNESCAPED_UNICODE);
}
