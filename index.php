<?php
session_start();
// Si quedó pendiente el cambio obligatorio de contraseña, no permitir saltarlo.
if (!empty($_SESSION['id_usuario']) && !empty($_SESSION['debe_cambiar_password'])) {
    header('Location: cuenta/cambiar_password.php');
    exit;
}
// Si ya hay sesión completa, saltar al main.
if(isset($_SESSION['id_usuario']) && isset($_SESSION['id_empresa'])) {
    header('Location: main.php');
    exit;
}
$passwordChanged = isset($_GET['password_changed']) && $_GET['password_changed'] === '1';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - Visor XML</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { 
            background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); 
            height: 100vh; 
            display: flex; 
            align-items: center; 
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
        }
        
        .card-login { 
            border: none; 
            border-radius: 20px; 
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            transition: all 0.5s ease;
        }

        .empresa-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .empresa-card:hover {
            border-color: #58a6ff;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            background: #ffffff;
        }

        .empresa-icon {
            width: 50px;
            height: 50px;
            background: #1e3c72;
            color: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: bold;
            margin-bottom: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .empresa-nombre {
            font-weight: 700;
            color: #212529;
            font-size: 0.85rem;
            line-height: 1.2;
            margin-bottom: 5px;
        }

        .empresa-rfc {
            font-size: 0.7rem;
            color: #6c757d;
            font-family: monospace;
        }

        #contenedor-empresas {
            max-height: 350px;
            overflow-y: auto;
            padding: 5px;
        }

        /* Scrollbar estético */
        #contenedor-empresas::-webkit-scrollbar { width: 5px; }
        #contenedor-empresas::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 10px; }

        .btn-primary { background-color: #1e3c72; border: none; padding: 12px; font-weight: 600; }
        .btn-primary:hover { background-color: #2a5298; }
        
        .visor-logo { color: #1e3c72; font-weight: 800; letter-spacing: 1px; }
        #mensaje { min-height: 20px; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div id="col-contenedor" class="col-md-4">
            <div class="card card-login">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-cpu-fill fs-1 text-primary"></i>
                        <h2 class="visor-logo">VISOR XML</h2>
                        <p id="subtitulo" class="text-muted small">Inicie sesión para continuar</p>
                    </div>
                    
                    <div id="step-1">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">USUARIO</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person"></i></span>
                                <input type="text" id="usuario" class="form-control bg-light border-start-0" placeholder="Ej: juan">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold">CONTRASEÑA</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock"></i></span>
                                <input type="password" id="password" class="form-control bg-light border-start-0" placeholder="••••••••">
                            </div>
                        </div>
                        <button onclick="validarUsuario()" class="btn btn-primary btn-lg w-100 shadow-sm">
                            ACCEDER <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>

                    <div id="step-2" style="display:none;">
                        <div id="contenedor-empresas" class="row g-3 text-center"></div>
                        <div class="text-center mt-4">
                            <button onclick="regresarLogin()" class="btn btn-link btn-sm text-decoration-none text-muted">
                                <i class="bi bi-chevron-left"></i> Cambiar de usuario
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($passwordChanged): ?>
                    <div class="mt-3 alert alert-success py-2 text-center small fw-bold mb-0">Contraseña actualizada. Inicie sesión con su nueva contraseña.</div>
                    <?php endif; ?>
                    <div id="mensaje" class="mt-3 text-danger text-center small fw-bold"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function validarUsuario() {
    let u = $('#usuario').val().trim();
    let p = $('#password').val();
    
    if(u === '' || p === '') {
        $('#mensaje').text('Por favor, llene todos los campos');
        return;
    }

    $.post('auth/procesar_login.php', {accion: 'validar', user: u, pass: p}, function(res) {
        if(res.success) {
            $('#mensaje').text('');

            if (res.force_password_change && res.redirect) {
                window.location.href = res.redirect;
                return;
            }

            if(res.redirect) {
                window.location.href = res.redirect;
                return;
            }

            $('#col-contenedor').removeClass('col-md-4').addClass('col-md-6');
            $('#step-1').fadeOut(300, function(){
                $('#subtitulo').text('Seleccione la empresa a gestionar');
                let htmlEmpresas = '';
                res.empresas.forEach(e => {
                    let inicial = e.razon_social.charAt(0).toUpperCase();
                    htmlEmpresas += `
                        <div class="col-md-6">
                            <div class="empresa-card" onclick="entrarConEmpresa(${e.id_empresa})">
                                <div class="empresa-icon">${inicial}</div>
                                <div class="empresa-nombre">${e.razon_social}</div>
                                <div class="empresa-rfc">${e.rfc}</div>
                            </div>
                        </div>
                    `;
                });
                $('#contenedor-empresas').html(htmlEmpresas);
                $('#step-2').fadeIn();
            });
        } else {
            $('#mensaje').text(res.error);
        }
    }, 'json').fail(function() {
        $('#mensaje').text('No fue posible conectar con el servicio. Intente nuevamente en unos minutos.');
    });
}

function entrarConEmpresa(id_emp) {
    $.post('auth/procesar_login.php', {accion: 'set_empresa', id_empresa: id_emp}, function(res) {
        if(res.success) window.location.href = 'main.php';
    }, 'json').fail(function() {
        $('#mensaje').text('No fue posible conectar con el servicio. Intente nuevamente en unos minutos.');
    });
}

function regresarLogin() {
    $('#step-2').fadeOut(300, function(){
        $('#col-contenedor').removeClass('col-md-6').addClass('col-md-4');
        $('#subtitulo').text('Inicie sesión para continuar');
        $('#step-1').fadeIn();
    });
}

$('#password').keypress(function (e) {
    if (e.which == 13) {
        validarUsuario();
        return false;
    }
});
</script>
</body>
</html>