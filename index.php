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
    <title>Acceso al Sistema - PitayaCode Visor XML Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --pitaya-bg: #202020;
            --pitaya-surface: #282828;
            --pitaya-surface-elevated: #303030;
            --pitaya-border: #383838;
            --pitaya-green: #24A77F;
            --pitaya-green-hover: #1E8D6B;
            --pitaya-blue: #17A0C6;
            --pitaya-pink: #DA3C7A;
            --pitaya-text-primary: #FFFFFF;
            --pitaya-text-secondary: #A0A0A0;
            --pitaya-text-muted: #707070;
        }

        body { 
            background: var(--pitaya-bg);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            position: relative;
            overflow-x: hidden;
            color: var(--pitaya-text-primary);
        }

        /* Ambient tech background glow */
        body::before {
            content: '';
            position: absolute;
            top: -20%;
            left: 50%;
            transform: translateX(-50%);
            width: 800px;
            height: 600px;
            background: radial-gradient(circle, rgba(36, 167, 127, 0.09) 0%, rgba(23, 160, 198, 0.05) 40%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            position: relative;
            z-index: 1;
        }

        .card-login { 
            border: 1px solid var(--pitaya-border); 
            border-radius: 16px; 
            background: var(--pitaya-surface);
            box-shadow: 0 24px 48px -12px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.04);
            transition: all 0.4s ease;
            backdrop-filter: blur(8px);
        }

        .pitaya-logo-img {
            max-height: 120px;
            width: auto;
            display: block;
            margin: 0 auto 0.75rem auto;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .pitaya-logo-img:hover {
            transform: scale(1.02);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45);
        }

        .brand-sub-title {
            color: #FFFFFF;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 0.15rem;
        }

        .brand-subtitle-badge {
            display: inline-block;
            color: var(--pitaya-text-secondary);
            font-size: 0.82rem;
            font-weight: 500;
            letter-spacing: 0.02em;
        }

        .form-label {
            color: var(--pitaya-text-secondary);
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            margin-bottom: 0.45rem;
        }

        .form-control {
            background-color: var(--pitaya-bg) !important;
            border: 1px solid var(--pitaya-border) !important;
            color: var(--pitaya-text-primary) !important;
            border-radius: 8px;
            padding: 0.65rem 0.9rem;
            font-size: 0.92rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--pitaya-green) !important;
            box-shadow: 0 0 0 3px rgba(36, 167, 127, 0.2) !important;
            outline: none;
        }

        .form-control::placeholder {
            color: var(--pitaya-text-muted);
        }

        .input-group-text {
            background-color: var(--pitaya-surface-elevated) !important;
            border: 1px solid var(--pitaya-border) !important;
            border-right: none !important;
            color: var(--pitaya-text-secondary);
            border-radius: 8px 0 0 8px;
        }

        .input-group .form-control {
            border-left: none !important;
            border-radius: 0 8px 8px 0;
        }

        .btn-primary { 
            background-color: var(--pitaya-green) !important; 
            border: 1px solid var(--pitaya-green) !important; 
            padding: 12px; 
            font-weight: 600; 
            font-size: 0.92rem;
            border-radius: 8px;
            letter-spacing: 0.03em;
            box-shadow: 0 4px 14px rgba(36, 167, 127, 0.28);
            transition: all 0.2s ease;
        }

        .btn-primary:hover, .btn-primary:focus { 
            background-color: var(--pitaya-green-hover) !important;
            border-color: var(--pitaya-green-hover) !important;
            box-shadow: 0 6px 18px rgba(36, 167, 127, 0.38);
            transform: translateY(-1px);
        }

        /* Empresa selection cards */
        .empresa-card {
            background: var(--pitaya-bg);
            border: 1px solid var(--pitaya-border);
            border-radius: 12px;
            padding: 18px 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .empresa-card:hover {
            border-color: var(--pitaya-green);
            background: var(--pitaya-surface-elevated);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35), 0 0 12px rgba(36, 167, 127, 0.2);
        }

        .empresa-icon {
            width: 46px;
            height: 46px;
            background: rgba(36, 167, 127, 0.12);
            color: var(--pitaya-green);
            border: 1px solid rgba(36, 167, 127, 0.3);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .empresa-card:hover .empresa-icon {
            background: var(--pitaya-green);
            color: #FFFFFF;
        }

        .empresa-nombre {
            font-weight: 600;
            color: #FFFFFF;
            font-size: 0.85rem;
            line-height: 1.25;
            margin-bottom: 6px;
        }

        .empresa-rfc {
            font-size: 0.72rem;
            color: var(--pitaya-text-secondary);
            font-family: 'JetBrains Mono', Consolas, monospace;
            background: rgba(255, 255, 255, 0.05);
            padding: 2px 6px;
            border-radius: 4px;
        }

        #contenedor-empresas {
            max-height: 360px;
            overflow-y: auto;
            padding: 5px;
        }

        /* Scrollbar */
        #contenedor-empresas::-webkit-scrollbar { width: 5px; }
        #contenedor-empresas::-webkit-scrollbar-track { background: var(--pitaya-bg); }
        #contenedor-empresas::-webkit-scrollbar-thumb { background: #484848; border-radius: 10px; }
        #contenedor-empresas::-webkit-scrollbar-thumb:hover { background: var(--pitaya-green); }

        .btn-link-custom {
            color: var(--pitaya-blue);
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 500;
            transition: color 0.15s ease;
        }

        .btn-link-custom:hover {
            color: var(--pitaya-green);
        }

        .login-footer-info {
            margin-top: 1.5rem;
            padding-top: 1.2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--pitaya-text-muted);
            font-size: 0.75rem;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: var(--pitaya-green);
            box-shadow: 0 0 6px var(--pitaya-green);
            display: inline-block;
            margin-right: 5px;
        }

        #mensaje { min-height: 20px; font-weight: 500; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div id="col-contenedor" class="col-md-4">
            <div class="card card-login">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="assets/img/pitayacode-logo-cropped.png" alt="PitayaCode Logo" class="pitaya-logo-img">
                        <div class="brand-sub-title">Visor XML Pro</div>
                        <span class="brand-subtitle-badge" id="subtitulo">Acceso al Sistema</span>
                    </div>
                    
                    <div id="step-1">
                        <div class="mb-3">
                            <label class="form-label" for="usuario">USUARIO</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" id="usuario" class="form-control" placeholder="Ej: juan" autocomplete="username" autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password">CONTRASEÑA</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" id="password" class="form-control" placeholder="••••••••" autocomplete="current-password">
                            </div>
                        </div>
                        <button id="btnAcceder" onclick="validarUsuario()" class="btn btn-primary w-100">
                            <span>ACCEDER</span> <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>

                    <div id="step-2" style="display:none;">
                        <div id="contenedor-empresas" class="row g-3 text-center"></div>
                        <div class="text-center mt-4">
                            <button onclick="regresarLogin()" class="btn btn-link btn-link-custom p-0">
                                <i class="bi bi-chevron-left me-1"></i> Cambiar de usuario
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($passwordChanged): ?>
                    <div class="mt-3 alert alert-success py-2 text-center small fw-bold mb-0" style="background: rgba(36, 167, 127, 0.15); border-color: rgba(36, 167, 127, 0.3); color: #24A77F;">
                        Contraseña actualizada. Inicie sesión con su nueva contraseña.
                    </div>
                    <?php endif; ?>
                    <div id="mensaje" class="mt-3 text-danger text-center small fw-bold"></div>

                    <div class="login-footer-info">
                        <div>
                            <span class="status-dot"></span>
                            <span>Servicio Activo</span>
                        </div>
                        <span>v1.0 • PitayaCode</span>
                    </div>
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
        $('#mensaje').text('Por favor, llene todos los campos.');
        return;
    }

    let $btn = $('#btnAcceder');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validando...');
    $('#mensaje').text('');

    $.post('auth/procesar_login.php', {accion: 'validar', user: u, pass: p}, function(res) {
        $btn.prop('disabled', false).html('<span>ACCEDER</span> <i class="bi bi-arrow-right ms-2"></i>');
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
            $('#step-1').fadeOut(250, function(){
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
                $('#step-2').fadeIn(250);
            });
        } else {
            $('#mensaje').text(res.error || 'Credenciales inválidas.');
        }
    }, 'json').fail(function() {
        $btn.prop('disabled', false).html('<span>ACCEDER</span> <i class="bi bi-arrow-right ms-2"></i>');
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
    $('#step-2').fadeOut(250, function(){
        $('#col-contenedor').removeClass('col-md-6').addClass('col-md-4');
        $('#subtitulo').text('Visor XML Pro • Acceso al Sistema');
        $('#step-1').fadeIn(250);
    });
}

$('#password, #usuario').keypress(function (e) {
    if (e.which == 13) {
        validarUsuario();
        return false;
    }
});
</script>
</body>
</html>