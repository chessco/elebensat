<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
?>
<div class="card shadow-sm animate__animated animate__fadeIn">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold"><i class="bi bi-search me-2"></i>Visor de Documentos Digitales</h5>
        <button class="btn btn-sm btn-outline-info"><i class="bi bi-cloud-arrow-up"></i> Cargar XML</button>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-4">
            <div class="col-md-2">
                <label class="small text-info">Desde</label>
                <input type="date" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="small text-info">Hasta</label>
                <input type="date" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="small text-info">Filtrar por Tipo</label>
                <select class="form-select form-select-sm">
                    <option>Todos los documentos</option>
                    <option>Ingresos</option>
                    <option>Egresos</option>
                    <option>Pagos</option>
                    <option>Nómina</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-info">Búsqueda rápida</label>
                <input type="text" class="form-control form-control-sm" placeholder="RFC, UUID, Nombre...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-info btn-sm w-100 fw-bold text-dark">BUSCAR</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover table-borderless align-middle" style="font-size: 0.85rem;">
                <thead>
                    <tr class="border-bottom border-secondary text-info">
                        <th>UUID (Folio Fiscal)</th>
                        <th>Fecha</th>
                        <th>RFC / Nombre Emisor</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-cfdis">
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <i class="bi bi-info-circle me-1"></i> Use los filtros superiores para consultar los XML cargados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>