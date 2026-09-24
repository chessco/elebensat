<?php
/**
 * Conciliación financiera genérica.
 * Primera fuente implementada: ORACLE.
 *
 * Relaciona:
 *   XML/CFDI -> detalle fiscal de pago (PUE sintético o PPD/REP)
 *   -> documento/pago Oracle -> conciliación bancaria Oracle.
 *
 * No reemplaza la conciliación CONTPAQ histórica; agrega una capa genérica
 * para que el visor pueda presentar "origen de pago" sin importar el sistema.
 */

require_once __DIR__ . '/control_diot_pagos.php';

/* V3 RAPIDA: cruces UUID por igualdad directa para usar indices compuestos.
   Evitamos UPPER/TRIM sobre columnas indexadas, que provocaban recorridos completos. */

function cf_normalizar_uuid($v): string {
    return strtoupper(trim((string)$v));
}

function cf_fecha($v): ?string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
    return substr($v, 0, 10);
}

function cf_dias(?string $a, ?string $b): ?int {
    if (!$a || !$b) return null;
    try {
        $d1 = new DateTimeImmutable(substr($a,0,10));
        $d2 = new DateTimeImmutable(substr($b,0,10));
        return (int)$d1->diff($d2)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

function cf_exp_number($reportId): string {
    $v = preg_replace('/\D+/', '', trim((string)$reportId));
    if ($v === '') return '';
    return 'EXP' . substr(str_pad($v, 15, '0', STR_PAD_LEFT), -12);
}

function cf_schema(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;

    $tables = ['conciliacion_financiera','oracle_facturas','oracle_gastos','oracle_factura_pagos','facturas','facturas_pagos_detalles','oracle_conciliacion_uuid_manual'];
    $marks = implode(',', array_fill(0,count($tables),'?'));
    $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
    $st->execute($tables);
    $found = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($tables as $t) {
        if (!isset($found[$t])) throw new RuntimeException('Falta ejecutar sql/20260816_conciliacion_financiera_oracle_v1.sql. Falta tabla '.$t.'.');
    }

    $required = [
        'facturas.origen_pago_financiero',
        'facturas.estatus_conciliacion_financiera',
        'facturas_pagos_detalles.origen_financiero',
        'facturas_pagos_detalles.estatus_conciliacion_origen',
        'oracle_facturas.estatus_conciliacion',
        'oracle_gastos.estatus_conciliacion'
    ];
    $st = $pdo->query("SELECT CONCAT(table_name,'.',column_name) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('facturas','facturas_pagos_detalles','oracle_facturas','oracle_gastos')");
    $cols = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($required as $c) {
        if (!isset($cols[$c])) throw new RuntimeException('La migración de conciliación financiera está incompleta. Falta '.$c.'.');
    }
    $ok = true;
}


function cf_uuid_manual(PDO $pdo,int $idEmpresa,string $tipo,int $idDocumento): ?array {
    $st=$pdo->prepare("SELECT * FROM oracle_conciliacion_uuid_manual WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=? AND activo=1 LIMIT 1");
    $st->execute([$idEmpresa,strtoupper($tipo),$idDocumento]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

function cf_uuid_efectivo(PDO $pdo,int $idEmpresa,string $tipo,int $idDocumento,$uuidOrigen=''): array {
    $m=cf_uuid_manual($pdo,$idEmpresa,$tipo,$idDocumento);
    if($m && trim((string)$m['uuid'])!=='') return ['uuid'=>cf_normalizar_uuid($m['uuid']),'manual'=>true,'liga'=>$m];
    return ['uuid'=>cf_normalizar_uuid($uuidOrigen),'manual'=>false,'liga'=>null];
}

function cf_clasificacion_manual(PDO $pdo,int $idEmpresa,string $tipo,int $idDocumento): ?array {
    $st=$pdo->prepare("SELECT * FROM oracle_conciliacion_clasificaciones WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=? AND origen_clasificacion='MANUAL' LIMIT 1");
    $st->execute([$idEmpresa,strtoupper($tipo),$idDocumento]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

function cf_textos_documento_sin_uuid(PDO $pdo,int $idEmpresa,string $tipo,array $doc): array {
    $tipo=strtoupper($tipo);
    if($tipo==='FACTURA'){
        $conceptos=[];
        foreach(['description','payment_reference','payment_method','paid_status'] as $k) if(!empty($doc[$k])) $conceptos[]=(string)$doc[$k];
        if(!empty($doc['oracle_invoice_id'])){
            $st=$pdo->prepare("SELECT payment_description,payment_reference,payment_number,payee FROM oracle_factura_pagos WHERE id_empresa=? AND oracle_invoice_id=?");
            $st->execute([$idEmpresa,$doc['oracle_invoice_id']]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $x) foreach($x as $v) if(trim((string)$v)!=='') $conceptos[]=(string)$v;
        }
        return [
            'CONCEPTO'=>implode(' | ',$conceptos),
            'DOCUMENTO'=>trim((string)($doc['invoice_number']??'')),
            'PROVEEDOR'=>trim((string)($doc['supplier']??'')),
            'TODOS'=>implode(' | ',array_filter([implode(' | ',$conceptos),$doc['invoice_number']??'', $doc['supplier']??'', $doc['supplier_tax_registration_number']??'']))
        ];
    }
    $concepto=implode(' | ',array_filter([$doc['description']??'', $doc['expense_type']??'', $doc['expense_reference']??'', $doc['reference_number']??'']));
    return [
        'CONCEPTO'=>$concepto,
        'DOCUMENTO'=>trim((string)($doc['reference_number']??$doc['expense_reference']??$doc['oracle_expense_id']??'')),
        'PROVEEDOR'=>trim((string)($doc['merchant_name']??$doc['person_name']??'')),
        'TODOS'=>implode(' | ',array_filter([$concepto,$doc['reference_number']??'', $doc['expense_reference']??'', $doc['merchant_name']??'', $doc['person_name']??'']))
    ];
}

function cf_buscar_regla_sin_uuid(PDO $pdo,int $idEmpresa,string $tipo,array $doc): ?array {
    // Las reglas cambian poco durante una conciliación. Cachearlas por empresa evita
    // repetir la misma consulta para cada documento sin UUID dentro del lote.
    static $cache=[];
    $ck=(string)$idEmpresa;
    if(!array_key_exists($ck,$cache)){
        $st=$pdo->prepare("SELECT * FROM oracle_conciliacion_reglas WHERE activo=1 AND (id_empresa=? OR id_empresa IS NULL) ORDER BY CASE WHEN id_empresa=? THEN 0 ELSE 1 END, prioridad ASC, id ASC");
        $st->execute([$idEmpresa,$idEmpresa]);
        $cache[$ck]=$st->fetchAll(PDO::FETCH_ASSOC);
    }
    $textos=cf_textos_documento_sin_uuid($pdo,$idEmpresa,$tipo,$doc);
    foreach($cache[$ck] as $r){
        $campo=strtoupper(trim((string)$r['campo_busqueda'])); if(!isset($textos[$campo]))$campo='TODOS';
        $hay=mb_strtoupper((string)$textos[$campo],'UTF-8');
        $pat=mb_strtoupper(trim((string)$r['patron']),'UTF-8'); if($pat==='')continue;
        $modo=strtoupper(trim((string)$r['tipo_coincidencia']));
        $ok=$modo==='EXACTO' ? trim($hay)===$pat : ($modo==='INICIA' ? str_starts_with(trim($hay),$pat) : mb_strpos($hay,$pat,0,'UTF-8')!==false);
        if($ok)return $r;
    }
    return null;
}

function cf_aplicar_clasificacion_sin_uuid(PDO $pdo,int $idEmpresa,string $tipo,array $doc,int $idUsuario=0): ?string {
    $idDoc=(int)($doc['id']??0); if(!$idDoc)return null;
    $manual=cf_clasificacion_manual($pdo,$idEmpresa,$tipo,$idDoc);
    if($manual)return strtoupper((string)$manual['estatus_asignado']);
    $regla=cf_buscar_regla_sin_uuid($pdo,$idEmpresa,$tipo,$doc);
    if(!$regla){
        $pdo->prepare("DELETE FROM oracle_conciliacion_clasificaciones WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=? AND origen_clasificacion='PALABRA_CLAVE'")->execute([$idEmpresa,strtoupper($tipo),$idDoc]);
        return null;
    }
    $estado=strtoupper((string)$regla['estatus_asignado']);
    $sql="INSERT INTO oracle_conciliacion_clasificaciones (id_empresa,tipo_documento,id_documento_origen,estatus_asignado,origen_clasificacion,observaciones,id_usuario) VALUES (?,?,?,?, 'PALABRA_CLAVE', ?,?) ON DUPLICATE KEY UPDATE estatus_asignado=VALUES(estatus_asignado),origen_clasificacion='PALABRA_CLAVE',observaciones=VALUES(observaciones),id_usuario=VALUES(id_usuario),fecha_actualizacion=NOW()";
    $pdo->prepare($sql)->execute([$idEmpresa,strtoupper($tipo),$idDoc,$estado,'Regla #'.$regla['id'].': '.$regla['patron'],$idUsuario?:null]);
    return $estado;
}

function cf_estado_general(bool $cancelado, bool $xmlOk, bool $detalleOk, bool $pagoOk, float $diferencia, bool $banco): string {
    if ($cancelado) return 'CANCELADO';
    if (!$xmlOk) return 'SIN_XML';
    if (!$detalleOk) return 'SIN_DETALLE';
    if (!$pagoOk) return 'SIN_PAGO';
    if (abs($diferencia) > 0.02) return 'DIFERENCIA';
    return $banco ? 'CONCILIADO' : 'PARCIAL';
}

function cf_estado_prioridad(string $estado): int {
    static $p = [
        'DIFERENCIA'=>100,'SIN_XML'=>90,'SIN_DETALLE'=>80,'SIN_PAGO'=>70,
        'PENDIENTE'=>60,'PARCIAL'=>50,'CONCILIADO_SIN_COMPLEMENTO'=>45,'CANCELADO'=>40,'PENSION'=>20,'DEMANDA'=>20,'NO_CONSIDERAR'=>20,'MANUAL'=>20,'CONCILIADO'=>10
    ];
    $e = strtoupper(trim($estado));
    return $p[$e] ?? 60;
}

function cf_guardar_relacion(PDO $pdo, array $d): void {
    $clave = hash('sha256', implode('|', [
        (int)$d['id_empresa'], cf_normalizar_uuid($d['uuid_factura'] ?? ''),
        (int)($d['id_detalle'] ?? 0), strtoupper((string)($d['origen_pago'] ?? '')),
        strtoupper((string)($d['tipo_documento_origen'] ?? '')),
        (string)($d['id_documento_origen'] ?? ''), (string)($d['id_pago_origen'] ?? ''),
        (string)($d['folio_pago'] ?? ''), (string)($d['fecha_pago'] ?? '')
    ]));

    $sql = "INSERT INTO conciliacion_financiera
        (clave_conciliacion,id_empresa,uuid_factura,id_detalle,origen_pago,tipo_documento_origen,
         id_documento_origen,referencia_documento,documento_padre,id_pago_origen,folio_pago,fecha_pago,
         importe_documento,importe_pago,importe_aplicado,moneda,estatus_pago,conciliado_banco,
         fecha_conciliacion_banco,fecha_valor,estatus_xml,estatus_detalle_pago,estatus_conciliacion,
         regla_cruce,diferencia_importe,diferencia_dias,origen_conciliacion,id_usuario_concilia,
         fecha_conciliacion,fecha_ultima_revision,observaciones)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)
      ON DUPLICATE KEY UPDATE
         id_detalle=VALUES(id_detalle),referencia_documento=VALUES(referencia_documento),
         documento_padre=VALUES(documento_padre),id_pago_origen=VALUES(id_pago_origen),
         folio_pago=VALUES(folio_pago),fecha_pago=VALUES(fecha_pago),importe_documento=VALUES(importe_documento),
         importe_pago=VALUES(importe_pago),importe_aplicado=VALUES(importe_aplicado),moneda=VALUES(moneda),
         estatus_pago=VALUES(estatus_pago),conciliado_banco=VALUES(conciliado_banco),
         fecha_conciliacion_banco=VALUES(fecha_conciliacion_banco),fecha_valor=VALUES(fecha_valor),
         estatus_xml=VALUES(estatus_xml),estatus_detalle_pago=VALUES(estatus_detalle_pago),
         estatus_conciliacion=VALUES(estatus_conciliacion),regla_cruce=VALUES(regla_cruce),
         diferencia_importe=VALUES(diferencia_importe),diferencia_dias=VALUES(diferencia_dias),
         origen_conciliacion=VALUES(origen_conciliacion),id_usuario_concilia=VALUES(id_usuario_concilia),
         fecha_conciliacion=NOW(),fecha_ultima_revision=NOW(),observaciones=VALUES(observaciones)";
    $pdo->prepare($sql)->execute([
        $clave,(int)$d['id_empresa'],cf_normalizar_uuid($d['uuid_factura'] ?? ''),$d['id_detalle'] ?? null,
        strtoupper((string)($d['origen_pago'] ?? 'ORACLE')),strtoupper((string)($d['tipo_documento_origen'] ?? 'FACTURA')),
        $d['id_documento_origen'] ?? null,$d['referencia_documento'] ?? null,$d['documento_padre'] ?? null,
        $d['id_pago_origen'] ?? null,$d['folio_pago'] ?? null,cf_fecha($d['fecha_pago'] ?? null),
        $d['importe_documento'] ?? null,$d['importe_pago'] ?? null,$d['importe_aplicado'] ?? 0,
        $d['moneda'] ?? null,$d['estatus_pago'] ?? null,!empty($d['conciliado_banco'])?1:0,
        cf_fecha($d['fecha_conciliacion_banco'] ?? null),cf_fecha($d['fecha_valor'] ?? null),
        strtoupper((string)($d['estatus_xml'] ?? 'OK')),strtoupper((string)($d['estatus_detalle_pago'] ?? 'PENDIENTE')),
        strtoupper((string)($d['estatus_conciliacion'] ?? 'PENDIENTE')),$d['regla_cruce'] ?? null,
        (float)($d['diferencia_importe'] ?? 0),$d['diferencia_dias'] ?? null,
        strtoupper((string)($d['origen_conciliacion'] ?? 'AUTOMATICA')),$d['id_usuario_concilia'] ?? null,
        $d['observaciones'] ?? null
    ]);
}

function cf_limpiar_oracle_documento(PDO $pdo, int $idEmpresa, string $tipo, int $idDocumento): void {
    $pdo->prepare("DELETE FROM conciliacion_financiera WHERE id_empresa=? AND origen_pago='ORACLE' AND tipo_documento_origen=? AND id_documento_origen=?")
        ->execute([$idEmpresa,$tipo,$idDocumento]);
}

function cf_factura_xml(PDO $pdo, int $idEmpresa, string $uuid): ?array {
    $st=$pdo->prepare("SELECT f.*,e.rfc rfc_emisor,e.nombre emisor,emp.criterio_fecha_pue
                         FROM facturas f
                         LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
                         INNER JOIN empresas emp ON emp.id_empresa=f.id_empresa
                        WHERE f.id_empresa=? AND f.uuid=? LIMIT 1");
    $st->execute([$idEmpresa,$uuid]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function cf_detalles_fiscales(PDO $pdo, int $idEmpresa, string $uuid): array {
    // Sólo la cadena fiscal vigente: excluye complementos sustituidos/cancelados.
    // Los PUE sintéticos siempre permanecen porque no dependen de un REP externo.
    $st=$pdo->prepare("SELECT d.*
                         FROM facturas_pagos_detalles d
                         LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND fp.uuid=d.uuid_pago
                        WHERE d.id_empresa=? AND d.uuid_relacionado=?
                          AND COALESCE(d.es_sustituido,0)=0
                          AND (d.es_sintetico=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
                        ORDER BY COALESCE(d.fecha_aplicacion_fiscal,d.fecha_pago_sat),d.parcialidad,d.id_detalle");
    $st->execute([$idEmpresa,$uuid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cf_crear_pue_si_falta(PDO $pdo, array $factura, ?string $fechaPagoOrigen): array {
    $metodo = strtoupper(trim((string)($factura['metodo_pago'] ?? '')));
    if ($metodo !== 'PUE') return cf_detalles_fiscales($pdo,(int)$factura['id_empresa'],(string)$factura['uuid']);

    $det = cf_detalles_fiscales($pdo,(int)$factura['id_empresa'],(string)$factura['uuid']);
    if ($det) return $det;

    $criterio = strtoupper(trim((string)($factura['criterio_fecha_pue'] ?? 'EMISION')));
    $fecha = null;
    $origen = 'PUE_EMISION';
    if ($criterio === 'PAGO') {
        $fecha = cf_fecha($fechaPagoOrigen);
        if (!$fecha) return [];
        $origen = 'PUE_ORACLE';
    } else {
        $fecha = cf_fecha($factura['fecha_emision'] ?? null);
    }
    if (!$fecha) return [];

    diot_crear_detalle_pue($pdo,$factura,$fecha,$origen);
    diot_recalcular_factura($pdo,(string)$factura['uuid'],(int)$factura['id_empresa']);
    return cf_detalles_fiscales($pdo,(int)$factura['id_empresa'],(string)$factura['uuid']);
}

function cf_pagos_oracle(PDO $pdo, int $idEmpresa, string $oracleInvoiceId, ?array $resumen=null): array {
    // Un mismo encabezado EXP puede alimentar muchos comprobantes. Cache local del
    // request para aprovechar idx_oracle_factura_pago_invoice y no repetir lecturas.
    static $cache=[];
    $ck=$idEmpresa.'|'.$oracleInvoiceId;
    if(array_key_exists($ck,$cache)) return $cache[$ck];

    $st=$pdo->prepare("SELECT id,check_id,invoice_payment_id,payment_number,payment_reference,paper_document_number,
                             payment_amount,invoice_payment_amount,amount_paid_payment_currency,amount_paid_invoice_currency,
                             payment_currency,payment_date,payment_status,invoice_payment_status,reconciled_flag,
                             clearing_date,clearing_value_date,void_date,accounting_date
                        FROM oracle_factura_pagos
                       WHERE id_empresa=? AND oracle_invoice_id=?
                       ORDER BY payment_date,id");
    $st->execute([$idEmpresa,$oracleInvoiceId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) return $cache[$ck]=$rows;

    if ($resumen && !empty($resumen['payment_date'])) {
        return $cache[$ck]=[[
            'id'=>null,'check_id'=>$resumen['payment_check_id']??null,'invoice_payment_id'=>null,
            'payment_number'=>$resumen['payment_number']??null,'payment_reference'=>$resumen['payment_reference']??null,
            'paper_document_number'=>null,'payment_amount'=>$resumen['payment_amount']??$resumen['amount_paid']??null,
            'invoice_payment_amount'=>$resumen['payment_amount']??$resumen['amount_paid']??null,
            'amount_paid_payment_currency'=>$resumen['payment_amount']??null,'amount_paid_invoice_currency'=>$resumen['payment_amount']??null,
            'payment_currency'=>$resumen['payment_currency_detail']??$resumen['payment_currency']??$resumen['invoice_currency']??null,
            'payment_date'=>$resumen['payment_date']??null,'payment_status'=>$resumen['payment_status']??$resumen['paid_status']??null,
            'invoice_payment_status'=>null,'reconciled_flag'=>(int)($resumen['payment_reconciled_flag']??0),
            'clearing_date'=>$resumen['payment_clearing_date']??null,'clearing_value_date'=>$resumen['payment_clearing_value_date']??null,
            'void_date'=>$resumen['payment_void_date']??null,'accounting_date'=>null
        ]];
    }
    return $cache[$ck]=[];
}

function cf_pago_folio(array $p): string {
    foreach (['payment_number','payment_reference','paper_document_number','check_id'] as $k) {
        $v=trim((string)($p[$k]??''));
        if($v!=='') return $v;
    }
    return '';
}

function cf_pago_importe(array $p): float {
    foreach (['invoice_payment_amount','amount_paid_invoice_currency','amount_paid_payment_currency','payment_amount'] as $k) {
        if (isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null) return (float)$p[$k];
    }
    return 0.0;
}

function cf_mejor_pago(array $detalle, array $pagos, array $usados=[]): ?array {
    if (!$pagos) return null;
    $esperado=(float)($detalle['importe_aplicado'] ?? $detalle['monto_pagado'] ?? 0);
    $fechaDet=cf_fecha($detalle['fecha_pago_sat'] ?? $detalle['fecha_aplicacion_fiscal'] ?? null);
    $mejor=null;$score=null;
    foreach($pagos as $i=>$p){
        if(isset($usados[$i])) continue;
        $imp=cf_pago_importe($p);
        $dif=abs($esperado-$imp);
        $dias=cf_dias($fechaDet,cf_fecha($p['payment_date']??null));
        $s=($dif*1000)+(abs((int)($dias??30))*0.1);
        if($score===null||$s<$score){$score=$s;$mejor=['idx'=>$i,'pago'=>$p,'dif'=>$dif,'dias'=>$dias];}
    }
    return $mejor;
}

function cf_recalcular_detalle_generico(PDO $pdo, int $idEmpresa, int $idDetalle): void {
    $st=$pdo->prepare("SELECT d.importe_aplicado,d.monto_pagado,
                             COALESCE(SUM(c.importe_aplicado),0) total_origen,
                             MAX(c.fecha_pago) fecha_pago,MAX(c.folio_pago) folio,
                             MAX(c.moneda) moneda,MIN(c.conciliado_banco) banco,
                             MAX(c.fecha_conciliacion_banco) fecha_banco,MAX(c.fecha_valor) fecha_valor
                        FROM facturas_pagos_detalles d
                        LEFT JOIN conciliacion_financiera c ON c.id_empresa=d.id_empresa AND c.id_detalle=d.id_detalle AND c.origen_pago IN ('ORACLE','CONTPAQ')
                       WHERE d.id_empresa=? AND d.id_detalle=?
                       GROUP BY d.id_detalle,d.importe_aplicado,d.monto_pagado");
    $st->execute([$idEmpresa,$idDetalle]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r)return;
    $esperado=(float)($r['importe_aplicado']??$r['monto_pagado']??0);
    $total=(float)($r['total_origen']??0);
    $saldo=round($esperado-$total,4);

    $se=$pdo->prepare("SELECT estatus_conciliacion,origen_pago FROM conciliacion_financiera WHERE id_empresa=? AND id_detalle=? ORDER BY id");
    $se->execute([$idEmpresa,$idDetalle]);
    $rels=$se->fetchAll(PDO::FETCH_ASSOC);
    $estado='PENDIENTE';$origen=null;$max=-1;
    foreach($rels as $x){
        $pr=cf_estado_prioridad((string)$x['estatus_conciliacion']);
        if($pr>$max){$max=$pr;$estado=(string)$x['estatus_conciliacion'];}
        $origen=$origen?:($x['origen_pago']??null);
    }

    // El saldo del detalle manda sobre un "CONCILIADO" individual. Esto evita
    // marcar completo un PPD cuando Oracle sólo cubrió una parcialidad.
    $estadoCritico=in_array(strtoupper($estado),['DIFERENCIA','SIN_XML','SIN_DETALLE','SIN_PAGO','CANCELADO'],true);
    if(!$rels){
        $estado='PENDIENTE';
    }elseif($estadoCritico){
        // Conservar el motivo real aunque todavía no exista importe aplicado.
    }elseif($total<=0.0001){
        $estado='PENDIENTE';
    }elseif($total > $esperado + 0.02){
        $estado='DIFERENCIA';
    }elseif($saldo > 0.02){
        $estado='PARCIAL';
    }elseif($saldo < -0.02){
        $estado='DIFERENCIA';
    }elseif($estado==='PARCIAL' && (int)($r['banco']??0)===1){
        $estado='CONCILIADO';
    }

    $pdo->prepare("UPDATE facturas_pagos_detalles SET origen_financiero=?,folio_pago_origen=?,fecha_pago_origen=?,moneda_pago_origen=?,
                           monto_conciliado_origen=?,saldo_por_conciliar_origen=?,estatus_conciliacion_origen=?,
                           conciliado_banco_origen=?,fecha_conciliacion_banco_origen=?,fecha_valor_origen=?,fecha_ultima_conciliacion_origen=NOW()
                    WHERE id_empresa=? AND id_detalle=?")
        ->execute([$origen,$r['folio']?:null,$r['fecha_pago']?:null,$r['moneda']?:null,$total,max(0,$saldo),$estado,(int)($r['banco']??0),$r['fecha_banco']?:null,$r['fecha_valor']?:null,$idEmpresa,$idDetalle]);
}

function cf_recalcular_factura_generica(PDO $pdo, int $idEmpresa, string $uuid): void {
    $uuid=cf_normalizar_uuid($uuid);
    if($uuid==='')return;

    $st=$pdo->prepare("SELECT c.id_detalle,c.estatus_conciliacion,c.origen_pago,c.folio_pago,c.fecha_pago,c.estatus_pago,c.conciliado_banco,
                             c.fecha_conciliacion_banco,c.fecha_valor,c.id
                        FROM conciliacion_financiera c
                       WHERE c.id_empresa=? AND c.uuid_factura=?
                       ORDER BY c.fecha_pago,c.id");
    $st->execute([$idEmpresa,$uuid]);
    $rels=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rels){
        // No quedan relaciones financieras: limpiar también el resumen de pago empresa
        // para no conservar fechas/folios viejos después de quitar o rehacer una conciliación.
        $pdo->prepare("UPDATE facturas
                          SET origen_pago_financiero=NULL,
                              folio_pago_origen=NULL,
                              fecha_pago_origen=NULL,
                              estatus_pago_origen=NULL,
                              conciliado_banco_origen=0,
                              fecha_conciliacion_banco_origen=NULL,
                              fecha_valor_origen=NULL,
                              estatus_conciliacion_financiera='PENDIENTE',
                              fecha_ultima_conciliacion_financiera=NOW()
                        WHERE id_empresa=? AND uuid=?")
            ->execute([$idEmpresa,$uuid]);
        return;
    }

    // Primero tomar el resultado ya agregado por cada detalle fiscal (PUE o PPD).
    $sd=$pdo->prepare("SELECT estatus_conciliacion_origen FROM facturas_pagos_detalles WHERE id_empresa=? AND uuid_relacionado=?");
    $sd->execute([$idEmpresa,$uuid]);
    $estadosDetalle=$sd->fetchAll(PDO::FETCH_COLUMN);

    $estado='CONCILIADO';$max=-1;$ultimo=end($rels);$origen=null;$banco=1;$hayPago=false;

    // Resumen de pago a nivel factura. Los detalles quedan intactos.
    // Se reconstruye siempre desde conciliacion_financiera para que reprocesar no duplique datos.
    $folios=[];
    $origenes=[];
    $fechaPagoEmpresa=null;
    $ultimoPago=null;
    foreach($rels as $x){
        $fo=trim((string)($x['folio_pago']??''));
        if($fo!=='' && !in_array($fo,$folios,true)) $folios[]=$fo;
        $og=strtoupper(trim((string)($x['origen_pago']??'')));
        if($og!=='' && !in_array($og,$origenes,true)) $origenes[]=$og;
        $fp=cf_fecha($x['fecha_pago']??null);
        if($fp!==null && ($fechaPagoEmpresa===null || $fp>$fechaPagoEmpresa)){
            $fechaPagoEmpresa=$fp;
            $ultimoPago=$x;
        }
    }
    $referenciasEmpresa=$folios ? implode(' / ',$folios) : null;
    $origenResumen=count($origenes)>1 ? 'MIXTO' : ($origenes[0]??null);
    if($ultimoPago===null) $ultimoPago=$ultimo;

    foreach($estadosDetalle as $e){
        $pr=cf_estado_prioridad((string)$e);
        if($pr>$max){$max=$pr;$estado=(string)$e;}
    }
    // Relaciones sin detalle (SIN_XML/SIN_DETALLE, etc.) también deben pesar.
    foreach($rels as $x){
        if(empty($x['id_detalle'])){
            $pr=cf_estado_prioridad((string)$x['estatus_conciliacion']);
            if($pr>$max){$max=$pr;$estado=(string)$x['estatus_conciliacion'];}
        }
        $origen=$origen?:($x['origen_pago']??null);
        if(!empty($x['fecha_pago']) || !empty($x['folio_pago'])) $hayPago=true;
        if((int)($x['conciliado_banco']??0)!==1) $banco=0;
    }
    if($max<0){
        foreach($rels as $x){
            $pr=cf_estado_prioridad((string)$x['estatus_conciliacion']);
            if($pr>$max){$max=$pr;$estado=(string)$x['estatus_conciliacion'];}
        }
    }

    if(!$hayPago) $banco=0;

    $pdo->prepare("UPDATE facturas SET origen_pago_financiero=?,folio_pago_origen=?,fecha_pago_origen=?,estatus_pago_origen=?,
                           conciliado_banco_origen=?,fecha_conciliacion_banco_origen=?,fecha_valor_origen=?,
                           estatus_conciliacion_financiera=?,fecha_ultima_conciliacion_financiera=NOW()
                    WHERE id_empresa=? AND uuid=?")
        ->execute([$origenResumen,$referenciasEmpresa,$fechaPagoEmpresa,$ultimoPago['estatus_pago']?:null,$banco,
                   $ultimoPago['fecha_conciliacion_banco']?:null,$ultimoPago['fecha_valor']?:null,$estado,$idEmpresa,$uuid]);
}

function cf_relacion_base(array $doc, array $xml, ?array $det, ?array $pago, array $extra=[]): array {
    $esperado=(float)($det['importe_aplicado']??$det['monto_pagado']??0);
    $importeDocumento=(float)($extra['importe_documento']??$doc['invoice_amount']??$doc['receipt_amount']??0);
    $importePago=$pago?cf_pago_importe($pago):0.0;
    $comparado=($extra['comparar_con_documento']??false)?$importeDocumento:$importePago;
    if(($extra['comparar_con_documento']??false) && $comparado<=0) $comparado=$esperado;
    if(!($extra['comparar_con_documento']??false) && !$pago) $comparado=0;
    $dif=$esperado-$comparado;
    $fechaDet=cf_fecha($det['fecha_pago_sat']??$det['fecha_aplicacion_fiscal']??null);
    $fechaPag=$pago?cf_fecha($pago['payment_date']??null):null;
    $cancelado=((int)($doc['canceled_flag']??0)===1)||in_array(strtoupper(trim((string)($xml['estatus_sat']??''))),['CANCELADO','CANCELADA','0'],true)||!empty($pago['void_date']);
    $estado=cf_estado_general($cancelado,true,$det!==null,$pago!==null,$dif,!empty($pago['reconciled_flag']));
    return array_merge([
        'uuid_factura'=>$xml['uuid']??'',
        'id_detalle'=>$det['id_detalle']??null,
        'folio_pago'=>$pago?cf_pago_folio($pago):null,
        'fecha_pago'=>$fechaPag,
        'importe_documento'=>$importeDocumento,
        'importe_pago'=>$importePago,
        'importe_aplicado'=>(!$pago||$cancelado?0:($estado==='DIFERENCIA'?min(max(0,$comparado),max(0,$esperado)):$esperado)),
        'moneda'=>$pago['payment_currency']??$doc['invoice_currency']??$doc['receipt_currency_code']??$xml['moneda']??null,
        'estatus_pago'=>$pago['payment_status']??$pago['invoice_payment_status']??$doc['payment_status']??$doc['paid_status']??null,
        'conciliado_banco'=>!empty($pago['reconciled_flag']),
        'fecha_conciliacion_banco'=>$pago['clearing_date']??null,
        'fecha_valor'=>$pago['clearing_value_date']??null,
        'estatus_xml'=>'OK','estatus_detalle_pago'=>$det?'OK':'SIN_DETALLE',
        'estatus_conciliacion'=>$estado,'diferencia_importe'=>$dif,'diferencia_dias'=>cf_dias($fechaDet,$fechaPag)
    ],$extra);
}

function cf_conciliar_oracle_factura(PDO $pdo, int $idEmpresa, array $of, int $idUsuario=0): array {
    $idDoc=(int)$of['id'];
    if($manual=cf_clasificacion_manual($pdo,$idEmpresa,'FACTURA',$idDoc)){
        $estado=strtoupper((string)$manual['estatus_asignado']);
        $pdo->prepare("UPDATE oracle_facturas SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$estado,$idDoc,$idEmpresa]);
        return ['estado'=>$estado,'uuid'=>cf_normalizar_uuid($of['uuid_cfdi']??''),'clasificacion'=>'MANUAL'];
    }
    cf_limpiar_oracle_documento($pdo,$idEmpresa,'FACTURA',$idDoc);
    $uinfo=cf_uuid_efectivo($pdo,$idEmpresa,'FACTURA',$idDoc,$of['uuid_cfdi']??'');
    $uuid=$uinfo['uuid'];
    $uuidManual=!empty($uinfo['manual']);
    if($uuid===''){
        $clas=cf_aplicar_clasificacion_sin_uuid($pdo,$idEmpresa,'FACTURA',$of,$idUsuario);
        if($clas){
            $pdo->prepare("UPDATE oracle_facturas SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$clas,$idDoc,$idEmpresa]);
            return ['estado'=>$clas,'uuid'=>'','clasificacion'=>'REGLA'];
        }
        $pdo->prepare("UPDATE oracle_facturas SET conciliado=0,estatus_conciliacion='SIN_UUID',fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$idDoc,$idEmpresa]);
        return ['estado'=>'SIN_UUID','uuid'=>''];
    }

    $xml=cf_factura_xml($pdo,$idEmpresa,$uuid);
    $pagos=cf_pagos_oracle($pdo,$idEmpresa,(string)$of['oracle_invoice_id'],$of);
    $ultimoPago=$pagos?end($pagos):null;
    if(!$xml){
        $p=$ultimoPago?:null;
        cf_guardar_relacion($pdo,[
            'id_empresa'=>$idEmpresa,'uuid_factura'=>$uuid,'id_detalle'=>null,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'FACTURA',
            'id_documento_origen'=>$idDoc,'referencia_documento'=>$of['invoice_number']??null,'id_pago_origen'=>$p['id']??null,
            'folio_pago'=>$p?cf_pago_folio($p):null,'fecha_pago'=>$p['payment_date']??null,'importe_documento'=>$of['invoice_amount']??0,
            'importe_pago'=>$p?cf_pago_importe($p):0,'importe_aplicado'=>0,'moneda'=>$p['payment_currency']??$of['invoice_currency']??null,
            'estatus_pago'=>$p['payment_status']??$of['payment_status']??$of['paid_status']??null,'conciliado_banco'=>!empty($p['reconciled_flag']),
            'fecha_conciliacion_banco'=>$p['clearing_date']??null,'fecha_valor'=>$p['clearing_value_date']??null,
            'estatus_xml'=>'SIN_XML','estatus_detalle_pago'=>'PENDIENTE','estatus_conciliacion'=>'SIN_XML','regla_cruce'=>$uuidManual?'UUID_MANUAL_SIN_XML':'UUID_ORACLE_SIN_XML',
            'diferencia_importe'=>0,'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA'
        ]);
        $pdo->prepare("UPDATE oracle_facturas SET conciliado=0,estatus_conciliacion='SIN_XML',fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$idDoc,$idEmpresa]);
        return ['estado'=>'SIN_XML','uuid'=>$uuid];
    }

    $detalles=cf_crear_pue_si_falta($pdo,$xml,$ultimoPago['payment_date']??null);
    if(!$detalles){
        $p=$ultimoPago?:null;
        // Si Oracle ya trae el pago y además está conciliado contra banco, el cruce
        // financiero sí está correcto; lo único pendiente es que llegue el REP SAT.
        $estadoSinDetalle=($p && !empty($p['reconciled_flag'])) ? 'CONCILIADO_SIN_COMPLEMENTO' : 'SIN_DETALLE';
        $reglaSinDetalle=$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO' ? 'UUID+PAGO+BANCARIO_SIN_COMPLEMENTO' : 'UUID_SIN_DETALLE_FISCAL';
        cf_guardar_relacion($pdo,[
            'id_empresa'=>$idEmpresa,'uuid_factura'=>$uuid,'id_detalle'=>null,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'FACTURA',
            'id_documento_origen'=>$idDoc,'referencia_documento'=>$of['invoice_number']??null,'id_pago_origen'=>$p['id']??null,
            'folio_pago'=>$p?cf_pago_folio($p):null,'fecha_pago'=>$p['payment_date']??null,'importe_documento'=>$of['invoice_amount']??0,
            'importe_pago'=>$p?cf_pago_importe($p):0,'importe_aplicado'=>0,'moneda'=>$p['payment_currency']??$of['invoice_currency']??null,
            'estatus_pago'=>$p['payment_status']??$of['payment_status']??$of['paid_status']??null,'conciliado_banco'=>!empty($p['reconciled_flag']),
            'fecha_conciliacion_banco'=>$p['clearing_date']??null,'fecha_valor'=>$p['clearing_value_date']??null,
            'estatus_xml'=>'OK','estatus_detalle_pago'=>'SIN_COMPLEMENTO','estatus_conciliacion'=>$estadoSinDetalle,'regla_cruce'=>$uuidManual?('UUID_MANUAL+'. $reglaSinDetalle):$reglaSinDetalle,
            'diferencia_importe'=>0,'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA',
            'observaciones'=>$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO' ? 'Pago Oracle conciliado contra banco; pendiente complemento de pago SAT.' : null
        ]);
        $pdo->prepare("UPDATE oracle_facturas SET conciliado=?,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")
            ->execute([$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO'?1:0,$estadoSinDetalle,$idDoc,$idEmpresa]);
        cf_recalcular_factura_generica($pdo,$idEmpresa,$uuid);
        return ['estado'=>$estadoSinDetalle,'uuid'=>$uuid];
    }

    $usados=[];$estados=[];
    // Caso de una sola parcialidad fiscal con varios pagos Oracle: repartir el
    // importe del detalle entre los pagos, sin contar el total completo varias veces.
    if(count($detalles)===1 && count($pagos)>1){
        $det=$detalles[0];
        $restante=max(0,(float)($det['importe_aplicado']??$det['monto_pagado']??0));
        foreach($pagos as $p){
            $r=cf_relacion_base($of,$xml,$det,$p,[
                'id_empresa'=>$idEmpresa,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'FACTURA','id_documento_origen'=>$idDoc,
                'referencia_documento'=>$of['invoice_number']??null,'id_pago_origen'=>$p['id']??null,
                'regla_cruce'=>$uuidManual?'UUID_MANUAL+PAGO_ORACLE':(strtoupper((string)$xml['metodo_pago'])==='PUE'?'UUID+PUE+PAGO_ORACLE':'UUID+PAGO_ORACLE'),
                'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA'
            ]);
            $importePago=max(0,cf_pago_importe($p));
            $aplicado=min($restante,$importePago);
            $r['importe_aplicado']=$aplicado;
            $r['diferencia_importe']=0;
            if($aplicado<=0.0001){
                $r['estatus_conciliacion']='DIFERENCIA';
            }elseif(!empty($p['void_date'])){
                $r['estatus_conciliacion']='CANCELADO';
            }else{
                $r['estatus_conciliacion']=!empty($p['reconciled_flag'])?'CONCILIADO':'PARCIAL';
            }
            $restante=max(0,$restante-$aplicado);
            cf_guardar_relacion($pdo,$r);$estados[]=$r['estatus_conciliacion'];
        }
    }else{
        foreach($detalles as $det){
            $match=cf_mejor_pago($det,$pagos,$usados);
            $p=$match['pago']??null;
            if($match && count($pagos)>=count($detalles))$usados[$match['idx']]=true;
            $r=cf_relacion_base($of,$xml,$det,$p,[
                'id_empresa'=>$idEmpresa,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'FACTURA','id_documento_origen'=>$idDoc,
                'referencia_documento'=>$of['invoice_number']??null,'id_pago_origen'=>$p['id']??null,
                'regla_cruce'=>$uuidManual?'UUID_MANUAL+IMPORTE+FECHA':(strtoupper((string)$xml['metodo_pago'])==='PPD'?'UUID+PPD+IMPORTE+FECHA':'UUID+PUE+PAGO_ORACLE'),
                'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA'
            ]);
            cf_guardar_relacion($pdo,$r);$estados[]=$r['estatus_conciliacion'];
        }
    }

    foreach($detalles as $d) cf_recalcular_detalle_generico($pdo,$idEmpresa,(int)$d['id_detalle']);
    cf_recalcular_factura_generica($pdo,$idEmpresa,$uuid);
    $se=$pdo->prepare("SELECT estatus_conciliacion_financiera FROM facturas WHERE id_empresa=? AND uuid=? LIMIT 1");
    $se->execute([$idEmpresa,$uuid]);
    $estado=(string)($se->fetchColumn()?:'PENDIENTE');
    $pdo->prepare("UPDATE oracle_facturas SET conciliado=?,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")
        ->execute([$estado==='CONCILIADO'?1:0,$estado,$idDoc,$idEmpresa]);
    return ['estado'=>$estado,'uuid'=>$uuid];
}

function cf_conciliar_oracle_gasto(PDO $pdo, int $idEmpresa, array $g, int $idUsuario=0): array {
    $idDoc=(int)$g['id'];
    if($manual=cf_clasificacion_manual($pdo,$idEmpresa,'EXP_DETALLE',$idDoc)){
        $estado=strtoupper((string)$manual['estatus_asignado']);
        $pdo->prepare("UPDATE oracle_gastos SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$estado,$idDoc,$idEmpresa]);
        return ['estado'=>$estado,'uuid'=>cf_normalizar_uuid($g['uuid_cfdi']??''),'exp'=>cf_exp_number($g['oracle_expense_report_id']??''),'clasificacion'=>'MANUAL'];
    }
    cf_limpiar_oracle_documento($pdo,$idEmpresa,'EXP_DETALLE',$idDoc);
    $uinfo=cf_uuid_efectivo($pdo,$idEmpresa,'EXP_DETALLE',$idDoc,$g['uuid_cfdi']??'');
    $uuid=$uinfo['uuid'];
    $uuidManual=!empty($uinfo['manual']);
    $exp=cf_exp_number($g['oracle_expense_report_id']??'');

    // El encabezado EXP se busca por igualdad directa para usar
    // idx_oracle_factura_empresa_numero. Cache local porque varios gastos suelen
    // pertenecer al mismo reporte de gastos.
    static $parentCache=[];
    $parentKey=$idEmpresa.'|'.$exp;
    if(array_key_exists($parentKey,$parentCache)){
        $parent=$parentCache[$parentKey];
    }else{
        $sp=$pdo->prepare("SELECT * FROM oracle_facturas WHERE id_empresa=? AND invoice_number=? LIMIT 1");
        $sp->execute([$idEmpresa,$exp]);
        $parent=$sp->fetch(PDO::FETCH_ASSOC)?:null;
        $parentCache[$parentKey]=$parent;
    }
    $pagos=$parent?cf_pagos_oracle($pdo,$idEmpresa,(string)$parent['oracle_invoice_id'],$parent):[];
    $pago=$pagos?end($pagos):null;

    // En comprobantes de reposición EXP conservamos el cheque y agregamos
    // el folio completo de la caja chica para que el Visor muestre, por ejemplo:
    // 15123/EXP000434216683. Esta regla aplica únicamente a EXP_DETALLE.
    $folioPagoExp='';
    if($pago){
        $folioPagoExp=trim(cf_pago_folio($pago));
        if($folioPagoExp!=='' && $exp!=='') $folioPagoExp.='/'.$exp;
    }

    if($uuid===''){
        $clas=cf_aplicar_clasificacion_sin_uuid($pdo,$idEmpresa,'EXP_DETALLE',$g,$idUsuario);
        if($clas){
            $pdo->prepare("UPDATE oracle_gastos SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$clas,$idDoc,$idEmpresa]);
            return ['estado'=>$clas,'uuid'=>'','exp'=>$exp,'clasificacion'=>'REGLA'];
        }
        $pdo->prepare("UPDATE oracle_gastos SET conciliado=0,estatus_conciliacion='SIN_UUID',fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$idDoc,$idEmpresa]);
        return ['estado'=>'SIN_UUID','uuid'=>'','exp'=>$exp];
    }
    $xml=cf_factura_xml($pdo,$idEmpresa,$uuid);
    if(!$xml){
        cf_guardar_relacion($pdo,[
            'id_empresa'=>$idEmpresa,'uuid_factura'=>$uuid,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'EXP_DETALLE','id_documento_origen'=>$idDoc,
            'referencia_documento'=>$g['reference_number']??$g['expense_reference']??$g['oracle_expense_id'],'documento_padre'=>$exp,
            'id_pago_origen'=>$pago['id']??null,'folio_pago'=>$folioPagoExp!==''?$folioPagoExp:null,'fecha_pago'=>$pago['payment_date']??null,
            'importe_documento'=>$g['receipt_amount']??0,'importe_pago'=>$pago?cf_pago_importe($pago):0,'importe_aplicado'=>0,
            'moneda'=>$g['receipt_currency_code']??null,'estatus_pago'=>$pago['payment_status']??$parent['payment_status']??null,
            'conciliado_banco'=>!empty($pago['reconciled_flag']),'fecha_conciliacion_banco'=>$pago['clearing_date']??null,
            'fecha_valor'=>$pago['clearing_value_date']??null,'estatus_xml'=>'SIN_XML','estatus_detalle_pago'=>'PENDIENTE',
            'estatus_conciliacion'=>'SIN_XML','regla_cruce'=>$uuidManual?'EXP_UUID_MANUAL_SIN_XML':'EXP_UUID_SIN_XML','id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA',
            'observaciones'=>$parent?'Detalle heredado de '.$exp:'No se encontró encabezado '.$exp
        ]);
        $pdo->prepare("UPDATE oracle_gastos SET conciliado=0,estatus_conciliacion='SIN_XML',fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")->execute([$idDoc,$idEmpresa]);
        return ['estado'=>'SIN_XML','uuid'=>$uuid,'exp'=>$exp];
    }

    $detalles=cf_crear_pue_si_falta($pdo,$xml,$pago['payment_date']??null);
    if(!$detalles){
        $estadoSinDetalle=($pago && !empty($pago['reconciled_flag'])) ? 'CONCILIADO_SIN_COMPLEMENTO' : 'SIN_DETALLE';
        $reglaSinDetalle=$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO' ? 'EXP+UUID+PAGO+BANCARIO_SIN_COMPLEMENTO' : 'EXP_UUID_SIN_DETALLE';
        cf_guardar_relacion($pdo,[
            'id_empresa'=>$idEmpresa,'uuid_factura'=>$uuid,'origen_pago'=>'ORACLE','tipo_documento_origen'=>'EXP_DETALLE','id_documento_origen'=>$idDoc,
            'referencia_documento'=>$g['reference_number']??$g['expense_reference']??$g['oracle_expense_id'],'documento_padre'=>$exp,
            'id_pago_origen'=>$pago['id']??null,'folio_pago'=>$folioPagoExp!==''?$folioPagoExp:null,'fecha_pago'=>$pago['payment_date']??null,
            'importe_documento'=>$g['receipt_amount']??0,'importe_pago'=>$pago?cf_pago_importe($pago):0,'importe_aplicado'=>0,
            'moneda'=>$g['receipt_currency_code']??null,'estatus_pago'=>$pago['payment_status']??$parent['payment_status']??null,
            'conciliado_banco'=>!empty($pago['reconciled_flag']),'fecha_conciliacion_banco'=>$pago['clearing_date']??null,'fecha_valor'=>$pago['clearing_value_date']??null,
            'estatus_xml'=>'OK','estatus_detalle_pago'=>'SIN_COMPLEMENTO','estatus_conciliacion'=>$estadoSinDetalle,'regla_cruce'=>$uuidManual?('UUID_MANUAL+'. $reglaSinDetalle):$reglaSinDetalle,
            'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA',
            'observaciones'=>$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO' ? 'Comprobante de '.$exp.'. Pago conciliado contra banco; pendiente complemento de pago SAT.' : 'Comprobante de '.$exp
        ]);
        $pdo->prepare("UPDATE oracle_gastos SET conciliado=?,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")
            ->execute([$estadoSinDetalle==='CONCILIADO_SIN_COMPLEMENTO'?1:0,$estadoSinDetalle,$idDoc,$idEmpresa]);
        cf_recalcular_factura_generica($pdo,$idEmpresa,$uuid);
        return ['estado'=>$estadoSinDetalle,'uuid'=>$uuid,'exp'=>$exp];
    }

    $estados=[];
    // El comprobante EXP representa la factura completa; sus detalles fiscales
    // pueden ser una sola fila PUE o varias parcialidades PPD. La validación del
    // importe se hace a nivel documento XML vs comprobante EXP, no comparando
    // cada parcialidad contra el total de la reposición.
    $importeDoc=(float)($g['receipt_amount']??0);
    $importeXml=(float)($xml['total_xml']??0);
    $difDoc=round($importeXml-$importeDoc,4);
    foreach($detalles as $det){
        $esperado=(float)($det['importe_aplicado']??$det['monto_pagado']??0);
        $fechaDet=cf_fecha($det['fecha_pago_sat']??$det['fecha_aplicacion_fiscal']??null);
        $fechaPago=$pago?cf_fecha($pago['payment_date']??null):null;
        $cancelado=in_array(strtoupper(trim((string)($xml['estatus_sat']??''))),['CANCELADO','CANCELADA','0'],true)||!empty($pago['void_date']);
        if($cancelado){
            $estado='CANCELADO';
        }elseif(!$pago){
            $estado='SIN_PAGO';
        }elseif(abs($difDoc)>0.02){
            $estado='DIFERENCIA';
        }else{
            $estado=!empty($pago['reconciled_flag'])?'CONCILIADO':'PARCIAL';
        }
        $r=[
            'id_empresa'=>$idEmpresa,'uuid_factura'=>$uuid,'id_detalle'=>$det['id_detalle']??null,
            'origen_pago'=>'ORACLE','tipo_documento_origen'=>'EXP_DETALLE','id_documento_origen'=>$idDoc,
            'referencia_documento'=>$g['reference_number']??$g['expense_reference']??$g['oracle_expense_id'],'documento_padre'=>$exp,
            'id_pago_origen'=>$pago['id']??null,'folio_pago'=>$folioPagoExp!==''?$folioPagoExp:null,'fecha_pago'=>$fechaPago,
            'importe_documento'=>$importeDoc,'importe_pago'=>$pago?cf_pago_importe($pago):0,'importe_aplicado'=>$pago?$esperado:0,
            'moneda'=>$pago['payment_currency']??$g['receipt_currency_code']??$xml['moneda']??null,
            'estatus_pago'=>$pago['payment_status']??$pago['invoice_payment_status']??$parent['payment_status']??$parent['paid_status']??null,
            'conciliado_banco'=>!empty($pago['reconciled_flag']),'fecha_conciliacion_banco'=>$pago['clearing_date']??null,
            'fecha_valor'=>$pago['clearing_value_date']??null,'estatus_xml'=>'OK','estatus_detalle_pago'=>'OK',
            'estatus_conciliacion'=>$estado,'regla_cruce'=>'EXP+UUID+DETALLE_PAGO+PAGO_REPOSICION',
            'diferencia_importe'=>$difDoc,'diferencia_dias'=>cf_dias($fechaDet,$fechaPago),'id_usuario_concilia'=>$idUsuario?:null,'origen_conciliacion'=>$uuidManual?'MANUAL':'AUTOMATICA',
            'observaciones'=>$parent?('Pago heredado de '.$exp):('Sin encabezado Oracle '.$exp)
        ];
        cf_guardar_relacion($pdo,$r);$estados[]=$estado;
    }
    foreach($detalles as $d) cf_recalcular_detalle_generico($pdo,$idEmpresa,(int)$d['id_detalle']);
    cf_recalcular_factura_generica($pdo,$idEmpresa,$uuid);
    $se=$pdo->prepare("SELECT estatus_conciliacion_financiera FROM facturas WHERE id_empresa=? AND uuid=? LIMIT 1");
    $se->execute([$idEmpresa,$uuid]);
    $estado=(string)($se->fetchColumn()?:'PENDIENTE');
    $pdo->prepare("UPDATE oracle_gastos SET conciliado=?,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")
        ->execute([$estado==='CONCILIADO'?1:0,$estado,$idDoc,$idEmpresa]);
    return ['estado'=>$estado,'uuid'=>$uuid,'exp'=>$exp];
}

function cf_actualizar_exp_padres(PDO $pdo, int $idEmpresa, string $desde, string $hasta): void {
    $st=$pdo->prepare("SELECT id,invoice_number FROM oracle_facturas WHERE id_empresa=? AND invoice_date>=? AND invoice_date<=? AND invoice_number LIKE 'EXP%'");
    $st->execute([$idEmpresa,$desde,$hasta]);
    // Consulta preparada una sola vez. Se compara oracle_expense_report_id por igualdad
    // para usar idx_oracle_gasto_report; se elimina CONCAT/RIGHT/LPAD/TRIM de la columna.
    $sg=$pdo->prepare("SELECT estatus_conciliacion,COUNT(*) n
                         FROM oracle_gastos
                        WHERE id_empresa=? AND oracle_expense_report_id IN (?,?)
                        GROUP BY estatus_conciliacion");
    while($p=$st->fetch(PDO::FETCH_ASSOC)){
        $exp=strtoupper(trim((string)$p['invoice_number']));
        $reportConCeros=preg_replace('/\D+/','',$exp);
        $reportSinCeros=ltrim((string)$reportConCeros,'0');
        if($reportSinCeros==='') $reportSinCeros='0';
        $sg->execute([$idEmpresa,$reportSinCeros,$reportConCeros]);
        $estados=$sg->fetchAll(PDO::FETCH_ASSOC);
        if(!$estados){$estado='PENDIENTE';$ok=0;}
        else{
            $estado='CONCILIADO';$max=-1;$todos=true;
            foreach($estados as $e){$pr=cf_estado_prioridad((string)$e['estatus_conciliacion']);if($pr>$max){$max=$pr;$estado=(string)$e['estatus_conciliacion'];}if($e['estatus_conciliacion']!=='CONCILIADO')$todos=false;}
            $ok=$todos?1:0;
        }
        $pdo->prepare("UPDATE oracle_facturas SET conciliado=?,estatus_conciliacion=?,fecha_conciliacion=NOW() WHERE id=? AND id_empresa=?")
            ->execute([$ok,$estado,(int)$p['id'],$idEmpresa]);
    }
}

function cf_conciliar_oracle_rango(PDO $pdo, int $idEmpresa, string $desde, string $hasta, int $idUsuario=0): array {
    cf_schema($pdo);
    $desde=cf_fecha($desde);$hasta=cf_fecha($hasta);
    if(!$desde||!$hasta) throw new InvalidArgumentException('Rango de conciliación inválido.');
    if($desde>$hasta) throw new InvalidArgumentException('La fecha desde no puede ser mayor que hasta.');

    $res=['facturas'=>0,'gastos'=>0,'conciliados'=>0,'parciales'=>0,'diferencias'=>0,'pendientes'=>0,'sin_xml'=>0,'sin_uuid'=>0,'cancelados'=>0,'errores'=>[]];

    // Limpiar sólo relaciones Oracle del rango actual; no toca CONTPAQ ni otros periodos.
    $st=$pdo->prepare("SELECT * FROM oracle_facturas WHERE id_empresa=? AND invoice_date>=? AND invoice_date<=? ORDER BY invoice_date,id");
    $st->execute([$idEmpresa,$desde,$hasta]);
    while($of=$st->fetch(PDO::FETCH_ASSOC)){
        // Las EXP se calculan al final con el agregado de sus comprobantes.
        if(str_starts_with(strtoupper(trim((string)$of['invoice_number'])),'EXP')) continue;
        try{$r=cf_conciliar_oracle_factura($pdo,$idEmpresa,$of,$idUsuario);$res['facturas']++;cf_contar_estado($res,$r['estado']);}
        catch(Throwable $e){$res['errores'][]='Factura '.($of['invoice_number']??$of['oracle_invoice_id']).': '.$e->getMessage();}
    }

    $st=$pdo->prepare("SELECT * FROM oracle_gastos
                        WHERE id_empresa=?
                          AND ((creation_date>=? AND creation_date<DATE_ADD(?,INTERVAL 1 DAY))
                               OR (creation_date IS NULL AND receipt_date>=? AND receipt_date<=?))
                        ORDER BY creation_date,id");
    $st->execute([$idEmpresa,$desde,$hasta,$desde,$hasta]);
    while($g=$st->fetch(PDO::FETCH_ASSOC)){
        try{$r=cf_conciliar_oracle_gasto($pdo,$idEmpresa,$g,$idUsuario);$res['gastos']++;cf_contar_estado($res,$r['estado']);}
        catch(Throwable $e){$res['errores'][]='Gasto '.($g['oracle_expense_id']??$g['id']).': '.$e->getMessage();}
    }

    cf_actualizar_exp_padres($pdo,$idEmpresa,$desde,$hasta);
    return $res;
}

function cf_contar_estado(array &$res, string $estado): void {
    switch(strtoupper($estado)){
        case 'CONCILIADO':$res['conciliados']++;break;
        case 'PARCIAL':$res['parciales']++;break;
        case 'DIFERENCIA':$res['diferencias']++;break;
        case 'SIN_XML':$res['sin_xml']++;break;
        case 'SIN_UUID':$res['sin_uuid']++;break;
        case 'CANCELADO':$res['cancelados']++;break;
        default:$res['pendientes']++;break;
    }
}

function cf_detalle_uuid(PDO $pdo, int $idEmpresa, string $uuid): array {
    cf_schema($pdo);
    $uuid=cf_normalizar_uuid($uuid);
    $xml=cf_factura_xml($pdo,$idEmpresa,$uuid);
    if(!$xml) throw new RuntimeException('El UUID no existe en el visor para la empresa activa.');
    $st=$pdo->prepare("SELECT c.*,d.origen_pago origen_fiscal,d.parcialidad,d.fecha_pago_sat,d.fecha_aplicacion_fiscal,d.uuid_pago,
                             d.importe_aplicado importe_detalle_fiscal
                        FROM conciliacion_financiera c
                        LEFT JOIN facturas_pagos_detalles d ON d.id_detalle=c.id_detalle AND d.id_empresa=c.id_empresa
                       WHERE c.id_empresa=? AND c.uuid_factura=?
                       ORDER BY COALESCE(c.fecha_pago,c.fecha_conciliacion_banco),c.id");
    $st->execute([$idEmpresa,$uuid]);
    return ['xml'=>$xml,'relaciones'=>$st->fetchAll(PDO::FETCH_ASSOC)];
}

function cf_detalle_oracle_factura(PDO $pdo, int $idEmpresa, string $invoiceId): array {
    cf_schema($pdo);
    $st=$pdo->prepare("SELECT * FROM oracle_facturas WHERE id_empresa=? AND oracle_invoice_id=? LIMIT 1");
    $st->execute([$idEmpresa,$invoiceId]);
    $f=$st->fetch(PDO::FETCH_ASSOC);
    if(!$f) throw new RuntimeException('Factura Oracle no encontrada.');
    $esExp=str_starts_with(strtoupper(trim((string)$f['invoice_number'])),'EXP');
    if(!$esExp){
        $uuid=cf_normalizar_uuid($f['uuid_cfdi']??'');
        $d=$uuid?cf_detalle_uuid($pdo,$idEmpresa,$uuid):['xml'=>null,'relaciones'=>[]];
        return ['tipo'=>'FACTURA','oracle'=>$f,'pagos'=>cf_pagos_oracle($pdo,$idEmpresa,$invoiceId,$f),'xml'=>$d['xml'],'relaciones'=>$d['relaciones']];
    }

    $exp=strtoupper(trim((string)$f['invoice_number']));
    $reportConCeros=preg_replace('/\D+/','',$exp);
    $reportSinCeros=ltrim((string)$reportConCeros,'0');
    if($reportSinCeros==='') $reportSinCeros='0';
    $sg=$pdo->prepare("SELECT * FROM oracle_gastos
                        WHERE id_empresa=? AND oracle_expense_report_id IN (?,?)
                        ORDER BY creation_date,id");
    $sg->execute([$idEmpresa,$reportSinCeros,$reportConCeros]);
    $gastos=$sg->fetchAll(PDO::FETCH_ASSOC);
    $rel=[];
    $sr=$pdo->prepare("SELECT c.*,d.origen_pago origen_fiscal,d.parcialidad,d.fecha_pago_sat,d.fecha_aplicacion_fiscal,d.uuid_pago
                        FROM conciliacion_financiera c LEFT JOIN facturas_pagos_detalles d ON d.id_detalle=c.id_detalle
                       WHERE c.id_empresa=? AND c.tipo_documento_origen='EXP_DETALLE' AND c.id_documento_origen=? ORDER BY c.id");
    foreach($gastos as $g){
        $sr->execute([$idEmpresa,(int)$g['id']]);
        $g['relaciones']=$sr->fetchAll(PDO::FETCH_ASSOC);
        $rel[]=$g;
    }
    return ['tipo'=>'EXP','oracle'=>$f,'pagos'=>cf_pagos_oracle($pdo,$idEmpresa,$invoiceId,$f),'gastos'=>$rel];
}
