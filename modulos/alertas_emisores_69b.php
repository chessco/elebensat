<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_alertas_69b'); } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; exit; }
?>
<style>
.a69-panel{background:#fff;border:1px solid #c9d8e5;border-radius:10px;padding:16px;color:#243746}.a69-cards{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:14px}.a69-card{border:1px solid #d7e2ea;border-radius:9px;padding:12px;background:#f8fbfd}.a69-card .n{font-size:1.55rem;font-weight:800;line-height:1}.a69-card .t{font-size:.73rem;color:#61788a;margin-top:5px}.a69-card.riesgo .n{color:#dc3545}.a69-card.nueva .n{color:#fd7e14}.a69-card.vista .n{color:#0d6efd}.a69-card.atendida .n{color:#198754}.a69-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center}.a69-toolbar .form-select{width:190px}.tabla-a69 th{background:#e9f2f8!important;color:#123f5d!important;white-space:nowrap;font-size:.76rem}.tabla-a69 td{font-size:.76rem;vertical-align:middle}.a69-sit{display:inline-block;margin:1px 2px;padding:3px 6px;border-radius:5px;font-size:.68rem;font-weight:700}.a69-uuid{font-family:Consolas,monospace;font-size:.7rem;word-break:break-all}@media(max-width:850px){.a69-cards{grid-template-columns:1fr 1fr}.a69-toolbar .form-select{width:100%}}
</style>
<div class="a69-panel">
 <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h5 class="mb-1"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Emisores con alertas 69-B</h5><div class="text-muted small">Cruce de los RFC del catálogo de emisores contra el listado oficial del SAT. La vista inicia mostrando emisores con situación Presunto o Definitivo.</div></div>
  <button class="btn btn-danger btn-sm" id="btnCruzar69B"><i class="bi bi-arrow-repeat"></i> Cruzar emisores ahora</button>
 </div>
 <div class="a69-cards">
  <div class="a69-card riesgo"><div class="n" id="a69Riesgo">0</div><div class="t">EMISORES A REVISAR</div></div>
  <div class="a69-card nueva"><div class="n" id="a69Nuevas">0</div><div class="t">NUEVAS</div></div>
  <div class="a69-card vista"><div class="n" id="a69Vistas">0</div><div class="t">EN REVISIÓN</div></div>
  <div class="a69-card atendida"><div class="n" id="a69Atendidas">0</div><div class="t">ATENDIDAS</div></div>
 </div>
 <div class="a69-toolbar">
  <select id="filtroSituacionA69" class="form-select form-select-sm"><option value="">Todas las situaciones</option><option>Definitivo</option><option>Presunto</option><option>Desvirtuado</option><option>Sentencia Favorable</option></select>
  <select id="filtroSeguimientoA69" class="form-select form-select-sm"><option value="">Todos los seguimientos</option><option value="NUEVA">Nuevas</option><option value="VISTA">En revisión</option><option value="ATENDIDA">Atendidas</option></select>
  <div class="form-check ms-1"><input class="form-check-input" type="checkbox" id="soloRiesgoA69" checked><label class="form-check-label small" for="soloRiesgoA69">Solo Presunto / Definitivo</label></div>
  <button type="button" class="btn btn-success btn-sm ms-auto" id="btnExportarA69B"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</button>
 </div>
 <div class="table-responsive"><table id="tablaAlertas69B" class="table table-striped table-hover table-bordered tabla-a69 w-100"><thead><tr><th>SEGUIMIENTO</th><th>EMPRESA</th><th>RFC</th><th>EMISOR</th><th>SITUACIÓN SAT</th><th>CFDI</th><th>IMPORTE CFDI</th><th>DETECTADO</th><th>ACCIONES</th></tr></thead></table></div>
</div>
<script>
(function(){
 const esc=s=>$('<div>').text(s??'').html();
 const badgeSeg=v=>v==='NUEVA'?'<span class="badge bg-danger">NUEVA</span>':(v==='VISTA'?'<span class="badge bg-primary">EN REVISIÓN</span>':'<span class="badge bg-success">ATENDIDA</span>');
 function badgesSituaciones(v){return String(v||'').split(' | ').filter(Boolean).map(s=>{let c='secondary';if(s==='Definitivo')c='danger';else if(s==='Presunto')c='warning text-dark';else if(s==='Desvirtuado')c='success';else if(s==='Sentencia Favorable')c='info text-dark';return `<span class="a69-sit bg-${c}">${esc(s)}</span>`}).join(' ')}
 const tabla=$('#tablaAlertas69B').DataTable({destroy:true,scrollX:true,pageLength:25,lengthMenu:[[10,25,50,100],[10,25,50,100]],order:[],ajax:{url:'ajax/listar_alertas_emisores_69b.php',data:d=>{d.situacion=$('#filtroSituacionA69').val();d.seguimiento=$('#filtroSeguimientoA69').val();d.solo_riesgo=$('#soloRiesgoA69').is(':checked')?1:0},dataSrc:r=>{if(!r.success){Swal.fire('Error',r.error||'No se pudieron consultar las alertas.','error');return []}const x=r.resumen||{};$('#a69Riesgo').text(x.riesgo||0);$('#a69Nuevas').text(x.nuevas||0);$('#a69Vistas').text(x.vista||0);$('#a69Atendidas').text(x.atendidas||0);return r.data||[]}},columns:[
  {data:'seguimiento',render:badgeSeg},{data:'empresa'},{data:'rfc',className:'fw-bold'},{data:'nombre_emisor'},{data:'situaciones',render:badgesSituaciones},{data:'cfdi',className:'text-end'},{data:'total_cfdi',className:'text-end',render:v=>'$'+Number(v||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})},{data:'primera_deteccion'},{data:null,orderable:false,render:r=>`<button class="btn btn-outline-primary btn-sm btnDetalleA69" title="Ver detalle"><i class="bi bi-eye"></i></button> ${r.seguimiento!=='ATENDIDA'?'<button class="btn btn-success btn-sm btnAtenderA69"><i class="bi bi-check2-circle"></i></button>':'<button class="btn btn-outline-secondary btn-sm btnReabrirA69"><i class="bi bi-arrow-counterclockwise"></i></button>'}`}
 ]});
 function reload(){tabla.ajax.reload(null,false)}
 $('#filtroSituacionA69,#filtroSeguimientoA69,#soloRiesgoA69').on('change',reload);
 $('#btnExportarA69B').on('click',function(){const q=new URLSearchParams({situacion:$('#filtroSituacionA69').val()||'',seguimiento:$('#filtroSeguimientoA69').val()||'',solo_riesgo:$('#soloRiesgoA69').is(':checked')?'1':'0',buscar:tabla.search()||''});window.location.href='ajax/exportar_alertas_emisores_69b_excel.php?'+q.toString();});
 $('#btnCruzar69B').on('click',function(){
 const b=$(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Cruzando...');
 const jobId=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():('sat69b_'+Date.now()+'_'+Math.random().toString(36).slice(2));
 let timer=null,terminado=false,cancelando=false;
 const cerrarTimer=()=>{if(timer){clearInterval(timer);timer=null}};
 const pintar=j=>{
   const p=Math.max(0,Math.min(100,Number(j.porcentaje||0)));
   $('#satCruceBar').css('width',p+'%').attr('aria-valuenow',p).text(p+'%');
   $('#satCruceTexto').text(j.mensaje||'Procesando...');
   $('#satCruceConteo').text((j.procesados||0)+' de '+(j.total||0)+' emisores');
   if(j.estado==='cancelando'){$('#btnCancelarCruceSat').prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Cancelando...')}
 };
 Swal.fire({
   title:'Cruzando emisores · Artículo 69-B',
   html:`<div class="text-start">
          <div id="satCruceTexto" class="small mb-2">Preparando cruce...</div>
          <div class="progress" style="height:24px"><div id="satCruceBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">0%</div></div>
          <div id="satCruceConteo" class="small text-muted mt-2">0 de 0 emisores</div>
          <div class="text-center mt-3"><button type="button" class="btn btn-danger btn-sm" style="display:inline-block!important;visibility:visible!important;opacity:1!important;background:#dc3545!important;color:#fff!important;border:1px solid #dc3545!important;min-width:145px" id="btnCancelarCruceSat"><i class="bi bi-x-circle"></i> Cancelar proceso</button></div>
          <div class="small text-muted mt-2">Si cancela, se revierte el cruce actual y no quedan datos parciales.</div>
         </div>`,
   showConfirmButton:false,allowOutsideClick:false,allowEscapeKey:false,
   didOpen:()=>{
     $('#btnCancelarCruceSat').on('click',function(){
       if(cancelando||terminado)return;
       cancelando=true;
       $(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Cancelando...');
       $('#satCruceTexto').text('Solicitando cancelación segura...');
       $.post('ajax/cancelar_cruce_sat.php',{job_id:jobId},null,'json').fail(x=>{
         cancelando=false;
         $('#btnCancelarCruceSat').prop('disabled',false).html('<i class="bi bi-x-circle"></i> Cancelar proceso');
         Swal.showValidationMessage((x.responseJSON&&x.responseJSON.error)||'No se pudo solicitar la cancelación.');
       });
     });
     timer=setInterval(()=>{$.getJSON('ajax/estado_cruce_sat.php',{job_id:jobId},r=>{if(r.success&&r.job)pintar(r.job)})},500);
   }
 });
 $.post('ajax/sincronizar_alertas_69b.php',{job_id:jobId},r=>{
   terminado=true;cerrarTimer();Swal.close();
   if(r.success&&r.cancelled){
     Swal.fire('Cruce cancelado','No se guardaron cambios parciales.','info');
   }else if(r.success){
     Swal.fire('Cruce terminado',`Emisores coincidentes: <b>${r.resumen.total_emisores||0}</b><br>Con Presunto/Definitivo: <b>${r.resumen.emisores_riesgo||0}</b>`,'success');
     reload();
   }else Swal.fire('Error',r.error||'No se pudo cruzar.','error');
 },'json').fail(x=>{
   terminado=true;cerrarTimer();Swal.close();
   Swal.fire('Error',(x.responseJSON&&x.responseJSON.error)||'No se pudo cruzar.','error');
 }).always(()=>b.prop('disabled',false).html('<i class="bi bi-arrow-repeat"></i> Cruzar emisores ahora'));
});
 $('#tablaAlertas69B').on('click','.btnDetalleA69',function(){const r=tabla.row($(this).closest('tr')).data();if(!r)return;$.post('ajax/actualizar_alerta_emisor_69b.php',{id_emisor:r.id_emisor,accion:'vista'},()=>{},'json');$.getJSON('ajax/detalle_alerta_emisor_69b.php',{id_emisor:r.id_emisor},d=>{if(!d.success){Swal.fire('Error',d.error||'No se pudo leer detalle.','error');return}const sat=(d.sat||[]).map(x=>`<tr><td>${badgesSituaciones(x.situacion)}</td><td>${esc(x.fecha_publicacion_relevante||'')}</td><td>${esc(x.nombre_sat||'')}</td></tr>`).join('');const cfdi=(d.cfdi||[]).map(x=>`<tr><td class="a69-uuid">${esc(x.uuid)}</td><td>${esc([x.serie,x.folio].filter(Boolean).join('-'))}</td><td>${esc(x.fecha_emision||'')}</td><td class="text-end">$${Number(x.total_xml||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>${esc(x.estatus_sat||'')}</td></tr>`).join('');Swal.fire({title:esc(d.emisor.nombre_emisor),width:'92%',html:`<div class="text-start"><div class="mb-2"><b>Empresa:</b> ${esc(d.emisor.empresa)} &nbsp; <b>RFC:</b> ${esc(d.emisor.rfc)}</div><h6>Registros encontrados en 69-B</h6><div class="table-responsive" style="max-height:180px"><table class="table table-sm table-bordered"><thead><tr><th>Situación</th><th>Publicación</th><th>Nombre SAT</th></tr></thead><tbody>${sat||'<tr><td colspan="3">Sin detalle</td></tr>'}</tbody></table></div><h6 class="mt-3">CFDI relacionados (${(d.cfdi||[]).length})</h6><div class="table-responsive" style="max-height:360px;overflow:auto"><table class="table table-sm table-striped table-bordered"><thead><tr><th>UUID</th><th>Factura</th><th>Fecha</th><th>Total</th><th>Estatus SAT</th></tr></thead><tbody>${cfdi||'<tr><td colspan="5">Sin CFDI</td></tr>'}</tbody></table></div></div>`,confirmButtonText:'Cerrar'}).then(reload)})});
 $('#tablaAlertas69B').on('click','.btnAtenderA69',function(){const r=tabla.row($(this).closest('tr')).data();Swal.fire({title:'Marcar emisor como atendido',input:'textarea',inputLabel:'Observaciones / acción realizada',showCancelButton:true,confirmButtonText:'Marcar atendido'}).then(x=>{if(!x.isConfirmed)return;$.post('ajax/actualizar_alerta_emisor_69b.php',{id_emisor:r.id_emisor,accion:'atendida',observaciones:x.value||''},z=>{if(z.success){Swal.fire('Atendido','Quedó registrado en el historial.','success');reload()}else Swal.fire('Error',z.error||'No se pudo actualizar.','error')},'json')})});
 $('#tablaAlertas69B').on('click','.btnReabrirA69',function(){const r=tabla.row($(this).closest('tr')).data();$.post('ajax/actualizar_alerta_emisor_69b.php',{id_emisor:r.id_emisor,accion:'reabrir'},z=>{if(z.success)reload();else Swal.fire('Error',z.error||'No se pudo reabrir.','error')},'json')});
})();
</script>
