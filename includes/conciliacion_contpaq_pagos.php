<?php

// Todas las comparaciones de UUID se fuerzan a utf8mb4_unicode_ci para evitar
// el error 1267 cuando las tablas históricas usan collations diferentes.

function cp_normalizar_uuid($v): string {
    return strtoupper(trim((string)$v));
}

function cp_fecha($v): ?string {
    if (!$v || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
    return substr((string)$v, 0, 10);
}

function cp_schema_conciliacion(PDO $pdo): void {
    static $validado = false;
    if ($validado) return;

    // Validación compacta: dos consultas a information_schema en lugar de una
    // consulta por cada tabla/columna. Reduce la demora antes del primer UUID.
    $tablas=['conciliacion_pagos_detalle','facturas_pagos_detalles','contpaq_dispersiones_pagos'];
    $marks=implode(',',array_fill(0,count($tablas),'?'));
    $st=$pdo->prepare("SELECT table_name FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name IN ($marks)");
    $st->execute($tablas);
    $encontradas=$st->fetchAll(PDO::FETCH_COLUMN);
    if(count(array_unique($encontradas))!==count($tablas)){
        throw new RuntimeException('Falta ejecutar sql/migracion_conciliacion_contpaq_diot_v1.sql.');
    }

    $requeridas=[
        'facturas_pagos_detalles.tc_pago_sat',
        'facturas_pagos_detalles.fecha_pago_contpaq',
        'facturas.tc_contpaq_real',
        'contpaq_dispersiones_pagos.moneda_contpaq',
        'contpaq_egresos.es_cancelado',
        'facturas_pagos_detalles.observaciones_conciliacion_contpaq',
        'facturas_pagos_detalles.fecha_ultima_conciliacion_contpaq',
        'conciliacion_pagos_detalle.rfc_emisor'
    ];
    $tablasColumnas=['facturas_pagos_detalles','facturas','contpaq_dispersiones_pagos','contpaq_egresos','conciliacion_pagos_detalle'];
    $marks=implode(',',array_fill(0,count($tablasColumnas),'?'));
    $st=$pdo->prepare("SELECT CONCAT(table_name,'.',column_name) clave
                        FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name IN ($marks)");
    $st->execute($tablasColumnas);
    $columnas=array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    foreach($requeridas as $clave){
        if(!isset($columnas[$clave])) throw new RuntimeException('La migración de conciliación CONTPAQ/DIOT está incompleta. Falta '.$clave.'.');
    }
    $validado = true;
}

function cp_recalcular_detalle(PDO $pdo, int $idEmpresa, int $idDetalle): void {
    $st=$pdo->prepare("SELECT d.monto_pagado,d.importe_aplicado,
                             COALESCE(SUM(c.importe_conciliado),0) conciliado,
                             MIN(c.fecha_pago_contpaq) primera_fecha,
                             MAX(c.fecha_pago_contpaq) ultima_fecha,
                             MAX(c.tc_contpaq) tc_contpaq,
                             MAX(c.moneda_contpaq) moneda_contpaq,
                             COUNT(c.id_conciliacion) aplicaciones
                        FROM facturas_pagos_detalles d
                        LEFT JOIN conciliacion_pagos_detalle c ON c.id_empresa=d.id_empresa AND c.id_detalle=d.id_detalle
                       WHERE d.id_empresa=? AND d.id_detalle=?
                       GROUP BY d.id_detalle,d.monto_pagado,d.importe_aplicado");
    $st->execute([$idEmpresa,$idDetalle]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) return;
    $esperado=(float)($r['importe_aplicado'] ?? $r['monto_pagado'] ?? 0);
    $conc=(float)$r['conciliado'];
    $saldo=max(0,round($esperado-$conc,4));
    $estatus=$conc<=0.0001?'PENDIENTE':($saldo<=0.01?'CONCILIADO':'PARCIAL');
    $fecha=$r['ultima_fecha'] ?: null;
    $pdo->prepare("UPDATE facturas_pagos_detalles SET
                       fecha_pago_contpaq=?, moneda_contpaq=?, tc_contpaq=?,
                       monto_conciliado_contpaq=?, saldo_por_conciliar=?, estatus_conciliacion_contpaq=?,
                       fecha_aplicacion_fiscal=CASE WHEN es_sintetico=0 AND ? IS NOT NULL THEN ? ELSE fecha_aplicacion_fiscal END
                    WHERE id_empresa=? AND id_detalle=?")
        ->execute([$fecha,$r['moneda_contpaq'],$r['tc_contpaq'],$conc,$saldo,$estatus,$fecha,$fecha,$idEmpresa,$idDetalle]);
}

function cp_recalcular_factura(PDO $pdo, int $idEmpresa, string $uuid): void {
    $uuid=cp_normalizar_uuid($uuid);
    if($uuid==='') return;

    // Consulta separada para aprovechar los índices reales:
    // facturas PRIMARY KEY(uuid) e idx_empresa_uuid_relacionado(id_empresa,uuid_relacionado).
    // La versión anterior aplicaba UPPER/COLLATE entre columnas y provocaba recorridos
    // completos de facturas_pagos_detalles, consumiendo más de 2 segundos por UUID.
    $sf=$pdo->prepare("SELECT total_xml,estatus_sat,fecha_cancelacion
                         FROM facturas
                        WHERE uuid=? AND id_empresa=?
                        LIMIT 1");
    $sf->execute([$uuid,$idEmpresa]);
    $factura=$sf->fetch(PDO::FETCH_ASSOC);
    if(!$factura) return;

    $sa=$pdo->prepare("SELECT COALESCE(SUM(c.importe_conciliado),0) total_contpaq,
                             MAX(c.fecha_pago_contpaq) ultima_fecha,
                             MAX(c.tc_contpaq) tc_contpaq,
                             MAX(c.moneda_contpaq) moneda_contpaq
                        FROM facturas_pagos_detalles d
                        LEFT JOIN conciliacion_pagos_detalle c
                          ON c.id_empresa=d.id_empresa AND c.id_detalle=d.id_detalle
                       WHERE d.id_empresa=? AND d.uuid_relacionado=? AND d.es_sintetico=0");
    $sa->execute([$idEmpresa,$uuid]);
    $r=$sa->fetch(PDO::FETCH_ASSOC) ?: [];

    $total=(float)($factura['total_xml'] ?? 0);
    $pagado=(float)($r['total_contpaq'] ?? 0);
    $saldo=max(0,round($total-$pagado,4));
    $cancelada=cp_cfdi_cancelado($factura['estatus_sat'] ?? null,$factura['fecha_cancelacion'] ?? null);

    // Una factura cancelada nunca queda conciliada ni entra por fecha CONTPAQ.
    if($cancelada){
        $pagado=0.0;
        $saldo=max(0,$total);
        $r['ultima_fecha']=null;
        $r['moneda_contpaq']=null;
        $r['tc_contpaq']=null;
    }

    $pdo->prepare("UPDATE facturas SET total_abonos_contpaq=?,saldo_conciliar_contpaq=?,fecha_ultimo_pago_contpaq=?,
                       fecha_pago_banco=?,fecha_aplicacion_fiscal=CASE WHEN ?=1 THEN NULL ELSE COALESCE(fecha_pago_usuario,?) END,
                       moneda_contpaq=?,tc_contpaq_real=?,tc_banco_real=CASE WHEN ?=1 THEN tc_banco_real ELSE COALESCE(?,tc_banco_real) END,
                       esta_conciliado=CASE WHEN ?=1 THEN 0 WHEN ?>0 AND ?<=0.01 THEN 1 ELSE 0 END
                    WHERE uuid=? AND id_empresa=?")
        ->execute([$pagado,$saldo,$r['ultima_fecha']??null,$r['ultima_fecha']??null,$cancelada?1:0,$r['ultima_fecha']??null,
            $r['moneda_contpaq']??null,$r['tc_contpaq']??null,$cancelada?1:0,$r['tc_contpaq']??null,
            $cancelada?1:0,$total,$saldo,$uuid,$idEmpresa]);
}

function cp_cfdi_cancelado($estatusSat, $fechaCancelacion=null): bool {
    if($fechaCancelacion && $fechaCancelacion!=='0000-00-00' && $fechaCancelacion!=='0000-00-00 00:00:00') return true;
    $e=mb_strtoupper(trim((string)$estatusSat),'UTF-8');
    return in_array($e,['CANCELADO','CANCELADA','CANCELLED'],true);
}


function cp_retirar_conciliaciones_canceladas(PDO $pdo, int $idEmpresa): int {
    // Si un cheque/egreso fue conciliado y posteriormente CONTPAQi lo marca cancelado,
    // se retira esa aplicación antes de recalcular. Las dispersiones permanecen como evidencia.
    $st=$pdo->prepare("SELECT c.id_conciliacion,c.id_detalle,c.uuid_factura
                        FROM conciliacion_pagos_detalle c
                        LEFT JOIN contpaq_cheques ch
                          ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
                        LEFT JOIN contpaq_egresos eg
                          ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
                       WHERE c.id_empresa=?
                         AND ((c.tipo_origen='C' AND COALESCE(ch.es_cancelado,0)=1)
                           OR (c.tipo_origen='T' AND COALESCE(eg.es_cancelado,0)=1))");
    $st->execute([$idEmpresa]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return 0;

    $ids=[]; $detalles=[]; $facturas=[];
    foreach($rows as $r){
        $ids[]=(int)$r['id_conciliacion'];
        $detalles[(int)$r['id_detalle']]=true;
        if(!empty($r['uuid_factura'])) $facturas[cp_normalizar_uuid($r['uuid_factura'])]=true;
    }
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("DELETE FROM conciliacion_pagos_detalle WHERE id_empresa=? AND id_conciliacion IN ($marks)")
        ->execute(array_merge([$idEmpresa],$ids));
    foreach(array_keys($detalles) as $idDet) cp_recalcular_detalle($pdo,$idEmpresa,(int)$idDet);
    foreach(array_keys($facturas) as $uuid) cp_recalcular_factura($pdo,$idEmpresa,(string)$uuid);
    return count($ids);
}

function cp_backfill_tipos_cambio_sat(PDO $pdo, int $idEmpresa): int {
    $st=$pdo->prepare("SELECT f.uuid,fd.xml_base64 FROM facturas f INNER JOIN facturas_datos fd ON (fd.uuid COLLATE utf8mb4_unicode_ci)=(f.uuid COLLATE utf8mb4_unicode_ci)
                       WHERE f.id_empresa=? AND UPPER(f.id_tipo_comprobante)='P'");
    $st->execute([$idEmpresa]);
    $up=$pdo->prepare("UPDATE facturas_pagos_detalles d
                       LEFT JOIN facturas fr ON fr.id_empresa=d.id_empresa AND (UPPER(fr.uuid) COLLATE utf8mb4_unicode_ci)=(UPPER(d.uuid_relacionado) COLLATE utf8mb4_unicode_ci)
                          SET d.moneda_factura=COALESCE(NULLIF(d.moneda_factura,''),fr.moneda),
                              d.tc_factura=COALESCE(NULLIF(d.tc_factura,0),fr.tc_xml_factura,1),
                              d.moneda_pago_sat=?,d.tc_pago_sat=?,d.moneda_dr=?,d.equivalencia_dr=?,
                              d.saldo_por_conciliar=CASE WHEN d.monto_conciliado_contpaq<=0 THEN COALESCE(d.importe_aplicado,d.monto_pagado,0) ELSE d.saldo_por_conciliar END
                        WHERE d.id_empresa=? AND d.clave_detalle=?");
    $n=0;
    while($row=$st->fetch(PDO::FETCH_ASSOC)){
        $raw=base64_decode((string)$row['xml_base64'],true); if($raw===false||trim($raw)==='') continue;
        $clean=str_replace(['cfdi:','tfd:','pago20:','pago10:','nomina12:','ecc12:'],'',$raw);
        $xml=@simplexml_load_string($clean); if(!$xml||!isset($xml->Complemento->Pagos->Pago)) continue;
        $np=0; $uuidPago=cp_normalizar_uuid($row['uuid']);
        foreach($xml->Complemento->Pagos->Pago as $pago){
            $np++; $nd=0;
            $monP=(string)($pago['MonedaP']??''); $tcP=(float)($pago['TipoCambioP']??1); if($tcP<=0)$tcP=1;
            foreach($pago->DoctoRelacionado as $doc){
                $nd++; $clave=hash('sha256',$uuidPago.'|'.$np.'|'.$nd);
                $monDr=(string)($doc['MonedaDR']??''); $eq=(float)($doc['EquivalenciaDR']??1); if($eq<=0)$eq=1;
                $up->execute([$monP?:null,$tcP,$monDr?:null,$eq,$idEmpresa,$clave]);
                $n += $up->rowCount()>0 ? 1 : 0;
            }
        }
    }
    return $n;
}

function cp_conciliar_automatico(PDO $pdo, int $idEmpresa, int $idUsuario): array {
    cp_schema_conciliacion($pdo);
    $stats=['revisadas'=>0,'creadas'=>0,'parciales'=>0,'sin_coincidencia'=>0,'ya_conciliadas'=>0,'facturas'=>0,'tipos_cambio_actualizados'=>0,'canceladas_retiradas'=>0];
    $stats['canceladas_retiradas']=cp_retirar_conciliaciones_canceladas($pdo,$idEmpresa);
    $stats['tipos_cambio_actualizados']=cp_backfill_tipos_cambio_sat($pdo,$idEmpresa);

    $sql="SELECT d.id,d.id_contpaq_dispersion,d.tipo_origen,d.id_contpaq_cheque,d.id_contpaq_egreso,
                 d.uuid,d.uuid_rep,d.fecha_pago,d.total_pago,d.total_pago_comprobante,d.tipo_cambio,
                 COALESCE(NULLIF(d.moneda_contpaq,''),ch.codigo_moneda,eg.codigo_moneda) moneda_contpaq,
                 p.id_detalle,p.uuid_pago,p.uuid_relacionado,p.monto_pagado,p.importe_aplicado,p.fecha_pago_sat,
                 p.moneda_factura,p.tc_factura,p.moneda_pago_sat,p.tc_pago_sat,
                 COALESCE((SELECT SUM(c.importe_conciliado) FROM conciliacion_pagos_detalle c WHERE c.id_empresa=p.id_empresa AND c.id_detalle=p.id_detalle),0) ya_detalle
            FROM contpaq_dispersiones_pagos d
            INNER JOIN facturas_pagos_detalles p
              ON p.id_empresa=d.id_empresa
             AND p.es_sintetico=0
             AND (UPPER(p.uuid_relacionado) COLLATE utf8mb4_unicode_ci)=(UPPER(d.uuid) COLLATE utf8mb4_unicode_ci)
             AND (NULLIF(TRIM(d.uuid_rep),'') IS NULL OR (UPPER(p.uuid_pago) COLLATE utf8mb4_unicode_ci)=(UPPER(d.uuid_rep) COLLATE utf8mb4_unicode_ci))
            LEFT JOIN contpaq_cheques ch ON d.tipo_origen='C' AND ch.id_empresa=d.id_empresa AND ch.id_contpaq_cheque=d.id_contpaq_cheque
            LEFT JOIN contpaq_egresos eg ON d.tipo_origen='T' AND eg.id_empresa=d.id_empresa AND eg.id_contpaq_egreso=d.id_contpaq_egreso
            LEFT JOIN conciliacion_pagos_detalle cx
              ON cx.id_empresa=d.id_empresa AND cx.id_contpaq_dispersion=d.id_contpaq_dispersion
           WHERE d.id_empresa=? AND cx.id_conciliacion IS NULL
             AND ((d.tipo_origen='C' AND COALESCE(ch.es_cancelado,0)=0)
               OR (d.tipo_origen='T' AND COALESCE(eg.es_cancelado,0)=0))
           ORDER BY CASE WHEN (UPPER(COALESCE(d.uuid_rep,'')) COLLATE utf8mb4_unicode_ci)=(UPPER(p.uuid_pago) COLLATE utf8mb4_unicode_ci) THEN 0 ELSE 1 END,
                    ABS(DATEDIFF(DATE(d.fecha_pago),p.fecha_pago_sat)),p.id_detalle,d.id_contpaq_dispersion";
    $st=$pdo->prepare($sql); $st->execute([$idEmpresa]);
    $usadas=[]; $detalles=[]; $facturas=[]; $acumuladoDetalle=[];
    $ins=$pdo->prepare("INSERT IGNORE INTO conciliacion_pagos_detalle
       (id_empresa,id_detalle,id_contpaq_dispersion,tipo_origen,id_contpaq_cheque,id_contpaq_egreso,
        uuid_factura,uuid_pago_sat,uuid_rep_contpaq,fecha_pago_sat,fecha_pago_contpaq,
        moneda_factura,tc_factura,moneda_pago_sat,tc_pago_sat,moneda_contpaq,tc_contpaq,
        importe_sat,importe_contpaq,importe_conciliado,diferencia_importe,diferencia_dias,
        origen_conciliacion,estatus,id_usuario_concilia,observaciones)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    while($r=$st->fetch(PDO::FETCH_ASSOC)) {
        $stats['revisadas']++;
        $disp=(int)$r['id_contpaq_dispersion'];
        if(isset($usadas[$disp])) continue;
        $esperado=(float)($r['importe_aplicado'] ?? $r['monto_pagado'] ?? 0);
        $idDet=(int)$r['id_detalle'];
        if(!array_key_exists($idDet,$acumuladoDetalle)) $acumuladoDetalle[$idDet]=(float)$r['ya_detalle'];
        $restante=max(0,$esperado-$acumuladoDetalle[$idDet]);
        if($restante<=0.01){ $stats['ya_conciliadas']++; continue; }
        $impContpaq=abs((float)$r['total_pago']);
        if($impContpaq<=0.0001) $impContpaq=abs((float)$r['total_pago_comprobante']);
        if($impContpaq<=0.0001){ $stats['sin_coincidencia']++; continue; }

        // La fecha SAT y la fecha CONTPAQ se conservan para informar diferencias,
        // pero nunca impiden el cruce cuando coinciden empresa + UUID de factura.
        $rep=cp_normalizar_uuid($r['uuid_rep']);
        $dias=null;
        if($r['fecha_pago'] && $r['fecha_pago_sat']) $dias=(int)((new DateTime(cp_fecha($r['fecha_pago'])))->diff(new DateTime(cp_fecha($r['fecha_pago_sat'])))->format('%r%a'));

        $importe=min($restante,$impContpaq);
        if($importe<=0.0001) continue;
        $dif=round($esperado-$importe,4);
        $estatus=($importe+0.01 >= $restante)?'CONCILIADO':'PARCIAL';
        $obs=$rep!==''?'Cruce automático UUID factura + UUID REP.':'Cruce automático UUID factura; UUID REP no disponible en CONTPAQ.';
        $ins->execute([$idEmpresa,(int)$r['id_detalle'],$disp,$r['tipo_origen'],$r['id_contpaq_cheque'],$r['id_contpaq_egreso'],
            cp_normalizar_uuid($r['uuid_relacionado']),cp_normalizar_uuid($r['uuid_pago']),$rep?:null,cp_fecha($r['fecha_pago_sat']),cp_fecha($r['fecha_pago']),
            $r['moneda_factura'],$r['tc_factura'],$r['moneda_pago_sat'],$r['tc_pago_sat'],$r['moneda_contpaq'],$r['tipo_cambio'],
            $esperado,$impContpaq,$importe,$dif,$dias,'AUTOMATICA',$estatus,$idUsuario,$obs]);
        if($ins->rowCount()>0){
            $stats['creadas']++; if($estatus==='PARCIAL')$stats['parciales']++;
            $usadas[$disp]=true; $acumuladoDetalle[$idDet]+=$importe; $detalles[$idDet]=true; $facturas[cp_normalizar_uuid($r['uuid_relacionado'])]=true;
            $pdo->prepare("UPDATE contpaq_dispersiones_pagos SET conciliado=1,estatus_conciliacion=?,fecha_conciliacion=NOW(),id_usuario_concilia=? WHERE id_empresa=? AND id_contpaq_dispersion=?")
                ->execute([$estatus,$idUsuario,$idEmpresa,$disp]);
        }
    }
    foreach(array_keys($detalles) as $idDet) cp_recalcular_detalle($pdo,$idEmpresa,$idDet);
    foreach(array_keys($facturas) as $uuid) cp_recalcular_factura($pdo,$idEmpresa,$uuid);
    $stats['facturas']=count($facturas);
    return $stats;
}

/**
 * Convierte un periodo YYYY-MM en límites inclusivos.
 */
function cp_periodo_limites(string $periodo): array {
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $periodo, $m)) {
        throw new RuntimeException('Periodo inválido. Use el formato Año-Mes.');
    }
    $desde = $periodo . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    return [$desde, $hasta];
}

/**
 * Retira únicamente conciliaciones de movimientos cancelados dentro del periodo.
 * Recalcula desde las aplicaciones vigentes para evitar dobles restas.
 */
function cp_retirar_conciliaciones_canceladas_periodo(PDO $pdo, int $idEmpresa, string $desde, string $hasta): int {
    $st=$pdo->prepare("SELECT c.id_conciliacion,c.id_detalle,c.uuid_factura
                        FROM conciliacion_pagos_detalle c
                        LEFT JOIN contpaq_cheques ch
                          ON c.tipo_origen='C' AND ch.id_empresa=c.id_empresa AND ch.id_contpaq_cheque=c.id_contpaq_cheque
                        LEFT JOIN contpaq_egresos eg
                          ON c.tipo_origen='T' AND eg.id_empresa=c.id_empresa AND eg.id_contpaq_egreso=c.id_contpaq_egreso
                       WHERE c.id_empresa=?
                         AND c.fecha_pago_contpaq BETWEEN ? AND ?
                         AND ((c.tipo_origen='C' AND COALESCE(ch.es_cancelado,0)=1)
                           OR (c.tipo_origen='T' AND COALESCE(eg.es_cancelado,0)=1))");
    $st->execute([$idEmpresa,$desde,$hasta]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return 0;

    $ids=[]; $detalles=[]; $facturas=[];
    foreach($rows as $r){
        $ids[]=(int)$r['id_conciliacion'];
        $detalles[(int)$r['id_detalle']]=true;
        if(!empty($r['uuid_factura'])) $facturas[cp_normalizar_uuid($r['uuid_factura'])]=true;
    }
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("DELETE FROM conciliacion_pagos_detalle WHERE id_empresa=? AND id_conciliacion IN ($marks)")
        ->execute(array_merge([$idEmpresa],$ids));
    foreach(array_keys($detalles) as $idDet) cp_recalcular_detalle($pdo,$idEmpresa,(int)$idDet);
    foreach(array_keys($facturas) as $uuid) cp_recalcular_factura($pdo,$idEmpresa,(string)$uuid);
    return count($ids);
}

/**
 * Revierte conciliaciones del periodo cuando la factura relacionada o el REP
 * ahora aparecen cancelados ante el SAT. Conserva las dispersiones como evidencia,
 * elimina únicamente la aplicación conciliada y recalcula desde datos vigentes.
 */
function cp_retirar_conciliaciones_cfdi_cancelados_periodo(PDO $pdo, int $idEmpresa, string $desde, string $hasta, ?int $idUsuario=null): array {
    $sql="SELECT c.id_conciliacion,c.id_detalle,c.id_contpaq_dispersion,c.uuid_factura,c.uuid_pago_sat,
                 f.estatus_sat estatus_factura,f.fecha_cancelacion cancelacion_factura,
                 rep.estatus_sat estatus_rep,rep.fecha_cancelacion cancelacion_rep
            FROM conciliacion_pagos_detalle c
            LEFT JOIN facturas f
              ON f.id_empresa=c.id_empresa
             AND (f.uuid COLLATE utf8mb4_unicode_ci)=(c.uuid_factura COLLATE utf8mb4_unicode_ci)
            LEFT JOIN facturas rep
              ON rep.id_empresa=c.id_empresa
             AND (rep.uuid COLLATE utf8mb4_unicode_ci)=(c.uuid_pago_sat COLLATE utf8mb4_unicode_ci)
           WHERE c.id_empresa=?
             AND c.fecha_pago_contpaq>=? AND c.fecha_pago_contpaq<DATE_ADD(?, INTERVAL 1 DAY)
             AND (
                  UPPER(COALESCE(f.estatus_sat,'')) IN ('CANCELADO','CANCELADA')
                  OR f.fecha_cancelacion IS NOT NULL
                  OR UPPER(COALESCE(rep.estatus_sat,'')) IN ('CANCELADO','CANCELADA')
                  OR rep.fecha_cancelacion IS NOT NULL
             )";
    $st=$pdo->prepare($sql);
    $st->execute([$idEmpresa,$desde,$hasta]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['retiradas'=>0,'facturas_canceladas'=>0,'rep_cancelados'=>0];

    $ids=[];$detalles=[];$facturas=[];$dispersiones=[];$facturasCanceladas=[];$repCancelados=[];
    foreach($rows as $r){
        $ids[]=(int)$r['id_conciliacion'];
        $detalles[(int)$r['id_detalle']]=true;
        $uuid=cp_normalizar_uuid($r['uuid_factura']??'');
        if($uuid!=='') $facturas[$uuid]=true;
        $dispersiones[(int)$r['id_contpaq_dispersion']]=true;
        if(cp_cfdi_cancelado($r['estatus_factura']??null,$r['cancelacion_factura']??null) && $uuid!=='') $facturasCanceladas[$uuid]=true;
        $uuidRep=cp_normalizar_uuid($r['uuid_pago_sat']??'');
        if(cp_cfdi_cancelado($r['estatus_rep']??null,$r['cancelacion_rep']??null) && $uuidRep!=='') $repCancelados[$uuidRep]=true;
    }

    $marks=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("DELETE FROM conciliacion_pagos_detalle WHERE id_empresa=? AND id_conciliacion IN ($marks)")
        ->execute(array_merge([$idEmpresa],$ids));

    $motivo='Conciliación revertida: la factura o el complemento de pago aparece cancelado ante el SAT.';
    foreach(array_keys($dispersiones) as $idDisp){
        cp_marcar_dispersion_estado($pdo,$idEmpresa,(int)$idDisp,'NO_CONCILIADO',$motivo,$idUsuario,0);
    }
    foreach(array_keys($detalles) as $idDet){
        cp_recalcular_detalle($pdo,$idEmpresa,(int)$idDet);
        cp_marcar_detalle_estado($pdo,$idEmpresa,(int)$idDet,'NO_CONCILIADO',$motivo);
    }
    foreach(array_keys($facturas) as $uuid){
        cp_recalcular_factura($pdo,$idEmpresa,(string)$uuid);
    }

    return [
        'retiradas'=>count($ids),
        'facturas_canceladas'=>count($facturasCanceladas),
        'rep_cancelados'=>count($repCancelados)
    ];
}

/** Cuenta dispersiones pendientes y vigentes para el periodo. */
function cp_total_pendiente_periodo(PDO $pdo, int $idEmpresa, string $desde, string $hasta): int {
    // El medidor revisa todas las dispersiones del mes una por una, incluidas las
    // ya conciliadas y canceladas, para reflejar su estado actual sin duplicar.
    $st=$pdo->prepare("SELECT COUNT(DISTINCT id_contpaq_dispersion)
                        FROM contpaq_dispersiones_pagos
                       WHERE id_empresa=? AND fecha_pago>=? AND fecha_pago<DATE_ADD(?, INTERVAL 1 DAY)");
    $st->execute([$idEmpresa,$desde,$hasta]);
    return (int)$st->fetchColumn();
}

/**
 * Procesa un bloque de dispersiones del periodo. El cursor es el último
 * id_contpaq_dispersion revisado, por lo que puede reanudarse sin OFFSET.
 */
function cp_marcar_dispersion_estado(PDO $pdo, int $idEmpresa, int $idDispersion, string $estatus, string $observacion, ?int $idUsuario=null, int $conciliado=0): void {
    $pdo->prepare("UPDATE contpaq_dispersiones_pagos
                      SET conciliado=?, estatus_conciliacion=?, fecha_conciliacion=NOW(),
                          id_usuario_concilia=?, observaciones=?
                    WHERE id_empresa=? AND id_contpaq_dispersion=?")
        ->execute([$conciliado,$estatus,$idUsuario,$observacion,$idEmpresa,$idDispersion]);
}

function cp_marcar_detalle_estado(PDO $pdo, int $idEmpresa, int $idDetalle, string $estatus, string $observacion): void {
    $sql="UPDATE facturas_pagos_detalles
             SET estatus_conciliacion_contpaq=?,
                 observaciones_conciliacion_contpaq=?,
                 fecha_ultima_conciliacion_contpaq=NOW()
           WHERE id_empresa=? AND id_detalle=?";
    $pdo->prepare($sql)->execute([$estatus,$observacion,$idEmpresa,$idDetalle]);
}

/**
 * Revierte, de forma idempotente, la aplicación de una dispersión concreta.
 * Se usa cuando el movimiento CONTPAQ, la factura o el REP cambian a cancelado.
 * Nunca resta acumulados manualmente: elimina la relación y recalcula desde lo vigente.
 */
function cp_revertir_dispersion_actual(PDO $pdo, int $idEmpresa, int $idDispersion, string $motivo, ?int $idUsuario=null): int {
    $st=$pdo->prepare("SELECT id_conciliacion,id_detalle,uuid_factura
                        FROM conciliacion_pagos_detalle
                       WHERE id_empresa=? AND id_contpaq_dispersion=?");
    $st->execute([$idEmpresa,$idDispersion]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return 0;

    $detalles=[]; $facturas=[];
    foreach($rows as $r){
        $detalles[(int)$r['id_detalle']]=true;
        $u=cp_normalizar_uuid($r['uuid_factura']??'');
        if($u!=='') $facturas[$u]=true;
    }
    $pdo->prepare("DELETE FROM conciliacion_pagos_detalle WHERE id_empresa=? AND id_contpaq_dispersion=?")
        ->execute([$idEmpresa,$idDispersion]);

    foreach(array_keys($detalles) as $idDet){
        cp_recalcular_detalle($pdo,$idEmpresa,(int)$idDet);
        cp_marcar_detalle_estado($pdo,$idEmpresa,(int)$idDet,'NO_CONCILIADO',$motivo);
    }
    foreach(array_keys($facturas) as $uuid){
        cp_recalcular_factura($pdo,$idEmpresa,(string)$uuid);
    }
    cp_marcar_dispersion_estado($pdo,$idEmpresa,$idDispersion,'CANCELADO',$motivo,$idUsuario,0);
    return count($rows);
}

/**
 * Procesa exactamente una dispersión por llamada. Nunca aborta el mes por una
 * dispersión sin cruce: devuelve su resultado y el cliente continúa con la siguiente.
 */
function cp_conciliar_periodo_bloque(
    PDO $pdo,
    int $idEmpresa,
    int $idUsuario,
    string $desde,
    string $hasta,
    int $cursor=0,
    int $limite=1
): array {
    cp_schema_conciliacion($pdo);
    $t0 = microtime(true);
    $tiempos = [];
    $marca = static function(string $nombre, float $inicio) use (&$tiempos): float {
        $ahora = microtime(true);
        $tiempos[$nombre] = round($ahora - $inicio, 6);
        return $ahora;
    };
    $fase = microtime(true);

    $sql="SELECT d.id_contpaq_dispersion,d.tipo_origen,d.id_contpaq_cheque,d.id_contpaq_egreso,
                 d.uuid,d.uuid_rep,d.fecha_pago,d.total_pago,d.total_pago_comprobante,d.tipo_cambio,
                 COALESCE(NULLIF(d.moneda_contpaq,''),ch.codigo_moneda,eg.codigo_moneda) moneda_contpaq,
                 COALESCE(ch.es_cancelado,eg.es_cancelado,0) es_cancelado,
                 COALESCE(ch.folio,eg.folio,d.origen_folio) folio,
                 COALESCE(ch.beneficiario_pagador,eg.beneficiario_pagador,d.beneficiario_pagador) beneficiario
            FROM contpaq_dispersiones_pagos d
            LEFT JOIN contpaq_cheques ch
              ON d.tipo_origen='C' AND ch.id_empresa=d.id_empresa AND ch.id_contpaq_cheque=d.id_contpaq_cheque
            LEFT JOIN contpaq_egresos eg
              ON d.tipo_origen='T' AND eg.id_empresa=d.id_empresa AND eg.id_contpaq_egreso=d.id_contpaq_egreso
           WHERE d.id_empresa=?
             AND d.fecha_pago>=? AND d.fecha_pago<DATE_ADD(?, INTERVAL 1 DAY)
             AND d.id_contpaq_dispersion>?
           ORDER BY d.id_contpaq_dispersion
           LIMIT 1";
    $st=$pdo->prepare($sql);
    $st->execute([$idEmpresa,$desde,$hasta,$cursor]);
    $d=$st->fetch(PDO::FETCH_ASSOC);
    $fase=$marca('buscar_dispersion',$fase);
    if(!$d){
        return ['done'=>true,'cursor'=>$cursor,'procesadas'=>0,'revisadas'=>0,'creadas'=>0,'parciales'=>0,
                'sin_coincidencia'=>0,'ya_conciliadas'=>0,'facturas'=>0,'errores'=>0,'canceladas'=>0,
                'resultado'=>'FINALIZADO','mensaje'=>'No quedan dispersiones por revisar.','tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)];
    }

    $disp=(int)$d['id_contpaq_dispersion'];
    $base=['done'=>false,'cursor'=>$disp,'procesadas'=>1,'revisadas'=>1,'creadas'=>0,'parciales'=>0,
           'sin_coincidencia'=>0,'ya_conciliadas'=>0,'facturas'=>0,'errores'=>0,'canceladas'=>0,
           'id_dispersion'=>$disp,'uuid'=>cp_normalizar_uuid($d['uuid']),'uuid_rep'=>cp_normalizar_uuid($d['uuid_rep']),
           'fecha_pago'=>cp_fecha($d['fecha_pago']),'importe'=>(float)($d['total_pago'] ?: $d['total_pago_comprobante']),
           'folio'=>(string)($d['folio']??''),'beneficiario'=>(string)($d['beneficiario']??'')];

    if((int)$d['es_cancelado']===1){
        $motivo='Movimiento CONTPAQ cancelado; se revirtió cualquier aplicación anterior.';
        $revertidas=cp_revertir_dispersion_actual($pdo,$idEmpresa,$disp,$motivo,$idUsuario);
        if($revertidas===0) cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'CANCELADO',$motivo,$idUsuario,0);
        $marca('validar_cancelado',$fase);
        return array_merge($base,['canceladas'=>1,'canceladas_retiradas'=>$revertidas,'resultado'=>'CANCELADO','mensaje'=>$motivo,
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    $uuid=cp_normalizar_uuid($d['uuid']);
    $rep=cp_normalizar_uuid($d['uuid_rep']);
    if($uuid===''){
        cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO','La dispersión no contiene UUID de factura.',$idUsuario,0);
        $marca('validar_uuid',$fase);
        return array_merge($base,['sin_coincidencia'=>1,'resultado'=>'NO_CONCILIADO','mensaje'=>'Sin UUID de factura en CONTPAQ.',
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    // Antes de conciliar, validar el estado SAT actual de la factura. Si se canceló,
    // se informa y no se vuelve a aplicar ningún importe.
    $sf=$pdo->prepare("SELECT estatus_sat,fecha_cancelacion FROM facturas WHERE uuid=? AND id_empresa=? LIMIT 1");
    $sf->execute([$uuid,$idEmpresa]);
    $estadoFactura=$sf->fetch(PDO::FETCH_ASSOC);
    if($estadoFactura && cp_cfdi_cancelado($estadoFactura['estatus_sat']??null,$estadoFactura['fecha_cancelacion']??null)){
        $motivo='Factura cancelada ante el SAT; se revirtió cualquier aplicación anterior.';
        $revertidas=cp_revertir_dispersion_actual($pdo,$idEmpresa,$disp,$motivo,$idUsuario);
        if($revertidas===0) cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO',$motivo,$idUsuario,0);
        $marca('validar_factura_cancelada',$fase);
        return array_merge($base,['sin_coincidencia'=>1,'canceladas_retiradas'=>$revertidas,'resultado'=>'NO_CONCILIADO','mensaje'=>$motivo,
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    if($rep!==''){
        $sr=$pdo->prepare("SELECT estatus_sat,fecha_cancelacion FROM facturas WHERE uuid=? AND id_empresa=? LIMIT 1");
        $sr->execute([$rep,$idEmpresa]);
        $estadoRep=$sr->fetch(PDO::FETCH_ASSOC);
        if($estadoRep && cp_cfdi_cancelado($estadoRep['estatus_sat']??null,$estadoRep['fecha_cancelacion']??null)){
            $motivo='Complemento REP cancelado ante el SAT; se revirtió cualquier aplicación anterior.';
            $revertidas=cp_revertir_dispersion_actual($pdo,$idEmpresa,$disp,$motivo,$idUsuario);
            if($revertidas===0) cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO',$motivo,$idUsuario,0);
            $marca('validar_rep_cancelado',$fase);
            return array_merge($base,['sin_coincidencia'=>1,'canceladas_retiradas'=>$revertidas,'resultado'=>'NO_CONCILIADO','mensaje'=>$motivo,
                'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
        }
    }

    // La revisión de duplicado se hace después de validar cancelaciones actuales.
    // Así, una aplicación vieja se revierte si el movimiento, factura o REP cambió a cancelado.
    $ex=$pdo->prepare("SELECT id_detalle,estatus FROM conciliacion_pagos_detalle WHERE id_empresa=? AND id_contpaq_dispersion=? LIMIT 1");
    $ex->execute([$idEmpresa,$disp]);
    $ya=$ex->fetch(PDO::FETCH_ASSOC);
    $fase=$marca('verificar_duplicado',$fase);
    if($ya){
        cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,(string)$ya['estatus'],'Aplicación ya existente; no se duplicó.',$idUsuario,1);
        return array_merge($base,['ya_conciliadas'=>1,'resultado'=>'YA_CONCILIADO','mensaje'=>'La dispersión ya estaba conciliada; no se duplicó.',
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    // Al comparar contra parámetros PHP evitamos cruces columna-contra-columna de collations distintas
    // y MySQL puede utilizar los índices de uuid_relacionado y uuid_pago.
    $sqlDet="SELECT p.id_detalle,p.rfc_emisor,p.uuid_pago,p.uuid_relacionado,p.monto_pagado,p.importe_aplicado,p.fecha_pago_sat,
                    p.moneda_factura,p.tc_factura,p.moneda_pago_sat,p.tc_pago_sat,
                    COALESCE((SELECT SUM(c.importe_conciliado)
                                FROM conciliacion_pagos_detalle c
                               WHERE c.id_empresa=p.id_empresa AND c.id_detalle=p.id_detalle),0) ya_detalle
               FROM facturas_pagos_detalles p
              WHERE p.id_empresa=? AND p.es_sintetico=0 AND p.uuid_relacionado=?";
    $params=[$idEmpresa,$uuid];
    if($rep!==''){
        $sqlDet.=" AND p.uuid_pago=?";
        $params[]=$rep;
    }
    // No filtramos ni ordenamos por fecha de factura o del complemento.
    // El periodo únicamente selecciona las dispersiones CONTPAQ a procesar.
    $sqlDet.=" ORDER BY p.id_detalle";
    $sd=$pdo->prepare($sqlDet);
    $sd->execute($params);
    $candidatos=$sd->fetchAll(PDO::FETCH_ASSOC);
    $fase=$marca('buscar_detalles_sat',$fase);

    if(!$candidatos){
        cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO',
            $rep!==''?'No se encontró detalle SAT con UUID de factura y UUID REP.':'No se encontró detalle SAT con el UUID de factura.',$idUsuario,0);
        return array_merge($base,['sin_coincidencia'=>1,'resultado'=>'NO_CONCILIADO','mensaje'=>'No se encontró un detalle de complemento compatible.',
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    $impContpaq=abs((float)$d['total_pago']);
    if($impContpaq<=0.0001) $impContpaq=abs((float)$d['total_pago_comprobante']);
    if($impContpaq<=0.0001){
        foreach($candidatos as $cand) cp_marcar_detalle_estado($pdo,$idEmpresa,(int)$cand['id_detalle'],'NO_CONCILIADO','El pago CONTPAQ tiene importe cero.');
        cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO','El pago CONTPAQ tiene importe cero.',$idUsuario,0);
        $fase=$marca('validar_importe',$fase);
        return array_merge($base,['sin_coincidencia'=>1,'resultado'=>'NO_CONCILIADO','mensaje'=>'Importe CONTPAQ en cero.',
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    $seleccion=null;
    $mejorDiferencia=null;
    foreach($candidatos as $cand){
        $esperado=(float)($cand['importe_aplicado'] ?: $cand['monto_pagado']);
        $restante=max(0,round($esperado-(float)$cand['ya_detalle'],4));
        if($restante<=0.01) continue;

        // Si un UUID aparece en más de un detalle, elegimos el saldo pendiente
        // más cercano al importe CONTPAQ. La fecha no participa en el cruce.
        $difImporte=abs($restante-$impContpaq);
        if($seleccion===null || $difImporte<$mejorDiferencia){
            $seleccion=$cand;
            $seleccion['_esperado']=$esperado;
            $seleccion['_restante']=$restante;
            $mejorDiferencia=$difImporte;
        }
    }
    $fase=$marca('seleccionar_detalle',$fase);
    if(!$seleccion){
        cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO','Los detalles SAT localizados ya están cubiertos por otras aplicaciones.',$idUsuario,0);
        return array_merge($base,['sin_coincidencia'=>1,'resultado'=>'NO_CONCILIADO','mensaje'=>'Los detalles SAT relacionados ya están totalmente conciliados.',
            'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
    }

    // El complemento REP también puede haberse cancelado después de la primera
    // conciliación. Se valida su UUID real del detalle SAT antes de guardar.
    $uuidPagoSat=cp_normalizar_uuid($seleccion['uuid_pago']??'');
    if($uuidPagoSat!==''){
        $sr=$pdo->prepare("SELECT estatus_sat,fecha_cancelacion FROM facturas WHERE uuid=? AND id_empresa=? LIMIT 1");
        $sr->execute([$uuidPagoSat,$idEmpresa]);
        $estadoRep=$sr->fetch(PDO::FETCH_ASSOC);
        if($estadoRep && cp_cfdi_cancelado($estadoRep['estatus_sat']??null,$estadoRep['fecha_cancelacion']??null)){
            cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,'NO_CONCILIADO','Complemento de pago REP cancelado ante el SAT; no es conciliable.',$idUsuario,0);
            cp_marcar_detalle_estado($pdo,$idEmpresa,(int)$seleccion['id_detalle'],'NO_CONCILIADO','Complemento REP cancelado ante el SAT.');
            $marca('validar_rep_cancelado',$fase);
            return array_merge($base,['sin_coincidencia'=>1,'resultado'=>'NO_CONCILIADO','mensaje'=>'El complemento REP relacionado está cancelado ante el SAT.',
                'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6)]);
        }
    }

    $idDet=(int)$seleccion['id_detalle'];
    $esperado=(float)$seleccion['_esperado'];
    $restante=(float)$seleccion['_restante'];
    $importe=min($restante,$impContpaq);
    $dias=null;
    if($d['fecha_pago'] && $seleccion['fecha_pago_sat']){
        $dias=(int)((new DateTime(cp_fecha($d['fecha_pago'])))->diff(new DateTime(cp_fecha($seleccion['fecha_pago_sat'])))->format('%r%a'));
    }
    $estatus=($importe+0.01 >= $restante)?'CONCILIADO':'PARCIAL';
    $dif=round($esperado-$importe,4);
    $obs=$rep!==''?'Cruce automático por empresa + UUID de factura + UUID REP; las fechas se conservan solo para comparación.':'Cruce automático por empresa + UUID de factura; CONTPAQ no proporcionó UUID REP y las fechas se conservan solo para comparación.';
    $ins=$pdo->prepare("INSERT INTO conciliacion_pagos_detalle
       (id_empresa,id_detalle,id_contpaq_dispersion,tipo_origen,id_contpaq_cheque,id_contpaq_egreso,
        rfc_emisor,uuid_factura,uuid_pago_sat,uuid_rep_contpaq,fecha_pago_sat,fecha_pago_contpaq,
        moneda_factura,tc_factura,moneda_pago_sat,tc_pago_sat,moneda_contpaq,tc_contpaq,
        importe_sat,importe_contpaq,importe_conciliado,diferencia_importe,diferencia_dias,
        origen_conciliacion,estatus,id_usuario_concilia,observaciones)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE id_detalle=VALUES(id_detalle),rfc_emisor=VALUES(rfc_emisor),fecha_pago_contpaq=VALUES(fecha_pago_contpaq),
          moneda_contpaq=VALUES(moneda_contpaq),tc_contpaq=VALUES(tc_contpaq),importe_contpaq=VALUES(importe_contpaq),
          importe_conciliado=VALUES(importe_conciliado),diferencia_importe=VALUES(diferencia_importe),
          diferencia_dias=VALUES(diferencia_dias),estatus=VALUES(estatus),id_usuario_concilia=VALUES(id_usuario_concilia),
          fecha_conciliacion=NOW(),observaciones=VALUES(observaciones)");
    $ins->execute([$idEmpresa,$idDet,$disp,$d['tipo_origen'],$d['id_contpaq_cheque'],$d['id_contpaq_egreso'],
        strtoupper(trim((string)($seleccion['rfc_emisor'] ?? ''))) ?: null,
        cp_normalizar_uuid($seleccion['uuid_relacionado']),cp_normalizar_uuid($seleccion['uuid_pago']),$rep?:null,
        cp_fecha($seleccion['fecha_pago_sat']),cp_fecha($d['fecha_pago']),$seleccion['moneda_factura'],$seleccion['tc_factura'],
        $seleccion['moneda_pago_sat'],$seleccion['tc_pago_sat'],$d['moneda_contpaq'],$d['tipo_cambio'],
        $esperado,$impContpaq,$importe,$dif,$dias,'AUTOMATICA',$estatus,$idUsuario,$obs]);
    $fase=$marca('guardar_aplicacion',$fase);

    cp_marcar_dispersion_estado($pdo,$idEmpresa,$disp,$estatus,$obs,$idUsuario,1);
    $fase=$marca('actualizar_estatus_dispersion',$fase);
    cp_recalcular_detalle($pdo,$idEmpresa,$idDet);
    $fase=$marca('recalcular_detalle',$fase);
    cp_marcar_detalle_estado($pdo,$idEmpresa,$idDet,$estatus,$obs);
    $fase=$marca('actualizar_estatus_detalle',$fase);
    cp_recalcular_factura($pdo,$idEmpresa,cp_normalizar_uuid($seleccion['uuid_relacionado']));
    $marca('recalcular_factura',$fase);

    return array_merge($base,['creadas'=>1,'parciales'=>$estatus==='PARCIAL'?1:0,'facturas'=>1,
                  'resultado'=>$estatus,'mensaje'=>$obs,'id_detalle'=>$idDet,
                  'tiempos'=>$tiempos,'segundos_total'=>round(microtime(true)-$t0,6),
                  'detalle_sat_encontrado'=>true,'importe_sat'=>$esperado,'importe_aplicado'=>$importe,
                  'diferencia_importe'=>$dif]);
}

