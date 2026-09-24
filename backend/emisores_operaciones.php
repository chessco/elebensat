<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true, false, true);
header('Content-Type: application/json');

if (!isset($_SESSION['id_empresa'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada']);
    exit;
}

$id_empresa = $_SESSION['id_empresa'];
$accion = $_GET['accion'] ?? '';

// Desde aquí solo leemos datos; liberar el lock permite otras peticiones PHP en paralelo.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    // 1. LISTAR PROVEEDORES - DataTables server-side
    if ($accion === 'listar') {
        $draw = (int)($_GET['draw'] ?? 0);
        $start = max(0, (int)($_GET['start'] ?? 0));
        $length = (int)($_GET['length'] ?? 10);
        if ($length < 1) $length = 10;
        if ($length > 100) $length = 100;

        $search = trim((string)($_GET['search']['value'] ?? ''));

        $columnasOrden = [
            0 => 'rfc',
            1 => 'nombre',
            2 => 'correo',
            3 => 'tipo_tercero',
            4 => 'tipo_operacion',
            5 => 'region_diot',
            6 => 'aplica_diot',
            7 => 'id_emisor'
        ];

        $orderCol = (int)($_GET['order'][0]['column'] ?? 1);
        $orderDir = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = $columnasOrden[$orderCol] ?? 'nombre';

        // Total de registros de la empresa.
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM cat_emisores WHERE id_empresa = ?");
        $stmtTotal->execute([$id_empresa]);
        $recordsTotal = (int)$stmtTotal->fetchColumn();

        $where = " WHERE id_empresa = :id_empresa ";
        $params = [':id_empresa' => $id_empresa];

        if ($search !== '') {
            $where .= " AND (
                rfc LIKE :buscar_rfc
                OR nombre LIKE :buscar_nombre
                OR COALESCE(correo,'') LIKE :buscar_correo
                OR COALESCE(tipo_tercero,'') LIKE :buscar_tercero
                OR COALESCE(tipo_operacion,'') LIKE :buscar_operacion
                OR COALESCE(region_diot,'') LIKE :buscar_region
            ) ";
            $buscar = '%' . $search . '%';
            $params[':buscar_rfc'] = $buscar;
            $params[':buscar_nombre'] = $buscar;
            $params[':buscar_correo'] = $buscar;
            $params[':buscar_tercero'] = $buscar;
            $params[':buscar_operacion'] = $buscar;
            $params[':buscar_region'] = $buscar;
        }

        // Total después del filtro.
        $stmtFiltrado = $pdo->prepare("SELECT COUNT(*) FROM cat_emisores" . $where);
        $stmtFiltrado->execute($params);
        $recordsFiltered = (int)$stmtFiltrado->fetchColumn();

        $sql = "SELECT *
                FROM cat_emisores
                $where
                ORDER BY $orderBy $orderDir
                LIMIT :inicio, :cantidad";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':inicio', $start, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $length, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // 2. GUARDAR (NUEVO O EDICIÓN)
    if ($accion === 'guardar') {
        $id = $_POST['id_emisor'] ?? '';
        $rfc = strtoupper(trim($_POST['rfc']));
        $nombre = strtoupper(trim($_POST['nombre']));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $tipo_tercero = $_POST['tipo_tercero'];
        $tipo_operacion = $_POST['tipo_operacion'];
        $aplica_diot = isset($_POST['aplica_diot']) ? 1 : 0;
        $aplica_diferencia_base_no_objeto = isset($_POST['aplica_diferencia_base_no_objeto']) ? 1 : 0;
        $num_id_fiscal = strtoupper(trim($_POST['num_id_fiscal'] ?? ''));
        $nombre_extranjero = strtoupper(trim($_POST['nombre_extranjero'] ?? ''));
        $pais_residencia = strtoupper(trim($_POST['pais_residencia'] ?? ''));
        $nacionalidad = strtoupper(trim($_POST['nacionalidad'] ?? ''));
        $region_diot = strtoupper(trim($_POST['region_diot'] ?? 'RESTO'));
        $aplica_estimulo_fronterizo = isset($_POST['aplica_estimulo_fronterizo']) ? 1 : 0;
        $tasa_iva_fronteriza = 0.080000;

        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El correo del proveedor no tiene un formato válido.');
        }

        if (!in_array($region_diot, ['RESTO','RFN','RFS'], true)) $region_diot = 'RESTO';
        if ($region_diot === 'RFN' || $region_diot === 'RFS') {
            $aplica_estimulo_fronterizo = 1;
        } else {
            $aplica_estimulo_fronterizo = 0;
        }

        // Si el XML/proveedor usa RFC genérico extranjero, no puede quedar como nacional.
        if ($rfc === 'XEXX010101000') {
            $tipo_tercero = '05';
        }

        if ($tipo_tercero === '05') {
            if ($num_id_fiscal === '' || $nombre_extranjero === '' || $pais_residencia === '' || $nacionalidad === '') {
                throw new Exception('Para proveedor extranjero capture número de ID fiscal, nombre extranjero, país de residencia y nacionalidad.');
            }
        } else {
            $num_id_fiscal = '';
            $nombre_extranjero = '';
            $pais_residencia = '';
            $nacionalidad = '';
        }

        if (empty($id)) {
            // INSERTAR
            $sql = "INSERT INTO cat_emisores
                    (id_empresa, rfc, nombre, correo, tipo_tercero, tipo_operacion, aplica_diot, aplica_diferencia_base_no_objeto,
                     num_id_fiscal, nombre_extranjero, pais_residencia, nacionalidad,
                     region_diot, aplica_estimulo_fronterizo, tasa_iva_fronteriza, origen_region_diot, fecha_validacion_region)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MANUAL', NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id_empresa, $rfc, $nombre, $correo, $tipo_tercero, $tipo_operacion, $aplica_diot, $aplica_diferencia_base_no_objeto,
                            $num_id_fiscal, $nombre_extranjero, $pais_residencia, $nacionalidad,
                            $region_diot, $aplica_estimulo_fronterizo, $tasa_iva_fronteriza]);
        } else {
            // ACTUALIZAR (Validamos que pertenezca a la empresa)
            $sql = "UPDATE cat_emisores
                    SET rfc=?, nombre=?, correo=?, tipo_tercero=?, tipo_operacion=?, aplica_diot=?, aplica_diferencia_base_no_objeto=?,
                        num_id_fiscal=?, nombre_extranjero=?, pais_residencia=?, nacionalidad=?,
                        region_diot=?, aplica_estimulo_fronterizo=?, tasa_iva_fronteriza=?,
                        origen_region_diot='MANUAL', fecha_validacion_region=NOW()
                    WHERE id_emisor=? AND id_empresa=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$rfc, $nombre, $correo, $tipo_tercero, $tipo_operacion, $aplica_diot, $aplica_diferencia_base_no_objeto,
                            $num_id_fiscal, $nombre_extranjero, $pais_residencia, $nacionalidad,
                            $region_diot, $aplica_estimulo_fronterizo, $tasa_iva_fronteriza,
                            $id, $id_empresa]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 3. ELIMINAR
    if ($accion === 'eliminar') {
        $id = $_POST['id_emisor'];
        // Validamos que sea de la empresa para no borrar ajenos
        $stmt = $pdo->prepare("DELETE FROM cat_emisores WHERE id_emisor = ? AND id_empresa = ?");
        if ($stmt->execute([$id, $id_empresa])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo eliminar']);
        }
        exit;
    }

} catch (Exception $e) {
    seguridad_log_error($e, 'emisores_operaciones'); echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación de emisor.']);
}
?>