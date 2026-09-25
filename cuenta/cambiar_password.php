<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
$sesion = seguridad_exigir_sesion($pdo, false, true);

$st = $pdo->prepare('SELECT usuario, nombre_real, debe_cambiar_password FROM usuarios WHERE id_usuario = ? LIMIT 1');
$st->execute([(int)$sesion['id_usuario']]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    seguridad_responder_denegado('Usuario no disponible.', 404);
}
if (empty($u['debe_cambiar_password'])) {
    header('Location: ../index.php');
    exit;
}
$token = csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña - PitayaCode Visor XML</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon.png">
<link rel="icon" type="image/png" href="../assets/img/pitayacode-icon.png">
<link rel="shortcut icon" href="../favicon.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{min-height:100vh;background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',sans-serif}
.card{width:min(520px,94vw);border:0;border-radius:20px;box-shadow:0 20px 45px rgba(0,0,0,.4)}
.brand{color:#1e3c72;font-weight:800}
</style>
</head>
<body>
<div class="card">
  <div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
      <i class="bi bi-shield-lock-fill fs-1 text-primary"></i>
      <h3 class="brand">CAMBIO DE CONTRASEÑA</h3>
      <div class="text-muted small">Hola, <?= htmlspecialchars($u['nombre_real']) ?>. Por seguridad debes cambiar la contraseña temporal antes de continuar.</div>
    </div>
    <form id="formForzado" autocomplete="off">
      <input type="hidden" name="accion" value="cambiar_password">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
      <div class="mb-3">
        <label class="form-label fw-bold small">CONTRASEÑA ACTUAL</label>
        <input type="password" name="password_actual" class="form-control" required autocomplete="current-password">
      </div>
      <div class="mb-3">
        <label class="form-label fw-bold small">NUEVA CONTRASEÑA</label>
        <input type="password" name="password_nueva" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label fw-bold small">CONFIRMAR NUEVA CONTRASEÑA</label>
        <input type="password" name="password_confirmacion" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
      </div>
      <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>Mínimo 8 caracteres. La nueva contraseña debe ser distinta de la temporal.</div>
      <button class="btn btn-primary w-100 fw-bold" type="submit">GUARDAR NUEVA CONTRASEÑA</button>
    </form>
  </div>
</div>
<script>
$('#formForzado').on('submit', function(e){
  e.preventDefault();
  $.post('../backend/cuenta_operaciones.php', $(this).serialize(), function(r){
    if (r.success) {
      Swal.fire({icon:'success',title:'Contraseña actualizada',text:'Vuelve a iniciar sesión con tu nueva contraseña.',confirmButtonText:'Continuar'})
        .then(() => { window.location.href='../auth/logout.php?password_changed=1'; });
    } else {
      Swal.fire('Error', r.error || 'No fue posible cambiar la contraseña.', 'error');
    }
  }, 'json').fail(function(){
    Swal.fire('Error', 'No fue posible conectar con el servicio.', 'error');
  });
});
</script>
</body>
</html>
