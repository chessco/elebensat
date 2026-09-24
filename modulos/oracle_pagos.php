<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';

seguridad_exigir_sesion($pdo,true,false,true);
$idUsuario=(int)($_SESSION['id_usuario']??0);
$idEmpresa=(int)($_SESSION['id_empresa']??0);
try{exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');}
catch(Throwable $e){http_response_code(403);exit($e->getMessage());}

$st=$pdo->prepare(
    'SELECT c.unidad_negocio,c.usuario,c.ultima_sincronizacion,c.ultimo_error,e.razon_social
       FROM empresas e
       LEFT JOIN empresa_oracle_pagos_config c ON c.id_empresa=e.id_empresa
      WHERE e.id_empresa=? LIMIT 1'
);
$st->execute([$idEmpresa]);
$cfg=$st->fetch(PDO::FETCH_ASSOC)?:[];
?>
<style>
.oracle-wrap{font-size:.82rem}
.oracle-head{background:#3e5873;color:white;border-radius:8px;padding:12px}
.oracle-filter-label{display:block!important;color:#fff!important;font-size:.78rem!important;font-weight:800!important;opacity:1!important;line-height:1.1!important;letter-spacing:.03em;background:rgba(0,0,0,.22);padding:4px 7px;border-radius:4px;margin-bottom:5px;text-shadow:0 1px 1px rgba(0,0,0,.35)}
.oracle-consulta{background:transparent;border:0;border-radius:0;padding:0;margin:0}
.oracle-consulta .form-control,.oracle-consulta .form-select{min-width:105px}
.oracle-progress-modal .progress{height:24px}
.oracle-progress-modal .progress-bar{font-weight:700}
#tablaOracleFacturas,#tablaOracleGastos{font-size:.76rem}
#tablaOracleFacturas th,#tablaOracleFacturas td,#tablaOracleGastos th,#tablaOracleGastos td{vertical-align:middle!important;white-space:nowrap}
#tablaOracleFacturas_wrapper .dataTables_scrollHeadInner,#tablaOracleFacturas_wrapper .dataTables_scrollHeadInner table,
#tablaOracleGastos_wrapper .dataTables_scrollHeadInner,#tablaOracleGastos_wrapper .dataTables_scrollHeadInner table{width:100%!important}
.oracle-concepto-cell{min-width:210px!important;max-width:300px!important}
.oracle-concepto-text{display:block;max-width:285px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:help}
#tablaOracleFacturas td.oracle-exp-control{width:34px;text-align:center;vertical-align:middle}
#tablaOracleFacturas tr.oracle-reposicion>td{background:#fff8e1!important}
#tablaOracleFacturas tr.oracle-reposicion:hover>td{background:#fff2bf!important}
.oracle-exp-btn{border:0;background:transparent;color:#0d6efd;font-size:1rem;padding:0 4px;line-height:1}
.oracle-exp-btn:disabled{color:#adb5bd}
.oracle-detalle-wrap{padding:8px 12px;background:#f7fbff;border-left:4px solid #0dcaf0}
.oracle-detalle-tabla{font-size:.74rem;margin-bottom:0}
.oracle-detalle-tabla thead th{white-space:nowrap;background:#e8f5fb}
/* Igual que CONTPAQ Cheques: DataTables mantiene el encabezado fuera del cuerpo desplazable. */
#tablaOracleFacturas_wrapper .dataTables_scrollBody,#tablaOracleGastos_wrapper .dataTables_scrollBody{border-bottom:1px solid #d9e2e8}
#tablaOracleFacturas_wrapper .dataTables_scrollHead,#tablaOracleGastos_wrapper .dataTables_scrollHead{background:#e8f2f8}
.oracle-head .oracle-consulta{margin-left:auto;padding-left:34px}
.oracle-head .oracle-consulta .form-control,.oracle-head .oracle-consulta .form-select{min-height:31px}
@media (max-width:1200px){.oracle-head .oracle-consulta{margin-left:0!important;padding-left:0;width:100%}}

/* V17: acciones Oracle siempre en un solo renglón + desplazamiento horizontal visible */
.oracle-acciones-nowrap{
    display:inline-flex!important;
    flex-wrap:nowrap!important;
    align-items:center!important;
    justify-content:center!important;
    gap:5px!important;
    white-space:nowrap!important;
    min-width:max-content;
}
.oracle-acciones-nowrap .btn{
    flex:0 0 auto!important;
    margin:0!important;
}
#tablaOracleFacturas td:last-child,
#tablaOracleGastos td:last-child{
    white-space:nowrap!important;
    min-width:165px!important;
}
#tablaOracleFacturas_wrapper .dataTables_scrollBody,
#tablaOracleGastos_wrapper .dataTables_scrollBody{
    overflow-x:auto!important;
    overflow-y:auto!important;
}

</style>

<div class="oracle-wrap animate__animated animate__fadeIn">
    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-cloud-arrow-down-fill text-danger me-2"></i>ORACLE PAGOS</h6>
        <div class="text-muted">Importación mensual de facturas, estatus, pagos, conciliación bancaria y detalle de gastos Oracle.</div>
        <div class="ms-auto d-flex align-items-center gap-2"><button type="button" class="btn btn-sm btn-outline-warning" onclick="cargarModulo('oracle_iva_conciliacion')"><i class="bi bi-intersect"></i> Conciliación IVA</button><div class="small"><b>Última importación:</b> <?=htmlspecialchars((string)($cfg['ultima_sincronizacion']??'Sin información'))?></div></div>
    </div>

    <?php if(empty($cfg['unidad_negocio'])): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Falta configurar <b>Oracle Pagos</b> para esta empresa en Administración → Empresas.
    </div>
    <?php endif; ?>

    <div class="oracle-head d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
            <span class="oracle-filter-label">MES A SINCRONIZAR</span>
            <select id="oracle_mes" class="form-select form-select-sm">
                <?php
                $ms=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                $m=(int)date('n');
                foreach($ms as $n=>$nom)echo '<option value="'.$n.'" '.($n===$m?'selected':'').'>'.$nom.'</option>';
                ?>
            </select>
        </div>
        <div>
            <span class="oracle-filter-label">AÑO</span>
            <input id="oracle_anio" type="number" class="form-control form-control-sm" min="2000" max="2100" value="<?=date('Y')?>" style="width:100px">
        </div>
        <div>
            <span class="oracle-filter-label">DESDE SINCR.</span>
            <input id="oracle_desde" type="date" class="form-control form-control-sm">
        </div>
        <div>
            <span class="oracle-filter-label">HASTA SINCR.</span>
            <input id="oracle_hasta" type="date" class="form-control form-control-sm">
        </div>
        <button id="btnOracleImportar" type="button" class="btn btn-warning btn-sm fw-bold" <?=empty($cfg['unidad_negocio'])?'disabled':''?>>
            <i class="bi bi-arrow-repeat me-1"></i> SINCRONIZAR
        </button>
        <div class="oracle-consulta d-flex flex-wrap align-items-end gap-2 ms-auto">
            <div>
                <span class="oracle-filter-label">AÑO RÁPIDO</span>
                <input id="oracle_filtro_anio" type="number" class="form-control form-control-sm" min="2000" max="2100" value="<?=date('Y')?>" style="width:100px">
            </div>
            <div>
                <span class="oracle-filter-label">MES RÁPIDO</span>
                <select id="oracle_filtro_mes" class="form-select form-select-sm" style="min-width:130px">
                    <?php foreach($ms as $n=>$nom)echo '<option value="'.$n.'" '.($n===$m?'selected':'').'>'.$nom.'</option>'; ?>
                </select>
            </div>
            <div>
                <span class="oracle-filter-label">DESDE FILTRO</span>
                <input id="oracle_filtro_desde" type="date" class="form-control form-control-sm">
            </div>
            <div>
                <span class="oracle-filter-label">HASTA FILTRO</span>
                <input id="oracle_filtro_hasta" type="date" class="form-control form-control-sm">
            </div>
            <div>
                <span class="oracle-filter-label">ESTATUS CONCILIACIÓN</span>
                <select id="oracle_estatus_conciliacion" class="form-select form-select-sm" style="min-width:155px">
                    <option value="TODOS">TODOS</option>
                    <option value="PENDIENTE">PENDIENTE</option>
                    <option value="CONCILIADO">CONCILIADO</option>
                    <option value="CONCILIADO_SIN_COMPLEMENTO">CONCILIADO SIN COMPLEMENTO</option>
                    <option value="PARCIAL">PARCIAL</option>
                    <option value="DIFERENCIA">DIFERENCIA</option>
                    <option value="SIN_XML">SIN XML</option>
                    <option value="SIN_UUID">SIN UUID</option>
                    <option value="SIN_DETALLE">SIN DETALLE</option>
                    <option value="SIN_PAGO">SIN PAGO</option>
                    <option value="CANCELADO">CANCELADO</option>
                    <option value="PENSION">PENSIÓN</option>
                    <option value="DEMANDA">DEMANDA</option>
                    <option value="NO_CONSIDERAR">NO CONSIDERAR</option>
                    <option value="MANUAL">MANUAL</option>
                </select>
            </div>
            <button id="btnOracleFiltrar" type="button" class="btn btn-primary btn-sm fw-bold">
                <i class="bi bi-funnel-fill me-1"></i> FILTRAR
            </button>
            <button id="btnOracleConciliar" type="button" class="btn btn-success btn-sm fw-bold">
                <i class="bi bi-link-45deg me-1"></i> CONCILIAR
            </button>
            <button id="btnOracleExportar" type="button" class="btn btn-light btn-sm fw-bold text-success">
                <i class="bi bi-file-earmark-excel-fill me-1"></i> EXPORTAR EXCEL
            </button>
            <button id="btnOracleReglas" type="button" class="btn btn-outline-light btn-sm fw-bold">
                <i class="bi bi-tags-fill me-1"></i> REGLAS SIN UUID
            </button>
        </div>
    </div>

    <ul class="nav nav-tabs mb-2">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#oracleF">Facturas</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#oracleG">Gastos / movimientos</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="oracleF">
            <div class="table-responsive">
                <table id="tablaOracleFacturas" class="table table-striped table-hover w-100">
                    <thead><tr>
                        <th style="width:34px"></th><th>InvoiceId</th><th>Factura</th><th>Fecha</th><th>Proveedor</th><th>RFC</th>
                        <th>Importe</th><th>Moneda</th><th>Estatus factura</th><th>Fecha cancelación</th>
                        <th>Estado pago</th><th>Fecha pago</th><th>No. pago</th><th>Concepto cheque / pago</th><th>Conciliado banco</th>
                        <th>Fecha conciliación</th><th>Fecha valor</th><th># pagos</th><th>UUID</th><th>Diferencia $</th><th>Conciliación</th>
                    </tr></thead><tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="oracleG">
            <div class="table-responsive">
                <table id="tablaOracleGastos" class="table table-striped table-hover w-100">
                    <thead><tr>
                        <th>ReportId</th><th>ExpenseId</th><th>Fecha</th><th>Persona</th><th>Comercio</th>
                        <th>Tipo</th><th>Importe</th><th>Moneda</th><th>Estado</th><th>UUID</th><th>Diferencia $</th><th>Conciliación</th>
                    </tr></thead><tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
 let tf=null,tg=null,jobActual=null,cancelando=false,xhrActual=null;

 function periodo(){return {anio:+$('#oracle_anio').val(),mes:+$('#oracle_mes').val()};}
 function rango(){return {desde:$('#oracle_desde').val(),hasta:$('#oracle_hasta').val()};}
 function periodoFiltro(){return {anio:+$('#oracle_filtro_anio').val(),mes:+$('#oracle_filtro_mes').val()};}
 function rangoFiltro(){return {desde:$('#oracle_filtro_desde').val(),hasta:$('#oracle_filtro_hasta').val()};}
 function periodoImportacion(){const p=periodo(),r=rango();return {anio:p.anio,mes:p.mes,desde:r.desde,hasta:r.hasta};}
 function estatusConciliacion(){return String($('#oracle_estatus_conciliacion').val()||'TODOS').toUpperCase();}
 function ymd(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
 function ponerRangoMes(anio,mes,forzarValores=true){
   anio=Number(anio);mes=Number(mes);
   const d1=new Date(anio,mes-1,1), d2=new Date(anio,mes,0);
   const min=ymd(d1), max=ymd(d2);
   $('#oracle_desde,#oracle_hasta').attr({min:min,max:max});
   if(forzarValores){
      $('#oracle_desde').val(min);
      $('#oracle_hasta').val(max);
   }else{
      const desde=$('#oracle_desde').val(), hasta=$('#oracle_hasta').val();
      if(!desde || desde<min || desde>max) $('#oracle_desde').val(min);
      if(!hasta || hasta<min || hasta>max) $('#oracle_hasta').val(max);
   }
 }
 function ponerRangoFiltroMes(anio,mes,forzarValores=true){
   anio=Number(anio);mes=Number(mes);
   const d1=new Date(anio,mes-1,1), d2=new Date(anio,mes,0);
   const min=ymd(d1), max=ymd(d2);
   $('#oracle_filtro_desde,#oracle_filtro_hasta').attr({min:min,max:max});
   if(forzarValores){
      $('#oracle_filtro_desde').val(min);
      $('#oracle_filtro_hasta').val(max);
   }else{
      const desde=$('#oracle_filtro_desde').val(), hasta=$('#oracle_filtro_hasta').val();
      if(!desde || desde<min || desde>max) $('#oracle_filtro_desde').val(min);
      if(!hasta || hasta<min || hasta>max) $('#oracle_filtro_hasta').val(max);
   }
 }
 function validarRangoFiltro(){
   const p=periodoFiltro(),r=rangoFiltro();
   const min=ymd(new Date(p.anio,p.mes-1,1)), max=ymd(new Date(p.anio,p.mes,0));
   if(!r.desde||!r.hasta){Swal.fire('Filtro','Capture fecha desde y hasta.','warning');return false;}
   if(r.desde>r.hasta){Swal.fire('Filtro','La fecha desde no puede ser mayor que la fecha hasta.','warning');return false;}
   if(r.desde<min||r.desde>max||r.hasta<min||r.hasta>max){
      Swal.fire('Filtro','El rango debe permanecer dentro de '+$('#oracle_filtro_mes option:selected').text()+' '+p.anio+'.','warning');
      ponerRangoFiltroMes(p.anio,p.mes,false);
      return false;
   }
   return true;
 }

 function validarRangoMesSeleccionado(){
   const p=periodo(),r=rango();
   const min=ymd(new Date(p.anio,p.mes-1,1)), max=ymd(new Date(p.anio,p.mes,0));
   if(!r.desde||!r.hasta){Swal.fire('Importación','Capture fecha desde y hasta.','warning');return false;}
   if(r.desde>r.hasta){Swal.fire('Importación','La fecha desde no puede ser mayor que la fecha hasta.','warning');return false;}
   if(r.desde<min||r.desde>max||r.hasta<min||r.hasta>max){
      Swal.fire('Importación','El rango debe permanecer dentro de '+$('#oracle_mes option:selected').text()+' '+p.anio+'.','warning');
      ponerRangoMes(p.anio,p.mes,false);
      return false;
   }
   return true;
 }
 function money(v){return Number(v||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function diferenciaHtml(v,estado){
   const n=Number(v||0);
   if(String(estado||'').toUpperCase()!=='DIFERENCIA' && Math.abs(n)<0.000001)return '<span class="text-muted">—</span>';
   const signo=n<0?'-':'';
   return '<span class="fw-bold '+(Math.abs(n)>0.000001?'text-danger':'text-muted')+'">'+signo+'$'+money(Math.abs(n))+'</span>';
 }
 function esc(v){return $('<div>').text(v==null?'':String(v)).html();}

 function badgeConciliacion(estado,accion){
   estado=String(estado||'PENDIENTE').toUpperCase();
   let cls='bg-secondary',icon='bi-hourglass-split';
   if(estado==='CONCILIADO'){cls='bg-success';icon='bi-check-circle-fill';}
   else if(estado==='CONCILIADO_SIN_COMPLEMENTO'){cls='bg-warning text-dark';icon='bi-check-circle';}
   else if(estado==='PARCIAL'){cls='bg-warning text-dark';icon='bi-exclamation-circle-fill';}
   else if(estado==='DIFERENCIA'){cls='bg-danger';icon='bi-exclamation-octagon-fill';}
   else if(estado==='CANCELADO'){cls='bg-dark';icon='bi-x-circle-fill';}
   else if(estado==='PENSION'){cls='bg-info text-dark';icon='bi-person-heart';}
   else if(estado==='DEMANDA'){cls='bg-primary';icon='bi-bank';}
   else if(estado==='NO_CONSIDERAR'){cls='bg-secondary';icon='bi-slash-circle';}
   else if(estado==='MANUAL'){cls='bg-secondary';icon='bi-pencil-square';}
   else if(estado==='SIN_XML'||estado==='SIN_UUID'||estado==='SIN_DETALLE'||estado==='SIN_PAGO'){cls='bg-danger';icon='bi-link-45deg';}
   const a=accion||'';
   return '<button type="button" class="btn btn-sm p-0 border-0 '+a+'" title="Ver conciliación financiera"><span class="badge '+cls+'"><i class="bi '+icon+' me-1"></i>'+esc(estado.replaceAll('_',' '))+'</span></button>';
 }
 function estadoBadge(e){return badgeConciliacion(e,'').replace('<button type="button" class="btn btn-sm p-0 border-0 " title="Ver conciliación financiera">','').replace('</button>','');}

 function htmlConciliacion(data){
   const o=data.oracle||null, x=data.xml||null;
   let h='<div class="text-start">';
   if(o){
      h+='<div class="border rounded p-2 mb-2"><b>Documento Oracle:</b> '+esc(o.invoice_number||o.oracle_invoice_id||'')+
         ' &nbsp; <b>Importe:</b> '+money(o.invoice_amount||0)+' '+esc(o.invoice_currency||'')+
         ' &nbsp; <b>Estatus:</b> '+estadoBadge(o.estatus_conciliacion||'PENDIENTE')+'</div>';
   }
   if(x){
      h+='<div class="border rounded p-2 mb-2"><b>XML:</b> '+esc(x.uuid||'')+
         ' &nbsp; <b>Método:</b> '+esc(x.metodo_pago||'')+
         ' &nbsp; <b>Total:</b> '+money(x.total_xml||0)+' '+esc(x.moneda||'')+
         ' &nbsp; <b>SAT:</b> '+esc(x.estatus_sat||'')+'</div>';
   }
   if(data.tipo==='EXP' && Array.isArray(data.gastos)){
      h+='<div class="fw-bold mb-1">Comprobantes de la reposición</div><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Factura</th><th>UUID</th><th>Importe</th><th>Conciliación</th></tr></thead><tbody>';
      data.gastos.forEach(g=>{
        let est=g.estatus_conciliacion||'PENDIENTE';
        h+='<tr><td>'+esc(g.reference_number||g.expense_reference||g.oracle_expense_id)+'</td><td>'+esc(g.uuid_cfdi||'Sin UUID')+'</td><td class="text-end">'+money(g.receipt_amount||0)+'</td><td>'+estadoBadge(est)+'</td></tr>';
      });
      h+='</tbody></table></div>';
   }else{
      const rel=Array.isArray(data.relaciones)?data.relaciones:[];
      h+='<div class="fw-bold mb-1">Cruce XML → detalle fiscal → pago Oracle → banco</div><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Origen fiscal</th><th>Parc.</th><th>Pago Oracle</th><th>Fecha</th><th>Aplicado</th><th>Banco</th><th>Fecha conc.</th><th>Estado</th></tr></thead><tbody>';
      if(!rel.length) h+='<tr><td colspan="8" class="text-center text-muted">Todavía no existe conciliación para este documento.</td></tr>';
      rel.forEach(r=>{
        h+='<tr><td>'+esc(r.origen_fiscal||r.estatus_detalle_pago||'')+'</td><td>'+esc(r.parcialidad||'')+'</td><td>'+esc(r.folio_pago||'')+'</td><td>'+esc(r.fecha_pago||'')+'</td><td class="text-end">'+money(r.importe_aplicado||0)+'</td><td>'+(Number(r.conciliado_banco)===1?'SÍ':'NO')+'</td><td>'+esc(r.fecha_conciliacion_banco||'')+'</td><td>'+estadoBadge(r.estatus_conciliacion||'PENDIENTE')+'</td></tr>';
      });
      h+='</tbody></table></div>';
   }
   h+='</div>'; return h;
 }
 function uuidEfectivo(r){ return String((r&&r.uuid_manual)||((r&&r.uuid_cfdi)||'')).trim(); }
 function botonesClasificacion(r,tipo){
   const est=String(r.estatus_conciliacion||'PENDIENTE').toUpperCase();
   const marcado=['PENSION','DEMANDA','NO_CONSIDERAR','MANUAL'].includes(est);
   const tieneLiga=String(r.uuid_manual||'').trim()!=='';
   let h='<span class="oracle-acciones-nowrap">';
   h+='<button type="button" class="btn btn-sm btn-outline-secondary oracle-clasificar" title="Clasificar manualmente" data-tipo="'+tipo+'" data-id="'+esc(r.id)+'"><i class="bi bi-pencil-square"></i></button>';
   if(est==='SIN_UUID'||tieneLiga){
     const tit=tieneLiga?'Editar liga manual de UUID':'Buscar y ligar UUID del visor';
     const ico=tieneLiga?'bi-link-45deg':'bi-search';
     h+='<button type="button" class="btn btn-sm '+(tieneLiga?'btn-outline-warning':'btn-outline-primary')+' oracle-buscar-uuid" title="'+tit+'" data-tipo="'+tipo+'" data-id="'+esc(r.id)+'" onclick="event.preventDefault();event.stopPropagation();window.oracleBuscarUuidManual(\''+tipo+'\',\''+esc(r.id)+'\');return false;"><i class="bi '+ico+'"></i></button>';
   }
   if(marcado) h+='<button type="button" class="btn btn-sm btn-outline-danger oracle-quitar-clasificacion" title="Quitar clasificación manual" data-tipo="'+tipo+'" data-id="'+esc(r.id)+'"><i class="bi bi-arrow-counterclockwise"></i></button>';
   h+='</span>';
   return h;
 }
 function modalClasificar(r,tipo){
   const doc=tipo==='FACTURA'?(r.invoice_number||r.oracle_invoice_id):(r.reference_number||r.expense_reference||r.oracle_expense_id);
   const prov=tipo==='FACTURA'?(r.supplier||''):(r.merchant_name||r.person_name||'');
   const sugerida=String(r.estatus_conciliacion||'SIN_UUID').toUpperCase();
   const opciones=['PENSION','DEMANDA','NO_CONSIDERAR','MANUAL'];
   let sel='<select id="ocm_estatus" class="form-select form-select-sm">'+opciones.map(x=>'<option value="'+x+'" '+(x===sugerida?'selected':'')+'>'+x.replaceAll('_',' ')+'</option>').join('')+'</select>';
   let html='<div class="text-start"><div class="mb-2"><b>Documento:</b> '+esc(doc||'')+'</div><div class="mb-2"><b>Proveedor/beneficiario:</b> '+esc(prov||'')+'</div><label class="form-label">Estatus manual</label>'+sel+
     '<label class="form-label mt-2">Observación</label><input id="ocm_obs" class="form-control form-control-sm" placeholder="Opcional">'+
     '<div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="ocm_regla"><label class="form-check-label" for="ocm_regla">Guardar también una regla automática para futuros documentos sin UUID</label></div>'+
     '<div id="ocm_regla_box" class="border rounded p-2 mt-2" style="display:none"><label class="form-label">Palabra o frase</label><input id="ocm_patron" class="form-control form-control-sm" value="'+esc(doc||'')+'">'+
     '<div class="row g-2 mt-1"><div class="col"><label class="form-label">Buscar en</label><select id="ocm_campo" class="form-select form-select-sm"><option value="CONCEPTO">Concepto de pago</option><option value="DOCUMENTO">Documento/factura</option><option value="PROVEEDOR">Proveedor/beneficiario</option><option value="TODOS" selected>Todos</option></select></div>'+
     '<div class="col"><label class="form-label">Coincidencia</label><select id="ocm_modo" class="form-select form-select-sm"><option value="CONTIENE" selected>Contiene</option><option value="INICIA">Inicia con</option><option value="EXACTO">Exacta</option></select></div></div>'+
     '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="ocm_global"><label class="form-check-label" for="ocm_global">Regla global para todas las empresas</label></div></div></div>';
   Swal.fire({title:'Clasificar documento Oracle',html:html,width:'720px',showCancelButton:true,confirmButtonText:'Guardar clasificación',cancelButtonText:'Cancelar',didOpen:function(){$('#ocm_regla').on('change',function(){$('#ocm_regla_box').toggle(this.checked);});},preConfirm:function(){return {estatus:$('#ocm_estatus').val(),observaciones:$('#ocm_obs').val(),crear_regla:$('#ocm_regla').is(':checked')?1:0,patron:$('#ocm_patron').val(),campo_busqueda:$('#ocm_campo').val(),tipo_coincidencia:$('#ocm_modo').val(),regla_global:$('#ocm_global').is(':checked')?1:0};}}).then(function(x){
      if(!x.isConfirmed)return; const d=x.value||{}; d.tipo_documento=tipo; d.id_documento=r.id;
      $.post('ajax/oracle_clasificar_documento.php',d,function(z){if(!z.success)return Swal.fire('Clasificación',z.error||'No fue posible guardar.','error');Swal.fire('Clasificación','Documento clasificado correctamente.','success').then(()=>cargar());},'json').fail(function(xhr){Swal.fire('Clasificación',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible guardar.','error');});
   });
 }
 function modalBuscarUuid(r,tipo){
   $.getJSON('ajax/oracle_uuid_manual.php',{accion:'info',tipo_documento:tipo,id_documento:r.id}).done(function(info){
      if(!info.success) return Swal.fire('Buscar UUID',info.error||'No fue posible cargar el documento.','error');
      const d=info.documento||{}, manual=info.manual||null, sugerido=info.sugerido||info.rfc_sugerido||null;
      const editando=!!(manual&&String(manual.uuid||'').trim());
      const fecha=String(d.fecha||'').substring(0,10);
      let desde='',hasta='';
      if(/^\d{4}-\d{2}-\d{2}$/.test(fecha)){
         const a=fecha.split('-').map(Number); desde=a[0]+'-'+String(a[1]).padStart(2,'0')+'-01';
         const fin=new Date(a[0],a[1]+1,0); hasta=fin.getFullYear()+'-'+String(fin.getMonth()+1).padStart(2,'0')+'-'+String(fin.getDate()).padStart(2,'0');
      }
      let html='<div class="text-start">'+
       '<div class="row g-2 mb-2"><div class="col-md-6"><b>Documento Oracle:</b> '+esc(d.invoice_number||'')+'</div><div class="col-md-6"><b>Proveedor:</b> '+esc(d.supplier||'')+'</div></div>'+
       '<div class="row g-2"><div class="col-md-3"><label class="form-label">RFC *</label><input id="oum_rfc" class="form-control form-control-sm" value="'+esc((manual&&manual.rfc_validado)||d.rfc||(sugerido&&sugerido.rfc)||'')+'"></div>'+
       '<div class="col-md-3"><label class="form-label">Nombre (referencia)</label><input id="oum_nombre" class="form-control form-control-sm" value="" placeholder="'+esc(d.supplier||'')+'"></div>'+
       '<div class="col-md-3"><label class="form-label">Importe</label><input id="oum_importe" class="form-control form-control-sm" value="'+esc(d.importe||'')+'"></div>'+
       '<div class="col-md-3"><label class="form-label">UUID contiene</label><input id="oum_uuid" class="form-control form-control-sm" value="'+esc(editando?manual.uuid:(d.invoice_number||''))+'" placeholder="Opcional"></div></div>'+
       '<div class="row g-2 mt-1"><div class="col-md-3"><label class="form-label">Desde</label><input id="oum_desde" type="date" class="form-control form-control-sm" value="'+esc(desde)+'"></div>'+
       '<div class="col-md-3"><label class="form-label">Hasta</label><input id="oum_hasta" type="date" class="form-control form-control-sm" value="'+esc(hasta)+'"></div>'+
       '<div class="col-md-3 d-flex align-items-end"><button id="oum_buscar" type="button" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> BUSCAR CFDI</button></div>'+
       '<div class="col-md-3 d-flex align-items-end"><div class="small"><b>Oracle:</b> '+money(d.importe||0)+' '+esc(d.moneda||'')+'</div></div></div>'+
       ((!d.rfc&&sugerido&&sugerido.rfc)?'<div class="alert alert-info py-1 px-2 mt-2 mb-1 small"><b>RFC sugerido por nombre del emisor:</b> '+esc(sugerido.rfc)+' — '+esc(sugerido.nombre||'')+'. Puedes editarlo antes de buscar.</div>':'')+
       (editando?'<div class="alert alert-warning py-2 mt-2 mb-2"><div><b>EDITANDO LIGA MANUAL</b></div><div class="mt-1"><b>UUID actual:</b> <span class="font-monospace">'+esc(manual.uuid)+'</span></div><div><b>RFC validado:</b> '+esc(manual.rfc_validado||'')+' &nbsp; <b>Importe XML:</b> '+money(manual.importe_xml||0)+' '+esc(manual.moneda_xml||'')+'</div><div><b>Último cambio:</b> '+esc(manual.fecha_actualizacion||manual.fecha_registro||'')+'</div><div class="mt-2"><span class="small">Busca y selecciona otro CFDI para reemplazar la liga, o usa el botón rojo inferior para quitarla y devolver el documento a conciliación automática.</span></div></div>':'')+
       '<div id="oum_msg" class="small text-muted mt-2">La búsqueda toma los CFDI del RFC dentro del rango. Con UUID parcial, el importe no oculta resultados. Sin UUID parcial, busca candidatos dentro de ±$5.00. Al ligar se valida RFC y moneda; cualquier diferencia de importe requiere confirmación.</div>'+
       '<div class="table-responsive border rounded mt-2" style="max-height:330px;overflow:auto"><table class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"><tr><th></th><th>Fecha</th><th>RFC</th><th>Proveedor</th><th>UUID</th><th>Serie/Folio</th><th>Total XML</th><th>Oracle</th><th>Diferencia</th><th>Moneda</th><th>Método</th><th>SAT</th></tr></thead><tbody id="oum_rows"><tr><td colspan="12" class="text-center text-muted">Pulsa BUSCAR CFDI.</td></tr></tbody></table></div>'+
       '<div class="d-flex justify-content-end gap-2 mt-3">'+
       (editando?'<button id="oum_quitar" type="button" class="btn btn-danger"><i class="bi bi-trash3"></i> QUITAR UUID / LIGA MANUAL</button>':'')+
       '<button id="oum_guardar" class="btn btn-success" disabled><i class="bi bi-link-45deg"></i> '+(editando?'CAMBIAR UUID Y RECONCILIAR':'LIGAR UUID Y CONCILIAR')+'</button></div></div>';
      Swal.fire({title:(editando?'Editar liga manual de UUID':'Buscar UUID en el visor'),html:html,width:'1350px',showConfirmButton:false,showCancelButton:true,cancelButtonText:'Cerrar',didOpen:function(){
         let elegido=editando?String(manual.uuid||''):'';
         function buscar(){
            const q={accion:'buscar',tipo_documento:tipo,id_documento:r.id,rfc:$('#oum_rfc').val(),importe:$('#oum_importe').val(),uuid:$('#oum_uuid').val(),desde:$('#oum_desde').val(),hasta:$('#oum_hasta').val()};
            $('#oum_rows').html('<tr><td colspan="12" class="text-center">Buscando...</td></tr>'); $('#oum_guardar').prop('disabled',true); elegido='';
            $.getJSON('ajax/oracle_uuid_manual.php',q).done(function(z){
               if(!z.success) throw new Error(z.error||'Error');
               const rows=z.data||[];
               if(!rows.length){$('#oum_rows').html('<tr><td colspan="12" class="text-center text-warning">No se encontraron CFDI con esos filtros.</td></tr>');return;}
               $('#oum_rows').html(rows.map(function(x){const sf=[x.serie||'',x.folio||''].filter(Boolean).join('/');const actual=editando&&String(x.uuid||'').toUpperCase()===String(manual.uuid||'').toUpperCase();const dif=Number(x.diferencia_importe||0);const difHtml='<span class="'+(Math.abs(dif)>=0.01?'text-danger fw-bold':'text-success')+'">'+(dif>0?'+':'')+money(dif)+'</span>'; return '<tr '+(actual?'class="table-warning"':'')+'><td><input class="form-check-input oum-radio" type="radio" name="oum_sel" value="'+esc(x.uuid)+'" data-dif="'+dif+'" data-total="'+Number(x.total_xml||0)+'" '+(actual?'checked':'')+'></td><td>'+esc(String(x.fecha_emision||'').substring(0,10))+'</td><td>'+esc(x.rfc)+'</td><td>'+esc(x.emisor)+'</td><td><span class="badge bg-success">'+esc(x.uuid)+'</span></td><td>'+esc(sf)+'</td><td class="text-end">'+money(x.total_xml)+'</td><td class="text-end">'+money(x.importe_oracle||d.importe||0)+'</td><td class="text-end">'+difHtml+'</td><td>'+esc(x.moneda)+'</td><td>'+esc(x.metodo_pago)+'</td><td>'+esc(x.estatus_sat)+'</td></tr>';}).join(''));
               $('.oum-radio').on('change',function(){elegido=this.value;$('#oum_guardar').prop('disabled',!elegido);});
               const chk=$('.oum-radio:checked'); if(chk.length){elegido=chk.val();$('#oum_guardar').prop('disabled',false);}
            }).fail(function(xhr){$('#oum_rows').html('<tr><td colspan="12" class="text-center text-danger">'+esc((xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible buscar.')+'</td></tr>');});
         }
         $('#oum_buscar').on('click',buscar);
         $('#oum_guardar').on('click',function(){
            if(!elegido)return;
            const $sel=$('.oum-radio:checked'); const dif=Number($sel.data('dif')||0); const totalXml=Number($sel.data('total')||0);
            // Guardar estos valores ANTES de abrir otra alerta SweetAlert.
            // La alerta de diferencia reemplaza temporalmente el DOM del buscador.
            const rfcSeleccionado=String($('#oum_rfc').val()||'').trim().toUpperCase();
            const uuidSeleccionado=String(elegido||'').trim();
            if(!rfcSeleccionado){
               Swal.fire('RFC requerido','Capture o confirme el RFC antes de ligar el CFDI.','warning')
                  .then(()=>modalBuscarUuid(r,tipo));
               return;
            }
            const enviar=function(aceptar){
               const payload={accion:'guardar',tipo_documento:tipo,id_documento:r.id,uuid:uuidSeleccionado,rfc:rfcSeleccionado,aceptar_diferencia:aceptar?1:0};
               $('#oum_guardar').prop('disabled',true).text('Ligando y conciliando...');
               $.post('ajax/oracle_uuid_manual.php',payload,function(z){
                  if(z.confirmar_diferencia){
                     $('#oum_guardar').prop('disabled',false).html('<i class="bi bi-link-45deg"></i> '+(editando?'CAMBIAR UUID Y RECONCILIAR':'LIGAR UUID Y CONCILIAR'));
                     Swal.fire({title:'El importe no coincide',html:'<b>Oracle:</b> '+money(z.importe_oracle||d.importe||0)+'<br><b>CFDI:</b> '+money(z.importe_xml||totalXml)+'<br><b>Diferencia:</b> <span class="text-danger fw-bold">'+((Number(z.diferencia||dif)>0)?'+':'')+money(z.diferencia||dif)+'</span><br><br>¿Deseas ligar este UUID de todas formas? La conciliación conservará la diferencia.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, ligar con diferencia',cancelButtonText:'Cancelar'}).then(q=>{if(q.isConfirmed)enviar(true);});
                     return;
                  }
                  if(!z.success)throw new Error(z.error||'No fue posible ligar.');
                  Swal.close();Swal.fire(editando?'Liga actualizada':'UUID ligado',(editando?'La liga manual fue reemplazada y quedó protegida. Estado: ':'La liga manual quedó protegida y la conciliación resultó: ')+String(z.estado||'PENDIENTE').replaceAll('_',' '),'success').then(()=>cargar());
               },'json').fail(function(xhr){Swal.fire('Liga UUID',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible guardar la liga.','error').then(()=>modalBuscarUuid(r,tipo));});
            };
            if(Math.abs(dif)>=0.01){
               Swal.fire({title:'CFDI con diferencia de importe',html:'<b>Oracle:</b> '+money(d.importe||0)+'<br><b>CFDI:</b> '+money(totalXml)+'<br><b>Diferencia:</b> <span class="text-danger fw-bold">'+(dif>0?'+':'')+money(dif)+'</span><br><br>¿Deseas usar este UUID?',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, ligar con diferencia',cancelButtonText:'Cancelar'}).then(q=>{if(q.isConfirmed)enviar(true);});
            }else enviar(false);
         });
         $('#oum_quitar').on('click',function(){Swal.fire({title:'¿Quitar UUID / liga manual?',html:'Se quitará únicamente la liga manual con el UUID:<br><b class="font-monospace">'+esc(manual.uuid||'')+'</b><br><br>El documento volverá a conciliación automática. <b>No se borra el CFDI ni el pago.</b>',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Sí, quitar liga',cancelButtonText:'Cancelar',reverseButtons:true}).then(function(z){if(!z.isConfirmed)return;let $b=$('#oum_quitar');$b.prop('disabled',true).text('Quitando liga...');$.post('ajax/oracle_uuid_manual.php',{accion:'quitar',tipo_documento:tipo,id_documento:r.id},function(x){if(x.success){Swal.close();Swal.fire('Liga eliminada','Se quitó la liga manual. El documento volverá a evaluarse por la conciliación automática.','success').then(()=>cargar());}else{Swal.fire('Liga UUID',x.error||'Error','error').then(()=>modalBuscarUuid(r,tipo));}},'json').fail(function(xhr){Swal.fire('Liga UUID',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible quitar la liga.','error').then(()=>modalBuscarUuid(r,tipo));});});});
         if($('#oum_rfc').val()) buscar();
      }});
   }).fail(function(xhr){Swal.fire('Buscar UUID',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible cargar el documento.','error');});
 }
 // Abrir búsqueda UUID por ID, independiente del redraw/paginación de DataTables.
 window.oracleBuscarUuidManual=function(tipo,idDocumento){
    if(!idDocumento){ Swal.fire('Buscar UUID','No se recibió el documento de Oracle.','warning'); return; }
    modalBuscarUuid({id:idDocumento},tipo);
 };

 function modalReglas(){
   $.getJSON('ajax/oracle_reglas_clasificacion.php',{accion:'listar'}).done(function(r){
     if(!r.success)return Swal.fire('Reglas',r.error||'No fue posible cargar.','error');
     let rows=(r.data||[]).map(x=>'<tr><td>'+esc(x.patron)+'</td><td>'+esc(x.campo_busqueda)+'</td><td>'+esc(x.tipo_coincidencia)+'</td><td>'+esc(x.estatus_asignado)+'</td><td>'+esc(x.alcance)+'</td><td>'+esc(x.prioridad)+'</td><td>'+(Number(x.activo)?'<span class="badge bg-success">ACTIVA</span>':'<span class="badge bg-secondary">INACTIVA</span>')+'</td><td><button class="btn btn-sm btn-outline-primary ocr-edit" data-id="'+x.id+'">Editar</button> <button class="btn btn-sm btn-outline-warning ocr-toggle" data-id="'+x.id+'">'+(Number(x.activo)?'Desactivar':'Activar')+'</button> <button class="btn btn-sm btn-outline-danger ocr-del" data-id="'+x.id+'">Borrar</button></td></tr>').join('');
     let html='<div class="text-start"><button id="ocrNueva" class="btn btn-success btn-sm mb-2"><i class="bi bi-plus-circle"></i> Nueva regla</button><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Palabra/frase</th><th>Campo</th><th>Coincidencia</th><th>Estatus</th><th>Alcance</th><th>Prioridad</th><th>Activo</th><th>Acciones</th></tr></thead><tbody>'+rows+'</tbody></table></div></div>';
     Swal.fire({title:'Reglas automáticas sin UUID',html:html,width:'1200px',confirmButtonText:'Cerrar',didOpen:function(){
       $('#ocrNueva').on('click',()=>editarRegla(null));
       $('.ocr-edit').on('click',function(){const id=$(this).data('id');editarRegla((r.data||[]).find(x=>Number(x.id)===Number(id)));});
       $('.ocr-toggle').on('click',function(){accionRegla('toggle',$(this).data('id'));});
       $('.ocr-del').on('click',function(){const id=$(this).data('id');Swal.fire({title:'¿Borrar regla?',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, borrar'}).then(z=>{if(z.isConfirmed)accionRegla('borrar',id);});});
     }});
   });
 }
 function accionRegla(accion,id){$.post('ajax/oracle_reglas_clasificacion.php',{accion:accion,id:id},function(r){if(r.success)modalReglas();else Swal.fire('Reglas',r.error||'Error','error');},'json');}
 function editarRegla(x){x=x||{}; const html='<div class="text-start"><label class="form-label">Palabra/frase</label><input id="ocr_pat" class="form-control form-control-sm" value="'+esc(x.patron||'')+'"><div class="row g-2 mt-1"><div class="col"><label class="form-label">Campo</label><select id="ocr_campo" class="form-select form-select-sm"><option>CONCEPTO</option><option>DOCUMENTO</option><option>PROVEEDOR</option><option>TODOS</option></select></div><div class="col"><label class="form-label">Coincidencia</label><select id="ocr_modo" class="form-select form-select-sm"><option>CONTIENE</option><option>INICIA</option><option>EXACTO</option></select></div><div class="col"><label class="form-label">Estatus</label><select id="ocr_est" class="form-select form-select-sm"><option>PENSION</option><option>DEMANDA</option><option>NO_CONSIDERAR</option><option>MANUAL</option></select></div><div class="col"><label class="form-label">Prioridad</label><input id="ocr_prio" type="number" class="form-control form-control-sm" value="'+esc(x.prioridad||100)+'"></div></div><div class="form-check mt-2"><input id="ocr_global" class="form-check-input" type="checkbox" '+(x.alcance==='GLOBAL'?'checked':'')+'><label class="form-check-label">Global para todas las empresas</label></div></div>';
   Swal.fire({title:(x.id?'Editar':'Nueva')+' regla',html:html,showCancelButton:true,confirmButtonText:'Guardar',didOpen:function(){$('#ocr_campo').val(x.campo_busqueda||'TODOS');$('#ocr_modo').val(x.tipo_coincidencia||'CONTIENE');$('#ocr_est').val(x.estatus_asignado||'NO_CONSIDERAR');},preConfirm:function(){return {accion:'guardar',id:x.id||0,patron:$('#ocr_pat').val(),campo_busqueda:$('#ocr_campo').val(),tipo_coincidencia:$('#ocr_modo').val(),estatus:$('#ocr_est').val(),prioridad:$('#ocr_prio').val(),global:$('#ocr_global').is(':checked')?1:0};}}).then(z=>{if(!z.isConfirmed)return;$.post('ajax/oracle_reglas_clasificacion.php',z.value,function(r){if(r.success)modalReglas();else Swal.fire('Reglas',r.error||'Error','error');},'json');});
 }

 function verConciliacionOracle(invoiceId){
   $.getJSON('ajax/conciliacion_financiera_detalle.php',{invoice_id:invoiceId}).done(function(r){
      if(!r.success) throw new Error(r.error||'No fue posible consultar la conciliación.');
      Swal.fire({title:'Conciliación financiera',html:htmlConciliacion(r.data||{}),width:'1200px',confirmButtonText:'Cerrar'});
   }).fail(function(xhr){Swal.fire('Conciliación',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible consultar la conciliación.','error');});
 }
 function verConciliacionUuid(uuid){
   if(!uuid)return;
   $.getJSON('ajax/conciliacion_financiera_detalle.php',{uuid:uuid}).done(function(r){
      if(!r.success) throw new Error(r.error||'No fue posible consultar la conciliación.');
      Swal.fire({title:'Conciliación financiera',html:htmlConciliacion(r.data||{}),width:'1200px',confirmButtonText:'Cerrar'});
   }).fail(function(xhr){Swal.fire('Conciliación',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible consultar la conciliación.','error');});
 }

 function formatoDetalleReposicion(rows,padre){
   padre=padre||{};
   let h='<div class="oracle-detalle-wrap">'+
         '<div class="fw-bold mb-2"><i class="bi bi-diagram-3 me-1"></i>Detalle de la reposición ('+rows.length+' movimientos)</div>'+
         '<div class="table-responsive"><table class="table table-sm table-bordered oracle-detalle-tabla">'+
         '<thead><tr><th></th><th>InvoiceId</th><th>Factura</th><th>Fecha</th><th>Proveedor</th><th>RFC</th><th>Importe</th><th>Moneda</th><th>Estatus factura</th><th>Fecha cancelación</th><th>Estado pago</th><th>Fecha pago</th><th>No. pago</th><th>Concepto cheque / pago</th><th>Conciliado banco</th><th>Fecha conciliación</th><th>Fecha valor</th><th># pagos</th><th>UUID</th><th>Diferencia $</th><th>Conciliación</th></tr></thead><tbody>';
   if(!rows.length){
      h+='<tr><td colspan="21" class="text-center text-muted">No se encontraron movimientos para esta reposición.</td></tr>';
   }else{
      rows.forEach(function(x){
        h+='<tr>'+
          '<td class="text-center"><i class="bi bi-arrow-return-right text-info"></i></td>'+
          '<td>'+esc(x.oracle_invoice_id)+'</td>'+
          '<td class="fw-semibold">'+esc(x.invoice_number)+'</td>'+
          '<td>'+esc(x.invoice_date)+'</td>'+
          '<td>'+esc(x.supplier)+'</td>'+
          '<td>'+esc(x.supplier_tax_registration_number)+'</td>'+
          '<td class="text-end">'+money(x.invoice_amount)+'</td>'+
          '<td>'+esc(x.invoice_currency)+'</td>'+
          '<td><span class="badge bg-info text-dark">'+esc(x.estatus_factura||'COMPROBANTE')+'</span></td>'+
          '<td>'+esc(x.canceled_date||'')+'</td>'+
          '<td>'+esc(padre.payment_void_date?'ANULADO':(padre.payment_status||padre.paid_status||'INCLUIDO EN REPOSICIÓN'))+'</td>'+
          '<td>'+esc(padre.payment_date||'')+'</td>'+
          '<td>'+esc(padre.payment_number||padre.payment_reference||'')+'</td>'+
          '<td class="oracle-concepto-cell"><span class="oracle-concepto-text" title="'+esc(padre.payment_description||'')+'">'+esc(padre.payment_description||'—')+'</span></td>'+
          '<td class="text-center">'+(Number(padre.payment_reconciled_flag)===1?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>')+'</td>'+
          '<td>'+esc(padre.payment_clearing_date||'')+'</td>'+
          '<td>'+esc(padre.payment_clearing_value_date||'')+'</td>'+
          '<td class="text-center">'+esc(padre.payment_count||0)+'</td>'+
          '<td>'+(x.uuid_cfdi?'<span class="badge bg-success">'+esc(x.uuid_cfdi)+'</span>':'<span class="text-muted">Sin UUID</span>')+'</td>'+
          '<td class="text-end">'+diferenciaHtml(x.diferencia_importe,x.estatus_conciliacion)+'</td>'+
          '<td>'+badgeConciliacion(x.estatus_conciliacion||'PENDIENTE',x.uuid_cfdi?'oracle-conc-uuid':'')+'</td>'+
          '</tr>';
      });
   }
   h+='</tbody></table></div>'+
      '<div class="small text-muted mt-2">La fila EXP es el encabezado de la reposición. Los comprobantes desplegados heredan los datos de pago y conciliación de la reposición EXP, porque fueron pagados mediante ese encabezado; conservan su propio UUID, RFC, factura e importe para conciliar contra XML.</div>'+
      '</div>';
   return h;
 }

 function cargar(){
   const p=periodoFiltro(), r=rangoFiltro();
   if(tf){tf.destroy();$('#tablaOracleFacturas tbody').empty();}
   if(tg){tg.destroy();$('#tablaOracleGastos tbody').empty();}

   tf=$('#tablaOracleFacturas').DataTable({
     ajax:{url:'ajax/listar_oracle_pagos.php',data:{tipo:'facturas',anio:p.anio,mes:p.mes,desde:r.desde,hasta:r.hasta,estatus_conciliacion:estatusConciliacion()},dataSrc:r=>r.data||[]},
     pageLength:25,order:[[3,'desc']],
     scrollX:true,scrollY:'46vh',scrollCollapse:true,autoWidth:false,deferRender:true,
     columns:[
       {data:null,orderable:false,searchable:false,className:'oracle-exp-control',render:function(d,t,r){
          if(Number(r.es_reposicion)!==1)return '';
          const n=Number(r.detalle_count||0);
          return '<button type="button" class="oracle-exp-btn" title="Ver detalle de la reposición" '+(n===0?'disabled':'')+'>'+
                 '<i class="bi '+(n===0?'bi-dash-circle':'bi-plus-square-fill')+'"></i></button>';
       }},
       {data:'oracle_invoice_id'},
       {data:'invoice_number',render:function(v,t,r){
          if(Number(r.es_reposicion)===1){
             return '<span class="fw-bold">'+esc(v)+'</span> <span class="badge bg-warning text-dark ms-1">REPOSICIÓN</span>';
          }
          return esc(v);
       }},
       {data:'invoice_date'},{data:'supplier'},
       {data:'supplier_tax_registration_number'},
       {data:'invoice_amount',className:'text-end',render:v=>money(v)},
       {data:'invoice_currency'},
       {data:'estatus_factura',render:function(v,t,r){
          if(Number(r.es_reposicion)===1)return '<span class="badge bg-warning text-dark">REPOSICIÓN</span>';
          return Number(r.canceled_flag)===1?'<span class="badge bg-danger">CANCELADA</span>':'<span class="badge bg-success">VIGENTE</span>';
       }},
       {data:'canceled_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_status',render:function(v,t,r){
          const texto=v||r.paid_status||'';
          if(r.payment_void_date)return '<span class="badge bg-danger">ANULADO</span>';
          return esc(texto);
       }},
       {data:'payment_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_number',render:function(v,t,r){
          let z=v||r.payment_reference||'';
          if(!z)return '<span class="text-muted">—</span>';
          return esc(z)+(Number(r.payment_count||0)>1?' <span class="badge bg-secondary">'+esc(r.payment_count)+'</span>':'');
       }},
       {data:'payment_description',className:'oracle-concepto-cell',render:function(v){
          const z=String(v||'').trim();
          return z?'<span class="oracle-concepto-text" title="'+esc(z)+'">'+esc(z)+'</span>':'<span class="text-muted">—</span>';
       }},
       {data:'payment_reconciled_flag',className:'text-center',render:function(v){
          return Number(v)===1?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>';
       }},
       {data:'payment_clearing_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_clearing_value_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_count',className:'text-center',render:function(v,t,r){ const n=Number(v||0); return n>0?'<button type="button" class="btn btn-link btn-sm p-0 oracle-pagos-btn" data-invoice="'+esc(r.oracle_invoice_id)+'">'+n+'</button>':'0'; }},
       {data:'uuid_cfdi',render:function(v,t,r){
          if(Number(r.es_reposicion)===1)return '<span class="text-muted">No aplica</span>';
          const u=uuidEfectivo(r); if(!u)return '<span class="text-muted">Sin UUID</span>';
          return r.uuid_manual?'<span class="badge bg-primary">'+esc(u)+'</span> <span class="badge bg-info text-dark">MANUAL</span>':'<span class="badge bg-success">'+esc(u)+'</span>';
       }},
       {data:'diferencia_importe',className:'text-end',render:function(v,t,r){return diferenciaHtml(v,r.estatus_conciliacion);}},
       {data:'estatus_conciliacion',className:'text-center text-nowrap oracle-acciones-cell',render:function(v,t,r){return '<span class="oracle-estado-inline">'+badgeConciliacion(v||'PENDIENTE','oracle-conc-invoice')+'</span>'+botonesClasificacion(r,'FACTURA');}}
     ],
     createdRow:function(row,data){
       if(Number(data.es_reposicion)===1)$(row).addClass('oracle-reposicion');
     },
     initComplete:function(){ const api=this.api(); setTimeout(function(){api.columns.adjust();},0); },
     drawCallback:function(){ const api=this.api(); setTimeout(function(){api.columns.adjust();},0); }
   });

   $('#tablaOracleFacturas tbody').off('click.oracleConc').on('click.oracleConc','button.oracle-conc-invoice',function(e){
      e.stopPropagation(); const d=tf.row($(this).closest('tr')).data(); if(d)verConciliacionOracle(d.oracle_invoice_id);
   });
   $('#tablaOracleFacturas tbody').off('click.oracleConcUuid').on('click.oracleConcUuid','button.oracle-conc-uuid',function(e){
      e.stopPropagation(); const uuid=$(this).closest('tr').find('td').eq(18).text().trim(); if(uuid&&uuid!=='Sin UUID')verConciliacionUuid(uuid);
   });
   $('#tablaOracleGastos tbody').off('click.oracleConcGasto').on('click.oracleConcGasto','button.oracle-conc-gasto',function(e){
      e.stopPropagation(); const d=tg.row($(this).closest('tr')).data(); if(d&&uuidEfectivo(d))verConciliacionUuid(uuidEfectivo(d));
   });

   $('#tablaOracleFacturas tbody').off('click.oraclePagos').on('click.oraclePagos','button.oracle-pagos-btn',function(e){
      e.stopPropagation();
      const invoiceId=$(this).data('invoice');
      $.getJSON('ajax/oracle_pagos_detalle_pagos.php',{invoice_id:invoiceId})
       .done(function(r){
          if(!r.success)throw new Error(r.error||'No fue posible consultar pagos.');
          let h='<div class="table-responsive"><table class="table table-sm table-bordered text-start align-middle"><thead><tr><th>Pago</th><th>Fecha</th><th>Concepto cheque / pago</th><th>Beneficiario</th><th>Importe aplicado</th><th>Moneda</th><th>Estado</th><th>Conciliado</th><th>Fecha conciliación</th><th>Fecha valor</th></tr></thead><tbody>';
          (r.data||[]).forEach(function(x){
             h+='<tr><td>'+esc(x.payment_number||x.payment_reference||x.check_id)+'</td><td>'+esc(x.payment_date||'')+'</td><td style="min-width:240px;white-space:normal">'+esc(x.payment_description||'—')+'</td><td>'+esc(x.payee||'')+'</td><td class="text-end">'+money(x.invoice_payment_amount||x.payment_amount)+'</td><td>'+esc(x.payment_currency||'')+'</td><td>'+esc(x.payment_status||x.invoice_payment_status||'')+'</td><td>'+(Number(x.reconciled_flag)===1?'SÍ':'NO')+'</td><td>'+esc(x.clearing_date||'')+'</td><td>'+esc(x.clearing_value_date||'')+'</td></tr>';
          });
          h+='</tbody></table></div>';
          Swal.fire({title:'Pagos Oracle de la factura',html:h,width:'1000px',confirmButtonText:'Cerrar'});
       })
       .fail(function(xhr){
          const msg=(xhr.responseJSON&&xhr.responseJSON.error)?xhr.responseJSON.error:'No fue posible consultar los pagos.';
          Swal.fire('Pagos Oracle',msg,'error');
       });
   });

   $('#tablaOracleFacturas tbody').off('click.oracleExp').on('click.oracleExp','button.oracle-exp-btn:not(:disabled)',function(){
      const tr=$(this).closest('tr');
      const row=tf.row(tr);
      const d=row.data();
      const btn=$(this);
      if(row.child.isShown()){
         row.child.hide();tr.removeClass('shown');
         btn.find('i').removeClass('bi-dash-square-fill').addClass('bi-plus-square-fill');
         return;
      }

      btn.prop('disabled',true).find('i').removeClass().addClass('bi bi-hourglass-split');
      $.getJSON('ajax/oracle_pagos_detalle_reposicion.php',{factura:d.invoice_number})
       .done(function(r){
          if(!r.success)throw new Error(r.error||'No fue posible consultar el detalle.');
          row.child(formatoDetalleReposicion(r.data||[],d)).show();
          tr.addClass('shown');
          btn.find('i').removeClass().addClass('bi bi-dash-square-fill');
       })
       .fail(function(xhr){
          const msg=(xhr.responseJSON&&xhr.responseJSON.error)?xhr.responseJSON.error:'No fue posible consultar el detalle de la reposición.';
          Swal.fire('Detalle de reposición',msg,'error');
          btn.find('i').removeClass().addClass('bi bi-plus-square-fill');
       })
       .always(function(){btn.prop('disabled',false);});
   });

   tg=$('#tablaOracleGastos').DataTable({
     ajax:{url:'ajax/listar_oracle_pagos.php',data:{tipo:'gastos',anio:p.anio,mes:p.mes,desde:r.desde,hasta:r.hasta,estatus_conciliacion:estatusConciliacion()},dataSrc:r=>r.data||[]},
     pageLength:25,order:[[2,'desc']],
     scrollX:true,scrollY:'46vh',scrollCollapse:true,autoWidth:false,deferRender:true,
     columns:[
       {data:'oracle_expense_report_id'},{data:'oracle_expense_id'},{data:'creation_date'},{data:'person_name'},
       {data:'merchant_name'},{data:'expense_type'},
       {data:'receipt_amount',className:'text-end',render:v=>money(v)},
       {data:'receipt_currency_code'},{data:'expense_report_status'},
       {data:'uuid_cfdi',render:function(v,t,r){const u=uuidEfectivo(r);if(!u)return '<span class="text-muted">Sin UUID</span>';return r.uuid_manual?'<span class="badge bg-primary">'+esc(u)+'</span> <span class="badge bg-info text-dark">MANUAL</span>':'<span class="badge bg-success">'+esc(u)+'</span>'; }},
       {data:'diferencia_importe',className:'text-end',render:function(v,t,r){return diferenciaHtml(v,r.estatus_conciliacion);}},
       {data:'estatus_conciliacion',className:'text-center',render:function(v,t,r){return badgeConciliacion(v||'PENDIENTE',uuidEfectivo(r)?'oracle-conc-gasto':'')+botonesClasificacion(r,'EXP_DETALLE');}}
     ],
     initComplete:function(){ const api=this.api(); setTimeout(function(){api.columns.adjust();},0); },
     drawCallback:function(){ const api=this.api(); setTimeout(function(){api.columns.adjust();},0); }
   });
 }

 function filaOracleDesdeBoton(b,tipo){
    const tr=b.closest('tr');
    let d=(tipo==='FACTURA'&&tf)?tf.row(tr).data():((tipo!=='FACTURA'&&tg)?tg.row(tr).data():null);
    // Si DataTables/Responsive colocó el botón en una fila hija, tomar la fila padre.
    if(!d && tr.hasClass('child')){
       const prev=tr.prev('tr');
       d=(tipo==='FACTURA'&&tf)?tf.row(prev).data():((tipo!=='FACTURA'&&tg)?tg.row(prev).data():null);
    }
    return d||null;
 }

 $(document).off('click.oracleClas','button.oracle-clasificar').on('click.oracleClas','button.oracle-clasificar',function(e){
    e.preventDefault();e.stopPropagation();
    const b=$(this),tipo=b.data('tipo'),d=filaOracleDesdeBoton(b,tipo);
    if(d) modalClasificar(d,tipo);
 });
 $(document).off('click.oracleQClas','button.oracle-quitar-clasificacion').on('click.oracleQClas','button.oracle-quitar-clasificacion',function(e){
    e.preventDefault();e.stopPropagation(); const b=$(this);
    Swal.fire({title:'¿Quitar clasificación?',text:'El documento volverá a PENDIENTE y en la próxima conciliación se evaluará nuevamente.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, quitar'}).then(z=>{if(!z.isConfirmed)return;$.post('ajax/oracle_quitar_clasificacion.php',{tipo_documento:b.data('tipo'),id_documento:b.data('id')},function(r){if(r.success)cargar();else Swal.fire('Clasificación',r.error||'Error','error');},'json');});
 });
 $(document).off('click.oracleBuscaUuid','button.oracle-buscar-uuid').on('click.oracleBuscaUuid','button.oracle-buscar-uuid',function(e){
    e.preventDefault();e.stopPropagation();
    const b=$(this);
    window.oracleBuscarUuidManual(String(b.data('tipo')||'FACTURA'),String(b.data('id')||''));
 });
 $('#btnOracleReglas').off('click.oracleReglas').on('click.oracleReglas',modalReglas);

 function modalMeter(inicio){
   const tf=inicio.total_facturas, tg=inicio.total_gastos;
   const known=(tf!==null&&tg!==null);
   const factKnown=(tf!==null);
   const total=(known?(Number(tf)+Number(tg)):0);

   Swal.fire({
     title:'Importando Oracle Pagos',
     html:
       '<div class="oracle-progress-modal text-start">'+
       '<div id="oracleMeterTexto" class="mb-2">Preparando información...</div>'+
       '<div class="progress mb-2">'+
         '<div id="oracleMeterBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:'+(factKnown?'0%':'100%')+'">'+
           (factKnown?'0%':'Procesando...')+
         '</div>'+
       '</div>'+
       '<div id="oracleMeterConteo" class="small text-muted mb-2">'+
          (known?('0 de '+total+' movimientos'):(factKnown?('0 de '+Number(tf)+' facturas; después se leerá el detalle de reposiciones'):'0 movimientos procesados'))+
       '</div>'+
       '<div class="border rounded bg-light p-2 small">'+
         '<div><b>Facturas procesadas:</b> <span id="oracleMsgFacturas">0</span></div>'+
         '<div><b>Gastos/movimientos encontrados:</b> <span id="oracleMsgGastos">0</span></div>'+
         '<div><b>Total procesado:</b> <span id="oracleMsgTotal">0</span></div>'+
         '<div id="oracleFilaUuid" class="d-none"><b>UUID revisados:</b> <span id="oracleMsgUuid">0</span></div>'+
         '<div class="text-muted mt-1" id="oracleMsgEstado">Preparando primer lote...</div>'+
         '<div id="oracleMsgErrorReal" class="alert alert-warning py-2 px-2 mt-2 mb-0 d-none" style="white-space:pre-wrap;max-height:170px;overflow:auto"></div>'+
       '</div>'+
       '<div class="text-center mt-3"><button type="button" id="btnCancelarOracleProceso" class="btn btn-danger btn-sm">'+
       '<i class="bi bi-x-circle me-1"></i> Cancelar proceso</button></div>'+
       '<div class="small text-muted mt-2">La cancelación detiene la importación; lo ya procesado queda guardado y una nueva importación lo actualiza sin duplicar.</div>'+
       '</div>',
     showConfirmButton:false,allowOutsideClick:false,allowEscapeKey:false,
     didOpen:function(){
       $('#btnCancelarOracleProceso').on('click',cancelar);
     }
   });
 }

 function actualizarMeter(r){
   const pf=Number(r.procesados_facturas||0), pg=Number(r.procesados_gastos||0), hechos=pf+pg;
   const tf=r.total_facturas, tg=r.total_gastos;
   const known=(tf!==null&&tg!==null);
   const factKnown=(tf!==null);
   const total=known?(Number(tf)+Number(tg)):0;
   const ur=(r.uuid_revisados===null||typeof r.uuid_revisados==='undefined')?null:Number(r.uuid_revisados||0);
   const up=(r.uuid_pendientes===null||typeof r.uuid_pendientes==='undefined')?null:Number(r.uuid_pendientes||0);
   const ut=(ur!==null&&up!==null)?(ur+up):null;
   let pct=null;

   if(r.fase==='UUID_GASTOS' && ut!==null){
      pct=ut>0?Math.min(99,Math.floor((ur/ut)*100)):99;
   }else if(known&&total>0){
      pct=Math.min(100,Math.floor((hechos/total)*100));
   }else if(r.fase==='FACTURAS'&&factKnown&&Number(tf)>0){
      pct=Math.min(100,Math.floor((pf/Number(tf))*100));
   }

   $('#oracleMeterTexto').text(
     r.fase==='FACTURAS' ? 'Leyendo facturas pagadas de Oracle...' :
     r.fase==='GASTOS' ? 'Leyendo detalle de reposiciones / gastos...' :
     r.fase==='UUID_GASTOS' ? 'Leyendo UUID de comprobantes de gastos...' :
     'Terminando...'
   );

   if(r.fase==='UUID_GASTOS' && ur!==null && up!==null){
      $('#oracleMeterConteo').text('UUID revisados: '+ur+' · Pendientes: '+up+' · Gastos: '+pg);
   }else if(known){
      $('#oracleMeterConteo').text(hechos+' de '+total+' movimientos');
   }else if(r.fase==='FACTURAS'&&factKnown){
      $('#oracleMeterConteo').text(pf+' de '+Number(tf)+' facturas');
   }else if(r.fase==='GASTOS'||r.fase==='UUID_GASTOS'){
      $('#oracleMeterConteo').text('Facturas: '+pf+' · Gastos/movimientos encontrados: '+pg);
   }else{
      $('#oracleMeterConteo').text(hechos+' movimientos procesados');
   }

   $('#oracleMsgFacturas').text(pf+(tf!==null?' / '+Number(tf):''));
   $('#oracleMsgGastos').text(pg+(tg!==null?' / '+Number(tg):''));
   $('#oracleMsgTotal').text(hechos);
   if(r.fase==='UUID_GASTOS' && ur!==null && up!==null){
      $('#oracleFilaUuid').removeClass('d-none');
      $('#oracleMsgUuid').text(ur+' revisados · '+up+' pendientes');
   }else{
      $('#oracleFilaUuid').addClass('d-none');
   }
   $('#oracleMsgEstado').text(
      r.fase==='FACTURAS' ? 'Oracle está leyendo y guardando facturas del rango seleccionado.' :
      r.fase==='GASTOS' ? 'Oracle está leyendo el detalle de reposiciones/gastos por bloques cortos.' :
      r.fase==='UUID_GASTOS' ? 'Oracle está completando los UUID de los comprobantes en bloques pequeños para evitar timeouts.' :
      'Cerrando la importación y actualizando totales.'
   );

   const avisos=[];
   if(Array.isArray(r.errores) && r.errores.length) avisos.push(...r.errores);
   if(r.ultimo_error && !avisos.includes(r.ultimo_error)) avisos.push(r.ultimo_error);
   if(avisos.length){
      $('#oracleMsgErrorReal').removeClass('d-none').html('<b>Detalle devuelto por Oracle:</b>\n'+esc(avisos.slice(-8).join('\n')));
   }

   if(pct!==null){
      $('#oracleMeterBar').css('width',pct+'%').text(pct+'%');
   }else{
      $('#oracleMeterBar').css('width','100%').text('Procesando detalle...');
   }
 }

 function cancelar(){
   if(!jobActual||cancelando)return;
   cancelando=true;
   $('#btnCancelarOracleProceso').prop('disabled',true).text('Cancelando...');
   $.post('ajax/oracle_pagos_cancelar.php',{job:jobActual},function(){},'json');
 }

 function mostrarErroresOracle(r){
   const errs=Array.isArray(r.errores)?r.errores.filter(Boolean):[];
   if(!errs.length)return false;
   Swal.fire({
      icon:'warning',
      title:'Oracle devolvió un error',
      width:980,
      html:'<div class="text-start small">'+
           '<div class="mb-2">El proceso puede continuar. Se muestra el error real y la consulta/URL involucrada para diagnóstico.</div>'+
           '<pre class="border rounded p-2 bg-light" style="white-space:pre-wrap;max-height:430px;overflow:auto">'+esc(errs.join('\n\n'))+'</pre>'+
           '</div>',
      showCancelButton:true,
      confirmButtonText:'Continuar',
      cancelButtonText:'Cancelar proceso',
      allowOutsideClick:false,
      allowEscapeKey:false
   }).then(function(x){
      if(!x.isConfirmed){
         cancelar();
         return;
      }
      modalMeter(r);
      actualizarMeter(r);
      setTimeout(siguiente,80);
   });
   return true;
 }

 function siguiente(){
   if(!jobActual)return;
   xhrActual=$.post('ajax/oracle_pagos_procesar.php',{job:jobActual},function(r){
      if(!r.success){
         Swal.fire('Error',r.error||'No fue posible continuar la importación.','error');
         jobActual=null;return;
      }
      actualizarMeter(r);

      if(r.cancelado||r.estado==='CANCELADO'){
         jobActual=null;cancelando=false;
         Swal.fire('Proceso cancelado','La importación Oracle se detuvo. Los registros ya guardados no se duplicarán cuando vuelva a ejecutarla.','info')
           .then(()=>cargar());
         return;
      }
      if(r.terminado||r.estado==='TERMINADO'){
         $('#oracleMeterBar').css('width','100%').text('100%');
         const total=Number(r.procesados_facturas||0)+Number(r.procesados_gastos||0);
         jobActual=null;cancelando=false;
         const detalleError=(r.ultimo_error||((Array.isArray(r.errores)&&r.errores.length)?r.errores.join('\n'):''));
         if(detalleError){
           Swal.fire({
             icon:'warning',title:'Importación terminada con avisos de Oracle',
             html:'Facturas: <b>'+r.procesados_facturas+'</b><br>Gastos/movimientos: <b>'+r.procesados_gastos+'</b><hr>'+
                  '<div class="text-start small"><b>Error/aviso real:</b><br><pre style="white-space:pre-wrap">'+esc(detalleError)+'</pre></div>'
           }).then(()=>cargar());
         }else{
           Swal.fire({
             icon:'success',title:'Importación terminada',
             html:'Facturas: <b>'+r.procesados_facturas+'</b><br>Gastos/movimientos: <b>'+r.procesados_gastos+'</b><br>Total procesado: <b>'+total+'</b>'
           }).then(()=>cargar());
         }
         return;
      }

      // En producción no hay pausas de diagnóstico. Solo se detiene visualmente
      // cuando Oracle devuelve un error real, mostrando también la consulta/URL.
      if(mostrarErroresOracle(r))return;
      setTimeout(siguiente,80);
   },'json').fail(function(xhr){
      let m='No fue posible continuar la importación Oracle.';
      try{
        const r=JSON.parse(xhr.responseText);
        if(r.error)m=r.error;
      }catch(e){
        const raw=(xhr.responseText||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
        if(raw)m+='\nHTTP '+xhr.status+' '+(xhr.statusText||'')+'\nRespuesta servidor: '+raw.substring(0,1800);
        else m+='\nHTTP '+xhr.status+' '+(xhr.statusText||'');
      }
      jobActual=null;cancelando=false;
      Swal.fire({icon:'error',title:'Error Oracle Pagos',width:980,html:'<pre class="text-start" style="white-space:pre-wrap;max-height:420px;overflow:auto">'+esc(m)+'</pre>'});
   });
 }

 $('#btnOracleImportar').off('click.oracle').on('click.oracle',function(){
   if(!validarRangoMesSeleccionado())return;
   const p=periodoImportacion(),mesTxt=$('#oracle_mes option:selected').text();
   Swal.fire({
      title:'Sincronizar Oracle Pagos',
      html:'Se sincronizará <b>'+esc(mesTxt)+' '+p.anio+'</b> del <b>'+esc(p.desde)+'</b> al <b>'+esc(p.hasta)+'</b>.<br><br>El rango no puede salir del mes seleccionado.',
      icon:'question',showCancelButton:true,confirmButtonText:'Sí, sincronizar',cancelButtonText:'Cancelar'
   }).then(x=>{
      if(!x.isConfirmed)return;
      $.post('ajax/oracle_pagos_iniciar.php',p,function(r){
         if(!r.success)return Swal.fire('Error',r.error||'No fue posible iniciar.','error');
         jobActual=r.job;cancelando=false;
         modalMeter(r);
         siguiente();
      },'json').fail(function(xhr){
         let m='No fue posible iniciar Oracle Pagos.';
         try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
         Swal.fire('Error',m,'error');
      });
   });
 });

 let conciliacionJob=null, conciliacionCancelando=false, conciliacionXhr=null;

 function pintarProgresoConciliacion(j){
    const tf=Number(j.total_facturas||0), tg=Number(j.total_gastos||0);
    const pf=Number(j.procesadas_facturas||0), pg=Number(j.procesados_gastos||0);
    const total=tf+tg, hechos=pf+pg, pct=total?Math.min(100,Math.round(hechos*100/total)):100;
    const fase=(j.fase||'').toUpperCase();
    const errores=Number(j.errores||0);
    const html='<div class="text-start small mb-2"><b>Fase:</b> '+esc(fase)+'<br>'+esc('Facturas: '+pf+' / '+tf)+'<br>'+esc('Detalles EXP: '+pg+' / '+tg)+'<br>'+esc('Errores detectados: '+errores)+'</div>'+ 
      '<div class="progress" style="height:20px"><div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:'+pct+'%">'+pct+'%</div></div>'+ 
      '<div class="small text-muted mt-2">Si un registro falla, se conserva el avance y usted decide si continúa o cancela.</div>';
    Swal.update({html:html});
 }

 function abrirModalConciliacion(j){
    Swal.fire({
       title:'Conciliando...',
       html:'Preparando conciliación...',
       allowOutsideClick:false,
       allowEscapeKey:false,
       showConfirmButton:false,
       showCancelButton:true,
       cancelButtonText:'Cancelar proceso',
       didOpen:()=>Swal.showLoading()
    }).then(function(x){
       if(x.dismiss===Swal.DismissReason.cancel) solicitarCancelarConciliacion();
    });
    if(j) setTimeout(()=>pintarProgresoConciliacion(j),0);
 }

 function resumenConciliacionHtml(j){
    let h='Facturas Oracle: <b>'+Number(j.procesadas_facturas||0)+'</b><br>'+ 
          'Detalles EXP: <b>'+Number(j.procesados_gastos||0)+'</b><br>'+ 
          'Conciliados: <b>'+Number(j.conciliados||0)+'</b><br>'+ 
          'Parciales: <b>'+Number(j.parciales||0)+'</b><br>'+ 
          'Diferencias: <b>'+Number(j.diferencias||0)+'</b><br>'+ 
          'Sin XML: <b>'+Number(j.sin_xml||0)+'</b><br>'+ 
          'Sin UUID: <b>'+Number(j.sin_uuid||0)+'</b><br>'+ 
          'Cancelados: <b>'+Number(j.cancelados||0)+'</b><br>'+ 
          'Errores: <b>'+Number(j.errores||0)+'</b>';
    if(Number(j.errores||0)>0 && j.ultimo_error){
       h+='<hr><div class="text-start"><b>Último error registrado:</b><pre style="white-space:pre-wrap;max-height:180px;overflow:auto;margin-top:6px">'+esc(j.ultimo_error)+'</pre></div>';
    }
    return h;
 }

 function terminarConciliacion(j){
    conciliacionJob=null; conciliacionCancelando=false; conciliacionXhr=null;
    const est=(j.estado||'').toUpperCase();
    if(est==='CANCELADO'){
       Swal.fire({icon:'info',title:'Conciliación cancelada',html:'Se conservaron los documentos que ya alcanzaron a procesarse.<br><br>'+resumenConciliacionHtml(j)}).then(()=>cargar());
       return;
    }
    if(est==='ERROR'){
       Swal.fire({icon:'error',title:'Conciliación',html:resumenConciliacionHtml(j)}); return;
    }
    const conErrores=Number(j.errores||0)>0;
    Swal.fire({
       icon:conErrores?'warning':'success',
       title:conErrores?'Conciliación terminada con incidencias':'Conciliación terminada',
       html:resumenConciliacionHtml(j),
       width:720
    }).then(()=>cargar());
 }

 function preguntarContinuarConciliacion(j,errores){
    const lista=(errores||[]).filter(Boolean);
    const detalle=lista.length?lista.join('\n'):(j.ultimo_error||'Se detectó un error durante la conciliación.');
    Swal.fire({
       icon:'warning',
       title:'Se detectó un error',
       width:820,
       html:'<div class="text-start">El avance ya procesado se conserva. Puede continuar con el siguiente registro o cancelar el proceso.</div>'+ 
            '<pre class="text-start mt-3" style="white-space:pre-wrap;max-height:280px;overflow:auto">'+esc(detalle)+'</pre>'+ 
            '<div class="text-start small text-muted">Errores acumulados: '+Number(j.errores||0)+'</div>',
       showCancelButton:true,
       confirmButtonText:'Continuar',
       cancelButtonText:'Cancelar proceso',
       allowOutsideClick:false,
       allowEscapeKey:false
    }).then(function(x){
       if(x.isConfirmed){
          if(!conciliacionJob)return;
          abrirModalConciliacion(j);
          setTimeout(pasoConciliacion,80);
       }else{
          solicitarCancelarConciliacion();
       }
    });
 }

 function pasoConciliacion(){
    if(!conciliacionJob||conciliacionCancelando)return;
    conciliacionXhr=$.post('ajax/conciliar_oracle_pagos_procesar.php',{job:conciliacionJob},function(resp){
       conciliacionXhr=null;
       if(!resp.success){
          return preguntarContinuarConciliacion(resp.job||{},[resp.error||'No fue posible continuar.']);
       }
       const j=resp.job||{};
       pintarProgresoConciliacion(j);
       if(resp.done)return terminarConciliacion(j);
       if(resp.recoverable_error || resp.had_errors){
          return preguntarContinuarConciliacion(j,resp.batch_errors||[resp.error]);
       }
       setTimeout(pasoConciliacion,40);
    },'json').fail(function(xhr,status){
       conciliacionXhr=null;
       if(status==='abort'||conciliacionCancelando)return;
       let m=(xhr.responseJSON&&xhr.responseJSON.error)||'';
       if(!m){
          const raw=(xhr.responseText||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
          m=raw||('Error HTTP '+xhr.status+' '+(xhr.statusText||''));
       }
       // No se pierde el job por un error de transporte/timeout. El usuario decide.
       preguntarContinuarConciliacion({errores:0,ultimo_error:m},[m]);
    });
 }

 function solicitarCancelarConciliacion(){
    if(!conciliacionJob||conciliacionCancelando)return;
    conciliacionCancelando=true;
    const job=conciliacionJob;
    if(conciliacionXhr){try{conciliacionXhr.abort();}catch(e){}}
    Swal.fire({title:'Cancelando...',html:'Esperando a que termine el registro actual.',allowOutsideClick:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
    $.post('ajax/conciliar_oracle_pagos_cancelar.php',{job:job},function(){
       $.post('ajax/conciliar_oracle_pagos_procesar.php',{job:job},function(resp){
          terminarConciliacion((resp&&resp.job)||{estado:'CANCELADO'});
       },'json').fail(function(){
          conciliacionJob=null;conciliacionCancelando=false;
          Swal.fire('Conciliación','Cancelación solicitada. El proceso se detendrá al terminar el registro actual.','info').then(()=>cargar());
       });
    },'json').fail(function(){
       conciliacionCancelando=false;
       Swal.fire({
          icon:'error',title:'Conciliación',
          text:'No fue posible registrar la cancelación. Puede intentar cancelar nuevamente o continuar el proceso.',
          showCancelButton:true,confirmButtonText:'Continuar',cancelButtonText:'Cerrar'
       }).then(function(x){
          if(x.isConfirmed && conciliacionJob){abrirModalConciliacion(null);setTimeout(pasoConciliacion,80);}
       });
    });
 }

 $('#btnOracleConciliar').off('click.oracleConciliar').on('click.oracleConciliar',function(){
   const r=rangoFiltro();
   if(!r.desde||!r.hasta)return Swal.fire('Conciliación','Capture fecha desde y hasta.','warning');
   if(r.desde>r.hasta)return Swal.fire('Conciliación','La fecha desde no puede ser mayor que la fecha hasta.','warning');
   Swal.fire({title:'¿Conciliar pagos Oracle?',html:'Se cruzarán los XML del visor, detalles fiscales <b>PUE/PPD</b>, facturas y reposiciones EXP, pagos Oracle y conciliación bancaria del periodo.<br><br><b>'+esc(r.desde)+' a '+esc(r.hasta)+'</b>',icon:'question',showCancelButton:true,confirmButtonText:'Sí, conciliar',cancelButtonText:'Cancelar'}).then(function(x){
      if(!x.isConfirmed)return;
      $.post('ajax/conciliar_oracle_pagos_iniciar.php',r,function(resp){
         if(!resp.success)return Swal.fire('Conciliación',resp.error||'No fue posible iniciar.','error');
         conciliacionJob=resp.job; conciliacionCancelando=false;
         const inicial={fase:'FACTURAS',total_facturas:resp.total_facturas,total_gastos:resp.total_gastos,procesadas_facturas:0,procesados_gastos:0,errores:0};
         abrirModalConciliacion(inicial);
         pasoConciliacion();
      },'json').fail(function(xhr){Swal.fire('Conciliación',(xhr.responseJSON&&xhr.responseJSON.error)||'No fue posible iniciar la conciliación.','error');});
   });
 });

 function sincronizarFiltroConImportacion(){
   const p=periodo();
   $('#oracle_filtro_anio').val(p.anio);
   $('#oracle_filtro_mes').val(p.mes);
   ponerRangoFiltroMes(p.anio,p.mes,true);
 }

 $('#oracle_mes,#oracle_anio').off('change.oraclePeriodo').on('change.oraclePeriodo',function(){
    const p=periodo();
    ponerRangoMes(p.anio,p.mes,true);
    $('#oracle_filtro_anio').val(p.anio);
    $('#oracle_filtro_mes').val(p.mes);
    ponerRangoFiltroMes(p.anio,p.mes,true);
 });
 $('#oracle_desde,#oracle_hasta').off('change.oracleRango').on('change.oracleRango',function(){
    validarRangoMesSeleccionado();
 });

 $('#oracle_filtro_anio,#oracle_filtro_mes').off('change.oracleFiltro').on('change.oracleFiltro',function(){
    const p=periodoFiltro();
    ponerRangoFiltroMes(p.anio,p.mes,true);
 });
 $('#oracle_filtro_desde,#oracle_filtro_hasta').off('change.oracleFiltroRango').on('change.oracleFiltroRango',function(){
    validarRangoFiltro();
 });
 $('#btnOracleExportar').off('click.oracleExportar').on('click.oracleExportar',function(){
   if(!validarRangoFiltro())return;
   const p=periodoFiltro(),r=rangoFiltro();
   const q=new URLSearchParams({anio:p.anio,mes:p.mes,desde:r.desde||'',hasta:r.hasta||'',estatus_conciliacion:estatusConciliacion()});
   window.location.href='ajax/exportar_oracle_pagos_excel.php?'+q.toString();
 });

 $('#btnOracleFiltrar').off('click.oracleFiltro').on('click.oracleFiltro',function(){
    if(!validarRangoFiltro())return;
    cargar();
 });

 $('button[data-bs-toggle="tab"]').off('shown.bs.tab.oraclePagos').on('shown.bs.tab.oraclePagos',function(){
    setTimeout(function(){if(tf)tf.columns.adjust();if(tg)tg.columns.adjust();},50);
 });
 $(window).off('resize.oraclePagos').on('resize.oraclePagos',function(){
    if(tf)tf.columns.adjust();
    if(tg)tg.columns.adjust();
 });

 sincronizarFiltroConImportacion();
 cargar();
})();
</script>
