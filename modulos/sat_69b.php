<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69b'); } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; exit; }
if (empty($_SESSION['id_usuario']) || empty($_SESSION['es_superadmin'])) { http_response_code(403); exit('Sin permiso'); }
?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-exclamation"></i> Contribuyentes Artículo 69-B SAT</h4>
            <small class="text-muted">Listado completo oficial del SAT. Consulta de presuntos, definitivos, desvirtuados y sentencias favorables.</small>
        </div>
        <div>
            <button id="btnSincronizar69B" class="btn btn-danger">
                <i class="bi bi-cloud-download"></i> Sincronizar listado SAT
            </button>
        </div>
    </div>

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle"></i>
        Fuente oficial: <strong>Listado completo del artículo 69-B del CFF</strong>. La sincronización reemplaza el catálogo local únicamente cuando la descarga y lectura terminan correctamente.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Total</div><div id="sat69bTotal" class="fs-4 fw-bold">—</div></div></div></div>
        <div class="col-6 col-lg"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Definitivos</div><div id="sat69bDef" class="fs-4 fw-bold text-danger">—</div></div></div></div>
        <div class="col-6 col-lg"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Presuntos</div><div id="sat69bPre" class="fs-4 fw-bold text-warning">—</div></div></div></div>
        <div class="col-6 col-lg"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Desvirtuados</div><div id="sat69bDes" class="fs-4 fw-bold text-success">—</div></div></div></div>
        <div class="col-6 col-lg"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3"><div class="small text-muted">Sentencia favorable</div><div id="sat69bSen" class="fs-4 fw-bold text-info">—</div></div></div></div>
    </div>

    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body py-2">
            <div class="row align-items-center g-2">
                <div class="col-md-4">
                    <label for="filtroSituacion69B" class="form-label small text-muted mb-1">Filtrar por situación</label>
                    <select id="filtroSituacion69B" class="form-select">
                        <option value="">Todas</option>
                        <option value="Definitivo">Definitivo</option>
                        <option value="Presunto">Presunto</option>
                        <option value="Desvirtuado">Desvirtuado</option>
                        <option value="Sentencia Favorable">Sentencia Favorable</option>
                    </select>
                </div>
                <div class="col-md-8 text-md-end">
                    <div class="small text-muted">Última sincronización local</div>
                    <div id="sat69bVersion" class="fw-semibold">Sin información</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaSat69B" class="table table-striped table-hover table-sm w-100 text-nowrap">
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>RFC</th>
                        <th>Contribuyente</th>
                        <th>Situación</th>
                        <th>Oficio presunción SAT</th>
                        <th>Publicación SAT presuntos</th>
                        <th>Oficio presunción DOF</th>
                        <th>Publicación DOF presuntos</th>
                        <th>Oficio desvirtuado SAT</th>
                        <th>Publicación SAT desvirtuados</th>
                        <th>Oficio desvirtuado DOF</th>
                        <th>Publicación DOF desvirtuados</th>
                        <th>Oficio definitivo SAT</th>
                        <th>Publicación SAT definitivos</th>
                        <th>Oficio definitivo DOF</th>
                        <th>Publicación DOF definitivos</th>
                        <th>Oficio sentencia SAT</th>
                        <th>Publicación SAT sentencia</th>
                        <th>Oficio sentencia DOF</th>
                        <th>Publicación DOF sentencia</th>
                        <th>Sincronizado</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    function badgeSituacion(v){
        const t=String(v||'');
        if(t==='Definitivo') return '<span class="badge bg-danger">Definitivo</span>';
        if(t==='Presunto') return '<span class="badge bg-warning text-dark">Presunto</span>';
        if(t==='Desvirtuado') return '<span class="badge bg-success">Desvirtuado</span>';
        if(t==='Sentencia Favorable') return '<span class="badge bg-info text-dark">Sentencia favorable</span>';
        return '<span class="badge bg-secondary">'+$('<div>').text(t).html()+'</span>';
    }

    const tabla=$('#tablaSat69B').DataTable({
        processing:true,
        serverSide:true,
        scrollX:true,
        pageLength:25,
        lengthMenu:[[10,25,50,100],[10,25,50,100]],
        ajax:{
            url:'ajax/listar_sat_69b.php',
            data:function(d){ d.situacion=$('#filtroSituacion69B').val(); },
            dataSrc:function(json){
                const r=json.resumen||{};
                $('#sat69bTotal').text(Number(r.total||0).toLocaleString());
                $('#sat69bDef').text(Number(r.definitivos||0).toLocaleString());
                $('#sat69bPre').text(Number(r.presuntos||0).toLocaleString());
                $('#sat69bDes').text(Number(r.desvirtuados||0).toLocaleString());
                $('#sat69bSen').text(Number(r.sentencias||0).toLocaleString());
                const c=json.control||{};
                let txt='Sin información';
                if(c.fecha_sincronizacion){
                    txt=c.fecha_sincronizacion;
                    if(c.total_registros) txt+=' · '+Number(c.total_registros).toLocaleString()+' registros';
                }
                $('#sat69bVersion').text(txt);
                return json.data||[];
            }
        },
        order:[[0,'asc']],
        columns:[
            {data:'numero_sat'},
            {data:'rfc',className:'fw-semibold'},
            {data:'nombre_contribuyente'},
            {data:'situacion',render:badgeSituacion},
            {data:'oficio_presuncion_sat',defaultContent:''},
            {data:'fecha_sat_presuntos',defaultContent:''},
            {data:'oficio_presuncion_dof',defaultContent:''},
            {data:'fecha_dof_presuntos',defaultContent:''},
            {data:'oficio_desvirtuado_sat',defaultContent:''},
            {data:'fecha_sat_desvirtuados',defaultContent:''},
            {data:'oficio_desvirtuado_dof',defaultContent:''},
            {data:'fecha_dof_desvirtuados',defaultContent:''},
            {data:'oficio_definitivo_sat',defaultContent:''},
            {data:'fecha_sat_definitivos',defaultContent:''},
            {data:'oficio_definitivo_dof',defaultContent:''},
            {data:'fecha_dof_definitivos',defaultContent:''},
            {data:'oficio_sentencia_sat',defaultContent:''},
            {data:'fecha_sat_sentencia',defaultContent:''},
            {data:'oficio_sentencia_dof',defaultContent:''},
            {data:'fecha_dof_sentencia',defaultContent:''},
            {data:'fecha_sincronizacion',defaultContent:''}
        ]
    });

    $('#filtroSituacion69B').on('change',function(){ tabla.ajax.reload(); });

    let sat69bPollTimer=null;
    let sat69bProcesoActivo=null;
    let sat69bCancelando=false;

    function detenerPoll69B(){
        if(sat69bPollTimer){ clearInterval(sat69bPollTimer); sat69bPollTimer=null; }
    }

    function pintarAvance69B(p){
        const pct=Math.max(0,Math.min(100,parseInt(p.porcentaje||0,10)));
        const procesados=Number(p.registros_procesados||0);
        const total=Number(p.total_registros||0);
        $('#sat69bBarra').css('width',pct+'%').attr('aria-valuenow',pct).text(pct+'%');
        $('#sat69bEtapa').text(p.etapa||'Procesando...');
        let detalle='';
        if(procesados>0){
            detalle=procesados.toLocaleString()+(total>0?' de '+total.toLocaleString():'')+' registros';
        } else if(p.mensaje){
            detalle=p.mensaje;
        }
        $('#sat69bDetalle').text(detalle);
        $('#sat69bPorcentaje').text(pct+'%');
    }

    function consultarEstado69B(){
        if(!sat69bProcesoActivo) return;
        $.ajax({
            url:'ajax/sincronizar_sat_69b.php',
            method:'GET',
            dataType:'json',
            cache:false,
            data:{accion:'estado',id_proceso:sat69bProcesoActivo}
        }).done(function(r){
            if(!r || r.status!=='ok' || !r.proceso) return;
            const p=r.proceso;
            pintarAvance69B(p);
            if(p.estado==='completado'){
                detenerPoll69B();
                sat69bProcesoActivo=null;
                tabla.ajax.reload(null,false);
                Swal.fire('Listo',p.mensaje||'Listado actualizado correctamente.','success');
                $('#btnSincronizar69B').prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar listado SAT');
            } else if(p.estado==='cancelado'){
                detenerPoll69B();
                sat69bProcesoActivo=null;
                Swal.fire('Cancelado',p.mensaje||'Proceso cancelado. El catálogo anterior se conservó.','info');
                $('#btnSincronizar69B').prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar listado SAT');
            } else if(p.estado==='error'){
                detenerPoll69B();
                sat69bProcesoActivo=null;
                Swal.fire('Error',p.mensaje||'No se pudo sincronizar.','error');
                $('#btnSincronizar69B').prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar listado SAT');
            }
        });
    }

    function abrirMonitor69B(){
        Swal.fire({
            title:'Sincronizando artículo 69-B',
            html:`
                <div class="text-start mb-2"><strong id="sat69bEtapa">Preparando...</strong></div>
                <div class="progress" style="height:24px;">
                    <div id="sat69bBarra" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:1%" aria-valuemin="0" aria-valuemax="100">1%</div>
                </div>
                <div class="d-flex justify-content-between mt-2 small text-muted">
                    <span id="sat69bDetalle">Iniciando proceso...</span>
                    <strong id="sat69bPorcentaje">1%</strong>
                </div>
                <div class="mt-3">
                    <button type="button" id="btnCancelarProceso69B" class="btn btn-outline-danger">
                        <i class="bi bi-x-circle"></i> Cancelar proceso
                    </button>
                </div>`,
            showConfirmButton:false,
            allowOutsideClick:false,
            allowEscapeKey:false,
            didOpen:function(){
                $('#btnCancelarProceso69B').on('click',function(){
                    if(!sat69bProcesoActivo || sat69bCancelando) return;
                    sat69bCancelando=true;
                    const b=$(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Cancelando...');
                    $.ajax({
                        url:'ajax/sincronizar_sat_69b.php',
                        method:'POST',
                        dataType:'json',
                        data:{accion:'cancelar',id_proceso:sat69bProcesoActivo}
                    }).always(function(){
                        $('#sat69bEtapa').text('Cancelando proceso...');
                        $('#sat69bDetalle').text('Espere a que el servidor detenga la sincronización de forma segura.');
                        b.prop('disabled',true);
                        sat69bCancelando=false;
                    });
                });
            }
        });
    }

    $('#btnSincronizar69B').on('click',function(){
        const btn=$(this);
        Swal.fire({
            title:'Sincronizar artículo 69-B',
            html:'Se descargará el <b>Listado completo oficial del SAT</b> y se actualizará la tabla local.<br><small>Puede cancelar durante el proceso; el catálogo anterior se conservará.</small>',
            icon:'question',
            showCancelButton:true,
            confirmButtonText:'Sí, sincronizar',
            cancelButtonText:'Cancelar'
        }).then(function(result){
            if(!result.isConfirmed) return;

            btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Sincronizando...');
            $.ajax({
                url:'ajax/sincronizar_sat_69b.php',
                method:'POST',
                dataType:'json',
                data:{accion:'iniciar'}
            }).done(function(r){
                if(!r || r.status!=='ok' || !r.id_proceso){
                    throw new Error((r&&r.msg)||'No se pudo iniciar el proceso.');
                }
                sat69bProcesoActivo=r.id_proceso;
                sat69bCancelando=false;
                abrirMonitor69B();
                consultarEstado69B();
                sat69bPollTimer=setInterval(consultarEstado69B,650);

                // Arranca el trabajo pesado en una petición separada. El resultado final
                // se toma del monitor, por lo que un timeout del navegador no pierde el estado.
                $.ajax({
                    url:'ajax/sincronizar_sat_69b.php',
                    method:'POST',
                    dataType:'json',
                    timeout:0,
                    data:{accion:'ejecutar',id_proceso:sat69bProcesoActivo}
                }).fail(function(xhr){
                    // El polling mostrará el error real almacenado por el servidor.
                    if(xhr.status===0) return;
                    consultarEstado69B();
                });
            }).fail(function(xhr){
                const r=xhr.responseJSON||{};
                btn.prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar listado SAT');
                Swal.fire('No se pudo iniciar',r.msg||xhr.responseText||'Error del servidor','error');
            });
        });
    });
})();
</script>
