(function(){
 'use strict';
 const host=document.getElementById('nomina-conceptos');if(!host)return;
 if(window.__nominaConceptosDispose)window.__nominaConceptosDispose();
 let disposed=false;
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const money=v=>v===null||v===''?'—':typeof v==='number'?v.toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}):esc(v);
 let token='',block=0,running=false,cancel=false,selected=-1,page=1,pages=1,detailSeq=0,startTime=0,timer=null;
 host.innerHTML=`<div class="nomina-card mb-3"><div class="fw-bold mb-2">Conciliación por campos · Workbeat vs XML</div><p class="small text-muted">Informe adicional. La conciliación general por importe sigue en su pestaña, sin cambios. Selecciona <b>una importación completa</b>; se usa su última versión guardada y su rango de fechas. No se suman semanales con acumulados.</p><div class="d-flex gap-2 flex-wrap"><select id="nccFuente" class="form-select form-select-sm" style="max-width:760px"><option value="">Cargando importaciones…</option></select><button id="nccRecargar" class="btn btn-sm btn-outline-secondary">Actualizar lista</button><button id="nccIniciar" class="btn btn-sm btn-success">CONCILIAR CAMPOS</button></div><div id="nccError" class="text-danger small mt-2"></div></div><div id="nccResultado" class="nomina-card" hidden><div class="d-flex justify-content-between align-items-center"><b>Resumen por campo</b><button class="btn btn-sm btn-success nccDescargar">DESCARGAR EXCEL COMPLETO</button></div><div id="nccScope" class="small my-2"></div><div id="nccAvisos"></div><div class="small mb-2">Diferencia = XML − Workbeat. No sumar columnas de conceptos distintos: hay bases e importes repetidos. <b>Haz clic en un campo para revisar empleados.</b></div><div style="overflow:auto;max-height:44vh"><table class="table table-sm table-striped nomina-tabla"><thead><tr><th>Col.</th><th>Campo</th><th>Workbeat</th><th>XML (puede ser parcial)</th><th>Diferencia</th><th>Coincide</th><th>Diferencias</th><th>Sin movimiento</th><th>Pendientes / incidencias</th></tr></thead><tbody id="nccCampos"></tbody></table></div><hr><div class="d-flex gap-2 flex-wrap align-items-center mb-2"><b id="nccCampoTitulo">Todos los campos</b><button id="nccTodos" class="btn btn-sm btn-outline-primary">Ver todos</button><select id="nccFiltro" class="form-select form-select-sm" style="max-width:220px"><option value="">Todos los resultados</option><option value="revisar">Sólo por revisar</option></select><input id="nccBuscar" class="form-control form-control-sm" style="max-width:260px" placeholder="RFC, empleado o campo"><button id="nccBuscarBtn" class="btn btn-sm btn-primary">Buscar</button></div><div style="overflow:auto;max-height:45vh"><table class="table table-sm table-striped nomina-tabla"><thead><tr><th>RFC</th><th>Empleado</th><th>Renglones</th><th>Campo</th><th>Workbeat</th><th>XML</th><th>Diferencia</th><th>Estado</th><th>Regla / observación</th></tr></thead><tbody id="nccDetalle"></tbody></table></div><div class="d-flex gap-2 align-items-center"><button id="nccPrev" class="btn btn-sm btn-outline-secondary">Anterior</button><span id="nccPagina"></span><button id="nccNext" class="btn btn-sm btn-outline-secondary">Siguiente</button></div><div id="nccDescargaEstado" class="small mt-2"></div></div>`;
 const old=document.getElementById('nccModal');if(old)old.remove();
 document.body.insertAdjacentHTML('beforeend',`<div class="modal fade" id="nccModal" tabindex="-1" aria-labelledby="nccModalTitle" aria-modal="true" role="dialog"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 id="nccModalTitle" class="modal-title">Conciliación por campos — procesamiento</h5></div><div class="modal-body"><div id="nccModalScope" class="small text-muted mb-3"></div><b id="nccEstado">Preparando…</b><div class="progress my-3" style="height:24px"><div id="nccBarra" class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%">0%</div></div><div id="nccContador">0 CFDI procesados</div><div id="nccTiempo" class="small mt-2">Tiempo: 00:00</div><div id="nccMensaje" class="alert alert-info mt-3">Se conserva la conciliación general. Esta ventana permanecerá abierta.</div><button id="nccModalDescargar" class="btn btn-success nccDescargar w-100" hidden>DESCARGAR EXCEL COMPLETO</button><button id="nccReintentar" class="btn btn-outline-primary mt-2" hidden>Reintentar bloque</button></div><div class="modal-footer"><span class="small text-muted me-auto">El Excel y la consulta estarán disponibles durante 24 horas.</span><button id="nccCancelar" class="btn btn-outline-danger">Cancelar proceso</button><button id="nccCerrar" class="btn btn-secondary" disabled>Cerrar / ver informe</button></div></div></div></div>`);
 const el=id=>document.getElementById(id);const modal=new bootstrap.Modal(el('nccModal'),{backdrop:'static',keyboard:false});
 window.__nominaConceptosDispose=()=>{disposed=true;running=false;clearInterval(timer);modal.hide();modal.dispose?.();};
 async function api(action,data={},post=false){
  const params=new URLSearchParams({accion:action,...data});if(post)params.set('csrf',window.nominaCamposCsrf||'');
  const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),150000);
  try{
   const r=await fetch('ajax/nomina_conceptos.php'+(post?'':'?'+params),{method:post?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json',...(post?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:post?params:undefined,signal:controller.signal});
   if(disposed)throw Error('La pantalla cambió.');
   let j;try{j=await r.json();}catch(e){throw Error('El servidor no devolvió una respuesta válida. Revisa la sesión o el registro de PHP.');}
   if(!r.ok||!j.success)throw Error(j.error||j.msg||'No se pudo completar la solicitud.');return j;
  }finally{clearTimeout(timeout);}
 }
 async function sources(id){
  try{el('nccError').textContent='';const r=await api('listar');el('nccFuente').innerHTML='<option value="">Selecciona una importación completa</option>'+r.fuentes.map(x=>`<option value="${Number(x.id_importacion)}">#${Number(x.id_importacion)} · ${esc(x.fecha_desde)} a ${esc(x.fecha_hasta)} · ${esc(x.tipo_nomina)} · ${esc(x.nombre_archivo)}</option>`).join('');if(id)el('nccFuente').value=String(id);}
  catch(e){if(disposed)return;el('nccError').textContent=e.message;el('nccFuente').innerHTML='<option value="">No se pudo cargar la lista</option>';}
 }
 function stop(){running=false;clearInterval(timer);timer=null;el('nccIniciar').disabled=false;el('nccCerrar').disabled=false;el('nccCancelar').hidden=true;}
 async function run(){
  if(!timer)timer=setInterval(()=>{const s=Math.floor((Date.now()-startTime)/1000);el('nccTiempo').textContent='Tiempo: '+String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');},1000);
  running=true;cancel=false;el('nccIniciar').disabled=true;el('nccCerrar').disabled=true;el('nccCancelar').hidden=false;el('nccCancelar').disabled=false;el('nccReintentar').hidden=true;
  try{
   while(running){
    if(cancel){await api('cancelar',{token},true);stop();el('nccEstado').textContent='Proceso cancelado';el('nccMensaje').textContent='No se modificaron la importación ni la conciliación general.';return;}
    const r=await api('procesar',{token,bloque:block},true);block=r.bloque;
    el('nccBarra').style.width=r.porcentaje+'%';el('nccBarra').textContent=r.porcentaje+'%';el('nccBarra').setAttribute('aria-valuenow',r.porcentaje);
    el('nccContador').textContent=Number(r.procesados).toLocaleString()+' de '+Number(r.total).toLocaleString()+' CFDI procesados';
    el('nccEstado').textContent=r.procesados===r.total?'Preparando resumen y Excel…':'Revisando XML y conceptos…';
    if(r.completo){await summary();stop();el('nccEstado').textContent='Informe terminado — revisa también los pendientes';el('nccMensaje').className='alert alert-success mt-3';el('nccMensaje').textContent='El Excel está listo. Puedes descargarlo aquí y cerrar esta ventana para consultar el resumen.';el('nccModalDescargar').hidden=false;return;}
   }
  }catch(e){if(disposed)return;stop();el('nccEstado').textContent='No se pudo completar el informe';el('nccMensaje').className='alert alert-danger mt-3';el('nccMensaje').textContent=e.name==='AbortError'?'La respuesta tardó demasiado. Puedes reintentar el mismo bloque sin duplicar importes.':e.message;el('nccReintentar').hidden=!token;}
 }
 async function start(){
  if(running)return;const id=el('nccFuente').value;if(!id){el('nccError').textContent='Selecciona una importación.';return;}
  token='';block=0;selected=-1;page=1;detailSeq++;el('nccResultado').hidden=true;el('nccModalDescargar').hidden=true;el('nccReintentar').hidden=true;el('nccBarra').style.width='0%';el('nccBarra').textContent='0%';el('nccBarra').setAttribute('aria-valuenow',0);el('nccContador').textContent='Preparando selección…';el('nccEstado').textContent='Preparando fuente y selección de XML…';el('nccModalScope').textContent=el('nccFuente').selectedOptions[0].textContent;el('nccMensaje').className='alert alert-info mt-3';el('nccMensaje').textContent='Se revisa la fuente completa seleccionada; los campos sin regla quedarán pendientes.';el('nccCerrar').disabled=true;el('nccIniciar').disabled=true;el('nccCancelar').hidden=false;el('nccCancelar').disabled=true;modal.show();
  startTime=Date.now();clearInterval(timer);timer=setInterval(()=>{const s=Math.floor((Date.now()-startTime)/1000);el('nccTiempo').textContent='Tiempo: '+String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');},1000);
  try{const r=await api('iniciar',{id_importacion:id},true);token=r.token;el('nccModalScope').textContent=r.archivo+' · '+r.scope.desde+' a '+r.scope.hasta;await run();}
  catch(e){if(disposed)return;stop();el('nccEstado').textContent='No se pudo iniciar';el('nccMensaje').className='alert alert-danger mt-3';el('nccMensaje').textContent=e.message;}
 }
 async function summary(){
  const r=(await api('resumen',{token})).resumen;el('nccResultado').hidden=false;el('nccScope').textContent=r.archivo+' · Fuente #'+r.fuente+' · '+r.scope.desde+' a '+r.scope.hasta+' · '+r.comparaciones.toLocaleString()+' comparaciones · '+(r.cfdi_estados.INCLUIDO||0)+' CFDI incluidos';
  el('nccAvisos').innerHTML=r.avisos.map(x=>`<div class="alert alert-warning py-2 small">${esc(x)}</div>`).join('');
  el('nccCampos').innerHTML=r.columnas.map(c=>{const s=c.estados;return `<tr style="cursor:pointer" data-indice="${c.indice}" title="${esc(c.regla)}"><td>${esc(c.columna)}</td><td>${esc(c.campo)}</td><td>${money(c.workbeat)}</td><td>${money(c.xml)}</td><td>${money(c.diferencia)}</td><td>${s.COINCIDE||0}</td><td class="text-danger">${s.DIFERENCIA||0}</td><td>${s.SIN_MOVIMIENTO||0}</td><td>${Object.entries(s).filter(([k])=>!['COINCIDE','DIFERENCIA','SIN_MOVIMIENTO'].includes(k)).map(([k,v])=>esc(k)+': '+v).join('; ')}</td></tr>`;}).join('');
  await detail();
 }
 async function detail(){
  const seq=++detailSeq;el('nccDetalle').innerHTML='<tr><td colspan="9">Cargando…</td></tr>';
  try{const r=await api('detalle',{token,indice:selected,pagina:page,filtro:el('nccFiltro').value,buscar:el('nccBuscar').value});if(seq!==detailSeq)return;pages=r.paginas;el('nccDetalle').innerHTML=r.filas.map(d=>`<tr><td>${esc(d.rfc)}</td><td>${esc(d.empleado)}</td><td>${esc(d.renglones)}</td><td>${esc(d.campo)}</td><td>${money(d.workbeat)}</td><td>${money(d.xml)}</td><td>${money(d.diferencia)}</td><td>${esc(d.estado)}</td><td>${esc(d.nota)}</td></tr>`).join('')||'<tr><td colspan="9">Sin resultados</td></tr>';el('nccPagina').textContent='Página '+page+' de '+pages+' · '+r.total.toLocaleString()+' registros';el('nccPrev').disabled=page<=1;el('nccNext').disabled=page>=pages;}
  catch(e){if(disposed)return;if(seq===detailSeq)el('nccDetalle').innerHTML='<tr><td colspan="9" class="text-danger">'+esc(e.message)+'</td></tr>';}
 }
 async function download(){
  const buttons=document.querySelectorAll('.nccDescargar');buttons.forEach(b=>b.disabled=true);
  try{el('nccDescargaEstado').textContent='Preparando descarga…';const r=await fetch('ajax/nomina_conceptos.php?accion=descargar&token='+encodeURIComponent(token),{credentials:'same-origin',headers:{Accept:'application/json'}});if(!r.ok||!String(r.headers.get('content-disposition')).includes('attachment')){let j={};try{j=await r.json();}catch(e){}throw Error(j.error||'No se pudo descargar el Excel.');}
   const blob=await r.blob();if(disposed)return;if(blob.size<100)throw Error('La descarga llegó vacía.');const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=(r.headers.get('content-disposition').match(/filename="([^"]+)"/)||[])[1]||'Conciliacion_campos_nomina.xlsx';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);const msg='Descarga solicitada al navegador. Revisa Descargas (Ctrl+J); esta ventana no se cierra sola.';el('nccDescargaEstado').textContent=msg;el('nccMensaje').textContent=msg;
  }catch(e){if(disposed)return;el('nccDescargaEstado').textContent=e.message;el('nccMensaje').className='alert alert-danger mt-3';el('nccMensaje').textContent=e.message;}finally{buttons.forEach(b=>b.disabled=false);}
 }
 el('nccIniciar').onclick=start;el('nccRecargar').onclick=()=>sources();el('nccReintentar').onclick=run;
 el('nccCancelar').onclick=()=>{cancel=true;el('nccCancelar').disabled=true;el('nccMensaje').textContent='Cancelando al terminar el bloque actual…';};
 el('nccCerrar').onclick=()=>modal.hide();document.querySelectorAll('.nccDescargar').forEach(b=>b.onclick=download);
 el('nccCampos').onclick=e=>{const row=e.target.closest('tr[data-indice]');if(row){selected=Number(row.dataset.indice);page=1;el('nccCampoTitulo').textContent=row.children[1].textContent;detail();}};
 el('nccTodos').onclick=()=>{selected=-1;page=1;el('nccCampoTitulo').textContent='Todos los campos';detail();};
 el('nccBuscarBtn').onclick=el('nccFiltro').onchange=()=>{page=1;detail();};el('nccBuscar').onkeydown=e=>{if(e.key==='Enter'){page=1;detail();}};
 el('nccPrev').onclick=()=>{if(page>1){page--;detail();}};el('nccNext').onclick=()=>{if(page<pages){page++;detail();}};
 window.abrirConciliacionCampos=async id=>{bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#nomina-conceptos"]')).show();await sources(id);};
 document.querySelector('[data-bs-target="#nomina-conceptos"]').addEventListener('shown.bs.tab',()=>{if(el('nccFuente').options.length<=1)sources();});
})();
