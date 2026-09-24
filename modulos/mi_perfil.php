<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
seguridad_exigir_sesion($pdo, true);

$st = $pdo->prepare('SELECT usuario, nombre_real, correo, fecha_cambio_password FROM usuarios WHERE id_usuario = ? LIMIT 1');
$st->execute([(int)$_SESSION['id_usuario']]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    seguridad_responder_denegado('Usuario no disponible.', 404);
}
$token = csrf_token();
?>
<div class="container-fluid animate__animated animate__fadeIn">
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card bg-dark border-secondary shadow h-100">
                <div class="card-header border-secondary fw-bold text-info"><i class="bi bi-person-circle me-2"></i>MI PERFIL</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small text-muted">NOMBRE</label>
                        <input class="form-control bg-black text-white border-secondary" value="<?= htmlspecialchars($u['nombre_real']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted">USUARIO</label>
                        <input class="form-control bg-black text-white border-secondary" value="<?= htmlspecialchars($u['usuario']) ?>" disabled>
                    </div>
                    <form id="formCorreoPerfil">
                        <input type="hidden" name="accion" value="actualizar_correo">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                        <div class="mb-3">
                            <label class="form-label small text-muted">CORREO ELECTRÓNICO</label>
                            <input type="email" name="correo" class="form-control bg-black text-white border-secondary" value="<?= htmlspecialchars((string)($u['correo'] ?? '')) ?>" required>
                            <div class="form-text text-secondary">Se utilizará más adelante para recuperación segura de contraseña.</div>
                        </div>
                        <button class="btn btn-outline-info btn-sm" type="submit"><i class="bi bi-envelope-check me-1"></i>Guardar correo</button>
                    </form>
                    <?php if (!empty($u['fecha_cambio_password'])): ?>
                    <div class="small text-secondary mt-3">Último cambio de contraseña: <?= htmlspecialchars($u['fecha_cambio_password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card bg-dark border-secondary shadow">
                <div class="card-header border-secondary fw-bold text-warning"><i class="bi bi-key me-2"></i>CAMBIAR CONTRASEÑA</div>
                <div class="card-body">
                    <form id="formCambiarPasswordPerfil" autocomplete="off">
                        <input type="hidden" name="accion" value="cambiar_password">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                        <div class="mb-3">
                            <label class="form-label small text-muted">CONTRASEÑA ACTUAL</label>
                            <input type="password" name="password_actual" class="form-control bg-black text-white border-secondary" required autocomplete="current-password">
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">NUEVA CONTRASEÑA</label>
                                <input type="password" name="password_nueva" class="form-control bg-black text-white border-secondary" minlength="8" maxlength="72" required autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">CONFIRMAR NUEVA</label>
                                <input type="password" name="password_confirmacion" class="form-control bg-black text-white border-secondary" minlength="8" maxlength="72" required autocomplete="new-password">
                            </div>
                        </div>
                        <div class="form-text text-secondary mt-2">Mínimo 8 caracteres y debe ser distinta de la contraseña actual.</div>
                        <button class="btn btn-warning btn-sm mt-3 fw-bold" type="submit"><i class="bi bi-shield-lock me-1"></i>Cambiar contraseña</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
$('#formCorreoPerfil').on('submit', function(e){
    e.preventDefault();
    $.post('backend/cuenta_operaciones.php', $(this).serialize(), function(r){
        if (r.success) Swal.fire('Listo', r.msg, 'success');
        else Swal.fire('Error', r.error, 'error');
    }, 'json');
});

$('#formCambiarPasswordPerfil').on('submit', function(e){
    e.preventDefault();
    $.post('backend/cuenta_operaciones.php', $(this).serialize(), function(r){
        if (r.success) {
            $('#formCambiarPasswordPerfil')[0].reset();
            Swal.fire('Listo', r.msg, 'success');
        } else {
            Swal.fire('Error', r.error, 'error');
        }
    }, 'json');
});
</script>
