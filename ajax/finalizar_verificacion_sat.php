<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/db.php';
require_once '../includes/seguridad.php';

function out_error(string $m, int $http=400): void {
    http_response_code($http);
    echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    seguridad_exigir_sesion($pdo, true);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) out_error('No hay empresa activa.');

    $id = (int)($_POST['id_verificacion'] ?? 0);
    if ($id <= 0) out_error('Verificación inválida.');
    $cancelada = !empty($_POST['cancelada']);

    $st = $pdo->prepare("SELECT id_verificacion, fecha_inicio, total_cfdi,
                                total_consultados, total_vigentes, total_cancelados,
                                total_cambios, total_errores
                         FROM verificaciones_sat
                         WHERE id_verificacion=? AND id_empresa=? LIMIT 1");
    $st->execute([$id,$idEmpresa]);
    $cab = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cab) out_error('No se encontró la corrida de verificación.',404);

    // Los acumulados ya se fueron guardando en MYSQL durante cada consulta.
    // Aquí solamente cerramos la corrida; NO recalculamos desde el detalle,
    // porque el detalle contiene únicamente CFDI que cambiaron de estatus.
    $consultados = (int)($cab['total_consultados'] ?? 0);
    $vigentes = (int)($cab['total_vigentes'] ?? 0);
    $cancelados = (int)($cab['total_cancelados'] ?? 0);
    $cambios = (int)($cab['total_cambios'] ?? 0);
    $errores = (int)($cab['total_errores'] ?? 0);

    $estado = $cancelada ? 'CANCELADA' : 'COMPLETADA';
    $dur = max(0, time() - strtotime((string)$cab['fecha_inicio']));

    $up = $pdo->prepare("UPDATE verificaciones_sat SET
        fecha_fin=NOW(), ultimo_indice_procesado=total_consultados,
        estatus_proceso=?, duracion_segundos=?
        WHERE id_verificacion=? AND id_empresa=?");
    $up->execute([$estado, $dur, $id, $idEmpresa]);

    echo json_encode(['ok'=>true,'id_verificacion'=>$id,'estatus_proceso'=>$estado,'resumen'=>[
        'consultados'=>$consultados,
        'vigentes'=>$vigentes,
        'cancelados'=>$cancelados,
        'cambios'=>$cambios,
        'errores'=>$errores,
        'duracion_segundos'=>$dur
    ]], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'finalizar_verificacion_sat');
    out_error($e->getMessage() ?: 'No se pudo cerrar la bitácora de verificación.',500);
}
