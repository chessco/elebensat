<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
if (!isset($_SESSION['id_usuario'])) { exit; }
?>
<div class="p-2">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold text-white">Hola, <?php echo $_SESSION['nombre_usuario']; ?></h2>
            <p class="text-muted">Estás trabajando en: <span class="text-info fw-bold"><?php echo $_SESSION['empresa_nombre']; ?></span></p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-file-earmark-text fs-1 text-info"></i>
                    <h4 class="mt-3">Facturas</h4>
                    <p class="text-muted small">Módulo de visor de XML</p>
                    <button class="btn btn-sm btn-outline-info" onclick="cargarModulo('visor')">Ir al visor</button>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-cloud-arrow-up fs-1 text-info"></i>
                    <h4 class="mt-3">Carga</h4>
                    <p class="text-muted small">Subir nuevos archivos</p>
                    <button class="btn btn-sm btn-outline-info" onclick="cargarModulo('subir_xml')">Subir ahora</button>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-check-circle fs-1 text-success"></i>
                    <h4 class="mt-3">Sistema OK</h4>
                    <p class="text-muted small">Conexión establecida</p>
                </div>
            </div>
        </div>
    </div>
</div>