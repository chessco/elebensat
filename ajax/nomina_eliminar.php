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

    $idImportacion = (int)($_POST['id_importacion'] ?? 0);
    if ($idImportacion <= 0) throw new RuntimeException('Importación de nómina no válida.');

    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM nomina_importaciones WHERE id_importacion=? AND id_empresa=? FOR UPDATE');
    $st->execute([$idImportacion, $idEmpresa]);
    $imp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$imp) throw new RuntimeException('La nómina ya no existe o no pertenece a la empresa activa.');

    $log = $pdo->prepare("INSERT INTO nomina_eliminaciones (id_importacion_original,id_empresa,anio,periodo,periodo_desde,periodo_hasta,fecha_desde,fecha_hasta,tipo_nomina,referencia_nomina,nombre_archivo,total_empleados,total_neto,id_usuario,fecha_eliminacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $log->execute([
        $imp['id_importacion'],$imp['id_empresa'],$imp['anio'],$imp['periodo'],$imp['periodo_desde'] ?? $imp['periodo'],$imp['periodo_hasta'] ?? $imp['periodo'],$imp['fecha_desde'],$imp['fecha_hasta'],
        $imp['tipo_nomina'] ?? 'SEMANAL',$imp['referencia_nomina'] ?? null,$imp['nombre_archivo'],$imp['total_empleados'],$imp['total_neto'],$idUsuario
    ]);

    // ON DELETE CASCADE elimina todo el detalle de esa nómina.
    $pdo->prepare('DELETE FROM nomina_importaciones WHERE id_importacion=? AND id_empresa=?')->execute([$idImportacion,$idEmpresa]);
    $pdo->commit();

    echo json_encode(['success'=>true,'message'=>'Nómina eliminada completamente. Se conservó la bitácora de eliminación.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($e, 'nomina_eliminar');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
