<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
seguridad_exigir_superadmin($pdo, false);

$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
if ($idEmpresa <= 0) {
    echo '<div class="alert alert-danger">No hay una empresa activa.</div>';
    exit;
}

$csrfSyncCorreo = csrf_token();

$st = $pdo->prepare(
    'SELECT e.rfc, e.razon_social,
            c.activo AS contpaq_activo,
            c.servidor, c.base_datos, c.base_datos_comercial,
            c.ultima_sincronizacion
       FROM empresas e
       LEFT JOIN empresa_contpaq_config c ON c.id_empresa=e.id_empresa
      WHERE e.id_empresa=?
      LIMIT 1'
);
$st->execute([$idEmpresa]);
$empresa = $st->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<style>
#syncCorreoCard .kpi-sync { min-height:72px; }
#syncCorreoCard .kpi-sync .v { font-size:1.25rem; font-weight:700; }
#syncCorreoLog {
    height:170px; overflow:auto; background:#0d1117; border:1px solid #30363d;
    color:#c9d1d9; font-family:Consolas,monospace; font-size:.74rem; padding:8px;
}
</style>

<div class="animate__animated animate__fadeIn" id="syncCorreoCard">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-envelope-arrow-down me-2 text-info"></i>SINCRONIZAR CORREOS DESDE CONTPAQ COMERCIAL</h6>
            <div class="small text-muted">
                Empresa:
                <b><?= htmlspecialchars((string)($empresa['razon_social'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b>
                · RFC <?= htmlspecialchars((string)($empresa['rfc'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cargarModulo('emisores')">
            <i class="bi bi-arrow-left me-1"></i>Volver a emisores
        </button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="alert alert-info py-2 small">
                Se lee <b>dbo.admClientes</b> de la base Comercial y se cruza por <b>RFC</b> contra <b>cat_emisores</b>.
                Se toma el primer correo válido disponible en <b>CEMAIL1 → CEMAIL2 → CEMAIL3</b>.
                Nunca se borra un correo del visor por venir vacío desde CONTPAQi.
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Servidor / instancia</div>
                        <b><?= htmlspecialchars((string)($empresa['servidor'] ?? 'SIN CONFIGURAR'), ENT_QUOTES, 'UTF-8') ?></b>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">BD Bancos</div>
                        <b><?= htmlspecialchars((string)($empresa['base_datos'] ?? 'SIN CONFIGURAR'), ENT_QUOTES, 'UTF-8') ?></b>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">BD Comercial</div>
                        <b class="text-info"><?= htmlspecialchars((string)($empresa['base_datos_comercial'] ?? 'SIN CONFIGURAR'), ENT_QUOTES, 'UTF-8') ?></b>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button id="btnIniciarSyncCorreo" type="button" class="btn btn-primary fw-bold" onclick="iniciarSyncCorreosContpaq()">
                    <i class="bi bi-play-fill me-1"></i>INICIAR SINCRONIZACIÓN
                </button>
                <button id="btnCancelarSyncCorreo" type="button" class="btn btn-outline-danger fw-bold" onclick="cancelarSyncCorreosContpaq()" disabled>
                    <i class="bi bi-stop-circle me-1"></i>CANCELAR
                </button>
            </div>

            <div class="mb-2 d-flex justify-content-between small">
                <span id="syncCorreoEstado">Listo para iniciar.</span>
                <b id="syncCorreoPct">0%</b>
            </div>
            <div class="progress mb-3" style="height:24px;">
                <div id="syncCorreoBar" class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width:0%">0%</div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">Leídos</div><div id="kLeidos" class="v">0</div></div></div>
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">RFC encontrados</div><div id="kCoinciden" class="v">0</div></div></div>
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">Actualizados</div><div id="kActualizados" class="v text-success">0</div></div></div>
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">Sin cambio</div><div id="kSinCambio" class="v">0</div></div></div>
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">No encontrados</div><div id="kNoEncontrados" class="v text-warning">0</div></div></div>
                <div class="col-6 col-md-2"><div class="border rounded p-2 text-center kpi-sync"><div class="small text-muted">Correo inválido</div><div id="kInvalidos" class="v text-danger">0</div></div></div>
            </div>

            <div class="small fw-bold mb-1">DETALLE DEL PROCESO</div>
            <div id="syncCorreoLog">Esperando inicio...</div>
        </div>
    </div>
</div>

<script>
(function(){
    const CSRF_SYNC_CORREO = <?= json_encode($csrfSyncCorreo, JSON_UNESCAPED_UNICODE) ?>;
    let tokenSyncCorreo = null;
    let cancelandoSyncCorreo = false;

    function logSyncCorreo(msg) {
        const el = $('#syncCorreoLog');
        const hora = new Date().toLocaleTimeString();
        el.append($('<div>').text('[' + hora + '] ' + msg));
        el.scrollTop(el[0].scrollHeight);
    }

    function pintarSyncCorreo(r) {
        const total = Number(r.total || 0);
        const leidos = Number(r.leidos || 0);
        const pct = total > 0 ? Math.min(100, Math.round((leidos / total) * 100)) : (r.terminado ? 100 : 0);

        $('#syncCorreoBar').css('width', pct + '%').text(pct + '%');
        $('#syncCorreoPct').text(pct + '%');
        $('#kLeidos').text(leidos.toLocaleString());
        $('#kCoinciden').text(Number(r.coinciden || 0).toLocaleString());
        $('#kActualizados').text(Number(r.actualizados || 0).toLocaleString());
        $('#kSinCambio').text(Number(r.sin_cambio || 0).toLocaleString());
        $('#kNoEncontrados').text(Number(r.no_encontrados || 0).toLocaleString());
        $('#kInvalidos').text(Number(r.invalidos || 0).toLocaleString());

        if (r.estado) $('#syncCorreoEstado').text(r.estado);
    }

    window.iniciarSyncCorreosContpaq = function() {
        if (tokenSyncCorreo) return;

        cancelandoSyncCorreo = false;
        $('#syncCorreoLog').empty();
        $('#btnIniciarSyncCorreo').prop('disabled', true);
        $('#btnCancelarSyncCorreo').prop('disabled', false);
        $('#syncCorreoEstado').text('Preparando sincronización...');
        logSyncCorreo('Conectando con CONTPAQi Comercial...');

        $.ajax({
            url: 'ajax/sincronizar_correos_contpaq.php',
            method: 'POST',
            dataType: 'json',
            data: {accion:'iniciar', csrf_token:CSRF_SYNC_CORREO}
        }).done(function(r){
            if (!r.success) throw new Error(r.error || 'No fue posible iniciar.');
            tokenSyncCorreo = r.token;
            pintarSyncCorreo(r);
            logSyncCorreo('Encontrados ' + Number(r.total || 0).toLocaleString() + ' registros de Comercial con RFC/correo.');
            pasoSyncCorreo();
        }).fail(function(xhr){
            const m = xhr.responseJSON?.error || xhr.responseText || 'Error al iniciar.';
            terminarSyncCorreoUI(false, m);
        });
    };

    function pasoSyncCorreo() {
        if (!tokenSyncCorreo || cancelandoSyncCorreo) return;

        $.ajax({
            url: 'ajax/sincronizar_correos_contpaq.php',
            method: 'POST',
            dataType: 'json',
            data: {accion:'paso', token:tokenSyncCorreo, csrf_token:CSRF_SYNC_CORREO}
        }).done(function(r){
            if (!r.success) {
                terminarSyncCorreoUI(false, r.error || 'Error de sincronización.');
                return;
            }

            pintarSyncCorreo(r);
            if (r.mensaje_paso) logSyncCorreo(r.mensaje_paso);

            if (r.cancelado) {
                terminarSyncCorreoUI(false, 'Sincronización cancelada por el usuario.', true);
                return;
            }

            if (r.terminado) {
                terminarSyncCorreoUI(true, 'Sincronización terminada correctamente.');
                return;
            }

            setTimeout(pasoSyncCorreo, 80);
        }).fail(function(xhr){
            const m = xhr.responseJSON?.error || xhr.responseText || 'Error durante la sincronización.';
            terminarSyncCorreoUI(false, m);
        });
    }

    window.cancelarSyncCorreosContpaq = function() {
        if (!tokenSyncCorreo || cancelandoSyncCorreo) return;

        Swal.fire({
            icon:'warning',
            title:'¿Cancelar sincronización?',
            text:'Se detendrá al terminar el bloque actual. Los correos ya actualizados se conservan.',
            showCancelButton:true,
            confirmButtonText:'Sí, cancelar',
            cancelButtonText:'Continuar'
        }).then(function(x){
            if (!x.isConfirmed) return;
            cancelandoSyncCorreo = true;
            $('#btnCancelarSyncCorreo').prop('disabled', true);
            $('#syncCorreoEstado').text('Cancelando...');
            logSyncCorreo('Solicitud de cancelación enviada...');

            $.post('ajax/sincronizar_correos_contpaq.php', {
                accion:'cancelar',
                token:tokenSyncCorreo,
                csrf_token:CSRF_SYNC_CORREO
            }, function(r){
                if (r && r.success) {
                    terminarSyncCorreoUI(false, 'Sincronización cancelada por el usuario.', true);
                } else {
                    cancelandoSyncCorreo = false;
                    $('#btnCancelarSyncCorreo').prop('disabled', false);
                    logSyncCorreo('No se pudo confirmar la cancelación.');
                }
            }, 'json').fail(function(){
                cancelandoSyncCorreo = false;
                $('#btnCancelarSyncCorreo').prop('disabled', false);
                logSyncCorreo('Error al solicitar cancelación.');
            });
        });
    };

    function terminarSyncCorreoUI(ok, mensaje, cancelado=false) {
        logSyncCorreo(mensaje);
        $('#syncCorreoEstado').text(mensaje);
        $('#btnIniciarSyncCorreo').prop('disabled', false);
        $('#btnCancelarSyncCorreo').prop('disabled', true);

        if (ok) {
            $('#syncCorreoBar').removeClass('bg-danger bg-warning').addClass('bg-success');
            Swal.fire({icon:'success', title:'Sincronización terminada', text:mensaje});
        } else if (cancelado) {
            $('#syncCorreoBar').removeClass('bg-danger bg-success').addClass('bg-warning');
        } else {
            $('#syncCorreoBar').removeClass('bg-success bg-warning').addClass('bg-danger');
            Swal.fire({icon:'error', title:'Sincronización detenida', text:mensaje});
        }
        tokenSyncCorreo = null;
        cancelandoSyncCorreo = false;
    }
})();
</script>
