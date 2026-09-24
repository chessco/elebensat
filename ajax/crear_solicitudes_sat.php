<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'ver_solicitudes_sat'); } catch (Throwable $e) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>$e->getMessage()]); exit; }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function responder(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fechaValida(string $fecha): bool {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

function uuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
        responder(['success' => false, 'error' => 'La sesión ha terminado.'], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(['success' => false, 'error' => 'Método no permitido.'], 405);
    }

    $idEmpresa = (int)$_SESSION['id_empresa'];
    $idUsuario = (int)$_SESSION['id_usuario'];
    $tipo = strtolower(trim((string)($_POST['tipo'] ?? '')));
    $paquete = strtolower(trim((string)($_POST['paquete_tipo'] ?? 'xml')));
    $estadoComprobante = strtolower(trim((string)($_POST['estado_comprobante'] ?? 'todos')));
    $fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_POST['fecha_fin'] ?? ''));
    $motivo = trim((string)($_POST['motivo'] ?? 'Carga inicial'));
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));

    if (!in_array($tipo, ['emitidos', 'recibidos', 'ambos'], true)) {
        responder(['success' => false, 'error' => 'Seleccione emitidos, recibidos o ambos.'], 422);
    }
    if (!in_array($paquete, ['xml', 'metadata'], true)) {
        responder(['success' => false, 'error' => 'El paquete debe ser XML o metadata.'], 422);
    }
    // El filtro de estado solo aplica a METADATA. Para XML se conserva la lógica
    // actual del Worker/SAT (no se intenta pedir XML cancelados).
    if ($paquete === 'metadata') {
        if (!in_array($estadoComprobante, ['todos', 'cancelado'], true)) {
            responder(['success' => false, 'error' => 'El estatus de metadata no es válido.'], 422);
        }
    } else {
        $estadoComprobante = 'todos';
    }
    if (!fechaValida($fechaInicio) || !fechaValida($fechaFin) || $fechaInicio > $fechaFin) {
        responder(['success' => false, 'error' => 'El rango de fechas no es válido.'], 422);
    }
    if ($fechaFin > date('Y-m-d')) {
        responder(['success' => false, 'error' => 'La fecha final no puede ser posterior a hoy.'], 422);
    }

    $inicioObj = new DateTimeImmutable($fechaInicio);
    $finObj = new DateTimeImmutable($fechaFin);
    $maximoFin = $inicioObj->modify('+2 years')->modify('-1 day');
    if ($finObj > $maximoFin) {
        responder(['success' => false, 'error' => 'El rango máximo permitido es de 2 años. Genere otra solicitud para el periodo restante.'], 422);
    }
    if (mb_strlen($motivo) > 100 || mb_strlen($observaciones) > 500) {
        responder(['success' => false, 'error' => 'El motivo u observaciones exceden la longitud permitida.'], 422);
    }

    $stmtEmpresa = $pdo->prepare(
        "SELECT rfc, razon_social, descarga_sat_automatica, pfx_fiel_validado, pfx_fiel_fecha_vencimiento
         FROM empresas WHERE id_empresa = ? LIMIT 1"
    );
    $stmtEmpresa->execute([$idEmpresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
    if (!$empresa) {
        responder(['success' => false, 'error' => 'No se encontró la empresa activa.'], 404);
    }
    if ((int)($empresa['pfx_fiel_validado'] ?? 0) !== 1) {
        responder(['success' => false, 'error' => 'La empresa no tiene un PFX FIEL validado.'], 422);
    }
    if (empty($empresa['pfx_fiel_fecha_vencimiento']) || $empresa['pfx_fiel_fecha_vencimiento'] < date('Y-m-d')) {
        responder(['success' => false, 'error' => 'El PFX FIEL está vencido.'], 422);
    }

    // Un rango capturado por el usuario equivale a una sola solicitud por tipo.
    // Si se selecciona 'ambos', se generan exactamente dos solicitudes: emitidos y recibidos.
    $tipos = $tipo === 'ambos' ? ['emitidos', 'recibidos'] : [$tipo];
    $lote = uuidV4();
    $creadas = 0;
    $omitidas = 0;
    $detalles = [];

    $sqlInsert = "INSERT INTO descargas_sat
        (id_empresa, id_solicitud, fecha_inicio, fecha_fin, tipo, paquete_tipo, estado_comprobante,
         origen, id_usuario_solicita, motivo, observaciones, lote_manual,
         estatus, mensaje_sat, fecha_registro)
        VALUES
        (:id_empresa, NULL, :fecha_inicio, :fecha_fin, :tipo, :paquete_tipo, :estado_comprobante,
         'manual', :id_usuario_solicita, :motivo, :observaciones, :lote_manual,
         0, 'Pendiente de envío por SGKSAT', NOW())";
    $stmtInsert = $pdo->prepare($sqlInsert);

    $pdo->beginTransaction();
    foreach ($tipos as $tipoReal) {
        $stmtInsert->execute([
            ':id_empresa' => $idEmpresa,
            ':fecha_inicio' => $fechaInicio,
            ':fecha_fin' => $fechaFin,
            ':tipo' => $tipoReal,
            ':paquete_tipo' => $paquete,
            ':estado_comprobante' => $estadoComprobante,
            ':id_usuario_solicita' => $idUsuario,
            ':motivo' => $motivo !== '' ? $motivo : 'Carga inicial',
            ':observaciones' => $observaciones !== '' ? $observaciones : null,
            ':lote_manual' => $lote,
        ]);
        $creadas++;
        $filtroTexto = ($paquete === 'metadata' && $estadoComprobante === 'cancelado') ? ' solo cancelados' : '';
        $detalles[] = "Creada {$tipoReal} {$paquete}{$filtroTexto} {$fechaInicio} al {$fechaFin}.";
    }
    $pdo->commit();

    $textoTipos = $creadas === 2 ? 'dos solicitudes (Emitidos y Recibidos)' : 'una solicitud';
    $filtroMensaje = ($paquete === 'metadata' && $estadoComprobante === 'cancelado')
        ? ' de METADATA solo cancelados'
        : '';
    responder([
        'success' => true,
        'message' => "Se generó {$textoTipos}{$filtroMensaje} para el rango completo {$fechaInicio} al {$fechaFin}. El SAT determinará si la solicitud es aceptada o rechazada.",
        'creadas' => $creadas,
        'omitidas' => 0,
        'lote_manual' => $lote,
        'detalles' => $detalles,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    seguridad_log_error($e, 'crear_solicitudes_sat'); responder(['success' => false, 'error' => 'No se pudieron crear las solicitudes SAT.'], 500);
}
