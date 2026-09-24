<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';

try {
    $u = seguridad_exigir_sesion($pdo, true);
    $idUsuario = (int)$u['id_usuario'];
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'contpaq_cheques');

    $tipoOrigen = strtoupper(trim((string)($_GET['tipo_origen'] ?? 'C')));
    if (!in_array($tipoOrigen, ['C', 'T'], true)) {
        throw new RuntimeException('Tipo de movimiento no válido.');
    }

    $idOrigen = (int)($_GET['id_origen'] ?? $_GET['id_contpaq_cheque'] ?? 0);
    if ($idEmpresa <= 0 || $idOrigen <= 0) {
        throw new RuntimeException('Movimiento no válido.');
    }

    if ($tipoOrigen === 'C') {
        $st = $pdo->prepare(
            'SELECT id_contpaq_cheque AS id_origen, tipo_documento, folio, fecha, codigo_persona, persona_rfc,
                    beneficiario_pagador, id_cuenta_cheques, codigo_moneda, codigo_moneda_tipo_cambio,
                    tipo_cambio, total, referencia, concepto, es_cancelado, num_pol, id_poliza,
                    es_anticipo, guid, datos_origen_json, fecha_ultima_sync
               FROM contpaq_cheques
              WHERE id_empresa=? AND id_contpaq_cheque=?
              LIMIT 1'
        );
        $st->execute([$idEmpresa, $idOrigen]);
    } else {
        $st = $pdo->prepare(
            'SELECT id_contpaq_egreso AS id_origen, tipo_documento, folio, fecha, codigo_persona, persona_rfc,
                    beneficiario_pagador, id_cuenta_cheques, codigo_moneda, codigo_moneda_tipo_cambio,
                    tipo_cambio, total, referencia, concepto, es_cancelado, num_pol, id_poliza,
                    es_anticipo, guid, datos_origen_json, fecha_ultima_sync
               FROM contpaq_egresos
              WHERE id_empresa=? AND id_contpaq_egreso=?
              LIMIT 1'
        );
        $st->execute([$idEmpresa, $idOrigen]);
    }

    $movimiento = $st->fetch(PDO::FETCH_ASSOC);
    if (!$movimiento) {
        throw new RuntimeException('No se encontró el movimiento solicitado en la copia local.');
    }
    $movimiento['tipo_origen'] = $tipoOrigen;

    if ($tipoOrigen === 'C') {
        $sqlDocs = "SELECT id_contpaq_dispersion, uuid, uuid_rep, guid_ref, num_nodo_pago,
                           fecha_pago, total_pago, tipo_cambio, total_pago_comprobante,
                           origen_folio, origen_fecha, origen_tipo_documento, codigo_persona,
                           persona_rfc, beneficiario_pagador, concepto, total_origen,
                           conciliado, estatus_conciliacion, observaciones AS observacion_conciliacion,
                           fecha_conciliacion, id_usuario_concilia,
                           datos_origen_json, fecha_ultima_sync
                      FROM contpaq_dispersiones_pagos
                     WHERE id_empresa=? AND tipo_origen='C' AND id_contpaq_cheque=?
                     ORDER BY fecha_pago, id_contpaq_dispersion";
    } else {
        $sqlDocs = "SELECT id_contpaq_dispersion, uuid, uuid_rep, guid_ref, num_nodo_pago,
                           fecha_pago, total_pago, tipo_cambio, total_pago_comprobante,
                           origen_folio, origen_fecha, origen_tipo_documento, codigo_persona,
                           persona_rfc, beneficiario_pagador, concepto, total_origen,
                           conciliado, estatus_conciliacion, observaciones AS observacion_conciliacion,
                           fecha_conciliacion, id_usuario_concilia,
                           datos_origen_json, fecha_ultima_sync
                      FROM contpaq_dispersiones_pagos
                     WHERE id_empresa=? AND tipo_origen='T' AND id_contpaq_egreso=?
                     ORDER BY fecha_pago, id_contpaq_dispersion";
    }
    $st = $pdo->prepare($sqlDocs);
    $st->execute([$idEmpresa, $idOrigen]);
    $documentos = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalDocs=count($documentos);
    $conciliados=0; $parciales=0; $errores=0; $cancelados=0;
    foreach($documentos as $d){
        $e=strtoupper(trim((string)($d['estatus_conciliacion'] ?? '')));
        if(in_array($e,['CONCILIADO','YA_CONCILIADO'],true)) $conciliados++;
        elseif($e==='PARCIAL') $parciales++;
        elseif($e==='ERROR') $errores++;
        elseif($e==='CANCELADO') $cancelados++;
    }
    if((int)($movimiento['es_cancelado'] ?? 0)===1) $movEstado='CANCELADO';
    elseif($totalDocs===0) $movEstado='SIN_DISPERSION';
    elseif($errores>0) $movEstado='ERROR';
    elseif($parciales>0 || ($conciliados>0 && $conciliados<$totalDocs)) $movEstado='PARCIAL';
    elseif($conciliados===$totalDocs) $movEstado='CONCILIADO';
    else $movEstado='NO_CONCILIADO';
    $movimiento['estatus_conciliacion_movimiento']=$movEstado;
    $movimiento['dispersiones_total']=$totalDocs;
    $movimiento['dispersiones_conciliadas']=$conciliados;

    if (!empty($movimiento['datos_origen_json'])) {
        $tmp = json_decode($movimiento['datos_origen_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $movimiento['datos_origen'] = $tmp;
        }
    }
    unset($movimiento['datos_origen_json']);

    foreach ($documentos as &$d) {
        if (!empty($d['datos_origen_json'])) {
            $tmp = json_decode($d['datos_origen_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $d['datos_origen'] = $tmp;
            }
        }
        unset($d['datos_origen_json']);
    }
    unset($d);

    echo json_encode([
        'success' => true,
        'movimiento' => $movimiento,
        'documentos' => $documentos,
        'total_documentos' => count($documentos),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
