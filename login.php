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
            background-color: var(--pitaya-bg);
            color: var(--pitaya-text-primary);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow-x: hidden;
        }

        /* Subtle radial ambient glow */
        body::before {
            content: '';
            position: absolute;
            top: -20%;
            left: 50%;
            transform: translateX(-50%);
            width: 800px;
            height: 600px;
            background: radial-gradient(circle, rgba(36, 167, 127, 0.08) 0%, rgba(23, 160, 198, 0.04) 40%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 1.5rem;
        }

        .login-card {
            background-color: var(--pitaya-surface);
            border: 1px solid var(--pitaya-border);
            border-radius: 14px;
            box-shadow: 0 24px 48px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03);
            padding: 2.5rem;
            backdrop-filter: blur(8px);
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(36, 167, 127, 0.15) 0%, rgba(23, 160, 198, 0.15) 100%);
            border: 1px solid rgba(36, 167, 127, 0.3);
            color: var(--pitaya-green);
            font-size: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 16px rgba(36, 167, 127, 0.15);
        }

        .brand-title {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.25rem;
        }

        .brand-gradient {
            background: linear-gradient(135deg, var(--pitaya-green) 0%, var(--pitaya-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-subtitle {
            color: var(--pitaya-text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 2rem;
        }

        .form-label {
            color: var(--pitaya-text-secondary);
            font-size: 0.82rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            background-color: var(--pitaya-bg);
            border: 1px solid var(--pitaya-border);
            color: var(--pitaya-text-primary);
            border-radius: 8px;
            padding: 0.65rem 0.9rem;
            font-size: 0.92rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--pitaya-bg);
            border-color: var(--pitaya-green);
            color: var(--pitaya-text-primary);
            box-shadow: 0 0 0 3px rgba(36, 167, 127, 0.2);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--pitaya-text-muted);
        }

        .input-group-text {
            background-color: var(--pitaya-surface-elevated);
            border: 1px solid var(--pitaya-border);
            border-right: none;
            color: var(--pitaya-text-secondary);
            border-radius: 8px 0 0 8px;
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 8px 8px 0;
        }

        .btn-pitaya-primary {
            background-color: var(--pitaya-green);
            border: 1px solid var(--pitaya-green);
            color: #FFFFFF;
            font-weight: 600;
            font-size: 0.92rem;
            border-radius: 8px;
            padding: 0.7rem 1.2rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(36, 167, 127, 0.25);
        }

        .btn-pitaya-primary:hover, .btn-pitaya-primary:focus {
            background-color: var(--pitaya-green-hover);
            border-color: var(--pitaya-green-hover);
            color: #FFFFFF;
            box-shadow: 0 6px 16px rgba(36, 167, 127, 0.35);
            transform: translateY(-1px);
        }

        .btn-pitaya-gradient {
            background: linear-gradient(135deg, var(--pitaya-green) 0%, var(--pitaya-blue) 100%);
            border: none;
            color: #FFFFFF;
            font-weight: 600;
            font-size: 0.92rem;
            border-radius: 8px;
            padding: 0.7rem 1.2rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 16px rgba(23, 160, 198, 0.25);
        }

        .btn-pitaya-gradient:hover, .btn-pitaya-gradient:focus {
            opacity: 0.95;
            color: #FFFFFF;
            box-shadow: 0 6px 20px rgba(23, 160, 198, 0.4);
            transform: translateY(-1px);
        }

        .empresa-selector-box {
            background-color: rgba(36, 167, 127, 0.08);
            border: 1px solid rgba(36, 167, 127, 0.25);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .empresa-selector-title {
            color: var(--pitaya-green);
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .login-footer {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--pitaya-text-muted);
            font-size: 0.75rem;
        }

        .login-footer a {
            color: var(--pitaya-blue);
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .login-footer a:hover {
            color: var(--pitaya-green);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: var(--pitaya-green);
            box-shadow: 0 0 6px var(--pitaya-green);
        }

        #mensaje {
            min-height: 22px;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="text-center">
            <div class="brand-badge">
                <i class="bi bi-cpu"></i>
            </div>
            <h1 class="brand-title">
                <span class="brand-gradient">PitayaCode</span>
            </h1>
            <p class="brand-subtitle">Visor XML Pro • Inteligencia Fiscal</p>
        </div>
        
        <div id="step-1">
            <div class="mb-3">
                <label class="form-label" for="usuario">Usuario o Correo</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" id="usuario" class="form-control" placeholder="Ingrese su usuario" autocomplete="username" autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" id="password" class="form-control" placeholder="••••••••" autocomplete="current-password">
                </div>
            </div>
            <button id="btnValidar" onclick="validarUsuario()" class="btn btn-pitaya-primary w-100">
                <span>Continuar</span> <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>

        <div id="step-2" style="display:none;">
            <div class="empresa-selector-box">
                <div class="empresa-selector-title">
                    <i class="bi bi-building-check"></i>
                    <span>Empresa de Trabajo</span>
                </div>
                <label class="form-label" for="select-empresa">Seleccione Entidad</label>
                <select id="select-empresa" class="form-select"></select>
            </div>
            <button onclick="entrarAlSistema()" class="btn btn-pitaya-gradient w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> <span>Entrar al Sistema</span>
            </button>
        </div>
        
        <div id="mensaje" class="mt-3 text-danger text-center small"></div>

        <div class="login-footer">
            <div class="status-pill">
                <span class="status-dot"></span>
                <span>Servidor Operativo</span>
            </div>
            <span>v1.0 • PitayaCode</span>
        </div>
    </div>
</div>

<script>
$('#usuario, #password').on('keypress', function(e) {
    if (e.which === 13) {
        validarUsuario();
    }
});

function validarUsuario() {
    let u = $('#usuario').val().trim();
    let p = $('#password').val();
    
    if (!u || !p) {
        $('#mensaje').text('Por favor capture usuario y contraseña.');
        return;
    }

    let $btn = $('#btnValidar');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validando...');
    $('#mensaje').text('');

    $.post('auth/procesar_login.php', {accion: 'validar', user: u, pass: p}, function(res) {
        $btn.prop('disabled', false).html('<span>Continuar</span> <i class="bi bi-arrow-right ms-1"></i>');
        if(res.success) {
            $('#step-1').fadeOut(250, function(){
                let options = '';
                res.empresas.forEach(e => {
                    options += `<option value="${e.id_empresa}">${e.razon_social} (${e.rfc})</option>`;
                });
                $('#select-empresa').html(options);
                $('#step-2').fadeIn(250);
            });
        } else {
            $('#mensaje').text(res.error || 'Credenciales inválidas.');
        }
    }, 'json').fail(function() {
        $btn.prop('disabled', false).html('<span>Continuar</span> <i class="bi bi-arrow-right ms-1"></i>');
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