<?php
declare(strict_types=1);

session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
require_once '../includes/contpaq_sqlserver.php';

seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json; charset=utf-8');

$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
$accion = (string)($_POST['accion'] ?? '');

function sync_json(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sync_email_valido_desde_campos(array $fila): ?string {
    foreach (['CEMAIL1','CEMAIL2','CEMAIL3'] as $campo) {
        $raw = trim((string)($fila[$campo] ?? ''));
        if ($raw === '') continue;

        // CONTPAQi puede traer uno o más correos separados por ; , o espacios.
        $partes = preg_split('/[;,]+|\s{2,}/u', $raw) ?: [];
        if (!$partes) $partes = [$raw];

        foreach ($partes as $parte) {
            $mail = trim($parte, " \t\n\r\0\x0B<>");
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                return mb_strtolower($mail, 'UTF-8');
            }
        }

        // Por compatibilidad, intentar el campo completo.
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($raw, 'UTF-8');
        }
    }
    return null;
}

function sync_estado(PDO $pdo, string $token, int $idEmpresa): array {
    $st = $pdo->prepare(
        'SELECT * FROM contpaq_correo_sync
          WHERE token=? AND id_empresa=?
          LIMIT 1'
    );
    $st->execute([$token, $idEmpresa]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        throw new RuntimeException('La sincronización indicada ya no existe.');
    }
    return $r;
}

function sync_salida(array $r, string $estadoTexto='', bool $terminado=false, bool $cancelado=false): array {
    return [
        'success' => true,
        'token' => (string)$r['token'],
        'total' => (int)$r['total'],
        'leidos' => (int)$r['leidos'],
        'coinciden' => (int)$r['coinciden'],
        'actualizados' => (int)$r['actualizados'],
        'sin_cambio' => (int)$r['sin_cambio'],
        'no_encontrados' => (int)$r['no_encontrados'],
        'invalidos' => (int)$r['invalidos'],
        'estado' => $estadoTexto !== '' ? $estadoTexto : (string)$r['estatus'],
        'terminado' => $terminado,
        'cancelado' => $cancelado,
    ];
}

try {
    if ($idEmpresa <= 0) {
        throw new RuntimeException('No hay una empresa activa.');
    }
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        sync_json(['success'=>false, 'error'=>'Token de seguridad inválido. Recargue la pantalla.'], 403);
    }

    if ($accion === 'iniciar') {
        $cfg = contpaq_config_empresa($pdo, $idEmpresa);
        $baseComercial = trim((string)($cfg['base_datos_comercial'] ?? ''));
        if ($baseComercial === '') {
            throw new RuntimeException('Configure primero la BASE DE DATOS COMERCIAL de esta empresa.');
        }

        $cfgCom = $cfg;
        $cfgCom['base_datos'] = $baseComercial;

        $sql = contpaq_sqlserver_conectar($cfgCom);
        try {
            $cols = contpaq_sqlserver_columnas($sql, 'admClientes');
            foreach (['CIDCLIENTEPROVEEDOR','CRFC','CEMAIL1','CEMAIL2','CEMAIL3'] as $campoReq) {
                contpaq_sqlserver_columna_requerida($cols, 'admClientes', $campoReq);
            }

            $st = contpaq_sqlserver_query($sql, "
                SELECT COUNT(*) AS total
                  FROM dbo.admClientes
                 WHERE ISNULL(LTRIM(RTRIM(CRFC)), '') <> ''
                   AND (
                        ISNULL(LTRIM(RTRIM(CEMAIL1)), '') <> ''
                     OR ISNULL(LTRIM(RTRIM(CEMAIL2)), '') <> ''
                     OR ISNULL(LTRIM(RTRIM(CEMAIL3)), '') <> ''
                   )
            ");
            $rTotal = contpaq_sqlserver_fetch($st) ?: [];
            contpaq_sqlserver_liberar($st);
            $total = (int)($rTotal['total'] ?? 0);
        } finally {
            contpaq_sqlserver_cerrar($sql);
        }

        // Cancelar cualquier corrida anterior aún abierta de la misma empresa.
        $pdo->prepare(
            "UPDATE contpaq_correo_sync
                SET estatus='CANCELADA', fecha_fin=NOW()
              WHERE id_empresa=? AND estatus IN ('INICIADA','PROCESANDO')"
        )->execute([$idEmpresa]);

        $token = bin2hex(random_bytes(16));
        $pdo->prepare(
            'INSERT INTO contpaq_correo_sync
                (token,id_empresa,estatus,total,ultimo_id,leidos,coinciden,actualizados,sin_cambio,no_encontrados,invalidos,fecha_inicio)
             VALUES (?,?,"INICIADA",?,0,0,0,0,0,0,0,NOW())'
        )->execute([$token,$idEmpresa,$total]);

        $r = sync_estado($pdo, $token, $idEmpresa);
        sync_json(sync_salida($r, $total > 0 ? 'Sincronización preparada.' : 'No hay registros con RFC/correo en Comercial.', $total === 0));
    }

    $token = preg_replace('/[^a-f0-9]/i', '', (string)($_POST['token'] ?? ''));
    if (strlen($token) !== 32) {
        throw new RuntimeException('Token de sincronización inválido.');
    }

    if ($accion === 'cancelar') {
        $pdo->prepare(
            "UPDATE contpaq_correo_sync
                SET estatus='CANCELADA', fecha_fin=NOW()
              WHERE token=? AND id_empresa=? AND estatus IN ('INICIADA','PROCESANDO')"
        )->execute([$token,$idEmpresa]);

        $r = sync_estado($pdo, $token, $idEmpresa);
        sync_json(sync_salida($r, 'Sincronización cancelada.', false, true));
    }

    if ($accion !== 'paso') {
        throw new RuntimeException('Acción no reconocida.');
    }

    $estado = sync_estado($pdo, $token, $idEmpresa);
    if ($estado['estatus'] === 'CANCELADA') {
        sync_json(sync_salida($estado, 'Sincronización cancelada.', false, true));
    }
    if ($estado['estatus'] === 'TERMINADA') {
        sync_json(sync_salida($estado, 'Sincronización terminada.', true, false));
    }

    $cfg = contpaq_config_empresa($pdo, $idEmpresa);
    $baseComercial = trim((string)($cfg['base_datos_comercial'] ?? ''));
    if ($baseComercial === '') {
        throw new RuntimeException('La empresa no tiene configurada la base Comercial.');
    }
    $cfgCom = $cfg;
    $cfgCom['base_datos'] = $baseComercial;

    $ultimoId = (int)$estado['ultimo_id'];
    $tamBloque = 100;

    $sql = contpaq_sqlserver_conectar($cfgCom);
    try {
        $query = "
            SELECT TOP {$tamBloque}
                   CIDCLIENTEPROVEEDOR, CRFC, CRAZONSOCIAL, CEMAIL1, CEMAIL2, CEMAIL3
              FROM dbo.admClientes
             WHERE CIDCLIENTEPROVEEDOR > ?
               AND ISNULL(LTRIM(RTRIM(CRFC)), '') <> ''
               AND (
                    ISNULL(LTRIM(RTRIM(CEMAIL1)), '') <> ''
                 OR ISNULL(LTRIM(RTRIM(CEMAIL2)), '') <> ''
                 OR ISNULL(LTRIM(RTRIM(CEMAIL3)), '') <> ''
               )
             ORDER BY CIDCLIENTEPROVEEDOR
        ";
        $st = contpaq_sqlserver_query($sql, $query, [$ultimoId]);

        $filas = [];
        while (($fila = contpaq_sqlserver_fetch($st)) !== null) {
            $filas[] = $fila;
        }
        contpaq_sqlserver_liberar($st);
    } finally {
        contpaq_sqlserver_cerrar($sql);
    }

    if (!$filas) {
        $pdo->prepare(
            "UPDATE contpaq_correo_sync
                SET estatus='TERMINADA', fecha_fin=NOW()
              WHERE token=? AND id_empresa=?"
        )->execute([$token,$idEmpresa]);

        $pdo->prepare(
            'UPDATE empresa_contpaq_config
                SET ultima_sincronizacion=NOW(), ultimo_error=NULL
              WHERE id_empresa=?'
        )->execute([$idEmpresa]);

        $fin = sync_estado($pdo, $token, $idEmpresa);
        sync_json(sync_salida($fin, 'Sincronización terminada.', true, false));
    }

    $coinciden = $actualizados = $sinCambio = $noEncontrados = $invalidos = 0;
    $ultimoProcesado = $ultimoId;

    $sel = $pdo->prepare(
        'SELECT id_emisor, correo
           FROM cat_emisores
          WHERE id_empresa=? AND UPPER(TRIM(rfc))=UPPER(TRIM(?))
          LIMIT 1'
    );
    $upd = $pdo->prepare(
        'UPDATE cat_emisores
            SET correo=?
          WHERE id_emisor=? AND id_empresa=?'
    );

    foreach ($filas as $fila) {
        $ultimoProcesado = max($ultimoProcesado, (int)($fila['CIDCLIENTEPROVEEDOR'] ?? 0));
        $rfc = strtoupper(trim((string)($fila['CRFC'] ?? '')));
        if ($rfc === '') {
            $noEncontrados++;
            continue;
        }

        $correo = sync_email_valido_desde_campos($fila);
        if ($correo === null) {
            $invalidos++;
            continue;
        }

        $sel->execute([$idEmpresa, $rfc]);
        $emisor = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$emisor) {
            $noEncontrados++;
            continue;
        }

        $coinciden++;
        $actual = mb_strtolower(trim((string)($emisor['correo'] ?? '')), 'UTF-8');
        if ($actual === $correo) {
            $sinCambio++;
            continue;
        }

        $upd->execute([$correo, (int)$emisor['id_emisor'], $idEmpresa]);
        $actualizados++;
    }

    $leidosBloque = count($filas);
    $pdo->prepare(
        "UPDATE contpaq_correo_sync
            SET estatus='PROCESANDO',
                ultimo_id=?,
                leidos=leidos+?,
                coinciden=coinciden+?,
                actualizados=actualizados+?,
                sin_cambio=sin_cambio+?,
                no_encontrados=no_encontrados+?,
                invalidos=invalidos+?
          WHERE token=? AND id_empresa=? AND estatus IN ('INICIADA','PROCESANDO')"
    )->execute([
        $ultimoProcesado, $leidosBloque, $coinciden, $actualizados,
        $sinCambio, $noEncontrados, $invalidos, $token, $idEmpresa
    ]);

    $r = sync_estado($pdo, $token, $idEmpresa);

    // Si este bloque fue menor al máximo, ya no hay más filas.
    $terminado = $leidosBloque < $tamBloque || (int)$r['leidos'] >= (int)$r['total'];
    if ($terminado) {
        $pdo->prepare(
            "UPDATE contpaq_correo_sync
                SET estatus='TERMINADA', fecha_fin=NOW()
              WHERE token=? AND id_empresa=?"
        )->execute([$token,$idEmpresa]);

        $pdo->prepare(
            'UPDATE empresa_contpaq_config
                SET ultima_sincronizacion=NOW(), ultimo_error=NULL
              WHERE id_empresa=?'
        )->execute([$idEmpresa]);

        $r = sync_estado($pdo, $token, $idEmpresa);
    }

    $salida = sync_salida(
        $r,
        $terminado ? 'Sincronización terminada.' : 'Procesando correos...',
        $terminado,
        false
    );
    $salida['mensaje_paso'] =
        'Bloque: ' . $leidosBloque .
        ' leídos · ' . $actualizados . ' actualizados · ' .
        $sinCambio . ' sin cambio · ' . $noEncontrados . ' sin RFC en emisores.';
    sync_json($salida);

} catch (Throwable $e) {
    seguridad_log_error($e, 'sincronizar_correos_contpaq');
    try {
        if (!empty($token) && $idEmpresa > 0) {
            $pdo->prepare(
                "UPDATE contpaq_correo_sync
                    SET estatus='ERROR', error=?, fecha_fin=NOW()
                  WHERE token=? AND id_empresa=?"
            )->execute([mb_substr($e->getMessage(),0,1000),$token,$idEmpresa]);
        }
        if ($idEmpresa > 0) {
            $pdo->prepare(
                'UPDATE empresa_contpaq_config SET ultimo_error=? WHERE id_empresa=?'
            )->execute([mb_substr($e->getMessage(),0,1000),$idEmpresa]);
        }
    } catch (Throwable $ignorado) {}

    sync_json(['success'=>false, 'error'=>$e->getMessage()], 400);
}
