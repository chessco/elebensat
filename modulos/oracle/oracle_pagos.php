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
.oracle-progress-modal .progress{height:24px}
.oracle-progress-modal .progress-bar{font-weight:700}
#tablaOracleFacturas,#tablaOracleGastos{font-size:.76rem}
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
</style>

<div class="oracle-wrap animate__animated animate__fadeIn">
    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-cloud-arrow-down-fill text-danger me-2"></i>ORACLE PAGOS</h6>
        <div class="text-muted">Importación mensual de facturas, estatus, pagos, conciliación bancaria y detalle de gastos Oracle.</div>
        <div class="ms-auto small"><b>Última importación:</b> <?=htmlspecialchars((string)($cfg['ultima_sincronizacion']??'Sin información'))?></div>
    </div>

    <?php if(empty($cfg['unidad_negocio'])): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Falta configurar <b>Oracle Pagos</b> para esta empresa en Administración → Empresas.
    </div>
    <?php endif; ?>

    <div class="oracle-head d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
            <label class="form-label mb-1 small">MES A IMPORTAR</label>
            <select id="oracle_mes" class="form-select form-select-sm">
                <?php
                $ms=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                $m=(int)date('n');
                foreach($ms as $n=>$nom)echo '<option value="'.$n.'" '.($n===$m?'selected':'').'>'.$nom.'</option>';
                ?>
            </select>
        </div>
        <div>
            <label class="form-label mb-1 small">AÑO</label>
            <input id="oracle_anio" type="number" class="form-control form-control-sm" min="2000" max="2100" value="<?=date('Y')?>" style="width:100px">
        </div>
        <button id="btnOracleImportar" type="button" class="btn btn-warning btn-sm fw-bold" <?=empty($cfg['unidad_negocio'])?'disabled':''?>>
            <i class="bi bi-cloud-arrow-down me-1"></i> IMPORTAR
        </button>
        <button id="btnOracleVer" type="button" class="btn btn-outline-light btn-sm">
            <i class="bi bi-eye me-1"></i> VER PERIODO
        </button>
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
                        <th>Estado pago</th><th>Fecha pago</th><th>No. pago</th><th>Conciliado banco</th>
                        <th>Fecha conciliación</th><th>Fecha valor</th><th># pagos</th><th>UUID</th>
                    </tr></thead><tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="oracleG">
            <div class="table-responsive">
                <table id="tablaOracleGastos" class="table table-striped table-hover w-100">
                    <thead><tr>
                        <th>ReportId</th><th>ExpenseId</th><th>Fecha</th><th>Persona</th><th>Comercio</th>
                        <th>Tipo</th><th>Importe</th><th>Moneda</th><th>Estado</th><th>UUID</th>
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
 function money(v){return Number(v||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function esc(v){return $('<div>').text(v==null?'':String(v)).html();}

 function formatoDetalleReposicion(rows){
   let h='<div class="oracle-detalle-wrap">'+
         '<div class="fw-bold mb-2"><i class="bi bi-diagram-3 me-1"></i>Detalle de la reposición ('+rows.length+' movimientos)</div>'+
         '<div class="table-responsive"><table class="table table-sm table-bordered oracle-detalle-tabla">'+
         '<thead><tr><th></th><th>InvoiceId</th><th>Factura</th><th>Fecha</th><th>Proveedor</th><th>RFC</th><th>Importe</th><th>Moneda</th><th>Estatus factura</th><th>Fecha cancelación</th><th>Estado pago</th><th>Fecha pago</th><th>No. pago</th><th>Conciliado banco</th><th>Fecha conciliación</th><th>Fecha valor</th><th># pagos</th><th>UUID</th></tr></thead><tbody>';
   if(!rows.length){
      h+='<tr><td colspan="18" class="text-center text-muted">No se encontraron movimientos para esta reposición.</td></tr>';
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
          '<td>'+esc(x.payment_status||'INCLUIDO EN REPOSICIÓN')+'</td>'+
          '<td>'+esc(x.payment_date||'')+'</td>'+
          '<td>'+esc(x.payment_number||'')+'</td>'+
          '<td><span class="text-muted">—</span></td>'+
          '<td>'+esc(x.payment_clearing_date||'')+'</td>'+
          '<td>'+esc(x.payment_clearing_value_date||'')+'</td>'+
          '<td class="text-center">'+esc(x.payment_count||0)+'</td>'+
          '<td>'+(x.uuid_cfdi?'<span class="badge bg-success">'+esc(x.uuid_cfdi)+'</span>':'<span class="text-muted">Sin UUID</span>')+'</td>'+
          '</tr>';
      });
   }
   h+='</tbody></table></div>'+
      '<div class="small text-muted mt-2">La fila EXP es el encabezado de la reposición. Los comprobantes desplegados conservan las mismas columnas para facilitar la conciliación con XML.</div>'+
      '</div>';
   return h;
 }

 function cargar(){
   const p=periodo();
   if(tf){tf.destroy();$('#tablaOracleFacturas tbody').empty();}
   if(tg){tg.destroy();$('#tablaOracleGastos tbody').empty();}

   tf=$('#tablaOracleFacturas').DataTable({
     ajax:{url:'ajax/listar_oracle_pagos.php',data:{tipo:'facturas',anio:p.anio,mes:p.mes},dataSrc:r=>r.data||[]},
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
       {data:'payment_reconciled_flag',className:'text-center',render:function(v){
          return Number(v)===1?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>';
       }},
       {data:'payment_clearing_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_clearing_value_date',render:v=>v?esc(v):'<span class="text-muted">—</span>'},
       {data:'payment_count',className:'text-center',render:function(v,t,r){ const n=Number(v||0); return n>0?'<button type="button" class="btn btn-link btn-sm p-0 oracle-pagos-btn" data-invoice="'+esc(r.oracle_invoice_id)+'">'+n+'</button>':'0'; }},
       {data:'uuid_cfdi',render:function(v,t,r){
          if(Number(r.es_reposicion)===1)return '<span class="text-muted">No aplica</span>';
          return v?'<span class="badge bg-success">'+esc(v)+'</span>':'<span class="text-muted">Sin UUID</span>';
       }}
     ],
     createdRow:function(row,data){
       if(Number(data.es_reposicion)===1)$(row).addClass('oracle-reposicion');
     }
   });

   $('#tablaOracleFacturas tbody').off('click.oraclePagos').on('click.oraclePagos','button.oracle-pagos-btn',function(e){
      e.stopPropagation();
      const invoiceId=$(this).data('invoice');
      $.getJSON('ajax/oracle_pagos_detalle_pagos.php',{invoice_id:invoiceId})
       .done(function(r){
          if(!r.success)throw new Error(r.error||'No fue posible consultar pagos.');
          let h='<div class="table-responsive"><table class="table table-sm table-bordered text-start"><thead><tr><th>Pago</th><th>Fecha</th><th>Importe aplicado</th><th>Moneda</th><th>Estado</th><th>Conciliado</th><th>Fecha conciliación</th><th>Fecha valor</th></tr></thead><tbody>';
          (r.data||[]).forEach(function(x){
             h+='<tr><td>'+esc(x.payment_number||x.payment_reference||x.check_id)+'</td><td>'+esc(x.payment_date||'')+'</td><td class="text-end">'+money(x.invoice_payment_amount||x.payment_amount)+'</td><td>'+esc(x.payment_currency||'')+'</td><td>'+esc(x.payment_status||x.invoice_payment_status||'')+'</td><td>'+(Number(x.reconciled_flag)===1?'SÍ':'NO')+'</td><td>'+esc(x.clearing_date||'')+'</td><td>'+esc(x.clearing_value_date||'')+'</td></tr>';
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
          row.child(formatoDetalleReposicion(r.data||[])).show();
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
     ajax:{url:'ajax/listar_oracle_pagos.php',data:{tipo:'gastos',anio:p.anio,mes:p.mes},dataSrc:r=>r.data||[]},
     pageLength:25,order:[[2,'desc']],
     scrollX:true,scrollY:'46vh',scrollCollapse:true,autoWidth:false,deferRender:true,
     columns:[
       {data:'oracle_expense_report_id'},{data:'oracle_expense_id'},{data:'creation_date'},{data:'person_name'},
       {data:'merchant_name'},{data:'expense_type'},
       {data:'receipt_amount',className:'text-end',render:v=>money(v)},
       {data:'receipt_currency_code'},{data:'expense_report_status'},
       {data:'uuid_cfdi',render:v=>v?'<span class="badge bg-success">'+esc(v)+'</span>':'<span class="text-muted">Sin UUID</span>'}
     ]
   });
 }

 function modalMeter(inicio){
   const tf=inicio.total_facturas, tg=inicio.total_gastos;
   const known=(tf!==null&&tg!==null);
   const total=(known?(Number(tf)+Number(tg)):0);

   Swal.fire({
     title:'Importando Oracle Pagos',
     html:
       '<div class="oracle-progress-modal text-start">'+
       '<div id="oracleMeterTexto" class="mb-2">Preparando información...</div>'+
       '<div class="progress mb-2">'+
         '<div id="oracleMeterBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:'+(known?'0%':'100%')+'">'+
           (known?'0%':'Procesando...')+
         '</div>'+
       '</div>'+
       '<div id="oracleMeterConteo" class="small text-muted">'+
          (known?('0 de '+total+' movimientos'):'0 movimientos procesados')+
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
   const total=known?(Number(tf)+Number(tg)):0;
   let pct=known&&total>0?Math.min(100,Math.floor((hechos/total)*100)):null;

   $('#oracleMeterTexto').text(
     r.fase==='FACTURAS' ? 'Leyendo facturas pagadas de Oracle...' :
     r.fase==='GASTOS' ? 'Leyendo detalle de gastos / pagos...' :
     'Terminando...'
   );
   $('#oracleMeterConteo').text(known?(hechos+' de '+total+' movimientos'):(hechos+' movimientos procesados'));

   if(pct!==null){
      $('#oracleMeterBar').css('width',pct+'%').text(pct+'%');
   }else{
      $('#oracleMeterBar').css('width','100%').text('Procesando...');
   }
 }

 function cancelar(){
   if(!jobActual||cancelando)return;
   cancelando=true;
   $('#btnCancelarOracleProceso').prop('disabled',true).text('Cancelando...');
   $.post('ajax/oracle_pagos_cancelar.php',{job:jobActual},function(){},'json');
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
         Swal.fire({
           icon:'success',title:'Importación terminada',
           html:'Facturas: <b>'+r.procesados_facturas+'</b><br>Gastos/movimientos: <b>'+r.procesados_gastos+'</b><br>Total procesado: <b>'+total+'</b>'
         }).then(()=>cargar());
         return;
      }
      setTimeout(siguiente,80);
   },'json').fail(function(xhr){
      let m='No fue posible continuar la importación Oracle.';
      try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
      jobActual=null;cancelando=false;
      Swal.fire('Error',m,'error');
   });
 }

 $('#btnOracleImportar').off('click.oracle').on('click.oracle',function(){
   const p=periodo(),mesTxt=$('#oracle_mes option:selected').text();
   Swal.fire({
      title:'¿Importar '+mesTxt+' '+p.anio+'?',
      text:'Oracle actualizará los IDs existentes y agregará únicamente los nuevos.',
      icon:'question',showCancelButton:true,confirmButtonText:'Sí, importar',cancelButtonText:'Cancelar'
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

 $('#btnOracleVer').off('click.oracle').on('click.oracle',cargar);
 cargar();
})();
</script>
