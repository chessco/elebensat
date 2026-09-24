<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';

try {
    exigir_permiso_accion(
        $pdo,
        (int)($_SESSION['id_usuario'] ?? 0),
        (int)($_SESSION['id_empresa'] ?? 0),
        'exportar'
    );

    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    $anio = (int)($_GET['anio'] ?? 0);
    $mes = (int)($_GET['mes'] ?? 0);
    if ($idEmpresa <= 0 || $anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        throw new RuntimeException('Periodo DIOT inválido.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    diot_sincronizar_pagos_usuario_empresa($pdo,$idEmpresa);

    $anteriores = diot_pagos_facturas_anteriores($pdo, $idEmpresa, $anio, $mes);
    $pendientes = diot_cfdi_mes_no_pagados($pdo, $idEmpresa, $anio, $mes);
    $posteriores = diot_cfdi_mes_pagados_posterior($pdo, $idEmpresa, $anio, $mes);
    $usuarioSinRep = diot_cfdi_mes_pago_usuario_sin_rep($pdo, $idEmpresa, $anio, $mes);

    // Complementamos nombre/RFC del receptor y total XML para que los renglones
    // auxiliares usen las mismas columnas del CSV principal del visor.
    $uuids = [];
    foreach ([$anteriores, $posteriores, $usuarioSinRep] as $grupo) {
        foreach ($grupo as $r) {
            $u = trim((string)($r['uuid_factura'] ?? ''));
            if ($u !== '') $uuids[$u] = true;
        }
    }
    foreach ($pendientes as $r) {
        $u = trim((string)($r['uuid'] ?? ''));
        if ($u !== '') $uuids[$u] = true;
    }

    $extra = [];
    if ($uuids) {
        foreach (array_chunk(array_keys($uuids), 400) as $chunk) {
            $ph = [];
            $params = [':id_empresa' => $idEmpresa];
            foreach ($chunk as $i => $u) {
                $k = ':u' . $i;
                $ph[] = $k;
                $params[$k] = $u;
            }
            $sql = "SELECT f.uuid, f.total_xml, f.subtotal_xml, f.base_iva, f.iva_16,
                           f.iva_retenido, f.isr_retenido, f.forma_pago,
                           f.tc_xml_factura, f.folio_pago_origen, f.referencia_banco_real,
                           r.nombre AS receptor, r.rfc AS rfc_receptor
                    FROM facturas f
                    LEFT JOIN cat_receptores r
                           ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor
                    WHERE f.id_empresa=:id_empresa
                      AND f.uuid IN (" . implode(',', $ph) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            while ($x = $st->fetch(PDO::FETCH_ASSOC)) {
                $extra[(string)$x['uuid']] = $x;
            }
        }
    }

    $baseFila = static function(array $r, string $uuid, string $estatus, array $extra): array {
        $x = $extra[$uuid] ?? [];
        $moneda = strtoupper(trim((string)($r['moneda'] ?? 'MXN')));
        $tc = (float)($r['tipo_cambio'] ?? $x['tc_xml_factura'] ?? 1);
        if ($tc <= 0) $tc = 1;
        $factor = in_array($moneda, ['', 'MXN', 'XXX'], true) ? 1.0 : $tc;
        return [
            'tipo' => 'E',
            'uuid' => $uuid,
            'emisor' => (string)($r['nombre'] ?? ''),
            'rfc_emisor' => (string)($r['rfc'] ?? ''),
            'receptor' => (string)($x['receptor'] ?? ''),
            'rfc_receptor' => (string)($x['rfc_receptor'] ?? ''),
            'folio' => trim((string)($r['serie'] ?? '') . ((string)($r['serie'] ?? '') !== '' ? ' ' : '') . (string)($r['folio'] ?? '')),
            'fecha' => (string)($r['fecha_emision'] ?? ''),
            'fecha_fiscal' => (string)($r['fecha_aplicacion_fiscal'] ?? $r['fecha_pago_usuario'] ?? ''),
            'estatus_periodo_diot' => $estatus,
            'p_sat' => (string)($r['fecha_pago_sat'] ?? ''),
            'p_banco' => '',
            'p_usu' => (string)($r['fecha_pago_usuario'] ?? ''),
            'ref_banco' => trim((string)($r['folio_pago_origen'] ?? $x['folio_pago_origen'] ?? '')) !== ''
                ? trim((string)($r['folio_pago_origen'] ?? $x['folio_pago_origen'] ?? ''))
                : trim((string)($r['referencia_banco_real'] ?? $x['referencia_banco_real'] ?? '')),
            'forma_pago' => (string)($r['forma_pago'] ?? $x['forma_pago'] ?? ''),
            'metodo_pago' => (string)($r['metodo_pago'] ?? ''),
            'moneda' => $moneda,
            'tc_factura' => $tc,
            'moneda_importes' => 'MXN',
            'factor_conversion_mxn' => $factor,
            'total' => (float)($x['total_xml'] ?? $r['total_mxn'] ?? 0) * (isset($x['total_xml']) ? $factor : 1),
            'subtotal' => (float)($x['subtotal_xml'] ?? 0) * $factor,
            'base_iva' => (float)($r['base_iva_16_mxn'] ?? (($x['base_iva'] ?? 0) * $factor)),
            'iva_t' => (float)($r['iva_16_mxn'] ?? (($x['iva_16'] ?? 0) * $factor)),
            'base_iva_r' => 0,
            'iva_r' => (float)($r['iva_retenido_mxn'] ?? (($x['iva_retenido'] ?? 0) * $factor)),
            'base_isr_r' => 0,
            'isr_r' => (float)($r['isr_retenido_mxn'] ?? (($x['isr_retenido'] ?? 0) * $factor)),
            'base_iva_8' => (float)($r['base_iva_8_mxn'] ?? 0),
            'iva_8' => (float)($r['iva_8_mxn'] ?? 0),
            'base_norte' => 0,
            'iva_norte' => 0,
            'base_sur' => 0,
            'iva_sur' => 0,
            'numero_pagos' => '',
            'uso_cfdi' => ''
        ];
    };

    $filasAnteriores = [];
    foreach ($anteriores as $r) {
        $uuid = trim((string)($r['uuid_factura'] ?? ''));
        if ($uuid === '') continue;
        $fila = $baseFila($r, $uuid, 'MES ANTERIOR PAGADO EN ESTE MES', $extra);
        // En este grupo el monto fiscal relevante es la aplicación que entró a la DIOT.
        if (isset($r['pago_mxn'])) $fila['total'] = (float)$r['pago_mxn'];
        $filasAnteriores[] = $fila;
    }

    // Todo lo emitido en el mes que NO perteneció fiscalmente a ese mes porque
    // quedó pendiente, se pagó después o tiene pago manual posterior sin REP.
    $filasTransito = [];
    $agregados = [];

    foreach ($pendientes as $r) {
        $uuid = trim((string)($r['uuid'] ?? ''));
        if ($uuid === '') continue;
        $fila = $baseFila($r, $uuid, 'EN TRÁNSITO - PENDIENTE AL CIERRE', $extra);
        $fila['total'] = (float)($r['total_mxn'] ?? $fila['total']);
        $filasTransito[] = $fila;
        $agregados[$uuid] = true;
    }

    foreach ($posteriores as $r) {
        $uuid = trim((string)($r['uuid_factura'] ?? ''));
        if ($uuid === '' || isset($agregados[$uuid])) continue;
        $fila = $baseFila($r, $uuid, 'EN TRÁNSITO - PAGADO EN MES POSTERIOR', $extra);
        $filasTransito[] = $fila;
        $agregados[$uuid] = true;
    }

    foreach ($usuarioSinRep as $r) {
        $uuid = trim((string)($r['uuid_factura'] ?? ''));
        if ($uuid === '' || isset($agregados[$uuid])) continue;
        $fila = $baseFila($r, $uuid, 'EN TRÁNSITO - PAGO POSTERIOR SIN REP', $extra);
        $fila['total'] = (float)($r['total_mxn'] ?? $fila['total']);
        $filasTransito[] = $fila;
        $agregados[$uuid] = true;
    }

    echo json_encode([
        'success' => true,
        'anteriores' => $filasAnteriores,
        'transito' => $filasTransito,
        'conteos' => [
            'anteriores' => count($filasAnteriores),
            'transito' => count($filasTransito),
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    seguridad_log_error($e, 'exportar_diot_transitos');
    echo json_encode([
        'success' => false,
        'error' => 'No fue posible obtener los movimientos auxiliares DIOT.'
    ], JSON_UNESCAPED_UNICODE);
}
