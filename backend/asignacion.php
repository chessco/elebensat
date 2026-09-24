<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);

// Listamos usuarios que tienen una zona asignada
$usuarios = $pdo->query("SELECT u.id_usuario, u.nombre_real, z.nombre_zona, u.id_zona 
                         FROM usuarios u 
                         INNER JOIN zonas z ON u.id_zona = z.id_zona 
                         ORDER BY u.nombre_real ASC")->fetchAll();
?>

<div class="animate__animated animate__fadeIn">
    <div class="mb-3">
        <h6 class="text-white fw-bold"><i class="bi bi-shield-check me-2 text-info"></i>ASIGNACIÓN DE EMPRESAS</h6>
        <p class="text-muted small">Selecciona un usuario para vincularlo con las empresas de su zona establecida.</p>
    </div>
    
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card bg-dark border-secondary">
                <div class="card-header border-secondary py-2"><b class="small text-info text-uppercase">Usuarios</b></div>
                <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                    <?php foreach($usuarios as $u): ?>
                        <button type="button" class="list-group-item list-group-item-action bg-dark text-white border-secondary btn-user-asig" 
                                onclick="cargarListadoEmpresas(<?php echo $u['id_usuario']; ?>, <?php echo $u['id_zona']; ?>, this)">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-bold text-uppercase"><?php echo $u['nombre_real']; ?></span>
                                <span class="badge bg-secondary" style="font-size: 0.6rem;"><?php echo $u['nombre_zona']; ?></span>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card bg-dark border-secondary">
                <div class="card-header border-secondary py-2 d-flex justify-content-between align-items-center">
                    <b class="small text-info text-uppercase">Empresas de su Zona</b>
                    <button class="btn btn-info btn-sm fw-bold py-0 d-none" id="btnGuardarAsig" onclick="guardarAsignacion()">
                        <i class="bi bi-save me-1"></i> GUARDAR CAMBIOS
                    </button>
                </div>
                <div class="card-body p-0" id="contenedor_empresas" style="min-height: 300px;">
                    <div class="text-center text-muted mt-5 py-5">
                        <i class="bi bi-people d-block mb-2" style="font-size: 2.5rem;"></i>
                        Seleccione un usuario de la lista izquierda
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var idUsuarioActivo = 0;

function cargarListadoEmpresas(id_user, id_zona, btn) {
    idUsuarioActivo = id_user;
    $('.btn-user-asig').removeClass('active bg-primary');
    $(btn).addClass('active bg-primary');
    
    $('#contenedor_empresas').html('<div class="text-center py-5"><div class="spinner-border text-info"></div></div>');
    
    $.post('backend/asignacion_operaciones.php', { accion: 'ver', id_user: id_user, id_zona: id_zona }, function(html) {
        $('#contenedor_empresas').html(html);
        $('#btnGuardarAsig').removeClass('d-none');
    });
}

function guardarAsignacion() {
    let ids_empresas = [];
    $('.chk-empresa:checked').each(function() { ids_empresas.push($(this).val()); });

    $.post('backend/asignacion_operaciones.php', { 
        accion: 'guardar', 
        id_user: idUsuarioActivo, 
        empresas: ids_empresas 
    }, function(res) {
        if(res.success) {
            Swal.fire({ 
                icon: 'success', 
                title: 'Permisos Actualizados', 
                timer: 1000, 
                showConfirmButton: false, 
                background: '#161b22', 
                color: '#fff' 
            });
        }
    }, 'json');
}
</script>