<?php
/**
 * Control general de relaciones CFDI y sustituciones TipoRelacion=04.
 * No borra historial: marca los detalles del complemento anterior como sustituidos.
 */

function cfdi_uuid_normalizar($v): string {
    return strtoupper(trim((string)$v));
}

function cfdi_extraer_relaciones(SimpleXMLElement $xml): array {
    $rels=[];
    if (!isset($xml->CfdiRelacionados)) return $rels;
    foreach ($xml->CfdiRelacionados as $grupo) {
        $tipo=trim((string)($grupo['TipoRelacion'] ?? ''));
        if ($tipo==='') continue;
        foreach ($grupo->CfdiRelacionado as $r) {
            $u=cfdi_uuid_normalizar($r['UUID'] ?? '');
            if ($u!=='') $rels[]=['tipo_relacion'=>$tipo,'uuid_relacionado'=>$u];
        }
    }
    return $rels;
}

function cfdi_guardar_relaciones(PDO $pdo, int $idEmpresa, string $uuidOrigen, array $relaciones): void {
    $uuidOrigen=cfdi_uuid_normalizar($uuidOrigen);
    if ($idEmpresa<=0 || $uuidOrigen==='') return;
    $st=$pdo->prepare("INSERT INTO cfdi_relaciones (id_empresa,uuid_origen,uuid_relacionado,tipo_relacion)
                       VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE fecha_registro=fecha_registro");
    foreach($relaciones as $r){
        $u=cfdi_uuid_normalizar($r['uuid_relacionado'] ?? '');
        $t=trim((string)($r['tipo_relacion'] ?? ''));
        if($u==='' || $t==='') continue;
        $st->execute([$idEmpresa,$uuidOrigen,$u,$t]);
    }
}

/** Devuelve facturas relacionadas afectadas por cambiar el estado de uno o más UUID de pago. */
function cfdi_sincronizar_sustituciones_pago(PDO $pdo, int $idEmpresa, ?array $uuidsPago=null): array {
    if($idEmpresa<=0) return [];
    $params=[$idEmpresa]; $where='d.id_empresa=? AND d.es_sintetico=0';
    if($uuidsPago){
        $uuids=array_values(array_unique(array_filter(array_map('cfdi_uuid_normalizar',$uuidsPago))));
        if($uuids){
            $where .= ' AND UPPER(d.uuid_pago) IN ('.implode(',',array_fill(0,count($uuids),'?')).')';
            $params=array_merge($params,$uuids);
        }
    }

    $st=$pdo->prepare("SELECT DISTINCT UPPER(d.uuid_relacionado) uuid_relacionado FROM facturas_pagos_detalles d WHERE $where");
    $st->execute($params);
    $afectadas=array_values(array_filter(array_map(fn($r)=>$r['uuid_relacionado']??'', $st->fetchAll(PDO::FETCH_ASSOC))));

    // Para cada detalle SAT: si su uuid_pago aparece como relacionado de un CFDI 04, queda sustituido.
    $sql="UPDATE facturas_pagos_detalles d
          LEFT JOIN (
              SELECT r.id_empresa,r.uuid_relacionado,r.uuid_origen AS uuid_sustituto
              FROM cfdi_relaciones r
              INNER JOIN (
                  SELECT id_empresa,uuid_relacionado,MAX(id_relacion) id_relacion
                  FROM cfdi_relaciones
                  WHERE tipo_relacion='04' AND id_empresa=?
                  GROUP BY id_empresa,uuid_relacionado
              ) u ON u.id_relacion=r.id_relacion
          ) x ON x.id_empresa=d.id_empresa AND UPPER(x.uuid_relacionado) COLLATE utf8mb4_general_ci = UPPER(d.uuid_pago) COLLATE utf8mb4_general_ci
          SET d.es_sustituido=CASE WHEN x.uuid_sustituto IS NULL THEN 0 ELSE 1 END,
              d.uuid_sustituto=x.uuid_sustituto,
              d.aplicado=CASE WHEN x.uuid_sustituto IS NULL THEN d.aplicado ELSE 0 END
          WHERE $where";
    // El primer parámetro corresponde al subquery; después van los del WHERE.
    $params2=[$idEmpresa];
    $paramsWhere=[$idEmpresa];
    if($uuidsPago && !empty($uuids)) $paramsWhere=array_merge($paramsWhere,$uuids);
    $params2=array_merge($params2,$paramsWhere);
    $up=$pdo->prepare($sql); $up->execute($params2);
    return $afectadas;
}

function cfdi_estado_pago(PDO $pdo, int $idEmpresa, string $uuidPago): array {
    $uuidPago=cfdi_uuid_normalizar($uuidPago);
    $st=$pdo->prepare("SELECT f.estatus_sat,
                             CASE WHEN EXISTS(SELECT 1 FROM cfdi_relaciones r WHERE r.id_empresa=f.id_empresa AND r.tipo_relacion='04' AND UPPER(r.uuid_relacionado) COLLATE utf8mb4_general_ci = UPPER(f.uuid) COLLATE utf8mb4_general_ci) THEN 1 ELSE 0 END es_sustituido,
                             (SELECT r.uuid_origen FROM cfdi_relaciones r WHERE r.id_empresa=f.id_empresa AND r.tipo_relacion='04' AND UPPER(r.uuid_relacionado) COLLATE utf8mb4_general_ci = UPPER(f.uuid) COLLATE utf8mb4_general_ci ORDER BY r.id_relacion DESC LIMIT 1) uuid_sustituto,
                             CASE WHEN EXISTS(SELECT 1 FROM cfdi_relaciones r WHERE r.id_empresa=f.id_empresa AND r.tipo_relacion='04' AND UPPER(r.uuid_origen) COLLATE utf8mb4_general_ci = UPPER(f.uuid) COLLATE utf8mb4_general_ci) THEN 1 ELSE 0 END es_sustituto
                      FROM facturas f WHERE f.id_empresa=? AND UPPER(f.uuid)=UPPER(?) LIMIT 1");
    $st->execute([$idEmpresa,$uuidPago]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['estatus_sat'=>null,'es_sustituido'=>0,'uuid_sustituto'=>null,'es_sustituto'=>0];
}
?>
