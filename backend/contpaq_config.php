<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
require_once '../includes/contpaq_crypto.php';
require_once '../includes/contpaq_sqlserver.php';

seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');

$accion = (string)($_REQUEST['accion'] ?? '');
$idEmpresa = (int)($_REQUEST['id_empresa'] ?? 0);
if ($idEmpresa <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empresa inválida.']);
    exit;
}

try {
    if ($accion === 'obtener') {
        $st = $pdo->prepare(
            'SELECT id_empresa, activo, servidor, base_datos, base_datos_comercial, usuario,
                    CASE WHEN password_enc IS NULL OR password_enc = "" THEN 0 ELSE 1 END AS tiene_password,
                    ultima_prueba, ultimo_error, ultima_sincronizacion
               FROM empresa_contpaq_config
              WHERE id_empresa = ?
              LIMIT 1'
        );
        $st->execute([$idEmpresa]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'config' => $r ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido. Recargue la pantalla.']);
        exit;
    }

    // El estado activo se guarda de forma independiente. Así el interruptor
    // no depende de volver a guardar servidor/usuario/contraseña.
    if ($accion === 'estado') {
        $activo = ((string)($_POST['activo'] ?? '0') === '1') ? 1 : 0;

        $st = $pdo->prepare('SELECT id_empresa FROM empresa_contpaq_config WHERE id_empresa=? LIMIT 1');
        $st->execute([$idEmpresa]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Primero guarde la configuración CONTPAQi de esta empresa.');
        }

        $up = $pdo->prepare(
            'UPDATE empresa_contpaq_config
                SET activo=?, fecha_actualizacion=NOW()
              WHERE id_empresa=?'
        );
        $up->execute([$activo, $idEmpresa]);

        // Verificación inmediata de persistencia.
        $chk = $pdo->prepare('SELECT activo FROM empresa_contpaq_config WHERE id_empresa=? LIMIT 1');
        $chk->execute([$idEmpresa]);
        $guardado = (int)$chk->fetchColumn();
        if ($guardado !== $activo) {
            throw new RuntimeException('No fue posible conservar el estado de la conexión CONTPAQi.');
        }

        echo json_encode([
            'success' => true,
            'activo' => $guardado,
            'message' => $guardado === 1
                ? 'Conexión CONTPAQi activada.'
                : 'Conexión CONTPAQi desactivada.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'guardar' || $accion === 'probar') {
        $servidor = trim((string)($_POST['servidor'] ?? ''));
        $base = trim((string)($_POST['base_datos'] ?? ''));
        $baseComercial = trim((string)($_POST['base_datos_comercial'] ?? ''));
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $passwordNueva = (string)($_POST['password'] ?? '');
        $activo = !empty($_POST['activo']) ? 1 : 0;

        if ($servidor === '' || $base === '' || $baseComercial === '' || $usuario === '') {
            throw new RuntimeException('Servidor, base de datos Bancos, base de datos Comercial y usuario son obligatorios.');
        }
        if (strlen($servidor) > 190 || strlen($base) > 150 || strlen($baseComercial) > 150 || strlen($usuario) > 100) {
            throw new RuntimeException('Alguno de los datos de conexión excede el tamaño permitido.');
        }

        $st = $pdo->prepare('SELECT password_enc FROM empresa_contpaq_config WHERE id_empresa=? LIMIT 1');
        $st->execute([$idEmpresa]);
        $actual = $st->fetch(PDO::FETCH_ASSOC);
        $passwordEnc = $actual['password_enc'] ?? '';

        if ($passwordNueva !== '') {
            $passwordEnc = contpaq_cifrar($passwordNueva);
        }
        if ($passwordEnc === '') {
            throw new RuntimeException('Capture la contraseña de SQL Server al menos una vez.');
        }

        $cfgPrueba = [
            'servidor' => $servidor,
            'base_datos' => $base,
            'usuario' => $usuario,
            'password_enc' => $passwordEnc,
        ];
        $cfgComercial = $cfgPrueba;
        $cfgComercial['base_datos'] = $baseComercial;

        if ($accion === 'probar') {
            try {
                $sql = contpaq_sqlserver_conectar($cfgPrueba);
                $stCh = contpaq_sqlserver_query($sql, 'SELECT TOP 1 Id FROM Cheques ORDER BY Id DESC');
                contpaq_sqlserver_fetch($stCh);
                contpaq_sqlserver_liberar($stCh);
                $stDp = contpaq_sqlserver_query($sql, 'SELECT TOP 1 Id FROM DispersionesPagos ORDER BY Id DESC');
                contpaq_sqlserver_fetch($stDp);
                contpaq_sqlserver_liberar($stDp);
                contpaq_sqlserver_cerrar($sql);

                $sqlCom = contpaq_sqlserver_conectar($cfgComercial);
                $colsAdm = contpaq_sqlserver_columnas($sqlCom, 'admClientes');
                foreach (['CRFC', 'CEMAIL1', 'CEMAIL2', 'CEMAIL3'] as $campoReq) {
                    contpaq_sqlserver_columna_requerida($colsAdm, 'admClientes', $campoReq);
                }
                contpaq_sqlserver_cerrar($sqlCom);

                $pdo->prepare(
                    'UPDATE empresa_contpaq_config SET ultima_prueba=NOW() WHERE id_empresa=?'
                )->execute([$idEmpresa]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Conexión correcta. Bancos: Cheques/DispersionesPagos. Comercial: admClientes con RFC y correos.'
                ]);
                exit;
            } catch (Throwable $e) {
                seguridad_log_error($e, 'contpaq_config_probar');
                throw new RuntimeException('No fue posible conectar con SQL Server de CONTPAQi. Revise servidor, base, usuario y contraseña.');
            }
        }

        // GUARDAR: la configuración se conserva aunque en este momento SQL Server
        // no esté disponible. Después intentamos una prueba y guardamos su resultado.
        $up = $pdo->prepare(
            'INSERT INTO empresa_contpaq_config
                (id_empresa, activo, servidor, base_datos, base_datos_comercial, usuario, password_enc, ultimo_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                activo=VALUES(activo),
                servidor=VALUES(servidor),
                base_datos=VALUES(base_datos),
                base_datos_comercial=VALUES(base_datos_comercial),
                usuario=VALUES(usuario),
                password_enc=VALUES(password_enc),
                fecha_actualizacion=NOW()'
        );
        $up->execute([$idEmpresa, $activo, $servidor, $base, $baseComercial, $usuario, $passwordEnc]);

        $conexionOk = false;
        $aviso = '';
        try {
            $sql = contpaq_sqlserver_conectar($cfgPrueba);
            $stCh = contpaq_sqlserver_query($sql, 'SELECT TOP 1 Id FROM Cheques ORDER BY Id DESC');
            contpaq_sqlserver_fetch($stCh);
            contpaq_sqlserver_liberar($stCh);
            $stDp = contpaq_sqlserver_query($sql, 'SELECT TOP 1 Id FROM DispersionesPagos ORDER BY Id DESC');
            contpaq_sqlserver_fetch($stDp);
            contpaq_sqlserver_liberar($stDp);
            contpaq_sqlserver_cerrar($sql);

            $sqlCom = contpaq_sqlserver_conectar($cfgComercial);
            $colsAdm = contpaq_sqlserver_columnas($sqlCom, 'admClientes');
            foreach (['CRFC', 'CEMAIL1', 'CEMAIL2', 'CEMAIL3'] as $campoReq) {
                contpaq_sqlserver_columna_requerida($colsAdm, 'admClientes', $campoReq);
            }
            contpaq_sqlserver_cerrar($sqlCom);

            $conexionOk = true;
            $pdo->prepare(
                'UPDATE empresa_contpaq_config SET ultima_prueba=NOW() WHERE id_empresa=?'
            )->execute([$idEmpresa]);
        } catch (Throwable $pruebaError) {
            seguridad_log_error($pruebaError, 'contpaq_config_guardar_prueba');
            $aviso = 'Configuración guardada, pero SQL Server no respondió. Revise servidor, base, usuario y contraseña.';
            // La prueba de conexión no modifica ultimo_error: ese campo queda
            // reservado para el resultado de la sincronización CONTPAQi.
            $pdo->prepare(
                'UPDATE empresa_contpaq_config SET ultima_prueba=NOW() WHERE id_empresa=?'
            )->execute([$idEmpresa]);
        }

        echo json_encode([
            'success' => true,
            'connection_ok' => $conexionOk,
            'message' => $conexionOk
                ? 'Configuración CONTPAQi guardada. Bancos y Comercial validados.'
                : $aviso,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acción no reconocida.']);
} catch (Throwable $e) {
    seguridad_log_error($e, 'contpaq_config');
    http_response_code(400);
    $mensaje = $e instanceof PDOException
        ? 'No fue posible conectar con SQL Server de CONTPAQi. Revise la configuración.'
        : $e->getMessage();
    echo json_encode(['success' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
}
