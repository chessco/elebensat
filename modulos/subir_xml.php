<?php
// modulos/subir_xml.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
?>
<style>
.xml-drop-zone {
    border: 2px dashed #8bb7d1;
    border-radius: 14px;
    background: #f7fbfe;
    padding: 30px 20px;
    text-align: center;
    cursor: pointer;
    transition: .18s ease;
}
.xml-drop-zone:hover, .xml-drop-zone.dragover {
    border-color: #0b7cab;
    background: #edf8fd;
    transform: translateY(-1px);
}
.xml-drop-zone .drop-icon { font-size: 2.5rem; color: #0b7cab; }
.xml-option-btn { border-radius: 12px; min-height: 112px; }
.xml-browser-note { font-size: .82rem; }
</style>
<div class="container py-4">
    <h2 class="mb-4 text-center">Cargar XML de Factura</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-primary" id="btnAbrirCargaXml">
                    <i class="bi bi-cloud-upload"></i> Seleccionar XML
                </button>
                <span class="text-muted">Puede elegir archivos individuales o una carpeta completa.</span>
            </div>
            <div id="uploadResult" class="mt-3"></div>
        </div>
    </div>
</div>

<!-- Inputs reales ocultos. El navegador no entrega la ruta física; sí permite elegir una carpeta completa. -->
<input type="file" id="xmlFilesInput" accept=".xml,text/xml,application/xml" multiple hidden>

<div class="modal fade" id="modalCargaXml" tabindex="-1" aria-labelledby="modalCargaXmlLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCargaXmlLabel">
                    <i class="bi bi-filetype-xml"></i> Carga de archivos XML
                </h5>
                <button type="button" class="btn-close" id="btnCerrarModalXml" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div id="panelSeleccionXml">
                    <div class="xml-drop-zone mb-3" id="zonaSoltarXml">
                        <i class="bi bi-cloud-arrow-up drop-icon d-block mb-2"></i>
                        <h5 class="mb-1">Arrastra aquí una carpeta o tus archivos XML</h5>
                        <div class="text-muted">Es la forma recomendada y evita la confirmación oscura del navegador.</div>
                    </div>

                    <div class="text-center text-muted small mb-3">— o selecciónalos manualmente —</div>
                    <div class="row justify-content-center">
                        <div class="col-md-7">
                            <button type="button" class="btn btn-primary w-100 px-4 py-3 xml-option-btn" id="btnElegirArchivosXml">
                                <i class="bi bi-files fs-3 d-block mb-2"></i>
                                <strong>Elegir archivos XML</strong>
                                <span class="d-block small mt-1 opacity-75">Uno o varios archivos XML</span>
                            </button>
                        </div>
                    </div>
                    <div class="alert alert-info border-0 mt-3 mb-0 py-2 xml-browser-note text-center">
                        <i class="bi bi-folder2-open"></i> Para cargar una carpeta completa, arrástrala desde el Explorador de Windows al recuadro superior.
                    </div>
                </div>

                <div id="panelProcesoXml" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong id="textoAvanceXml">Preparando carga...</strong>
                        <span class="badge bg-secondary" id="porcentajeXml">0%</span>
                    </div>
                    <div class="progress mb-3" style="height: 24px;">
                        <div id="barraAvanceXml" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>

                    <div class="row g-2 text-center mb-3">
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><small class="text-muted d-block">Total</small><strong id="contadorTotalXml">0</strong></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><small class="text-muted d-block">Subidos</small><strong class="text-success" id="contadorOkXml">0</strong></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><small class="text-muted d-block">Duplicados</small><strong class="text-warning" id="contadorDuplicadosXml">0</strong></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><small class="text-muted d-block">Errores</small><strong class="text-danger" id="contadorErroresXml">0</strong></div></div>
                    </div>

                    <div class="small text-muted mb-2" id="archivoActualXml"></div>
                    <div id="detalleCargaXml" class="border rounded bg-light p-2" style="max-height:230px; overflow:auto; font-size:.88rem;"></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="btnCambiarSeleccionXml">Cambiar selección</button>
                <button type="button" class="btn btn-danger d-none" id="btnCancelarCargaXml">
                    <i class="bi bi-x-circle"></i> Cancelar carga
                </button>
                <button type="button" class="btn btn-primary d-none" id="btnIniciarCargaXml">
                    <i class="bi bi-play-fill"></i> Iniciar carga
                </button>
                <button type="button" class="btn btn-secondary" id="btnCerrarCargaXml" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    let archivosXml = [];
    let cargando = false;
    let cancelarSolicitado = false;
    let peticionActual = null;
    let resumen = { ok: 0, duplicados: 0, errores: 0, procesados: 0, rfcAjenos: 0 };

    const modalEl = document.getElementById('modalCargaXml');
    const modalXml = bootstrap.Modal.getOrCreateInstance(modalEl);

    function escaparHtml(texto) {
        return $('<div>').text(texto == null ? '' : String(texto)).html();
    }

    function reiniciarVista() {
        archivosXml = [];
        cargando = false;
        cancelarSolicitado = false;
        peticionActual = null;
        resumen = { ok: 0, duplicados: 0, errores: 0, procesados: 0, rfcAjenos: 0 };

        $('#xmlFilesInput').val('');
        $('#panelSeleccionXml').removeClass('d-none');
        $('#panelProcesoXml').addClass('d-none');
        $('#btnIniciarCargaXml').addClass('d-none').prop('disabled', false);
        $('#btnCancelarCargaXml').addClass('d-none').prop('disabled', false);
        $('#btnCambiarSeleccionXml').show().prop('disabled', false);
        $('#btnCerrarCargaXml, #btnCerrarModalXml').prop('disabled', false);
        $('#detalleCargaXml').empty();
        actualizarContadores();
        actualizarBarra(0, 'Arrastre una carpeta o seleccione archivos XML');
    }

    function actualizarContadores() {
        $('#contadorTotalXml').text(archivosXml.length);
        $('#contadorOkXml').text(resumen.ok);
        $('#contadorDuplicadosXml').text(resumen.duplicados);
        $('#contadorErroresXml').text(resumen.errores);
    }

    function actualizarBarra(porcentaje, texto) {
        porcentaje = Math.max(0, Math.min(100, Math.round(porcentaje || 0)));
        $('#barraAvanceXml').css('width', porcentaje + '%').attr('aria-valuenow', porcentaje).text(porcentaje + '%');
        $('#porcentajeXml').text(porcentaje + '%');
        if (texto) $('#textoAvanceXml').text(texto);
    }

    function agregarDetalle(tipo, archivo, mensaje) {
        const iconos = {
            ok: '<i class="bi bi-check-circle-fill text-success"></i>',
            duplicado: '<i class="bi bi-exclamation-circle-fill text-warning"></i>',
            error: '<i class="bi bi-x-circle-fill text-danger"></i>',
            info: '<i class="bi bi-info-circle-fill text-primary"></i>'
        };
        const linea = '<div class="py-1 border-bottom">' + (iconos[tipo] || iconos.info) +
            ' <strong>' + escaparHtml(archivo) + '</strong>' +
            (mensaje ? ' — ' + escaparHtml(mensaje) : '') + '</div>';
        $('#detalleCargaXml').append(linea);
        const panel = $('#detalleCargaXml')[0];
        panel.scrollTop = panel.scrollHeight;
    }

    function leerEntradaDirectorio(entry, rutaBase) {
        return new Promise(function (resolve) {
            if (entry.isFile) {
                entry.file(function (file) {
                    try { Object.defineProperty(file, 'rutaRelativa', { value: rutaBase + file.name }); } catch (e) {}
                    resolve([file]);
                }, function () { resolve([]); });
                return;
            }

            if (!entry.isDirectory) { resolve([]); return; }
            const reader = entry.createReader();
            let entradas = [];
            function leerLote() {
                reader.readEntries(async function (lote) {
                    if (!lote.length) {
                        const grupos = await Promise.all(entradas.map(function (hijo) {
                            return leerEntradaDirectorio(hijo, rutaBase + entry.name + '/');
                        }));
                        resolve([].concat.apply([], grupos));
                        return;
                    }
                    entradas = entradas.concat(Array.from(lote));
                    leerLote();
                }, function () { resolve([]); });
            }
            leerLote();
        });
    }

    async function obtenerArchivosSoltados(dataTransfer) {
        const items = Array.from((dataTransfer && dataTransfer.items) || []);
        if (!items.length) return Array.from((dataTransfer && dataTransfer.files) || []);

        const entradas = items.map(function (item) {
            return item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
        }).filter(Boolean);

        if (!entradas.length) return Array.from(dataTransfer.files || []);
        const grupos = await Promise.all(entradas.map(function (entry) {
            return leerEntradaDirectorio(entry, '');
        }));
        return [].concat.apply([], grupos);
    }



    function prepararArchivos(fileList, origen) {
        if (cargando) return;

        const todos = Array.from(fileList || []);
        archivosXml = todos.filter(function (file) {
            return file.name && file.name.toLowerCase().endsWith('.xml');
        });

        $('#xmlFilesInput').val('');
        resumen = { ok: 0, duplicados: 0, errores: 0, procesados: 0, rfcAjenos: 0 };
        $('#detalleCargaXml').empty();

        if (!archivosXml.length) {
            $('#uploadResult').html('<div class="alert alert-warning mb-0">No se encontraron archivos con extensión XML.</div>');
            return;
        }

        $('#panelSeleccionXml').addClass('d-none');
        $('#panelProcesoXml').removeClass('d-none');
        $('#btnIniciarCargaXml').removeClass('d-none').prop('disabled', false);
        $('#btnCancelarCargaXml').addClass('d-none');
        $('#btnCambiarSeleccionXml').show().prop('disabled', false);
        $('#archivoActualXml').text(origen + ': ' + archivosXml.length + ' archivo(s) XML listos.');
        actualizarContadores();
        actualizarBarra(0, 'Listos para iniciar');
        agregarDetalle('info', origen, archivosXml.length + ' XML seleccionados');
    }

    async function enviarArchivo(file, indice) {
        return new Promise(function (resolve) {
            const data = new FormData();
            data.append('xml', file, file.name);

            peticionActual = $.ajax({
                url: 'ajax/procesar_xml_unico.php',
                type: 'POST',
                data: data,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 180000
            }).done(function (resp) {
                resolve(resp || { status: 'error', msg: 'Respuesta vacía del servidor' });
            }).fail(function (xhr, textStatus) {
                if (textStatus === 'abort') {
                    resolve({ status: 'cancelado', msg: 'Solicitud cancelada' });
                    return;
                }

                let mensaje = 'Error de comunicación con el servidor';
                if (textStatus === 'timeout') mensaje = 'Tiempo de espera agotado';
                if (xhr && xhr.responseText) {
                    try {
                        const json = JSON.parse(xhr.responseText);
                        if (json.msg) mensaje = json.msg;
                    } catch (e) {
                        const limpio = String(xhr.responseText).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                        if (limpio) mensaje += ': ' + limpio.substring(0, 180);
                    }
                }
                resolve({ status: 'error', msg: mensaje });
            }).always(function () {
                peticionActual = null;
            });
        });
    }

    async function iniciarCarga() {
        if (cargando || !archivosXml.length) return;

        cargando = true;
        cancelarSolicitado = false;
        resumen = { ok: 0, duplicados: 0, errores: 0, procesados: 0, rfcAjenos: 0 };
        $('#detalleCargaXml').empty();
        $('#btnIniciarCargaXml').addClass('d-none');
        $('#btnCancelarCargaXml').removeClass('d-none').prop('disabled', false);
        $('#btnCambiarSeleccionXml').hide();
        $('#btnCerrarCargaXml, #btnCerrarModalXml').prop('disabled', true);
        $('#barraAvanceXml').addClass('progress-bar-animated');
        actualizarContadores();

        for (let i = 0; i < archivosXml.length; i++) {
            if (cancelarSolicitado) break;

            const file = archivosXml[i];
            const nombreVisible = file.webkitRelativePath || file.rutaRelativa || file.name;
            $('#archivoActualXml').text('Procesando ' + (i + 1) + ' de ' + archivosXml.length + ': ' + nombreVisible);
            actualizarBarra((i / archivosXml.length) * 100, 'Cargando XML...');

            const resp = await enviarArchivo(file, i);
            if (resp.status === 'cancelado') {
                cancelarSolicitado = true;
                break;
            }

            resumen.procesados++;
            if (resp.status === 'ok') {
                resumen.ok++;
                agregarDetalle('ok', nombreVisible, resp.msg || 'Procesado correctamente');
            } else if (resp.status === 'duplicado') {
                resumen.duplicados++;
                agregarDetalle('duplicado', nombreVisible, resp.msg || 'Ya estaba registrado');
            } else {
                resumen.errores++;
                if (resp.error_code === 'RFC_EMPRESA_NO_COINCIDE') {
                    resumen.rfcAjenos++;
                    agregarDetalle('error', nombreVisible, resp.msg || 'XML NO DADO DE ALTA: RFC ajeno a la empresa seleccionada');
                } else {
                    agregarDetalle('error', nombreVisible, resp.msg || 'No se pudo procesar');
                }
            }

            actualizarContadores();
            actualizarBarra(((i + 1) / archivosXml.length) * 100, 'Cargando XML...');
        }

        cargando = false;
        $('#btnCancelarCargaXml').addClass('d-none');
        $('#btnCerrarCargaXml, #btnCerrarModalXml').prop('disabled', false);
        $('#btnCambiarSeleccionXml').show().prop('disabled', false);
        $('#barraAvanceXml').removeClass('progress-bar-animated');

        const pendientes = archivosXml.length - resumen.procesados;
        let textoFinal;
        let claseAlerta;

        if (cancelarSolicitado) {
            textoFinal = 'Carga cancelada. Lo ya procesado permanece guardado. Procesados: ' + resumen.procesados +
                ', subidos: ' + resumen.ok + ', duplicados: ' + resumen.duplicados + ', errores: ' + resumen.errores +
                ', pendientes: ' + pendientes + '.';
            claseAlerta = 'alert-warning';
            actualizarBarra((resumen.procesados / archivosXml.length) * 100, 'Carga cancelada');
            agregarDetalle('info', 'Carga cancelada', pendientes + ' archivo(s) quedaron pendientes');
        } else {
            textoFinal = 'Carga terminada. Subidos: ' + resumen.ok + ', duplicados: ' + resumen.duplicados +
                ', errores: ' + resumen.errores + ', total revisados: ' + resumen.procesados + '.';
            claseAlerta = resumen.errores ? 'alert-warning' : 'alert-success';
            actualizarBarra(100, 'Carga terminada');
        }

        $('#archivoActualXml').text(textoFinal);
        $('#uploadResult').html('<div class="alert ' + claseAlerta + ' mb-0">' + escaparHtml(textoFinal) + '</div>');

        if (resumen.rfcAjenos > 0) {
            Swal.fire({
                icon: 'error',
                title: 'XML NO DADO DE ALTA',
                html: '<b>' + resumen.rfcAjenos + '</b> archivo(s) fueron rechazados porque el RFC del emisor o receptor no corresponde a la empresa seleccionada.<br><br><b>No se guardaron en la base de datos.</b>',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#dc3545'
            });
        }
    }

    $('#zonaSoltarXml').on('click', function () { $('#xmlFilesInput').trigger('click'); });

    $('#zonaSoltarXml').on('dragenter dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('dragover');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
    }).on('drop', async function (e) {
        if (cargando) return;
        const dt = e.originalEvent.dataTransfer;
        $('#zonaSoltarXml').html('<span class="spinner-border text-primary mb-2"></span><div>Revisando carpeta...</div>');
        const archivos = await obtenerArchivosSoltados(dt);
        $('#zonaSoltarXml').html('<i class="bi bi-cloud-arrow-up drop-icon d-block mb-2"></i><h5 class="mb-1">Arrastra aquí una carpeta o tus archivos XML</h5><div class="text-muted">Es la forma recomendada y evita la confirmación oscura del navegador.</div>');
        prepararArchivos(archivos, 'Elementos arrastrados');
    });

    $('#btnAbrirCargaXml').on('click', function () {
        reiniciarVista();
        modalXml.show();
    });

    $('#btnElegirArchivosXml').on('click', function () { $('#xmlFilesInput').trigger('click'); });
    $('#xmlFilesInput').on('change', function () { prepararArchivos(this.files, 'Archivos seleccionados'); });


    $('#btnIniciarCargaXml').on('click', iniciarCarga);

    $('#btnCancelarCargaXml').on('click', function () {
        if (!cargando) return;
        cancelarSolicitado = true;
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Cancelando...');
        if (peticionActual) peticionActual.abort();
    });

    $('#btnCambiarSeleccionXml').on('click', function () {
        reiniciarVista();
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (!cargando) reiniciarVista();
        $('#btnCancelarCargaXml').html('<i class="bi bi-x-circle"></i> Cancelar carga');
    });
});
</script>
