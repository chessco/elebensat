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

    $anio = (int)($_POST['anio'] ?? 0);
    $fecha = trim((string)($_POST['fecha_periodo1'] ?? ''));

    if ($idEmpresa <= 0) throw new RuntimeException('No hay empresa activa.');
    if ($anio < 2000 || $anio > 2200) throw new RuntimeException('El año no es válido.');

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        throw new RuntimeException('La fecha de inicio del periodo 1 no es válida.');
    }

    // La configuración es única por empresa + año. Cambiar la fecha sustituye
    // inmediatamente la anterior y queda disponible para futuras previsualizaciones.
    $sql = "INSERT INTO nomina_periodo_config
                (id_empresa, anio, fecha_inicio_periodo1, dias_periodo, id_usuario)
            VALUES (?, ?, ?, 7, ?)
            ON DUPLICATE KEY UPDATE
                fecha_inicio_periodo1 = VALUES(fecha_inicio_periodo1),
                dias_periodo = 7,
                id_usuario = VALUES(id_usuario),
                fecha_actualizacion = NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([$idEmpresa, $anio, $fecha, $idUsuario]);

    echo json_encode([
        'success' => true,
        'id_empresa' => $idEmpresa,
        'anio' => $anio,
        'fecha_periodo1' => $fecha,
        'message' => 'Fecha del periodo 1 guardada correctamente.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    seguridad_log_error($e, 'nomina_guardar_periodo1');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
