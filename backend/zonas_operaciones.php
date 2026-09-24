<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    if ($accion == 'guardar') {
        $id = $_POST['id_zona'] ?? '';
        $nombre = strtoupper(trim($_POST['nombre_zona']));

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
            exit;
        }

        if (!empty($id)) {
            $stmt = $pdo->prepare("UPDATE zonas SET nombre_zona = ? WHERE id_zona = ?");
            $stmt->execute([$nombre, $id]);
            echo json_encode(['success' => true, 'message' => 'Zona actualizada.']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO zonas (nombre_zona) VALUES (?)");
            $stmt->execute([$nombre]);
            echo json_encode(['success' => true, 'message' => 'Zona creada.']);
        }
    } 
    elseif ($accion == 'eliminar') {
        $id = $_POST['id_zona'] ?? '';
        $stmt = $pdo->prepare("DELETE FROM zonas WHERE id_zona = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Zona eliminada correctamente.']);
    }

} catch (PDOException $e) {
    // Si falla por integridad (porque hay empresas en esa zona), mandamos un error claro
    if ($e->getCode() == '23000') {
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar: Esta zona está siendo usada por empresas o usuarios.']);
    } else {
        seguridad_log_error($e, 'zonas_operaciones'); echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación de zona.']);
    }
}