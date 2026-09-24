<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/conciliacion_financiera.php';

function oum_doc(PDO $pdo,int $idEmpresa,string $tipo,int $id): array {
    if($tipo==='FACTURA'){
        $st=$pdo->prepare("SELECT id,oracle_invoice_id,invoice_number,supplier,supplier_tax_registration_number rfc,invoice_amount importe,invoice_currency moneda,invoice_date fecha,uuid_cfdi FROM oracle_facturas WHERE id_empresa=? AND id=? LIMIT 1");
    }else{
        $st=$pdo->prepare("SELECT id,oracle_expense_id,COALESCE(NULLIF(reference_number,''),NULLIF(expense_reference,''),oracle_expense_id) invoice_number,COALESCE(NULLIF(merchant_name,''),person_name) supplier,merchant_taxpayer_id rfc,receipt_amount importe,receipt_currency_code moneda,DATE(COALESCE(receipt_date,creation_date)) fecha,uuid_cfdi FROM oracle_gastos WHERE id_empresa=? AND id=? LIMIT 1");
    }
    $st->execute([$idEmpresa,$id]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new RuntimeException('No se encontró el documento Oracle.');
    return $r;
}
function oum_norm_rfc($v): string { return strtoupper(preg_replace('/\s+/','',trim((string)$v))); }
function oum_fecha_ok(string $f): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f); return $d!==false && $d->format('Y-m-d')===$f; }
function oum_rfc_por_nombre(PDO $pdo,int $idEmpresa,string $nombre): ?array {
    $nombre=trim($nombre);
    if(mb_strlen($nombre)<5) return null;
    // Busca coincidencia exacta o por inclusión en ambos sentidos. Se exige un nombre
    // suficientemente largo para evitar empates por palabras genéricas.
    $sql="SELECT rfc,nombre,
                 CASE
                   WHEN UPPER(TRIM(nombre))=UPPER(TRIM(?)) THEN 100
                   WHEN UPPER(?) LIKE CONCAT('%',UPPER(TRIM(nombre)),'%') THEN 80
                   WHEN UPPER(TRIM(nombre)) LIKE CONCAT('%',UPPER(?),'%') THEN 70
                   ELSE 0
                 END puntaje
            FROM cat_emisores
           WHERE id_empresa=?
             AND COALESCE(TRIM(rfc),'')<>''
             AND CHAR_LENGTH(TRIM(nombre))>=5
             AND (
                  UPPER(TRIM(nombre))=UPPER(TRIM(?))
                  OR UPPER(?) LIKE CONCAT('%',UPPER(TRIM(nombre)),'%')
                  OR UPPER(TRIM(nombre)) LIKE CONCAT('%',UPPER(?),'%')
             )
           ORDER BY puntaje DESC, CHAR_LENGTH(TRIM(nombre)) DESC
           LIMIT 2";
    $st=$pdo->prepare($sql);
    $st->execute([$nombre,$nombre,$nombre,$idEmpresa,$nombre,$nombre,$nombre]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return null;
    // Si las dos mejores coincidencias tienen el mismo puntaje, no adivina: se captura a mano.
    if(count($rows)>1 && (int)$rows[0]['puntaje']===(int)$rows[1]['puntaje']) return null;
    return $rows[0];
}

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)($_SESSION['id_usuario']??0); $e=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$u,$e,'oracle_pagos');
    $accion=strtolower(trim((string)($_REQUEST['accion']??'info')));
    $tipo=strtoupper(trim((string)($_REQUEST['tipo_documento']??'')));
    $id=(int)($_REQUEST['id_documento']??0);
    if(!in_array($tipo,['FACTURA','EXP_DETALLE'],true) || $id<=0) throw new RuntimeException('Documento Oracle no válido.');
    $doc=oum_doc($pdo,$e,$tipo,$id);

    if($accion==='info'){
        $m=cf_uuid_manual($pdo,$e,$tipo,$id);
        $sugerido=null;
        if(oum_norm_rfc($doc['rfc']??'')==='') $sugerido=oum_rfc_por_nombre($pdo,$e,(string)($doc['supplier']??''));
        echo json_encode(['success'=>true,'documento'=>$doc,'manual'=>$m,'rfc_sugerido'=>$sugerido],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }

    if($accion==='buscar'){
        $rfc=oum_norm_rfc($_GET['rfc']??$doc['rfc']??'');
        if($rfc==='') throw new RuntimeException('Capture el RFC para buscar CFDI compatibles.');
        // El RFC + rango son la base de la búsqueda. Nombre es solo informativo y no restringe.
        $uuid=cf_normalizar_uuid($_GET['uuid']??'');
        $importeTxt=trim((string)($_GET['importe']??''));
        $importe=$importeTxt===''?null:(float)str_replace(',','',$importeTxt);
        $desde=trim((string)($_GET['desde']??'')); $hasta=trim((string)($_GET['hasta']??''));
        if(!oum_fecha_ok($desde)||!oum_fecha_ok($hasta)||$desde>$hasta) throw new RuntimeException('Capture un rango de fechas válido.');

        $se=$pdo->prepare("SELECT id_emisor FROM cat_emisores WHERE id_empresa=? AND UPPER(TRIM(rfc))=?");
        $se->execute([$e,$rfc]); $ids=$se->fetchAll(PDO::FETCH_COLUMN);
        if(!$ids){ echo json_encode(['success'=>true,'data'=>[]],JSON_UNESCAPED_UNICODE); exit; }
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $params=array_merge([$e],$ids,[$desde,$hasta.' 23:59:59']);
        $where="f.id_empresa=? AND f.id_emisor IN ($ph) AND f.fecha_emision>=? AND f.fecha_emision<=? AND COALESCE(f.tipo_movimiento_empresa,'E')='E'";
        if($uuid!==''){
            // Con UUID parcial, el importe NO bloquea resultados: se usa para ordenar y mostrar diferencia.
            $where.=" AND f.uuid LIKE ?"; $params[]='%'.$uuid.'%';
        }elseif($importe!==null){
            // Sin UUID parcial, buscar candidatos dentro de una tolerancia de ±$5.00.
            // La tolerancia es SOLO para encontrar candidatos; al ligar cualquier diferencia distinta de 0.00 requiere confirmación.
            $where.=" AND COALESCE(f.total_xml,0) BETWEEN (? - 5.00) AND (? + 5.00)";
            $params[]=$importe;
            $params[]=$importe;
        }
        $sql="SELECT f.uuid,f.fecha_emision,e.rfc,e.nombre emisor,f.serie,f.folio,f.total_xml,f.moneda,f.metodo_pago,f.forma_pago,f.estatus_sat,f.id_tipo_comprobante,
                     ROUND(COALESCE(f.total_xml,0)-?,2) diferencia_importe, ? importe_oracle
                FROM facturas f INNER JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa
               WHERE $where
               ORDER BY ABS(COALESCE(f.total_xml,0)-?),ABS(DATEDIFF(DATE(f.fecha_emision),?)),f.fecha_emision DESC LIMIT 100";
        $importeOrden=$importe??(float)$doc['importe'];
        $params=array_merge([$importeOrden,$importeOrden],$params,[$importeOrden,$doc['fecha']?:$desde]);
        $st=$pdo->prepare($sql); $st->execute($params);
        echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }

    if($accion==='guardar'){
        $uuid=cf_normalizar_uuid($_POST['uuid']??'');
        $rfcCapt=oum_norm_rfc($_POST['rfc']??'');
        if(strlen($uuid)!==36) throw new RuntimeException('Seleccione un UUID válido.');
        $st=$pdo->prepare("SELECT f.uuid,f.total_xml,f.moneda,f.fecha_emision,f.estatus_sat,e.rfc,e.nombre FROM facturas f INNER JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa WHERE f.id_empresa=? AND f.uuid=? LIMIT 1");
        $st->execute([$e,$uuid]); $xml=$st->fetch(PDO::FETCH_ASSOC);
        if(!$xml) throw new RuntimeException('El CFDI seleccionado ya no existe en el visor.');
        $rfcDoc=oum_norm_rfc($doc['rfc']??'');
        $rfcEsperado=$rfcCapt!==''?$rfcCapt:$rfcDoc;
        if($rfcEsperado==='') throw new RuntimeException('Capture el RFC antes de ligar el CFDI.');
        if(oum_norm_rfc($xml['rfc'])!==$rfcEsperado) throw new RuntimeException('El RFC del CFDI no coincide con el RFC del documento/pago Oracle.');
        $dif=round((float)$xml['total_xml']-(float)$doc['importe'],2);
        $forzar=(int)($_POST['aceptar_diferencia']??0)===1;
        if(abs($dif)>0.0001 && !$forzar){
            echo json_encode(['success'=>false,'confirmar_diferencia'=>true,'importe_oracle'=>(float)$doc['importe'],'importe_xml'=>(float)$xml['total_xml'],'diferencia'=>$dif,'uuid'=>$uuid],JSON_UNESCAPED_UNICODE); exit;
        }
        $monO=strtoupper(trim((string)($doc['moneda']??''))); $monX=strtoupper(trim((string)($xml['moneda']??'')));
        if($monO!=='' && $monX!=='' && $monO!==$monX) throw new RuntimeException('La moneda del CFDI no coincide con la moneda Oracle.');
        $dup=$pdo->prepare("SELECT tipo_documento,id_documento_origen FROM oracle_conciliacion_uuid_manual WHERE id_empresa=? AND uuid=? AND activo=1 AND NOT(tipo_documento=? AND id_documento_origen=?) LIMIT 1");
        $dup->execute([$e,$uuid,$tipo,$id]);
        if($dup->fetch()) throw new RuntimeException('Ese UUID ya está ligado manualmente a otro documento Oracle.');
        $obs=trim((string)($_POST['observaciones']??''));
        $sql="INSERT INTO oracle_conciliacion_uuid_manual(id_empresa,tipo_documento,id_documento_origen,uuid,rfc_validado,importe_origen,importe_xml,moneda_origen,moneda_xml,observaciones,activo,id_usuario)
              VALUES(?,?,?,?,?,?,?,?,?,?,1,?)
              ON DUPLICATE KEY UPDATE uuid=VALUES(uuid),rfc_validado=VALUES(rfc_validado),importe_origen=VALUES(importe_origen),importe_xml=VALUES(importe_xml),moneda_origen=VALUES(moneda_origen),moneda_xml=VALUES(moneda_xml),observaciones=VALUES(observaciones),activo=1,id_usuario=VALUES(id_usuario),fecha_actualizacion=NOW()";
        $pdo->prepare($sql)->execute([$e,$tipo,$id,$uuid,$rfcEsperado,(float)$doc['importe'],(float)$xml['total_xml'],$monO?:null,$monX?:null,$obs?:null,$u?:null]);
        // La liga manual y una clasificación NO-CFDI son excluyentes.
        $pdo->prepare("DELETE FROM oracle_conciliacion_clasificaciones WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=?")->execute([$e,$tipo,$id]);
        $tabla=$tipo==='FACTURA'?'oracle_facturas':'oracle_gastos';
        $pdo->prepare("UPDATE {$tabla} SET conciliado=0,estatus_conciliacion='PENDIENTE',fecha_conciliacion=NULL WHERE id_empresa=? AND id=?")->execute([$e,$id]);
        $doc2=oum_doc($pdo,$e,$tipo,$id);
        $res=$tipo==='FACTURA'?cf_conciliar_oracle_factura($pdo,$e,$doc2,$u):cf_conciliar_oracle_gasto($pdo,$e,$doc2,$u);
        echo json_encode(['success'=>true,'estado'=>$res['estado']??'PENDIENTE','uuid'=>$uuid],JSON_UNESCAPED_UNICODE); exit;
    }

    if($accion==='quitar'){
        $pdo->prepare("UPDATE oracle_conciliacion_uuid_manual SET activo=0,id_usuario=?,fecha_actualizacion=NOW() WHERE id_empresa=? AND tipo_documento=? AND id_documento_origen=?")->execute([$u?:null,$e,$tipo,$id]);
        cf_limpiar_oracle_documento($pdo,$e,$tipo,$id);
        $tabla=$tipo==='FACTURA'?'oracle_facturas':'oracle_gastos';
        $pdo->prepare("UPDATE {$tabla} SET conciliado=0,estatus_conciliacion='PENDIENTE',fecha_conciliacion=NULL WHERE id_empresa=? AND id=?")->execute([$e,$id]);
        echo json_encode(['success'=>true],JSON_UNESCAPED_UNICODE); exit;
    }
    throw new RuntimeException('Acción no válida.');
}catch(Throwable $x){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$x->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
