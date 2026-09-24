<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/control_diot_pagos.php';

function fecha_valida_o_null($v): ?string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function fecha_fiscal_sin_usuario(array $d, string $fechaEmision): ?string {
    $origen = strtoupper(trim((string)($d['origen_pago'] ?? '')));
    if ($origen === 'PUE_EMISION') return $fechaEmision ?: null;
    return fecha_valida_o_null($d['fecha_pago_banco'] ?? null)
        ?: fecha_valida_o_null($d['fecha_pago_sat'] ?? null)
        ?: (($origen === 'PUE_EMISION') ? ($fechaEmision ?: null) : null);
}

try {
    if (!isset($_SESSION['id_empresa'])) throw new Exception('Sesión expirada');

    $idEmpresa = (int)$_SESSION['id_empresa'];
    $uuid = strtoupper(trim((string)($_POST['uuid'] ?? '')));
    $fecha = fecha_valida_o_null($_POST['fecha_usuario'] ?? null);
    $alcance = strtolower(trim((string)($_POST['alcance_fecha'] ?? 'auto')));
    $tratamientoDiot = strtoupper(trim((string)($_POST['tratamiento_diot'] ?? '')));
    if (!in_array($tratamientoDiot, ['NORMAL','NO_EFECTOS','NO_INCLUIR'], true)) {
        // Compatibilidad con clientes anteriores al selector de tres estados.
        $excluirCompat = (isset($_POST['excluir_diot']) && (int)$_POST['excluir_diot'] === 1);
        $efectoCompat = trim((string)($_POST['efecto_fiscal_diot'] ?? '01'));
        $tratamientoDiot = $excluirCompat ? 'NO_INCLUIR' : ($efectoCompat === '02' ? 'NO_EFECTOS' : 'NORMAL');
    }
    $excluir = ($tratamientoDiot === 'NO_INCLUIR') ? 1 : 0;
    $efectoFiscalDiot = ($tratamientoDiot === 'NO_EFECTOS') ? '02' : '01';

    // Tratamiento especial del IVA para DIOT. No altera el XML ni sus importes originales.
    $ivaEspecial = (isset($_POST['iva_tratamiento_especial_diot']) && (int)$_POST['iva_tratamiento_especial_diot'] === 1) ? 1 : 0;
    $porcentajeIva = isset($_POST['iva_porcentaje_acreditable_diot']) ? (float)$_POST['iva_porcentaje_acreditable_diot'] : 100.0;
    $porcentajeIva = max(0.0, min(100.0, $porcentajeIva));
    $motivoIva = trim((string)($_POST['iva_motivo_tratamiento_diot'] ?? ''));
    if (!$ivaEspecial) {
        $porcentajeIva = 100.0;
        $motivoIva = '';
    }

    // Tipo de cambio real/manual del pago. Se conserva separado del TC XML/REP.
    $tcBancoRealRaw = trim((string)($_POST['tc_banco_real'] ?? ''));
    $tcBancoReal = null;
    if ($tcBancoRealRaw !== '') {
        $tcBancoReal = (float)$tcBancoRealRaw;
        if ($tcBancoReal <= 0 || $tcBancoReal > 1000) {
            throw new Exception('Tipo de cambio real inválido.');
        }
        $tcBancoReal = round($tcBancoReal, 4);
    }

    if ($uuid === '') throw new Exception('UUID requerido');
    exigir_permiso_factura_uuid($pdo, (int)($_SESSION['id_usuario'] ?? 0), $idEmpresa, $uuid);

    $st = $pdo->prepare("SELECT DATE(fecha_emision) fecha_emision, fecha_pago_usuario
                         FROM facturas WHERE UPPER(uuid)=UPPER(?) AND id_empresa=? LIMIT 1");
    $st->execute([$uuid, $idEmpresa]);
    $factura = $st->fetch(PDO::FETCH_ASSOC);
    if (!$factura) throw new Exception('Factura no encontrada.');

    $fechaAnterior = fecha_valida_o_null($factura['fecha_pago_usuario'] ?? null);
    $cambioFecha = ($fechaAnterior !== $fecha);

    $st = $pdo->prepare("SELECT id_detalle, origen_pago, fecha_pago_sat, fecha_pago_banco
                         FROM facturas_pagos_detalles
                         WHERE id_empresa=? AND UPPER(uuid_relacionado)=UPPER(?)
                         ORDER BY parcialidad,id_detalle");
    $st->execute([$idEmpresa, $uuid]);
    $detalles = $st->fetchAll(PDO::FETCH_ASSOC);
    $numDetalles = count($detalles);

    // Si hay varias parcialidades y la fecha realmente cambió, primero pedimos al usuario el alcance.
    if ($cambioFecha && $numDetalles > 1 && $alcance === 'auto') {
        echo json_encode([
            'status' => 'requiere_alcance',
            'movimientos' => $numDetalles,
            'msg' => "Esta factura tiene {$numDetalles} movimientos de pago."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    // Tratamiento DIOT siempre forma parte de la edición de la factura.
    // 01 = sí se dieron efectos fiscales; 02 = no se dieron efectos fiscales.
    // NO_INCLUIR conserva el CFDI, pero lo saca completamente del generador.
    $pdo->prepare("UPDATE facturas
                   SET excluir_diot=?, efecto_fiscal_diot=?,
                       iva_tratamiento_especial_diot=?, iva_porcentaje_acreditable_diot=?,
                       iva_motivo_tratamiento_diot=?, tc_banco_real=?
                   WHERE UPPER(uuid)=UPPER(?) AND id_empresa=?")
        ->execute([$excluir, $efectoFiscalDiot, $ivaEspecial, $porcentajeIva, ($motivoIva !== '' ? $motivoIva : null), $tcBancoReal, $uuid, $idEmpresa]);

    if ($cambioFecha) {
        if ($numDetalles <= 1 || $alcance === 'todos') {
            // Una sola parcialidad: factura y detalle se mantienen sincronizados.
            // Varias + 'todos': la decisión explícita del usuario mueve todos los detalles.
            $pdo->prepare("UPDATE facturas
                           SET fecha_pago_usuario=?, fecha_aplicacion_fiscal=?
                           WHERE UPPER(uuid)=UPPER(?) AND id_empresa=?")
                ->execute([$fecha, $fecha, $uuid, $idEmpresa]);

            foreach ($detalles as $d) {
                $fechaFiscal = $fecha ?: fecha_fiscal_sin_usuario($d, (string)$factura['fecha_emision']);
                $pdo->prepare("UPDATE facturas_pagos_detalles
                               SET fecha_pago_usuario=?, fecha_aplicacion_fiscal=?
                               WHERE id_detalle=? AND id_empresa=?")
                    ->execute([$fecha, $fechaFiscal, (int)$d['id_detalle'], $idEmpresa]);
            }

            // Si se borró la fecha manual, recalcular la fecha resumen desde los detalles/origen.
            if ($fecha === null) {
                diot_recalcular_factura($pdo, $uuid, $idEmpresa);
            }
        }
    }

    // Regla DIOT: si existe Fecha Efectiva de Pago, esa fecha MANDA para DIOT
    // y detallado. Sin REP se usa un detalle sintético; cuando llegue el REP real
    // se conservan sus importes/fecha SAT, pero la fecha fiscal aplicada continúa
    // siendo la capturada por el usuario.
    diot_sincronizar_pago_usuario_factura($pdo, $uuid, $idEmpresa);
    diot_recalcular_factura($pdo, $uuid, $idEmpresa);

    $pdo->commit();
    echo json_encode([
        'status' => 'ok',
        'movimientos' => $numDetalles,
        'fecha_usuario' => $fecha,
        'alcance' => ($numDetalles > 1 ? $alcance : 'unico'),
        'tratamiento_diot' => $tratamientoDiot,
        'efecto_fiscal_diot' => $efectoFiscalDiot,
        'excluir_diot' => $excluir,
        'iva_tratamiento_especial_diot' => $ivaEspecial,
        'iva_porcentaje_acreditable_diot' => $porcentajeIva,
        'iva_motivo_tratamiento_diot' => $motivoIva,
        'tc_banco_real' => $tcBancoReal
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    seguridad_log_error($e, 'actualizar_factura'); echo json_encode(['status'=>'error','msg'=>'No se pudo actualizar la factura.'], JSON_UNESCAPED_UNICODE);
}
?>
