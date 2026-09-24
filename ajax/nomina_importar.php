<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/nomina_archivo.php';
require_once __DIR__ . '/../includes/nomina_fuente.php';

function nomina_fecha_post(string $name): ?string {
    $v = trim((string)($_POST[$name] ?? ''));
    if ($v === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');

    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['token'] ?? '')));
    if (strlen($token) !== 48) throw new RuntimeException('La previsualización ya no es válida. Seleccione nuevamente el archivo.');
    $info = $_SESSION['nomina_preview'][$token] ?? null;
    if (!$info || (int)$info['id_empresa'] !== $idEmpresa || time() - (int)$info['created'] > 7200 || !is_file($info['path'])) throw new RuntimeException('La previsualización expiró. Seleccione nuevamente el archivo.');

    $st = $pdo->prepare('SELECT rfc,razon_social FROM empresas WHERE id_empresa=? AND activo=1 LIMIT 1');
    $st->execute([$idEmpresa]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$empresa) throw new RuntimeException('No se encontró la empresa activa.');

    $ext = strtolower((string)($info['ext'] ?? pathinfo((string)$info['name'], PATHINFO_EXTENSION)));
    $parser = new NominaArchivoParser($info['path'], 'Sheet', $ext);
    $r = $parser->parse((string)$empresa['rfc'], (string)$empresa['razon_social']);
    if (!$r['valid']) throw new RuntimeException('El archivo no pasó la validación: ' . implode(' ', $r['errors']));
    $m = $r['metadata'];

    $tipoNomina = strtoupper(trim((string)($_POST['tipo_nomina'] ?? $m['tipo_sugerido'] ?? 'SEMANAL')));
    $tiposPermitidos = ['SEMANAL','ACUMULADO_PERIODOS','FINIQUITO','AGUINALDO','EXTRAORDINARIA','PTU','PRIMA_VACACIONAL','OTRO'];
    if (!in_array($tipoNomina, $tiposPermitidos, true)) $tipoNomina = (string)($m['tipo_sugerido'] ?? 'SEMANAL');

    $periodoDesde = (int)$m['periodo_desde'];
    $periodoHasta = (int)$m['periodo_hasta'];

    $fechaDesde = $m['fecha_desde'];
    $fechaHasta = $m['fecha_hasta'];
    $fechaPeriodo1 = null;
    $diasPeriodo = 7;
    if ($m['requiere_fechas']) {
        $fechaPeriodo1 = nomina_fecha_post('fecha_periodo1');
        if (!$fechaPeriodo1) throw new RuntimeException('Capture la fecha de inicio del periodo 1.');
        if ($periodoDesde < 1 || $periodoHasta < $periodoDesde) throw new RuntimeException('El rango de periodos del archivo no es válido.');

        // Cada periodo semanal comienza cada 7 días tomando como ancla el periodo 1.
        $base = new DateTimeImmutable($fechaPeriodo1);
        $fechaDesde = $base->modify('+' . (($periodoDesde - 1) * $diasPeriodo) . ' days')->format('Y-m-d');
        $inicioUltimo = $base->modify('+' . (($periodoHasta - 1) * $diasPeriodo) . ' days');
        $fechaHasta = $inicioUltimo->modify('+' . ($diasPeriodo - 1) . ' days')->format('Y-m-d');
    }
    $periodoCompat = $periodoHasta;
    $referenciaNomina = trim((string)($_POST['referencia_nomina'] ?? ''));
    if ($referenciaNomina === '') {
        $pref = ['SEMANAL'=>'SEM','ACUMULADO_PERIODOS'=>'ACU','FINIQUITO'=>'FIN','AGUINALDO'=>'AGU','EXTRAORDINARIA'=>'EXT','PTU'=>'PTU','PRIMA_VACACIONAL'=>'PV','OTRO'=>'OTR'][$tipoNomina] ?? 'NOM';
        $periodoRef = $periodoDesde === $periodoHasta ? (string)$periodoHasta : ($periodoDesde . '-' . $periodoHasta);
        $referenciaNomina = $pref . '-' . $periodoRef . '-' . $m['anio'];
    }
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));
    $hash = hash_file('sha256', $info['path']);

    // Fallar antes de modificar datos si no se ha aplicado la migración.
    nf_require_schema($pdo);
    $snapshot = $parser->fullReport($r);

    $pdo->beginTransaction();

    if ($m['requiere_fechas'] && $fechaPeriodo1) {
        $cfg = $pdo->prepare("INSERT INTO nomina_periodo_config (id_empresa,anio,fecha_inicio_periodo1,dias_periodo,id_usuario) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE fecha_inicio_periodo1=VALUES(fecha_inicio_periodo1),dias_periodo=VALUES(dias_periodo),id_usuario=VALUES(id_usuario),fecha_actualizacion=NOW()");
        $cfg->execute([$idEmpresa,(int)$m['anio'],$fechaPeriodo1,$diasPeriodo,$idUsuario]);
    }

    $se = $pdo->prepare('SELECT id_importacion FROM nomina_importaciones WHERE id_empresa=? AND anio=? AND COALESCE(periodo_desde,periodo)=? AND COALESCE(periodo_hasta,periodo)=? AND fecha_desde=? AND fecha_hasta=? AND tipo_nomina=? FOR UPDATE');
    $se->execute([$idEmpresa,$m['anio'],$periodoDesde,$periodoHasta,$fechaDesde,$fechaHasta,$tipoNomina]);
    $idImport = (int)($se->fetchColumn() ?: 0);
    $reimport = $idImport > 0;

    if ($reimport) {
        $pdo->prepare('DELETE FROM nomina_detalles WHERE id_importacion=?')->execute([$idImport]);
        $up = $pdo->prepare("UPDATE nomina_importaciones SET periodo=?,periodo_desde=?,periodo_hasta=?,fecha_desde=?,fecha_hasta=?,tipo_nomina=?,referencia_nomina=?,observaciones=?,tipo_nomina_empleado=?,tipo_nomina_procesada=?,empresa_archivo=?,rfc_empresa_archivo=?,nombre_archivo=?,hash_archivo=?,total_empleados=?,total_percepciones=?,total_deducciones=?,total_neto=?,estatus='REIMPORTADO',id_usuario=?,fecha_importacion=NOW() WHERE id_importacion=?");
        $up->execute([$periodoCompat,$periodoDesde,$periodoHasta,$fechaDesde,$fechaHasta,$tipoNomina,$referenciaNomina,$observaciones,$m['tipo_nomina_empleado'],$m['tipo_nomina_procesada'],$m['empresa'],$m['rfc_empresa'],$info['name'],$hash,$r['totals']['empleados'],$r['totals']['percepciones'],$r['totals']['deducciones'],$r['totals']['neto'],$idUsuario,$idImport]);
    } else {
        $in = $pdo->prepare("INSERT INTO nomina_importaciones (id_empresa,anio,periodo,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta,tipo_nomina,referencia_nomina,observaciones,tipo_nomina_empleado,tipo_nomina_procesada,empresa_archivo,rfc_empresa_archivo,nombre_archivo,hash_archivo,total_empleados,total_percepciones,total_deducciones,total_neto,estatus,id_usuario) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'IMPORTADO',?)");
        $in->execute([$idEmpresa,$m['anio'],$periodoCompat,$periodoDesde,$periodoHasta,$fechaDesde,$fechaHasta,$tipoNomina,$referenciaNomina,$observaciones,$m['tipo_nomina_empleado'],$m['tipo_nomina_procesada'],$m['empresa'],$m['rfc_empresa'],$info['name'],$hash,$r['totals']['empleados'],$r['totals']['percepciones'],$r['totals']['deducciones'],$r['totals']['neto'],$idUsuario]);
        $idImport = (int)$pdo->lastInsertId();
    }

    $id = $pdo->prepare('INSERT INTO nomina_detalles (id_importacion,id_empresa,numero_empleado,nombre,apellido_paterno,apellido_materno,rfc,total_percepciones,total_deducciones,neto,renglon_origen) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($r['employees'] as $e) {
        $id->execute([$idImport,$idEmpresa,$e['numero_empleado'],$e['nombre'],$e['apellido_paterno'],$e['apellido_materno'],$e['rfc'],$e['total_percepciones'],$e['total_deducciones'],$e['neto'],$e['renglon']]);
    }
    $info['ext']=$ext;
    $fuente=nf_save($pdo,$idEmpresa,$idImport,$idUsuario,$info,$snapshot,[
        'version'=>1,'archivo'=>$m,'fecha_desde_importada'=>$fechaDesde,'fecha_hasta_importada'=>$fechaHasta,
        'tipo_nomina_importado'=>$tipoNomina,'referencia_nomina'=>$referenciaNomina,
        'totales_importados'=>$r['totals'],
        'nota'=>'Valores de la hoja sin consolidar. Fórmulas, estilos y demás hojas conservados en el archivo original. No implica equivalencia validada con XML.'
    ]);
    $pdo->commit();

    @unlink($info['path']); unset($_SESSION['nomina_preview'][$token]);
    echo json_encode([
        'success'=>true,'id_importacion'=>$idImport,'reimportado'=>$reimport,'empleados'=>$r['totals']['empleados'],
        'total_percepciones'=>$r['totals']['percepciones'],'total_deducciones'=>$r['totals']['deducciones'],'total_neto'=>$r['totals']['neto'],
        'renglones_adicionales'=>$r['totals']['renglones_adicionales']??0,
        'reporte_completo'=>['id_fuente'=>$fuente['id_fuente'],'columnas'=>$snapshot['total_columnas'],'filas'=>$snapshot['total_filas'],'pendientes'=>$fuente['pendientes']],
        'tipo_nomina'=>$tipoNomina,'referencia_nomina'=>$referenciaNomina,
        'message'=>$reimport ? 'Nómina reimportada y actualizada correctamente.' : 'Nómina importada correctamente.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($e, 'nomina_importar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
