<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, true);
require_once '../includes/control_diot_pagos.php';
require_once '../includes/cfdi_sustituciones.php';

try {
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    if($idEmpresa<=0) throw new RuntimeException('Sin empresa activa.');

    // No borramos historial. Releemos todos los CFDI P, hacemos UPSERT de sus detalles
    // y reconstruimos relaciones 04. Puede ejecutarse repetidamente sin duplicar.
    $st=$pdo->prepare("SELECT f.uuid,fd.xml_base64
                       FROM facturas f INNER JOIN facturas_datos fd ON fd.uuid=f.uuid
                       WHERE f.id_empresa=? AND UPPER(f.id_tipo_comprobante)='P'
                       ORDER BY f.fecha_emision,f.uuid");
    $st->execute([$idEmpresa]);

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM cfdi_relaciones WHERE id_empresa=? AND tipo_relacion='04'")->execute([$idEmpresa]);
    $pdo->prepare("UPDATE facturas_pagos_detalles SET es_sustituido=0,uuid_sustituto=NULL WHERE id_empresa=? AND es_sintetico=0")->execute([$idEmpresa]);

    $complementos=0;$detalles=0;$relaciones=0;$errores=[];$afectadas=[];$uuidsPago=[];
    while($row=$st->fetch(PDO::FETCH_ASSOC)){
        $uuidPago=cfdi_uuid_normalizar($row['uuid']);
        $uuidsPago[]=$uuidPago;
        try{
            $raw=base64_decode((string)$row['xml_base64'],true);
            if($raw===false||trim($raw)==='') throw new RuntimeException('XML vacío/Base64 inválido.');
            $clean=str_replace(['cfdi:','tfd:','pago20:','nomina12:','ecc12:'],'',$raw);
            libxml_use_internal_errors(true); $xml=simplexml_load_string($clean);
            if(!$xml) throw new RuntimeException('XML inválido.');

            $rels=cfdi_extraer_relaciones($xml);
            cfdi_guardar_relaciones($pdo,$idEmpresa,$uuidPago,$rels);
            foreach($rels as $r) if(($r['tipo_relacion']??'')==='04') $relaciones++;

            if(!isset($xml->Complemento->Pagos->Pago)){ $complementos++; continue; }
            $nPago=0;
            foreach($xml->Complemento->Pagos->Pago as $pago){
                $nPago++;$nDoc=0;
                foreach($pago->DoctoRelacionado as $docto){
                    $nDoc++; $uuidRel=cfdi_uuid_normalizar($docto['IdDocumento']??'');
                    if($uuidRel==='') continue;
                    $monto=(float)($docto['ImpPagado']??0);
                    $facturaRel=diot_foto_factura($pdo,$uuidRel,$idEmpresa);
                    $foto=diot_extraer_impuestos_dr($docto,$facturaRel,$monto);
                    $fecha=diot_fecha((string)($pago['FechaPago']??''));
                    diot_upsert_detalle($pdo,array_merge($foto,[
                        'id_empresa'=>$idEmpresa,'uuid_pago'=>$uuidPago,'uuid_relacionado'=>$uuidRel,
                        'monto_pagado'=>$monto,'importe_aplicado'=>$monto,
                        'parcialidad'=>isset($docto['NumParcialidad'])?(int)$docto['NumParcialidad']:null,
                        'saldo_anterior'=>(float)($docto['ImpSaldoAnt']??0),'saldo_insoluto'=>(float)($docto['ImpSaldoInsoluto']??0),
                        'clave_detalle'=>hash('sha256',$uuidPago.'|'.$nPago.'|'.$nDoc),'aplicado'=>1,
                        'origen_pago'=>'COMPLEMENTO_SAT','es_sintetico'=>0,
                        'moneda_factura'=>$facturaRel['moneda']??(string)($docto['MonedaDR']??''),'tc_factura'=>$facturaRel['tc_xml_factura']??1,
                        'moneda_pago_sat'=>(string)($pago['MonedaP']??''),'tc_pago_sat'=>(float)($pago['TipoCambioP']??1),
                        'moneda_dr'=>(string)($docto['MonedaDR']??''),'equivalencia_dr'=>(float)($docto['EquivalenciaDR']??1),
                        'fecha_pago_sat'=>$fecha,'fecha_pago_banco'=>null,'fecha_pago_contpaq'=>null,'moneda_contpaq'=>null,'tc_contpaq'=>null,
                        'monto_conciliado_contpaq'=>0,'saldo_por_conciliar'=>$monto,'estatus_conciliacion_contpaq'=>'PENDIENTE',
                        'fecha_pago_usuario'=>null,'fecha_aplicacion_fiscal'=>$fecha,'forma_pago'=>(string)($pago['FormaDePagoP']??''),
                        'referencia'=>(string)($pago['NumOperacion']??'')
                    ]));
                    $afectadas[$uuidRel]=1;$detalles++;
                }
            }
            $complementos++;
        }catch(Throwable $e){
            seguridad_log_error($e,'reprocesar_pago:'.$uuidPago);$errores[]=$uuidPago;
        }
    }

    // Una sola pasada global determina quién fue sustituido. Incluye cadenas A->B->C.
    foreach(cfdi_sincronizar_sustituciones_pago($pdo,$idEmpresa,null) as $u) $afectadas[$u]=1;
    foreach(array_keys($afectadas) as $uuidFactura) diot_recalcular_factura($pdo,$uuidFactura,$idEmpresa);
    $pdo->commit();

    $st=$pdo->prepare("SELECT COUNT(*) FROM facturas_pagos_detalles WHERE id_empresa=? AND es_sintetico=0 AND es_sustituido=1");
    $st->execute([$idEmpresa]);$sustituidos=(int)$st->fetchColumn();
    echo json_encode([
        'status'=>empty($errores)?'ok':'warning',
        'msg'=>"Reproceso terminado: {$complementos} complementos, {$detalles} detalles, {$relaciones} relaciones 04, {$sustituidos} detalles sustituidos, ".count($afectadas)." facturas recalculadas.",
        'complementos'=>$complementos,'detalles'=>$detalles,'relaciones_04'=>$relaciones,'detalles_sustituidos'=>$sustituidos,
        'facturas_recalculadas'=>count($afectadas),'errores'=>count($errores)
    ],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    seguridad_log_error($e,'reconstruir_detalles_pago');http_response_code(500);
    echo json_encode(['status'=>'error','msg'=>'No se pudo reprocesar los complementos de pago.'],JSON_UNESCAPED_UNICODE);
}
