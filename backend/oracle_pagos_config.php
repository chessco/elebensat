<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
require_once '../includes/oracle_pagos_crypto.php';
require_once '../includes/oracle_pagos_rest.php';

seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');

$accion=(string)($_REQUEST['accion']??'');
$idEmpresa=(int)($_REQUEST['id_empresa']??0);

if($idEmpresa<=0){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Empresa inválida.']);
    exit;
}

try{
    if($accion==='obtener'){
        $st=$pdo->prepare(
            'SELECT id_empresa,unidad_negocio,usuario,
                    CASE WHEN password_enc IS NULL OR password_enc="" THEN 0 ELSE 1 END tiene_password,
                    ultima_prueba,ultimo_error,ultima_sincronizacion
               FROM empresa_oracle_pagos_config
              WHERE id_empresa=? LIMIT 1'
        );
        $st->execute([$idEmpresa]);
        echo json_encode(['success'=>true,'config'=>$st->fetch(PDO::FETCH_ASSOC)?:null],JSON_UNESCAPED_UNICODE);
        exit;
    }

    if(!csrf_validar($_POST['csrf_token']??null)){
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Token de seguridad inválido. Recargue la pantalla.']);
        exit;
    }

    if(!in_array($accion,['guardar','probar'],true)){
        throw new RuntimeException('Acción no reconocida.');
    }

    $unidad=strtoupper(trim((string)($_POST['unidad_negocio']??'')));
    $usuario=trim((string)($_POST['usuario']??''));
    $passwordNueva=(string)($_POST['password']??'');

    if($unidad===''||$usuario===''){
        throw new RuntimeException('Unidad de negocio y usuario Oracle son obligatorios.');
    }

    $st=$pdo->prepare('SELECT password_enc FROM empresa_oracle_pagos_config WHERE id_empresa=? LIMIT 1');
    $st->execute([$idEmpresa]);
    $actual=$st->fetch(PDO::FETCH_ASSOC);
    $passwordEnc=(string)($actual['password_enc']??'');

    if($passwordNueva!=='') $passwordEnc=oracle_pagos_cifrar($passwordNueva);
    if($passwordEnc==='') throw new RuntimeException('Capture la contraseña Oracle.');

    // Para probar no hace falta guardar primero.
    $cfg=[
        'unidad_negocio'=>$unidad,
        'usuario'=>$usuario,
        'password'=>oracle_pagos_descifrar($passwordEnc),
        'base_url'=>oracle_pagos_base_url(),
        'api_version'=>oracle_pagos_api_version(),
    ];

    $url=oracle_pagos_api_base($cfg).'/invoices?'.http_build_query([
        'q'=>'BusinessUnit='.$unidad,
        'limit'=>1,
        'offset'=>0
    ],'','&',PHP_QUERY_RFC3986);

    $r=oracle_pagos_ok(oracle_pagos_get($cfg,$url),'Prueba Oracle Invoices');

    if($accion==='probar'){
        echo json_encode([
            'success'=>true,
            'message'=>'Conexión Oracle correcta. HTTP '.$r['http'].' · Unidad '.$unidad.'.',
            'base_url'=>oracle_pagos_base_url()
        ],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $up=$pdo->prepare(
        'INSERT INTO empresa_oracle_pagos_config
            (id_empresa,unidad_negocio,usuario,password_enc,ultima_prueba,ultimo_error)
         VALUES (?,?,?,?,NOW(),NULL)
         ON DUPLICATE KEY UPDATE
            unidad_negocio=VALUES(unidad_negocio),
            usuario=VALUES(usuario),
            password_enc=VALUES(password_enc),
            ultima_prueba=NOW(),
            ultimo_error=NULL,
            fecha_actualizacion=NOW()'
    );
    $up->execute([$idEmpresa,$unidad,$usuario,$passwordEnc]);

    echo json_encode([
        'success'=>true,
        'message'=>'Configuración Oracle Pagos guardada correctamente.'
    ],JSON_UNESCAPED_UNICODE);

}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_config');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
