<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - Visor XML</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f4f7f6; height: 100vh; display: flex; align-items: center; }
        .card { border: none; border-radius: 15px; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-lg">
                <div class="card-body p-5">
                    <h3 class="text-center mb-4">Visor XML</h3>
                    
                    <div id="step-1">
                        <div class="mb-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" id="usuario" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña</label>
                            <input type="password" id="password" class="form-control">
                        </div>
                        <button onclick="validarUsuario()" class="btn btn-primary w-100">Siguiente</button>
                    </div>

                    <div id="step-2" style="display:none;">
                        <h5 class="text-muted text-center mb-3">Seleccione Empresa</h5>
                        <div class="mb-3">
                            <select id="select-empresa" class="form-select"></select>
                        </div>
                        <button onclick="entrarAlSistema()" class="btn btn-success w-100">Entrar</button>
                    </div>
                    
                    <div id="mensaje" class="mt-3 text-danger text-center small"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function validarUsuario() {
    let u = $('#usuario').val();
    let p = $('#password').val();
    
    $.post('auth/procesar_login.php', {accion: 'validar', user: u, pass: p}, function(res) {
        if(res.success) {
            $('#step-1').fadeOut(300, function(){
                let options = '';
                res.empresas.forEach(e => {
                    options += `<option value="${e.id_empresa}">${e.razon_social} (${e.rfc})</option>`;
                });
                $('#select-empresa').html(options);
                $('#step-2').fadeIn();
            });
        } else {
            $('#mensaje').text(res.error);
        }
    }, 'json').fail(function() {
        $('#mensaje').text('No fue posible conectar con el servicio. Intente nuevamente en unos minutos.');
    });
}

function entrarAlSistema() {
    let id_emp = $('#select-empresa').val();
    $.post('auth/procesar_login.php', {accion: 'set_empresa', id_empresa: id_emp}, function(res) {
        if(res.success) window.location.href = 'main.php';
    }, 'json');
}
</script>
</body>
</html>