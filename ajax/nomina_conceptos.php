<?php
ob_start();session_start();
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/nomina_conceptos_excel.php';
$lock=null;
try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)$_SESSION['id_usuario'];$e=(int)$_SESSION['id_empresa'];
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    if (!usuario_puede_ver_tipo($pdo,$u,$e,'N')) {http_response_code(403);throw new RuntimeException('No tienes permiso para consultar XML de nómina en esta empresa.');}
    $action=(string)($_POST['accion']??$_GET['accion']??'listar');
    if (!in_array($action,['listar','iniciar','procesar','resumen','detalle','descargar','cancelar'],true)) throw new InvalidArgumentException('Acción inválida.');
    if (in_array($action,['iniciar','procesar','cancelar'],true)) {
        if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['nomina_campos_csrf']) || !hash_equals($_SESSION['nomina_campos_csrf'],(string)($_POST['csrf']??''))) {http_response_code(403);throw new RuntimeException('La sesión del informe cambió. Recarga Nómina.');}
    }
    if ($action==='descargar') exigir_permiso_accion($pdo,$u,$e,'exportar');
    session_write_close();
    nf_require_schema($pdo);
    if ($action==='listar') {
        $q=$pdo->prepare('SELECT i.id_importacion,i.anio,i.periodo_desde,i.periodo_hasta,i.fecha_desde,i.fecha_hasta,i.tipo_nomina,i.nombre_archivo,MAX(s.id_fuente) id_fuente FROM nomina_importaciones i JOIN nomina_fuentes s ON s.id_importacion=i.id_importacion AND s.id_empresa=i.id_empresa WHERE i.id_empresa=? GROUP BY i.id_importacion,i.anio,i.periodo_desde,i.periodo_hasta,i.fecha_desde,i.fecha_hasta,i.tipo_nomina,i.nombre_archivo ORDER BY i.id_importacion DESC');$q->execute([$e]);
        $out=['success'=>true,'fuentes'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    } elseif ($action==='iniciar') {
        ncc_clean();$id=(int)($_POST['id_importacion']??0);if ($id<1) throw new InvalidArgumentException('Seleccione el maestro importado.');
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT * FROM nomina_importaciones WHERE id_empresa=? AND id_importacion=?');$q->execute([$e,$id]);$h=$q->fetch(PDO::FETCH_ASSOC);
        if (!$h) throw new RuntimeException('La importación no pertenece a la empresa activa.');
        $source=nf_latest($pdo,$e,$id);
        $from=rng_date($h['fecha_desde']);$to=rng_date($h['fecha_hasta']);
        if (!$from || !$to || $from>$to) throw new RuntimeException('Corrige el rango de fechas de esta importación antes de conciliar.');
        $q=$pdo->prepare('SELECT rfc,razon_social FROM empresas WHERE id_empresa=?');$q->execute([$e]);$company=$q->fetch(PDO::FETCH_ASSOC);
        $fields=nf_fields($pdo,$e,json_decode($source['columnas_json'],true,512,JSON_THROW_ON_ERROR));
        $q=$pdo->prepare('SELECT renglon,clase,numero_empleado,rfc,valores_json FROM nomina_fuente_filas WHERE id_fuente=? ORDER BY renglon');$q->execute([(int)$source['id_fuente']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$rules=ncm_load($pdo,$e);
        $workbook=ncc_workbook($fields['columnas'],$rows,(string)$company['rfc'],$rules);
        if (!$workbook['empleados']) throw new RuntimeException('El maestro guardado no tiene empleados con RFC.');
        $original=json_decode($source['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        if (($original['fecha_desde_importada']??$from)!==$from || ($original['fecha_hasta_importada']??$to)!==$to) $workbook['incidencias'][]='Se usa el rango corregido de la importación, diferente del rango al guardar esta fuente. Revise que corresponda al mismo acumulado.';
        $q=$pdo->prepare("SELECT DISTINCT f.uuid FROM facturas f WHERE f.id_empresa=? AND f.id_tipo_comprobante='N' AND (f.nomina_fecha_inicial_pago BETWEEN ? AND ? OR f.nomina_fecha_inicial_pago IS NULL OR CAST(f.nomina_fecha_inicial_pago AS CHAR)='0000-00-00') ORDER BY f.uuid");$q->execute([$e,$from,$to]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$pdo->commit();
        if (count($ids)>40000) throw new RuntimeException('Hay más de 40,000 XML candidatos. Procesa sus fechas de nómina y usa una importación de menor rango.');
        $token=bin2hex(random_bytes(16));$dir=ncc_dir($token);if (!mkdir($dir,0700)) throw new RuntimeException('No se pudo crear el temporal del informe.');
        $m=['usuario'=>$u,'empresa'=>$e,'empresa_nombre'=>$company['razon_social'],'importacion'=>$id,'fuente'=>(int)$source['id_fuente'],'archivo'=>$source['nombre_archivo'],'hash'=>$source['hash_archivo'],'created'=>time(),'scope'=>['desde'=>$from,'hasta'=>$to,'rfc_emisor'=>$company['rfc'],'reglas'=>$rules],'total'=>count($ids),'procesados'=>0,'bloques'=>0,'completo'=>false,'cancelado'=>false];
        rng_atomic_json($dir.'/fuente.json',$workbook);rng_atomic_json($dir.'/ids.json',$ids);rng_atomic_json($dir.'/meta.json',$m);
        $out=['success'=>true,'token'=>$token,'total'=>count($ids),'scope'=>$m['scope'],'archivo'=>$m['archivo'],'bloque'=>0,'procesados'=>0,'completo'=>false];
    } else {
        $token=(string)($_POST['token']??$_GET['token']??'');$dir=ncc_dir($token);
        if (!is_file($dir.'/meta.json')) throw new RuntimeException('Este informe ya no está disponible; vuelve a generarlo.');
        $lock=fopen($dir.'/job.lock','c');if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {http_response_code(409);throw new RuntimeException('El informe está procesando otro bloque. Intenta nuevamente.');}
        $m=json_decode((string)file_get_contents($dir.'/meta.json'),true,512,JSON_THROW_ON_ERROR);
        if ($m['usuario']!==$u || $m['empresa']!==$e) {http_response_code(403);throw new RuntimeException('Informe no autorizado para esta sesión/empresa.');}
        if ($m['created']<time()-86400) throw new RuntimeException('El informe venció después de 24 horas. Vuelve a generarlo.');
        // No se permite recuperar un reporte de una fuente eliminada.
        $q=$pdo->prepare('SELECT id_fuente FROM nomina_fuentes WHERE id_fuente=? AND id_empresa=? AND id_importacion=?');$q->execute([$m['fuente'],$e,$m['importacion']]);
        if (!$q->fetchColumn()) throw new RuntimeException('La fuente original fue eliminada; el informe ya no está disponible.');
        if ($m['cancelado']) throw new RuntimeException('Informe cancelado.');
        if ($action==='cancelar') {
            $m['cancelado']=true;rng_atomic_json($dir.'/meta.json',$m);
            foreach(glob($dir.'/*')?:[] as $file) if (!in_array(basename($file),['meta.json','job.lock'],true)) @unlink($file);
            $out=['success'=>true];
        } elseif ($action==='procesar') {
            $block=(int)($_POST['bloque']??-1);if ($block<0 || $block>$m['bloques']) throw new InvalidArgumentException('Número de bloque inválido.');
            $ready=$m['procesados']===$m['total'];
            if (!$m['completo'] && !$ready && $block===$m['bloques']) {
                $ids=json_decode((string)file_get_contents($dir.'/ids.json'),true,512,JSON_THROW_ON_ERROR);$ids=array_slice($ids,$m['procesados'],25);$ph=implode(',',array_fill(0,count($ids),'?'));$docs=[];
                $q=$pdo->prepare("SELECT f.uuid,f.estatus_sat,r.rfc rfc_receptor,e.rfc rfc_emisor,d.xml_base64,d.ruta_xml FROM facturas f LEFT JOIN cat_receptores r ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor LEFT JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor LEFT JOIN facturas_datos d ON d.uuid=f.uuid WHERE f.id_empresa=? AND f.id_tipo_comprobante='N' AND f.uuid IN ($ph)");$q->execute(array_merge([$e],$ids));
                while($row=$q->fetch(PDO::FETCH_ASSOC)) $docs[strtoupper($row['uuid'])]=$row;
                $parsed=[];foreach($ids as $uuid) $parsed[]=ncc_document($docs[strtoupper($uuid)]??['uuid'=>$uuid],$m['scope']);
                rng_atomic_json($dir.'/bloque_'.$m['bloques'].'.json',$parsed);$m['procesados']+=count($ids);$m['bloques']++;rng_atomic_json($dir.'/meta.json',$m);
            }
            if (!$m['completo'] && $ready) {
                @set_time_limit(120);ncc_finish($dir,$m);$m['completo']=true;rng_atomic_json($dir.'/meta.json',$m);
            }
            $out=['success'=>true,'completo'=>$m['completo'],'procesados'=>$m['procesados'],'total'=>$m['total'],'bloque'=>$m['bloques'],'porcentaje'=>$m['completo']?100:min(95,(int)round(95*$m['procesados']/max(1,$m['total'])))];
        } elseif ($action==='resumen' || $action==='detalle') {
            if (!$m['completo']) throw new RuntimeException('El informe todavía no termina.');
            if ($action==='resumen') $out=['success'=>true,'resumen'=>json_decode((string)file_get_contents($dir.'/resumen.json'),true,512,JSON_THROW_ON_ERROR)];
            else {
                $index=(int)($_GET['indice']??-1);$page=max(1,(int)($_GET['pagina']??1));$size=50;$filter=(string)($_GET['filtro']??'');$needle=ncc_norm($_GET['buscar']??'');
                $rows=[];$count=0;$fh=fopen($dir.'/detalle.jsonl','rb');if (!$fh) throw new RuntimeException('Falta el detalle del informe.');
                try {while(($line=fgets($fh))!==false) {
                    $r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if ($index>=0 && $r['indice']!==$index) continue;
                    if ($filter==='revisar' && in_array($r['estado'],['COINCIDE','SIN_MOVIMIENTO'],true)) continue;
                    if ($needle!=='' && !str_contains(ncc_norm($r['rfc'].' '.$r['empleado'].' '.$r['campo']),$needle)) continue;
                    if ($count>=($page-1)*$size && count($rows)<$size) $rows[]=$r;$count++;
                }} finally {fclose($fh);}
                $out=['success'=>true,'filas'=>$rows,'total'=>$count,'pagina'=>$page,'paginas'=>max(1,(int)ceil($count/$size))];
            }
        } elseif ($action==='descargar') {
            $path=$dir.'/informe.xlsx';if (!$m['completo'] || !is_file($path)) throw new RuntimeException('El Excel aún no está listo.');
            while(ob_get_level()) ob_end_clean();@ini_set('zlib.output_compression','0');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="Conciliacion_campos_nomina_'.$m['importacion'].'_'.$m['scope']['desde'].'_'.$m['scope']['hasta'].'.xlsx"');header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);flock($lock,LOCK_UN);fclose($lock);exit;
        }
    }
    if (is_resource($lock)) {flock($lock,LOCK_UN);fclose($lock);$lock=null;}
    while(ob_get_level()) ob_end_clean();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo nf_json($out);
} catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($lock)) {flock($lock,LOCK_UN);fclose($lock);}
    while(ob_get_level()) ob_end_clean();if(http_response_code()<400) http_response_code(400);
    seguridad_log_error($ex,'nomina_conceptos');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode(['success'=>false,'error'=>$ex instanceof PDOException?'No se pudo consultar la nómina. Revisa el registro del servidor.':$ex->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} finally {if (session_status()===PHP_SESSION_ACTIVE) session_write_close();}
