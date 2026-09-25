<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/seguridad.php';
seguridad_exigir_sesion($pdo, true, false, true);
require_once __DIR__ . '/includes/permisos_documentos.php';
if (!isset($_SESSION['id_usuario']) || !isset($_SESSION['id_empresa'])) {
    header('Location: index.php');
    exit;
}
$nombreReal = trim((string)($_SESSION['nombre_usuario'] ?? 'Usuario'));
$nombreEmpresa = trim((string)($_SESSION['empresa_nombre'] ?? ''));
$esSuperAdmin = $_SESSION['es_superadmin'] ?? 0;
$permisosAcciones = obtener_permisos_acciones($pdo, (int)$_SESSION['id_usuario'], (int)$_SESSION['id_empresa']);
$puedeProcesarPagos = in_array('procesar_pagos', $permisosAcciones, true);
$puedeGenerarDiot = in_array('generar_diot', $permisosAcciones, true);
$puedeVerSolicitudesSat = in_array('ver_solicitudes_sat', $permisosAcciones, true);
$puedeContpaqCheques = in_array('contpaq_cheques', $permisosAcciones, true);
$puedeOraclePagos = in_array('oracle_pagos', $permisosAcciones, true);
$puedeNominaArchivo = in_array('nomina_archivo', $permisosAcciones, true);
$puedeCruceMetadata = in_array('consulta_cruce_metadata', $permisosAcciones, true);
$puedeCodigosPostales = in_array('consulta_codigos_postales', $permisosAcciones, true);
$puedeProductosServicios = $puedeCodigosPostales; // Comparte permiso de catálogos SAT con Códigos postales.
$puedeSat69 = in_array('consulta_sat_69', $permisosAcciones, true);
$puedeAlertas69 = in_array('consulta_alertas_69', $permisosAcciones, true);
$puedeSat69B = in_array('consulta_sat_69b', $permisosAcciones, true);
$puedeAlertas69B = in_array('consulta_alertas_69b', $permisosAcciones, true);
$puedeValidacionesSat = $puedeCruceMetadata || $puedeCodigosPostales || $puedeSat69 || $puedeAlertas69 || $puedeSat69B || $puedeAlertas69B;

// Recuperar el nombre de la empresa cuando la sesión solo conserva el ID.
// Esto evita que la barra superior aparezca vacía después de seleccionar empresa.
if ($nombreEmpresa === '' && !empty($_SESSION['id_empresa'])) {
    try {
        require_once __DIR__ . '/config/db.php';
        $stmtEmpresaActiva = $pdo->prepare("SELECT razon_social FROM empresas WHERE id_empresa = ? AND activo=1 LIMIT 1");
        $stmtEmpresaActiva->execute([$_SESSION['id_empresa']]);
        $empresaActiva = $stmtEmpresaActiva->fetch(PDO::FETCH_ASSOC);

        if ($empresaActiva && !empty($empresaActiva['razon_social'])) {
            $nombreEmpresa = trim($empresaActiva['razon_social']);
            $_SESSION['empresa_nombre'] = $nombreEmpresa;
        }
    } catch (Throwable $e) {
        // No interrumpimos el acceso al sistema si falla únicamente la etiqueta visual.
    }
}

if ($nombreEmpresa === '') {
    $nombreEmpresa = 'Empresa no identificada';
}

// Ya no se modificará la sesión durante el render de main.php.
// Liberamos el lock de sesión para que las peticiones AJAX de módulos
// puedan ejecutarse en paralelo sin esperar a otra petición PHP.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PitayaCode • Visor XML Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --bg-dark: #202020;
            --bg-card: #282828;
            --pitaya-green: #24A77F;
            --pitaya-blue: #17A0C6;
            --pitaya-pink: #DA3C7A;
            --borde: #383838;
        }
        body {
            background-color: var(--bg-dark);
            color: #F5F5F5;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .navbar-custom {
            background-color: var(--bg-card);
            border-bottom: 1px solid var(--borde);
            padding: 0.6rem 1.25rem;
        }
        .brand-pitaya-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            letter-spacing: -0.02em;
            font-size: 1.05rem;
        }
        .brand-pitaya-title i {
            color: var(--pitaya-green);
            font-size: 1.2rem;
        }
        .brand-pitaya-title .brand-accent {
            background: linear-gradient(135deg, var(--pitaya-green) 0%, var(--pitaya-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }
        .brand-pitaya-title .brand-sub {
            color: #A0A0A0;
            font-weight: 500;
            font-size: 0.88rem;
        }
        .btn-nav {
            background: transparent;
            border: 1px solid transparent;
            color: #A0A0A0;
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            margin: 0 0.15rem;
            transition: all 0.2s ease;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .btn-nav:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #FFFFFF;
            border-color: #383838;
        }
        .btn-nav.active {
            background: var(--pitaya-green);
            border-color: var(--pitaya-green);
            color: #FFFFFF;
            box-shadow: 0 2px 8px rgba(36, 167, 127, 0.35);
        }
        #contenido-dinamico {
            padding: 24px;
            min-height: calc(100vh - 120px);
        }
        .empresa-activa {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(36, 167, 127, 0.1);
            border: 1px solid rgba(36, 167, 127, 0.35);
            color: #24A77F;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: .2px;
        }
        .empresa-activa .etiqueta {
            color: #A0A0A0;
            font-size: 0.70rem;
            font-weight: 600;
        }
        .usuario-activo {
            color: #A0A0A0;
            font-size: 0.80rem;
            margin-left: 14px;
            white-space: nowrap;
            font-weight: 500;
        }
        .usuario-activo:hover {
            color: #FFFFFF;
        }
        .navbar-buttons {
            background-color: #242424 !important;
            border-bottom: 1px solid #333333 !important;
        }
        @media (max-width: 768px) {
            .navbar-custom .container-fluid { align-items: flex-start !important; gap: 8px; }
            .navbar-custom .d-flex.align-items-center { flex-wrap: wrap; }
            .empresa-activa { width: 100%; margin-top: 5px; }
            .usuario-activo { margin-left: 0; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/tema_kconta.css?v=<?= filemtime(__DIR__ . '/assets/css/tema_kconta.css') ?>">
</head>
<body>

<nav class="navbar navbar-custom">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <span class="brand-pitaya-title me-3">
                <img src="assets/img/pitayacode-icon.png" alt="PitayaCode" style="height: 32px; width: auto; vertical-align: -6px;" class="me-2">
                <span class="brand-accent">PitayaCode</span>
                <span class="brand-sub">Visor XML Pro</span>
            </span>
            <div class="empresa-activa" title="Empresa seleccionada actualmente">
                <i class="bi bi-building-check"></i>
                <span class="etiqueta">EMPRESA:</span>
                <span><?= htmlspecialchars(mb_strtoupper($nombreEmpresa, 'UTF-8')) ?></span>
            </div>
            <button type="button" class="usuario-activo btn btn-link p-0 text-decoration-none" onclick="cargarModulo('mi_perfil')" title="Mi perfil / Cambiar contraseña">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombreReal) ?>
                <i class="bi bi-chevron-down ms-1" style="font-size:.65rem"></i>
            </button>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($puedeVerSolicitudesSat): ?>
            <button type="button" id="btnNotificacionesSat" class="btn btn-outline-warning btn-sm position-relative" onclick="cargarModulo('notificaciones_sat')" title="Alertas SAT">
                <i class="bi bi-bell-fill"></i> Alertas
                <span id="badgeNotificacionesSat" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none">0</span>
            </button>
            <?php endif; ?>
            <a href="auth/logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Salir</a>
        </div>
    </div>
</nav>

<div class="navbar-buttons border-bottom border-secondary py-2 bg-card">
    <div class="container-fluid d-flex flex-wrap justify-content-center">
        <button class="btn-nav" data-module="dashboard" onclick="cargarModulo('dashboard')"><i class="bi bi-speedometer2"></i> INICIO</button>
        <button class="btn-nav" data-module="visor" onclick="cargarModulo('visor')"><i class="bi bi-table"></i> VISOR</button>
        <button class="btn-nav" data-module="subir_xml" onclick="cargarModulo('subir_xml')"><i class="bi bi-cloud-upload"></i> SUBIR XML</button>
        <button class="btn-nav" data-module="emisores" onclick="cargarModulo('emisores')"><i class="bi bi-person-vcard"></i> EMISORES</button>
        <?php if ($puedeGenerarDiot): ?><button class="btn-nav" data-module="diot_view" onclick="cargarModulo('diot_view')"><i class="bi bi-file-earmark-ruled"></i> GENERAR DIOT</button><?php endif; ?>
        <?php if ($puedeVerSolicitudesSat): ?><button class="btn-nav" data-module="solicitudes_sat" onclick="cargarModulo('solicitudes_sat')"><i class="bi bi-cloud-arrow-down"></i> SOLICITUDES SAT</button><?php endif; ?>
        <?php if ($puedeProcesarPagos): ?>
        <button class="btn-nav" type="button" onclick="procesarPagos()">
            <i class="bi bi-currency-dollar"></i> PROCESAR PAGOS
        </button>
        <?php endif; ?>
        <?php if ($puedeContpaqCheques): ?>
        <button class="btn-nav" data-module="contpaq_cheques" type="button" onclick="cargarModulo('contpaq_cheques')">
            <i class="bi bi-bank2"></i> CONTPAQ CHEQUES
        </button>
        <?php endif; ?>
        <?php if ($puedeOraclePagos): ?>
        <button class="btn-nav" data-module="oracle_pagos" type="button" onclick="cargarModulo('oracle_pagos')">
            <i class="bi bi-cloud-arrow-down"></i> ORACLE PAGOS
        </button>
        <button class="btn-nav" data-module="oracle_iva_conciliacion" type="button" onclick="cargarModulo('oracle_iva_conciliacion')">
            <i class="bi bi-intersect"></i> CONCILIAR IVA
        </button>
        <?php endif; ?>
        <?php if ($puedeNominaArchivo): ?>
        <button class="btn-nav" data-module="nomina" type="button" onclick="cargarModulo('nomina')">
            <i class="bi bi-people-fill"></i> NÓMINA
        </button>
        <?php endif; ?>

        <?php if ($puedeValidacionesSat): ?>
            <div class="dropdown d-inline-block ms-1">
                <button class="btn-nav dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-shield-check"></i> VALIDACIONES SAT
                </button>
                <ul class="dropdown-menu dropdown-menu-dark">
                    <?php if ($puedeCruceMetadata): ?><li><button class="dropdown-item text-info" type="button" onclick="cargarModulo('cruce_metadata_cfdi')"><i class="bi bi-intersect me-2"></i>Cruce Metadata vs CFDI</button></li><?php endif; ?>
                    <?php if ($puedeCodigosPostales): ?><li><button class="dropdown-item" type="button" onclick="cargarModulo('codigos_postales')"><i class="bi bi-mailbox me-2"></i>Códigos postales</button></li><?php endif; ?>
                    <?php if ($puedeProductosServicios): ?><li><button class="dropdown-item" type="button" onclick="cargarModulo('productos_servicios_sat')"><i class="bi bi-box-seam me-2"></i>Productos y servicios SAT</button></li><?php endif; ?>
                    <?php if ($puedeSat69): ?><li><button class="dropdown-item" type="button" onclick="cargarModulo('sat_69')"><i class="bi bi-shield-check me-2"></i>Artículo 69 SAT</button></li><?php endif; ?>
                    <?php if ($puedeAlertas69): ?><li><button class="dropdown-item text-danger" type="button" onclick="cargarModulo('alertas_emisores_69')"><i class="bi bi-person-exclamation me-2"></i>Emisores con riesgos 69</button></li><?php endif; ?>
                    <?php if (($puedeSat69 || $puedeAlertas69) && ($puedeSat69B || $puedeAlertas69B)): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                    <?php if ($puedeSat69B): ?><li><button class="dropdown-item" type="button" onclick="cargarModulo('sat_69b')"><i class="bi bi-shield-exclamation me-2"></i>Artículo 69-B SAT</button></li><?php endif; ?>
                    <?php if ($puedeAlertas69B): ?><li><button class="dropdown-item text-warning" type="button" onclick="cargarModulo('alertas_emisores_69b')"><i class="bi bi-exclamation-triangle-fill me-2"></i>Emisores con alertas 69-B</button></li><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($esSuperAdmin): ?>
            <div class="dropdown d-inline-block ms-1">
                <button class="btn-nav dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-gear"></i> ADMINISTRACIÓN
                </button>
                <ul class="dropdown-menu dropdown-menu-dark">
                    <li><button class="dropdown-item" type="button" onclick="cargarModulo('empresas')"><i class="bi bi-building me-2"></i>Empresas</button></li>
                    <li><button class="dropdown-item" type="button" onclick="cargarModulo('usuarios')"><i class="bi bi-people me-2"></i>Usuarios</button></li>
                    <li><button class="dropdown-item" type="button" onclick="cargarModulo('zonas')"><i class="bi bi-geo-alt me-2"></i>Zonas</button></li>
                    <li><button class="dropdown-item text-info" type="button" onclick="cargarModulo('sincronizar_correos_contpaq')"><i class="bi bi-envelope-arrow-down me-2"></i>Sincronizar correos CONTPAQ</button></li>
                    <li><button class="dropdown-item" type="button" onclick="cargarModulo('asignacion')"><i class="bi bi-diagram-3 me-2"></i>Asignaciones</button></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="contenido-dinamico">
    <div class="text-center mt-5">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Cargando módulo...</p>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Idioma general de DataTables para todos los módulos del sistema.
    // Se define localmente para no depender del archivo de traducción externo.
    if ($.fn.dataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            language: {
                decimal: '',
                emptyTable: 'No hay información disponible',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Mostrando 0 a 0 de 0 registros',
                infoFiltered: '(filtrado de _MAX_ registros totales)',
                infoPostFix: '',
                thousands: ',',
                lengthMenu: 'Mostrar _MENU_ registros',
                loadingRecords: 'Cargando...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron registros coincidentes',
                paginate: {
                    first: 'Primero',
                    last: 'Último',
                    next: 'Siguiente',
                    previous: 'Anterior'
                },
                aria: {
                    sortAscending: ': activar para ordenar ascendente',
                    sortDescending: ': activar para ordenar descendente'
                }
            }
        });
        $.fn.dataTable.ext.errMode = 'none';
    }

    <?php if ($puedeVerSolicitudesSat): ?>
    function actualizarContadorNotificaciones() {
        $.getJSON('ajax/notificaciones_sat_contador.php')
            .done(function(r) {
                const n = Number(r && r.nuevas ? r.nuevas : 0);
                const badge = $('#badgeNotificacionesSat');
                badge.text(n > 99 ? '99+' : n);
                badge.toggle(n > 0);
            })
            .fail(function(){ $('#badgeNotificacionesSat').hide(); });
    }
    actualizarContadorNotificaciones();
    setInterval(actualizarContadorNotificaciones, 60000);
    <?php endif; ?>

    // --- CONTROL DE PETICIONES DE LECTURA DE MÓDULOS ---
    // Los módulos se cargan dinámicamente dentro de #contenido-dinamico.
    // Si el usuario cambia de pantalla mientras un DataTable / getJSON sigue consultando,
    // el navegador normalmente deja viva esa petición aunque el HTML anterior ya desapareció.
    // Con bases grandes eso puede dejar consultas viejas trabajando y acumular carga.
    // Registramos SOLO peticiones GET; los POST (procesos / guardados) nunca se cancelan aquí.
    window.lecturasModuloActivas = window.lecturasModuloActivas || new Set();
    window.cargaModuloXhr = null;

    $(document)
        .off('ajaxSend.controlModulo ajaxComplete.controlModulo')
        .on('ajaxSend.controlModulo', function(_evt, xhr, settings) {
            const metodo = String(settings.type || settings.method || 'GET').toUpperCase();
            const url = String(settings.url || '');
            if (metodo !== 'GET') return;
            if (!(url.startsWith('ajax/') || url.startsWith('modulos/'))) return;
            // El contador de la campana es pequeño y periódico; no forma parte del módulo visible.
            if (url.indexOf('ajax/notificaciones_sat_contador.php') !== -1) return;
            window.lecturasModuloActivas.add(xhr);
        })
        .on('ajaxComplete.controlModulo', function(_evt, xhr) {
            window.lecturasModuloActivas.delete(xhr);
        });

    function cancelarLecturasModuloAnteriores() {
        if (!window.lecturasModuloActivas) return;
        Array.from(window.lecturasModuloActivas).forEach(function(xhr) {
            try {
                if (xhr && xhr.readyState !== 4) xhr.abort();
            } catch (e) {}
        });
        window.lecturasModuloActivas.clear();
    }

    // --- LÓGICA DE CARGA DE MÓDULOS ---
    function cargarModulo(nombre) {
        // Si salimos del VISOR, conservar sus filtros antes de reemplazar el HTML.
        // De esta forma al entrar a Solicitudes SAT (u otro módulo) y regresar,
        // el visor continúa exactamente con el rango/búsqueda que tenía aplicado.
        if ($('#tablaFacturas').length && typeof window.guardarEstadoVisorNavegacion === 'function') {
            try { window.guardarEstadoVisorNavegacion(); } catch (e) { console.warn('No se pudo guardar el estado del visor:', e); }
        }

        // Antes de cambiar de opción, matar lecturas GET de la pantalla anterior.
        // Esto evita que Visor, DIOT, Solicitudes, CONTPAQ, etc. dejen AJAX viejos vivos.
        cancelarLecturasModuloAnteriores();

        if (window.cargaModuloXhr && window.cargaModuloXhr.readyState !== 4) {
            try { window.cargaModuloXhr.abort(); } catch (e) {}
        }

        $('.btn-nav').removeClass('active');
        // Marcar activo el botón correspondiente
        $(`[data-module="${nombre}"]`).addClass('active');

        window.cargaModuloXhr = $.ajax({
            url: 'modulos/' + nombre + '.php',
            type: 'GET',
            cache: false,
            success: function(html) {
                $('#contenido-dinamico').html(html);
            },
            error: function(xhr, status) {
                // Un abort es intencional cuando el usuario cambió rápidamente de módulo.
                if (status === 'abort') return;
                if (xhr.status === 401 || xhr.status === 403) {
                    window.location.href = 'index.php';
                    return;
                }
                const detalle = xhr.responseText ? $('<div>').text(xhr.responseText).html() : 'Sin detalle del servidor';
                $('#contenido-dinamico').html(
                    '<div class="alert alert-danger"><strong>Error al cargar el módulo ' + nombre + '.</strong><br><small>' + detalle + '</small></div>'
                );
            },
            complete: function() {
                window.cargaModuloXhr = null;
            }
        });
    }

    // --- FUNCIÓN PARA ELIMINAR FACTURA ---
    function eliminarFactura(id) {
        Swal.fire({
            title: '¿Eliminar factura?',
            text: "Esta acción no se puede deshacer.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Sí, eliminar',
            background: '#ffffff',
            color: '#172b3a'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('ajax/eliminar_factura.php', {id: id}, function(res) {
                    if(res.status === 'ok') {
                        if (typeof table !== 'undefined' && $('#tablaFacturas').length > 0) {
                            table.ajax.reload(null, false);
                        }
                        Swal.fire('Eliminado', res.msg, 'success');
                    } else {
                        Swal.fire('Error', res.msg, 'error');
                    }
                }, 'json');
            }
        });
    }

    // --- FUNCIÓN PARA EDITAR FECHA DE PAGO ---
    function editarFechaPago(id, fechaActual) {
        Swal.fire({
            title: 'Editar Fecha de Pago',
            html: `<input type="date" id="nueva_fecha" class="form-control" value="${fechaActual}">`,
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            preConfirm: () => {
                return document.getElementById('nueva_fecha').value;
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                $.post('ajax/actualizar_fecha_pago.php', {id: id, fecha: result.value}, function(res) {
                    if(res.status === 'ok') {
                        if (typeof table !== 'undefined' && $('#tablaFacturas').length > 0) {
                            table.ajax.reload(null, false);
                        }
                        Swal.fire('Actualizado', '', 'success');
                    }
                }, 'json');
            }
        });
    }

    // --- FUNCIÓN PARA PROCESAR PAGOS ---
    function procesarPagos() {
        Swal.fire({
            title: '¿Procesar todos los pagos?',
            text: "Se aplicarán las fechas de pago a los complementos y facturas PUE pendientes.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#21875b',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sí, procesar',
            background: '#ffffff',
            color: '#172b3a'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Procesando...',
                    html: 'Sincronizando saldos y fechas, espera un momento.',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'ajax/aplicar_pagos_pendientes.php',
                    type: 'POST',
                    dataType: 'json',
                    success: function(res) {
                        if(res.status === 'ok') {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Éxito!',
                                text: res.msg,
                                background: '#161b22',
                                color: '#c9d1d9'
                            });
                            if (typeof table !== 'undefined' && $('#tablaFacturas').length > 0) {
                                table.ajax.reload(null, false);
                            }
                        } else {
                            Swal.fire('Error', res.msg, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error');
                    }
                });
            }
        });
    }

    // --- FUNCIÓN PARA DESCARGAR EXCEL ---
    function descargarExcel() {
        let mes = $('#filtro_mes').val() || '';
        let anio = $('#filtro_anio').val() || '';
        window.location.href = `ajax/exportar_excel.php?mes=${mes}&anio=${anio}`;
    }

    $(document).ready(function() {
        cargarModulo('visor');
    });
</script>
</body>
</html>