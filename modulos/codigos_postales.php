<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales'); } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; exit; }
if (empty($_SESSION['id_usuario']) || empty($_SESSION['es_superadmin'])) { http_response_code(403); exit('Sin permiso'); }
?>
<div class="container-fluid">
 <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div><h4 class="mb-1"><i class="bi bi-mailbox"></i> Códigos postales SAT</h4><small class="text-muted">Catálogo CFDI 4.0, hojas c_CodigoPostal_Parte_1 y Parte_2.</small></div>
  <div class="d-flex gap-2 flex-wrap">
   <button id="btnSincronizarCp" class="btn btn-primary"><i class="bi bi-cloud-download"></i> Descargar y sincronizar SAT</button>
   <button id="btnImportarCp" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Importar Excel manual</button>
   <input id="archivoCp" type="file" accept=".xls" hidden>
  </div>
 </div>
 <div class="alert alert-info py-2"><i class="bi bi-info-circle"></i> La sincronización actualiza o da de alta cada CP de forma masiva. Puede cancelarse de forma segura; si no concluye, se revierte la transacción y se conserva el catálogo anterior.</div>

 <div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Total de códigos</div><div id="cpTotal" class="fs-4 fw-bold">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Frontera norte</div><div id="cpNorte" class="fs-4 fw-bold text-primary">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Frontera sur</div><div id="cpSur" class="fs-4 fw-bold text-success">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">No aplica</div><div id="cpNoAplica" class="fs-4 fw-bold text-secondary">—</div></div></div></div>
 </div>

 <div class="card mb-3 border-0 shadow-sm"><div class="card-body py-2">
  <div class="row align-items-center g-2">
   <div class="col-md-4"><label for="filtroEstimuloCp" class="form-label small text-muted mb-1">Filtrar por estímulo</label><select id="filtroEstimuloCp" class="form-select"><option value="">Todos</option><option value="1">Frontera norte</option><option value="2">Frontera sur</option><option value="0">No aplica</option></select></div>
   <div class="col-md-8 text-md-end"><div class="small text-muted">Versión del catálogo SAT</div><div id="cpVersionCatalogo" class="fw-semibold">Sin información</div></div>
  </div>
 </div></div>

 <div class="card"><div class="card-body">
  <table id="tablaCodigosPostales" class="table table-striped table-hover w-100">
   <thead><tr><th>CP</th><th>Estado</th><th>Municipio</th><th>Localidad</th><th>Estímulo</th><th>Inicio vigencia</th><th>Fin vigencia</th><th>Sincronizado</th></tr></thead>
  </table>
 </div></div>
</div>
<script>
(function(){
 const tabla=$('#tablaCodigosPostales').DataTable({
  processing:true,
  serverSide:true,
  ajax:{
   url:'ajax/listar_codigos_postales.php',
   data:function(d){ d.estimulo=$('#filtroEstimuloCp').val(); },
   dataSrc:function(json){
    const r=json.resumen||{};
    $('#cpTotal').text(Number(r.total||0).toLocaleString());
    $('#cpNorte').text(Number(r.norte||0).toLocaleString());
    $('#cpSur').text(Number(r.sur||0).toLocaleString());
    $('#cpNoAplica').text(Number(r.no_aplica||0).toLocaleString());
    const c=json.catalogo||{};
    let txt='Sin información';
    if(c.revision_catalogo || c.fecha_publicacion){
     txt='Revisión '+(c.revision_catalogo||'N/D');
     if(c.fecha_publicacion) txt+=' · Publicado '+c.fecha_publicacion;
     if(c.fecha_sincronizacion) txt+=' · Sincronizado '+c.fecha_sincronizacion;
    }
    $('#cpVersionCatalogo').text(txt);
    return json.data||[];
   }
  },
  pageLength:25,
  columns:[
  {data:'codigo_postal'},{data:'estado'},{data:'municipio'},{data:'localidad'},
  {data:'estimulo_franja_fronteriza',render:function(v){v=parseInt(v||0);return v===1?'<span class="badge bg-primary">1 · Norte</span>':v===2?'<span class="badge bg-success">2 · Sur</span>':'<span class="badge bg-secondary">0 · No aplica</span>'; }},
  {data:'fecha_inicio_vigencia'},{data:'fecha_fin_vigencia'},{data:'fecha_sincronizacion'}
 ]});

 let pollTimer=null;
 let currentJob=null;
 let cancelSent=false;

 function makeJobId(){
  if(window.crypto && crypto.randomUUID) return crypto.randomUUID().replaceAll('-','');
  return Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);
 }

 function progressHtml(){
  return '<div class="text-start px-2">'+
   '<div id="cpProgressMessage" class="fw-semibold mb-1">Iniciando…</div>'+
   '<div id="cpProgressDetail" class="small text-muted mb-2">Preparando el proceso</div>'+
   '<div class="progress" style="height:22px"><div id="cpProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">0%</div></div>'+
   '<div id="cpSheetMeter" class="small text-muted mt-2"></div>'+
   '</div>';
 }

 function updateProgress(job){
  const pct=Math.max(0,Math.min(100,parseInt(job.percent||0)));
  $('#cpProgressMessage').text(job.message||'Procesando…');
  $('#cpProgressDetail').text(job.detail||'');
  $('#cpProgressBar').css('width',pct+'%').text(pct+'%');
  if(job.sheet){
   const rows=job.sheet_rows||0, done=job.sheet_processed||0;
   $('#cpSheetMeter').text('Hoja '+job.sheet+' de '+(job.sheets_total||2)+(rows?' · '+done.toLocaleString()+' / '+rows.toLocaleString()+' filas':''));
  }else if(job.total){
   $('#cpSheetMeter').text((job.processed||0).toLocaleString()+' / '+job.total.toLocaleString()+' registros');
  }else{
   $('#cpSheetMeter').text('');
  }
  if(job.cancel_requested){
   $('#btnCancelarCpProceso').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Cancelando…');
  }
 }

 function stopPolling(){ if(pollTimer){clearInterval(pollTimer);pollTimer=null;} }

 function pollStatus(){
  if(!currentJob)return;
  $.post('ajax/sincronizar_codigos_postales_sat.php',{action:'status',job_id:currentJob},function(r){
   if(r.status==='ok' && r.job) updateProgress(r.job);
  },'json');
 }

 function requestCancel(){
  if(!currentJob || cancelSent)return;
  cancelSent=true;
  $('#btnCancelarCpProceso').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Cancelando…');
  $.post('ajax/sincronizar_codigos_postales_sat.php',{action:'cancel',job_id:currentJob})
   .fail(function(){cancelSent=false;$('#btnCancelarCpProceso').prop('disabled',false).text('Cancelar proceso');});
 }

 function enviar(fd){
  currentJob=makeJobId(); cancelSent=false;
  fd.append('action','start'); fd.append('job_id',currentJob);
  Swal.fire({
   title:'Sincronizando catálogo SAT',
   html:progressHtml(),
   allowOutsideClick:false,
   allowEscapeKey:false,
   showConfirmButton:false,
   showCancelButton:true,
   cancelButtonText:'Cancelar proceso',
   didOpen:function(){
    const cancelBtn=Swal.getCancelButton();
    cancelBtn.id='btnCancelarCpProceso';
    cancelBtn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();requestCancel();});
    pollStatus(); pollTimer=setInterval(pollStatus,700);
   }
  });

  $.ajax({url:'ajax/sincronizar_codigos_postales_sat.php',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
   .done(function(r){
    stopPolling(); currentJob=null;
    if(r.status==='ok'){
     tabla.ajax.reload(null,false);
     Swal.fire('Listo',r.msg,'success');
    }else Swal.fire('Error',r.msg,'error');
   })
   .fail(function(x){
    stopPolling(); currentJob=null;
    const data=x.responseJSON||{};
    if(data.cancelled || x.status===409){
     Swal.fire('Proceso cancelado',data.msg||'No se aplicaron cambios incompletos.','info');
    }else{
     Swal.fire('No se pudo sincronizar',data.msg||x.responseText||'Error del servidor','error');
    }
   });
 }

 $('#filtroEstimuloCp').on('change',function(){tabla.ajax.reload();});
 $('#btnSincronizarCp').on('click',function(){enviar(new FormData());});
 $('#btnImportarCp').on('click',function(){$('#archivoCp').click();});
 $('#archivoCp').on('change',function(){
  if(!this.files.length)return;
  const fd=new FormData(); fd.append('archivo_excel',this.files[0]);
  enviar(fd); this.value='';
 });
})();
</script>
