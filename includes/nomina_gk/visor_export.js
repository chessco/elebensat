(function () {
    'use strict';
    const state = window.__nominaGkModal = window.__nominaGkModal || {busy: false};
    window.exportarNominaGkExcel = async function (filtrosAplicados, csrf) {
        if (state.busy) { state.modal?.show(); return; }
        const old = document.getElementById('rngModal');
        if (old) { bootstrap.Modal.getInstance(old)?.dispose(); old.remove(); }
        const el = document.createElement('div');
        el.id = 'rngModal'; el.className = 'modal'; el.tabIndex = -1;
        el.setAttribute('aria-labelledby', 'rngTitulo');
        el.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="background:#fff;color:#182b3a">
                <div class="modal-header" style="background:#12364b;color:#fff">
                    <h5 class="modal-title" id="rngTitulo">Detalle de nómina GK — procesamiento</h5>
                </div>
                <div class="modal-body p-4">
                    <div id="rngRango" class="small text-muted mb-3"></div>
                    <div id="rngEstado" class="fw-bold mb-2" role="status" aria-live="polite">Preparando la selección…</div>
                    <div class="progress mb-3" style="height:30px;background:#e6edf2">
                        <div id="rngBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%">0%</div>
                    </div>
                    <div class="row g-3 text-center mb-3">
                        <div class="col-4"><div class="small text-muted">CFDI procesados</div><strong id="rngProcesados" class="fs-4">0</strong></div>
                        <div class="col-4"><div class="small text-muted">CFDI seleccionados</div><strong id="rngTotal" class="fs-4">—</strong></div>
                        <div class="col-4"><div class="small text-muted">Renglones generados</div><strong id="rngRenglones" class="fs-4">0</strong></div>
                    </div>
                    <div id="rngTiempo" class="small text-muted mb-2">Tiempo transcurrido: 00:00</div>
                    <div id="rngEspera" class="small text-muted mb-3">Esperando respuesta del servidor. Esta ventana permanecerá abierta.</div>
                    <div id="rngMensaje" class="alert d-none" role="alert" style="white-space:pre-wrap;overflow-wrap:anywhere"></div>
                    <div id="rngDescargas" class="d-none">
                        <div class="d-grid gap-2">
                            <a id="rngDetalle" class="btn btn-success" target="_blank" rel="noopener">Descargar detalle de nómina — 43 columnas</a>
                            <a id="rngResumen" class="btn btn-outline-primary" target="_blank" rel="noopener">Descargar Excel de campos faltantes</a>
                        </div>
                        <div id="rngDescargaEstado" class="small mt-2" role="status" aria-live="polite">Los archivos están generados; todavía no has solicitado su descarga.</div>
                        <p class="small text-muted mt-3 mb-0">Total y Neto se repiten por concepto. No sumes esas columnas sin agrupar por UUID. Revisa también el resumen de campos faltantes.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="small text-muted me-auto" id="rngPie">No cierres ni recargues la página durante el proceso.</span>
                    <button id="rngCancelar" type="button" class="btn btn-outline-danger">Cancelar proceso</button>
                    <button id="rngCerrar" type="button" class="btn btn-secondary" disabled>Cerrar</button>
                </div>
            </div></div>`;
        document.body.appendChild(el);
        const q = id => el.querySelector('#' + id);
        const text = (id, value) => { q(id).textContent = value; };
        state.busy = true;
        let token = '', cancelar = false, bloque = 0, heartbeat, requestSince = Date.now();
        const start = Date.now();
        const modal = new bootstrap.Modal(el, {backdrop: 'static', keyboard: false, focus: true});
        state.modal = modal;
        // Independiente de SweetAlert: sin timer y sin cierre automático.
        el.addEventListener('hide.bs.modal', event => { if (state.busy) event.preventDefault(); });
        q('rngCerrar').addEventListener('click', () => { if (!state.busy) modal.hide(); });
        for (const [id, nombre] of [['rngDetalle', 'detalle de nómina'], ['rngResumen', 'resumen de campos faltantes']]) {
            q(id).addEventListener('click', () => {
                text('rngDescargaEstado', `Descarga de ${nombre} solicitada al navegador. Revisa Descargas (Ctrl+J); esta ventana permanecerá abierta.`);
            });
        }
        q('rngCancelar').addEventListener('click', () => {
            cancelar = true; q('rngCancelar').disabled = true;
            text('rngEstado', 'Cancelación solicitada');
            text('rngEspera', 'Esperando que termine la petición en curso para cancelar de forma segura…');
        });
        const unload = event => { if (state.busy) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', unload);
        const mensaje = (value, kind) => { q('rngMensaje').className = 'alert alert-' + kind; text('rngMensaje', value); };
        const finish = () => {
            state.busy = false; clearInterval(heartbeat); window.removeEventListener('beforeunload', unload);
            q('rngBar').classList.remove('progress-bar-animated');
            q('rngCancelar').classList.add('d-none'); q('rngCerrar').disabled = false;
            text('rngEspera', ''); text('rngPie', 'Esta ventana se cierra únicamente con el botón Cerrar.');
        };
        const endpoint = 'ajax/nomina_gk_excel.php';
        const enviar = async (accion, valores = {}) => {
            const fd = new FormData(); fd.set('accion', accion); fd.set('csrf', csrf);
            Object.entries(valores).forEach(([k, v]) => fd.set(k, String(v ?? '')));
            requestSince = Date.now();
            const response = await fetch(endpoint, {method: 'POST', body: fd, cache: 'no-store'});
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); }
            catch (_) { throw new Error(`El servidor respondió sin JSON válido (HTTP ${response.status}). Revisa la sesión y el registro PHP. La operación no está confirmada.`); }
            if (!response.ok || data.status === 'error') throw new Error(data.msg || `Error HTTP ${response.status} al generar la nómina.`);
            return data;
        };
        try {
            const filtros = {};
            ['inicio', 'fin', 'tipo', 'metodo', 'tipo_busqueda', 'busqueda', 'ppd_sin_complemento', 'pue_sin_pago_empresa']
                .forEach(k => { filtros[k] = filtrosAplicados[k] ?? ''; });
            text('rngRango', `Filtros aplicados · Emisión: ${filtros.inicio || 'sin límite inicial'} a ${filtros.fin || 'sin límite final'} · Clasificación: ${filtros.tipo || 'TODOS'}${filtros.busqueda ? ' · Búsqueda: ' + filtros.busqueda : ''}`);
            modal.show();
            heartbeat = setInterval(() => {
                const seconds = Math.floor((Date.now() - start) / 1000);
                text('rngTiempo', `Tiempo transcurrido: ${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`);
                if (!cancelar && state.busy) {
                    const waiting = Math.floor((Date.now() - requestSince) / 1000);
                    text('rngEspera', waiting >= 10 ? `La petición actual lleva ${waiting} segundos esperando respuesta. La ventana sigue abierta; el porcentaje avanza al confirmar datos del servidor.` : 'Procesando. Esta ventana permanecerá abierta.');
                }
            }, 1000);
            // Pintar el modal antes de enviar la primera consulta.
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const inicio = await enviar('iniciar', filtros);
            if (inicio.status === 'sin_datos') {
                text('rngEstado', 'No se encontraron recibos de nómina'); mensaje(inicio.msg, 'warning'); return;
            }
            token = inicio.token; text('rngTotal', Number(inicio.total).toLocaleString('es-MX'));
            while (!cancelar) {
                const avance = await enviar('procesar', {token, bloque}); bloque = avance.bloque;
                if (cancelar) break;
                const pct = Math.max(0, Math.min(100, Number(avance.porcentaje) || 0));
                q('rngBar').style.width = pct + '%'; text('rngBar', pct + '%'); q('rngBar').setAttribute('aria-valuenow', String(pct));
                text('rngProcesados', Number(avance.procesados).toLocaleString('es-MX'));
                text('rngTotal', Number(avance.total).toLocaleString('es-MX'));
                text('rngRenglones', Number(avance.renglones).toLocaleString('es-MX'));
                text('rngEstado', avance.finalizar ? 'Armando los dos archivos Excel…' : 'Leyendo XML y generando renglones de nómina…');
                if (avance.completo) {
                    const url = endpoint + '?accion=descargar&token=' + encodeURIComponent(token);
                    q('rngDetalle').href = url + '&archivo=detalle'; q('rngResumen').href = url + '&archivo=resumen';
                    q('rngDescargas').classList.remove('d-none'); text('rngEstado', 'Reporte terminado — descarga tus archivos');
                    mensaje(avance.incidencias ? `${Number(avance.incidencias)} CFDI requieren revisar el XML o su detalle. Consulta el Excel de campos faltantes.` : 'Los dos Excel están listos. Descárgalos con los botones de abajo.', avance.incidencias ? 'warning' : 'success');
                    return;
                }
            }
            if (token) await enviar('cancelar', {token});
            text('rngEstado', 'Proceso cancelado'); mensaje('Se canceló el reporte y se eliminaron sus temporales.', 'info');
        } catch (error) {
            // El error permanece visible para diagnóstico, sin alerta temporal.
            text('rngEstado', 'No se pudo completar el reporte');
            mensaje(error.message || String(error), 'danger');
        } finally { finish(); }
    };
})();
