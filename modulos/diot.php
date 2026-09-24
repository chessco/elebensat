<?php session_start(); 
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'generar_diot'); } catch (Throwable $e) { http_response_code(403); exit($e->getMessage()); }
?>
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<style>
    .tabla-diot {
        font-size: 0.8rem;
    }

    .monto {
        text-align: right;
        font-family: 'Courier New', monospace;
        font-weight: bold;
    }
</style>

<div class="container-fluid py-3">
    <div class="card bg-dark border-secondary shadow-sm mb-3">
        <div class="card-header border-secondary">
            <h5 class="text-white mb-0"><i class="bi bi-file-earmark-spreadsheet-fill text-warning"></i> Generador de
                DIOT</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="text-muted small">Mes</label>
                    <select id="diot_mes" class="form-select bg-dark text-white border-secondary">
                        <?php
                        $meses = [
                            '01' => 'Enero',
                            '02' => 'Febrero',
                            '03' => 'Marzo',
                            '04' => 'Abril',
                            '05' => 'Mayo',
                            '06' => 'Junio',
                            '07' => 'Julio',
                            '08' => 'Agosto',
                            '09' => 'Septiembre',
                            '10' => 'Octubre',
                            '11' => 'Noviembre',
                            '12' => 'Diciembre'
                        ];
                        $actual = date('m');
                        foreach ($meses as $k => $v) {
                            $sel = ($k == $actual) ? 'selected' : '';
                            echo "<option value='$k' $sel>$v</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="text-muted small">Año</label>
                    <select id="diot_anio" class="form-select bg-dark text-white border-secondary">
                        <?php
                        $y = date('Y');
                        for ($i = $y; $i >= $y - 5; $i--) {
                            echo "<option value='$i'>$i</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-warning w-100 fw-bold" onclick="cargarDIOT()">
                        <i class="bi bi-search"></i> CONSULTAR / GENERAR
                    </button>
                </div>
                <div class="col-md-5 text-end">
                    <div id="area_botones_diot"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card bg-dark border-secondary shadow-sm">
        <div class="card-body p-2">
            <table id="tablaDIOT" class="table table-dark table-hover table-striped tabla-diot w-100">
                <thead>
                    <tr>
                        <th>T. Tercero</th>
                        <th>T. Op.</th>
                        <th>RFC</th>
                        <th>ID Fiscal</th>
                        <th>Nombre</th>
                        <th>País</th>
                        <th>Nacionalidad</th>
                        <th class="monto">Valor 16%</th>
                        <th class="monto">IVA 16% NA</th>
                        <th class="monto">Valor 8%</th>
                        <th class="monto">IVA 8% NA</th>
                        <th class="monto">Imp. 16%</th>
                        <th class="monto">IVA Imp. 16% NA</th>
                        <th class="monto">Imp. 8%</th>
                        <th class="monto">IVA Imp. 8% NA</th>
                        <th class="monto">Valor 0%</th>
                        <th class="monto">Exento</th>
                        <th class="monto">IVA Ret</th>
                        <th class="monto">Devoluciones</th>
                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th colspan="7" class="text-end">Totales:</th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                        <th class="monto"></th>
                    </tr>
                </tfoot>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    var tableDIOT;

    $(document).ready(function () {
        initTable();
    });

    function initTable() {
        tableDIOT = $('#tablaDIOT').DataTable({
            dom: 'rt',
            paging: false,
            scrollY: '60vh',
            scrollCollapse: true,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-MX.json' },
            columns: [
                { data: 'c01_tipo_tercero', className: 'text-center' },
                { data: 'c02_tipo_operacion', className: 'text-center' },
                { data: 'c03_rfc' },
                { data: 'c04_id_fiscal' },
                { data: 'c05_nombre_extranjero', render: function (d, t, r) { return d ? d : r.c03_rfc; } }, // Mostrar RFC o Nombre
                { data: 'c06_pais' },
                { data: 'c07_nacionalidad' },
                { data: 'c08_valor_16', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') }, // DIOT usa enteros redondeados usualmente, pero dejamos decimales si usuario prefiere, SAT pide rounding? SAT pide ENTEROS sin decimales en TXT. En visual dejamos decimales.
                { data: 'c09_iva_16_no_acred', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c10_valor_8', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c11_iva_8_no_acred', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c12_valor_imp_16', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c13_iva_imp_16_no_acred', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c14_valor_imp_8', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c15_iva_imp_8_no_acred', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c16_valor_0', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c17_valor_exento', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c18_iva_retenido', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') },
                { data: 'c19_devoluciones', className: 'monto', render: $.fn.dataTable.render.number(',', '.', 0, '') }
            ],
            initComplete: function () {
                crearBotones();
            },
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();

                // Función auxiliar para convertir a número
                var intVal = function (i) {
                    return typeof i === 'string' ? i.replace(/[\$,]/g, '') * 1 : typeof i === 'number' ? i : 0;
                };

                // Función auxiliar para formatear moneda
                var fmt = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0, maximumFractionDigits: 0 });

                // Columnas a sumar (Indices 7 a 18)
                // 7: Valor 16% ... 18: Devoluciones
                for (var i = 7; i <= 18; i++) {
                    var total = api.column(i, { page: 'current' }).data().reduce(function (a, b) {
                        return intVal(a) + intVal(b);
                    }, 0);
                    $(api.column(i).footer()).html(fmt.format(total));
                }
            }
        });
    }

    function cargarDIOT() {
        let mes = $('#diot_mes').val();
        let anio = $('#diot_anio').val();

        tableDIOT.ajax.url('ajax/listar_diot.php?mes=' + mes + '&anio=' + anio).load(function () {
            crearBotones();
        });
    }

    function crearBotones() {
        $('#area_botones_diot').empty();
        if (typeof $.fn.dataTable.Buttons !== 'undefined') {
            new $.fn.dataTable.Buttons(tableDIOT, {
                buttons: [
                    {
                        extend: 'excel',
                        text: '<i class="bi bi-file-earmark-excel"></i> Excel Completo',
                        className: 'btn btn-success',
                        title: 'DIOT_' + $('#diot_mes').val() + '_' + $('#diot_anio').val(),
                        exportOptions: {
                            format: {
                                body: function (data, row, column, node) {
                                    return typeof data === 'string' ? data.replace(/[$,]/g, '') : data;
                                }
                            }
                        }
                    },
                    {
                        text: '<i class="bi bi-gear-wide-connected"></i> TXT SAT (Directo)',
                        className: 'btn btn-info fw-bold',
                        action: function (e, dt, node, config) {
                            let m = $('#diot_mes').val();
                            let a = $('#diot_anio').val();
                            window.location.href = 'ajax/generar_txt_diot.php?mes=' + m + '&anio=' + a;
                        }
                    },
                    {
                        extend: 'csv',
                        text: '<i class="bi bi-file-earmark-text"></i> CSV (Vista Previa)',
                        className: 'btn btn-secondary btn-sm',
                        title: 'DIOT_PREVIEW_' + $('#diot_mes').val() + '_' + $('#diot_anio').val(),
                        exportOptions: {
                            format: {
                                body: function (data, row, column, node) {
                                    return typeof data === 'string' ? data.replace(/[$,]/g, '') : data;
                                }
                            }
                        }
                    }
                ]
            }).container().appendTo('#area_botones_diot');
        }
    }
</script>