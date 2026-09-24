<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/nomina_archivo.php';
require_once __DIR__ . '/../includes/nomina_fuente.php';

try {
    seguridad_exigir_sesion($pdo, true, false, true);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'nomina_archivo');

    if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) throw new RuntimeException('Seleccione el archivo de nómina.');
    if ((int)($_FILES['archivo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo recibir el archivo de nómina.');

    $original = basename((string)($_FILES['archivo']['name'] ?? 'nomina.xls'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls','xlsx'], true)) throw new RuntimeException('El archivo esperado debe ser XLS o XLSX.');
    if ((int)($_FILES['archivo']['size'] ?? 0) > 25 * 1024 * 1024) throw new RuntimeException('El archivo excede el límite de 25 MB.');

    $st = $pdo->prepare('SELECT rfc, razon_social FROM empresas WHERE id_empresa=? AND activo=1 LIMIT 1');
    $st->execute([$idEmpresa]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$empresa) throw new RuntimeException('No se encontró la empresa activa.');

    $parser = new NominaArchivoParser($_FILES['archivo']['tmp_name'], 'Sheet', $ext);
    $parsed = $parser->parse((string)$empresa['rfc'], (string)$empresa['razon_social']);
    $snapshot = $parser->fullReport($parsed);
    nf_require_schema($pdo);
    $fields = nf_fields($pdo,$idEmpresa,$snapshot['columnas']);

    $m = $parsed['metadata'];

    // Calendario base de periodos: una fecha de inicio del periodo 1 por empresa y año.
    $fechaPeriodo1 = null;
    $diasPeriodo = 7;
    if (!empty($m['anio'])) {
        $sc = $pdo->prepare('SELECT fecha_inicio_periodo1,dias_periodo FROM nomina_periodo_config WHERE id_empresa=? AND anio=? LIMIT 1');
        $sc->execute([$idEmpresa,(int)$m['anio']]);
        if ($cfg = $sc->fetch(PDO::FETCH_ASSOC)) {
            $fechaPeriodo1 = $cfg['fecha_inicio_periodo1'];
            $diasPeriodo = max(1,(int)$cfg['dias_periodo']);
        }
    }

    $duplicates = [];
    if ($m['anio'] && $m['periodo_desde'] && $m['periodo_hasta']) {
        if ($m['formato_archivo'] === 'ACUMULADO_PERIODOS') {
            $sd = $pdo->prepare("SELECT id_importacion,nombre_archivo,fecha_importacion,total_empleados,total_neto,tipo_nomina,referencia_nomina,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta FROM nomina_importaciones WHERE id_empresa=? AND anio=? AND COALESCE(periodo_desde,periodo)=? AND COALESCE(periodo_hasta,periodo)=?");
            $sd->execute([$idEmpresa,$m['anio'],$m['periodo_desde'],$m['periodo_hasta']]);
            $duplicates = $sd->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif ($m['fecha_desde'] && $m['fecha_hasta']) {
            $sd = $pdo->prepare("SELECT id_importacion,nombre_archivo,fecha_importacion,total_empleados,total_neto,tipo_nomina,referencia_nomina,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta FROM nomina_importaciones WHERE id_empresa=? AND anio=? AND periodo=? AND fecha_desde=? AND fecha_hasta=?");
            $sd->execute([$idEmpresa,$m['anio'],$m['periodo'],$m['fecha_desde'],$m['fecha_hasta']]);
            $duplicates = $sd->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'visor_nomina';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear el área temporal de nómina.');
    $viejos = array_merge(glob($dir . DIRECTORY_SEPARATOR . '*.xls') ?: [], glob($dir . DIRECTORY_SEPARATOR . '*.xlsx') ?: []);
    foreach ($viejos as $old) if (@filemtime($old) < time() - 7200) @unlink($old);

    $token = bin2hex(random_bytes(24));
    $dest = $dir . DIRECTORY_SEPARATOR . $token . '.' . $ext;
    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dest)) throw new RuntimeException('No se pudo preparar el archivo para importar.');

    $_SESSION['nomina_preview'][$token] = ['path'=>$dest,'name'=>$original,'ext'=>$ext,'id_empresa'=>$idEmpresa,'created'=>time()];

    echo json_encode([
        'success'=>true,'token'=>$token,'valid'=>$parsed['valid'],'errors'=>$parsed['errors'],
        'metadata'=>$parsed['metadata'],'totals'=>$parsed['totals'],'header_row'=>$parsed['header_row'],
        'reporte_completo'=>['columnas'=>$snapshot['total_columnas'],'filas'=>$snapshot['total_filas'],'pendientes'=>$fields['pendientes']],
        'employees'=>$parsed['employees'],'duplicate'=>null,'duplicates'=>$duplicates,
        'empresa_activa'=>['rfc'=>$empresa['rfc'],'razon_social'=>$empresa['razon_social']],
        'calendario_periodos'=>['fecha_periodo1'=>$fechaPeriodo1,'dias_periodo'=>$diasPeriodo],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    seguridad_log_error($e, 'nomina_previsualizar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
