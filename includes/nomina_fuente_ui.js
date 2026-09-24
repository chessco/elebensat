(function () {
  'use strict';
  let modal, selected = 0, page = 1, pages = 1, sequence = 0, pending = [];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const url = action => 'ajax/nomina_reporte_completo.php?accion=' + action + '&id_importacion=' + encodeURIComponent(selected);
  function ensureModal() {
    if (modal && document.body.contains(modal)) return;
    document.getElementById('modalNominaFuente')?.remove();
    modal = document.createElement('div');
    modal.id = 'modalNominaFuente'; modal.className = 'modal fade'; modal.tabIndex = -1;
    modal.innerHTML = `<div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:95vw"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Reporte original completo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body"><div class="nf-info small mb-2"></div><div class="nf-message small mb-2" role="status"></div><div class="nf-pending small mb-2"></div>
      <div class="table-responsive" style="max-height:58vh"><table class="table table-sm table-bordered" style="font-size:.75rem;white-space:nowrap"><thead></thead><tbody></tbody></table></div>
      <div class="small text-muted mt-2">Valores originales, sin sumar renglones adicionales. Vacío y cero se conservan distintos. Esta consulta todavía no es una conciliación por concepto.</div></div>
      <div class="modal-footer"><button class="btn btn-outline-secondary nf-prev">Anterior</button><span class="nf-page small"></span><button class="btn btn-outline-secondary nf-next">Siguiente</button>
      <button class="btn btn-warning nf-register" hidden>Dar de alta campos nuevos</button><button class="btn btn-success nf-download">Descargar original</button><button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('.nf-prev').onclick = () => load(page - 1);
    modal.querySelector('.nf-next').onclick = () => load(page + 1);
    modal.querySelector('.nf-download').onclick = download;
    modal.querySelector('.nf-register').onclick = registerFields;
    modal.addEventListener('hidden.bs.modal', () => { sequence++; });
  }
  async function responseJson(response) {
    let data; try { data = await response.json(); } catch (_) { throw new Error('El servidor no devolvió una respuesta válida.'); }
    if (!response.ok || !data.success) throw new Error(data.error || 'No se pudo leer el reporte completo.');
    return data;
  }
  async function load(next) {
    const seq = ++sequence;
    for (const b of modal.querySelectorAll('.nf-prev,.nf-next,.nf-download,.nf-register')) b.disabled = true;
    pending = []; modal.querySelector('.nf-pending').innerHTML = ''; modal.querySelector('.nf-register').hidden = true;
    modal.querySelector('.nf-message').textContent = 'Leyendo columnas y renglones guardados…';
    modal.querySelector('tbody').innerHTML = '';
    try {
      const data = await responseJson(await fetch(url('ver') + '&pagina=' + Math.max(1,next), {credentials:'same-origin'}));
      if (seq !== sequence) return;
      page = data.pagina; pages = data.paginas;
      pending = data.pendientes || [];
      modal.querySelector('.nf-pending').innerHTML = pending.length ? '<div class="alert alert-warning mb-0"><b>Campos pendientes de alta o revisión: no habilitados para el cruce por campos.</b> El original sí se conservó.<ul class="mb-0">' + pending.map(f => `<li>${esc(f.columna)} · ${esc(f.encabezado || '(sin encabezado)')}: ${esc(f.motivo)}</li>`).join('') + '</ul></div>' : '';
      modal.querySelector('.nf-register').hidden = !pending.some(f => f.id_campo > 0 && f.estado === 'PENDIENTE');
      modal.querySelector('.nf-register').disabled = false;
      modal.querySelector('.nf-info').textContent = `${data.archivo} · Hoja: ${data.hoja} · ${data.total_columnas} columnas · ${data.total_filas} filas (incluye encabezado y metadatos)`;
      modal.querySelector('thead').innerHTML = '<tr><th>Renglón Excel</th><th>Tipo de renglón</th><th>Empleado vinculado</th>' + data.columnas.map(c => `<th>${esc(c.letra)} · ${esc(c.encabezado)}</th>`).join('') + '</tr>';
      modal.querySelector('tbody').innerHTML = data.filas.map(r => `<tr><td>${esc(r.renglon)}</td><td>${esc(r.clase)}</td><td>${esc(r.numero_empleado)}</td>${r.valores.map(v => `<td>${esc(v)}</td>`).join('')}</tr>`).join('');
      modal.querySelector('.nf-page').textContent = `Página ${page} de ${pages}`;
      modal.querySelector('.nf-message').textContent = 'Reporte recuperado. Las columnas mantienen el orden del archivo original.';
      modal.querySelector('.nf-prev').disabled = page <= 1;
      modal.querySelector('.nf-next').disabled = page >= pages;
      modal.querySelector('.nf-download').disabled = false;
    } catch (e) {
      if (seq === sequence) modal.querySelector('.nf-message').textContent = e.message;
    }
  }
  async function registerFields() {
    const seq = sequence;
    const unique = [...new Map(pending.filter(f => f.id_campo > 0 && f.estado === 'PENDIENTE').map(f => [f.id_campo,f])).values()];
    const decision = await Swal.fire({title:'Alta de campos de nómina',width:750,
      html:'<div class="text-start small">Dar de alta permite reconocer el campo en las próximas lecturas. Su equivalencia XML se definirá aparte.</div><div style="max-height:45vh;overflow:auto"><table class="table table-sm text-start"><thead><tr><th>Alta</th><th>Campo</th><th>Tipo</th></tr></thead><tbody>' + unique.map(f => `<tr><td><input type="checkbox" class="nf-check" data-id="${Number(f.id_campo)}"></td><td>${esc(f.encabezado)}</td><td><select id="nfType${Number(f.id_campo)}" class="form-select form-select-sm"><option value="TEXTO">Texto / código</option><option value="NUMERO">Número / importe</option></select></td></tr>`).join('') + '</tbody></table></div>',
      showCancelButton:true,confirmButtonText:'Dar de alta seleccionados',cancelButtonText:'Cancelar',
      preConfirm:() => {
        const fields = [...Swal.getPopup().querySelectorAll('.nf-check:checked')].map(c => ({id_campo:Number(c.dataset.id),tipo:Swal.getPopup().querySelector('#nfType'+c.dataset.id).value}));
        if (!fields.length) {Swal.showValidationMessage('Seleccione al menos un campo.');return false;}
        return fields;
      }});
    if (!decision.isConfirmed || seq !== sequence) return;
    try {
      const data = await responseJson(await fetch('ajax/nomina_campos_alta.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:window.nominaCamposCsrf||'',campos:JSON.stringify(decision.value)})}));
      if (seq !== sequence) return;
      await Swal.fire('Campos registrados',data.message,'success');
      if (seq === sequence) load(page);
    } catch(e) { await Swal.fire('No se pudo guardar',e.message,'error'); }
  }
  async function download() {
    const seq = sequence, id = selected, button = modal.querySelector('.nf-download');
    button.disabled = true; modal.querySelector('.nf-message').textContent = 'Recuperando y verificando el archivo original…';
    try {
      const response = await fetch(url('descargar'), {credentials:'same-origin'});
      if (!response.ok) { await responseJson(response); }
      if (!(response.headers.get('content-disposition') || '').includes('attachment')) throw new Error('El servidor no devolvió un archivo descargable.');
      const blob = await response.blob();
      if (seq !== sequence) return;
      const name = /filename="([^"]+)"/.exec(response.headers.get('content-disposition') || '')?.[1] || `Nomina_original_${id}.xlsx`;
      const link = document.createElement('a'), objectUrl = URL.createObjectURL(blob);
      link.href = objectUrl; link.download = name; document.body.appendChild(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      modal.querySelector('.nf-message').textContent = 'Original verificado; descarga solicitada al navegador. Revisa Descargas (Ctrl+J).';
    } catch (e) { if (seq === sequence) modal.querySelector('.nf-message').textContent = e.message; }
    finally { if (seq === sequence) button.disabled = false; }
  }
  window.verNominaFuente = function (id) {
    selected = Number(id); if (!Number.isSafeInteger(selected) || selected <= 0) return;
    ensureModal(); modal.querySelector('.nf-info').textContent = ''; modal.querySelector('thead').innerHTML = ''; modal.querySelector('.nf-page').textContent = '';
    bootstrap.Modal.getOrCreateInstance(modal,{focus:false}).show(); load(1);
  };
})();
