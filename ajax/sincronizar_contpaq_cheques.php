<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/csrf.php';
require_once '../includes/contpaq_sqlserver.php';

$u = seguridad_exigir_sesion($pdo, true);
$idUsuario = (int)$u['id_usuario'];
$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
header('Content-Type: application/json; charset=utf-8');

function v5_ci(array $r, string $campo, $default=null) {
    foreach ($r as $k=>$v) if (strcasecmp((string)$k,$campo)===0) return $v;
    return $default;
}
function v5_json(array $r): string {
    $j=json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    return $j===false ? '{}' : $j;
}
function v5_join_guid(array $colsOrigen, string $aOrigen, array $colsDp, string $aDp): string {
    if (!isset($colsOrigen['guid'],$colsDp['guidref'])) throw new RuntimeException('No se encontró Guid/GuidRef para relacionar DispersionesPagos.');
    $g1=str_replace(']',']]', $colsOrigen['guid']); $g2=str_replace(']',']]', $colsDp['guidref']);
    // Misma lógica del programa Harbour que ya funciona: comparar ambos GUID como texto bajo el mismo COLLATE.
    return "CONVERT(nvarchar(100),{$aOrigen}.[{$g1}]) COLLATE SQL_Latin1_General_CP1_CI_AS = CONVERT(nvarchar(100),{$aDp}.[{$g2}]) COLLATE SQL_Latin1_General_CP1_CI_AS";
}
function v5_persona_join(array $colsOrigen, array $colsPer, string $alias='O'): string {
    if (!isset($colsOrigen['codigopersona'],$colsPer['codigo'])) return '';
    $a=str_replace(']',']]', $colsOrigen['codigopersona']); $b=str_replace(']',']]', $colsPer['codigo']);
    return " LEFT JOIN Personas PER ON CONVERT(nvarchar(200),PER.[{$b}]) COLLATE SQL_Latin1_General_CP1_CI_AS = CONVERT(nvarchar(200),{$alias}.[{$a}]) COLLATE SQL_Latin1_General_CP1_CI_AS ";
}
function v5_schema(PDO $pdo): void {
    foreach (['contpaq_egresos','contpaq_cheques','contpaq_dispersiones_pagos'] as $t) {
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"); $st->execute([$t]);
        if (!(int)$st->fetchColumn()) throw new RuntimeException('Falta preparar la estructura CONTPAQi v5. Ejecute sql/migracion_contpaq_detalle_v5.sql una sola vez.');
    }
    $st=$pdo->query("SHOW COLUMNS FROM contpaq_dispersiones_pagos LIKE 'tipo_origen'");
    if (!$st->fetch()) throw new RuntimeException('Falta ejecutar sql/migracion_contpaq_detalle_v5.sql antes de sincronizar.');
    $st=$pdo->query("SHOW COLUMNS FROM contpaq_egresos LIKE 'es_cancelado'");
    if (!$st->fetch()) throw new RuntimeException('Falta ejecutar sql/migracion_contpaq_cancelados_v2.sql antes de sincronizar.');
}

try {
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'contpaq_cheques');
    if (!csrf_validar($_POST['csrf_token']??null)) throw new RuntimeException('Token de seguridad inválido. Recargue la pantalla.');
    v5_schema($pdo);
    $desde=(string)($_POST['fecha_desde']??''); $hasta=(string)($_POST['fecha_hasta']??'');
    $f1=contpaq_fecha_sql($desde,false); $f2=contpaq_fecha_sql($hasta,true);
    if ($desde>$hasta) throw new RuntimeException('La fecha inicial no puede ser mayor a la final.');
    $d1=new DateTimeImmutable($desde); $d2=new DateTimeImmutable($hasta);
    if ($d1->format('Y')!==$d2->format('Y')) throw new RuntimeException('El rango debe pertenecer al mismo año.');

    $cfg=contpaq_config_empresa($pdo,$idEmpresa); $cn=contpaq_sqlserver_conectar($cfg);
    $cCh=contpaq_sqlserver_columnas($cn,'Cheques'); $cEg=contpaq_sqlserver_columnas($cn,'Egresos');
    $cDp=contpaq_sqlserver_columnas($cn,'DispersionesPagos'); $cPer=contpaq_sqlserver_columnas($cn,'Personas');
    foreach ([['Cheques',$cCh],['Egresos',$cEg],['DispersionesPagos',$cDp]] as [$n,$c]) if(!$c) throw new RuntimeException("No se encontró la tabla {$n} en CONTPAQi.");

    $fechaCh=contpaq_sqlserver_columna_requerida($cCh,'Cheques','Fecha'); $idCh=contpaq_sqlserver_columna_requerida($cCh,'Cheques','Id');
    $fechaEg=contpaq_sqlserver_columna_requerida($cEg,'Egresos','Fecha'); $idEg=contpaq_sqlserver_columna_requerida($cEg,'Egresos','Id');
    $idDp=contpaq_sqlserver_columna_requerida($cDp,'DispersionesPagos','Id');
    $rfcSel=isset($cPer['rfc']) ? 'PER.['.str_replace(']',']]', $cPer['rfc']).'] AS [__PersonaRFC]' : 'NULL AS [__PersonaRFC]';

    $upCh=$pdo->prepare("INSERT INTO contpaq_cheques (id_empresa,id_contpaq_cheque,row_version,id_documento_de,tipo_documento,folio,fecha,ejercicio,periodo,fecha_aplicacion,ejercicio_ap,periodo_ap,codigo_persona,persona_rfc,beneficiario_pagador,id_cuenta_cheques,nat_bancaria,naturaleza,codigo_moneda,codigo_moneda_tipo_cambio,tipo_cambio,total,referencia,concepto,es_cancelado,num_pol,id_poliza,es_anticipo,guid,datos_origen_json,fecha_primera_sync,fecha_ultima_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE row_version=VALUES(row_version),tipo_documento=VALUES(tipo_documento),folio=VALUES(folio),fecha=VALUES(fecha),codigo_persona=VALUES(codigo_persona),persona_rfc=VALUES(persona_rfc),beneficiario_pagador=VALUES(beneficiario_pagador),tipo_cambio=VALUES(tipo_cambio),total=VALUES(total),referencia=VALUES(referencia),concepto=VALUES(concepto),es_cancelado=VALUES(es_cancelado),num_pol=VALUES(num_pol),id_poliza=VALUES(id_poliza),es_anticipo=VALUES(es_anticipo),guid=VALUES(guid),datos_origen_json=VALUES(datos_origen_json),fecha_ultima_sync=NOW()");
    $upEg=$pdo->prepare("INSERT INTO contpaq_egresos (id_empresa,id_contpaq_egreso,row_version,tipo_documento,folio,fecha,codigo_persona,persona_rfc,beneficiario_pagador,id_cuenta_cheques,codigo_moneda,codigo_moneda_tipo_cambio,tipo_cambio,total,referencia,concepto,es_cancelado,num_pol,id_poliza,es_anticipo,guid,datos_origen_json,fecha_primera_sync,fecha_ultima_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE row_version=VALUES(row_version),tipo_documento=VALUES(tipo_documento),folio=VALUES(folio),fecha=VALUES(fecha),codigo_persona=VALUES(codigo_persona),persona_rfc=VALUES(persona_rfc),beneficiario_pagador=VALUES(beneficiario_pagador),tipo_cambio=VALUES(tipo_cambio),total=VALUES(total),referencia=VALUES(referencia),concepto=VALUES(concepto),es_cancelado=VALUES(es_cancelado),num_pol=VALUES(num_pol),id_poliza=VALUES(id_poliza),es_anticipo=VALUES(es_anticipo),guid=VALUES(guid),datos_origen_json=VALUES(datos_origen_json),fecha_ultima_sync=NOW()");
    $upDp=$pdo->prepare("INSERT INTO contpaq_dispersiones_pagos (id_empresa,tipo_origen,id_contpaq_dispersion,id_contpaq_cheque,id_contpaq_egreso,row_version,uuid,uuid_rep,guid_ref,num_nodo_pago,fecha_pago,total_pago,tipo_cambio,total_pago_comprobante,origen_folio,origen_fecha,origen_tipo_documento,codigo_persona,persona_rfc,beneficiario_pagador,concepto,total_origen,datos_origen_json,fecha_primera_sync,fecha_ultima_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE tipo_origen=VALUES(tipo_origen),id_contpaq_cheque=VALUES(id_contpaq_cheque),id_contpaq_egreso=VALUES(id_contpaq_egreso),row_version=VALUES(row_version),uuid=VALUES(uuid),uuid_rep=VALUES(uuid_rep),guid_ref=VALUES(guid_ref),num_nodo_pago=VALUES(num_nodo_pago),fecha_pago=VALUES(fecha_pago),total_pago=VALUES(total_pago),tipo_cambio=VALUES(tipo_cambio),total_pago_comprobante=VALUES(total_pago_comprobante),origen_folio=VALUES(origen_folio),origen_fecha=VALUES(origen_fecha),origen_tipo_documento=VALUES(origen_tipo_documento),codigo_persona=VALUES(codigo_persona),persona_rfc=VALUES(persona_rfc),beneficiario_pagador=VALUES(beneficiario_pagador),concepto=VALUES(concepto),total_origen=VALUES(total_origen),datos_origen_json=VALUES(datos_origen_json),fecha_ultima_sync=NOW()");

    $nCh=$nEg=$nDp=0; $pdo->beginTransaction();
    foreach ([['C','Cheques','CH',$cCh,$fechaCh,$idCh,$upCh],['T','Egresos','EG',$cEg,$fechaEg,$idEg,$upEg]] as [$tipo,$tabla,$a,$cols,$fecha,$id,$up]) {
        $pj=v5_persona_join($cols,$cPer,$a);
        $sql="SELECT {$a}.*, {$rfcSel} FROM {$tabla} {$a} {$pj} WHERE {$a}.[".str_replace(']',']]', $fecha)."] BETWEEN ? AND ? ORDER BY {$a}.[".str_replace(']',']]', $id)."]";
        $q=contpaq_sqlserver_query($cn,$sql,[$f1,$f2]);
        while(($r=contpaq_sqlserver_fetch($q))!==null){
            if($tipo==='C'){
                $up->execute([$idEmpresa,(int)v5_ci($r,'Id'),v5_ci($r,'RowVersion'),v5_ci($r,'IdDocumentoDe'),v5_ci($r,'TipoDocumento'),v5_ci($r,'Folio'),v5_ci($r,'Fecha'),v5_ci($r,'Ejercicio'),v5_ci($r,'Periodo'),v5_ci($r,'FechaAplicacion'),v5_ci($r,'EjercicioAp'),v5_ci($r,'PeriodoAp'),v5_ci($r,'CodigoPersona'),v5_ci($r,'__PersonaRFC'),v5_ci($r,'BeneficiarioPagador'),v5_ci($r,'IdCuentaCheques'),v5_ci($r,'NatBancaria'),v5_ci($r,'Naturaleza'),v5_ci($r,'CodigoMoneda'),v5_ci($r,'CodigoMonedaTipoCambio'),v5_ci($r,'TipoCambio'),v5_ci($r,'Total'),v5_ci($r,'Referencia'),v5_ci($r,'Concepto'),!empty(v5_ci($r,'EsCancelado'))?1:0,v5_ci($r,'NumPol'),v5_ci($r,'IdPoliza'),!empty(v5_ci($r,'EsAnticipo'))?1:0,v5_ci($r,'Guid'),v5_json($r)]); $nCh++;
            } else {
                $up->execute([$idEmpresa,(int)v5_ci($r,'Id'),v5_ci($r,'RowVersion'),v5_ci($r,'TipoDocumento'),v5_ci($r,'Folio'),v5_ci($r,'Fecha'),v5_ci($r,'CodigoPersona'),v5_ci($r,'__PersonaRFC'),v5_ci($r,'BeneficiarioPagador'),v5_ci($r,'IdCuentaCheques'),v5_ci($r,'CodigoMoneda'),v5_ci($r,'CodigoMonedaTipoCambio'),v5_ci($r,'TipoCambio'),v5_ci($r,'Total'),v5_ci($r,'Referencia'),v5_ci($r,'Concepto'),!empty(v5_ci($r,'EsCancelado'))?1:0,v5_ci($r,'NumPol'),v5_ci($r,'IdPoliza'),!empty(v5_ci($r,'EsAnticipo'))?1:0,v5_ci($r,'Guid'),v5_json($r)]); $nEg++;
            }
        }
        contpaq_sqlserver_liberar($q);

        $join=v5_join_guid($cols,$a,$cDp,'DP'); $pj=v5_persona_join($cols,$cPer,$a);
        $sql="SELECT DP.*, {$a}.[".str_replace(']',']]', $id)."] AS [__OrigenId], ".contpaq_sqlserver_campo_compatible($cols,$a,'Folio').", ".contpaq_sqlserver_campo_compatible($cols,$a,'Fecha').", ".contpaq_sqlserver_campo_compatible($cols,$a,'TipoDocumento').", ".contpaq_sqlserver_campo_compatible($cols,$a,'CodigoPersona').", ".contpaq_sqlserver_campo_compatible($cols,$a,'BeneficiarioPagador').", ".contpaq_sqlserver_campo_compatible($cols,$a,'Concepto').", ".contpaq_sqlserver_campo_compatible($cols,$a,'Total').", {$rfcSel} FROM DispersionesPagos DP INNER JOIN {$tabla} {$a} ON {$join} {$pj} WHERE {$a}.[".str_replace(']',']]', $fecha)."] BETWEEN ? AND ? ORDER BY DP.[".str_replace(']',']]', $idDp)."]";
        $q=contpaq_sqlserver_query($cn,$sql,[$f1,$f2]);
        while(($r=contpaq_sqlserver_fetch($q))!==null){
            $oid=(int)v5_ci($r,'__OrigenId');
            $upDp->execute([$idEmpresa,$tipo,(int)v5_ci($r,'Id'),$tipo==='C'?$oid:null,$tipo==='T'?$oid:null,v5_ci($r,'RowVersion'),v5_ci($r,'UUID'),v5_ci($r,'UUIDRep'),v5_ci($r,'GuidRef'),v5_ci($r,'NumNodoPago'),v5_ci($r,'FechaPago'),v5_ci($r,'TotalPago'),v5_ci($r,'TipoCambio'),v5_ci($r,'TotalPagoComprobante'),v5_ci($r,'Folio'),v5_ci($r,'Fecha'),v5_ci($r,'TipoDocumento'),v5_ci($r,'CodigoPersona'),v5_ci($r,'__PersonaRFC'),v5_ci($r,'BeneficiarioPagador'),v5_ci($r,'Concepto'),v5_ci($r,'Total'),v5_json($r)]); $nDp++;
        }
        contpaq_sqlserver_liberar($q);
    }
    $pdo->commit(); contpaq_sqlserver_cerrar($cn);
    $pdo->prepare('UPDATE empresa_contpaq_config SET ultima_sincronizacion=NOW(), ultimo_error=NULL WHERE id_empresa=?')->execute([$idEmpresa]);
    echo json_encode(['success'=>true,'cheques'=>$nCh,'egresos'=>$nEg,'dispersiones'=>$nDp,'message'=>"{$nCh} cheques, {$nEg} egresos y {$nDp} aplicaciones/dispersiones."],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    try{ if($idEmpresa>0)$pdo->prepare('UPDATE empresa_contpaq_config SET ultimo_error=? WHERE id_empresa=?')->execute([mb_substr($e->getMessage(),0,900),$idEmpresa]); }catch(Throwable $x){}
    seguridad_log_error($e,'sincronizar_contpaq_cheques'); http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
