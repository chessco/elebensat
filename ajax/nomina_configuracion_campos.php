<?php
ob_start();session_start();
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/nomina_conceptos.php';
try{
    seguridad_exigir_sesion($pdo,true,false,true);$u=(int)$_SESSION['id_usuario'];$e=(int)$_SESSION['id_empresa'];
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    if(!usuario_puede_ver_tipo($pdo,$u,$e,'N')){http_response_code(403);throw new RuntimeException('Se requiere permiso de nómina para configurar sus campos.');}
    if($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH']??0)>0)throw new RuntimeException('PHP no recibió la carga. Reduce el tamaño o revisa post_max_size y upload_max_filesize en el servidor.');
    $action=(string)($_POST['accion']??$_GET['accion']??'listar');
    if(!in_array($action,['listar','excel','xml','probar','guardar','historial'],true))throw new RuntimeException('Acción inválida.');
    if(!in_array($action,['listar','historial'],true)){
        if($_SERVER['REQUEST_METHOD']!=='POST'||empty($_SESSION['nomina_campos_csrf'])||!hash_equals($_SESSION['nomina_campos_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);throw new RuntimeException('La sesión cambió. Recarga Nómina.');}
    }
    session_write_close();nf_require_schema($pdo);ncm_schema($pdo);
    $q=$pdo->prepare('SELECT rfc,razon_social FROM empresas WHERE id_empresa=?');$q->execute([$e]);$company=$q->fetch(PDO::FETCH_ASSOC);
    if($action==='listar'){
        $rules=ncm_load($pdo,$e);$q=$pdo->prepare('SELECT * FROM nomina_campos WHERE id_empresa=? ORDER BY id_campo');$q->execute([$e]);$fields=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){$base=ncc_plan(['clave_campo'=>$f['clave_campo'],'estado_al_importar'=>$f['estado']],$company['rfc']);$f['predeterminada']=$base['regla'];$f['configuracion']=$rules[$f['clave_campo']]??null;$f['plan']=$f['configuracion']?ncm_plan($base,$f['configuracion']):$base;$fields[]=$f;}
        $q=$pdo->prepare('SELECT id_xml_campo,etiqueta,ejemplos_json,tipo_sugerido,id_muestra FROM nomina_config_xml_campos WHERE id_empresa=? ORDER BY id_xml_campo');$q->execute([$e]);$xml=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){$f['ejemplos']=json_decode($f['ejemplos_json'],true);unset($f['ejemplos_json']);$xml[]=$f;}
        $q=$pdo->prepare('SELECT id_muestra,nombre_archivo,uuid,rfc_receptor,fecha_pago,fecha_registro FROM nomina_config_xml_muestras WHERE id_empresa=? ORDER BY id_muestra DESC');$q->execute([$e]);
        $out=['success'=>true,'empresa'=>$company,'campos'=>$fields,'campos_xml'=>$xml,'muestras'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }elseif($action==='excel'){
        $f=$_FILES['archivo']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('No se recibió el Excel. Revisa el tamaño y los límites de carga de PHP.');
        if($f['size']>10*1024*1024)throw new RuntimeException('El Excel de configuración debe ser menor de 10 MB.');
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));$parsed=ncm_excel($f['tmp_name'],$ext,(string)($_POST['hoja']??''),(int)($_POST['encabezado']??0));
        if(!$parsed['requiere_hoja']){
            $confirm=($_POST['confirmar']??'')==='1';
            if($confirm)$pdo->beginTransaction();
            $q=$pdo->prepare('SELECT COUNT(*) FROM nomina_campos WHERE id_empresa=?');$q->execute([$e]);$before=(int)$q->fetchColumn();
            $fields=nf_fields($pdo,$e,$parsed['columnas'],$confirm,$u);$parsed['columnas']=$fields['columnas'];$parsed['pendientes']=$fields['pendientes'];
            if($confirm){$q->execute([$e]);$parsed['nuevos']=(int)$q->fetchColumn()-$before;$pdo->commit();}
            $parsed['registrado']=$confirm;
        }
        $out=['success'=>true,'excel'=>$parsed,'aviso'=>'Esta carga sólo reconoce encabezados. No importa ni reemplaza empleados, importes ni conciliaciones.'];
    }elseif($action==='xml'){
        $fs=$_FILES['xmls']??null;if(!$fs||!is_array($fs['name'])||count($fs['name'])>10)throw new RuntimeException('Selecciona entre 1 y 10 archivos XML. Revisa los límites de carga de PHP.');
        $results=[];
        foreach($fs['name'] as $i=>$name){
            $name=basename($name);
            try{
                if($fs['error'][$i]!==UPLOAD_ERR_OK||!is_uploaded_file($fs['tmp_name'][$i])||$fs['size'][$i]>2*1024*1024||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xml')throw new RuntimeException('XML no recibido, extensión inválida o tamaño mayor de 2 MB.');
                $pdo->beginTransaction();$r=ncm_store_xml($pdo,$e,$u,$name,(string)file_get_contents($fs['tmp_name'][$i]),$company['rfc']);$pdo->commit();$results[]=['archivo'=>$name,'success'=>true]+$r;
            }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();seguridad_log_error($ex,'nomina_config_xml');$results[]=['archivo'=>$name,'success'=>false,'error'=>$ex instanceof PDOException?'No se pudo registrar esta muestra; intenta otra vez.':$ex->getMessage()];}
        }
        $out=['success'=>true,'resultados'=>$results,'aviso'=>'Muestras de configuración: no se agregaron facturas ni se modificaron sus estatus.'];
    }elseif($action==='probar'||$action==='guardar'){
        $id=(int)($_POST['id_campo']??0);$q=$pdo->prepare('SELECT id_campo FROM nomina_campos WHERE id_empresa=? AND id_campo=?');$q->execute([$e,$id]);if(!$q->fetchColumn())throw new RuntimeException('El campo no pertenece a la empresa activa.');
        $input=json_decode((string)($_POST['definicion']??''),true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new RuntimeException('Definición inválida.');
        $def=ncm_definition($pdo,$e,$input);$sampleIds=json_decode((string)($_POST['muestras']??'[]'),true,16,JSON_THROW_ON_ERROR);if(!is_array($sampleIds))throw new RuntimeException('Muestras inválidas.');
        $probe=ncm_probe($pdo,$e,$def,$sampleIds);
        if($action==='guardar'){
            if(($_POST['confirmar']??'')!=='1'||!$probe['apta'])throw new RuntimeException('Prueba la relación con muestras que contengan todos sus campos y confirma antes de guardar.');
            $pdo->beginTransaction();$version=ncm_save($pdo,$e,$u,$id,(int)($_POST['version']??-1),$def,trim((string)($_POST['comentario']??'')));$pdo->commit();
            $out=['success'=>true,'version'=>$version,'mensaje'=>'Relación guardada. Se aplicará al generar nuevas conciliaciones; los informes terminados no cambian.'];
        }else $out=['success'=>true,'definicion'=>$def,'prueba'=>$probe];
    }else{
        $q=$pdo->prepare('SELECT h.version_regla,h.definicion_json,h.id_usuario,h.comentario,h.fecha_registro FROM nomina_config_reglas_historial h JOIN nomina_config_reglas r ON r.id_regla=h.id_regla WHERE r.id_empresa=? AND r.id_campo=? ORDER BY h.version_regla DESC LIMIT 100');$q->execute([$e,(int)($_GET['id_campo']??0)]);$out=['success'=>true,'historial'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }
    while(ob_get_level())ob_end_clean();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo nf_json($out);
}catch(Throwable $ex){
    if($pdo->inTransaction())$pdo->rollBack();while(ob_get_level())ob_end_clean();if(http_response_code()<400)http_response_code(400);
    seguridad_log_error($ex,'nomina_configuracion_campos');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['success'=>false,'error'=>$ex instanceof PDOException?'No se pudo guardar/consultar la configuración. Revisa el registro del servidor.':$ex->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
