<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once __DIR__ . '/../includes/permisos_documentos.php';
$columnasDiot = require __DIR__ . '/../includes/diot_columnas.php';

try {
    exigir_permiso_accion(
        $pdo,
        (int)($_SESSION['id_usuario'] ?? 0),
        (int)($_SESSION['id_empresa'] ?? 0),
        'generar_diot'
    );
} catch (Throwable $e) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3"><strong>Sin acceso a DIOT.</strong><br>'
       . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}

$gruposDiot = [
    ['titulo' => 'DATOS DEL TERCERO', 'desde' => 1, 'hasta' => 7, 'clase' => 'grupo-tercero'],
    ['titulo' => 'VALOR DE ACTOS O ACTIVIDADES', 'desde' => 8, 'hasta' => 17, 'clase' => 'grupo-actos'],
    ['titulo' => 'IVA ACREDITABLE', 'desde' => 18, 'hasta' => 27, 'clase' => 'grupo-acreditable'],
    ['titulo' => 'IVA NO ACREDITABLE', 'desde' => 28, 'hasta' => 47, 'clase' => 'grupo-no-acreditable'],
    ['titulo' => 'DATOS ADICIONALES', 'desde' => 48, 'hasta' => 54, 'clase' => 'grupo-adicionales'],
];
?>
<div class="card bg-dark text-white border-secondary">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-file-earmark-ruled"></i> Generador DIOT (54 Columnas) <span id="periodo_diot_aplicado" class="badge bg-secondary ms-2">Sin filtrar</span></h5>
        <div class="d-flex gap-2 flex-wrap">
            <select id="anio_diot" class="form-select form-select-sm bg-dark text-white border-secondary" style="width:100px;">
                <?php
                $anio_actual = date('Y');
                for ($a = $anio_actual; $a >= 2024; $a--) echo "<option value='$a'>$a</option>";
                ?>
            </select>
            <select id="mes_diot" class="form-select form-select-sm bg-dark text-white border-secondary" style="width:130px;">
                <?php
                $meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
                foreach ($meses as $i => $m) {
                    $val = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
                    $sel = ($val == date('m')) ? 'selected' : '';
                    echo "<option value='$val' $sel>$m</option>";
                }
                ?>
            </select>
            <button class="btn btn-primary btn-sm" onclick="recargarDiot()"><i class="bi bi-search"></i> Filtrar</button>
            <button class="btn btn-success btn-sm" onclick="generarArchivoBatch()"><i class="bi bi-download"></i> Descargar .txt</button>
            <button class="btn btn-outline-success btn-sm" onclick="exportarExcelDIOT()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
            <button class="btn btn-warning btn-sm" onclick="confirmarAuditoriaDiotCompleta()"><i class="bi bi-file-earmark-spreadsheet"></i> Auditoría completa</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 px-3 mb-2 diot-ayuda">
            <i class="bi bi-info-circle"></i>
            La DIOT se calcula con los detalles de pago cuya fecha fiscal pertenece al mes y año seleccionados, acumulados por emisor/proveedor.
            Las columnas muestran un nombre corto; pase el mouse sobre el encabezado para ver la descripción completa del SAT. Las columnas Tipo tercero, Tipo operación, RFC y Nombre emisor permanecen fijas. El nombre es sólo visual y no forma parte del TXT DIOT.
        </div>
        <div class="d-flex align-items-end gap-2 flex-wrap mb-2 diot-filtros-rapidos">
            <div>
                <label for="filtroDetalleDiot" class="form-label form-label-sm mb-1 fw-semibold">
                    <i class="bi bi-funnel"></i> Ver detalle
                </label>
                <select id="filtroDetalleDiot" class="form-select form-select-sm" style="min-width:235px;">
                    <option value="todos">Todos los emisores</option>
                    <option value="base16">Con Base 16%</option>
                    <option value="iva16">Con IVA 16%</option>
                    <option value="base0">Con Base 0%</option>
                    <option value="ivaretenido">Con IVA retenido</option>
                    <option value="exentos">Con operaciones exentas</option>
                    <option value="noacreditable">Con IVA no acreditable</option>
                    <option value="noobjeto">Con operaciones no objeto</option>
                </select>
            </div>
            <div class="small text-muted pb-1">
                El filtro muestra únicamente emisores con importe en el concepto seleccionado y recalcula los totales visibles.
            </div>
        </div>
        <div class="table-responsive diot-table-wrap">
            <table id="tablaDIOT" class="table table-dark table-sm table-hover w-100" style="font-size: 11px;">
                <thead>
                    <tr class="diot-grupos">
                        <?php foreach ($gruposDiot as $grupo): ?>
                            <th colspan="<?= $grupo['hasta'] - $grupo['desde'] + 1 + ($grupo['desde'] === 1 ? 1 : 0) ?>" class="<?= htmlspecialchars($grupo['clase']) ?>">
                                <?= htmlspecialchars($grupo['titulo'], ENT_QUOTES, 'UTF-8') ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="diot-encabezados">
                        <?php for ($i = 1; $i <= 54; $i++):
                            $col = $columnasDiot[$i];
                            $titulo = str_replace("\n", ' · ', $col['descripcion']);
                        ?>
                            <th title="<?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>" data-columna="<?= $i ?>">
                                <span class="diot-col-num">Col. <?= $i ?></span>
                                <span class="diot-col-nombre"><?= htmlspecialchars($col['corto'], ENT_QUOTES, 'UTF-8') ?></span>
                            </th>
                            <?php if ($i === 3): ?>
                                <th title="Nombre del emisor/proveedor. Columna visual; no forma parte del archivo TXT DIOT." data-columna-visual="nombre_emisor">
                                    <span class="diot-col-num">Visual</span>
                                    <span class="diot-col-nombre">Nombre emisor</span>
                                </th>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tfoot>
                    <tr><?php for ($i = 1; $i <= 54; $i++) { echo "<th id='total_c" . str_pad($i, 2, '0', STR_PAD_LEFT) . "'>-</th>"; if ($i === 3) echo "<th id='total_nombre_emisor'>-</th>"; } ?></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProcesoAuditoriaDiot" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet"></i> Generando auditoría DIOT completa</h5>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-between mb-1 small">
          <span id="auditFullEstado">Preparando...</span>
          <span id="auditFullContador">0 / 0</span>
        </div>
        <div class="progress" style="height:22px;">
          <div id="auditFullBarra" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">0%</div>
        </div>
        <div class="small text-muted mt-2" id="auditFullEmisor">El proceso recorrerá emisor por emisor.</div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-danger btn-sm" id="btnCancelarAuditoriaCompleta"><i class="bi bi-x-circle"></i> Cancelar proceso</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalAuditoriaDiotEmisor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:96vw;">
    <div class="modal-content">
      <div class="modal-header py-2">
        <div>
          <h5 class="modal-title mb-0"><i class="bi bi-search"></i> Auditoría DIOT por emisor</h5>
          <div class="small text-muted" id="auditDiotIdentidad">-</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="auditDiotCargando" class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Consultando detalles...</div></div>
        <div id="auditDiotContenido" style="display:none;">
          <div class="row g-2 mb-3" id="auditDiotTarjetas"></div>
          <h6 class="text-primary"><i class="bi bi-receipt"></i> Complementos y detalles de pago SAT</h6>
          <div class="table-responsive mb-3"><table class="table table-sm table-striped table-bordered audit-table"><thead><tr>
            <th>Fecha fiscal</th><th>Fecha SAT</th><th>Factura</th><th>UUID factura</th><th>UUID complemento</th><th>Parc.</th><th>Pago MXN</th><th>Base 16 MXN</th><th>IVA 16 MXN</th><th>Base 8</th><th>IVA 8</th><th>Tasa 0</th><th>Exento</th><th>No objeto</th><th>IVA ret.</th><th>ISR ret.</th><th>IEPS</th><th>Forma</th><th>Referencia</th><th>Estado CONTPAQ</th>
          </tr></thead><tbody id="auditDiotSatBody"></tbody><tfoot id="auditDiotSatFoot"></tfoot></table></div>
          <h6 class="text-primary"><i class="bi bi-bank"></i> Aplicaciones CONTPAQ conciliadas</h6>
          <div class="table-responsive"><table class="table table-sm table-striped table-bordered audit-table"><thead><tr>
            <th>Tipo</th><th>Folio</th><th>Fecha</th><th>Beneficiario</th><th>Total movimiento</th><th>Importe SAT</th><th>Aplicado</th><th>Diferencia</th><th>Dif. días</th><th>Póliza</th><th>Referencia</th><th>UUID factura</th><th>UUID REP</th><th>Estatus</th>
          </tr></thead><tbody id="auditDiotContpaqBody"></tbody></table></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-success btn-sm" id="btnExportarAuditDiot"><i class="bi bi-file-earmark-excel"></i> Exportar detalle a Excel</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
    var columnasDiotUI = <?= json_encode($columnasDiot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function asegurarFixedColumns(callback) {
        if ($.fn.dataTable && $.fn.dataTable.FixedColumns) {
            callback();
            return;
        }

        if (!document.getElementById('dt-fixedcolumns-css-diot')) {
            $('<link>', {
                id: 'dt-fixedcolumns-css-diot',
                rel: 'stylesheet',
                href: 'https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css'
            }).appendTo('head');
        }

        $.getScript('https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js')
            .done(callback)
            .fail(function() {
                // El reporte sigue funcionando aunque el CDN de FixedColumns no responda.
                callback();
            });
    }

    // El módulo se carga por AJAX varias veces en la misma página.
    // Limpiamos referencias globales de la instancia anterior antes de crear la nueva.
    window.tableDiot = null;
    window.diotFiltroAplicado = null;
    window.diotXhrActual = null;

    // El módulo puede cargarse varias veces por AJAX. Quitamos el filtro
    // personalizado anterior para no acumular funciones en DataTables.
    if (window.diotFiltroDetalleFn && $.fn.dataTable && $.fn.dataTable.ext && $.fn.dataTable.ext.search) {
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn !== window.diotFiltroDetalleFn;
        });
    }

    function diotNumero(v) {
        if (v === null || v === undefined || v === '') return 0;
        if (typeof v === 'number') return isFinite(v) ? v : 0;
        const n = parseFloat(String(v).replace(/[$,\s]/g, ''));
        return isFinite(n) ? n : 0;
    }

    function diotTieneImporte(data, colNum) {
        const key = 'c' + String(colNum).padStart(2, '0');
        return Math.abs(diotNumero(data && data[key])) > 0.000001;
    }

    window.diotFiltroDetalleFn = function(settings, searchData, dataIndex, rowData) {
        if (!settings || !settings.nTable || settings.nTable.id !== 'tablaDIOT') return true;

        const filtro = String($('#filtroDetalleDiot').val() || 'todos');
        if (filtro === 'todos') return true;

        // rowData normalmente viene completo en DataTables 1.13. Si no, lo
        // recuperamos de la instancia para mantener compatibilidad.
        let d = rowData;
        if (!d && window.tableDiot) {
            try { d = window.tableDiot.row(dataIndex).data(); } catch (e) {}
        }
        d = d || {};

        switch (filtro) {
            case 'base16':
                return diotTieneImporte(d, 12);
            case 'iva16':
                return diotTieneImporte(d, 22);
            case 'base0':
                return diotTieneImporte(d, 51);
            case 'ivaretenido':
                return diotTieneImporte(d, 48);
            case 'exentos':
                return diotTieneImporte(d, 50);
            case 'noobjeto':
                return diotTieneImporte(d, 52);
            case 'noacreditable':
                // Las columnas 28 a 47 corresponden al bloque de IVA no
                // acreditable. Incluye el tratamiento proporcional (p.ej. Atlas).
                for (let c = 28; c <= 47; c++) {
                    if (diotTieneImporte(d, c)) return true;
                }
                return false;
            default:
                return true;
        }
    };

    if ($.fn.dataTable && $.fn.dataTable.ext && $.fn.dataTable.ext.search) {
        $.fn.dataTable.ext.search.push(window.diotFiltroDetalleFn);
    }

    function nombreMesDiot(mes) {
        const nombres = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        const n = parseInt(mes, 10);
        return (n >= 1 && n <= 12) ? nombres[n - 1] : mes;
    }

    window.obtenerPeriodoDiotAplicado = function() {
        return window.diotFiltroAplicado;
    };

    window.actualizarPeriodoDiotAplicado = function(mes, anio) {
        window.diotFiltroAplicado = { mes: String(mes), anio: String(anio) };
        $('#periodo_diot_aplicado')
            .removeClass('bg-secondary')
            .addClass('bg-primary')
            .text(nombreMesDiot(mes) + ' ' + anio);
    };

    function iniciarTablaDiot() {
        if ($.fn.DataTable.isDataTable('#tablaDIOT')) {
            $('#tablaDIOT').DataTable().destroy();
        }

        let columnas = [];
        for (let i = 1; i <= 54; i++) {
            let ancho = 110;
            if (i === 1) ancho = 82;
            if (i === 2) ancho = 98;
            if (i === 3) ancho = 128;
            if (i >= 4 && i <= 7) ancho = 135;

            const claseAlineacion = (i >= 8 && i <= 53) ? ' text-end' : '';
            columnas.push({
                data: 'c' + i.toString().padStart(2, '0'),
                defaultContent: '0',
                width: ancho + 'px',
                className: (i <= 3 ? 'diot-col-fija ' : '') + 'diot-c' + i.toString().padStart(2, '0') + ' text-nowrap' + claseAlineacion
            });

            // Columna SOLO VISUAL para facilitar la revisión contable.
            // No pertenece a las 54 columnas DIOT ni se usa para generar el TXT.
            if (i === 3) {
                columnas.push({
                    data: '_nombre',
                    defaultContent: '',
                    width: '260px',
                    className: 'diot-col-fija diot-nombre-emisor text-start'
                });
            }
        }

        const config = {
            ajax: function(data, callback) {
                const periodo = window.diotFiltroAplicado;

                // Al entrar al módulo NO consultar todavía. El usuario debe pulsar Filtrar.
                if (!periodo) {
                    callback({ data: [] });
                    return;
                }

                // Si hubiera una consulta anterior todavía viva, la cancelamos antes de lanzar otra.
                if (window.diotXhrActual && window.diotXhrActual.readyState !== 4) {
                    try { window.diotXhrActual.abort(); } catch (e) {}
                }

                window.diotXhrActual = $.ajax({
                    url: 'modulos/listar_diot_data.php',
                    method: 'GET',
                    dataType: 'json',
                    data: { mes: periodo.mes, anio: periodo.anio },
                    timeout: 30000
                }).done(function(resp) {
                    callback(resp && Array.isArray(resp.data) ? resp : { data: [] });
                }).fail(function(xhr, status) {
                    if (status === 'abort') return;
                    callback({ data: [] });
                    const msg = status === 'timeout'
                        ? 'La consulta DIOT tardó más de 30 segundos.'
                        : 'No se pudo obtener la información de la DIOT.';
                    Swal.fire('DIOT', msg, 'error');
                }).always(function() {
                    window.diotXhrActual = null;
                });
            },
            columns: columnas,
            // Usamos el scroll horizontal nativo del contenedor.
            // Evita que DataTables clone encabezado/cuerpo y desplace visualmente las columnas.
            scrollX: false,
            scrollCollapse: false,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [[10, 20, 30, 50, -1], [10, 20, 30, 50, 'Todos']],
            pagingType: 'simple_numbers',
            dom: 'Bfrtip',
            buttons: ['copy'],
            // Orden inicial por RFC del emisor/proveedor (columna 3; índice 2 en DataTables).
            order: [[2, 'asc']],
            createdRow: function(row, data) {
                $(row).attr('title', 'Doble clic para auditar al emisor').css('cursor','pointer');
                $(row).data('rfc-emisor', data._rfc || data.c03 || '');
            },
            orderCellsTop: false,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-MX.json' },
            footerCallback: function(row, data, start, end, display) {
                var api = this.api();
                var intVal = function(i) {
                    return typeof i === 'string' ? i.replace(/[\$,]/g, '') * 1 : typeof i === 'number' ? i : 0;
                };

                // Totales dinámicos: se recalculan SIEMPRE con las filas que
                // quedan después del filtro/búsqueda, sin importar la página actual.
                // Las columnas 8 a 53 del layout DIOT son numéricas.
                for (let colNum = 8; colNum <= 53; colNum++) {
                    // Después del RFC agregamos una columna visual (Nombre emisor),
                    // por lo que las columnas DIOT 4..54 están desplazadas +1 en DataTables.
                    const uiIndex = (colNum <= 3) ? (colNum - 1) : colNum;
                    const total = api
                        .column(uiIndex, { search: 'applied' })
                        .data()
                        .reduce(function(a, b) {
                            return intVal(a) + intVal(b);
                        }, 0);

                    $(api.column(uiIndex).footer()).html(
                        total.toLocaleString('es-MX', { minimumFractionDigits: 0 })
                    );
                }

                $(api.column(2).footer()).html('TOTALES:');
                $(api.column(3).footer()).html('');
            },
            initComplete: function() {
                // DataTables clona encabezados cuando usa scrollX; aseguramos tooltip nativo en todos.
                $('#tablaDIOT thead th[data-columna], .dataTables_scrollHead thead th[data-columna]').each(function() {
                    const n = parseInt($(this).attr('data-columna'), 10);
                    if (columnasDiotUI[n] && columnasDiotUI[n].descripcion) {
                        $(this).attr('title', columnasDiotUI[n].descripcion.replace(/\n/g, ' · '));
                    }
                });

                // Con scrollX + FixedColumns DataTables crea tablas separadas para encabezado/cuerpo.
                // Recalculamos después de que el navegador termine de pintar para que las 3 columnas
                // fijas tengan exactamente el mismo ancho arriba y abajo.
                const api = this.api();
                setTimeout(function() {
                    api.columns.adjust();
                    try {
                        if (typeof api.fixedColumns === 'function') {
                            const fc = api.fixedColumns();
                            if (fc && typeof fc.relayout === 'function') fc.relayout();
                            if (fc && typeof fc.update === 'function') fc.update();
                        }
                    } catch (e) {}
                }, 80);
            }
        };

        if ($.fn.dataTable && $.fn.dataTable.FixedColumns) {
            config.fixedColumns = { left: 4 };
        }

        window.tableDiot = $('#tablaDIOT').DataTable(config);

        // Filtro rápido contable/fiscal. Convive con el buscador general
        // de DataTables y dispara footerCallback para recalcular totales.
        $('#filtroDetalleDiot')
            .off('change.diotDetalle')
            .on('change.diotDetalle', function() {
                if (window.tableDiot) {
                    window.tableDiot.draw();
                }
            });
    }

    asegurarFixedColumns(iniciarTablaDiot);
})();

function auditEsc(v) {
    return $('<div>').text(v == null ? '' : String(v)).html();
}
function auditMoney(v) {
    return Number(v || 0).toLocaleString('es-MX',{style:'currency',currency:'MXN',minimumFractionDigits:2});
}
function auditFecha(v) {
    if (!v) return '-';
    const p=String(v).substring(0,10).split('-');
    return p.length===3 ? `${p[2]}/${p[1]}/${p[0]}` : auditEsc(v);
}
function abrirAuditoriaDiotEmisor(rfc, idEmisor) {
    rfc=(rfc||'').trim(); idEmisor=parseInt(idEmisor||0,10)||0; if(!rfc && !idEmisor) return;
    const modalEl=document.getElementById('modalAuditoriaDiotEmisor');
    const modal=bootstrap.Modal.getOrCreateInstance(modalEl);
    $('#auditDiotIdentidad').text(rfc);
    $('#auditDiotCargando').show(); $('#auditDiotContenido').hide();
    $('#btnExportarAuditDiot').off('click').on('click',function(){
        const periodo = periodoDiotParaAccion();
        if (!periodo) return;
        window.location.href=`ajax/exportar_detalle_diot_emisor_excel.php?id_emisor=${encodeURIComponent(idEmisor)}&rfc=${encodeURIComponent(rfc)}&mes=${encodeURIComponent(periodo.mes)}&anio=${encodeURIComponent(periodo.anio)}`;
    });
    modal.show();
    const periodoAudit = periodoDiotParaAccion();
    if (!periodoAudit) return;
    $.getJSON('ajax/detalle_diot_emisor.php',{id_emisor:idEmisor,rfc:rfc,mes:periodoAudit.mes,anio:periodoAudit.anio})
      .done(function(resp){
        if(!resp || resp.status!=='ok') throw new Error(resp && resp.msg ? resp.msg : 'Respuesta inválida');
        const e=resp.emisor||{}, r=resp.resumen||{};
        $('#auditDiotIdentidad').html(`<b>${auditEsc(e.nombre||'-')}</b> · RFC ${auditEsc(e.rfc||rfc)} · Periodo ${auditEsc(resp.periodo||'')}`);
        const cards=[['Facturas',r.facturas],['Complementos SAT',r.complementos_sat],['Detalles SAT',r.detalles_sat],['Pago SAT (MXN)',auditMoney(r.importe_aplicado)],['CONTPAQ conciliado',auditMoney(r.conciliado_contpaq)],['Diferencia',auditMoney(r.diferencia_sat_contpaq)],['Base 16 / IVA 16 (MXN)',auditMoney(r.base_iva_16)+' / '+auditMoney(r.iva_16)],['Base 8 / IVA 8',auditMoney(r.base_iva_8)+' / '+auditMoney(r.iva_8)],['Tasa 0',auditMoney(r.base_tasa_0)],['Exento',auditMoney(r.base_exento)],['No objeto',auditMoney(r.base_no_objeto)],['Retenciones',auditMoney(r.iva_retenido)+' IVA / '+auditMoney(r.isr_retenido)+' ISR']];
        $('#auditDiotTarjetas').html(cards.map(c=>`<div class="col-6 col-md-3 col-xl-2"><div class="border rounded p-2 h-100"><div class="small text-muted">${auditEsc(c[0])}</div><div class="fw-bold">${c[1]??0}</div></div></div>`).join(''));
        let sat=''; (resp.detalles_sat||[]).forEach(d=>{sat+=`<tr><td>${auditFecha(d.fecha_aplicacion_fiscal)}</td><td>${auditFecha(d.fecha_pago_sat)}</td><td>${auditEsc(((d.serie||'')+' '+(d.folio||'')).trim()||'S/F')}</td><td class="uuid-cell">${auditEsc(d.uuid_relacionado)}</td><td class="uuid-cell">${auditEsc(d.uuid_pago)}</td><td>${auditEsc(d.parcialidad)}</td><td class="text-end">${auditMoney(d.importe_aplicado)}</td><td class="text-end">${auditMoney(d.base_iva_16)}</td><td class="text-end">${auditMoney(d.iva_16)}</td><td class="text-end">${auditMoney(d.base_iva_8)}</td><td class="text-end">${auditMoney(d.iva_8)}</td><td class="text-end">${auditMoney(d.base_tasa_0)}</td><td class="text-end">${auditMoney(d.base_exento)}</td><td class="text-end">${auditMoney(d.base_no_objeto)}</td><td class="text-end">${auditMoney(d.iva_retenido)}</td><td class="text-end">${auditMoney(d.isr_retenido)}</td><td class="text-end">${auditMoney(d.ieps_otros)}</td><td>${auditEsc(d.forma_pago||'-')}</td><td>${auditEsc(d.referencia||'-')}</td><td>${auditEsc(d.estatus_conciliacion_contpaq||'PENDIENTE')}</td></tr>`;});
        if(!sat) sat='<tr><td colspan="20" class="text-center text-muted">Sin detalles SAT en el periodo.</td></tr>'; $('#auditDiotSatBody').html(sat);
        $('#auditDiotSatFoot').html(`<tr class="fw-bold"><td colspan="6">TOTALES</td><td class="text-end">${auditMoney(r.importe_aplicado)}</td><td class="text-end">${auditMoney(r.base_iva_16)}</td><td class="text-end">${auditMoney(r.iva_16)}</td><td class="text-end">${auditMoney(r.base_iva_8)}</td><td class="text-end">${auditMoney(r.iva_8)}</td><td class="text-end">${auditMoney(r.base_tasa_0)}</td><td class="text-end">${auditMoney(r.base_exento)}</td><td class="text-end">${auditMoney(r.base_no_objeto)}</td><td class="text-end">${auditMoney(r.iva_retenido)}</td><td class="text-end">${auditMoney(r.isr_retenido)}</td><td class="text-end">${auditMoney(r.ieps_otros)}</td><td colspan="3"></td></tr>`);
        let cp=''; (resp.movimientos_contpaq||[]).forEach(c=>{cp+=`<tr><td>${auditEsc(c.tipo_documento||c.tipo_origen||'-')}</td><td>${auditEsc(c.folio_contpaq||'-')}</td><td>${auditFecha(c.fecha_movimiento||c.fecha_pago_contpaq)}</td><td>${auditEsc(c.beneficiario||'-')}</td><td class="text-end">${auditMoney(c.total_movimiento)}</td><td class="text-end">${auditMoney(c.importe_sat)}</td><td class="text-end">${auditMoney(c.importe_conciliado)}</td><td class="text-end">${auditMoney(c.diferencia_importe)}</td><td>${c.diferencia_dias==null?'-':auditEsc(c.diferencia_dias)}</td><td>${auditEsc(c.num_pol||'-')}</td><td>${auditEsc(c.referencia_contpaq||'-')}</td><td class="uuid-cell">${auditEsc(c.uuid_factura||'-')}</td><td class="uuid-cell">${auditEsc(c.uuid_rep_contpaq||'-')}</td><td>${auditEsc(c.es_cancelado?'CANCELADO':(c.estatus||'-'))}</td></tr>`;});
        if(!cp) cp='<tr><td colspan="14" class="text-center text-warning">Los detalles SAT existen, pero no tienen movimiento CONTPAQ conciliado.</td></tr>'; $('#auditDiotContpaqBody').html(cp);
        $('#auditDiotCargando').hide(); $('#auditDiotContenido').show();
      }).fail(function(xhr){
        const msg=(xhr.responseJSON&&xhr.responseJSON.msg)?xhr.responseJSON.msg:'No se pudo cargar la auditoría.';
        $('#auditDiotCargando').html(`<div class="alert alert-danger">${auditEsc(msg)}</div>`);
      });
}

$(document).off('dblclick.auditDiot','#tablaDIOT tbody tr').on('dblclick.auditDiot','#tablaDIOT tbody tr',function(){
    let data=window.tableDiot ? window.tableDiot.row(this).data() : null;
    abrirAuditoriaDiotEmisor(data ? (data._rfc||data.c03) : $(this).data('rfc-emisor'), data ? (data._id_emisor||0) : 0);
});

function recargarDiot() {
    const mes = $('#mes_diot').val();
    const anio = $('#anio_diot').val();

    // El periodo solo cambia cuando el usuario pulsa Filtrar.
    if (typeof window.actualizarPeriodoDiotAplicado === 'function') {
        window.actualizarPeriodoDiotAplicado(mes, anio);
    }

    if (window.tableDiot) {
        window.tableDiot.ajax.reload(null, true);
    }
}

function periodoDiotParaAccion() {
    const p = (typeof window.obtenerPeriodoDiotAplicado === 'function')
        ? window.obtenerPeriodoDiotAplicado()
        : null;
    if (!p) {
        Swal.fire('Seleccione el periodo', 'Primero seleccione mes y año y pulse Filtrar.', 'warning');
        return null;
    }
    return p;
}

function generarArchivoBatch() {
    if (!window.tableDiot) return;
    const datos = window.tableDiot.rows().data().toArray();
    if (datos.length === 0) {
        Swal.fire('Sin datos', 'No hay registros en el periodo seleccionado', 'warning');
        return;
    }

    let contenidoTxt = '';
    datos.forEach(row => {
        let linea = [];
        for (let i = 1; i <= 54; i++) {
            let col = 'c' + i.toString().padStart(2, '0');
            linea.push(row[col] || '');
        }
        contenidoTxt += linea.join('|') + '\r\n';
    });

    const blob = new Blob([contenidoTxt], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const periodo = periodoDiotParaAccion();
    if (!periodo) return;
    a.download = `DIOT_BATCH_${periodo.mes}_${periodo.anio}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function exportarExcelDIOT() {
    const periodo = periodoDiotParaAccion();
    if (!periodo) return;
    window.location.href = `ajax/exportar_diot_excel.php?mes=${periodo.mes}&anio=${periodo.anio}`;
}

// IMPORTANTE: este script se vuelve a ejecutar cada vez que se entra a DIOT.
// Usar var permite reinicializar este estado sin SyntaxError por redeclaración global.
var auditFullCancelado = false;
var auditFullToken = '';
var auditFullXhr = null;

function confirmarAuditoriaDiotCompleta() {
    const periodo = periodoDiotParaAccion();
    if (!periodo) return;
    const mes = periodo.mes;
    const anio = periodo.anio;
    Swal.fire({
        title: '¿Generar auditoría completa?',
        html: `Se realizará un barrido <b>emisor por emisor</b> de la DIOT ${mes}/${anio}, incluyendo detalles SAT y aplicaciones CONTPAQ.<br><br>Durante el proceso podrás cancelarlo.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-play-fill"></i> Sí, generar',
        cancelButtonText: 'No',
        reverseButtons: true
    }).then(function(r) {
        if (r.isConfirmed) iniciarAuditoriaDiotCompleta();
    });
}

function iniciarAuditoriaDiotCompleta() {
    auditFullCancelado = false;
    auditFullToken = '';
    $('#auditFullEstado').text('Preparando emisores...');
    $('#auditFullContador').text('0 / 0');
    $('#auditFullEmisor').text('Creando archivo temporal de auditoría...');
    $('#auditFullBarra').css('width','0%').text('0%');
    $('#btnCancelarAuditoriaCompleta').prop('disabled', false).html('<i class="bi bi-x-circle"></i> Cancelar proceso');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProcesoAuditoriaDiot')).show();

    auditFullXhr = $.ajax({
        url: 'ajax/iniciar_auditoria_diot_excel.php',
        method: 'POST',
        dataType: 'json',
        data: (function(){ const p = periodoDiotParaAccion(); return p ? { mes: p.mes, anio: p.anio } : {}; })()
    }).done(function(resp) {
        if (!resp || resp.status !== 'ok') {
            falloAuditoriaDiotCompleta(resp && resp.msg ? resp.msg : 'No se pudo iniciar la auditoría.');
            return;
        }
        auditFullToken = resp.token;
        $('#auditFullContador').text(`0 / ${resp.total}`);
        if (resp.total <= 0) {
            falloAuditoriaDiotCompleta('No hay emisores DIOT en el periodo seleccionado.');
            return;
        }
        procesarSiguienteEmisorAuditoria();
    }).fail(function(xhr, status) {
        if (status !== 'abort' && !auditFullCancelado) {
            falloAuditoriaDiotCompleta((xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : 'No se pudo iniciar la auditoría.');
        }
    });
}

function procesarSiguienteEmisorAuditoria() {
    if (auditFullCancelado || !auditFullToken) return;
    auditFullXhr = $.ajax({
        url: 'ajax/procesar_auditoria_diot_excel.php',
        method: 'POST',
        dataType: 'json',
        data: { token: auditFullToken }
    }).done(function(resp) {
        if (auditFullCancelado) return;
        if (!resp || resp.status !== 'ok') {
            falloAuditoriaDiotCompleta(resp && resp.msg ? resp.msg : 'Error procesando un emisor.');
            return;
        }
        const pct = Math.max(0, Math.min(100, parseInt(resp.porcentaje || 0, 10)));
        $('#auditFullBarra').css('width', pct + '%').text(pct + '%');
        $('#auditFullContador').text(`${resp.procesados} / ${resp.total}`);
        $('#auditFullEstado').text(resp.completo ? 'Finalizando archivo...' : 'Procesando auditoría...');
        $('#auditFullEmisor').text(resp.emisor ? `${resp.emisor.nombre || ''} · ${resp.emisor.rfc || ''}` : 'Procesando...');

        if (resp.completo) {
            $('#auditFullBarra').removeClass('progress-bar-animated').css('width','100%').text('100%');
            $('#auditFullEstado').text('Auditoría terminada');
            $('#btnCancelarAuditoriaCompleta').prop('disabled', true).html('<i class="bi bi-check-circle"></i> Terminado');
            setTimeout(function() {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProcesoAuditoriaDiot')).hide();
                window.location.href = `ajax/descargar_auditoria_diot_excel.php?token=${encodeURIComponent(auditFullToken)}`;
                auditFullToken = '';
                Swal.fire('Auditoría generada', 'El barrido emisor por emisor terminó correctamente y el Excel se está descargando.', 'success');
            }, 350);
        } else {
            setTimeout(procesarSiguienteEmisorAuditoria, 20);
        }
    }).fail(function(xhr, status) {
        if (status !== 'abort' && !auditFullCancelado) {
            falloAuditoriaDiotCompleta((xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : 'Se interrumpió el procesamiento de la auditoría.');
        }
    });
}

function falloAuditoriaDiotCompleta(msg) {
    $('#btnCancelarAuditoriaCompleta').prop('disabled', true);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProcesoAuditoriaDiot')).hide();
    Swal.fire('No se pudo generar', msg, 'error');
}

$(document).off('click.auditFullCancel','#btnCancelarAuditoriaCompleta').on('click.auditFullCancel','#btnCancelarAuditoriaCompleta',function() {
    if (auditFullCancelado) return;
    Swal.fire({
        title: '¿Cancelar el proceso?',
        text: 'Se detendrá el barrido y se eliminará el archivo temporal generado hasta este momento.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'Continuar proceso',
        confirmButtonColor: '#dc3545'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        auditFullCancelado = true;
        if (auditFullXhr && auditFullXhr.readyState !== 4) { try { auditFullXhr.abort(); } catch(e) {} }
        const tok = auditFullToken;
        auditFullToken = '';
        $('#auditFullEstado').text('Cancelando...');
        $('#btnCancelarAuditoriaCompleta').prop('disabled', true);
        $.post('ajax/cancelar_auditoria_diot_excel.php', { token: tok }).always(function() {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProcesoAuditoriaDiot')).hide();
            Swal.fire('Proceso cancelado', 'La auditoría fue detenida y el archivo temporal fue eliminado.', 'info');
        });
    });
});
</script>

<style>
    #tablaDIOT {
        border-collapse: separate !important;
        border-spacing: 0;
    }



    .diot-filtros-rapidos {
        background: rgba(255,255,255,.035);
        border: 1px solid rgba(108,117,125,.35);
        border-radius: .35rem;
        padding: 8px 10px;
    }

    .diot-filtros-rapidos .form-select {
        font-size: 12px;
    }

    /* Un poco más de aire para lectura en el equipo de Contabilidad. */
    #tablaDIOT tbody td {
        padding-top: 8px !important;
        padding-bottom: 8px !important;
        line-height: 1.25;
        vertical-align: middle;
    }

    #tablaDIOT tbody td:nth-child(n+8):nth-child(-n+53),
    #tablaDIOT tfoot th:nth-child(n+8):nth-child(-n+53),
    #tablaDIOT tfoot td:nth-child(n+8):nth-child(-n+53) {
        text-align: right !important;
        font-variant-numeric: tabular-nums;
    }

    #tablaDIOT thead th {
        vertical-align: middle;
        text-align: center;
        white-space: normal !important;
    }

    #tablaDIOT thead tr.diot-grupos th {
        font-size: 10px;
        letter-spacing: .25px;
        padding: 5px 4px;
        border-bottom: 1px solid #6c757d;
    }

    #tablaDIOT thead tr.diot-encabezados th {
        min-width: 105px;
        max-width: 145px;
        height: 72px;
        padding: 5px 6px;
        line-height: 1.12;
        cursor: help;
    }

    #tablaDIOT thead tr.diot-encabezados th:nth-child(1) { min-width: 82px; }
    #tablaDIOT thead tr.diot-encabezados th:nth-child(2) { min-width: 98px; }
    #tablaDIOT thead tr.diot-encabezados th:nth-child(3) { min-width: 128px; }



    /*
     * Anchos estrictos de las cuatro columnas fijas (incluye Nombre emisor visual).
     * Se aplican tanto al encabezado clonado por scrollX como a las celdas del cuerpo/footer.
     * Esto evita que los iconos de ordenamiento o el texto del encabezado ensanchen solamente el THEAD.
     */
    #tablaDIOT th[data-columna="1"],
    .dataTables_scrollHead th[data-columna="1"],
    #tablaDIOT td.diot-c01,
    #tablaDIOT tfoot th:nth-child(1) {
        width: 96px !important;
        min-width: 96px !important;
        max-width: 96px !important;
        box-sizing: border-box !important;
    }

    #tablaDIOT th[data-columna="2"],
    .dataTables_scrollHead th[data-columna="2"],
    #tablaDIOT td.diot-c02,
    #tablaDIOT tfoot th:nth-child(2) {
        width: 120px !important;
        min-width: 120px !important;
        max-width: 120px !important;
        box-sizing: border-box !important;
    }

    #tablaDIOT th[data-columna="3"],
    .dataTables_scrollHead th[data-columna="3"],
    #tablaDIOT td.diot-c03,
    #tablaDIOT tfoot th:nth-child(3) {
        width: 155px !important;
        min-width: 155px !important;
        max-width: 155px !important;
        box-sizing: border-box !important;
    }

    #tablaDIOT th[data-columna-visual="nombre_emisor"],
    #tablaDIOT td.diot-nombre-emisor,
    #tablaDIOT tfoot th:nth-child(4) {
        width: 260px !important;
        min-width: 260px !important;
        max-width: 260px !important;
        box-sizing: border-box !important;
        white-space: normal !important;
    }


    /* Freeze visual hasta Nombre emisor: Tipo tercero, Tipo operación, RFC y Nombre. */
    #tablaDIOT thead tr.diot-encabezados th:nth-child(1),
    #tablaDIOT tbody td:nth-child(1),
    #tablaDIOT tfoot th:nth-child(1) { position: sticky !important; left: 0 !important; z-index: 5; }

    #tablaDIOT thead tr.diot-encabezados th:nth-child(2),
    #tablaDIOT tbody td:nth-child(2),
    #tablaDIOT tfoot th:nth-child(2) { position: sticky !important; left: 96px !important; z-index: 5; }

    #tablaDIOT thead tr.diot-encabezados th:nth-child(3),
    #tablaDIOT tbody td:nth-child(3),
    #tablaDIOT tfoot th:nth-child(3) { position: sticky !important; left: 216px !important; z-index: 5; }

    #tablaDIOT thead tr.diot-encabezados th:nth-child(4),
    #tablaDIOT tbody td:nth-child(4),
    #tablaDIOT tfoot th:nth-child(4) { position: sticky !important; left: 371px !important; z-index: 5; }

    #tablaDIOT thead tr.diot-encabezados th:nth-child(-n+4) { z-index: 8; background: #dbeaf2 !important; }
    #tablaDIOT tbody td:nth-child(-n+4) { background: inherit; }
    #tablaDIOT tfoot th:nth-child(-n+4) { z-index: 7; background: #eef6fb !important; }
    #tablaDIOT thead tr.diot-encabezados th:nth-child(4),
    #tablaDIOT tbody td:nth-child(4),
    #tablaDIOT tfoot th:nth-child(4) { box-shadow: 3px 0 4px rgba(0,0,0,.12); }

    .diot-col-num {
        display: block;
        font-size: 9px;
        font-weight: 700;
        opacity: .72;
        margin-bottom: 3px;
    }

    .diot-col-nombre {
        display: block;
        font-size: 10px;
        font-weight: 700;
    }

    .grupo-tercero, .grupo-acreditable, .grupo-adicionales {
        background: #537a49 !important;
        color: #fff !important;
    }

    .grupo-actos, .grupo-no-acreditable {
        background: #aab7bf !important;
        color: #10202b !important;
    }

    #tablaDIOT tfoot th {
        border-top: 2px solid #30363d !important;
        padding: 8px 4px;
        font-weight: bold;
        color: #0d6efd;
        background: #eef6fb;
    }

    .dataTables_scrollFootInner {
        background-color: #eef6fb !important;
    }

    .diot-ayuda {
        font-size: 12px;
    }

    /* FixedColumns usa celdas clonadas/posicionadas; conservamos fondo legible. */
    table.dataTable tbody tr > .dtfc-fixed-left,
    table.dataTable thead tr > .dtfc-fixed-left,
    table.dataTable tfoot tr > .dtfc-fixed-left {
        background-color: inherit;
    }

    .dtfc-fixed-left {
        box-shadow: 2px 0 3px rgba(0,0,0,.10);
    }
    .audit-table { font-size: 11px; white-space: nowrap; }
    .audit-table th { position: sticky; top: 0; background: #e9f3f8; z-index: 1; }
    .audit-table .uuid-cell { font-family: monospace; max-width: 190px; white-space: normal; word-break: break-all; }
    #tablaDIOT tbody tr:hover { outline: 1px solid rgba(13,110,253,.35); }

    /* DIOT v12: un solo scroll para encabezado, cuerpo y pie.
       Con 54 columnas no dejamos que DataTables genere una segunda tabla de encabezado. */
    .diot-table-wrap {
        overflow-x: auto !important;
        overflow-y: visible !important;
        width: 100%;
    }
    #tablaDIOT {
        width: max-content !important;
        min-width: 6400px !important;
        table-layout: fixed !important;
    }
    #tablaDIOT thead,
    #tablaDIOT tbody,
    #tablaDIOT tfoot {
        width: auto !important;
    }
</style>
