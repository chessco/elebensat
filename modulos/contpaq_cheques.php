<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/csrf.php';

$u = seguridad_exigir_sesion($pdo, true);
$idUsuario = (int)$u['id_usuario'];
$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
try {
    exigir_permiso_accion($pdo, $idUsuario, $idEmpresa, 'contpaq_cheques');
} catch (Throwable $e) {
    seguridad_responder_denegado('No tiene permiso para consultar CONTPAQi Cheques.', 403);
}

$cfgSt = $pdo->prepare(
    'SELECT activo, servidor, base_datos, usuario, ultima_prueba, ultimo_error, ultima_sincronizacion
       FROM empresa_contpaq_config
      WHERE id_empresa=?'
);
$cfgSt->execute([$idEmpresa]);
$cfg = $cfgSt->fetch(PDO::FETCH_ASSOC);

$csrf = csrf_token();
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$estadoMovimiento = static function(array $r): array {
    if ((int)($r['es_cancelado'] ?? 0) === 1) return ['CANCELADO','danger'];
    $total=(int)($r['dispersiones'] ?? 0);
    $conc=(int)($r['dispersiones_conciliadas'] ?? 0);
    $par=(int)($r['dispersiones_parciales'] ?? 0);
    $err=(int)($r['dispersiones_error'] ?? 0);
    if ($total<=0) return ['SIN DISPERSIÓN','secondary'];
    if ($err>0) return ['ERROR','danger'];
    if ($par>0 || ($conc>0 && $conc<$total)) return ['PARCIAL','warning text-dark'];
    if ($conc===$total) return ['CONCILIADO','success'];
    return ['NO CONCILIADO','secondary'];
};
?>
<style>
.contpaq-card{background:#42566f;border:1px solid #71849a;border-radius:8px;color:#f8fafc}
.contpaq-card .text-muted{color:#d7e0ea!important}
.contpaq-card label.text-muted{color:#e2e8f0!important}
.contpaq-card #cpRangoVistaTexto{color:#d7e0ea!important}
.contpaq-card .btn-outline-secondary{color:#f1f5f9;border-color:#a8b6c5}
.contpaq-card .btn-outline-secondary:hover{background:#5f738a;border-color:#c5d0dc;color:#fff}
.contpaq-small{font-size:.74rem}
#tablaContpaqCheques td,#tablaContpaqEgresos td{font-size:.76rem;vertical-align:middle}
.contpaq-table-wrap{max-height:56vh;overflow:auto}
#tablaContpaqCheques tbody tr,#tablaContpaqEgresos tbody tr{cursor:pointer}
#tablaContpaqCheques tbody tr:hover,#tablaContpaqEgresos tbody tr:hover{outline:1px solid rgba(88,166,255,.45)}
.cp-detail-label{font-size:.70rem;color:#8b949e;text-transform:uppercase;letter-spacing:.03em}
.cp-detail-value{font-weight:600;word-break:break-word}
.cp-uuid{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.74rem}
.cp-status{white-space:nowrap}
</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-bank2 text-warning me-2"></i>CONTPAQ CHEQUES / PAGOS</h6>
            <div class="text-muted contpaq-small">Copia local para conciliación. La sincronización actualiza datos CONTPAQi sin borrar estados internos del visor.</div>
        </div>
        <div class="d-flex gap-2 align-items-end flex-wrap">
            <div>
                <label class="small text-muted">Sincronizar desde</label>
                <input type="date" id="cp_fecha_desde" class="form-control form-control-sm"
                       value="<?=date('Y-m-d', strtotime('-15 days'))?>">
            </div>
            <div>
                <label class="small text-muted">Sincronizar hasta</label>
                <input type="date" id="cp_fecha_hasta" class="form-control form-control-sm"
                       value="<?=date('Y-m-d')?>">
            </div>
            <button class="btn btn-warning btn-sm fw-bold" id="btnSyncContpaq" onclick="sincronizarContpaqCheques()">
                <i class="bi bi-arrow-repeat me-1"></i>SINCRONIZAR
            </button>
            <button class="btn btn-outline-danger btn-sm fw-bold d-none" id="btnCancelarSyncContpaq" onclick="cancelarSincronizacionContpaq()">
                <i class="bi bi-x-circle me-1"></i>CANCELAR
            </button>
        </div>
    </div>

    <div class="d-flex justify-content-end align-items-end gap-2 flex-wrap mb-3">
        <div>
            <label class="small text-muted">Periodo a conciliar</label>
            <input type="month" id="cp_periodo_conciliar" class="form-control form-control-sm"
                   value="<?=date('Y-m', strtotime('first day of last month'))?>">
            <div class="text-muted" style="font-size:.68rem">Año y mes juntos. Puede capturar periodos anteriores.</div>
        </div>
        <button class="btn btn-success btn-sm fw-bold" id="btnConciliarContpaq" onclick="conciliarContpaqPagos()">
            <i class="bi bi-link-45deg me-1"></i>CONCILIAR PAGOS / APLICAR DIOT
        </button>
        <button class="btn btn-outline-danger btn-sm fw-bold d-none" id="btnCancelarConciliacion" onclick="cancelarConciliacionContpaq()">
            <i class="bi bi-x-circle me-1"></i>CANCELAR
        </button>
    </div>

    <div id="cpConcProgreso" class="contpaq-card p-3 mb-3 d-none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <div class="fw-bold" id="cpConcTitulo">Preparando conciliación...</div>
                <div class="text-muted contpaq-small" id="cpConcDetalle"></div>
            </div>
            <div class="fw-bold text-info" id="cpConcPct">0%</div>
        </div>
        <div class="progress" style="height:10px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="cpConcBar" style="width:0%"></div>
        </div>
        <div class="row mt-2 g-2 contpaq-small">
            <div class="col-md-2">Revisadas: <b id="cpConcRevisadas">0</b></div>
            <div class="col-md-2">Nuevas: <b id="cpConcCreadas">0</b></div>
            <div class="col-md-2">Parciales: <b id="cpConcParciales">0</b></div>
            <div class="col-md-2">Sin cruce: <b id="cpConcSinCruce">0</b></div>
            <div class="col-md-2">Facturas: <b id="cpConcFacturas">0</b></div>
            <div class="col-md-2">Canceladas: <b id="cpConcCanceladas">0</b></div>
        </div>
        <div class="mt-2 p-2 rounded" style="background:rgba(255,255,255,.08)">
            <div><b>Dispersión actual:</b> <span id="cpConcActual">Preparando...</span></div>
            <div class="text-muted contpaq-small" id="cpConcResultado"></div>
        </div>
        <div class="row mt-2 g-2 contpaq-small">
            <div class="col-md-3">Inicio proceso: <b id="cpConcInicio">-</b></div>
            <div class="col-md-3">Último UUID: <b id="cpConcTiempoUuid">-</b></div>
            <div class="col-md-2">Tiempo total: <b id="cpConcTiempoTotal">00:00</b></div>
            <div class="col-md-2">Promedio UUID: <b id="cpConcPromedio">0.000 s</b></div>
            <div class="col-md-2">Estimado restante: <b id="cpConcRestante">-</b></div>
        </div>
        <div class="row mt-2 g-2 contpaq-small">
            <div class="col-md-2">Errores: <b id="cpConcErrores">0</b></div>
            <div class="col-md-10"><b>Últimos resultados:</b> <span id="cpConcHistorial">Sin movimientos procesados.</span></div>
        </div>
    </div>

    <?php if (!$cfg): ?>
        <div class="alert alert-warning">
            Esta empresa todavía no tiene configuración CONTPAQi. Un SuperAdministrador debe capturarla en
            <b>Administración → Empresas → pestaña CONTPAQ</b>.
        </div>
    <?php elseif ((int)$cfg['activo'] !== 1): ?>
        <div class="alert alert-warning">La conexión CONTPAQi de esta empresa está desactivada.</div>
    <?php endif; ?>

    <div id="cpSyncProgreso" class="contpaq-card p-3 mb-3 d-none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <div class="fw-bold" id="cpSyncTitulo">Preparando sincronización...</div>
                <div class="text-muted contpaq-small" id="cpSyncDetalle"></div>
            </div>
            <div class="fw-bold text-info" id="cpSyncPct">0%</div>
        </div>
        <div class="progress" style="height:10px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="cpSyncBar" style="width:0%"></div>
        </div>
        <div class="row mt-2 g-2 contpaq-small">
            <div class="col-md-3">Cheques: <b id="cpSyncCheques">0</b></div>
            <div class="col-md-3">Egresos: <b id="cpSyncEgresos">0</b></div>
            <div class="col-md-3">Aplicaciones: <b id="cpSyncDisp">0</b></div>
            <div class="col-md-3">Bloques: <b id="cpSyncBloques">0</b></div>
        </div>
    </div>

    <div class="contpaq-card p-2 mb-3">
        <div class="d-flex align-items-end gap-2 flex-wrap">
            <div class="me-2">
                <div class="fw-bold small"><i class="bi bi-calendar-range me-1"></i>RANGO PARA VER MOVIMIENTOS</div>
                <div class="text-muted contpaq-small">Este rango solo filtra lo ya guardado localmente; no ejecuta sincronización con CONTPAQi.</div>
            </div>
            <div>
                <label class="small text-muted">Ver desde</label>
                <input type="date" id="cp_ver_desde" class="form-control form-control-sm">
            </div>
            <div>
                <label class="small text-muted">Ver hasta</label>
                <input type="date" id="cp_ver_hasta" class="form-control form-control-sm">
            </div>
            <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnCpAplicarFiltro">
                <i class="bi bi-funnel me-1"></i>APLICAR
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCpVerTodos">
                <i class="bi bi-list-ul me-1"></i>TODOS
            </button>
            <div>
                <label class="small text-muted">Año rápido</label>
                <select id="cp_ver_anio_rapido" class="form-select form-select-sm"></select>
            </div>
            <div>
                <label class="small text-muted">Mes rápido</label>
                <select id="cp_ver_mes_rapido" class="form-select form-select-sm">
                    <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                    <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                    <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                    <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
                </select>
            </div>
            <div class="ms-auto small text-muted" id="cpRangoVistaTexto"></div>
        </div>
    </div>

    <?php if (!empty($cfg['ultimo_error'])): ?>
        <div class="alert alert-danger py-2"><b>Último error CONTPAQi:</b> <?=$h($cfg['ultimo_error'])?></div>
    <?php endif; ?>

    <div class="small text-muted mb-2"><i class="bi bi-mouse2 me-1"></i>Doble clic sobre un cheque o egreso para ver las dispersiones y todos los documentos/UUID pagados.</div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <ul class="nav nav-tabs mb-0">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#cp-cheques" type="button">Cheques</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cp-egresos" type="button">Egresos / Transferencias</button></li>
        </ul>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-success btn-sm fw-bold" id="btnCpExportarCheques">
                <i class="bi bi-file-earmark-excel me-1"></i>EXPORTAR CHEQUES
            </button>
            <button type="button" class="btn btn-success btn-sm fw-bold" id="btnCpExportarEgresos">
                <i class="bi bi-file-earmark-excel me-1"></i>EXPORTAR EGRESOS
            </button>
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="cp-cheques">
            <div class="table-responsive contpaq-card p-2 contpaq-table-wrap">
                <table id="tablaContpaqCheques" class="table table-dark table-hover table-sm w-100">
                    <thead><tr>
                        <th>ID CONTPAQ</th><th>Folio</th><th>Fecha</th><th>Código</th><th>Beneficiario</th>
                        <th class="text-end">Total</th><th>Cancelado</th><th>Disp.</th><th>Conciliación</th><th>Última sync</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="cp-egresos">
            <div class="table-responsive contpaq-card p-2 contpaq-table-wrap">
                <table id="tablaContpaqEgresos" class="table table-dark table-hover table-sm w-100">
                    <thead><tr>
                        <th>ID CONTPAQ</th><th>Folio</th><th>Fecha</th><th>Código</th><th>Beneficiario</th>
                        <th class="text-end">Total</th><th>Cancelado</th><th>Disp.</th><th>Conciliación</th><th>Última sync</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalContpaqChequeDetalle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Detalle del movimiento / dispersiones y documentos pagados</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="cpDetalleLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2 text-muted">Consultando detalle...</div>
                    </div>
                    <div id="cpDetalleContenido" class="d-none">
                        <div class="row g-2 mb-3" id="cpDetalleCabecera"></div>
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <h6 class="mb-0 fw-bold">Documentos / facturas aplicadas</h6>
                            <div class="small text-muted" id="cpDetalleResumen"></div>
                        </div>
                        <div class="table-responsive" style="max-height:48vh;overflow:auto">
                            <table class="table table-sm table-hover align-middle" id="cpTablaDetalleDocumentos">
                                <thead class="table-light" style="position:sticky;top:0;z-index:2">
                                    <tr>
                                        <th>#</th><th>UUID factura</th><th>UUID REP</th><th>Fecha pago</th>
                                        <th class="text-end">Total pago</th><th>TC</th><th class="text-end">Total comprobante</th><th>ID dispersión</th>
                                        <th>Conciliación</th><th>Motivo</th><th>Fecha conciliación</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td colspan="4" class="text-end">Total aplicado:</td>
                                        <td class="text-end" id="cpDetalleTotalAplicado">0.00</td>
                                        <td></td><td colspan="5"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="accordion mt-3" id="cpDetalleTecnicoAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cpDetalleTecnico">Datos completos guardados de CONTPAQi</button>
                                </h2>
                                <div id="cpDetalleTecnico" class="accordion-collapse collapse">
                                    <div class="accordion-body"><pre id="cpDetalleJson" class="small mb-0" style="white-space:pre-wrap;max-height:280px;overflow:auto"></pre></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    let tablaCheques=null, tablaEgresos=null;
    const cpAjaxMovimientos='ajax/listar_contpaq_movimientos.php';
    function crearTablaContpaq(selector,tipo){
        return $(selector).DataTable({
            processing:true, serverSide:true, pageLength:25,
            lengthMenu:[[25,50,100],[25,50,100]],
            order:[[2,'asc']], scrollX:true, scrollY:'46vh', scrollCollapse:true,
            autoWidth:false, deferRender:true,
            ajax:{
                url:cpAjaxMovimientos, type:'POST',
                data:function(d){
                    d.tipo=tipo;
                    d.fecha_desde=$('#cp_ver_desde').val()||'';
                    d.fecha_hasta=$('#cp_ver_hasta').val()||'';
                    d.csrf_token=<?=json_encode($csrf)?>;
                }
            },
            columns:[
                {data:'id_origen'},{data:'folio'},{data:'fecha'},{data:'codigo_persona'},
                {data:'beneficiario_pagador'},{data:'total',className:'text-end'},
                {data:'cancelado_html',orderable:false,searchable:false},
                {data:'dispersiones_html',orderable:false,searchable:false},
                {data:'conciliacion_html',orderable:false,searchable:false},
                {data:'fecha_ultima_sync'}
            ],
            createdRow:function(row,data){
                $(row).attr('data-tipo-origen',tipo).attr('data-id-origen',data.id_origen)
                    .attr('title','Doble clic para ver dispersiones y documentos pagados');
            }
        });
    }
    if ($.fn.DataTable) {
        tablaCheques=crearTablaContpaq('#tablaContpaqCheques','C');
        tablaEgresos=crearTablaContpaq('#tablaContpaqEgresos','T');
    }

    // Filtro de FECHAS PARA VISUALIZAR. Es independiente del rango de sincronización.
    // Se guarda por empresa para que al sincronizar/recargar el módulo conserve la vista elegida.
    const cpEmpresaId=<?=json_encode($idEmpresa)?>;
    const keyDesde='cp_ver_desde_'+cpEmpresaId;
    const keyHasta='cp_ver_hasta_'+cpEmpresaId;

    function hoyIso(){
        const d=new Date();
        const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dia=String(d.getDate()).padStart(2,'0');
        return `${y}-${m}-${dia}`;
    }
    function primeroMesIso(){
        const d=new Date();
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`;
    }
    function normalizarFechaCelda(v){
        const m=String(v||'').match(/(\d{4})-(\d{2})-(\d{2})/);
        return m ? `${m[1]}-${m[2]}-${m[3]}` : '';
    }

    $('#cp_ver_desde').val(localStorage.getItem(keyDesde) || primeroMesIso());
    $('#cp_ver_hasta').val(localStorage.getItem(keyHasta) || hoyIso());

    function recargarTablas(ordenAsc=true){
        if(tablaCheques){
            if(ordenAsc) tablaCheques.order([2,'asc']);
            tablaCheques.ajax.reload(null,false);
        }
        if(tablaEgresos){
            if(ordenAsc) tablaEgresos.order([2,'asc']);
            tablaEgresos.ajax.reload(null,false);
        }
    }
    window.cpRecargarTablas=function(){ recargarTablas(false); };
    function aplicarFiltroVista(){
        const desde=$('#cp_ver_desde').val() || '';
        const hasta=$('#cp_ver_hasta').val() || '';
        if(desde && hasta && desde>hasta){
            Swal.fire('Fechas','La fecha inicial para visualizar no puede ser mayor a la final.','warning');
            return;
        }
        localStorage.setItem(keyDesde,desde);
        localStorage.setItem(keyHasta,hasta);
        $('#cpRangoVistaTexto').text(desde||hasta ? `Mostrando: ${desde||'inicio'} al ${hasta||'fin'} · orden menor a mayor` : 'Mostrando todos');
        recargarTablas(true);
    }
    $('#btnCpAplicarFiltro').off('click.cpVista').on('click.cpVista',aplicarFiltroVista);
    $('#cp_ver_desde,#cp_ver_hasta').off('keydown.cpVista').on('keydown.cpVista',function(e){
        if(e.key==='Enter'){ e.preventDefault(); aplicarFiltroVista(); }
    });
    $('#btnCpVerTodos').off('click.cpVista').on('click.cpVista',function(){
        $('#cp_ver_desde,#cp_ver_hasta').val('');
        localStorage.removeItem(keyDesde);
        localStorage.removeItem(keyHasta);
        $('#cpRangoVistaTexto').text('Mostrando todos los movimientos');
        recargarTablas(true);
    });

    function exportarMovimientosContpaq(tipo){
        const esCheque=tipo==='C';
        const tabla=esCheque ? tablaCheques : tablaEgresos;
        const buscar=tabla ? String(tabla.search()||'') : '';
        const form=$('<form>',{method:'POST',action:'ajax/exportar_contpaq_movimientos.php',target:'_blank'});
        const campos={
            tipo:tipo,
            fecha_desde:$('#cp_ver_desde').val()||'',
            fecha_hasta:$('#cp_ver_hasta').val()||'',
            buscar:buscar,
            csrf_token:<?=json_encode($csrf)?>
        };
        Object.entries(campos).forEach(([k,v])=>form.append($('<input>',{type:'hidden',name:k,value:v})));
        $('body').append(form);
        form.trigger('submit');
        setTimeout(()=>form.remove(),500);
    }
    $('#btnCpExportarCheques').off('click.cpExport').on('click.cpExport',()=>exportarMovimientosContpaq('C'));
    $('#btnCpExportarEgresos').off('click.cpExport').on('click.cpExport',()=>exportarMovimientosContpaq('T'));
    const ahoraRapido=new Date();
    for(let y=ahoraRapido.getFullYear();y>=ahoraRapido.getFullYear()-5;y--){
        $('#cp_ver_anio_rapido').append(`<option value="${y}">${y}</option>`);
    }
    $('#cp_ver_mes_rapido').val(String(ahoraRapido.getMonth()+1));

    function aplicarPeriodoRapido(){
        const y=Number($('#cp_ver_anio_rapido').val());
        const m=Number($('#cp_ver_mes_rapido').val());
        if(!y || !m) return;

        const desde=`${y}-${String(m).padStart(2,'0')}-01`;
        const fin=new Date(y,m,0);
        const hasta=`${y}-${String(m).padStart(2,'0')}-${String(fin.getDate()).padStart(2,'0')}`;

        $('#cp_ver_desde').val(desde);
        $('#cp_ver_hasta').val(hasta);
        aplicarFiltroVista();
    }

    $('#cp_ver_anio_rapido,#cp_ver_mes_rapido')
        .off('change.cpRapido')
        .on('change.cpRapido', aplicarPeriodoRapido);
    aplicarFiltroVista();

    // El módulo se carga por AJAX y DataTables puede reconstruir el tbody.
    // Usamos evento delegado sobre document para que el doble clic sobreviva
    // a recargas, paginación, búsqueda y redibujos de DataTables.
    $(document)
        .off('dblclick.cpDetalle', '#tablaContpaqCheques tbody tr, #tablaContpaqEgresos tbody tr')
        .on('dblclick.cpDetalle', '#tablaContpaqCheques tbody tr, #tablaContpaqEgresos tbody tr', function(e){
            e.preventDefault();
            e.stopPropagation();
            const tipo=String(this.getAttribute('data-tipo-origen') || $(this).data('tipo-origen') || 'C');
            const id=Number(this.getAttribute('data-id-origen') || $(this).data('id-origen') || 0);
            if(!id){
                Swal.fire('Detalle','No se pudo identificar el movimiento seleccionado.','warning');
                return;
            }
            cpAbrirDetalleMovimiento(tipo,id);
        });
})();

function cpEsc(v){
    return $('<div>').text(v == null ? '' : String(v)).html();
}

function cpNum(v){
    const n=Number(v||0);
    return n.toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function cpBadgeConciliacion(estatus){
    const e=String(estatus||'NO CONCILIADO').toUpperCase();
    let c='secondary';
    if(e==='CONCILIADO' || e==='YA_CONCILIADO') c='success';
    else if(e==='PARCIAL') c='warning text-dark';
    else if(e==='ERROR' || e==='CANCELADO') c='danger';
    return `<span class="badge bg-${c}">${cpEsc(e.replaceAll('_',' '))}</span>`;
}

function cpCampoDetalle(etiqueta,valor,clase='col-md-3'){
    return `<div class="${clase}"><div class="border rounded p-2 h-100"><div class="cp-detail-label">${cpEsc(etiqueta)}</div><div class="cp-detail-value">${cpEsc(valor ?? '')}</div></div></div>`;
}

function cpAbrirDetalleMovimiento(tipoOrigen,idOrigen){
    const el=document.getElementById('modalContpaqChequeDetalle');
    if(!el){
        Swal.fire('Detalle','No se encontró la ventana de detalle. Recargue el módulo e intente nuevamente.','error');
        return;
    }
    let modal=null;
    if(window.bootstrap && bootstrap.Modal){
        modal=bootstrap.Modal.getOrCreateInstance(el);
    }
    $('#cpDetalleLoading').removeClass('d-none');
    $('#cpDetalleContenido').addClass('d-none');
    $('#cpDetalleCabecera').empty();
    $('#cpTablaDetalleDocumentos tbody').empty();
    $('#cpDetalleResumen').text('');
    $('#cpDetalleTotalAplicado').text('0.00');
    $('#cpDetalleJson').text('');
    if(modal){
        modal.show();
    } else if($.fn.modal){
        $(el).modal('show');
    } else {
        Swal.fire('Detalle','No fue posible abrir la ventana de detalle porque Bootstrap Modal no está disponible.','error');
        return;
    }

    $.ajax({
        url:'ajax/contpaq_cheque_detalle.php',
        method:'GET',
        dataType:'json',
        data:{tipo_origen:tipoOrigen,id_origen:idOrigen}
    }).done(function(r){
        if(!r.success) throw new Error(r.error||'No fue posible consultar el detalle.');
        const c=r.movimiento||r.cheque||{};
        $('#cpDetalleCabecera').html(
            cpCampoDetalle('Folio',c.folio)+
            cpCampoDetalle('Fecha',c.fecha)+
            cpCampoDetalle('RFC',c.persona_rfc)+
            cpCampoDetalle('Código persona',c.codigo_persona)+
            cpCampoDetalle('Beneficiario',c.beneficiario_pagador,'col-md-6')+
            cpCampoDetalle(c.tipo_origen==='T'?'Total egreso':'Total cheque','$ '+cpNum(c.total))+
            cpCampoDetalle('Moneda / TC',(c.codigo_moneda||'')+' / '+(c.tipo_cambio||''))+
            cpCampoDetalle('Concepto',c.concepto,'col-md-6')+
            cpCampoDetalle('Póliza',c.num_pol || c.id_poliza || '')+
            cpCampoDetalle('Conciliación',String(c.estatus_conciliacion_movimiento||'NO CONCILIADO').replaceAll('_',' '))+
            cpCampoDetalle('Tipo origen',c.tipo_origen==='T'?'EGRESO / TRANSFERENCIA':'CHEQUE')
        );

        let total=0;
        const docs=Array.isArray(r.documentos)?r.documentos:[];
        const $tb=$('#cpTablaDetalleDocumentos tbody');
        docs.forEach((d,i)=>{
            total += Number(d.total_pago||0);
            $tb.append(`<tr>
                <td>${i+1}</td>
                <td class="cp-uuid">${cpEsc(d.uuid||'')}</td>
                <td class="cp-uuid">${cpEsc(d.uuid_rep||'')}</td>
                <td>${cpEsc(d.fecha_pago||'')}</td>
                <td class="text-end">${cpNum(d.total_pago)}</td>
                <td>${cpEsc(d.tipo_cambio||'')}</td>
                <td class="text-end">${cpNum(d.total_pago_comprobante)}</td>
                <td>${cpEsc(d.id_contpaq_dispersion||'')}</td>
                <td class="cp-status">${cpBadgeConciliacion(d.estatus_conciliacion)}</td>
                <td>${cpEsc(d.observacion_conciliacion||'')}</td>
                <td>${cpEsc(d.fecha_conciliacion||'')}</td>
            </tr>`);
        });
        if(!docs.length){
            $tb.html('<tr><td colspan="11" class="text-center text-muted py-4">Este movimiento no tiene documentos relacionados guardados en DispersionesPagos.</td></tr>');
        }
        $('#cpDetalleTotalAplicado').text(cpNum(total));
        $('#cpDetalleResumen').text(`${docs.length} documento(s) relacionado(s) · Total aplicado: $ ${cpNum(total)}`);
        $('#cpDetalleJson').text(JSON.stringify({movimiento:c,documentos:docs},null,2));
        $('#cpDetalleLoading').addClass('d-none');
        $('#cpDetalleContenido').removeClass('d-none');
    }).fail(function(xhr){
        let msg='No fue posible consultar el detalle del movimiento.';
        try { const r=JSON.parse(xhr.responseText||'{}'); if(r.error) msg=r.error; } catch(e){}
        $('#cpDetalleLoading').html(`<div class="alert alert-danger mb-0">${cpEsc(msg)}</div>`);
    });
}

var cpSyncCancelada = false;
var cpSyncXHR = null;

function cpFechaLocal(iso){
    const p=iso.split('-').map(Number);
    return new Date(p[0], p[1]-1, p[2]);
}

function cpIsoFecha(d){
    const y=d.getFullYear();
    const m=String(d.getMonth()+1).padStart(2,'0');
    const dia=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${dia}`;
}

function cpBloquesMensuales(desde, hasta){
    const inicio=cpFechaLocal(desde), fin=cpFechaLocal(hasta), bloques=[];
    let cursor=new Date(inicio.getFullYear(), inicio.getMonth(), inicio.getDate());

    while(cursor <= fin){
        const ultimoMes=new Date(cursor.getFullYear(), cursor.getMonth()+1, 0);
        const bloqueFin=ultimoMes < fin ? ultimoMes : fin;
        bloques.push({desde:cpIsoFecha(cursor), hasta:cpIsoFecha(bloqueFin)});
        cursor=new Date(bloqueFin.getFullYear(), bloqueFin.getMonth(), bloqueFin.getDate()+1);
    }
    return bloques;
}

function cpActualizarProgreso(idx,total,bloque,cheques,egresos,disp){
    const pct=Math.round((idx/total)*100);
    $('#cpSyncPct').text(pct+'%');
    $('#cpSyncBar').css('width',pct+'%');
    $('#cpSyncCheques').text(cheques.toLocaleString());
    $('#cpSyncEgresos').text(egresos.toLocaleString());
    $('#cpSyncDisp').text(disp.toLocaleString());
    $('#cpSyncBloques').text(`${idx} / ${total}`);
    if(bloque){
        $('#cpSyncTitulo').text(`Sincronizando bloque ${idx+1} de ${total}`);
        $('#cpSyncDetalle').text(`${bloque.desde} al ${bloque.hasta}`);
    }
}

function cancelarSincronizacionContpaq(){
    cpSyncCancelada=true;
    $('#btnCancelarSyncContpaq').prop('disabled',true).html('<i class="bi bi-hourglass-split me-1"></i>CANCELANDO...');
    // Abortamos la espera del navegador. El bloque SQL que ya inició puede alcanzar a terminar,
    // pero no se lanzará ningún bloque posterior. Al ser UPSERT, volver a correr es seguro.
    if(cpSyncXHR && cpSyncXHR.readyState !== 4){
        try { cpSyncXHR.abort(); } catch(e){}
    }
}

async function sincronizarContpaqCheques(){
    const desde=$('#cp_fecha_desde').val(), hasta=$('#cp_fecha_hasta').val();
    if(!desde || !hasta) return Swal.fire('Fechas','Seleccione el rango a sincronizar.','warning');
    if(desde > hasta) return Swal.fire('Fechas','La fecha inicial no puede ser mayor a la final.','warning');
    if(desde.substring(0,4) !== hasta.substring(0,4)) {
        return Swal.fire('Rango no permitido','La fecha inicial y la fecha final deben pertenecer al mismo año.','warning');
    }

    const bloques=cpBloquesMensuales(desde,hasta);
    cpSyncCancelada=false;
    let totalCheques=0, totalEgresos=0, totalDisp=0, terminados=0;

    $('#btnSyncContpaq').prop('disabled',true);
    $('#btnCancelarSyncContpaq').removeClass('d-none').prop('disabled',false)
        .html('<i class="bi bi-x-circle me-1"></i>CANCELAR');
    $('#cpSyncProgreso').removeClass('d-none');
    cpActualizarProgreso(0,bloques.length,bloques[0],0,0,0);

    try {
        for(let i=0;i<bloques.length;i++){
            if(cpSyncCancelada) break;
            const b=bloques[i];
            cpActualizarProgreso(i,bloques.length,b,totalCheques,totalEgresos,totalDisp);

            const r=await new Promise((resolve,reject)=>{
                cpSyncXHR=$.ajax({
                    url:'ajax/sincronizar_contpaq_cheques.php',
                    method:'POST',
                    dataType:'json',
                    data:{
                        fecha_desde:b.desde,
                        fecha_hasta:b.hasta,
                        csrf_token:<?=json_encode($csrf)?>
                    }
                }).done(resolve).fail((xhr,status)=>reject({xhr,status}));
            });

            if(!r.success) throw new Error(r.error||'Error desconocido');
            totalCheques += Number(r.cheques||0);
            totalEgresos += Number(r.egresos||0);
            totalDisp += Number(r.dispersiones||0);
            terminados++;
            cpActualizarProgreso(terminados,bloques.length,
                bloques[terminados]||null,totalCheques,totalEgresos,totalDisp);
        }

        if(cpSyncCancelada){
            $('#cpSyncTitulo').text('Sincronización cancelada');
            $('#cpSyncDetalle').text(`Se conservaron ${terminados} bloque(s) ya procesados.`);
            await Swal.fire('Sincronización cancelada',
                `Se conservaron ${totalCheques.toLocaleString()} cheques y ${totalDisp.toLocaleString()} dispersiones procesadas.`,
                'info');
        } else {
            $('#cpSyncPct').text('100%');
            $('#cpSyncBar').css('width','100%').removeClass('progress-bar-animated');
            $('#cpSyncTitulo').text('Sincronización terminada');
            $('#cpSyncDetalle').text(`${desde} al ${hasta}`);
            await Swal.fire('Sincronización terminada',
                `${totalCheques.toLocaleString()} cheques, ${totalEgresos.toLocaleString()} egresos y ${totalDisp.toLocaleString()} facturas aplicadas en ${terminados} bloque(s).`,
                'success');
        }

        cargarModulo('contpaq_cheques');
    } catch(err){
        if(cpSyncCancelada || err?.status === 'abort'){
            $('#cpSyncTitulo').text('Sincronización cancelada');
            $('#cpSyncDetalle').text('No se lanzarán más bloques. Los datos ya copiados permanecen guardados.');
            await Swal.fire('Cancelada','Los bloques ya procesados permanecen guardados.','info');
            cargarModulo('contpaq_cheques');
        } else {
            let msg=err?.message||'No fue posible sincronizar.';
            try {
                const rr=JSON.parse(err?.xhr?.responseText||'{}');
                if(rr.error) msg=rr.error;
            } catch(e){}
            Swal.fire('Error',msg,'error');
        }
    } finally {
        cpSyncXHR=null;
        $('#btnSyncContpaq').prop('disabled',false);
        $('#btnCancelarSyncContpaq').addClass('d-none').prop('disabled',false)
            .html('<i class="bi bi-x-circle me-1"></i>CANCELAR');
    }
}

var cpConcXHR=null;
var cpConcCancelada=false;
var cpConcInicioMs=0;

function cpFmtDuracion(seg){
    seg=Math.max(0,Number(seg||0));
    if(seg<60) return seg.toFixed(seg<10?2:1)+' s';
    const h=Math.floor(seg/3600), m=Math.floor((seg%3600)/60), s=Math.floor(seg%60);
    return (h>0?String(h).padStart(2,'0')+':':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}

function cancelarConciliacionContpaq(){
    cpConcCancelada=true;
    if(cpConcXHR && cpConcXHR.readyState!==4) cpConcXHR.abort();
    $('#btnCancelarConciliacion').prop('disabled',true).html('<i class="bi bi-hourglass-split me-1"></i>CANCELANDO...');
}

function cpActualizarConciliacion(procesadas,total,stats,periodo){
    const pct=total>0?Math.min(100,Math.round((procesadas/total)*100)):100;
    $('#cpConcPct').text(pct+'%');
    $('#cpConcBar').css('width',pct+'%');
    $('#cpConcTitulo').text('Conciliando '+periodo);
    $('#cpConcDetalle').text(`${procesadas.toLocaleString()} de ${total.toLocaleString()} dispersiones del periodo`);
    $('#cpConcRevisadas').text(Number(stats.revisadas||0).toLocaleString());
    $('#cpConcCreadas').text(Number(stats.creadas||0).toLocaleString());
    $('#cpConcParciales').text(Number(stats.parciales||0).toLocaleString());
    $('#cpConcSinCruce').text(Number(stats.sin_coincidencia||0).toLocaleString());
    $('#cpConcFacturas').text(Number(stats.facturas||0).toLocaleString());
    $('#cpConcCanceladas').text(Number((stats.canceladas_retiradas||0)+(stats.canceladas||0)).toLocaleString());
    $('#cpConcErrores').text(Number(stats.errores||0).toLocaleString());
    if(cpConcInicioMs>0){
        const trans=(Date.now()-cpConcInicioMs)/1000;
        const promedio=procesadas>0?trans/procesadas:0;
        const restante=procesadas>0?Math.max(0,total-procesadas)*promedio:0;
        $('#cpConcTiempoTotal').text(cpFmtDuracion(trans));
        $('#cpConcPromedio').text(promedio.toFixed(3)+' s');
        $('#cpConcRestante').text(procesadas>0?cpFmtDuracion(restante):'-');
    }
}

async function conciliarContpaqPagos(){
    const btn=$('#btnConciliarContpaq');
    const periodo=String($('#cp_periodo_conciliar').val()||'').trim();
    if(!/^20\d{2}-(0[1-9]|1[0-2])$/.test(periodo)){
        await Swal.fire('Periodo requerido','Seleccione el año y mes que desea conciliar.','warning');
        return;
    }
    const [anio,mes]=periodo.split('-');
    const meses=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    const periodoTexto=`${meses[Number(mes)-1]} de ${anio}`;
    const ok=await Swal.fire({
        title:'Conciliar '+periodoTexto,
        html:'Se procesará <b>una dispersión por vez</b>, mostrando el avance y los tiempos en pantalla.<br><br>'+
             'El proceso continuará automáticamente y podrá detenerlo con <b>Cancelar procedimiento</b>.',
        icon:'question',showCancelButton:true,confirmButtonText:'Sí, iniciar',cancelButtonText:'Cancelar',focusCancel:true
    });
    if(!ok.isConfirmed) return;

    cpConcCancelada=false;
    btn.prop('disabled',true).html('<i class="bi bi-hourglass-split me-1"></i>CONCILIANDO...');
    $('#btnCancelarConciliacion').removeClass('d-none').prop('disabled',false)
        .html('<i class="bi bi-stop-circle me-1"></i>CANCELAR PROCEDIMIENTO');
    $('#cpConcProgreso').removeClass('d-none');
    $('#cpConcBar').css('width','0%').addClass('progress-bar-animated');
    cpConcInicioMs=Date.now();
    const inicioTexto=new Date().toLocaleTimeString();
    $('#cpConcInicio').text(inicioTexto);

    const stats={revisadas:0,creadas:0,parciales:0,sin_coincidencia:0,ya_conciliadas:0,facturas:0,
        canceladas_retiradas:0,movimientos_cancelados_retirados:0,cfdi_cancelados_retirados:0,
        facturas_canceladas_detectadas:0,rep_cancelados_detectados:0,canceladas:0,errores:0};
    const historial=[];
    let cursor=0, procesadas=0, total=0;

    const htmlTiempos=(r)=>{
        const nombres={
            buscar_dispersion:'Buscar dispersión',
            verificar_duplicado:'Verificar duplicado',
            validar_cancelado:'Validar cancelado',
            validar_uuid:'Validar UUID',
            buscar_detalles_sat:'Buscar factura/detalle SAT',
            validar_importe:'Validar importe',
            seleccionar_detalle:'Seleccionar detalle',
            guardar_aplicacion:'Guardar aplicación',
            actualizar_estatus_dispersion:'Actualizar estatus dispersión',
            recalcular_detalle:'Recalcular detalle',
            actualizar_estatus_detalle:'Actualizar estatus detalle',
            recalcular_factura:'Recalcular factura (consulta optimizada por índice)',
            validar_factura_cancelada:'Validar estado SAT de la factura',
            validar_rep_cancelado:'Validar estado SAT del complemento REP',
            error:'Error'
        };
        const t=r.tiempos||{};
        const filas=Object.keys(t).map(k=>`<tr><td class="text-start">${cpEsc(nombres[k]||k)}</td><td class="text-end"><b>${Number(t[k]||0).toFixed(6)} s</b></td></tr>`).join('');
        return filas||'<tr><td colspan="2" class="text-muted">Sin desglose de tiempos.</td></tr>';
    };

    try{
        $('#cpConcTitulo').text('Preparando '+periodoTexto+'...');
        $('#cpConcDetalle').text('Validando estructura y contando dispersiones del periodo.');
        $('#cpConcActual').text('Preparación ligera, sin recorrer conciliaciones históricas.');
        cpConcXHR=$.ajax({
            url:'ajax/conciliar_contpaq_pagos.php',method:'POST',dataType:'json',
            data:{accion:'init',periodo,csrf_token:<?=json_encode($csrf)?>}
        });
        const init=await cpConcXHR;
        if(!init.success) throw new Error(init.error||'No fue posible preparar la conciliación.');
        total=Number(init.total||0);
        stats.canceladas_retiradas=Number(init.canceladas_retiradas||0);
        stats.movimientos_cancelados_retirados=Number(init.movimientos_cancelados_retirados||0);
        stats.cfdi_cancelados_retirados=Number(init.cfdi_cancelados_retirados||0);
        stats.facturas_canceladas_detectadas=Number(init.facturas_canceladas_detectadas||0);
        stats.rep_cancelados_detectados=Number(init.rep_cancelados_detectados||0);
        cpActualizarConciliacion(0,total,stats,periodoTexto);
        const tp=init.tiempos_preparacion||{};
        $('#cpConcResultado').text(`Preparación terminada en ${Number(tp.total||0).toFixed(3)} s · `+
            `estructura ${Number(tp.validar_estructura||0).toFixed(3)} s · conteo ${Number(tp.contar_dispersiones||0).toFixed(3)} s`);

        if(stats.canceladas_retiradas>0){
            $('#cpConcResultado').text(
                `Reversiones iniciales: ${stats.movimientos_cancelados_retirados} por movimiento CONTPAQ cancelado; `+
                `${stats.cfdi_cancelados_retirados} por factura/REP cancelado ante SAT.`
            );
        }

        if(total===0){
            await Swal.fire('Periodo revisado','No hay dispersiones en '+periodoTexto+'.','info');
            return;
        }

        while(!cpConcCancelada && procesadas<total){
            cpConcXHR=$.ajax({
                url:'ajax/conciliar_contpaq_pagos.php',method:'POST',dataType:'json',
                data:{accion:'step',periodo,cursor,csrf_token:<?=json_encode($csrf)?>}
            });
            const r=await cpConcXHR;
            if(!r.success) throw new Error(r.error||'No fue posible procesar la dispersión.');

            cursor=Number(r.cursor||cursor);
            const paso=Number(r.procesadas||0);
            if(paso===0 || r.done) break;
            procesadas+=paso;
            for(const k of ['revisadas','creadas','parciales','sin_coincidencia','ya_conciliadas','facturas','canceladas','errores','canceladas_retiradas']){
                stats[k]+=Number(r[k]||0);
            }

            const actual=`#${Number(r.id_dispersion||0)} · ${r.fecha_pago||'-'} · $${Number(r.importe||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
            $('#cpConcActual').text(actual+(r.uuid?` · UUID ${r.uuid}`:''));
            $('#cpConcResultado').text(`${r.resultado||'REVISADO'}: ${r.mensaje||''}`);
            $('#cpConcTiempoUuid').text(`${r.hora_inicio||'-'} → ${r.hora_fin||'-'} · ${cpFmtDuracion(r.segundos_peticion||r.segundos_total||0)}`);
            historial.unshift(`${Number(r.id_dispersion||0)} ${r.resultado||'REVISADO'} (${Number(r.segundos_total||r.segundos_peticion||0).toFixed(3)}s)`);
            if(historial.length>10) historial.pop();
            $('#cpConcHistorial').text(historial.join(' · '));
            cpActualizarConciliacion(procesadas,total,stats,periodoTexto);

            // Diagnóstico visible en el panel, sin ventanas por cada UUID.
            // El proceso continúa automáticamente; el usuario puede detenerlo con el botón rojo.
            await new Promise(resolve=>setTimeout(resolve,15));
        }

        if(cpConcCancelada){
            $('#cpConcTitulo').text('Conciliación cancelada por el usuario');
            $('#cpConcDetalle').text('Todo lo ya procesado permanece guardado.');
            await Swal.fire('Procedimiento cancelado',
                `Se procesaron <b>${procesadas}</b> de <b>${total}</b> dispersiones.<br>Lo realizado quedó guardado y no se duplicará al reanudar.`,
                'info');
        }else{
            $('#cpConcBar').css('width','100%').removeClass('progress-bar-animated');
            $('#cpConcPct').text('100%');
            $('#cpConcTitulo').text('Conciliación terminada');
            $('#cpConcDetalle').text(periodoTexto);
            await Swal.fire('Conciliación terminada',
                `Periodo: <b>${periodoTexto}</b><br>`+
                `Revisadas: <b>${stats.revisadas.toLocaleString()}</b><br>`+
                `Aplicaciones nuevas: <b>${stats.creadas.toLocaleString()}</b><br>`+
                `Ya conciliadas: <b>${stats.ya_conciliadas.toLocaleString()}</b><br>`+
                `Parciales: <b>${stats.parciales.toLocaleString()}</b><br>`+
                `Sin coincidencia: <b>${stats.sin_coincidencia.toLocaleString()}</b><br>`+
                `Errores continuados: <b>${stats.errores.toLocaleString()}</b><br>`+
                `Aplicaciones revertidas por cancelación: <b>${stats.canceladas_retiradas.toLocaleString()}</b><br>`+
                `Facturas SAT canceladas detectadas: <b>${stats.facturas_canceladas_detectadas.toLocaleString()}</b><br>`+
                `REP cancelados detectados: <b>${stats.rep_cancelados_detectados.toLocaleString()}</b><br>`+
                `Inicio: <b>${inicioTexto}</b><br>`+
                `Fin: <b>${new Date().toLocaleTimeString()}</b><br>`+
                `Tiempo total: <b>${cpFmtDuracion((Date.now()-cpConcInicioMs)/1000)}</b>`,
                'success');
        }
        if(window.cpRecargarTablas) window.cpRecargarTablas();
    }catch(e){
        if(cpConcCancelada || e?.statusText==='abort'){
            await Swal.fire('Procedimiento cancelado','Los UUID ya procesados permanecen guardados.','info');
            if(window.cpRecargarTablas) window.cpRecargarTablas();
        }else{
            let msg=e?.message||'No fue posible conciliar.';
            try{const rr=JSON.parse(e?.responseText||'{}');if(rr.error)msg=rr.error;}catch(x){}
            Swal.fire('Error de conciliación',msg,'error');
        }
    }finally{
        cpConcXHR=null;
        btn.prop('disabled',false).html('<i class="bi bi-link-45deg me-1"></i>CONCILIAR PAGOS / APLICAR DIOT');
        $('#btnCancelarConciliacion').addClass('d-none').prop('disabled',false)
            .html('<i class="bi bi-stop-circle me-1"></i>CANCELAR PROCEDIMIENTO');
    }
}

// Enter en cualquiera de las dos fechas ejecuta la sincronización.
$('#cp_fecha_desde,#cp_fecha_hasta').off('keydown.cpSync').on('keydown.cpSync',function(e){
    if(e.key === 'Enter'){
        e.preventDefault();
        sincronizarContpaqCheques();
    }
});
</script>
