<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_superadmin($pdo, false);
$stmt_z = $pdo->query("SELECT id_zona, nombre_zona FROM zonas ORDER BY nombre_zona ASC");
$zonas = $stmt_z->fetchAll();
?>
<style>
    #tablaUsuarios td { font-size: 0.8rem; vertical-align: middle; padding: 4px !important; }
    .form-control-min { background: #000 !important; border: 1px solid #30363d !important; color: #fff !important; font-size: 0.75rem !important; height: 30px; padding: 2px 6px; }
    .label-min { font-size: 0.62rem; color: #8b949e; font-weight: bold; text-transform: uppercase; margin-bottom: 1px; display: block; }
</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between mb-2">
        <h6 class="text-white fw-bold"><i class="bi bi-people me-2 text-info"></i>CATÁLOGO DE USUARIOS</h6>
        <button class="btn btn-info btn-sm fw-bold text-dark" onclick="nuevoUsuario()">+ NUEVO USUARIO</button>
    </div>

    <div class="card bg-dark border-secondary shadow">
        <div class="table-responsive">
            <table id="tablaUsuarios" class="table table-dark table-hover mb-0">
                <thead>
                    <tr class="small text-muted text-uppercase">
                        <th>USUARIO</th><th>NOMBRE REAL</th><th>CORREO</th><th>ZONA</th><th>CAMBIO CLAVE</th><th class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT u.*, z.nombre_zona FROM usuarios u LEFT JOIN zonas z ON u.id_zona = z.id_zona ORDER BY u.id_usuario DESC");
                    while ($r = $stmt->fetch()) {
                        $correo = htmlspecialchars((string)($r['correo'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $usuario = htmlspecialchars((string)$r['usuario'], ENT_QUOTES, 'UTF-8');
                        $nombre = htmlspecialchars((string)$r['nombre_real'], ENT_QUOTES, 'UTF-8');
                        $zona = htmlspecialchars((string)($r['nombre_zona'] ?? 'GLOBAL'), ENT_QUOTES, 'UTF-8');
                        $pendiente = !empty($r['debe_cambiar_password'])
                            ? "<span class='badge bg-warning text-dark'>PENDIENTE</span>"
                            : "<span class='badge bg-success'>OK</span>";
                        $json = htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        echo "<tr>
                            <td><b>{$usuario}</b></td>
                            <td>{$nombre}</td>
                            <td>{$correo}</td>
                            <td><span class='badge bg-primary px-2'>{$zona}</span></td>
                            <td>{$pendiente}</td>
                            <td class='text-center'>
                                <button class='btn btn-outline-warning btn-sm py-0' onclick='editarUsuario(JSON.parse(this.dataset.user))' data-user='{$json}'><i class='bi bi-pencil'></i></button>
                                <button class='btn btn-outline-danger btn-sm py-0' onclick='eliminarUsuario(".(int)$r['id_usuario'].", ".json_encode($r['usuario']).")'><i class='bi bi-trash'></i></button>
                            </td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg">
            <form id="formUsuario">
                <input type="hidden" id="id_usuario" name="id_usuario">
                <div class="modal-header py-2 border-secondary">
                    <b class="text-info small">DATOS DE ACCESO</b>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="label-min">NOMBRE REAL / COMPLETO</label><input type="text" name="nombre_real" id="nombre_real_u" class="form-control form-control-min" required></div>
                        <div class="col-12"><label class="label-min">CORREO ELECTRÓNICO</label><input type="email" name="correo" id="correo_u" class="form-control form-control-min" required autocomplete="email"></div>
                        <div class="col-md-6"><label class="label-min">USUARIO (LOGIN)</label><input type="text" name="usuario" id="usuario_u" class="form-control form-control-min" required autocomplete="off"></div>
                        <div class="col-md-6">
                            <label class="label-min">CONTRASEÑA TEMPORAL</label>
                            <input type="password" name="password_raw" id="password_u" class="form-control form-control-min" placeholder="Mínimo 8 caracteres" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <div class="alert alert-secondary py-2 px-3 mb-0 small">
                                <i class="bi bi-shield-lock me-1"></i>
                                En usuarios nuevos la contraseña es temporal y el sistema obligará a cambiarla en el primer acceso.
                                Al editar, deje la contraseña vacía para conservar la actual; si captura una nueva, también se marcará como temporal.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="label-min text-warning">ZONA ASIGNADA</label>
                            <select name="id_zona" id="id_zona_u" class="form-control form-control-min">
                                <option value="">-- GLOBAL / TODAS --</option>
                                <?php foreach($zonas as $z): ?>
                                    <option value="<?= (int)$z['id_zona'] ?>"><?= htmlspecialchars($z['nombre_zona'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1 border-secondary"><button type="submit" class="btn btn-info btn-sm fw-bold text-dark w-100">GRABAR USUARIO</button></div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#tablaUsuarios').DataTable({ language: {url:'//cdn.datatables.net/plug-ins/1.13.4/i18n/es-MX.json'}, dom: 'ftip' });

    $('#formUsuario').on('submit', function(e) {
        e.preventDefault();
        $.post('backend/usuarios_operaciones.php', $(this).serialize() + '&accion=guardar', function(res) {
            if(res.success) { $('#modalUsuario').modal('hide'); cargarModulo('usuarios'); }
            else { Swal.fire('Error', res.error, 'error'); }
        }, 'json');
    });
});

function nuevoUsuario() {
    $('#formUsuario')[0].reset();
    $('#id_usuario').val('');
    $('#password_u').attr('required', true);
    $('#modalUsuario').modal('show');
}

function editarUsuario(d) {
    $('#id_usuario').val(d.id_usuario);
    $('#nombre_real_u').val(d.nombre_real || '');
    $('#correo_u').val(d.correo || '');
    $('#usuario_u').val(d.usuario || '');
    $('#id_zona_u').val(d.id_zona || '');
    $('#password_u').val('').attr('required', false);
    $('#modalUsuario').modal('show');
}

function eliminarUsuario(id, u) {
    Swal.fire({
        title: '¿Eliminar usuario?', text: u, icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Sí, borrar', background: '#161b22', color: '#fff'
    }).then((r) => {
        if (r.isConfirmed) {
            $.post('backend/usuarios_operaciones.php', { id_usuario: id, accion: 'eliminar' }, function(res) {
                if (res.success) cargarModulo('usuarios');
                else Swal.fire('Error', res.error || 'No se pudo eliminar.', 'error');
            }, 'json');
        }
    });
}
</script>
