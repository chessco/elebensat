<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_cruce_metadata'); } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; exit; }
$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);

$st = $pdo->prepare("SELECT COUNT(*) FROM cfdi_faltantes_sat x LEFT JOIN facturas f ON f.id_empresa=x.id_empresa AND f.uuid=x.uuid WHERE x.id_empresa=? AND x.estatus_recuperacion<>2 AND f.uuid IS NULL");
$st->execute([$idEmpresa]);
$totalFaltantes = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM sat_metadata_cfdi WHERE id_empresa=?");
$st->execute([$idEmpresa]);
$totalMetadata = (int)$st->fetchColumn();
?>
<style>
.cruce-card{background:#fff;border:1px solid #d9e3ee;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.cruce-title{color:#16325c;font-weight:800}.cruce-help{color:#64748b;font-size:.9rem}.uuid-mono{font-family:Consolas,Monaco,monospace;font-size:.82rem}
.cruce-folder{border:1px solid #cbd5e1;background:#f8fafc;color:#334155;font-weight:700;border-radius:8px 8px 0 0;padding:.65rem 1rem}
.cruce-folder.active{background:#1f789d;color:#fff;border-color:#1f789d}.cruce-folder .badge{font-size:.72rem}
#tablaCruceMetadata td{vertical-align:middle}.dataTables_wrapper .dataTables_paginate{padding-top:.6rem}
</style>

<div class="container-fluid">
  <div class="cruce-card p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
      <div>
        <h4 class="cruce-title mb-1"><i class="bi bi-intersect me-2"></i>Cruce Metadata vs CFDI</h4>
        <div class="cruce-help">Compara la metadata SAT contra los CFDI del visor. Los cambios reales de estatus se actualizan y las cancelaciones nuevas continúan usando el módulo de Alertas SAT.</div>
      </div>
      <button type="button" class="btn btn-primary" id="btnEjecutarCruceMetadata"><i class="bi bi-arrow-repeat me-1"></i> Ejecutar cruce</button>
    </div>

    <div class="d-flex flex-wrap gap-1 align-items-end mb-0" role="tablist">
      <button type="button" class="cruce-folder active" data-modo="faltantes">
        <i class="bi bi-folder-x me-1"></i> XML faltantes
        <span class="badge bg-warning text-dark ms-1" id="badgeFaltantes"><?= number_format($totalFaltantes) ?></span>
      </button>
      <button type="button" class="cruce-folder" data-modo="todos">
        <i class="bi bi-folder2-open me-1"></i> Todo metadata
        <span class="badge bg-secondary ms-1"><?= number_format($totalMetadata) ?></span>
      </button>
    </div>

    <div class="border rounded-bottom rounded-end p-3">
      <div class="alert alert-warning py-2 mb-3" id="leyendaCruce">
        <strong>XML faltantes:</strong> UUID presentes en metadata SAT cuyo XML todavía no existe en el visor. No se genera ninguna solicitud SAT desde esta pantalla.
      </div>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="tablaCruceMetadata" style="width:100%">
          <thead><tr>
            <th>UUID</th><th>Tipo</th><th>Fecha</th><th>RFC emisor</th><th>Emisor</th><th>RFC receptor</th><th>Receptor</th><th class="text-end">Monto</th><th>Estatus SAT</th><th>Estado cruce</th><th>Revisado</th>
          </tr></thead>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  let modoActual='faltantes';
  const $tabla=$('#tablaCruceMetadata');

  function esc(v){ return $('<div>').text(v==null?'':String(v)).html(); }
  function crearTabla(){
    if ($.fn.DataTable.isDataTable($tabla[0])) $tabla.DataTable().destroy();
    $tabla.DataTable({
      processing:true,
      serverSide:true,
      pageLength:25,
      lengthMenu:[[25,50,100,250],[25,50,100,250]],
      searchDelay:350,
      scrollX:true,
      order:[],
      ajax:{url:'ajax/cruce_metadata_listado.php',type:'GET',data:function(d){d.modo=modoActual;}},
      language:{processing:'Consultando...',search:'Buscar:',lengthMenu:'Mostrar _MENU_ registros',info:'Mostrando _START_ a _END_ de _TOTAL_ registros',infoEmpty:'Sin registros',infoFiltered:'(filtrado de _MAX_)',zeroRecords:'No se encontraron registros',paginate:{previous:'Anterior',next:'Siguiente'}},
      columns:[
        {data:'uuid',render:v=>'<span class="uuid-mono">'+esc(v)+'</span>'},
        {data:'tipo'}, {data:'fecha'}, {data:'rfc_emisor'}, {data:'emisor'}, {data:'rfc_receptor'}, {data:'receptor'},
        {data:'monto',className:'text-end',render:v=>v===null?'':'$'+Number(v).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})},
        {data:'estatus_sat',render:v=>{const c=String(v||'').toUpperCase()==='CANCELADO';return '<span class="badge '+(c?'bg-danger':'bg-success')+'">'+esc(v)+'</span>'; }},
        {data:'estado_cruce',render:v=>{const falta=String(v)==='FALTA XML';return '<span class="badge '+(falta?'bg-warning text-dark':'bg-success')+'">'+esc(v)+'</span>'; }},
        {data:'detectado'}
      ]
    });
  }

  $('.cruce-folder').off('click').on('click',function(){
    $('.cruce-folder').removeClass('active'); $(this).addClass('active'); modoActual=$(this).data('modo');
    if(modoActual==='faltantes') $('#leyendaCruce').attr('class','alert alert-warning py-2 mb-3').html('<strong>XML faltantes:</strong> UUID presentes en metadata SAT cuyo XML todavía no existe en el visor. No se genera ninguna solicitud SAT desde esta pantalla.');
    else $('#leyendaCruce').attr('class','alert alert-info py-2 mb-3').html('<strong>Todo metadata:</strong> listado completo de UUID encontrados en metadata SAT. La columna <b>Estado cruce</b> indica si el XML ya está en el visor o todavía falta.');
    crearTabla();
  });

  crearTabla();

  $('#btnEjecutarCruceMetadata').off('click').on('click',function(){
    Swal.fire({title:'Cruzar Metadata vs CFDI',text:'Se actualizarán únicamente diferencias reales de estatus y se detectarán CFDI faltantes. No se generarán solicitudes SAT todavía.',icon:'question',showCancelButton:true,confirmButtonText:'Sí, ejecutar cruce',cancelButtonText:'Cancelar'}).then(function(result){
      if(!result.isConfirmed)return;
      Swal.fire({title:'Cruzando metadata...',html:'Comparando UUID y estatus SAT contra el visor.',allowOutsideClick:false,didOpen:function(){Swal.showLoading();}});
      $.ajax({url:'ajax/cruce_metadata_cfdi.php',type:'POST',dataType:'json'}).done(function(r){
        if(!r||r.status!=='ok'){Swal.fire('Error',r&&r.msg?r.msg:'No fue posible ejecutar el cruce.','error');return;}
        $('#badgeFaltantes').text(Number(r.faltantes||0).toLocaleString('es-MX'));
        let texto='Faltantes actuales: '+Number(r.faltantes||0).toLocaleString('es-MX')+'\nEstatus actualizados: '+Number(r.estatus_actualizados||0).toLocaleString('es-MX')+'\nAlertas nuevas: '+Number(r.notificaciones_nuevas||0).toLocaleString('es-MX')+'\nFaltantes resueltos: '+Number(r.faltantes_resueltos||0).toLocaleString('es-MX');
        Swal.fire({icon:'success',title:'Cruce terminado',text:texto}).then(function(){crearTabla();if(typeof actualizarContadorNotificaciones==='function')actualizarContadorNotificaciones();});
      }).fail(function(xhr){let msg='Error al ejecutar el cruce.';try{const r=JSON.parse(xhr.responseText||'{}');if(r.msg)msg=r.msg;}catch(e){}Swal.fire('Error',msg,'error');});
    });
  });
})();
</script>
