<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true);
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_sat_69'); } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; exit; }

$control69 = [];
try {
    $control69 = $pdo->query(
        "SELECT fecha_sincronizacion, total_registros, archivos_procesados
           FROM sat_69_control
          WHERE id_control = 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $control69 = [];
}

$total69 = (int)($control69['total_registros'] ?? 0);
$fecha69 = trim((string)($control69['fecha_sincronizacion'] ?? ''));
$archivos69 = (int)($control69['archivos_procesados'] ?? 0);

$tipos69 = [
    'Cancelados',
    'Reducción de multas Art. 74',
    'Condonados concurso mercantil Art. 146-B',
    'Reducción de recargos Art. 21',
    'Condonados por decreto',
    'Condonados 2007-2015',
    'Cancelados Art. 146-A 2007-2015',
    'Retorno de inversiones',
    'Créditos exigibles',
    'Créditos firmes',
    'No localizados',
    'Sentencias condenatorias',
    'CSD sin efectos',
    'Entes públicos y gobierno omisos',
];
?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check"></i> Contribuyentes Artículo 69 SAT</h4>
            <small class="text-muted">Listados oficiales de contribuyentes publicados: exigibles, firmes, no localizados, sentencias, CSD sin efectos y demás supuestos.</small>
        </div>
        <button id="btnSincronizar69" class="btn btn-danger"><i class="bi bi-cloud-download"></i> Sincronizar artículo 69</button>
    </div>

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle"></i> Fuente oficial: <strong>Datos abiertos del SAT, artículo 69 del CFF</strong>. El catálogo local solo se reemplaza cuando la lectura termina correctamente.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4 col-xl-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="small text-muted">Registros</div>
                    <div id="s69Total" class="fs-4 fw-bold"><?= number_format($total69) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="small text-muted">Tipo de publicación</label>
                    <select id="filtroTipo69" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($tipos69 as $tipo69): ?>
                            <option value="<?= htmlspecialchars($tipo69, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tipo69, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small text-muted">Nivel</label>
                    <select id="filtroRiesgo69" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="ALTO">Alto</option>
                        <option value="MEDIO">Medio</option>
                        <option value="INFORMATIVO">Informativo</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="small text-muted">RFC o contribuyente</label>
                    <div class="input-group input-group-sm">
                        <input id="buscarSat69" type="text" class="form-control" placeholder="Escriba RFC o nombre y pulse Filtrar" autocomplete="off">
                        <button id="btnFiltrarSat69" class="btn btn-primary" type="button"><i class="bi bi-search"></i> Filtrar</button>
                        <button id="btnLimpiarSat69" class="btn btn-outline-secondary" type="button" title="Limpiar búsqueda"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="col-md text-md-end">
                    <div class="small text-muted">Última sincronización</div>
                    <div id="s69Version" class="fw-semibold">
                        <?php if ($fecha69 !== ''): ?>
                            <?= htmlspecialchars($fecha69, ENT_QUOTES, 'UTF-8') ?> · <?= number_format($total69) ?> registros · <?= number_format($archivos69) ?> archivos
                        <?php else: ?>
                            Sin información
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaSat69" class="table table-striped table-hover table-sm w-100 text-nowrap">
                    <thead>
                        <tr>
                            <th>ID</th><th>RFC</th><th>Contribuyente</th><th>Publicación</th><th>Nivel</th><th>Fecha</th><th>Detalle</th><th>Sincronizado</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const esc = s => $('<div>').text(s ?? '').html();
    function nivel(v){
        let c = v === 'ALTO' ? 'danger' : (v === 'MEDIO' ? 'warning text-dark' : 'secondary');
        return `<span class="badge bg-${c}">${esc(v)}</span>`;
    }

    let busquedaAplicada = '';
    const t = $('#tablaSat69').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        scrollX: true,
        pageLength: 25,
        lengthMenu: [[25,50,100],[25,50,100]],
        order: [[0,'asc']],
        ajax: {
            url: 'ajax/listar_sat_69.php',
            data: d => {
                d.tipo = $('#filtroTipo69').val();
                d.riesgo = $('#filtroRiesgo69').val();
                d.busqueda = busquedaAplicada;
            },
            dataSrc: j => j.data || []
        },
        columns: [
            {data:'id_69'},
            {data:'rfc',className:'fw-bold'},
            {data:'nombre_contribuyente'},
            {data:'tipo_publicacion'},
            {data:'nivel_riesgo',render:nivel},
            {data:'fecha_publicacion',defaultContent:''},
            {data:'detalle_resumen',defaultContent:'',render:v=>`<span title="${esc(v)}">${esc(String(v||'').slice(0,120))}</span>`},
            {data:'fecha_sincronizacion'}
        ]
    });

    function aplicarBusqueda69(){
        busquedaAplicada = $.trim($('#buscarSat69').val());
        t.page('first').ajax.reload();
    }

    $('#btnFiltrarSat69').on('click', aplicarBusqueda69);
    $('#buscarSat69').on('keydown', e => {
        if(e.key === 'Enter'){
            e.preventDefault();
            aplicarBusqueda69();
        }
    });
    $('#btnLimpiarSat69').on('click', () => {
        $('#buscarSat69').val('');
        busquedaAplicada = '';
        t.page('first').ajax.reload();
        $('#buscarSat69').trigger('focus');
    });
    $('#filtroTipo69,#filtroRiesgo69').on('change', () => t.page('first').ajax.reload());

    let id = null, timer = null;
    function poll(){
        if(!id) return;
        $.getJSON('ajax/sincronizar_sat_69.php',{accion:'estado',id_proceso:id},r=>{
            const p = r.proceso || {};
            $('#p69bar').css('width',(p.porcentaje||0)+'%').text((p.porcentaje||0)+'%');
            $('#p69etapa').text(p.etapa||'Procesando');
            $('#p69det').text(p.mensaje||'');
            if(['completado','error','cancelado'].includes(p.estado)){
                clearInterval(timer);
                id = null;
                Swal.close();
                $('#btnSincronizar69').prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar artículo 69');
                if(p.estado === 'completado'){
                    window.location.reload();
                    return;
                }
                Swal.fire(p.estado==='cancelado'?'Cancelado':'Error',p.mensaje||'',p.estado==='cancelado'?'info':'error');
            }
        });
    }

    $('#btnSincronizar69').on('click',function(){
        Swal.fire({
            title:'Sincronizar artículo 69',
            text:'Se descargarán por separado los listados oficiales del SAT y se reemplazará el catálogo local al terminar.',
            icon:'question',showCancelButton:true,confirmButtonText:'Sí, sincronizar'
        }).then(x=>{
            if(!x.isConfirmed) return;
            const b=$(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Sincronizando...');
            $.post('ajax/sincronizar_sat_69.php',{accion:'iniciar'},r=>{
                id=r.id_proceso;
                Swal.fire({
                    title:'Sincronizando artículo 69',
                    html:'<div id="p69etapa" class="text-start fw-bold mb-2">Preparando...</div><div class="progress" style="height:24px"><div id="p69bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:1%">1%</div></div><div id="p69det" class="small text-muted mt-2"></div><button id="cancel69" class="btn btn-outline-danger mt-3">Cancelar</button>',
                    showConfirmButton:false,allowOutsideClick:false,
                    didOpen:()=>$('#cancel69').on('click',()=>$.post('ajax/sincronizar_sat_69.php',{accion:'cancelar',id_proceso:id}))
                });
                timer=setInterval(poll,800);
                poll();
                $.ajax({url:'ajax/sincronizar_sat_69.php',method:'POST',timeout:0,data:{accion:'ejecutar',id_proceso:id}});
            },'json').fail(x=>{
                b.prop('disabled',false).html('<i class="bi bi-cloud-download"></i> Sincronizar artículo 69');
                Swal.fire('Error',(x.responseJSON&&x.responseJSON.msg)||'No se pudo iniciar','error');
            });
        });
    });
})();
</script>
