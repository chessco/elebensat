<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo,true);
try{exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales');}catch(Throwable $e){echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>';exit;}
if(empty($_SESSION['id_usuario'])||empty($_SESSION['es_superadmin'])){http_response_code(403);exit('Sin permiso');}
?>
<div class="container-fluid">
 <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div><h4 class="mb-1"><i class="bi bi-box-seam"></i> Productos y servicios SAT</h4><small class="text-muted">Catálogo oficial CFDI 4.0 · hoja c_ClaveProdServ.</small></div>
  <div class="d-flex gap-2 flex-wrap">
   <a class="btn btn-outline-info" target="_blank" rel="noopener" href="https://www.sat.gob.mx/minisitio/Factura/emite_quenecesitoparafacturar.htm"><i class="bi bi-box-arrow-up-right"></i> Ver fuente SAT</a>
   <button id="btnSincronizarProdServ" class="btn btn-primary"><i class="bi bi-file-earmark-excel"></i> Seleccionar archivo para sincronizar</button>
   <input id="archivoProdServ" type="file" accept=".xls,.xlsx" hidden>
  </div>
 </div>
 <div class="alert alert-info py-2"><i class="bi bi-info-circle"></i> <b>SINCRONIZACIÓN:</b> seleccione el archivo SAT guardado en su equipo. No se descarga ni se busca ningún folder. Se lee directamente la hoja <b>c_ClaveProdServ</b> y todos los renglones con datos del archivo seleccionado.</div>
 <div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Total claves</div><div id="psTotal" class="fs-4 fw-bold">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Con IVA indicado</div><div id="psIva" class="fs-4 fw-bold text-primary">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Con IEPS indicado</div><div id="psIeps" class="fs-4 fw-bold text-warning">—</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Vigentes</div><div id="psVigentes" class="fs-4 fw-bold text-success">—</div></div></div></div>
 </div>
 <div class="card mb-3 border-0 shadow-sm"><div class="card-body py-2"><div class="row align-items-end g-2">
  <div class="col-md-3"><label class="form-label small text-muted mb-1">IVA trasladado</label><select id="filtroPsIva" class="form-select"><option value="">Todos</option><option value="Sí">Sí</option><option value="Opcional">Opcional</option><option value="No">No</option></select></div>
  <div class="col-md-3"><label class="form-label small text-muted mb-1">IEPS trasladado</label><select id="filtroPsIeps" class="form-select"><option value="">Todos</option><option value="Sí">Sí</option><option value="Opcional">Opcional</option><option value="No">No</option></select></div>
  <div class="col-md-6 text-md-end"><div class="small text-muted">Versión del catálogo SAT</div><div id="psVersion" class="fw-semibold">Sin información</div></div>
 </div></div></div>
 <div class="card"><div class="card-body"><table id="tablaProductosServiciosSat" class="table table-striped table-hover w-100"><thead><tr><th>Clave</th><th>Descripción</th><th>IVA</th><th>IEPS</th><th>Complemento</th><th>Inicio vigencia</th><th>Fin vigencia</th><th>Sincronizado</th></tr></thead></table></div></div>
</div>
<script>
(function(){
 const tabla=$('#tablaProductosServiciosSat').DataTable({processing:true,serverSide:true,ajax:{url:'ajax/listar_productos_servicios_sat.php',data:function(d){d.iva=$('#filtroPsIva').val();d.ieps=$('#filtroPsIeps').val();},dataSrc:function(j){const r=j.resumen||{};$('#psTotal').text(Number(r.total||0).toLocaleString());$('#psIva').text(Number(r.con_iva||0).toLocaleString());$('#psIeps').text(Number(r.con_ieps||0).toLocaleString());$('#psVigentes').text(Number(r.vigentes||0).toLocaleString());const c=j.catalogo||{};let t='Sin información';if(c.revision_catalogo||c.fecha_publicacion){t='Revisión '+(c.revision_catalogo||'N/D');if(c.fecha_publicacion)t+=' · '+c.fecha_publicacion;if(c.fecha_sincronizacion)t+=' · sincronizado '+c.fecha_sincronizacion;}$('#psVersion').text(t);return j.data||[];}},pageLength:25,columns:[{data:'clave_prod_serv'},{data:'descripcion'},{data:'incluir_iva_trasladado'},{data:'incluir_ieps_trasladado'},{data:'complemento_debe_incluir'},{data:'fecha_inicio_vigencia'},{data:'fecha_fin_vigencia'},{data:'fecha_sincronizacion'}]});
 let timer=null,job=null,cancelSent=false;
 function jid(){return(window.crypto&&crypto.randomUUID)?crypto.randomUUID().replaceAll('-',''):Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);}
 function html(){return '<div class="text-start px-2"><div id="psMsg" class="fw-semibold mb-1">Iniciando…</div><div id="psDetail" class="small text-muted mb-2"></div><div class="progress" style="height:22px"><div id="psBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div></div><div id="psMeter" class="small text-muted mt-2"></div></div>';}
 function upd(j){const p=Math.max(0,Math.min(100,parseInt(j.percent||0)));$('#psMsg').text(j.message||'Procesando…');$('#psDetail').text(j.detail||'');$('#psBar').css('width',p+'%').text(p+'%');$('#psMeter').text(j.total?(Number(j.processed||0).toLocaleString()+' / '+Number(j.total||0).toLocaleString()+' filas'):'');if(j.cancel_requested)$('#btnCancelarProdServ').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Cancelando…');}
 function poll(){if(!job)return;$.post('ajax/sincronizar_productos_servicios_sat.php',{action:'status',job_id:job},function(r){if(r.status==='ok'&&r.job)upd(r.job);},'json');}
 function cancel(){if(!job||cancelSent)return;cancelSent=true;$('#btnCancelarProdServ').prop('disabled',true).text('Cancelando…');$.post('ajax/sincronizar_productos_servicios_sat.php',{action:'cancel',job_id:job});}
 function send(fd){job=jid();cancelSent=false;fd.append('action','start');fd.append('job_id',job);Swal.fire({title:'Sincronizando productos y servicios SAT',html:html(),allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,showCancelButton:true,cancelButtonText:'Cancelar proceso',didOpen:function(){const b=Swal.getCancelButton();b.id='btnCancelarProdServ';b.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();cancel();});poll();timer=setInterval(poll,700);}});$.ajax({url:'ajax/sincronizar_productos_servicios_sat.php',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(function(r){clearInterval(timer);timer=null;job=null;if(r.status==='ok'){Swal.fire('Sincronización terminada',r.msg,'success');}else Swal.fire('Error de sincronización',r.msg,'error');}).fail(function(x){clearInterval(timer);timer=null;job=null;const d=x.responseJSON||{};if(d.cancelled||x.status===409)Swal.fire('Proceso cancelado',d.msg||'Se conservó el catálogo anterior.','info');else {
  const detalle =
    'HTTP: '+x.status+' '+(x.statusText||'')+'\n'+
    'Respuesta: '+(x.responseText||'(vacía)')+'\n'+
    'Parser: '+(d.msg||'sin mensaje JSON');
  Swal.fire({
    title:'Error en respuesta final de sincronización',
    html:'<div style="text-align:left;white-space:pre-wrap">'+$('<div>').text(detalle).html()+'</div>',
    icon:'error',
    width:800
  });
}});}
 $('#filtroPsIva,#filtroPsIeps').on('change',function(){tabla.ajax.reload();});$('#btnSincronizarProdServ').on('click',function(){$('#archivoProdServ').click();});$('#archivoProdServ').on('change',function(){if(!this.files.length)return;const fd=new FormData();fd.append('archivo_excel',this.files[0]);send(fd);this.value='';});
})();
</script>
