<?php
// Un único controlador: iniciar / procesar / cancelar / descargar.
ob_start();
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo,true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/reporte_nomina_gk.php';
$lock=null;
try {
    $empresa=(int)($_SESSION['id_empresa']??0); $usuario=(int)($_SESSION['id_usuario']??0);
    exigir_permiso_accion($pdo,$usuario,$empresa,'exportar');
    if (!usuario_puede_ver_tipo($pdo,$usuario,$empresa,'N')) { http_response_code(403); throw new RuntimeException('No tienes permiso para consultar Nómina en esta empresa.'); }
    $action=(string)($_POST['accion']??$_GET['accion']??'');
    if ($action!=='descargar') {
        if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['nomina_gk_csrf']) || !hash_equals($_SESSION['nomina_gk_csrf'],(string)($_POST['csrf']??''))) {
            http_response_code(403); throw new RuntimeException('La sesión del reporte cambió. Recarga el visor.');
        }
    }
    session_write_close();
    if ($action==='iniciar') {
        rng_cleanup_old();
        $filters=[];
        foreach (['inicio','fin','tipo','metodo','tipo_busqueda','busqueda','ppd_sin_complemento','pue_sin_pago_empresa'] as $key) $filters[$key]=(string)($_POST[$key]??'');
        [$where,$params]=rng_filter($pdo,$empresa,$filters);
        $st=$pdo->prepare('SELECT f.uuid FROM facturas f WHERE '.$where.' ORDER BY f.fecha_emision DESC,f.uuid'); $st->execute($params);
        $first=$st->fetchColumn();
        if ($first===false) $out=['status'=>'sin_datos','msg'=>'No hay CFDI de nómina con los filtros aplicados. Selecciona TODOS o NÓMINA y revisa fechas, método y búsqueda.'];
        else {
            $token=bin2hex(random_bytes(16)); $dir=rng_job_dir($token);
            if (!mkdir($dir,0700)) throw new RuntimeException('No se pudo crear el temporal de nómina.');
            $fh=fopen($dir.'/ids.txt','wb'); $total=0;
            do { rng_write_all($fh,$first."\n"); $total++; } while (($first=$st->fetchColumn())!==false);
            fclose($fh);
            $eq=$pdo->prepare('SELECT rfc,razon_social FROM empresas WHERE id_empresa=?'); $eq->execute([$empresa]); $e=$eq->fetch(PDO::FETCH_ASSOC)?:[];
            $m=['usuario'=>$usuario,'empresa'=>$empresa,'empresa_rfc'=>$e['rfc']??'','empresa_nombre'=>$e['razon_social']??'',
                'filters'=>$filters,'total'=>$total,'done'=>0,'rows'=>0,'offset'=>0,'chunks'=>0,'issue_rows'=>0,'xml_issues'=>0,'complete'=>false,'cancelled'=>false,'created'=>time(),'stats'=>[]];
            $m['calendar']=rng_calendar($pdo,$empresa);
            for ($i=1;$i<=43;$i++) $m['stats'][$i]=['ok'=>0,'missing'=>0,'na'=>0,'docs'=>0];
            rng_atomic_json($dir.'/meta.json',$m);
            $out=['status'=>'ok','token'=>$token,'total'=>$total];
        }
    } else {
        $token=(string)($_POST['token']??$_GET['token']??''); $dir=rng_job_dir($token);
        if (!is_dir($dir)) throw new RuntimeException('El reporte ya no está disponible. Genéralo nuevamente.');
        $lock=fopen($dir.'/job.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { http_response_code(409); throw new RuntimeException('Este reporte ya está procesando otro bloque. Espera e intenta de nuevo.'); }
        $m=json_decode((string)file_get_contents($dir.'/meta.json'),true,512,JSON_THROW_ON_ERROR);
        if ($m['usuario']!==$usuario || $m['empresa']!==$empresa) { http_response_code(403); throw new RuntimeException('Reporte no autorizado para esta sesión/empresa.'); }
        if ($m['cancelled']) throw new RuntimeException('El reporte fue cancelado.');
        if ($action==='cancelar') {
            $m['cancelled']=true; rng_atomic_json($dir.'/meta.json',$m);
            foreach (glob($dir.'/*')?:[] as $file) if (!in_array(basename($file),['meta.json','job.lock'],true)) @unlink($file);
            $out=['status'=>'ok'];
        } elseif ($action==='descargar') {
            $part=(string)($_GET['archivo']??'');
            if (!in_array($part,['detalle','resumen'],true) || !$m['complete'] || !is_file($dir.'/'.$part.'.xlsx')) throw new RuntimeException('El Excel aún no está listo.');
            $path=$dir.'/'.$part.'.xlsx'; $name=($part==='detalle'?'Detalle_nomina_GK_':'Resumen_faltantes_nomina_GK_').($m['filters']['inicio']?:'inicio').'_a_'.($m['filters']['fin']?:'fin').'.xlsx';
            while (ob_get_level()) ob_end_clean();
            @ini_set('zlib.output_compression','Off');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$name.'"');
            header('Content-Length: '.filesize($path)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
            readfile($path); flock($lock,LOCK_UN); fclose($lock); exit;
        } elseif ($action==='procesar') {
            // El número de bloque evita duplicar si se reenvía una petición tras perder la respuesta.
            $expected=(int)($_POST['bloque']??-1);
            if ($expected>$m['chunks'] || $expected<0) throw new InvalidArgumentException('Número de bloque inválido.');
            $readyAtStart=$m['done']===$m['total'];
            if (!$m['complete'] && !$readyAtStart && $expected===$m['chunks']) {
                $fh=fopen($dir.'/ids.txt','rb'); if (!$fh || fseek($fh,$m['offset'])!==0) throw new RuntimeException('No se pudo leer la selección.');
                $ids=[];
                while (count($ids)<20 && ($line=fgets($fh))!==false) { $id=trim($line); if ($id!=='') $ids[]=$id; }
                $offset=ftell($fh); fclose($fh);
                if (!$ids && $m['done']<$m['total']) throw new RuntimeException('Selección incompleta; vuelve a generar el reporte.');
                $docs=[];
                if ($ids) {
                    $ph=implode(',',array_fill(0,count($ids),'?'));
                    $q=$pdo->prepare("SELECT f.uuid,f.fecha_emision,f.total_xml,f.estatus_sat,f.version_cfdi,f.nomina_fecha_pago,f.nomina_fecha_inicial_pago,f.nomina_fecha_final_pago,f.nomina_periodicidad_pago,f.nomina_num_empleado,e.rfc rfc_emisor,r.rfc rfc_receptor,r.nombre nombre_receptor,d.xml_base64,d.ruta_xml FROM facturas f LEFT JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor LEFT JOIN cat_receptores r ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor LEFT JOIN facturas_datos d ON d.uuid=f.uuid WHERE f.id_empresa=? AND f.id_tipo_comprobante='N' AND f.uuid IN ($ph)");
                    $q->execute(array_merge([$empresa],$ids));
                    while ($doc=$q->fetch(PDO::FETCH_ASSOC)) $docs[strtoupper($doc['uuid'])]=$doc;
                }
                $detail=''; $issues='';
                foreach ($ids as $id) {
                    $d=$docs[strtoupper($id)]??['uuid'=>$id]; $result=rng_document($d,$m['calendar']['rows']??[]);
                    if (!isset($docs[strtoupper($id)])) $result['issues'][]='El CFDI ya no está disponible en la empresa; estaba incluido al iniciar.';
                    $missing=[];
                    foreach ($result['rows'] as $ri=>$row) {
                        $m['rows']++;
                        if ($m['rows']>=1048576) throw new RuntimeException('Se alcanzó el límite de filas de Excel. Divide el rango de fechas.');
                        $detail.=rng_row_xml($m['rows']+1,$row);
                        foreach ($row as $col=>$v) {
                            if ($v!==null && $v!=='') $m['stats'][$col]['ok']++;
                            elseif (isset($result['na'][$ri][$col])) $m['stats'][$col]['na']++;
                            else { $m['stats'][$col]['missing']++; $missing[$col]=true; }
                        }
                    }
                    foreach ($missing as $col=>$_) $m['stats'][$col]['docs']++;
                    if ($result['issues']) $m['xml_issues']++;
                    if ($missing || $result['issues']) {
                        $labels=[]; foreach ($missing as $col=>$_) $labels[]=rxr_col_name($col).' '.rng_formato()['headers'][$col-1];
                        $row=$result['rows'][0]; $m['issue_rows']++;
                        $missingText=implode('; ',$labels); $issueText=implode(' ',$result['issues']);
                        $issueRow=rng_row_xml($m['issue_rows']+1,[strtoupper($id),$row[25],$row[11],$missingText,$issueText],false);
                        // Dar espacio al caso de XML ausente, que puede listar casi las 43 columnas.
                        $height=min(400,max(64,16*max(ceil(strlen($missingText)/100),ceil(strlen($issueText)/90))));
                        $issues.=str_replace('ht="64"','ht="'.$height.'"',$issueRow);
                    }
                    $m['done']++;
                }
                foreach (['detail'=>$detail,'issues'=>$issues] as $key=>$data) {
                    $file=$dir.'/'.$key.'_'.$m['chunks'].'.part'; $fh=fopen($file.'.tmp','wb'); rng_write_all($fh,$data); fclose($fh);
                    if (!rename($file.'.tmp',$file)) throw new RuntimeException('No se pudo guardar el bloque.');
                }
                $m['chunks']++; $m['offset']=$offset;
                // Primero confirmar los bloques. El ensamblado final es una petición aparte.
                rng_atomic_json($dir.'/meta.json',$m);
            }
            if (!$m['complete'] && $readyAtStart) {
                rng_finish($dir,$m); $m['complete']=true; rng_atomic_json($dir.'/meta.json',$m);
            }
            $out=['status'=>'ok','completo'=>$m['complete'],'procesados'=>$m['done'],'total'=>$m['total'],'renglones'=>$m['rows'],'bloque'=>$m['chunks'],'finalizar'=>!$m['complete'] && $m['done']===$m['total'],'incidencias'=>$m['xml_issues'],'porcentaje'=>$m['complete']?100:min(99,(int)round(100*$m['done']/max(1,$m['total'])))];
        } else throw new InvalidArgumentException('Acción inválida.');
    }
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    while (ob_get_level()) ob_end_clean();
    if (http_response_code()<400) http_response_code($e instanceof InvalidArgumentException?400:500);
    seguridad_log_error($e,'nomina_gk_excel');
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['status'=>'error','msg'=>$e instanceof PDOException?'No se pudo consultar la base para el reporte de nómina. Revisa el registro del servidor.':$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
