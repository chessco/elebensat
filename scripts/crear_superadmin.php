<?php
/**
 * Script de inicialización de SuperAdmin y Empresa inicial
 * Uso: docker compose exec elebensat_app php scripts/crear_superadmin.php [usuario] [password]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$usuario = strtolower(trim((string)($argv[1] ?? 'admin')));
$password = (string)($argv[2] ?? 'Admin2026!');
$nombre = 'Administrador General';
$correo = 'admin@pitayacode.io';
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // 1. Crear empresa inicial si no existe
    $stmtEmp = $pdo->prepare("
        INSERT INTO empresas (rfc, razon_social, activo, modo_diot, criterio_fecha_pue)
        VALUES ('XEXX010101000', 'Empresa Principal', 1, 'normal', 'EMISION')
        ON DUPLICATE KEY UPDATE razon_social = VALUES(razon_social), activo = 1
    ");
    $stmtEmp->execute();

    $stmtGetEmp = $pdo->query("SELECT id_empresa FROM empresas WHERE rfc = 'XEXX010101000' LIMIT 1");
    $idEmpresa = (int)$stmtGetEmp->fetchColumn();

    // 2. Crear usuario superadmin si no existe, o actualizar su contraseña
    $stmtUser = $pdo->prepare("
        INSERT INTO usuarios (usuario, nombre_real, correo, password_hash, es_superadmin, activo, debe_cambiar_password)
        VALUES (?, ?, ?, ?, 1, 1, 0)
        ON DUPLICATE KEY UPDATE 
            password_hash = VALUES(password_hash),
            es_superadmin = 1,
            activo = 1,
            debe_cambiar_password = 0
    ");
    $stmtUser->execute([$usuario, $nombre, $correo, $hash]);

    $stmtGetUser = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE usuario = ? LIMIT 1");
    $stmtGetUser->execute([$usuario]);
    $idUsuario = (int)$stmtGetUser->fetchColumn();

    // 3. Vincular usuario a la empresa en usuario_empresas
    $stmtUe = $pdo->prepare("
        INSERT INTO usuario_empresas (id_usuario, id_empresa)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id_empresa = id_empresa
    ");
    $stmtUe->execute([$idUsuario, $idEmpresa]);

    // 4. Asignar permisos completos en usuario_empresa_documentos
    $stmtPerm = $pdo->prepare("
        INSERT INTO usuario_empresa_documentos (
            id_usuario, id_empresa, ver_ingreso, ver_egreso, ver_pago,
            ver_nomina, ver_traslado, ver_xml_pdf, descargar_xml,
            descargar_pdf, exportar, procesar_pagos, generar_diot,
            ver_solicitudes_sat, contpaq_cheques, oracle_pagos,
            nomina_archivo, consulta_cruce_metadata, consulta_codigos_postales,
            consulta_sat_69, consulta_alertas_69, consulta_sat_69b,
            consulta_alertas_69b
        ) VALUES (
            ?, ?, 1, 1, 1,
            1, 1, 1, 1,
            1, 1, 1, 1,
            1, 1, 1,
            1, 1, 1,
            1, 1, 1,
            1
        ) ON DUPLICATE KEY UPDATE ver_ingreso = 1
    ");
    $stmtPerm->execute([$idUsuario, $idEmpresa]);

    echo "OK: Usuario '$usuario' listo con permisos SuperAdmin.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
