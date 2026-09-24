<?php
session_start(); header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/seguridad.php';
require_once __DIR__.'/../includes/permisos_documentos.php';
require_once __DIR__.'/../includes/nomina_fuente.php';
try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $u=(int)$_SESSION['id_usuario']; $e=(int)$_SESSION['id_empresa'];
    exigir_permiso_accion($pdo,$u,$e,'nomina_archivo');
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException('Use POST para dar de alta campos.');
    $csrf=(string)($_SESSION['nomina_campos_csrf']??'');
    if ($csrf==='' || !hash_equals($csrf,(string)($_POST['csrf']??''))) {http_response_code(403);throw new RuntimeException('Sesión de campos no válida. Recargue Nómina.');}
    session_write_close();
    $fields=json_decode((string)($_POST['campos']??''),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($fields) || !$fields || count($fields)>200) throw new RuntimeException('Seleccione entre 1 y 200 campos.');
    nf_require_schema($pdo); $pdo->beginTransaction();
    $check=$pdo->prepare('SELECT estado FROM nomina_campos WHERE id_empresa=? AND id_campo=?');
    $update=$pdo->prepare("UPDATE nomina_campos SET estado='ACTIVO',tipo_valor=?,id_usuario_alta=?,fecha_alta=CURRENT_TIMESTAMP WHERE id_empresa=? AND id_campo=? AND estado='PENDIENTE'");
    $count=0;
    foreach($fields as $f) {
        $id=(int)($f['id_campo']??0); $type=(string)($f['tipo']??'');
        if ($id<=0 || !in_array($type,['TEXTO','NUMERO'],true)) throw new RuntimeException('Campo o tipo no válido.');
        $check->execute([$e,$id]);
        if (!$check->fetchColumn()) throw new RuntimeException('Campo no disponible en la empresa activa.');
        $update->execute([$type,$u,$e,$id]); $count+=$update->rowCount();
    }
    $pdo->commit();
    echo nf_json(['success'=>true,'altas'=>$count,'message'=>'Campos dados de alta. Los valores originales ya estaban conservados. Falta definir su equivalencia para el informe de conciliación por campos.']);
} catch(Throwable $x) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($x,'nomina_campos_alta');
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$x instanceof PDOException?'No se pudieron dar de alta los campos.':$x->getMessage()],JSON_UNESCAPED_UNICODE);
} finally {if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
