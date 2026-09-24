<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';

try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');

    $idConciliacion = (int)($_GET['id_conciliacion'] ?? 0);
    $rfc = strtoupper(trim((string)($_GET['rfc'] ?? '')));
    if ($idConciliacion <= 0 || $rfc === '') {
        throw new RuntimeException('Empleado o conciliación no válidos.');
    }

    $st = $pdo->prepare("SELECT id_conciliacion,id_empresa,anio,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta,tipo_nomina,fecha_conciliacion
                         FROM nomina_conciliaciones
                         WHERE id_conciliacion=? AND id_empresa=? LIMIT 1");
    $st->execute([$idConciliacion, $idEmpresa]);
    $conciliacion = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conciliacion) {
        throw new RuntimeException('No se encontró la conciliación.');
    }

    $st = $pdo->prepare("SELECT numero_empleado,rfc,nombre_completo,usa_desglose,
                                percepciones_visor,percepciones_reporte,diferencia_percepciones,
                                deducciones_visor,deducciones_reporte,diferencia_deducciones,
                                total_visor,total_reporte,diferencia,documentos_visor,
                                documentos_cancelados,periodos_reporte,estatus
                         FROM nomina_conciliacion_detalles
                         WHERE id_conciliacion=? AND id_empresa=? AND rfc=? LIMIT 1");
    $st->execute([$idConciliacion, $idEmpresa, $rfc]);
    $empleado = $st->fetch(PDO::FETCH_ASSOC);
    if (!$empleado) {
        throw new RuntimeException('No se encontró el empleado en esta conciliación.');
    }

    // IMPORTANTE: usar exactamente la misma regla con la que se realizó la conciliación
    // para que este detalle explique los importes guardados: FechaInicialPago dentro del rango real.
    $sql = "SELECT f.uuid,f.serie,f.folio,f.fecha_emision,f.estatus_sat,f.fecha_cancelacion,
                   f.nomina_tipo_nomina,f.nomina_fecha_pago,f.nomina_fecha_inicial_pago,
                   f.nomina_fecha_final_pago,f.nomina_num_dias_pagados,f.nomina_periodicidad_pago,
                   f.nomina_num_empleado,f.nomina_total_percepciones,f.nomina_total_deducciones,
                   f.nomina_total_otros_pagos,f.subtotal_xml,f.total_xml,f.moneda,
                   r.rfc rfc_receptor,r.nombre nombre_receptor,
                   e.rfc rfc_emisor,e.nombre nombre_emisor
            FROM facturas f
            INNER JOIN cat_receptores r ON r.id_empresa=f.id_empresa AND r.id_receptor=f.id_receptor
            LEFT JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor
            WHERE f.id_empresa=?
              AND f.id_tipo_comprobante='N'
              AND r.rfc=?
              AND f.nomina_fecha_inicial_pago BETWEEN ? AND ?
            ORDER BY f.nomina_fecha_inicial_pago,f.nomina_fecha_pago,f.fecha_emision,f.uuid";
    $st = $pdo->prepare($sql);
    $st->execute([$idEmpresa, $rfc, $conciliacion['fecha_desde'], $conciliacion['fecha_hasta']]);
    $cfdis = $st->fetchAll(PDO::FETCH_ASSOC);

    $totales = [
        'vigentes' => 0,
        'cancelados' => 0,
        'percepciones' => 0.0,
        'deducciones' => 0.0,
        'otros_pagos' => 0.0,
        'neto' => 0.0,
    ];
    foreach ($cfdis as &$c) {
        $vigente = strtoupper((string)($c['estatus_sat'] ?? 'VIGENTE')) === 'VIGENTE';
        $c['vigente_conciliacion'] = $vigente ? 1 : 0;
        if ($vigente) {
            $totales['vigentes']++;
            $totales['percepciones'] += (float)$c['nomina_total_percepciones'];
            $totales['deducciones'] += (float)$c['nomina_total_deducciones'];
            $totales['otros_pagos'] += (float)$c['nomina_total_otros_pagos'];
            $totales['neto'] += (float)$c['total_xml'];
        } else {
            $totales['cancelados']++;
        }
    }
    unset($c);
    foreach (['percepciones','deducciones','otros_pagos','neto'] as $k) {
        $totales[$k] = round($totales[$k], 2);
    }

    echo json_encode([
        'success' => true,
        'conciliacion' => $conciliacion,
        'empleado' => $empleado,
        'totales_cfdi' => $totales,
        'cfdis' => $cfdis,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    seguridad_log_error($e, 'nomina_conciliacion_empleado');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
