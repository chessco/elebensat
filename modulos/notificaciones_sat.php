<?php
session_start();
if(empty($_SESSION['id_usuario'])||empty($_SESSION['id_empresa'])){http_response_code(401);exit('Sesión no válida');}
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try{exigir_permiso_accion($pdo,(int)$_SESSION['id_usuario'],(int)$_SESSION['id_empresa'],'ver_solicitudes_sat');}catch(Throwable $e){http_response_code(403);exit($e->getMessage());}
?>
<style>
.alertas-panel{background:#fff;border:1px solid #c9d8e5;border-radius:10px;padding:16px;color:#243746}.alertas-cards{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:10px;margin-bottom:14px}.alerta-card{border:1px solid #d7e2ea;border-radius:9px;padding:12px;background:#f8fbfd}.alerta-card .n{font-size:1.6rem;font-weight:800;line-height:1}.alerta-card .t{font-size:.76rem;color:#61788a;margin-top:5px}.alerta-card.nueva .n{color:#dc3545}.alerta-card.vista .n{color:#0d6efd}.alerta-card.atendida .n{color:#198754}.alertas-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.alertas-toolbar .form-select{width:160px}.alertas-toolbar .form-control{max-width:420px;flex:1 1 260px}.estado-alerta{display:inline-block;min-width:82px;text-align:center;padding:4px 7px;border-radius:6px;font-weight:700;font-size:.72rem}.estado-NUEVA{background:#dc3545;color:#fff}.estado-VISTA{background:#0d6efd;color:#fff}.estado-ATENDIDA{background:#198754;color:#fff}.proc-badge{display:inline-block;margin:1px 2px;padding:3px 6px;border-radius:5px;font-size:.69rem;font-weight:700}.tabla-alertas th{background:#e9f2f8!important;color:#123f5d!important;white-space:nowrap;font-size:.75rem}.tabla-alertas td{font-size:.75rem;vertical-align:middle}.uuid-mini{font-family:Consolas,monospace;font-size:.68rem;max-width:220px;word-break:break-all}@media(max-width:800px){.alertas-cards{grid-template-columns:1fr}.alertas-toolbar .form-select,.alertas-toolbar .form-control{width:100%;max-width:none}}
</style>
<div class="alertas-panel">
 <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h5 class="mb-1"><i class="bi bi-bell-fill text-warning"></i> Alertas SAT de cancelación</h5><div class="text-muted small">Cancelaciones detectadas por metadata. Abrir una alerta la deja como vista; solo desaparece del pendiente cuando Contabilidad la marca como atendida.</div></div>
  <div class="d-flex gap-2 flex-wrap">
   <button class="btn btn-success btn-sm" id="btnExportarAlertasExcel"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</button>
   <button class="btn btn-outline-primary btn-sm" id="btnRecargarAlertas"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
  </div>
 </div>
 <div class="alertas-cards">
  <div class="alerta-card nueva"><div class="n" id="cntNueva">0</div><div class="t">NUEVAS / requieren revisión</div></div>
  <div class="alerta-card vista"><div class="n" id="cntVista">0</div><div class="t">VISTAS / pendientes de atender</div></div>
  <div class="alerta-card atendida"><div class="n" id="cntAtendida">0</div><div class="t">ATENDIDAS / historial</div></div>
 </div>
 <div class="alertas-toolbar">
  <select id="filtroEstadoAlerta" class="form-select form-select-sm"><option value="">Todos los estados</option><option value="NUEVA">Nuevas</option><option value="VISTA">Vistas</option><option value="ATENDIDA">Atendidas</option></select>
  <input id="buscarAlerta" class="form-control form-control-sm" placeholder="Buscar UUID, factura, RFC o nombre...">
  <button class="btn btn-primary btn-sm" id="btnFiltrarAlertas"><i class="bi bi-search"></i> Buscar</button>
 </div>
 <div class="table-responsive"><table id="tablaAlertasSat" class="table table-striped table-hover table-bordered tabla-alertas w-100"><thead><tr><th>ESTADO</th><th>FACTURA</th><th>UUID</th><th>FECHA CFDI</th><th>CANCELADA SAT</th><th>AVISO SGKSAT</th><th>CONTRAPARTE</th><th>PROCESO / IMPACTO</th><th>ACCIONES</th></tr></thead></table></div>
</div>
<script>
(function(){
 const esc=s=>$('<div>').text(s??'').html();
 const procesos=p=>(p||[]).map(x=>`<span class="proc-badge bg-${esc(x.tipo)} ${x.tipo==='warning'?'text-dark':'text-white'}">${esc(x.texto)}</span>`).join('');
 const tabla=$('#tablaAlertasSat').DataTable({destroy:true,searching:false,pageLength:15,order:[],ajax:{url:'ajax/listar_notificaciones_sat.php',dataSrc:r=>{if(!r.success){Swal.fire('Error',r.error||'No se pudieron leer alertas.','error');return []} $('#cntNueva').text(r.resumen.NUEVA||0);$('#cntVista').text(r.resumen.VISTA||0);$('#cntAtendida').text(r.resumen.ATENDIDA||0); if(typeof actualizarContadorNotificaciones==='function')actualizarContadorNotificaciones(); return r.data||[]},data:d=>{d.estado=$('#filtroEstadoAlerta').val();d.buscar=$('#buscarAlerta').val();}},columns:[
  {data:'estado',render:v=>`<span class="estado-alerta estado-${esc(v)}">${esc(v)}</span>`},
  {data:'factura'},
  {data:'uuid',render:v=>`<div class="uuid-mini">${esc(v)}</div>`},
  {data:'fecha_documento'},
  {data:'fecha_cancelacion'},
  {data:'fecha_aviso'},
  {data:'contraparte',render:v=>esc(v||'—')},
  {data:'procesos',orderable:false,render:p=>procesos(p)},
  {data:null,orderable:false,render:r=>`<button class="btn btn-outline-primary btn-sm btnDetalleAlerta" data-id="${r.id}"><i class="bi bi-eye"></i></button> ${r.estado!=='ATENDIDA'?`<button class="btn btn-success btn-sm btnAtenderAlerta" data-id="${r.id}"><i class="bi bi-check2-circle"></i> Atender</button>`:`<button class="btn btn-outline-secondary btn-sm btnReabrirAlerta" data-id="${r.id}"><i class="bi bi-arrow-counterclockwise"></i></button>`}`}
 ]});
 function recargar(){tabla.ajax.reload(null,false)}
 function xmlExcelEsc(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;');}
 function descargarExcelAlertas(){
  const filas=tabla.rows({search:'applied'}).data().toArray();
  if(!filas.length){Swal.fire('Sin datos','No hay alertas para exportar con los filtros actuales.','info');return;}
  const cab=['ESTADO','FACTURA','UUID','FECHA CFDI','CANCELADA SAT','AVISO SGKSAT','CONTRAPARTE','RFC EMISOR','RFC RECEPTOR','MONTO','PROCESO / IMPACTO','FECHA VISTO','USUARIO VISTO','FECHA ATENDIDO','USUARIO ATENDIÓ','OBSERVACIONES'];
  let rows='<Row>'+cab.map(h=>`<Cell ss:StyleID="Header"><Data ss:Type="String">${xmlExcelEsc(h)}</Data></Cell>`).join('')+'</Row>';
  filas.forEach(r=>{
   const proc=(r.procesos||[]).map(x=>x.texto||'').filter(Boolean).join(' | ');
   const vals=[r.estado,r.factura,r.uuid,r.fecha_documento,r.fecha_cancelacion,r.fecha_aviso,r.contraparte,r.rfc_emisor,r.rfc_receptor,null,proc,r.fecha_visto,r.usuario_visto,r.fecha_atendido,r.usuario_atendio,r.observaciones];
   rows+='<Row>'+vals.map((v,i)=>{
    if(i===9){const n=Number(r.monto);return Number.isFinite(n)?`<Cell ss:StyleID="Money"><Data ss:Type="Number">${n}</Data></Cell>`:'<Cell><Data ss:Type="String"></Data></Cell>';}
    return `<Cell><Data ss:Type="String">${xmlExcelEsc(v)}</Data></Cell>`;
   }).join('')+'</Row>';
  });
  const estado=$('#filtroEstadoAlerta').val()||'Todos'; const buscar=$('#buscarAlerta').val()||'';
  const xml=`\x3c?xml version="1.0" encoding="UTF-8"?>\x3c?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Calibri" ss:Size="10"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Center"/></Style><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style><Style ss:ID="Money"><NumberFormat ss:Format="$#,##0.00"/></Style></Styles><Worksheet ss:Name="ALERTAS SAT"><Table><Row><Cell ss:MergeAcross="15" ss:StyleID="Title"><Data ss:Type="String">Alertas SAT de cancelación</Data></Cell></Row><Row><Cell ss:MergeAcross="15"><Data ss:Type="String">Estado: ${xmlExcelEsc(estado)} | Buscar: ${xmlExcelEsc(buscar||'Sin texto')} | Registros: ${filas.length}</Data></Cell></Row>${rows}</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane></WorksheetOptions></Worksheet></Workbook>`;
  const blob=new Blob(['﻿',xml],{type:'application/vnd.ms-excel;charset=utf-8;'}); const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='Alertas_SAT_Cancelacion_'+new Date().toISOString().slice(0,10).replace(/-/g,'')+'.xls'; document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1000);
 }
 $('#btnExportarAlertasExcel').on('click',descargarExcelAlertas);
 $('#btnRecargarAlertas,#btnFiltrarAlertas').on('click',recargar);$('#buscarAlerta').on('keydown',e=>{if(e.key==='Enter')recargar()});$('#filtroEstadoAlerta').on('change',recargar);
 $('#tablaAlertasSat').on('click','.btnDetalleAlerta',function(){const r=tabla.row($(this).closest('tr')).data();if(!r)return;$.post('ajax/actualizar_notificacion_sat.php',{id:r.id,accion:'vista'},()=>{if(typeof actualizarContadorNotificaciones==='function')actualizarContadorNotificaciones();},'json');const historial=r.estado==='ATENDIDA'?`<hr><div class="text-start"><b>Atendida:</b> ${esc(r.fecha_atendido||'')} ${r.usuario_atendio?'por '+esc(r.usuario_atendio):''}<br><b>Observaciones:</b> ${esc(r.observaciones||'Sin observaciones')}</div>`:'';Swal.fire({title:'CFDI cancelado detectado',width:760,html:`<div class="text-start"><b>Factura:</b> ${esc(r.factura)}<br><b>UUID:</b> <span style="font-family:Consolas">${esc(r.uuid)}</span><br><b>Fecha CFDI:</b> ${esc(r.fecha_documento)}<br><b>Fecha cancelación SAT:</b> ${esc(r.fecha_cancelacion||'Sin fecha reportada')}<br><b>Fecha aviso SGKSAT:</b> ${esc(r.fecha_aviso)}<br><b>Contraparte:</b> ${esc(r.contraparte||'—')}<br><b>Monto:</b> ${r.monto===null?'—':'$'+Number(r.monto).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})}<br><br><b>Proceso / impacto:</b><br>${procesos(r.procesos)}</div>${historial}`,confirmButtonText:'Cerrar'}).then(recargar)});
 $('#tablaAlertasSat').on('click','.btnAtenderAlerta',function(){const id=$(this).data('id');Swal.fire({title:'Marcar cancelación como atendida',input:'textarea',inputLabel:'Qué se revisó / qué acción se tomó',inputPlaceholder:'Ej. Proveedor emitió sustitución, se revisará DIOT...',showCancelButton:true,confirmButtonText:'Marcar atendida',cancelButtonText:'Cancelar'}).then(x=>{if(!x.isConfirmed)return;$.post('ajax/actualizar_notificacion_sat.php',{id,accion:'atendida',observaciones:x.value||''},r=>{if(r.success){Swal.fire('Atendida','La alerta quedó en historial.','success');recargar()}else Swal.fire('Error',r.error||'No se pudo actualizar.','error')},'json')})});
 $('#tablaAlertasSat').on('click','.btnReabrirAlerta',function(){const id=$(this).data('id');$.post('ajax/actualizar_notificacion_sat.php',{id,accion:'reabrir'},r=>{if(r.success)recargar();else Swal.fire('Error',r.error||'No se pudo reabrir.','error')},'json')});
})();
</script>
