<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);

if (!isset($_SESSION['id_usuario'])) { exit('Sesión expirada'); }
?>

<style>
    /* 1. Tabla Ultra Compacta */
    #tablaZonas td, #tablaZonas th {
        padding-top: 3px !important;
        padding-bottom: 3px !important;
        font-size: 0.82rem;
    }
    .btn-compacto {
        padding: 0px 7px !important;
        font-size: 0.72rem !important;
    }

    /* 2. AJUSTE DEL PAGINADOR (Botones Siguiente/Atrás) */
    .dataTables_wrapper .dataTables_paginate {
        padding-top: 10px !important;
        font-size: 0.75rem !important; /* Texto más pequeño */
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 2px 8px !important; /* Botones más chicos */
        margin-left: 2px !important;
        border-radius: 4px !important;
        border: 1px solid #30363d !important;
        background: #161b22 !important;
        color: #58a6ff !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: #58a6ff !important; /* Color azul para el número activo */
        color: #000 !important;
        border: 1px solid #58a6ff !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #30363d !important;
        color: #fff !important;
    }
    .dataTables_wrapper .dataTables_info {
        font-size: 0.75rem !important;
        color: #8b949e !important;
        padding-top: 10px !important;
    }
</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="text-white fw-bold mb-0 small"><i class="bi bi-geo-alt text-info me-2"></i>ZONAS</h5>
        <button class="btn btn-info btn-compacto fw-bold" onclick="nuevaZona()">
            <i class="bi bi-plus-lg"></i> NUEVA
        </button>
    </div>

    <div class="card bg-dark border-secondary shadow">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaZonas" class="table table-dark table-hover mb-0 w-100" style="border-color: #30363d;">
                    <thead>
                        <tr style="background-color: #161b22;">
                            <th class="ps-3 text-info" style="width: 50px;">ID</th>
                            <th class="text-info">NOMBRE DE LA ZONA</th>
                            <th class="text-info text-center" style="width: 120px;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM zonas ORDER BY id_zona DESC");
                        while ($row = $stmt->fetch()) {
                            $nombre_seguro = htmlspecialchars($row['nombre_zona'], ENT_QUOTES, 'UTF-8');
                            echo "<tr>
                                    <td class='ps-3 align-middle'>{$row['id_zona']}</td>
                                    <td class='align-middle text-uppercase'>{$nombre_seguro}</td>
                                    <td class='text-center align-middle text-nowrap'>
                                        <button class='btn btn-outline-warning btn-compacto btn-editar' 
                                                data-id='{$row['id_zona']}' 
                                                data-nombre='{$nombre_seguro}'>
                                            <i class='bi bi-pencil'></i>
                                        </button>
                                        <button class='btn btn-outline-danger btn-compacto' 
                                                onclick=\"eliminarZona({$row['id_zona']}, '{$nombre_seguro}')\">
                                            <i class='bi bi-trash'></i>
                                        </button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalZona" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary text-white">
            <form id="formZona">
                <div class="modal-header py-2 border-secondary">
                    <h6 class="modal-title text-info" id="modalTitulo">NUEVA ZONA</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <input type="hidden" id="id_zona" name="id_zona">
                    <div class="mb-2">
                        <label class="form-label text-muted small fw-bold mb-1">NOMBRE</label>
                        <input type="text" id="nombre_zona" name="nombre_zona" 
                               class="form-control form-control-sm bg-black border-secondary text-white text-uppercase" 
                               placeholder="Ej: NORTE" required maxlength="50" autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer py-1 border-secondary">
                    <button type="button" class="btn btn-secondary btn-compacto" data-bs-dismiss="modal">CANCELAR</button>
                    <button type="submit" class="btn btn-info btn-compacto fw-bold text-dark">GUARDAR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#tablaZonas').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-MX.json' },
        pageLength: 10,
        destroy: true,
        dom: 'ftip',
        order: [[0, "desc"]]
    });

    $(document).off('click', '.btn-editar').on('click', '.btn-editar', function() {
        $('#id_zona').val($(this).data('id'));
        $('#nombre_zona').val($(this).data('nombre'));
        $('#modalTitulo').text('EDITAR ZONA');
        $('#modalZona').modal('show');
    });

    $('#formZona').on('submit', function(e) {
        e.preventDefault();
        let campo = $('#nombre_zona');
        let nombre = campo.val().trim();
        let patronSeguro = /^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑ ]+$/;

        if (!patronSeguro.test(nombre)) {
            campo.val(''); campo.focus();
            return false;
        }

        $.post('backend/zonas_operaciones.php', $(this).serialize() + '&accion=guardar', function(res) {
            if(res.success) {
                $('#modalZona').modal('hide');
                Swal.fire({ icon: 'success', title: 'OK', text: res.message, background: '#161b22', color: '#fff', timer: 1200, showConfirmButton: false });
                cargarModulo('zonas');
            }
        }, 'json');
    });
});

function eliminarZona(id, nombre) {
    Swal.fire({
        title: '¿ELIMINAR?',
        text: nombre,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#30363d',
        confirmButtonText: 'SÍ, BORRAR',
        background: '#161b22', color: '#fff'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('backend/zonas_operaciones.php', { id_zona: id, accion: 'eliminar' }, function(res) {
                if(res.success) {
                    Swal.fire({ icon: 'success', title: 'OK', background: '#161b22', color: '#fff', timer: 1200, showConfirmButton: false });
                    cargarModulo('zonas');
                }
            }, 'json');
        }
    });
}

function nuevaZona() {
    $('#formZona')[0].reset();
    $('#id_zona').val('');
    $('#modalTitulo').text('NUEVA ZONA');
    $('#modalZona').modal('show');
}
</script>