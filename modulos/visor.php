<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
// Mantener abierta solo hasta guardar la clave CSRF de esta pantalla.
seguridad_exigir_sesion($pdo, true, false, true);
require_once '../includes/permisos_documentos.php';
$idUsuarioPerm = (int)($_SESSION['id_usuario'] ?? 0);
$idEmpresaPerm = (int)($_SESSION['id_empresa'] ?? 0);
$tiposPermitidos = obtener_permisos_documentos($pdo, $idUsuarioPerm, $idEmpresaPerm);
$accionesPermitidas = obtener_permisos_acciones($pdo, $idUsuarioPerm, $idEmpresaPerm);
$puedeVerXmlPdf = in_array('ver_xml_pdf',$accionesPermitidas,true);
$puedeDescargarXml = in_array('descargar_xml',$accionesPermitidas,true);
$puedeDescargarPdf = in_array('descargar_pdf',$accionesPermitidas,true);
$puedeExportar = in_array('exportar',$accionesPermitidas,true);
$puedeProcesarPagos = in_array('procesar_pagos',$accionesPermitidas,true);
$puedeGenerarDiot = in_array('generar_diot',$accionesPermitidas,true);
$esSuperAdminVisor = !empty($_SESSION['es_superadmin']);
$puedeVerEmitidosVisor = in_array('I', $tiposPermitidos, true);
$puedeVerRecibidosVisor = in_array('E', $tiposPermitidos, true);
if (empty($_SESSION['nomina_gk_csrf'])) $_SESSION['nomina_gk_csrf']=bin2hex(random_bytes(32));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<script>
// includes/ está protegida por Apache: el navegador no debe solicitar su JS.
// PHP entrega únicamente este archivo de interfaz dentro del módulo autenticado.
<?php
$nominaGkJs = __DIR__ . '/../includes/nomina_gk/visor_export.js';
if (is_readable($nominaGkJs)) {
    readfile($nominaGkJs);
} else {
    echo 'window.exportarNominaGkExcel = async function () { throw new Error("Falta includes/nomina_gk/visor_export.js. Copia el archivo del paquete y recarga el visor."); };';
}
?>
</script>
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<style>
    /* Configuración Estética de Alta Densidad (GitHub Dark Style) */
    .contenedor-tabla-principal {
        width: 100%;
        background: #161b22;
        border: 1px solid #30363d;
        border-radius: 6px;
    }

    #tablaFacturas,
    #tablaFacturas_wrapper .dataTables_scrollHead table,
    #tablaFacturas_wrapper .dataTables_scrollHeadInner,
    #tablaFacturas_wrapper .dataTables_scrollHeadInner table,
    #tablaFacturas_wrapper .dataTables_scrollBody table,
    #tablaFacturas_wrapper .dataTables_scrollFoot table {
        table-layout: fixed !important;
        width: 8720px !important;
        border-collapse: separate !important;
        border-spacing: 0;
    }

    #tablaFacturas thead th,
    #tablaFacturas tfoot th,
    #tablaFacturas_wrapper .dataTables_scrollHead thead th,
    #tablaFacturas_wrapper thead th {
        background: #252827 !important;
        background-color: #252827 !important;
        color: #E6ECE9 !important;
        font-size: 0.72rem !important;
        font-weight: 700 !important;
        border: 1px solid #363c3a !important;
        padding: 7px 8px !important;
        text-align: left;
        vertical-align: middle;
        white-space: normal;
        line-height: 1.2;
        z-index: 3;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    #tablaFacturas thead th:hover,
    #tablaFacturas_wrapper .dataTables_scrollHead thead th:hover {
        background: #2d3330 !important;
        color: var(--pitaya-green, #24A77F) !important;
    }

    /* Totales: columnas monetarias más anchas para evitar que los importes se encimen. */
    #tablaFacturas tfoot th.text-derecha {
        min-width: 175px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: clip !important;
        padding-left: 10px !important;
        padding-right: 10px !important;
        font-size: 0.74rem !important;
    }

    #tablaFacturas tbody td {
        background-color: #1e201f !important;
        color: #d6ddd9;
        height: 18px !important;
        line-height: 18px !important;
        border: 1px solid #2e3331 !important;
        padding: 2px 8px !important;
        font-size: 0.76rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }

    /* FixedColumns oficial de DataTables: misma celda, mismo ancho, sin freezer artesanal. */
    #tablaFacturas_wrapper th,
    #tablaFacturas_wrapper td,
    #tablaFacturas_wrapper col {
        box-sizing: border-box !important;
    }

    #tablaFacturas_wrapper .dtfc-fixed-left {
        z-index: 4 !important;
    }

    #tablaFacturas_wrapper thead .dtfc-fixed-left,
    #tablaFacturas_wrapper tfoot .dtfc-fixed-left,
    #tablaFacturas_wrapper .dataTables_scrollHead thead .dtfc-fixed-left {
        background-color: #252827 !important;
        color: #E6ECE9 !important;
    }

    #tablaFacturas_wrapper tbody .dtfc-fixed-left {
        background-color: #1e201f !important;
        color: #d6ddd9 !important;
    }

    #tablaFacturas_wrapper .dtfc-fixed-left:last-child {
        border-right: 2px solid #24A77F !important;
        box-shadow: 2px 0 6px rgba(36, 167, 127, 0.25);
    }

    /* Fila seleccionada con un clic en el listado principal. */
    #tablaFacturas tbody tr.fila-seleccionada > td,
    #tablaFacturas_wrapper tbody tr.fila-seleccionada > td.dtfc-fixed-left {
        background-color: #1b3d33 !important;
        color: #ffffff !important;
    }

    #tablaFacturas tbody tr.fila-seleccionada > td:first-child {
        box-shadow: inset 4px 0 0 #24A77F;
    }

    /* Checkbox PPD: selección visible en individual / seleccionar todos. */
    #tablaPpdSinComplemento .ppd-check {
        width: 1rem !important;
        height: 1rem !important;
        border: 2px solid #6c9fd2 !important;
        background-color: #ffffff !important;
        opacity: 1 !important;
        cursor: pointer;
        box-shadow: none !important;
    }

    #tablaPpdSinComplemento .ppd-check:checked {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M3 8l3 3 7-7'/%3e%3c/svg%3e") !important;
        background-size: 12px 12px !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
    }

    #tablaPpdSinComplemento .ppd-check:focus {
        border-color: #0d6efd !important;
        box-shadow: 0 0 0 .15rem rgba(13,110,253,.20) !important;
    }

    .text-izquierda {
        text-align: left !important;
    }

    .text-derecha {
        text-align: right !important;
        font-weight: bold;
        font-family: 'Courier New', monospace;
        color: #aff;
    }

    .text-centro {
        text-align: center !important;
    }

    /* Mantiene el estatus SAT siempre centrado dentro de su columna. */
    #tablaFacturas td.text-centro .estatus-sat-wrap {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        flex-wrap: nowrap;
        margin: 0 auto;
    }

    #tablaFacturas td.text-centro .estatus-sat-wrap .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0 !important;
        flex: 0 0 auto;
    }

    #tablaFacturas thead th.text-derecha,
    #tablaFacturas tfoot th.text-derecha,
    #tablaFacturas_wrapper .dataTables_scrollHead thead th.text-derecha {
        text-align: right !important;
    }

    #tablaFacturas thead th.text-centro,
    #tablaFacturas tfoot th.text-centro,
    #tablaFacturas_wrapper .dataTables_scrollHead thead th.text-centro {
        text-align: center !important;
    }

    #tablaFacturas thead th.text-izquierda,
    #tablaFacturas tfoot th.text-izquierda,
    #tablaFacturas_wrapper .dataTables_scrollHead thead th.text-izquierda {
        text-align: left !important;
    }

    #tablaFacturas tbody td.texto-amplio {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Manejador para cambiar el ancho de cada columna con el mouse. */
    #tablaFacturas_wrapper .dt-column-resizer {
        position: absolute;
        top: 0;
        right: -3px;
        width: 7px;
        height: 100%;
        cursor: col-resize;
        z-index: 20;
        user-select: none;
    }

    #tablaFacturas_wrapper thead th {
        position: relative;
    }

    body.dt-redimensionando-columna {
        cursor: col-resize !important;
        user-select: none !important;
    }

    .btn-mini {
        padding: 0px 6px;
        font-size: 0.7rem;
        line-height: 1.2;
        margin: 0 1px;
    }

    #log_carga {
        background: #000;
        height: 120px;
        overflow-y: auto;
        font-family: 'Consolas', monospace;
        font-size: 0.75rem;
        border: 1px solid #238636;
    }

    #tablaFacturas.table-hover tbody tr:hover td {
        background-color: #323b44 !important;
        color: #ffffff !important;
        cursor: pointer;
    }

    .dataTables_filter {
        color: #58a6ff !important;
        margin-bottom: 5px;
        text-align: right;
    }

    .dataTables_filter label {
        font-weight: bold;
    }

    .dataTables_filter input {
        background: #0d1117 !important;
        border: 1px solid #30363d !important;
        color: #c9d1d9 !important;
        border-radius: 4px;
        padding: 2px 5px;
        margin-left: 5px;
    }

    /* Menú contextual de la fila (clic derecho). */
    #menuContextualFactura {
        position: fixed;
        display: none;
        min-width: 205px;
        background: #161b22;
        border: 1px solid #495057;
        border-radius: 7px;
        box-shadow: 0 10px 28px rgba(0,0,0,.45);
        padding: 5px;
        z-index: 1095;
    }
    #menuContextualFactura .ctx-item {
        display: flex;
        align-items: center;
        gap: 9px;
        width: 100%;
        border: 0;
        background: transparent;
        color: #e6edf3;
        text-align: left;
        padding: 8px 10px;
        border-radius: 5px;
        font-size: .82rem;
    }
    #menuContextualFactura .ctx-item:hover {
        background: #21262d;
        color: #fff;
    }
    #menuContextualFactura .ctx-sep {
        border-top: 1px solid #30363d;
        margin: 4px 2px;
    }
    #tablaFacturas tbody tr.fila-contexto td {
        background-color: #25313b !important;
        color: #fff !important;
    }

    #tablaDetallePagosFiscal th {
        white-space: nowrap;
        font-size: .73rem;
    }
    #tablaDetallePagosFiscal td {
        white-space: nowrap;
        font-size: .75rem;
        vertical-align: middle;
    }

    /* Selector Facturas / Detalle de pagos */
    .visor-selector-modo .btn { min-width: 150px; font-weight: 700; }
    .visor-selector-modo { margin-bottom: 0 !important; }
    #contenedorDetallePagos { display:none; }
    #tablaDetallePagos,
    #tablaDetallePagos_wrapper .dataTables_scrollHead table,
    #tablaDetallePagos_wrapper .dataTables_scrollHeadInner,
    #tablaDetallePagos_wrapper .dataTables_scrollHeadInner table,
    #tablaDetallePagos_wrapper .dataTables_scrollBody table,
    #tablaDetallePagos_wrapper .dataTables_scrollFoot table {
        table-layout: fixed !important;
        width: 6500px !important;
        border-collapse: separate !important;
        border-spacing: 0;
    }
    #tablaDetallePagos thead th, #tablaDetallePagos tfoot th,
    #tablaDetallePagos_wrapper .dataTables_scrollHead thead th,
    #tablaDetallePagos_wrapper thead th {
        background: #252827 !important;
        background-color: #252827 !important;
        color: #E6ECE9 !important;
        font-size: .68rem;
        font-weight: 700;
        border: 1px solid #363c3a !important;
        padding: 6px 7px;
        white-space: normal;
        line-height: 1.15;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    #tablaDetallePagos thead th:hover,
    #tablaDetallePagos_wrapper .dataTables_scrollHead thead th:hover {
        background: #2d3330 !important;
        color: var(--pitaya-green, #24A77F) !important;
    }
    #tablaDetallePagos tbody td {
        background: #1e201f !important;
        color: #d6ddd9;
        border: 1px solid #2e3331 !important;
        padding: 2px 7px !important;
        font-size: .74rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #tablaDetallePagos.table-hover tbody tr:hover td { background: #2a3330 !important; color: #fff !important; }
    #tablaDetallePagos_wrapper .dtfc-fixed-left { z-index: 4 !important; }
    #tablaDetallePagos_wrapper thead .dtfc-fixed-left, #tablaDetallePagos_wrapper tfoot .dtfc-fixed-left,
    #tablaDetallePagos_wrapper .dataTables_scrollHead thead .dtfc-fixed-left { background: #252827 !important; color: #E6ECE9 !important; }
    #tablaDetallePagos_wrapper tbody .dtfc-fixed-left { background: #1e201f !important; color: #d6ddd9 !important; }

</style>

<div class="container-fluid py-1">
    <div class="container-fluid py-1">
        <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($puedeGenerarDiot): ?><button class="btn btn-warning btn-sm fw-bold" onclick="cargarModulo('diot')">
                    <i class="bi bi-file-earmark-spreadsheet"></i> DIOT
                </button><?php endif; ?>
                <?php if ($puedeProcesarPagos): ?><button class="btn btn-info btn-sm fw-bold text-dark shadow-sm" onclick="procesarAbonosPendientes()">
                    <i class="bi bi-gear-wide-connected"></i> PROCESAR PAGOS
                </button><?php endif; ?>
                <?php if ($esSuperAdminVisor): ?><button class="btn btn-outline-warning btn-sm fw-bold" id="btnReprocesarComplementos" type="button" title="Reconstruir complementos, sustituciones 04 y saldos de la empresa activa">
                    <i class="bi bi-arrow-repeat"></i> REPROCESAR COMPLEMENTOS
                </button><?php endif; ?>
                <?php if (in_array('N',$tiposPermitidos,true)): ?><button class="btn btn-outline-info btn-sm fw-bold" id="btnReprocesarNomina" type="button" title="Releer los XML de nómina guardados y completar campos de conciliación">
                    <i class="bi bi-person-vcard"></i> PROCESAR XML NÓMINA
                </button><?php endif; ?>
                <?php if ($puedeExportar): ?><div id="area_botones" class="d-inline-flex gap-2 align-items-center">
                    <button class="btn btn-secondary btn-sm fw-bold" id="btnExportarCsv" type="button" title="Exportar a CSV todos los CFDI filtrados">
                        <i class="bi bi-filetype-csv"></i> Exportar CSV
                    </button>
                    <button class="btn btn-outline-success btn-sm fw-bold" id="btnVerificarEstatusSat" type="button" title="Verificar en el SAT el estatus de los CFDI filtrados por bloques de 500">
                        <i class="bi bi-shield-check"></i> VERIFICAR STATUS
                    </button>
                </div><?php endif; ?>
                <?php if ($puedeExportar && ($puedeDescargarXml || $puedeDescargarPdf)): ?><button class="btn btn-primary btn-sm fw-bold" id="btnExportarArchivos" type="button"
                    title="Guardar todos los XML y PDF que cumplen los filtros en una carpeta">
                    <i class="bi bi-folder-symlink"></i> EXPORTAR XML Y PDF
                </button><?php endif; ?>

                <span class="vr opacity-50 mx-1"></span>

                <div class="visor-selector-modo d-flex gap-2 align-items-center flex-wrap mb-0">
                    <button type="button" id="btnVistaFacturas" class="btn btn-primary btn-sm">
                        <i class="bi bi-receipt"></i> FACTURAS
                    </button>
                    <button type="button" id="btnVistaPagos" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-cash-stack"></i> DETALLE DE PAGOS
                    </button>
                    <span id="leyendaFechaVista" class="small text-muted ms-1">Rango de fechas: fecha de emisión de la factura</span>
                </div>
            </div>

        </div>

        <?php if (!$tiposPermitidos): ?>
        <div class="alert alert-warning mb-3">
            <i class="bi bi-shield-lock me-2"></i>No tienes tipos de documento autorizados para esta empresa.
        </div>
        <?php endif; ?>
        <div class="card bg-dark border-secondary shadow-sm mb-3">
            <div class="card-body p-2">
                <div class="row g-2 align-items-center">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark text-info border-secondary fw-bold">
                                <i class="bi bi-file-earmark-text me-1"></i> Clasificación CFDI
                            </span>
                            <select id="filtro_tipo"
                                class="form-select bg-dark text-white border-secondary fw-bold"
                                style="min-width: 225px;">
                                <option value="">TODOS</option>
                                <?php if (in_array('I', $tiposPermitidos, true)): ?><option value="INGRESO_RECIBIDO">PROVEEDORES</option><?php endif; ?>
                                <?php if (in_array('P', $tiposPermitidos, true)): ?><option value="PAGO_PROVEEDORES">PAGOS PROVEEDORES</option><?php endif; ?>
                                <?php if (in_array('P', $tiposPermitidos, true)): ?><option value="PAGO_EMITIDOS">PAGOS EMITIDOS</option><?php endif; ?>
                                <?php if (in_array('T', $tiposPermitidos, true)): ?><option value="TRASLADO_PROVEEDORES">TRASLADOS PROVEEDORES</option><?php endif; ?>
                                <?php if (in_array('T', $tiposPermitidos, true)): ?><option value="TRASLADO_EMITIDOS">TRASLADOS EMITIDOS</option><?php endif; ?>
                                <?php if (in_array('I', $tiposPermitidos, true)): ?><option value="INGRESO_EMITIDO">INGRESOS EMITIDOS</option><?php endif; ?>
                                <?php if (in_array('E', $tiposPermitidos, true)): ?><option value="EGRESO_RECIBIDO">EGRESOS RECIBIDOS</option><?php endif; ?>
                                <?php if (in_array('E', $tiposPermitidos, true)): ?><option value="EGRESO_EMITIDO">EGRESOS EMITIDOS</option><?php endif; ?>
                                <?php if (in_array('N', $tiposPermitidos, true)): ?><option value="N">EGRESOS EMITIDOS NÓMINA</option><?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark text-info border-secondary fw-bold">
                                <i class="bi bi-credit-card me-1"></i> Método de pago
                            </span>
                            <select id="filtro_metodo_pago"
                                class="form-select bg-dark text-white border-secondary fw-bold"
                                style="min-width: 115px;">
                                <option value="">TODOS</option>
                                <option value="PUE">PUE</option>
                                <option value="PPD">PPD</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-auto">
                        <button type="button"
                            id="btnPpdSinComplemento"
                            class="btn btn-outline-warning btn-sm fw-bold"
                            data-activo="0"
                            title="Abrir listado de CFDI PPD del rango seleccionado que todavía no tienen complemento de pago aplicado">
                            <i class="bi bi-exclamation-triangle"></i>
                            PPD SIN COMPLEMENTO
                            <span id="contadorPpdSinComplemento" class="badge bg-secondary ms-1 d-none">0</span>
                        </button>
                    </div>
                    <div class="col-auto">
                        <button type="button"
                            id="btnPueSinPagoEmpresa"
                            class="btn btn-outline-info btn-sm fw-bold"
                            data-activo="0"
                            title="Filtrar solo EGRESOS PUE del rango seleccionado, sin Fecha pago Empresa y cuyo emisor sea distinto al RFC de la empresa activa">
                            <i class="bi bi-calendar-x"></i>
                            PUE SIN PAGO EMPRESA
                            <span id="contadorPueSinPagoEmpresa" class="badge bg-secondary ms-1 d-none">0</span>
                        </button>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark text-info border-secondary fw-bold">
                                <i class="bi bi-calendar-range me-1"></i> Rango de fechas
                            </span>
                            <input type="date" id="f_inicio" title="Fecha inicial"
                                class="form-control bg-dark text-white border-secondary">
                            <input type="date" id="f_fin" title="Fecha final"
                                class="form-control bg-dark text-white border-secondary">
                            <button class="btn btn-primary fw-bold" id="btnAplicarFiltros" type="button" title="Aplicar filtros">
                                <i class="bi bi-funnel-fill"></i> Filtrar
                            </button>
                            <button class="btn btn-outline-secondary" id="btnLimpiarFiltros" type="button" title="Volver al mes actual">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark text-warning border-secondary fw-bold">
                                <i class="bi bi-calendar3 me-1"></i> Periodo rápido
                            </span>
                            <select id="filtro_anio_rapido"
                                class="form-select bg-dark text-white border-secondary"
                                style="min-width: 100px; width: 100px; flex: 0 0 100px; padding-right: 30px;"
                                title="Seleccione el año">
                            </select>
                            <select id="filtro_mes_rapido"
                                class="form-select bg-dark text-white border-secondary"
                                style="min-width: 150px; width: 150px; flex: 0 0 150px; padding-right: 30px;"
                                title="Seleccione el mes">
                                <option value="">MES...</option>
                                <option value="1">ENERO</option>
                                <option value="2">FEBRERO</option>
                                <option value="3">MARZO</option>
                                <option value="4">ABRIL</option>
                                <option value="5">MAYO</option>
                                <option value="6">JUNIO</option>
                                <option value="7">JULIO</option>
                                <option value="8">AGOSTO</option>
                                <option value="9">SEPTIEMBRE</option>
                                <option value="10">OCTUBRE</option>
                                <option value="11">NOVIEMBRE</option>
                                <option value="12">DICIEMBRE</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="button" id="btnCalcularTotales"
                            class="btn btn-outline-success btn-sm fw-bold"
                            title="Calcular los totales del filtro actualmente seleccionado">
                            <i class="bi bi-calculator"></i> Totales
                        </button>
                    </div>
                    <div class="w-100"></div>
                    <div class="col-12 col-md-9 col-lg-7">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark text-info border-secondary fw-bold">
                                <i class="bi bi-search me-1"></i> Buscar
                            </span>
                            <select id="tipoBusquedaFactura"
                                class="form-select bg-dark text-white border-secondary"
                                style="max-width: 185px; min-width: 165px;"
                                title="Seleccione el campo por el que desea buscar">
                                <option value="">Buscar por...</option>
                                <option value="uuid">UUID</option>
                                <option value="folio">Folio</option>
                                <option value="rfc_emisor">RFC emisor</option>
                                <option value="rfc_receptor">RFC receptor</option>
                                <option value="nombre_emisor">Nombre emisor</option>
                                <option value="nombre_emisor_descripcion">Nombre emisor descripción</option>
                                <option value="nombre_receptor">Nombre receptor</option>
                            </select>
                            <input type="text" id="customSearch"
                                class="form-control bg-dark text-white border-secondary"
                                autocomplete="off"
                                placeholder="Escriba los caracteres iniciales y pulse Filtrar...">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="contenedor_progreso" style="display:none;" class="mb-3">
            <div class="progress mb-1" style="height: 4px; background: #30363d;">
                <div id="bar_progreso" class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
            </div>
            <div id="log_carga" class="p-2 rounded shadow-inner"></div>
        </div>

        <div class="contenedor-tabla-principal" id="contenedorFacturas">
            <table id="tablaFacturas" class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tipo</th><th>Estatus SAT</th><th>UUID</th><th>Emisor</th><th>Versión</th><th>Serie</th><th>Folio</th><th>Fecha</th>
                        <th>RFC Emisor</th><th>Forma pago</th><th>Método</th><th>Pagos</th>
                        <th>Pago SAT</th><th>Fecha pago Empresa</th><th>Pago Usu.</th><th>Fecha fiscal</th>
                        <th>Uso CFDI</th><th>Mon.</th><th>TC Fact.</th><th>Total CFDI</th><th>Acciones</th><th>Subtotal</th>
                        <th>Base IVA</th><th>IVA Tras.</th><th>Base R.IVA</th><th>IVA Ret.</th><th>Base R.ISR</th><th>ISR Ret.</th>
                        <th>Base IVA 8</th><th>IVA 8</th><th>Base Norte</th><th>IVA Norte</th><th>Base Sur</th><th>IVA Sur</th>
                        <th>CP</th><th>Región CP</th><th>Región aplicada</th><th>Revisión</th>
                        <th>RFC Receptor</th><th>Receptor</th><th>Pagada</th>
                        <th>TC Pago SAT</th><th>TC CONTPAQ</th><th>Ref. XML/Cheque</th><th>Ref. Banco / Folio pago</th><th>Conciliado</th><th>Saldo</th>
                        <th>Tipo Nómina</th><th>Fecha Pago Nómina</th><th>Inicio Nómina</th><th>Fin Nómina</th><th>Días Pag.</th>
                        <th>Periodicidad</th><th>No. Empleado</th><th>Percepciones</th><th>Deducciones</th><th>Otros Pagos</th>
                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th class="text-derecha fw-bold" style="white-space:nowrap;">Sumas Totales:</th><th></th><th></th><th class="text-derecha"></th><th></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th class="text-derecha"></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th class="text-derecha"></th><th class="text-derecha"></th><th class="text-derecha"></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="contenedor-tabla-principal" id="contenedorDetallePagos">
            <table id="tablaDetallePagos" class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>T.</th><th>UUID factura</th><th>Serie</th><th>Folio</th><th>Fecha factura</th>
                    <th>RFC Emisor</th><th>Emisor</th><th>Método</th><th>Total factura</th><th>Saldo factura</th><th>Pagada</th>
                    <th>Origen</th><th>Parc.</th><th>Fecha SAT</th><th>TC Fact.</th><th>Moneda SAT</th><th>TC SAT</th>
                    <th>Fecha CONTPAQ</th><th>Moneda CONTPAQ</th><th>TC CONTPAQ</th><th>Conciliado CONTPAQ</th><th>Pendiente conciliar</th><th>Estatus conciliación</th>
                    <th>Fecha Banco</th><th>Fecha Usuario</th><th>Fecha fiscal</th>
                    <th>Pago SAT</th><th>Saldo anterior</th><th>Saldo insoluto</th><th>Base 16</th><th>IVA 16</th><th>Base 8</th><th>IVA 8</th>
                    <th>Tasa 0</th><th>Exento</th><th>No objeto</th><th>IVA Ret.</th><th>ISR Ret.</th><th>IEPS/Otros</th>
                    <th>Forma</th><th>Referencia</th><th>UUID pago</th><th>Estatus pago</th><th>UUID sustituto</th><th>Cuenta DIOT</th><th>Aplicado</th>
                </tr></thead>
                <tfoot><tr><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="modalEdicion" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-white border-secondary border-2">
                <div class="modal-header border-secondary py-2">
                    <h6 class="modal-title text-info"><i class="bi bi-pencil-square"></i> Gestión Contable de Factura</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-3">
                    <form id="formEdicion">
                        <input type="hidden" id="edit_uuid" name="uuid">
                        <div class="row g-2">
                            <div class="col-12 mb-2">
                                <label class="small text-muted mb-1">UUID Fiscal</label>
                                <input type="text" id="edit_uuid_disp"
                                    class="form-control form-control-sm bg-black text-info border-0" readonly>
                            </div>
                            <div class="col-6">
                                <label class="small text-muted">Tipo</label>
                                <input type="text" id="edit_tipo"
                                    class="form-control form-control-sm bg-secondary text-white border-0" readonly>
                            </div>
                            <div class="col-6">
                                <label class="small text-muted text-end d-block">Monto Total</label>
                                <input type="text" id="edit_total"
                                    class="form-control form-control-sm bg-secondary text-white border-0 text-end fw-bold"
                                    readonly>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="small text-muted">Emisor</label>
                                <input type="text" id="edit_emisor"
                                    class="form-control form-control-sm bg-secondary text-white border-0" readonly>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="small text-muted">Receptor</label>
                                <input type="text" id="edit_receptor"
                                    class="form-control form-control-sm bg-secondary text-white border-0" readonly>
                            </div>
                            
                            <div class="col-12 mt-3 p-3 border border-warning rounded-3 bg-opacity-10 bg-warning">
                                <label class="fw-bold text-warning d-block mb-1">FECHA EFECTIVA DE PAGO (USUARIO)</label>
                                <input type="date" id="edit_fecha_usuario" name="fecha_usuario"
                                    class="form-control bg-dark text-white border-warning shadow-sm">
                                <small class="text-muted d-block mt-1">Esta fecha se utilizará para el reporte DIOT.</small>

                                <div class="mt-3 pt-2 border-top border-secondary">
                                    <label class="fw-bold text-white mb-1" for="edit_tc_banco_real">
                                        <i class="bi bi-currency-exchange text-info"></i> Tipo de cambio real del pago
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="0" step="0.0001" id="edit_tc_banco_real" name="tc_banco_real"
                                            class="form-control bg-dark text-white border-info" placeholder="Ej. 17.4325">
                                        <span class="input-group-text bg-dark text-info border-info">MXN</span>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        Opcional. Para CFDI en moneda extranjera, si captura un valor mayor a cero, la DIOT usará este tipo de cambio para convertir a MXN. El TC original del CFDI/REP se conserva sin cambios.
                                    </small>
                                </div>

                                <div class="mt-3 pt-2 border-top border-secondary">
                                    <label class="fw-bold text-white mb-1" for="edit_tratamiento_diot">
                                        <i class="bi bi-file-earmark-check text-info"></i> Tratamiento DIOT
                                    </label>
                                    <select id="edit_tratamiento_diot" name="tratamiento_diot"
                                        class="form-select form-select-sm bg-dark text-white border-secondary">
                                        <option value="NORMAL">01 - Incluir normalmente / Sí se dieron efectos fiscales</option>
                                        <option value="NO_EFECTOS">02 - Informar / No se dieron efectos fiscales</option>
                                        <option value="NO_INCLUIR">No incluir este CFDI en la DIOT</option>
                                    </select>
                                    <small class="text-muted d-block mt-1">
                                        01 y 02 sí se informan al SAT. “No incluir” conserva el CFDI en el visor, pero no lo envía a la DIOT.
                                    </small>
                                </div>

                                <div class="mt-3 pt-2 border-top border-secondary">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="edit_iva_especial" name="iva_tratamiento_especial_diot" value="1" onchange="toggleTratamientoIvaEspecial()">
                                        <label class="form-check-label fw-bold text-warning" for="edit_iva_especial">
                                            <i class="bi bi-percent"></i> IVA con tratamiento especial DIOT
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mb-2">
                                        No modifica el CFDI. Solo reparte el IVA entre acreditable y no acreditable por proporción para la DIOT.
                                    </small>
                                    <div id="bloque_iva_especial" style="display:none">
                                        <label class="small text-white mb-1">Porcentaje de IVA acreditable</label>
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="number" min="0" max="100" step="0.01" id="edit_iva_porcentaje" name="iva_porcentaje_acreditable_diot" class="form-control bg-dark text-white border-warning" value="50" oninput="actualizarPreviewIvaEspecial()">
                                            <span class="input-group-text bg-dark text-warning border-warning">%</span>
                                            <button type="button" class="btn btn-outline-warning" onclick="$('#edit_iva_porcentaje').val(50); actualizarPreviewIvaEspecial();">50%</button>
                                            <button type="button" class="btn btn-outline-light" onclick="$('#edit_iva_porcentaje').val(0); actualizarPreviewIvaEspecial();">0%</button>
                                        </div>
                                        <div class="row g-1 mb-2 small">
                                            <div class="col-4"><div class="p-2 rounded bg-dark border border-secondary"><span class="text-muted d-block">IVA XML</span><b id="preview_iva_xml">$0.00</b></div></div>
                                            <div class="col-4"><div class="p-2 rounded bg-dark border border-success"><span class="text-muted d-block">IVA acreditable</span><b id="preview_iva_acred" class="text-success">$0.00</b></div></div>
                                            <div class="col-4"><div class="p-2 rounded bg-dark border border-warning"><span class="text-muted d-block">IVA no acreditable</span><b id="preview_iva_no_acred" class="text-warning">$0.00</b></div></div>
                                        </div>
                                        <input type="hidden" id="edit_iva_xml" value="0">
                                        <label class="small text-white mb-1">Motivo / criterio</label>
                                        <input type="text" maxlength="255" id="edit_iva_motivo" name="iva_motivo_tratamiento_diot" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Ej. Seguro compartido trabajador/empresa - criterio auditoría">
                                        <small class="text-info d-block mt-1">El resto del IVA se informa como <b>IVA no acreditable por proporción</b>; no se manda como “no objeto”.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary py-1">
                    <button class="btn btn-sm btn-outline-light px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-sm btn-primary px-4 fw-bold" onclick="guardarCambio()">
                        <i class="bi bi-save"></i> ACTUALIZAR DATOS
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="menuContextualFactura" role="menu" aria-hidden="true">
        <button type="button" class="ctx-item" data-accion="editar"><i class="bi bi-pencil-square text-info"></i> Editar factura</button>
        <?php if ($puedeExportar): ?>
        <button type="button" class="ctx-item" data-accion="verificar_sat"><i class="bi bi-shield-check text-success"></i> Verificar estatus SAT</button>
        <?php endif; ?>
        <?php if ($puedeVerXmlPdf): ?>
        <div class="ctx-sep"></div>
        <button type="button" class="ctx-item" data-accion="pdf"><i class="bi bi-file-earmark-pdf text-danger"></i> Ver PDF</button>
        <button type="button" class="ctx-item" data-accion="xml"><i class="bi bi-code-square text-warning"></i> Ver XML</button>
        <?php endif; ?>
        <div class="ctx-sep ctx-gasolina-sep" style="display:none"></div>
        <button type="button" class="ctx-item ctx-gasolina" data-accion="gasolina" style="display:none"><i class="bi bi-fuel-pump-fill text-warning"></i> Detalle de gasolina</button>
        <div class="ctx-sep"></div>
        <button type="button" class="ctx-item" data-accion="pagos"><i class="bi bi-cash-stack text-success"></i> Detalle de pagos / conciliación</button>
        <?php if ($puedeExportar && ($puedeDescargarXml || $puedeDescargarPdf)): ?>
        <div class="ctx-sep"></div>
        <button type="button" class="ctx-item" data-accion="descargar_archivos"><i class="bi bi-download text-info"></i> Descargar XML y PDF</button>
        <?php endif; ?>
    </div>

    <!-- Descarga individual con nombre amigable para compartir por correo/Teams.
         El folio editable SOLO afecta el nombre de descarga; no modifica el CFDI ni la BD. -->
    <div class="modal fade" id="modalDescargaXmlPdf" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-info border-2">
                <div class="modal-header border-secondary py-2">
                    <div>
                        <h5 class="modal-title text-info mb-0"><i class="bi bi-file-earmark-arrow-down"></i> Descargar XML y PDF</h5>
                        <small class="text-muted">Defina el nombre con el que se descargarán los dos archivos.</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="descarga_uuid">
                    <input type="hidden" id="descarga_serie_original">
                    <input type="hidden" id="descarga_folio_original">
                    <input type="hidden" id="descarga_nombre_emisor">
                    <input type="hidden" id="descarga_nombre_receptor">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small text-muted mb-1">UUID</label>
                            <input type="text" id="descarga_uuid_visible" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted mb-1">Fecha</label>
                            <input type="text" id="descarga_fecha" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted mb-1">Importe</label>
                            <input type="text" id="descarga_importe" class="form-control form-control-sm bg-black text-end text-success fw-bold border-secondary" readonly>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">RFC Emisor</label>
                            <input type="text" id="descarga_rfc_emisor" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small text-muted mb-1">Nombre Emisor</label>
                            <input type="text" id="descarga_emisor" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">RFC Receptor</label>
                            <input type="text" id="descarga_rfc_receptor" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small text-muted mb-1">Nombre Receptor</label>
                            <input type="text" id="descarga_receptor" class="form-control form-control-sm bg-black text-white border-secondary" readonly>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-bold text-warning mb-1">Folio para nombre del archivo</label>
                            <input type="text" id="descarga_folio" maxlength="80" class="form-control bg-dark text-white border-warning" placeholder="Ej. B-37179">
                            <small class="text-muted">Puede editarlo; no cambia el folio fiscal guardado.</small>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold text-info mb-1">Usar nombre de</label>
                            <div class="d-flex gap-3 border border-secondary rounded px-3 py-2">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="radio" name="descarga_nombre_origen" id="descarga_nombre_emisor_opt" value="emisor" checked>
                                    <label class="form-check-label" for="descarga_nombre_emisor_opt">Emisor</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="radio" name="descarga_nombre_origen" id="descarga_nombre_receptor_opt" value="receptor">
                                    <label class="form-check-label" for="descarga_nombre_receptor_opt">Receptor</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info bg-black border-info text-white mt-3 mb-0 py-2">
                        <small class="text-info d-block">Nombre de salida</small>
                        <strong id="descarga_nombre_preview" style="word-break:break-word">-</strong>
                        <div class="small text-muted mt-1">Se utilizará el mismo nombre para <b>.XML</b> y <b>.PDF</b>.</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary py-2">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                    <?php if ($puedeExportar && ($puedeDescargarXml || $puedeDescargarPdf)): ?>
                    <button type="button" class="btn btn-info fw-bold" id="btnDescargaXmlPdfPersonalizado" onclick="descargarXmlYPdfPersonalizado()">
                        <i class="bi bi-download"></i> DESCARGAR XML Y PDF
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDetallePagos" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white border-secondary border-2">
                <div class="modal-header border-secondary py-2">
                    <div>
                        <h6 class="modal-title text-success mb-0"><i class="bi bi-cash-stack"></i> Detalle fiscal de pagos</h6>
                        <div class="fw-bold mt-1" id="detallePagosEmisor">-</div>
                        <small class="text-muted d-block" id="detallePagosDatosFactura">RFC: - &nbsp; | &nbsp; Factura: - &nbsp; | &nbsp; Fecha: -</small>
                        <small class="text-muted d-block" id="detallePagosUuid"></small>
                        <div class="mt-1"><span id="detallePagosEstatusSat" class="badge bg-secondary">SIN ESTATUS</span> <span id="detallePagosConciliacionFin" class="badge bg-secondary">CONCILIACIÓN PENDIENTE</span></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Factura</small><b id="dpTotalFactura">$0.00</b></div></div>
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Aplicado SAT considerado</small><b id="dpTotalAplicado" class="text-success">$0.00</b><small id="dpExcluidoDiot" class="d-none text-warning d-block mt-1"></small></div></div>
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Conciliado</small><b id="dpTotalContpaq" class="text-info">$0.00</b></div></div>
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Diferencia SAT / conciliado</small><b id="dpDiferenciaContpaq" class="text-warning">$0.00</b></div></div>
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Saldo factura</small><b id="dpSaldo">$0.00</b></div></div>
                        <div class="col-md-2"><div class="border border-secondary rounded p-2 h-100"><small class="text-muted d-block">Detalles SAT / conciliados</small><b><span id="dpMovimientos">0</span> / <span id="dpMovimientosContpaq">0</span></b></div></div>
                    </div>
                    <div class="table-responsive border border-secondary rounded">
                        <table class="table table-dark table-striped table-hover table-sm mb-0" id="tablaDetallePagosFiscal">
                            <thead>
                                <tr>
                                    <th>#</th><th>Origen</th><th>Parc.</th><th>Fecha SAT</th><th>Fecha Banco</th><th>Fecha Usuario</th><th>Fecha fiscal</th>
                                    <th class="text-end">Pago</th><th class="text-end">Base 16</th><th class="text-end">IVA 16</th>
                                    <th class="text-end">Base 8</th><th class="text-end">IVA 8</th><th class="text-end">Tasa 0</th><th class="text-end">Exento</th>
                                    <th class="text-end">IVA Ret.</th><th class="text-end">ISR Ret.</th><th>Forma</th><th>Referencia</th><th>UUID pago</th><th>Estatus</th><th>UUID sustituto</th><th>Cuenta saldo</th><th>Editar</th>
                                </tr>
                            </thead>
                            <tbody id="detallePagosBody"></tbody>
                        </table>
                    </div>
                    <div id="detallePagosVacio" class="alert alert-secondary mt-3 mb-0 d-none">
                        <i class="bi bi-info-circle"></i> Esta factura todavía no tiene detalles de pago registrados.
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-3 mb-2">
                        <h6 class="mb-0 text-success"><i class="bi bi-link-45deg"></i> Conciliación financiera</h6>
                        <small class="text-muted">XML → detalle fiscal PUE/PPD → origen de pago → banco.</small>
                    </div>
                    <div class="table-responsive border border-secondary rounded">
                        <table class="table table-dark table-striped table-hover table-sm mb-0" id="tablaDetallePagosFinanciera">
                            <thead><tr>
                                <th>Detalle fiscal</th><th>Origen</th><th>Documento</th><th>Pago / folio</th><th>Fecha pago</th>
                                <th class="text-end">Importe documento</th><th class="text-end">Aplicado</th><th>Banco</th>
                                <th>Fecha conc.</th><th>Fecha valor</th><th>Regla</th><th>Estatus</th>
                            </tr></thead>
                            <tbody id="detallePagosFinBody"></tbody>
                        </table>
                    </div>
                    <div id="detallePagosFinVacio" class="alert alert-warning mt-2 mb-0 d-none">
                        <i class="bi bi-exclamation-triangle"></i> El XML todavía no tiene una relación financiera conciliada.
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-3 mb-2">
                        <h6 class="mb-0 text-info"><i class="bi bi-bank"></i> Aplicaciones de pago conciliadas</h6>
                        <small class="text-muted">Cheque, egreso o transferencia vigente que respalda cada abono SAT.</small>
                    </div>
                    <div class="table-responsive border border-secondary rounded">
                        <table class="table table-dark table-striped table-hover table-sm mb-0" id="tablaDetallePagosContpaq">
                            <thead>
                                <tr>
                                    <th>Detalle SAT</th><th>Tipo</th><th>Folio</th><th>Fecha pago</th><th>Beneficiario</th>
                                    <th>Moneda</th><th class="text-end">TC</th><th class="text-end">Importe movimiento</th>
                                    <th class="text-end">Aplicado</th><th class="text-end">Dif. importe</th><th class="text-center">Dif. días</th>
                                    <th>Póliza</th><th>Referencia</th><th>UUID factura</th><th>UUID REP</th><th>Estatus</th>
                                </tr>
                            </thead>
                            <tbody id="detallePagosContpaqBody"></tbody>
                        </table>
                    </div>
                    <div id="detallePagosContpaqVacio" class="alert alert-warning mt-2 mb-0 d-none">
                        <i class="bi bi-exclamation-triangle"></i> Los detalles SAT existen, pero todavía no tienen un cheque, egreso o transferencia conciliado.
                    </div>
                </div>
                <div class="modal-footer border-secondary py-1">
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalGasolina" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white border-secondary border-2">
                <div class="modal-header border-secondary py-2">
                    <div>
                        <h6 class="modal-title text-warning mb-0"><i class="bi bi-fuel-pump-fill"></i> Estado de Cuenta de Combustible</h6>
                        <small class="text-muted" id="gasUuid"></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <div class="card bg-black border-secondary h-100">
                                <div class="card-body p-3">
                                    <div class="small text-muted">Proveedor del CFDI</div>
                                    <div id="gasProveedor" class="fw-bold text-info mb-2">-</div>
                                    <div class="row g-2 small">
                                        <div class="col-5 text-muted">Fecha CFDI</div><div class="col-7" id="gasFechaCfdi">-</div>
                                        <div class="col-5 text-muted">Tipo operación</div><div class="col-7" id="gasTipoOperacion">-</div>
                                        <div class="col-5 text-muted">Cuenta</div><div class="col-7 text-break" id="gasCuenta">-</div>
                                        <div class="col-5 text-muted">Subtotal combustible</div><div class="col-7 text-end fw-bold" id="gasSubtotal">$0.00</div>
                                        <div class="col-5 text-muted">IVA combustible</div><div class="col-7 text-end" id="gasIva">$0.00</div>
                                        <div class="col-5 text-muted">IEPS combustible</div><div class="col-7 text-end" id="gasIeps">$0.00</div>
                                        <div class="col-5 text-warning fw-bold">Total combustible</div><div class="col-7 text-end fw-bold text-warning" id="gasTotal">$0.00</div>
                                    </div>
                                    <hr class="border-secondary">
                                    <label class="small fw-bold text-success" for="gasFechaPago">FECHA DE PAGO</label>
                                    <input type="date" id="gasFechaPago" class="form-control form-control-sm bg-dark text-white border-success mt-1">
                                    <div class="row g-2 mt-1 small">
                                        <div class="col-5 text-muted">Pagado</div><div class="col-7 text-end text-success fw-bold" id="gasPagado">$0.00</div>
                                        <div class="col-5 text-muted">Pendiente</div><div class="col-7 text-end text-warning fw-bold" id="gasPendiente">$0.00</div>
                                        <div class="col-5 text-muted">Estatus</div><div class="col-7 text-end" id="gasEstatus">-</div>
                                    </div>
                                    <label class="small text-muted mt-2" for="gasObservaciones">Observaciones</label>
                                    <textarea id="gasObservaciones" rows="2" class="form-control form-control-sm bg-dark text-white border-secondary"></textarea>
                                    <button type="button" class="btn btn-success btn-sm w-100 mt-2 fw-bold" onclick="guardarPagoGasolina()">
                                        <i class="bi bi-calendar2-check"></i> GUARDAR PAGO
                                    </button>
                                    <small class="text-muted d-block mt-2">El pago de combustible se controla aparte porque el total del complemento puede ser distinto al total del CFDI de comisión.</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <ul class="nav nav-tabs" id="gasTabs" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#gasResumen" type="button">Resumen</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasConsumos" type="button">Consumos</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gasImpuestos" type="button">Impuestos</button></li>
                            </ul>
                            <div class="tab-content border border-top-0 border-secondary rounded-bottom p-2" style="min-height:420px">
                                <div class="tab-pane fade show active" id="gasResumen">
                                    <div class="table-responsive"><table class="table table-dark table-sm table-hover mb-0">
                                        <thead><tr><th>Fecha</th><th>RFC</th><th>Total</th><th>Nombre</th><th>Operación</th></tr></thead>
                                        <tbody id="gasResumenBody"></tbody>
                                        <tfoot><tr><th colspan="2">Total</th><th class="text-end" id="gasResumenTotal">$0.00</th><th colspan="2"></th></tr></tfoot>
                                    </table></div>
                                </div>
                                <div class="tab-pane fade" id="gasConsumos">
                                    <div class="table-responsive"><table class="table table-dark table-sm table-hover mb-0">
                                        <thead><tr><th>Fecha</th><th>RFC</th><th>Operación</th><th>Combustible</th><th class="text-end">Cantidad</th><th class="text-end">Precio</th><th class="text-end">Importe</th></tr></thead>
                                        <tbody id="gasConsumosBody"></tbody>
                                        <tfoot><tr><th colspan="6">Total</th><th class="text-end" id="gasConsumosTotal">$0.00</th></tr></tfoot>
                                    </table></div>
                                </div>
                                <div class="tab-pane fade" id="gasImpuestos">
                                    <div class="table-responsive"><table class="table table-dark table-sm table-hover mb-0">
                                        <thead><tr><th>Fecha</th><th>RFC</th><th class="text-end">Importe</th><th>Impuesto</th><th class="text-end">Tasa</th><th class="text-end">T. Impuesto</th></tr></thead>
                                        <tbody id="gasImpuestosBody"></tbody>
                                        <tfoot><tr><th colspan="5">Total impuestos</th><th class="text-end" id="gasImpuestosTotal">$0.00</th></tr></tfoot>
                                    </table></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary py-1">
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Modal de búsqueda por nombre de emisor + descripciones de conceptos del XML -->
    <div class="modal fade" id="modalEmisorDescripcion" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-secondary py-2">
                    <div>
                        <h5 class="modal-title mb-0"><i class="bi bi-card-text text-info me-2"></i>Nombre emisor + descripción de conceptos</h5>
                        <small class="text-secondary" id="emdResumen">Preparando facturas...</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-2 d-flex flex-column" style="min-height:0;">
                    <div class="border border-secondary rounded p-2 mb-2 bg-black bg-opacity-25">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="flex-grow-1" style="min-width:280px;">
                                <div class="progress" style="height:8px;">
                                    <div id="emdBarra" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                                </div>
                                <div class="small text-secondary mt-1" id="emdEstado">Esperando...</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-success fw-bold" id="btnEmdExportarExcel">
                                <i class="bi bi-file-earmark-excel"></i> EXPORTAR EXCEL
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning fw-bold" id="btnEmdCancelar">
                                <i class="bi bi-stop-circle"></i> CANCELAR PROCESO
                            </button>
                            <div class="input-group input-group-sm" style="width:min(520px,100%);">
                                <span class="input-group-text bg-dark text-info border-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control bg-dark text-white border-secondary" id="emdBuscar" placeholder="Buscar en cualquier columna, especialmente Desc 1, Desc 2 o Desc 3..." autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary" id="btnEmdLimpiar"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="flex-grow-1" style="min-height:0; overflow:hidden;">
                        <table id="tablaEmisorDescripcion" class="table table-dark table-hover table-sm mb-0 w-100">
                            <thead><tr></tr></thead><tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-secondary py-1">
                    <span class="me-auto small text-secondary">Se leen solamente los primeros 3 conceptos de cada XML. Si hay menos, las columnas restantes quedan en blanco.</span>
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal PPD sin complemento: solo revisión/selección. El envío real se implementará después. -->
    <div class="modal fade" id="modalPpdSinComplemento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:96vw;">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary py-2">
                    <div>
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-envelope-exclamation text-warning me-2"></i>PPD SIN COMPLEMENTO
                        </h5>
                        <small class="text-secondary" id="ppdModalPeriodo">Facturas PPD sin complemento del rango seleccionado.</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="border border-secondary rounded p-2 mb-2 bg-black bg-opacity-25">
                        <div class="row g-2 align-items-end">
                            <div class="col-lg-5 col-md-12">
                                <label class="form-label small mb-1 text-info fw-bold">
                                    <i class="bi bi-person-copy me-1"></i>Copia para encargado de complementos
                                </label>
                                <input type="text" class="form-control form-control-sm bg-dark text-white border-secondary" id="ppdCorreoEncargado" placeholder="encargado@empresa.com; otro@empresa.com">
                                <div class="form-text text-secondary">Se guarda por empresa. Puede capturar uno o varios correos separados por punto y coma.</div>
                            </div>
                            <div class="col-lg-5 col-md-8">
                                <label class="form-label small mb-1 text-info fw-bold">Tipo de copia</label>
                                <div class="d-flex flex-wrap gap-3 border border-secondary rounded px-2 py-1">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio" name="ppdModoCopia" id="ppdCopiaIndividual" value="individual">
                                        <label class="form-check-label small" for="ppdCopiaIndividual">Copia individual en cada correo</label>
                                    </div>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio" name="ppdModoCopia" id="ppdCopiaResumen" value="resumen">
                                        <label class="form-check-label small" for="ppdCopiaResumen">Un resumen al finalizar con el detalle</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-4 d-grid">
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold" id="btnPpdGuardarCopia">
                                    <i class="bi bi-floppy"></i> GUARDAR COPIA
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <button type="button" class="btn btn-sm btn-outline-info fw-bold" id="btnPpdSeleccionarTodos">
                            <i class="bi bi-check2-square"></i> SELECCIONAR TODOS
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="btnPpdDeseleccionarTodos">
                            <i class="bi bi-square"></i> DESELECCIONAR TODOS
                        </button>
                        <div class="input-group input-group-sm" style="width:min(390px,100%);">
                            <span class="input-group-text bg-dark text-info border-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control bg-dark text-white border-secondary" id="ppdBuscarEmisor" placeholder="Buscar por RFC o emisor..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btnPpdLimpiarBusqueda" title="Limpiar búsqueda"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success fw-bold" id="btnPpdExportarExcel">
                            <i class="bi bi-file-earmark-excel"></i> EXPORTAR EXCEL
                        </button>
                        <span class="badge bg-primary" id="ppdTotalRegistros">0 facturas</span>
                        <span class="badge bg-info text-dark d-none" id="ppdTotalVisibles">0 visibles</span>
                        <span class="badge bg-success" id="ppdTotalSeleccionados">0 seleccionadas</span>
                        <span class="badge bg-warning text-dark d-none" id="ppdSinCorreo">0 sin correo</span>
                        <span class="small text-info ms-auto"><i class="bi bi-mouse"></i> Doble clic en una factura para actualizar únicamente el correo del emisor.</span>
                    </div>

                    <div class="table-responsive border border-secondary rounded" style="max-height:58vh; overflow:auto;">
                        <table class="table table-dark table-striped table-hover table-sm align-middle mb-0" id="tablaPpdSinComplemento" style="font-size:.78rem; white-space:nowrap;">
                            <thead class="sticky-top" style="z-index:2;">
                                <tr>
                                    <th class="text-center" style="width:44px;">Sel.</th>
                                    <th>UUID</th>
                                    <th>Factura</th>
                                    <th>Fecha factura</th>
                                    <th>Cheque</th>
                                    <th>Fecha cheque</th>
                                    <th>RFC</th>
                                    <th>Emisor</th>
                                    <th class="text-end">Importe</th>
                                    <th>Correo emisor</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyPpdSinComplemento">
                                <tr><td colspan="10" class="text-center text-secondary py-4">Sin consultar.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-secondary mt-2">
                        Esta pantalla únicamente prepara la selección. En esta etapa no se enviará ningún correo.
                    </div>
                </div>
                <div class="modal-footer border-secondary py-2">
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-sm btn-success fw-bold" id="btnPpdEnviarCorreo">
                        <i class="bi bi-envelope-arrow-up"></i> ENVIAR CORREO
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script>
        // Al volver desde otro módulo (por ejemplo DIOT), eliminamos cualquier instancia vieja
        // que DataTables haya conservado para que los filtros vuelvan a consultar la base.
        if ($.fn.DataTable.isDataTable('#tablaFacturas')) {
            $('#tablaFacturas').DataTable().clear().destroy();
        }

        // Estado de navegación del VISOR. Se conserva únicamente durante esta pestaña
        // (sessionStorage), para que entrar a Solicitudes SAT u otro módulo no borre filtros.
        var VISOR_ESTADO_KEY = 'sgksat_visor_estado_filtros_v4';
        window.ultimoEstadoAplicadoVisor = null;
        window.estadoVisorBaseDescripcion = null;

        function capturarEstadoFiltrosVisor() {
            return {
                inicio: $('#f_inicio').val() || '',
                fin: $('#f_fin').val() || '',
                tipo: normalizarClasificacionCfdi($('#filtro_tipo').val() || ''),
                metodo: $('#filtro_metodo_pago').val() || '',
                tipoBusqueda: $('#tipoBusquedaFactura').val() || '',
                busqueda: $('#customSearch').val() || '',
                ppdSinComplemento: $('#btnPpdSinComplemento').attr('data-activo') === '1',
                pueSinPagoEmpresa: $('#btnPueSinPagoEmpresa').attr('data-activo') === '1',
                anioRapido: $('#filtro_anio_rapido').val() || '',
                mesRapido: $('#filtro_mes_rapido').val() || ''
            };
        }

        function aplicarEstadoFiltrosVisor(f) {
            if (!f || typeof f !== 'object') return false;
            if (f.inicio) $('#f_inicio').val(f.inicio);
            if (f.fin) $('#f_fin').val(f.fin);
            $('#filtro_tipo').val(normalizarClasificacionCfdi(f.tipo || ''));
            $('#filtro_metodo_pago').val(f.metodo || '');
            $('#tipoBusquedaFactura').val(f.tipoBusqueda || '');
            $('#customSearch').val(f.busqueda || '');

            const ppd = !!f.ppdSinComplemento;
            $('#btnPpdSinComplemento').attr('data-activo', ppd ? '1' : '0')
                .toggleClass('btn-warning text-dark', ppd).toggleClass('btn-outline-warning', !ppd);
            const pue = !!f.pueSinPagoEmpresa;
            $('#btnPueSinPagoEmpresa').attr('data-activo', pue ? '1' : '0')
                .toggleClass('btn-info text-dark', pue).toggleClass('btn-outline-info', !pue);

            if (f.anioRapido) $('#filtro_anio_rapido').val(String(f.anioRapido));
            if (f.mesRapido) $('#filtro_mes_rapido').val(String(f.mesRapido));
            return true;
        }

        function fechaIsoValidaVisor(v) {
            return /^\d{4}-\d{2}-\d{2}$/.test(String(v || ''));
        }

        function leerEstadoVisorGuardado() {
            try {
                const raw = sessionStorage.getItem(VISOR_ESTADO_KEY);
                if (!raw) return null;
                const f = JSON.parse(raw);
                // El buscador de descripciones es un modal auxiliar, no un filtro permanente.
                if (f && f.tipoBusqueda === 'nombre_emisor_descripcion') return null;

                // IMPORTANTE: nunca restaurar un estado incompleto. Un rango vacío hace que
                // listar_facturas.php termine consultando demasiado histórico y DataTables
                // lo reporta únicamente como "Ajax error". Si faltan fechas, arrancamos limpio.
                if (!f || !fechaIsoValidaVisor(f.inicio) || !fechaIsoValidaVisor(f.fin)) {
                    sessionStorage.removeItem(VISOR_ESTADO_KEY);
                    return null;
                }
                return f;
            } catch (e) {
                try { sessionStorage.removeItem(VISOR_ESTADO_KEY); } catch (_) {}
                return null;
            }
        }

        window.guardarEstadoVisorNavegacion = function() {
            let f = capturarEstadoFiltrosVisor();
            if (f.tipoBusqueda === 'nombre_emisor_descripcion' && window.estadoVisorBaseDescripcion) {
                f = Object.assign({}, window.estadoVisorBaseDescripcion);
            }

            // Solo persistir estados realmente aplicables. Si por cualquier motivo el módulo
            // se está desmontando mientras los inputs todavía están vacíos, no guardar basura.
            if (!fechaIsoValidaVisor(f.inicio) || !fechaIsoValidaVisor(f.fin)) return;
            sessionStorage.setItem(VISOR_ESTADO_KEY, JSON.stringify(f));
        };

        function marcarEstadoVisorAplicado() {
            const f = capturarEstadoFiltrosVisor();
            if (f.tipoBusqueda !== 'nombre_emisor_descripcion') {
                window.ultimoEstadoAplicadoVisor = Object.assign({}, f);
                try { sessionStorage.setItem(VISOR_ESTADO_KEY, JSON.stringify(f)); } catch (e) {}
            }
        }

        // Rango inicial del visor: primer día del mes actual hasta hoy.
        // Se establece ANTES de crear DataTables para que la primera consulta
        // ya llegue filtrada al servidor y no cargue todo el histórico.
        function establecerRangoMesActualVisor() {
            const hoy = new Date();
            const yyyy = hoy.getFullYear();
            const mm = String(hoy.getMonth() + 1).padStart(2, '0');
            const dd = String(hoy.getDate()).padStart(2, '0');

            $('#f_inicio').val(`${yyyy}-${mm}-01`);
            $('#f_fin').val(`${yyyy}-${mm}-${dd}`);
        }

        var estadoVisorGuardado = leerEstadoVisorGuardado();
        if (!estadoVisorGuardado) establecerRangoMesActualVisor();

        function fechaIsoLocal(fecha) {
            const y = fecha.getFullYear();
            const m = String(fecha.getMonth() + 1).padStart(2, '0');
            const d = String(fecha.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function inicializarPeriodosRapidos() {
            const hoy = new Date();
            const anioActual = hoy.getFullYear();
            const $anio = $('#filtro_anio_rapido');

            $anio.empty();

            // Año actual primero y después años anteriores.
            // Se dejan 10 años disponibles; si después ocupan más, se puede ampliar.
            for (let anio = anioActual; anio >= anioActual - 9; anio--) {
                $anio.append(new Option(String(anio), String(anio), false, anio === anioActual));
            }

            $('#filtro_mes_rapido').val(String(hoy.getMonth() + 1));
        }

        function aplicarMesRapido() {
            const anio = parseInt($('#filtro_anio_rapido').val(), 10);
            const mes = parseInt($('#filtro_mes_rapido').val(), 10);

            if (!anio || !mes) {
                Swal.fire({
                    icon: 'info',
                    title: 'Seleccione año y mes',
                    text: 'Primero seleccione el año y el mes que desea consultar.'
                });
                return;
            }

            const hoy = new Date();
            const inicio = new Date(anio, mes - 1, 1);
            const ultimoDia = new Date(anio, mes, 0);

            // Si el periodo seleccionado es el mes actual, termina en hoy.
            // Para meses pasados, termina en el último día de ese mes.
            let fin = ultimoDia;
            if (anio === hoy.getFullYear() && mes === (hoy.getMonth() + 1)) {
                fin = hoy;
            }

            $('#f_inicio').val(fechaIsoLocal(inicio));
            $('#f_fin').val(fechaIsoLocal(fin));

            // IMPORTANTE: cambiar mes SOLO cambia el rango de fechas.
            // El filtro activo conserva la prioridad. Si hay una búsqueda específica
            // (por ejemplo Nombre emisor), NO regresamos a empresa+fecha.
            activarModoFiltroSegunEstado();
            marcarEstadoVisorAplicado();
            table.search('');
            table.page('first').ajax.reload();
        }

        inicializarPeriodosRapidos();
        if (estadoVisorGuardado) aplicarEstadoFiltrosVisor(estadoVisorGuardado);
        window.ultimoEstadoAplicadoVisor = capturarEstadoFiltrosVisor();

        // Al cambiar el año o el mes se actualiza el rango y se filtra de inmediato.
        // Ambos selectores trabajan siempre como un solo periodo Año-Mes.
        $('#filtro_anio_rapido, #filtro_mes_rapido')
            .off('change.periodo')
            .on('change.periodo', function () {
                const anio = $('#filtro_anio_rapido').val();
                const mes = $('#filtro_mes_rapido').val();
                if (anio && mes) aplicarMesRapido();
            });


        function ppdEscapeHtml(valor) {
            return $('<div>').text(valor == null ? '' : String(valor)).html();
        }

        function ppdFormatoImporte(valor, moneda) {
            const n = Number(valor || 0);
            try {
                return new Intl.NumberFormat('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + (moneda ? ' ' + moneda : '');
            } catch (e) {
                return n.toFixed(2) + (moneda ? ' ' + moneda : '');
            }
        }

        function ppdNormalizarBusqueda(valor) {
            return String(valor == null ? '' : valor)
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .toLowerCase().trim();
        }

        function ppdAplicarBusqueda() {
            const buscar = ppdNormalizarBusqueda($('#ppdBuscarEmisor').val());
            let visibles = 0;
            $('#tbodyPpdSinComplemento tr.ppd-row').each(function() {
                const $tr = $(this);
                const texto = ppdNormalizarBusqueda(($tr.attr('data-rfc') || '') + ' ' + ($tr.attr('data-emisor') || ''));
                const mostrar = buscar === '' || texto.indexOf(buscar) !== -1;
                $tr.toggle(mostrar);
                if (mostrar) visibles++;
            });
            const total = $('#tbodyPpdSinComplemento tr.ppd-row').length;
            if (buscar !== '' && total > 0) {
                $('#ppdTotalVisibles').removeClass('d-none').text(visibles + (visibles === 1 ? ' visible' : ' visibles'));
            } else {
                $('#ppdTotalVisibles').addClass('d-none').text('0 visibles');
            }
            ppdActualizarContadores();
        }

        function ppdActualizarContadores() {
            const total = $('#tbodyPpdSinComplemento .ppd-check').length;
            const seleccionados = $('#tbodyPpdSinComplemento tr.ppd-row:visible .ppd-check:checked').length;
            const sinCorreo = $('#tbodyPpdSinComplemento tr.ppd-row:visible .ppd-check:checked[data-correo-valido="0"]').length;
            $('#ppdTotalRegistros').text(total + (total === 1 ? ' factura' : ' facturas'));
            $('#ppdTotalSeleccionados').text(seleccionados + (seleccionados === 1 ? ' seleccionada' : ' seleccionadas'));
            if (sinCorreo > 0) {
                $('#ppdSinCorreo').removeClass('d-none').text(sinCorreo + ' sin correo');
            } else {
                $('#ppdSinCorreo').addClass('d-none').text('0 sin correo');
            }
        }

        async function abrirModalPpdSinComplemento() {
            const inicio = $('#f_inicio').val() || '';
            const fin = $('#f_fin').val() || '';
            if (!inicio || !fin) {
                Swal.fire('Rango requerido', 'Seleccione primero las fechas del visor.', 'warning');
                return;
            }

            $('#tbodyPpdSinComplemento').html('<tr><td colspan="10" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Consultando PPD sin complemento...</td></tr>');
            $('#ppdModalPeriodo').text('Rango: ' + inicio + ' al ' + fin);
            $('#ppdTotalRegistros').text('0 facturas');
            $('#ppdTotalSeleccionados').text('0 seleccionadas');
            $('#ppdSinCorreo').addClass('d-none');
            $('#ppdTotalVisibles').addClass('d-none').text('0 visibles');
            $('#ppdBuscarEmisor').val('');

            const el = document.getElementById('modalPpdSinComplemento');
            bootstrap.Modal.getOrCreateInstance(el).show();
            ppdCargarConfiguracionCopia();

            try {
                const fd = new FormData();
                fd.set('inicio', inicio);
                fd.set('fin', fin);
                const res = await fetch('ajax/listar_ppd_sin_complemento.php', {method:'POST', body:fd, cache:'no-store'});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No fue posible consultar las facturas PPD.');

                const rows = Array.isArray(json.data) ? json.data : [];
                $('#contadorPpdSinComplemento').toggleClass('d-none', rows.length === 0).text(rows.length);
                if (!rows.length) {
                    $('#tbodyPpdSinComplemento').html('<tr><td colspan="10" class="text-center text-success py-4"><i class="bi bi-check-circle me-2"></i>No hay CFDI PPD pagados sin complemento en este rango.</td></tr>');
                    ppdActualizarContadores();
                    return;
                }

                let html = '';
                rows.forEach(function(r, idx) {
                    const correoValido = Number(r.correo_valido || 0) === 1;
                    const correo = r.correo_emisor || '';
                    const correoHtml = correoValido
                        ? '<span class="text-info ppd-correo">' + ppdEscapeHtml(correo) + '</span>'
                        : '<span class="badge bg-warning text-dark ppd-correo">SIN CORREO</span>';
                    const cheque = (r.cheque || '').trim();
                    const fechaCheque = (r.fecha_cheque || '').trim();
                    html += '<tr class="ppd-row" data-index="' + idx + '" data-id-emisor="' + Number(r.id_emisor || 0) + '" data-rfc="' + ppdEscapeHtml(r.rfc_emisor || '') + '" data-emisor="' + ppdEscapeHtml(r.emisor || '') + '" title="Doble clic para editar correo del emisor">' +
                        '<td class="text-center"><input class="form-check-input ppd-check" type="checkbox" value="' + ppdEscapeHtml(r.uuid) + '" data-correo-valido="' + (correoValido ? '1' : '0') + '" data-index="' + idx + '" checked></td>' +
                        '<td><span class="font-monospace" title="' + ppdEscapeHtml(r.uuid) + '">' + ppdEscapeHtml(r.uuid) + '</span></td>' +
                        '<td>' + ppdEscapeHtml(r.factura || 'S/F') + '</td>' +
                        '<td>' + ppdEscapeHtml(r.fecha_factura || '') + '</td>' +
                        '<td>' + ppdEscapeHtml(cheque) + '</td>' +
                        '<td>' + ppdEscapeHtml(fechaCheque) + '</td>' +
                        '<td>' + ppdEscapeHtml(r.rfc_emisor || '') + '</td>' +
                        '<td style="max-width:260px; overflow:hidden; text-overflow:ellipsis;" title="' + ppdEscapeHtml(r.emisor || '') + '">' + ppdEscapeHtml(r.emisor || '') + '</td>' +
                        '<td class="text-end fw-bold">' + ppdFormatoImporte(r.importe, r.moneda) + '</td>' +
                        '<td>' + correoHtml + '</td>' +
                    '</tr>';
                });
                $('#tbodyPpdSinComplemento').html(html);
                $('#tbodyPpdSinComplemento').data('ppdRows', rows);
                ppdAplicarBusqueda();
            } catch (e) {
                $('#tbodyPpdSinComplemento').html('<tr><td colspan="10" class="text-center text-danger py-4">' + ppdEscapeHtml(e.message || 'Error de consulta') + '</td></tr>');
                ppdActualizarContadores();
            }
        }

        $('#btnPpdSeleccionarTodos').off('click.ppdModal').on('click.ppdModal', function() {
            $('#tbodyPpdSinComplemento tr.ppd-row:visible .ppd-check').prop('checked', true);
            ppdActualizarContadores();
        });
        $('#btnPpdDeseleccionarTodos').off('click.ppdModal').on('click.ppdModal', function() {
            $('#tbodyPpdSinComplemento tr.ppd-row:visible .ppd-check').prop('checked', false);
            ppdActualizarContadores();
        });
        $('#tbodyPpdSinComplemento').off('change.ppdModal', '.ppd-check').on('change.ppdModal', '.ppd-check', function() {
            ppdActualizarContadores();
        });
        $('#ppdBuscarEmisor').off('input.ppdModal').on('input.ppdModal', function() {
            ppdAplicarBusqueda();
        });
        $('#btnPpdLimpiarBusqueda').off('click.ppdModal').on('click.ppdModal', function() {
            $('#ppdBuscarEmisor').val('').trigger('input').focus();
        });
        $('#btnPpdExportarExcel').off('click.ppdModal').on('click.ppdModal', function() {
            const inicio = $('#f_inicio').val() || '';
            const fin = $('#f_fin').val() || '';
            const buscar = String($('#ppdBuscarEmisor').val() || '').trim();
            if (!inicio || !fin) {
                Swal.fire('Rango requerido', 'Seleccione primero las fechas del visor.', 'warning');
                return;
            }
            const qs = new URLSearchParams({inicio: inicio, fin: fin});
            if (buscar !== '') qs.set('buscar', buscar);
            window.location.href = 'ajax/exportar_ppd_sin_complemento_excel.php?' + qs.toString();
        });


        // Doble clic: actualiza ÚNICAMENTE el correo principal del emisor.
        // El correo con copia pertenece al encargado de complementos y se configura arriba, por empresa.
        $('#tbodyPpdSinComplemento').off('dblclick.ppdCorreo', '.ppd-row').on('dblclick.ppdCorreo', '.ppd-row', async function(e) {
            if ($(e.target).is('input,button,a')) return;

            const idx = Number($(this).attr('data-index'));
            const rows = $('#tbodyPpdSinComplemento').data('ppdRows') || [];
            const r = rows[idx];
            if (!r || !Number(r.id_emisor || 0)) {
                Swal.fire('Emisor no disponible', 'No fue posible identificar el emisor de esta factura.', 'warning');
                return;
            }

            const mismoEmisor = rows.filter(x => Number(x.id_emisor || 0) === Number(r.id_emisor || 0)).length;
            const correoActual = r.correo_emisor || '';

            // IMPORTANTE: SweetAlert se monta DENTRO del modal PPD.
            // Bootstrap mantiene un focus-trap sobre el modal abierto; si SweetAlert se agrega al <body>,
            // Bootstrap le roba el foco al input y parece que el correo no se puede editar.
            const ppdModalTarget = document.getElementById('modalPpdSinComplemento');
            const form = await Swal.fire({
                target: ppdModalTarget,
                title: 'Actualizar correo del emisor',
                html:
                    '<div class="text-start small mb-2"><b>' + ppdEscapeHtml(r.emisor || '') + '</b><br>' + ppdEscapeHtml(r.rfc_emisor || '') + '</div>' +
                    '<label for="ppdEditarCorreoEmisor" class="d-block text-start small text-secondary mb-1">Correo del emisor</label>' +
                    '<input id="ppdEditarCorreoEmisor" type="email" class="swal2-input" ' +
                    'placeholder="proveedor@empresa.com" autocomplete="off" autocapitalize="off" spellcheck="false" ' +
                    'value="' + ppdEscapeHtml(correoActual) + '" ' +
                    'style="display:block;width:100%;max-width:none;margin:0;box-sizing:border-box;">',
                width: 470,
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#0d6efd',
                background: '#161b22',
                color: '#fff',
                focusConfirm: false,
                heightAuto: false,
                scrollbarPadding: false,
                didOpen: (popup) => {
                    // Editor pequeño, sin barras de desplazamiento.
                    popup.style.overflow = 'hidden';
                    const html = popup.querySelector('.swal2-html-container');
                    if (html) {
                        html.style.overflow = 'visible';
                        html.style.padding = '0 1.5rem 0.25rem';
                        html.style.margin = '0';
                    }
                    const input = popup.querySelector('#ppdEditarCorreoEmisor');
                    if (input) {
                        input.removeAttribute('readonly');
                        input.removeAttribute('disabled');
                        setTimeout(() => {
                            input.focus();
                            input.select();
                        }, 0);
                    }
                },
                preConfirm: () => {
                    const input = document.getElementById('ppdEditarCorreoEmisor');
                    const correo = input ? String(input.value || '').trim() : '';
                    if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
                        Swal.showValidationMessage('El correo no tiene un formato válido.');
                        return false;
                    }
                    return correo;
                }
            });
            if (!form.isConfirmed) return;

            if (mismoEmisor > 1) {
                const confirma = await Swal.fire({
                    target: document.getElementById('modalPpdSinComplemento'),
                    icon: 'question',
                    title: 'Actualizar correo del emisor',
                    html: 'Hay <b>' + mismoEmisor + '</b> facturas mostradas del mismo emisor.<br>Al confirmar, el correo se actualizará en el catálogo del emisor y se reflejará en <b>todas</b> sus facturas.',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, actualizar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#198754',
                    background: '#161b22',
                    color: '#fff'
                });
                if (!confirma.isConfirmed) return;
            }

            try {
                const fd = new FormData();
                fd.set('id_emisor', r.id_emisor);
                fd.set('correo', (form.value || '').trim());
                const res = await fetch('ajax/actualizar_correo_emisor_ppd.php', {method:'POST', body:fd, cache:'no-store'});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No fue posible actualizar el correo.');

                rows.forEach(function(x) {
                    if (Number(x.id_emisor || 0) === Number(r.id_emisor || 0)) {
                        x.correo_emisor = json.correo || '';
                        x.correo_valido = json.correo ? 1 : 0;
                    }
                });
                $('#tbodyPpdSinComplemento').data('ppdRows', rows);

                $('#tbodyPpdSinComplemento .ppd-row[data-id-emisor="' + Number(r.id_emisor || 0) + '"]').each(function() {
                    const correo = json.correo || '';
                    $(this).find('.ppd-correo').replaceWith(
                        correo
                            ? '<span class="text-info ppd-correo">' + ppdEscapeHtml(correo) + '</span>'
                            : '<span class="badge bg-warning text-dark ppd-correo">SIN CORREO</span>'
                    );
                    $(this).find('.ppd-check').attr('data-correo-valido', correo ? '1' : '0');
                });
                ppdActualizarContadores();

                Swal.fire({icon:'success', title:'Correo actualizado', text:'El correo quedó guardado en el catálogo de emisores.', timer:1400, showConfirmButton:false, background:'#161b22', color:'#fff'});
            } catch (err) {
                Swal.fire('No se pudo actualizar', err.message || 'Error de actualización.', 'error');
            }
        });

        function ppdValidarListaCorreos(valor) {
            const partes = String(valor || '').split(/[;,]+/).map(x => x.trim()).filter(Boolean);
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const invalidos = partes.filter(x => !re.test(x));
            return {partes, invalidos};
        }

        async function ppdCargarConfiguracionCopia() {
            try {
                const res = await fetch('ajax/config_ppd_complementos.php?_=' + Date.now(), {cache:'no-store'});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No se pudo cargar la configuración de copia.');
                $('#ppdCorreoEncargado').val(json.correo_copia || '');
                const modo = json.modo_copia === 'resumen' ? 'resumen' : 'individual';
                $('input[name="ppdModoCopia"][value="' + modo + '"]').prop('checked', true);
            } catch (e) {
                console.warn('PPD copia:', e);
                $('#ppdCorreoEncargado').val('');
                $('#ppdCopiaIndividual').prop('checked', true);
            }
        }

        $('#btnPpdGuardarCopia').off('click.ppdCopia').on('click.ppdCopia', async function() {
            const correoCopia = ($('#ppdCorreoEncargado').val() || '').trim();
            const modo = $('input[name="ppdModoCopia"]:checked').val() || 'individual';
            const val = ppdValidarListaCorreos(correoCopia);
            if (val.invalidos.length) {
                Swal.fire('Correo no válido', 'Revise: ' + val.invalidos.join(', '), 'warning');
                return;
            }
            try {
                const fd = new FormData();
                fd.set('accion', 'guardar');
                fd.set('correo_copia', correoCopia);
                fd.set('modo_copia', modo);
                const res = await fetch('ajax/config_ppd_complementos.php', {method:'POST', body:fd, cache:'no-store'});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No se pudo guardar la configuración.');
                $('#ppdCorreoEncargado').val(json.correo_copia || '');
                $('input[name="ppdModoCopia"][value="' + json.modo_copia + '"]').prop('checked', true);
                Swal.fire({icon:'success', title:'Configuración guardada', text: json.modo_copia === 'resumen' ? 'El encargado recibirá un resumen al finalizar el envío.' : 'El encargado recibirá copia individual de cada correo.', timer:1700, showConfirmButton:false, background:'#161b22', color:'#fff'});
            } catch (e) {
                Swal.fire('No se pudo guardar', e.message || 'Error de configuración.', 'error');
            }
        });
        $('#btnPpdEnviarCorreo').off('click.ppdModal').on('click.ppdModal', async function() {
            const seleccionados = $('#tbodyPpdSinComplemento .ppd-check:checked');
            const total = seleccionados.length;
            if (!total) {
                Swal.fire('Sin selección', 'Seleccione por lo menos una factura.', 'warning');
                return;
            }
            const sinCorreo = seleccionados.filter('[data-correo-valido="0"]').length;
            let detalle = 'Se seleccionaron ' + total + (total === 1 ? ' factura.' : ' facturas.');
            if (sinCorreo > 0) detalle += ' ' + sinCorreo + ' no tienen correo válido y deberán corregirse antes del envío.';
            const correoCopia = ($('#ppdCorreoEncargado').val() || '').trim();
            const modoCopia = $('input[name="ppdModoCopia"]:checked').val() || 'individual';
            if (correoCopia) {
                detalle += modoCopia === 'resumen'
                    ? ' El encargado recibirá un resumen único con el detalle de los correos procesados.'
                    : ' El encargado recibirá copia individual de cada correo.';
            }

            const resp = await Swal.fire({
                icon: 'question',
                title: '¿Desea enviar los correos seleccionados?',
                html: ppdEscapeHtml(detalle),
                showCancelButton: true,
                confirmButtonText: 'Sí, continuar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#198754',
                background: '#161b22',
                color: '#fff'
            });
            if (!resp.isConfirmed) return;

            // IMPORTANTE: hasta aquí llega esta versión. Todavía NO existe método de envío.
            Swal.fire({
                icon: 'info',
                title: 'Selección preparada',
                text: 'No se envió ningún correo. El método de envío se implementará en la siguiente etapa.',
                background: '#161b22',
                color: '#fff'
            });
        });

        // Clasificación CFDI con valores explícitos para evitar confusión entre I/E y Recibido/Emitido.
        function normalizarClasificacionCfdi(v) {
            v = String(v || '').toUpperCase();
            const mapa = {
                'IR':'INGRESO_RECIBIDO',
                'IE':'INGRESO_EMITIDO',
                'ER':'EGRESO_RECIBIDO',
                'EE':'EGRESO_EMITIDO'
            };
            return mapa[v] || v;
        }

        // PRUEBA TEMPORAL: el periodo rápido usa únicamente id_empresa + fecha_emision.
        // Los filtros normales siguen usando listar_facturas.php completo.
        var pruebaMesSoloEmpresaFecha = false;
        var pruebaMesInicioMs = 0;
        // PRUEBA RAPIDA 3: nombre de emisor (prefijo izquierdo) -> id_emisor + rango de fechas.
        var pruebaEmisorSoloEmpresaFecha = false;
        var pruebaEmisorInicioMs = 0;
        // PRUEBA RAPIDA 4: nombre de receptor (prefijo izquierdo) -> id_receptor + rango de fechas.
        var pruebaReceptorSoloEmpresaFecha = false;
        var pruebaReceptorInicioMs = 0;

        var totalesCalculados = null;

        // La primera consulta al regresar de otro módulo ya respeta la ruta/index
        // correspondiente al filtro restaurado (emisor, receptor, UUID, etc.).
        activarModoFiltroSegunEstado();

        function pintarTotalesCalculados() {
            if (!table || !totalesCalculados) return;
            const fmt = new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'});
            const mapa = {
                19:'total',21:'subtotal',22:'base_iva',23:'iva_t',24:'base_iva_r',25:'iva_r',26:'base_isr_r',27:'isr_r',
                28:'base_iva_8',29:'iva_8',30:'base_norte',31:'iva_norte',32:'base_sur',33:'iva_sur',46:'saldo'
            };
            Object.entries(mapa).forEach(([idx,campo])=>{
                const f = table.column(Number(idx)).footer();
                if (f) $(f).html(fmt.format(Number(totalesCalculados[campo] || 0)));
            });
        }

        function limpiarTotalesCalculados() {
            totalesCalculados = null;
            if (!table) return;
            const indices = [19,21,22,23,24,25,26,27,28,29,30,31,32,33,46];
            indices.forEach(idx => {
                const f = table.column(idx).footer();
                if (f) $(f).html('');
            });
            $('#btnCalcularTotales').removeClass('btn-success').addClass('btn-outline-success');
        }

        var table = $('#tablaFacturas').DataTable({
            ajax: {
                url: 'ajax/listar_facturas.php',
                type: 'POST',
                cache: false,
                data: function (d) {
                    // Cinturón de seguridad: DataTables jamás debe consultar sin rango.
                    // Si el DOM quedó momentáneamente sin fechas al volver de otro módulo,
                    // reponemos el mes actual antes de formar la petición AJAX.
                    if (!fechaIsoValidaVisor($('#f_inicio').val()) || !fechaIsoValidaVisor($('#f_fin').val())) {
                        establecerRangoMesActualVisor();
                    }
                    const hoyVisor = new Date();
                    const yyyyVisor = hoyVisor.getFullYear();
                    const mmVisor = String(hoyVisor.getMonth() + 1).padStart(2, '0');
                    const ddVisor = String(hoyVisor.getDate()).padStart(2, '0');
                    const iniDefVisor = `${yyyyVisor}-${mmVisor}-01`;
                    const finDefVisor = `${yyyyVisor}-${mmVisor}-${ddVisor}`;

                    d._ts = Date.now();
                    d.modo_mes_simple = pruebaMesSoloEmpresaFecha ? 1 : 0;
                    d.modo_emisor_simple = pruebaEmisorSoloEmpresaFecha ? 1 : 0;
                    d.modo_receptor_simple = pruebaReceptorSoloEmpresaFecha ? 1 : 0;
                    d.inicio = fechaIsoValidaVisor($('#f_inicio').val()) ? $('#f_inicio').val() : iniDefVisor;
                    d.fin = fechaIsoValidaVisor($('#f_fin').val()) ? $('#f_fin').val() : finDefVisor;
                    d.tipo = normalizarClasificacionCfdi($('#filtro_tipo').val());
                    d.metodo = $('#filtro_metodo_pago').val();
                    d.ppd_sin_complemento = $('#btnPpdSinComplemento').attr('data-activo') === '1' ? 1 : 0;
                    d.pue_sin_pago_empresa = $('#btnPueSinPagoEmpresa').attr('data-activo') === '1' ? 1 : 0;
                    d.tipo_busqueda = $('#tipoBusquedaFactura').val() || '';
                    d.busqueda = ($('#customSearch').val() || '').trim();
                    // Evitar que DataTables mande una búsqueda global adicional.
                    if (d.search) d.search.value = '';
                },
                dataSrc: function(json) {
                    if (json && json.error) {
                        console.error('Error listar_facturas:', json.error);
                        mostrarErrorVisor(json.error);
                    }
                    const filas = (json && Array.isArray(json.data)) ? json.data : [];
                    if (json && json.prueba_mes_simple && pruebaMesInicioMs) {
                        const totalNavegador = performance.now() - pruebaMesInicioMs;
                        const dbg = json.debug_ms || {};
                        console.log('RANGO RAPIDO EMPRESA + FECHA', {
                            rango: ($('#f_inicio').val() || '') + ' a ' + ($('#f_fin').val() || ''),
                            total_registros: json.recordsFiltered || 0,
                            count_ms: dbg.count || 0,
                            select_ms: dbg.select || 0,
                            formato_ms: dbg.formato || 0,
                            php_total_ms: dbg.total || 0,
                            navegador_total_ms: Math.round(totalNavegador * 100) / 100
                        });
                        pruebaMesInicioMs = 0;
                    }
                    if (json && json.prueba_emisor_simple && pruebaEmisorInicioMs) {
                        const totalNavegador = performance.now() - pruebaEmisorInicioMs;
                        const dbg = json.debug_ms || {};
                        console.log('EMISOR RAPIDO EMPRESA + ID_EMISOR + FECHA', {
                            prefijo: ($('#customSearch').val() || '').trim(),
                            rango: ($('#f_inicio').val() || '') + ' a ' + ($('#f_fin').val() || ''),
                            emisores_encontrados: json.emisores_encontrados || 0,
                            total_registros: json.recordsFiltered || 0,
                            resolver_emisor_ms: dbg.resolver || 0,
                            count_ms: dbg.count || 0,
                            select_ms: dbg.select || 0,
                            formato_ms: dbg.formato || 0,
                            php_total_ms: dbg.total || 0,
                            navegador_total_ms: Math.round(totalNavegador * 100) / 100
                        });
                        pruebaEmisorInicioMs = 0;
                    }
                    if (json && json.prueba_receptor_simple && pruebaReceptorInicioMs) {
                        const totalNavegador = performance.now() - pruebaReceptorInicioMs;
                        const dbg = json.debug_ms || {};
                        console.log('RECEPTOR RAPIDO EMPRESA + ID_RECEPTOR + FECHA', {
                            prefijo: ($('#customSearch').val() || '').trim(),
                            rango: ($('#f_inicio').val() || '') + ' a ' + ($('#f_fin').val() || ''),
                            receptores_encontrados: json.receptores_encontrados || 0,
                            total_registros: json.recordsFiltered || 0,
                            resolver_receptor_ms: dbg.resolver || 0,
                            count_ms: dbg.count || 0,
                            select_ms: dbg.select || 0,
                            formato_ms: dbg.formato || 0,
                            php_total_ms: dbg.total || 0,
                            navegador_total_ms: Math.round(totalNavegador * 100) / 100
                        });
                        pruebaReceptorInicioMs = 0;
                    }
                    const $contador = $('#contadorPpdSinComplemento');
                    if ($('#btnPpdSinComplemento').attr('data-activo') === '1') {
                        $contador.text(Number(json.recordsFiltered || 0)).removeClass('d-none');
                    } else {
                        $contador.addClass('d-none').text('0');
                    }
                    const $contadorPue = $('#contadorPueSinPagoEmpresa');
                    if ($('#btnPueSinPagoEmpresa').attr('data-activo') === '1') {
                        $contadorPue.text(Number(json.recordsFiltered || 0)).removeClass('d-none');
                    } else {
                        $contadorPue.addClass('d-none').text('0');
                    }
                    return filas;
                }
            },
            serverSide: true,
            processing: true,
            deferRender: true,
            searchDelay: 350,
            scrollX: true,
            scrollCollapse: false,
            autoWidth: false,
            paging: true,
            pageLength: 15,
            fixedColumns: { left: 4 },
            searching: true,
            dom: 'rt<"d-flex justify-content-between mt-2"ip>',
            order: [[7, 'asc'], [0, 'asc']],
            language: {
                emptyTable: 'No hay facturas disponibles',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ facturas',
                infoEmpty: 'Mostrando 0 a 0 de 0 facturas',
                infoFiltered: '(filtrado de _MAX_ facturas totales)',
                lengthMenu: 'Mostrar _MENU_ facturas',
                loadingRecords: 'Cargando facturas...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron facturas con esos filtros',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' }
            },
            initComplete: function () {
                $('#btnExportarArchivos').on('click', function () {
                    exportarXmlPdfFiltrados();
                });

                $('#btnVerificarEstatusSat').off('click.verSat').on('click.verSat', function () {
                    abrirVerificadorEstatusSat();
                });

                $('#btnAplicarFiltros').on('click', function () {
                    validarYAplicarFiltros();
                });

                $('#btnPpdSinComplemento').off('click.ppd').on('click.ppd', function () {
                    // Ya NO altera los filtros del visor principal.
                    // Abre un modal independiente con todos los PPD sin complemento del rango visible.
                    abrirModalPpdSinComplemento();
                });

                $('#btnLimpiarFiltros').on('click', function () {
                    $('#filtro_tipo').val('');
                    $('#filtro_metodo_pago').val('');
                    $('#tipoBusquedaFactura').val('');
                    $('#customSearch').val('');
                    $('#btnPpdSinComplemento')
                        .attr('data-activo', '0')
                        .removeClass('btn-warning text-dark')
                        .addClass('btn-outline-warning');
                    $('#contadorPpdSinComplemento').addClass('d-none').text('0');
                    $('#btnPueSinPagoEmpresa')
                        .attr('data-activo', '0')
                        .removeClass('btn-info text-dark')
                        .addClass('btn-outline-info');
                    $('#contadorPueSinPagoEmpresa').addClass('d-none').text('0');
                    establecerRangoMesActualVisor();
                    table.ajax.reload();
                });

                // La clasificación contable del CFDI se aplica inmediatamente.
                // TODOS: fecha ASC y después tipo.
                // Tipo específico: fecha ASC (el tipo ya está filtrado y no aporta al orden).
                $('#filtro_tipo').on('change', function () {
                    const tipoSeleccionado = $(this).val();

                    if (tipoSeleccionado) {
                        table.order([[7, 'asc']]);
                    } else {
                        table.order([[7, 'asc'], [0, 'asc']]);
                    }

                    table.ajax.reload();
                });

                // Enter en cualquiera de las fechas aplica el filtro.
                $('#f_inicio, #f_fin').on('keydown', function (e) {
                    if (e.key === 'Enter') validarYAplicarFiltros();
                });

                // Exportación directa: no depende de botones ocultos de DataTables.
                $('#btnExportarExcel').off('click.export').on('click.export', function () {
                    exportarTablaExcel();
                });
                $('#btnExportarCsv').off('click.export').on('click.export', async function () {
                    const opcion = await solicitarTipoExportacionCsv();
                    if (!opcion) return;
                    if (opcion.modo === 'detalle_nomina_gk') {
                        try {
                            if (typeof window.exportarNominaGkExcel !== 'function') {
                                throw new Error('No se cargó el módulo de exportación de nómina. Recarga la página con Ctrl+F5.');
                            }
                            await window.exportarNominaGkExcel(table.ajax.params() || {}, <?= json_encode($_SESSION['nomina_gk_csrf']) ?>);
                        } catch (error) {
                            await Swal.fire({icon:'error', title:'No se pudo abrir el reporte de nómina',
                                text:error.message || String(error), confirmButtonText:'Cerrar',
                                allowOutsideClick:false, allowEscapeKey:false, timer:undefined});
                        }
                        return;
                    }
                    if (opcion.modo === 'auditoria_diot') {
                        window.location.href = `ajax/exportar_facturas_auditoria_diot_excel.php?mes=${encodeURIComponent(opcion.mes)}&anio=${encodeURIComponent(opcion.anio)}`;
                        return;
                    }
                    if (opcion.modo === 'detalle_recibidos') {
                        exportarDetalleRecibidosExcel();
                        return;
                    }
                    if (opcion.modo === 'emitidos_gk') {
                        exportarEmitidosGkExcel();
                        return;
                    }
                    exportarTablaCsv(opcion);
                });


                // Permite que el usuario amplíe o reduzca columnas arrastrando el borde del encabezado.
                setTimeout(function () {
                    table.columns.adjust();
                    sincronizarAnchosEncabezadoCuerpo(table);
                    if (table.fixedColumns && table.fixedColumns().relayout) {
                        table.fixedColumns().relayout();
                    }
                    inicializarRedimensionColumnas(table);
                }, 200);
            },
            drawCallback: function () {
                const api = this.api();
                window.requestAnimationFrame(function () {
                    api.columns.adjust();
                    sincronizarAnchosEncabezadoCuerpo(api);
                    if (api.fixedColumns && api.fixedColumns().relayout) {
                        api.fixedColumns().relayout();
                    }
                });
            },
            columnDefs: [
                { targets: 0, width: '55px' },
                { targets: 1, width: '90px' },
                { targets: 2, width: '330px' },
                { targets: 3, width: '500px' },
                { targets: 4, width: '85px' },
                { targets: 5, width: '110px' },
                { targets: 6, width: '135px' },
                { targets: 7, width: '110px' },
                { targets: 8, width: '145px' },
                { targets: 9, width: '120px' },
                { targets: 10, width: '105px' },
                { targets: 11, width: '85px' },
                { targets: [12,13,14,15], width: '145px' },
                { targets: 16, width: '105px' },
                { targets: 17, width: '75px' },
                { targets: 18, width: '95px' },
                { targets: 19, width: '175px' },
                { targets: 20, width: '125px' },
                { targets: [21,22,23,24,25,26,27,28,29,30,31,32,33,46], width: '175px' },
                { targets: [41,42], width: '125px' },
                { targets: 34, width: '90px' },
                { targets: [35,36], width: '125px' },
                { targets: 37, width: '105px' },
                { targets: 38, width: '145px' },
                { targets: 39, width: '430px' },
                { targets: 40, width: '90px' },
                { targets: [43,44], width: '210px' },
                { targets: 45, width: '105px' },
                { targets: [47,48,49,50,52,53], width: '125px' },
                { targets: 51, width: '90px' },
                { targets: [54,55,56], width: '130px' }
            ],
            columns: [
                {data:'tipo', className:'text-centro fw-bold'},
                {data:'estatus_sat', className:'text-centro', render:function(d,type,row){
                    let estado = String(d ?? '').trim().toUpperCase();
                    // Compatibilidad con registros antiguos que guardaron el estatus como bandera 1/0.
                    if (estado === '' || estado === '1' || estado === 'TRUE') estado = 'VIGENTE';
                    if (estado === '0' || estado === 'FALSE') estado = 'CANCELADO';
                    if (type !== 'display') return estado;
                    const cancelada = estado === 'CANCELADO' || estado === 'CANCELADA';
                    const clase = cancelada ? 'bg-danger' : (estado === 'VIGENTE' ? 'bg-success' : 'bg-secondary');
                    const texto = cancelada ? 'CANCELADA' : (estado === 'VIGENTE' ? 'VIGENTE' : estado);
                    const excluidoDiot = Number(row?.raw?.excluir_diot || 0) === 1;
                    const banderaDiot = excluidoDiot
                        ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.60rem;white-space:nowrap;" title="Este CFDI está marcado para no incluirse en la DIOT"><i class="bi bi-flag-fill"></i> NO DIOT</span>'
                        : '';
                    return `<div class="estatus-sat-wrap"><span class="badge ${clase}" style="font-size:.62rem; white-space:nowrap;">${texto}</span>${banderaDiot}</div>`;
                }},
                {data:'uuid', className:'text-izquierda texto-amplio'},
                {data:'emisor', className:'text-izquierda texto-amplio', render:function(d,type){
                    if (type !== 'display') return d || '';
                    const v = d || '';
                    return `<span title="${$('<div>').text(v).html()}">${$('<div>').text(v).html()}</span>`;
                }},
                {data:'version', className:'text-centro'},
                {data:'serie', className:'text-izquierda'},
                {data:'folio', className:'text-izquierda'},
                {data:'fecha', className:'text-centro'},
                {data:'rfc_emisor', className:'text-izquierda'},
                {data:'forma_pago', className:'text-centro'},
                {data:'metodo_pago', className:'text-centro'},
                {
                    data:'numero_pagos',
                    className:'text-centro',
                    render:function(d, type, row) {
                        const n = Number(d || 0);

                        if (type !== 'display') return n;

                        if (n <= 0) {
                            return '<span class="badge bg-secondary">0</span>';
                        }

                        return `<button type="button"
                                    class="btn btn-sm btn-success py-0 px-2 fw-bold"
                                    title="Ver ${n} pago${n === 1 ? '' : 's'} aplicado${n === 1 ? '' : 's'}"
                                    onclick='verDetallePagos(${JSON.stringify(row.uuid)}, ${JSON.stringify(row)})'>
                                    ${n}
                                </button>`;
                    }
                },
                {data:'p_sat', className:'text-centro'},
                {data:'p_contpaq', className:'text-centro'},
                {data:'p_usu', className:'text-centro'},
                {data:'fecha_fiscal', className:'text-centro fw-bold'},
                {data:'uso_cfdi', className:'text-centro'},
                {data:'moneda', className:'text-centro'},
                {data:'tc_factura', className:'text-derecha'},
                {data:'total', className:'text-derecha'},
                {
                    data:null, orderable:false, className:'text-centro',
                    render:function(d){
                        let js=btoa(unescape(encodeURIComponent(JSON.stringify(d.raw))));
                        let botones = `<button class="btn btn-mini btn-info" onclick="verDetalle('${js}')" title="Editar"><i class="bi bi-pencil"></i></button>`;
                        const ecf=String(d.estatus_conciliacion_financiera||'PENDIENTE').toUpperCase();
                        const ccf=ecf==='CONCILIADO'?'btn-success':(ecf==='PARCIAL'?'btn-warning':(ecf==='DIFERENCIA'||ecf.indexOf('SIN_')===0?'btn-danger':'btn-secondary'));
                        botones += `<button class="btn btn-mini ${ccf}" onclick='verDetallePagos(${JSON.stringify(d.uuid)}, ${JSON.stringify(d)})' title="Conciliación financiera: ${ecf}"><i class="bi bi-link-45deg"></i></button>`;
                        if (Number(d.es_gasolina || 0) === 1) {
                            botones += `<button class="btn btn-mini btn-success" onclick="verGasolina('${d.uuid}')" title="Estado de cuenta de combustible"><i class="bi bi-fuel-pump-fill"></i></button>`;
                        }
                        <?php if ($puedeVerXmlPdf): ?>
                        botones += `<button class="btn btn-mini btn-danger" onclick="descargarPDF('${d.uuid}')" title="PDF"><i class="bi bi-file-pdf"></i></button>`;
                        botones += `<button class="btn btn-mini btn-warning" onclick="verXML('${d.uuid}')" title="XML"><i class="bi bi-code-slash"></i></button>`;
                        <?php endif; ?>
                        return botones;
                    }
                },
                {data:'subtotal', className:'text-derecha'},
                {data:'base_iva', className:'text-derecha'},
                {data:'iva_t', className:'text-derecha'},
                {data:'base_iva_r', className:'text-derecha'},
                {data:'iva_r', className:'text-derecha'},
                {data:'base_isr_r', className:'text-derecha'},
                {data:'isr_r', className:'text-derecha'},
                {data:'base_iva_8', className:'text-derecha'},
                {data:'iva_8', className:'text-derecha'},
                {data:'base_norte', className:'text-derecha'},
                {data:'iva_norte', className:'text-derecha'},
                {data:'base_sur', className:'text-derecha'},
                {data:'iva_sur', className:'text-derecha'},
                {data:'cp', className:'text-centro'},
                {data:'region_cp', className:'text-centro'},
                {data:'region_aplicada', className:'text-centro'},
                {data:'revision', className:'text-centro', render:(d,t,r)=>d ? `<span class="badge bg-danger" title="${$('<div>').text(r.mensaje_revision||'Requiere revisión').html()}">REVISAR</span>` : '<span class="badge bg-success">OK</span>'},
                {data:'rfc_receptor', className:'text-izquierda'},
                {data:'receptor', className:'text-izquierda texto-amplio', render:function(d,type){
                    if (type !== 'display') return d || '';
                    const v = d || '';
                    return `<span title="${$('<div>').text(v).html()}">${$('<div>').text(v).html()}</span>`;
                }},
                {data:'ya_pago', className:'text-centro', render:d => d ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-warning text-dark">No</span>'},
                {data:'tc_pago', className:'text-derecha'},
                {data:'tc_banco', className:'text-derecha'},
                {data:'ref_xml', className:'text-izquierda'},
                {data:'ref_banco', className:'text-izquierda'},
                {data:'conciliado', className:'text-centro', render:d => d ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>'},
                {data:'saldo', className:'text-derecha'},
                {data:'nomina_tipo_nomina', className:'text-centro'},
                {data:'nomina_fecha_pago', className:'text-centro'},
                {data:'nomina_fecha_inicial_pago', className:'text-centro'},
                {data:'nomina_fecha_final_pago', className:'text-centro'},
                {data:'nomina_num_dias_pagados', className:'text-derecha'},
                {data:'nomina_periodicidad_pago', className:'text-centro'},
                {data:'nomina_num_empleado', className:'text-izquierda'},
                {data:'nomina_total_percepciones', className:'text-derecha', render:d=>Number(d||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})},
                {data:'nomina_total_deducciones', className:'text-derecha', render:d=>Number(d||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})},
                {data:'nomina_total_otros_pagos', className:'text-derecha', render:d=>Number(d||0).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})}
            ],
            footerCallback:function(){
                // Los totales ya no se calculan automáticamente al consultar/paginar.
                // Si el usuario ya los pidió con el botón Totales, se conservan al cambiar de página.
                pintarTotalesCalculados();
            }
        });


        $('#btnCalcularTotales').off('click.totales').on('click.totales', function(){
            if (modoVisor !== 'facturas') return;

            const ini = $('#f_inicio').val();
            const fin = $('#f_fin').val();
            if (ini && fin && ini > fin) {
                Swal.fire({icon:'warning', title:'Rango inválido', text:'La fecha inicial no puede ser mayor a la fecha final.'});
                return;
            }

            const $btn = $(this);
            const textoOriginal = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Calculando...');

            Swal.fire({
                title: 'Calculando totales',
                text: 'Se están sumando todos los registros del filtro seleccionado.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading()
            });

            // Determinar la misma ruta/índice que está usando actualmente el listado.
            activarModoFiltroSegunEstado();

            $.ajax({
                url: 'ajax/listar_facturas.php',
                type: 'POST',
                dataType: 'json',
                cache: false,
                data: {
                    solo_totales: 1,
                    modo_mes_simple: pruebaMesSoloEmpresaFecha ? 1 : 0,
                    modo_emisor_simple: pruebaEmisorSoloEmpresaFecha ? 1 : 0,
                    modo_receptor_simple: pruebaReceptorSoloEmpresaFecha ? 1 : 0,
                    _ts: Date.now(),
                    inicio: ini || '',
                    fin: fin || '',
                    tipo: normalizarClasificacionCfdi($('#filtro_tipo').val()),
                    metodo: $('#filtro_metodo_pago').val() || '',
                    ppd_sin_complemento: $('#btnPpdSinComplemento').attr('data-activo') === '1' ? 1 : 0,
                    pue_sin_pago_empresa: $('#btnPueSinPagoEmpresa').attr('data-activo') === '1' ? 1 : 0,
                    tipo_busqueda: $('#tipoBusquedaFactura').val() || '',
                    busqueda: ($('#customSearch').val() || '').trim()
                }
            }).done(function(json){
                if (!json || json.error || json.ok !== true) {
                    Swal.fire({icon:'error', title:'No se pudieron calcular los totales', text:(json && json.error) ? json.error : 'Respuesta no válida del servidor.'});
                    return;
                }
                totalesCalculados = json.totals || {};
                pintarTotalesCalculados();
                $btn.removeClass('btn-outline-success').addClass('btn-success');
                const registros = Number(totalesCalculados.registros || 0).toLocaleString('es-MX');
                const segundos = Number(json.tiempo_ms || 0) / 1000;
                Swal.fire({
                    icon:'success',
                    title:'Totales calculados',
                    html:`Se sumaron <b>${registros}</b> registros del filtro actual.<br><small>Tiempo: ${segundos.toFixed(2)} s</small>`,
                    timer:2500,
                    showConfirmButton:false
                });
            }).fail(function(xhr){
                let msg = 'Error al calcular los totales.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e) {}
                Swal.fire({icon:'error', title:'Error', text:msg});
            }).always(function(){
                $btn.prop('disabled', false).html(textoOriginal);
            });
        });

        // Si cambia cualquier criterio, los totales anteriores dejan de representar
        // el filtro actual. Se limpian hasta que el usuario vuelva a pulsar Totales.
        $('#f_inicio,#f_fin,#filtro_tipo,#filtro_metodo_pago,#tipoBusquedaFactura,#customSearch,#filtro_anio_rapido,#filtro_mes_rapido')
            .off('change.totales input.totales')
            .on('change.totales input.totales', function(){ limpiarTotalesCalculados(); });
        $('#btnPpdSinComplemento,#btnPueSinPagoEmpresa,#btnLimpiarFiltros')
            .off('click.totalesLimpiar')
            .on('click.totalesLimpiar', function(){ limpiarTotalesCalculados(); });

        var modoVisor = 'facturas';
        var ultimoErrorVisor = '';

        function mostrarErrorVisor(mensaje) {
            if (!mensaje || mensaje === ultimoErrorVisor) return;
            ultimoErrorVisor = mensaje;
            const box = `<div id="alertaErrorVisor" class="alert alert-danger py-2 mb-2"><b>Error al consultar el visor:</b> ${$('<div>').text(mensaje).html()}</div>`;
            $('#alertaErrorVisor').remove();
            $('.visor-selector-modo').after(box);
        }

        var tablePagos = $('#tablaDetallePagos').DataTable({
            // No consultar Detalle de pagos mientras la vista activa sea FACTURAS.
            // Antes DataTables disparaba este AJAX aunque la tabla estuviera oculta y,
            // si el endpoint tardaba o fallaba, mostraba un aviso que parecía pertenecer
            // al filtro de facturas (por ejemplo al buscar por Folio), aunque eran rutas
            // completamente distintas.
            ajax: function(d, callback) {
                if (modoVisor !== 'pagos') {
                    callback({data:[]});
                    return;
                }

                const params = {
                    inicio: $('#f_inicio').val(),
                    fin: $('#f_fin').val(),
                    tipo: normalizarClasificacionCfdi($('#filtro_tipo').val()),
                    metodo: $('#filtro_metodo_pago').val()
                };

                $.ajax({
                    url: 'ajax/listar_detalles_pagos.php',
                    method: 'GET',
                    dataType: 'json',
                    data: params
                }).done(function(json){
                    if (json && json.error) {
                        console.error('Error listar_detalles_pagos:', json.error);
                        mostrarErrorVisor(json.error);
                    }
                    callback({data:(json && Array.isArray(json.data)) ? json.data : []});
                }).fail(function(xhr, status, error){
                    const detalle = (xhr && xhr.responseText) ? String(xhr.responseText).trim() : '';
                    console.error('Error AJAX listar_detalles_pagos:', status, error, detalle);
                    mostrarErrorVisor(detalle || ('No se pudo consultar el detalle de pagos (' + (error || status || 'error') + ').'));
                    callback({data:[]});
                });
            },
            scrollX:true, scrollCollapse:false, autoWidth:false, paging:true, pageLength:15,
            fixedColumns:{left:2}, searching:true, dom:'rt<"d-flex justify-content-between mt-2"ip>',
            order:[[25,'desc']],
            language:{
                emptyTable:'No hay detalles de pago disponibles',
                info:'Mostrando _START_ a _END_ de _TOTAL_ movimientos',
                infoEmpty:'Mostrando 0 a 0 de 0 movimientos',
                infoFiltered:'(filtrado de _MAX_ movimientos totales)',
                zeroRecords:'No se encontraron detalles con esos filtros',
                paginate:{first:'Primero',last:'Último',next:'Siguiente',previous:'Anterior'}
            },
            columns:[
                {data:'tipo',className:'text-centro'}, {data:'uuid_factura'}, {data:'serie'}, {data:'folio'}, {data:'fecha_factura',className:'text-centro'},
                {data:'rfc_emisor'}, {data:'emisor'}, {data:'metodo_pago',className:'text-centro'}, {data:'total_factura',className:'text-derecha'},
                {data:'saldo_factura',className:'text-derecha'}, {data:'ya_pago',className:'text-centro',render:d=>d?'<span class="badge bg-success">Sí</span>':'<span class="badge bg-warning text-dark">No</span>'},
                {data:'origen',className:'text-centro'}, {data:'parcialidad',className:'text-centro'}, {data:'fecha_sat',className:'text-centro'},
                {data:'tc_factura',className:'text-derecha'}, {data:'moneda_pago_sat',className:'text-centro'}, {data:'tc_pago_sat',className:'text-derecha'},
                {data:'fecha_contpaq',className:'text-centro fw-bold'}, {data:'moneda_contpaq',className:'text-centro'}, {data:'tc_contpaq',className:'text-derecha'},
                {data:'monto_conciliado_contpaq',className:'text-derecha'}, {data:'saldo_por_conciliar',className:'text-derecha'},
                {data:'estatus_conciliacion_contpaq',className:'text-centro',render:d=>d==='CONCILIADO'?'<span class="badge bg-success">CONCILIADO</span>':(d==='PARCIAL'?'<span class="badge bg-warning text-dark">PARCIAL</span>':'<span class="badge bg-secondary">PENDIENTE</span>')},
                {data:'fecha_banco',className:'text-centro'}, {data:'fecha_usuario',className:'text-centro'}, {data:'fecha_fiscal',className:'text-centro fw-bold'}, {data:'importe_aplicado',className:'text-derecha'},
                {data:'saldo_anterior',className:'text-derecha'}, {data:'saldo_insoluto',className:'text-derecha'}, {data:'base_iva_16',className:'text-derecha'},
                {data:'iva_16',className:'text-derecha'}, {data:'base_iva_8',className:'text-derecha'}, {data:'iva_8',className:'text-derecha'},
                {data:'base_tasa_0',className:'text-derecha'}, {data:'base_exento',className:'text-derecha'}, {data:'base_no_objeto',className:'text-derecha'},
                {data:'iva_retenido',className:'text-derecha'}, {data:'isr_retenido',className:'text-derecha'}, {data:'ieps_otros',className:'text-derecha'},
                {data:'forma_pago',className:'text-centro'}, {data:'referencia'}, {data:'uuid_pago'},
                {data:'es_sustituido',className:'text-centro',render:(d,t,r)=>d?'<span class="badge bg-danger">SUSTITUIDO</span>':'<span class="badge bg-success">VIGENTE</span>'},
                {data:'uuid_sustituto',render:d=>d||'-'},
                {data:'cuenta_diot',className:'text-centro',render:d=>d?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>'},
                {data:'aplicado',className:'text-centro',render:d=>d?'<span class="badge bg-success">Sí</span>':'<span class="badge bg-secondary">No</span>'}
            ],
            columnDefs:[
                {targets:0,width:'55px'},{targets:1,width:'320px'},{targets:[2,3],width:'100px'},{targets:4,width:'115px'},
                {targets:5,width:'145px'},{targets:6,width:'400px'},{targets:[7,10,11,12,15,18,22,39,42],width:'105px'},
                {targets:[8,9,14,16,19,20,21,26,27,28,29,30,31,32,33,34,35,36,37,38],width:'125px'},
                {targets:[13,17,23,24,25],width:'125px'},{targets:40,width:'190px'},{targets:41,width:'330px'}
            ],
            footerCallback:function(){
                const api=this.api(), intVal=v=>typeof v==='string'?(parseFloat(v.replace(/[\$,]/g,''))||0):(typeof v==='number'?v:0);
                const fmt=new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'});
                const rows=api.rows({search:'applied'}).data().toArray();

                // La tabla conserva los importes originales del CFDI, pero los totales
                // fiscales se expresan siempre en MXN usando la conversión al vuelo.
                const mapa={
                    20:'monto_conciliado_contpaq', 21:'saldo_por_conciliar',
                    26:'importe_aplicado_mxn', 29:'base_iva_16_mxn', 30:'iva_16_mxn',
                    31:'base_iva_8_mxn', 32:'iva_8_mxn', 33:'base_tasa_0_mxn',
                    34:'base_exento_mxn', 35:'base_no_objeto_mxn', 36:'iva_retenido_mxn',
                    37:'isr_retenido_mxn', 38:'ieps_otros_mxn'
                };
                Object.entries(mapa).forEach(([idx,campo])=>{
                    const total=rows.reduce((acc,r)=>acc+intVal(r && r[campo]),0);
                    const f=api.column(Number(idx)).footer();
                    if(f) $(f).html(fmt.format(total));
                });
            }
        });


        $('#btnReprocesarComplementos').off('click.reprocesar').on('click.reprocesar', async function(){
            const r=await Swal.fire({
                icon:'warning', title:'Reprocesar complementos de pago',
                html:'Se releerán <b>todos los CFDI tipo P de la empresa activa</b>, se reconstruirán relaciones <b>04 - Sustitución</b> y se recalcularán saldos e impuestos de las facturas afectadas.<br><br><span class="text-warning">No se borran XML ni historial.</span>',
                showCancelButton:true, confirmButtonText:'Sí, reprocesar', cancelButtonText:'Cancelar',
                confirmButtonColor:'#d39e00', background:'#161b22', color:'#fff'
            });
            if(!r.isConfirmed) return;
            Swal.fire({title:'Reprocesando complementos...',html:'Puede tardar dependiendo del histórico.',allowOutsideClick:false,allowEscapeKey:false,didOpen:()=>Swal.showLoading(),background:'#161b22',color:'#fff'});
            try{
                const resp=await fetch('ajax/reconstruir_detalles_pago.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
                const j=await resp.json();
                if(!resp.ok || !j || j.status==='error') throw new Error((j&&j.msg)||'No se pudo reprocesar.');
                await Swal.fire({icon:j.status==='warning'?'warning':'success',title:'Reproceso terminado',text:j.msg||'Proceso terminado.',background:'#161b22',color:'#fff'});
                table.ajax.reload(null,false); tablePagos.ajax.reload(null,false);
            }catch(e){
                Swal.fire({icon:'error',title:'Error',text:e.message||'No se pudo reprocesar los complementos.',background:'#161b22',color:'#fff'});
            }
        });


        var cancelarReprocesoNomina = false;
        $('#btnReprocesarNomina').off('click.nomina').on('click.nomina', async function(){
            const r=await Swal.fire({
                icon:'question', title:'Procesar XML de nómina',
                html:'Se releerán los <b>CFDI tipo N</b> ya guardados en <code>facturas_datos.xml_base64</code> y se completarán los campos de período, empleado e importes.<br><br>El proceso trabaja en <b>lotes pequeños</b> para evitar timeouts del servidor. No se volverán a descargar XML ni se duplicarán facturas.',
                showCancelButton:true, confirmButtonText:'Sí, procesar', cancelButtonText:'Cancelar',
                confirmButtonColor:'#0dcaf0', background:'#161b22', color:'#fff'
            });
            if(!r.isConfirmed) return;

            cancelarReprocesoNomina=false;
            let revisados=0, hechos=0, errores=0, sinXml=0, ultimo='', huboRegistros=false;

            const leerJsonSeguro = async (resp, contexto) => {
                const texto = await resp.text();
                let j = null;
                try {
                    j = JSON.parse(texto);
                } catch (_) {
                    const limpio = String(texto||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,450);
                    throw new Error(`El servidor no devolvió JSON ${contexto} (HTTP ${resp.status}). Respuesta: ${limpio || 'vacía'}`);
                }
                if(!resp.ok || !j || !j.ok) throw new Error((j&&j.error)||`Error HTTP ${resp.status} ${contexto}.`);
                return j;
            };

            const pintar=()=>{
                Swal.update({
                    title:'Procesando XML de nómina...',
                    html:`<div class="progress mb-3" style="height:22px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Procesando por lotes...</div></div>`+
                         `Revisados: <b>${revisados}</b><br>Correctos: <b>${hechos}</b> &nbsp; Errores: <b>${errores}</b> &nbsp; Sin XML: <b>${sinXml}</b><br>`+
                         `<small>No se hace conteo previo para evitar el 504 de nginx. Puede cancelar; lo ya procesado queda guardado.</small>`,
                    showCancelButton:true, cancelButtonText:'Cancelar proceso', showConfirmButton:false,
                    allowOutsideClick:false, allowEscapeKey:false, background:'#161b22', color:'#fff'
                });
            };

            try{
                Swal.fire({
                    title:'Procesando XML de nómina...',
                    html:'Iniciando primer lote...',
                    showCancelButton:true,cancelButtonText:'Cancelar proceso',showConfirmButton:false,
                    allowOutsideClick:false,allowEscapeKey:false,background:'#161b22',color:'#fff'
                }).then(x=>{if(x.dismiss===Swal.DismissReason.cancel)cancelarReprocesoNomina=true;});
                pintar();

                while(!cancelarReprocesoNomina){
                    const f=new FormData();
                    f.append('accion','paso');
                    f.append('ultimo_uuid',ultimo);
                    f.append('limite','10');

                    const rp=await fetch('ajax/reprocesar_nomina_cfdi.php',{
                        method:'POST',body:f,
                        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},
                        cache:'no-store'
                    });
                    const j=await leerJsonSeguro(rp,'al reprocesar nómina');

                    const loteRevisados = Number(j.revisados||0);
                    if(loteRevisados>0) huboRegistros=true;
                    revisados += loteRevisados;
                    hechos += Number(j.procesados||0);
                    errores += Number(j.errores||0);
                    sinXml += Number(j.sin_xml||0);
                    ultimo = j.ultimo_uuid||ultimo;
                    pintar();

                    if(j.terminado) break;

                    // Cede brevemente el navegador entre peticiones y evita encadenar una solicitud pesada.
                    await new Promise(resolve=>setTimeout(resolve,100));
                }

                Swal.close();

                if(!huboRegistros && !cancelarReprocesoNomina){
                    await Swal.fire({
                        icon:'info', title:'Sin CFDI de nómina',
                        text:'No hay CFDI tipo N para procesar en la empresa activa.',
                        background:'#161b22',color:'#fff'
                    });
                    return;
                }

                await Swal.fire({
                    icon:cancelarReprocesoNomina?'warning':'success',
                    title:cancelarReprocesoNomina?'Proceso cancelado':'Nómina procesada',
                    html:`Revisados: <b>${revisados}</b><br>Correctos: <b>${hechos}</b><br>Errores: <b>${errores}</b><br>Sin XML: <b>${sinXml}</b>${cancelarReprocesoNomina?'<br><br>Puede volver a ejecutarlo cuando quiera.':''}`,
                    background:'#161b22',color:'#fff'
                });
                table.ajax.reload(null,false);
            }catch(e){
                Swal.fire({icon:'error',title:'Error',text:e.message||'No se pudo procesar la nómina.',background:'#161b22',color:'#fff'});
            }
        });

        function cambiarVistaVisor(modo) {
            modoVisor = modo === 'pagos' ? 'pagos' : 'facturas';
            $('#alertaErrorVisor').remove(); ultimoErrorVisor='';
            if (modoVisor === 'facturas') {
                $('#contenedorFacturas').show(); $('#contenedorDetallePagos').hide();
                $('#btnVistaFacturas').removeClass('btn-outline-primary').addClass('btn-primary');
                $('#btnVistaPagos').removeClass('btn-info').addClass('btn-outline-info');
                $('#leyendaFechaVista').text('Rango de fechas: fecha de emisión de la factura');
                $('#tipoBusquedaFactura').prop('disabled', false);
                $('#customSearch').attr('placeholder','Escriba los caracteres iniciales y pulse Filtrar...').val('');
                table.search('');
                table.ajax.reload(null, false);
                setTimeout(()=>{ table.columns.adjust(); if(table.fixedColumns&&table.fixedColumns().relayout)table.fixedColumns().relayout(); },80);
            } else {
                $('#btnPpdSinComplemento')
                    .attr('data-activo', '0')
                    .removeClass('btn-warning text-dark')
                    .addClass('btn-outline-warning');
                $('#contadorPpdSinComplemento').addClass('d-none').text('0');
                $('#contenedorFacturas').hide(); $('#contenedorDetallePagos').show();
                $('#btnVistaFacturas').removeClass('btn-primary').addClass('btn-outline-primary');
                $('#btnVistaPagos').removeClass('btn-outline-info').addClass('btn-info');
                $('#leyendaFechaVista').text('Rango de fechas: fecha de aplicación fiscal del pago');
                $('#tipoBusquedaFactura').prop('disabled', true).val('');
                $('#customSearch').attr('placeholder','Buscar en detalle de pagos y pulse Filtrar...').val('');
                tablePagos.search('');
                tablePagos.ajax.reload(function(){
                    tablePagos.columns.adjust(); if(tablePagos.fixedColumns&&tablePagos.fixedColumns().relayout)tablePagos.fixedColumns().relayout();
                },false);
            }
        }

        $('#btnVistaFacturas').off('click.vista').on('click.vista',()=>cambiarVistaVisor('facturas'));
        $('#btnVistaPagos').off('click.vista').on('click.vista',()=>cambiarVistaVisor('pagos'));

        // La búsqueda NO consulta mientras se escribe.
        // Solo se ejecuta al pulsar FILTRAR o al presionar ENTER en el campo de búsqueda.
        $('#customSearch').off('keyup input change keydown.buscarEnter');

        // ENTER en el campo de búsqueda = exactamente la misma acción que el botón FILTRAR.
        // Así conservamos una sola lógica para UUID, RFC, nombre emisor/receptor,
        // rango de fechas, paginación y las rutas rápidas por índice.
        $('#customSearch').on('keydown.buscarEnter', function(e){
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                e.stopPropagation();
                $('#btnAplicarFiltros').trigger('click');
                return false;
            }
        });

        // Al cambiar el campo de búsqueda limpiamos el valor anterior.
        // Esto evita que un UUID usado previamente siga quedando activo cuando el usuario
        // cambia a RFC/nombre y parezca que el visor se quedó "ido" hasta recargar la página.
        $('#tipoBusquedaFactura').off('change.buscar').on('change.buscar', function(){
            if (($(this).val() || '') === 'nombre_emisor_descripcion') {
                // El modal es un puente. Guardamos el filtro REAL aplicado para restaurarlo
                // si el usuario cierra el modal sin seleccionar una factura.
                window.estadoVisorBaseDescripcion = Object.assign({}, window.ultimoEstadoAplicadoVisor || capturarEstadoFiltrosVisor());
            }
            // Solo limpiar el texto anterior. NO recargar aquí: la regla del visor es
            // consultar únicamente al pulsar FILTRAR. La recarga automática agregada
            // junto con la búsqueda UUID podía disparar una consulta intermedia con
            // filtros viejos y dejar la pantalla aparentemente desfasada.
            $('#customSearch').val('');
            if (modoVisor === 'facturas') table.search('');
        });
        $('#btnAplicarFiltros').off('click').on('click', function(){
            const ini=$('#f_inicio').val(), fin=$('#f_fin').val();
            if(ini && fin && ini>fin){ alert('La fecha inicial no puede ser mayor a la fecha final.'); return; }

            if (modoVisor === 'facturas' && ($('#tipoBusquedaFactura').val() || '') === 'nombre_emisor_descripcion') {
                abrirModalEmisorDescripcion();
                return;
            }

            if (modoVisor === 'pagos') {
                // La vista detalle de pagos conserva su búsqueda general existente,
                // pero también espera hasta pulsar FILTRAR.
                tablePagos.search(($('#customSearch').val() || '').trim());
                tablePagos.ajax.reload();
            } else {
                // Elegir la ruta rápida según el filtro activo.
                // Nombre emisor/receptor conserva su índice compuesto; sin búsqueda usa empresa+fecha.
                activarModoFiltroSegunEstado();
                marcarEstadoVisorAplicado();
                table.search('');
                table.page('first').ajax.reload();
            }
        });
        $('#btnLimpiarFiltros').off('click').on('click', function(){
            limpiarTotalesCalculados();
            $('#filtro_tipo').val('');
            $('#filtro_metodo_pago').val('');
            $('#tipoBusquedaFactura').val('');
            $('#btnPpdSinComplemento')
                .attr('data-activo', '0')
                .removeClass('btn-warning text-dark')
                .addClass('btn-outline-warning');
            $('#contadorPpdSinComplemento').addClass('d-none').text('0');
            $('#btnPueSinPagoEmpresa')
                .attr('data-activo', '0')
                .removeClass('btn-info text-dark')
                .addClass('btn-outline-info');
            $('#contadorPueSinPagoEmpresa').addClass('d-none').text('0');
            establecerRangoMesActualVisor();
            const hoyPeriodo = new Date();
            $('#filtro_anio_rapido').val(String(hoyPeriodo.getFullYear()));
            $('#filtro_mes_rapido').val(String(hoyPeriodo.getMonth() + 1));
            $('#customSearch').val('');
            marcarEstadoVisorAplicado();
            (modoVisor==='pagos'?tablePagos:table).search('').ajax.reload();
        });
        $('#btnPueSinPagoEmpresa').off('click.pueSinPagoFinal').on('click.pueSinPagoFinal', function(){
            const $btn = $(this);
            const activar = $btn.attr('data-activo') !== '1';
            $btn.attr('data-activo', activar ? '1' : '0')
                .toggleClass('btn-info text-dark', activar)
                .toggleClass('btn-outline-info', !activar);
            if (activar) {
                // Este botón es una regla cerrada: siempre INGRESO RECIBIDO + PUE.
                $('#filtro_tipo').val('INGRESO_RECIBIDO');
                $('#filtro_metodo_pago').val('PUE');
            } else {
                $('#contadorPueSinPagoEmpresa').addClass('d-none').text('0');
            }
            (modoVisor==='pagos'?tablePagos:table).ajax.reload();
        });

        $('#filtro_tipo').off('change').on('change', function(){
            if ($('#btnPpdSinComplemento').attr('data-activo') === '1' && $(this).val() !== 'INGRESO_RECIBIDO') {
                $('#btnPpdSinComplemento')
                    .attr('data-activo', '0')
                    .removeClass('btn-warning text-dark')
                    .addClass('btn-outline-warning');
                $('#contadorPpdSinComplemento').addClass('d-none').text('0');
            }
            if ($('#btnPueSinPagoEmpresa').attr('data-activo') === '1' && $(this).val() !== 'INGRESO_RECIBIDO') {
                $('#btnPueSinPagoEmpresa')
                    .attr('data-activo', '0')
                    .removeClass('btn-info text-dark')
                    .addClass('btn-outline-info');
                $('#contadorPueSinPagoEmpresa').addClass('d-none').text('0');
            }
            (modoVisor==='pagos'?tablePagos:table).ajax.reload();
        });
        $('#filtro_metodo_pago').off('change').on('change', function(){
            if ($('#btnPpdSinComplemento').attr('data-activo') === '1' && $(this).val() !== 'PPD') {
                $('#btnPpdSinComplemento')
                    .attr('data-activo', '0')
                    .removeClass('btn-warning text-dark')
                    .addClass('btn-outline-warning');
                $('#contadorPpdSinComplemento').addClass('d-none').text('0');
            }
            if ($('#btnPueSinPagoEmpresa').attr('data-activo') === '1' && $(this).val() !== 'PUE') {
                $('#btnPueSinPagoEmpresa')
                    .attr('data-activo', '0')
                    .removeClass('btn-info text-dark')
                    .addClass('btn-outline-info');
                $('#contadorPueSinPagoEmpresa').addClass('d-none').text('0');
            }
            (modoVisor==='pagos'?tablePagos:table).ajax.reload();
        });
        $('#f_inicio,#f_fin').off('keydown').on('keydown', function(e){ if(e.key==='Enter') $('#btnAplicarFiltros').trigger('click'); });

        var filaContextoActual = null;

        function abrirEdicionDesdeFila(data) {
            if (!data || !data.raw) return;
            const js = btoa(unescape(encodeURIComponent(JSON.stringify(data.raw))));
            verDetalle(js);
        }


        // Un clic sobre la fila = dejarla seleccionada visualmente.
        // No interfiere con botones/controles ni con el doble clic que abre el modal.
        $('#tablaFacturas tbody').off('click.visorSeleccion').on('click.visorSeleccion', 'tr', function (e) {
            if ($(e.target).closest('button,a,input,select,textarea').length) return;

            $('#tablaFacturas tbody tr').removeClass('fila-seleccionada');
            $(this).addClass('fila-seleccionada');
        });

        // Doble clic sobre cualquier parte de la fila (excepto botones/enlaces) = editar.
        $('#tablaFacturas tbody').off('dblclick.visorFactura').on('dblclick.visorFactura', 'tr', function (e) {
            if ($(e.target).closest('button,a,input,select,textarea').length) return;
            const data = table.row(this).data();
            abrirEdicionDesdeFila(data);
        });

        // Clic derecho sobre la fila = menú contextual.
        $('#tablaFacturas tbody').off('contextmenu.visorFactura').on('contextmenu.visorFactura', 'tr', function (e) {
            e.preventDefault();
            const data = table.row(this).data();
            if (!data) return;

            filaContextoActual = data;
            $('#tablaFacturas tbody tr').removeClass('fila-contexto');
            $(this).addClass('fila-contexto');

            const $menu = $('#menuContextualFactura');
            const esGasolina = Number(data.es_gasolina || 0) === 1;
            $menu.find('.ctx-gasolina, .ctx-gasolina-sep').toggle(esGasolina);
            $menu.show().attr('aria-hidden', 'false');

            const ancho = $menu.outerWidth();
            const alto = $menu.outerHeight();
            const margen = 8;
            let x = e.clientX;
            let y = e.clientY;
            if (x + ancho + margen > window.innerWidth) x = Math.max(margen, window.innerWidth - ancho - margen);
            if (y + alto + margen > window.innerHeight) y = Math.max(margen, window.innerHeight - alto - margen);
            $menu.css({ left: x + 'px', top: y + 'px' });
        });

        function cerrarMenuContextualFactura() {
            $('#menuContextualFactura').hide().attr('aria-hidden', 'true');
            $('#tablaFacturas tbody tr').removeClass('fila-contexto');
        }

        $(document).off('mousedown.visorContexto').on('mousedown.visorContexto', function (e) {
            if (!$(e.target).closest('#menuContextualFactura').length) cerrarMenuContextualFactura();
        });
        $(document).off('keydown.visorContexto').on('keydown.visorContexto', function (e) {
            if (e.key === 'Escape') cerrarMenuContextualFactura();
        });
        $(window).off('scroll.visorContexto resize.visorContexto').on('scroll.visorContexto resize.visorContexto', cerrarMenuContextualFactura);

        $('#menuContextualFactura').off('click.visorContexto').on('click.visorContexto', '.ctx-item', function () {
            const accion = $(this).data('accion');
            const d = filaContextoActual;
            cerrarMenuContextualFactura();
            if (!d) return;

            if (accion === 'editar') abrirEdicionDesdeFila(d);
            else if (accion === 'verificar_sat') verificarEstatusSatIndividual(d);
            else if (accion === 'pdf') descargarPDF(d.uuid);
            else if (accion === 'xml') verXML(d.uuid);
            else if (accion === 'gasolina') verGasolina(d.uuid);
            else if (accion === 'pagos') verDetallePagos(d.uuid, d);
            else if (accion === 'descargar_archivos') abrirModalDescargaXmlPdf(d);
        });

        function limpiarParteNombreArchivo(valor) {
            return String(valor == null ? '' : valor)
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[<>:"/\\|?*\x00-\x1F]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function construirFolioDescarga(data) {
            const serie = limpiarParteNombreArchivo(data && data.serie ? data.serie : '');
            const folio = limpiarParteNombreArchivo(data && data.folio ? data.folio : '');
            const serieValida = serie !== '' && serie !== '-';
            const folioValido = folio !== '' && folio !== '-' && folio.toUpperCase() !== 'S/F';
            if (serieValida && folioValido) return serie + '-' + folio;
            if (folioValido) return folio;
            if (serieValida) return serie;
            return '';
        }

        function actualizarNombreDescargaPreview() {
            const folio = limpiarParteNombreArchivo($('#descarga_folio').val());
            const origen = $('input[name="descarga_nombre_origen"]:checked').val() || 'emisor';
            const nombre = limpiarParteNombreArchivo(origen === 'receptor' ? $('#descarga_nombre_receptor').val() : $('#descarga_nombre_emisor').val());
            const uuid = limpiarParteNombreArchivo($('#descarga_uuid').val());
            const folioConPrefijo = folio ? (/^f/i.test(folio) ? folio : ('F-' + folio)) : '';
            let base = [folioConPrefijo, nombre].filter(Boolean).join(' ').trim();
            if (!base) base = uuid || 'CFDI';
            $('#descarga_nombre_preview').text(base + '.xml / ' + base + '.pdf');
            return base;
        }

        function abrirModalDescargaXmlPdf(data) {
            if (!data || !data.uuid) return;
            const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
            const folio = construirFolioDescarga(data);

            $('#descarga_uuid').val(data.uuid || '');
            $('#descarga_uuid_visible').val(data.uuid || '');
            $('#descarga_fecha').val(data.fecha || '-');
            $('#descarga_importe').val(money.format(Number(data.total || 0)));
            $('#descarga_rfc_emisor').val(data.rfc_emisor || '-');
            $('#descarga_emisor').val(data.emisor || '-');
            $('#descarga_rfc_receptor').val(data.rfc_receptor || '-');
            $('#descarga_receptor').val(data.receptor || '-');
            $('#descarga_serie_original').val(data.serie || '');
            $('#descarga_folio_original').val(data.folio || '');
            $('#descarga_nombre_emisor').val(data.emisor || '');
            $('#descarga_nombre_receptor').val(data.receptor || '');
            $('#descarga_folio').val(folio);

            // Requisito: al abrir siempre propone el nombre del emisor.
            $('#descarga_nombre_emisor_opt').prop('checked', true);
            actualizarNombreDescargaPreview();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDescargaXmlPdf')).show();
        }

        $('#descarga_folio').off('input.descargaNombre').on('input.descargaNombre', actualizarNombreDescargaPreview);
        $('input[name="descarga_nombre_origen"]').off('change.descargaNombre').on('change.descargaNombre', actualizarNombreDescargaPreview);

        async function obtenerArchivoFacturaPersonalizado(tipo, uuid, base) {
            const url = 'ajax/archivo_repositorio.php?tipo=' + encodeURIComponent(tipo)
                + '&uuid=' + encodeURIComponent(uuid)
                + '&nombre=' + encodeURIComponent(base);
            const resp = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });

            if (resp.status === 204) {
                throw new Error(tipo === 'pdf'
                    ? 'El PDF todavía no está generado en el repositorio para este CFDI.'
                    : 'El XML no está disponible en el repositorio.');
            }
            if (!resp.ok) {
                const txt = await resp.text();
                throw new Error(txt || ('No fue posible descargar el ' + tipo.toUpperCase() + '.'));
            }

            const blob = await resp.blob();
            if (!blob || blob.size === 0) throw new Error('El archivo ' + tipo.toUpperCase() + ' recibido está vacío.');
            return blob;
        }

        function lanzarDescargaBlob(blob, nombreArchivo) {
            const enlace = document.createElement('a');
            const objectUrl = URL.createObjectURL(blob);
            enlace.href = objectUrl;
            enlace.download = nombreArchivo;
            enlace.style.display = 'none';
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 3000);
        }

        async function descargarXmlYPdfPersonalizado() {
            const uuid = String($('#descarga_uuid').val() || '').trim();
            if (!uuid) return;

            const base = actualizarNombreDescargaPreview();
            const $btn = $('#btnDescargaXmlPdfPersonalizado');
            const htmlOriginal = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Descargando...');

            const tipos = ['xml', 'pdf'];
            const archivos = [];
            const errores = [];

            try {
                // Primero obtenemos ambos archivos. Después disparamos las dos descargas juntas
                // para evitar que un error en uno impida descargar el otro.
                for (const tipo of tipos) {
                    try {
                        const blob = await obtenerArchivoFacturaPersonalizado(tipo, uuid, base);
                        archivos.push({ tipo, blob });
                    } catch (err) {
                        errores.push({ tipo, mensaje: err && err.message ? err.message : 'Archivo no disponible.' });
                    }
                }

                // Separar las descargas para que Edge/Chrome no las interprete
                // como dos descargas simultáneas disparadas por el mismo clic.
                // La primera sale inmediatamente y la segunda 1.2 segundos después.
                archivos.forEach((archivo, idx) => {
                    setTimeout(() => lanzarDescargaBlob(archivo.blob, base + '.' + archivo.tipo), idx * 1200);
                });

                if (errores.length) {
                    Swal.fire({
                        icon: archivos.length ? 'warning' : 'error',
                        title: archivos.length ? 'Descarga parcial' : 'No se pudieron descargar los archivos',
                        html: errores.map(e => '<b>' + e.tipo.toUpperCase() + ':</b> ' + $('<div>').text(e.mensaje).html()).join('<br>'),
                        background: '#161b22',
                        color: '#fff',
                        confirmButtonText: 'Aceptar'
                    });
                }
            } finally {
                $btn.prop('disabled', false).html(htmlOriginal);
            }
        }

        var detallePagosUuidActual = null;

        async function verDetallePagos(uuid, factura) {
            detallePagosUuidActual = uuid;
            const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
            const esc = v => $('<div>').text(v == null ? '' : String(v)).html();
            $('#detallePagosUuid').text(uuid || '');
            $('#detallePagosEmisor').text(factura && factura.emisor ? factura.emisor : '-');
            $('#detallePagosDatosFactura').text(
                'RFC: ' + (factura && factura.rfc_emisor ? factura.rfc_emisor : '-') +
                ' | Factura: ' + ((factura && factura.serie && factura.serie !== '-') ? factura.serie + '-' : '') + (factura && factura.folio ? factura.folio : '-') +
                ' | Fecha: ' + (factura && factura.fecha ? factura.fecha : '-')
            );
            const pintarEstatusSat = function(estatus) {
                let estado = String(estatus ?? '').trim().toUpperCase();
                if (estado === '' || estado === '1' || estado === 'TRUE') estado = 'VIGENTE';
                if (estado === '0' || estado === 'FALSE') estado = 'CANCELADO';
                const cancelada = estado === 'CANCELADO' || estado === 'CANCELADA';
                const vigente = estado === 'VIGENTE';
                $('#detallePagosEstatusSat')
                    .removeClass('bg-success bg-danger bg-secondary')
                    .addClass(cancelada ? 'bg-danger' : (vigente ? 'bg-success' : 'bg-secondary'))
                    .text(cancelada ? 'CANCELADA' : (vigente ? 'VIGENTE' : estado));
            };
            pintarEstatusSat(factura && factura.estatus_sat ? factura.estatus_sat : null);
            const pintarConcFin=function(est){ est=String(est||'PENDIENTE').toUpperCase(); const cls=est==='CONCILIADO'?'bg-success':(est==='PARCIAL'?'bg-warning text-dark':(est==='DIFERENCIA'||est.indexOf('SIN_')===0?'bg-danger':'bg-secondary')); $('#detallePagosConciliacionFin').removeClass('bg-success bg-warning text-dark bg-danger bg-secondary').addClass(cls).text('CONCILIACIÓN '+est); };
            pintarConcFin(factura && factura.estatus_conciliacion_financiera ? factura.estatus_conciliacion_financiera : 'PENDIENTE');
            $('#detallePagosBody').html('<tr><td colspan="20" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando...</td></tr>');
            $('#detallePagosVacio').addClass('d-none');
            $('#detallePagosFinVacio').addClass('d-none');
            $('#detallePagosFinBody').html('<tr><td colspan="12" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando conciliación financiera...</td></tr>');
            $('#detallePagosContpaqVacio').addClass('d-none');
            $('#detallePagosContpaqBody').html('<tr><td colspan="16" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando conciliación...</td></tr>');
            $('#dpTotalFactura').text(money.format(Number(factura && factura.total ? factura.total : 0)));
            $('#dpTotalAplicado').text(money.format(0));
            $('#dpExcluidoDiot').addClass('d-none').text('');
            $('#dpSaldo').text(money.format(Number(factura && factura.total ? factura.total : 0)));
            $('#dpMovimientos').text('0');
            $('#dpTotalContpaq').text(money.format(0));
            $('#dpDiferenciaContpaq').text(money.format(0));
            $('#dpMovimientosContpaq').text('0');
            $('#modalDetallePagos').modal('show');

            try {
                const res = await fetch('ajax/listar_detalles_pago_factura.php?uuid=' + encodeURIComponent(uuid), { cache: 'no-store' });
                const json = await res.json();
                if (!res.ok || json.status !== 'ok') throw new Error(json.msg || 'No fue posible consultar los pagos');

                const filas = Array.isArray(json.detalles) ? json.detalles : [];
                if (json.factura) {
                    $('#detallePagosEmisor').text(json.factura.emisor || '-');
                    $('#detallePagosDatosFactura').text(
                        'RFC: ' + (json.factura.rfc_emisor || '-') +
                        ' | Factura: ' + ((json.factura.serie && json.factura.serie !== '-') ? json.factura.serie + '-' : '') + (json.factura.folio || 'S/F') +
                        ' | Fecha: ' + (json.factura.fecha_emision || '-')
                    );
                    pintarEstatusSat(json.factura.estatus_sat || null);
                }
                $('#dpTotalFactura').text(money.format(Number(json.resumen.total_factura || 0)));
                $('#dpTotalAplicado').text(money.format(Number(json.resumen.total_aplicado || 0)));
                const totalExcluidoDiot = Number(json.resumen.total_excluido_diot || 0);
                const movExcluidosDiot = Number(json.resumen.movimientos_excluidos_diot || 0);
                if (movExcluidosDiot > 0 || Math.abs(totalExcluidoDiot) >= 0.005) {
                    $('#dpExcluidoDiot').removeClass('d-none').html('<i class="bi bi-exclamation-triangle-fill"></i> NO DIOT: ' + money.format(totalExcluidoDiot));
                } else {
                    $('#dpExcluidoDiot').addClass('d-none').text('');
                }
                $('#dpSaldo').text(money.format(Number(json.resumen.saldo || 0)));
                $('#dpMovimientos').text(String(json.resumen.movimientos || 0));
                $('#dpTotalContpaq').text(money.format(Number(json.resumen.total_contpaq || 0)));
                $('#dpDiferenciaContpaq').text(money.format(Number(json.resumen.diferencia_contpaq || 0)));
                $('#dpMovimientosContpaq').text(String(json.resumen.movimientos_contpaq || 0));
                pintarConcFin(json.resumen.estatus_conciliacion_financiera || 'PENDIENTE');

                const appsFin=[];
                filas.forEach((r,indiceDetalle)=>{
                    const lista=Array.isArray(r.aplicaciones_financieras)?r.aplicaciones_financieras:[];
                    lista.forEach(a=>appsFin.push({...a,numero_detalle_fiscal:indiceDetalle+1,detalle_excluido_diot:Number(r.excluido_diot||0)}));
                });
                (Array.isArray(json.relaciones_financieras_sin_detalle)?json.relaciones_financieras_sin_detalle:[]).forEach(a=>appsFin.push({...a,numero_detalle_fiscal:0}));
                const badgeFin=e=>{e=String(e||'PENDIENTE').toUpperCase();const c=e==='CONCILIADO'?'bg-success':(e==='PARCIAL'?'bg-warning text-dark':(e==='DIFERENCIA'||e.indexOf('SIN_')===0?'bg-danger':'bg-secondary'));return `<span class="badge ${c}">${esc(e)}</span>`;};
                if(!appsFin.length){
                    $('#detallePagosFinBody').empty();$('#detallePagosFinVacio').removeClass('d-none');
                }else{
                    $('#detallePagosFinVacio').addClass('d-none');
                    const htmlFin=appsFin.map(a=>`<tr class="${Number(a.detalle_excluido_diot||0)===1?'table-warning text-dark':''}">
                        <td class="text-center fw-bold">${Number(a.numero_detalle_fiscal||0)>0?'#'+Number(a.numero_detalle_fiscal):'-'}</td>
                        <td><span class="badge bg-info text-dark">${esc(a.origen_pago||'-')}</span>${Number(a.detalle_excluido_diot||0)===1?' <span class="badge bg-warning text-dark"><i class="bi bi-flag-fill"></i> NO DIOT</span>':''}</td>
                        <td>${esc(a.documento_padre||a.referencia_documento||'-')}</td>
                        <td>${esc(a.folio_pago||'-')}</td>
                        <td>${esc(a.fecha_pago||'-')}</td>
                        <td class="text-end">${money.format(Number(a.importe_documento||0))}</td>
                        <td class="text-end fw-bold text-success">${money.format(Number(a.importe_aplicado||0))}</td>
                        <td class="text-center">${Number(a.conciliado_banco||0)===1?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>'}</td>
                        <td>${esc(a.fecha_conciliacion_banco||'-')}</td><td>${esc(a.fecha_valor||'-')}</td>
                        <td>${esc(a.regla_cruce||'-')}</td><td>${badgeFin(a.estatus_conciliacion)}</td></tr>`).join('');
                    $('#detallePagosFinBody').html(htmlFin);
                }

                if (!filas.length) {
                    $('#detallePagosBody').empty();
                    $('#detallePagosVacio').removeClass('d-none');
                    $('#detallePagosContpaqBody').empty();
                    $('#detallePagosContpaqVacio').removeClass('d-none').html('<i class="bi bi-info-circle"></i> No existen detalles SAT para relacionar con movimientos conciliados.');
                    return;
                }

                const html = filas.map((r, i) => `
                    <tr class="${Number(r.excluido_diot||0)===1?'table-warning text-dark':''}">
                        <td>${i + 1}</td>
                        <td><span class="badge bg-secondary">${esc(r.origen_pago || '-')}</span>${Number(r.excluido_diot||0)===1?' <span class="badge bg-warning text-dark ms-1"><i class="bi bi-flag-fill"></i> NO DIOT</span>':''}</td>
                        <td class="text-center">${esc(r.parcialidad || '-')}</td>
                        <td>${esc(r.fecha_pago_sat || '-')}</td>
                        <td>${esc(r.fecha_pago_banco || '-')}</td>
                        <td>${esc(r.fecha_pago_usuario || '-')}</td>
                        <td class="fw-bold text-warning">${esc(r.fecha_aplicacion_fiscal || '-')}</td>
                        <td class="text-end">${money.format(Number(r.importe_aplicado || 0))}</td>
                        <td class="text-end">${money.format(Number(r.base_iva_16 || 0))}</td>
                        <td class="text-end">${money.format(Number(r.iva_16 || 0))}</td>
                        <td class="text-end">${money.format(Number(r.base_iva_8 || 0))}</td>
                        <td class="text-end">${money.format(Number(r.iva_8 || 0))}</td>
                        <td class="text-end">${money.format(Number(r.base_tasa_0 || 0))}</td>
                        <td class="text-end">${money.format(Number(r.base_exento || 0))}</td>
                        <td class="text-end">${money.format(Number(r.iva_retenido || 0))}</td>
                        <td class="text-end">${money.format(Number(r.isr_retenido || 0))}</td>
                        <td>${esc(r.forma_pago || '-')}</td>
                        <td>${esc(r.referencia || '-')}</td>
                        <td title="${esc(r.uuid_pago || '')}">${esc(r.uuid_pago || '-')}</td>
                        <td class="text-center">${r.estatus_complemento==='SUSTITUIDO'?'<span class="badge bg-danger">SUSTITUIDO</span>':(r.estatus_complemento==='SUSTITUTO'?'<span class="badge bg-info text-dark">SUSTITUTO</span>':'<span class="badge bg-success">VIGENTE</span>')}</td>
                        <td title="${esc(r.uuid_sustituto || '')}">${esc(r.uuid_sustituto || '-')}</td>
                        <td class="text-center">${Number(r.cuenta_saldo||0)===1?'<span class="badge bg-success">SÍ</span>':'<span class="badge bg-secondary">NO</span>'}</td>
                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 btn-editar-fecha-detalle" data-id="${Number(r.id_detalle || 0)}" data-fecha="${esc(r.fecha_pago_usuario || '')}" title="Editar fecha efectiva de esta parcialidad"><i class="bi bi-calendar2-check"></i></button></td>
                    </tr>`).join('');
                $('#detallePagosBody').html(html);

                const aplicaciones = [];
                filas.forEach((r, indiceDetalle) => {
                    const lista = Array.isArray(r.aplicaciones_contpaq) ? r.aplicaciones_contpaq : [];
                    lista.forEach(a => aplicaciones.push({ ...a, numero_detalle_sat: indiceDetalle + 1 }));
                });
                if (!aplicaciones.length) {
                    $('#detallePagosContpaqBody').empty();
                    $('#detallePagosContpaqVacio').removeClass('d-none');
                } else {
                    $('#detallePagosContpaqVacio').addClass('d-none');
                    const tipoTexto = a => a.tipo_origen === 'C' ? 'CHEQUE' : ((a.tipo_documento || '').toUpperCase().includes('TRANSFER') ? 'TRANSFERENCIA' : 'EGRESO');
                    const badgeEstatus = a => {
                        if (Number(a.es_cancelado || 0) === 1) return '<span class="badge bg-danger">CANCELADO</span>';
                        const estado = String(a.estatus || 'CONCILIADO').toUpperCase();
                        const clase = estado === 'CONCILIADO' ? 'bg-success' : (estado === 'PARCIAL' ? 'bg-warning text-dark' : 'bg-secondary');
                        return `<span class="badge ${clase}">${esc(estado)}</span>`;
                    };
                    const htmlContpaq = aplicaciones.map(a => `
                        <tr class="${Number(a.es_cancelado || 0) === 1 ? 'table-danger' : ''}">
                            <td class="text-center fw-bold">#${Number(a.numero_detalle_sat || 0)}</td>
                            <td><span class="badge bg-info text-dark">${esc(tipoTexto(a))}</span></td>
                            <td>${esc(a.folio || '-')}</td>
                            <td class="fw-bold text-info">${esc(a.fecha_pago_contpaq || a.fecha_movimiento || '-')}</td>
                            <td title="${esc(a.rfc_beneficiario || '')}">${esc(a.beneficiario || '-')}</td>
                            <td>${esc(a.moneda_contpaq || a.moneda_movimiento || 'MXN')}</td>
                            <td class="text-end">${Number(a.tc_contpaq || a.tc_movimiento || 0).toLocaleString('es-MX', {minimumFractionDigits: 4, maximumFractionDigits: 8})}</td>
                            <td class="text-end">${money.format(Number(a.importe_contpaq || a.total_movimiento || 0))}</td>
                            <td class="text-end fw-bold text-success">${money.format(Number(a.importe_conciliado || 0))}</td>
                            <td class="text-end ${Math.abs(Number(a.diferencia_importe || 0)) >= 0.01 ? 'text-warning fw-bold' : ''}">${money.format(Number(a.diferencia_importe || 0))}</td>
                            <td class="text-center ${Math.abs(Number(a.diferencia_dias || 0)) > 0 ? 'text-warning fw-bold' : ''}">${a.diferencia_dias == null ? '-' : Number(a.diferencia_dias)}</td>
                            <td>${esc(a.num_pol || a.id_poliza || '-')}</td>
                            <td title="${esc(a.concepto_contpaq || '')}">${esc(a.referencia_contpaq || '-')}</td>
                            <td title="${esc(a.uuid_factura_contpaq || '')}">${esc(a.uuid_factura_contpaq || '-')}</td>
                            <td title="${esc(a.uuid_rep_contpaq || a.uuid_rep || '')}">${esc(a.uuid_rep_contpaq || a.uuid_rep || '-')}</td>
                            <td>${badgeEstatus(a)}</td>
                        </tr>`).join('');
                    $('#detallePagosContpaqBody').html(htmlContpaq);
                }
            } catch (err) {
                $('#detallePagosBody').html(`<tr><td colspan="20" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle me-2"></i>${esc(err.message)}</td></tr>`);
                $('#detallePagosFinBody').html(`<tr><td colspan="12" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle me-2"></i>${esc(err.message)}</td></tr>`);
                $('#detallePagosContpaqBody').html(`<tr><td colspan="16" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle me-2"></i>${esc(err.message)}</td></tr>`);
            }
        }

        // Mantiene perfectamente alineados encabezado, cuerpo y columnas congeladas.
        // DataTables usa tablas separadas para scrollHead/scrollBody; copiamos el ancho
        // real del cuerpo al encabezado antes de recalcular FixedColumns.
        function sincronizarAnchosEncabezadoCuerpo(dt) {
            const $wrapper = $('#tablaFacturas_wrapper');
            const $fila = $wrapper.find('.dataTables_scrollBody #tablaFacturas tbody tr:visible').first();
            if (!$fila.length || $fila.find('.dataTables_empty').length || $fila.children('td').length <= 1) return;

            const anchos = [];
            $fila.children('td').each(function (i) {
                anchos[i] = Math.round(this.getBoundingClientRect().width * 100) / 100;
            });

            if (!anchos.length || anchos.length <= 1) return;

            $wrapper.find('.dataTables_scrollHead table, .dataTables_scrollBody table, .dataTables_scrollFoot table')
                .each(function () {
                    const $tabla = $(this);
                    anchos.forEach(function (ancho, indice) {
                        if (!ancho || ancho <= 0) return;
                        $tabla.find('colgroup col').eq(indice).css({
                            width: ancho + 'px',
                            minWidth: ancho + 'px',
                            maxWidth: ancho + 'px'
                        });
                        $tabla.find('thead th').eq(indice).css({
                            width: ancho + 'px',
                            minWidth: ancho + 'px',
                            maxWidth: ancho + 'px'
                        });
                        $tabla.find('tfoot th').eq(indice).css({
                            width: ancho + 'px',
                            minWidth: ancho + 'px',
                            maxWidth: ancho + 'px'
                        });
                    });
                });
        }

        function inicializarRedimensionColumnas(dt) {
            const $wrapper = $('#tablaFacturas_wrapper');
            const selectorEncabezados = '.dataTables_scrollHead thead th';

            $wrapper.find(selectorEncabezados).each(function (indice) {
                const $th = $(this);
                if ($th.find('.dt-column-resizer').length) return;

                const $resizer = $('<span class="dt-column-resizer" aria-hidden="true"></span>');
                $th.append($resizer);

                $resizer.on('mousedown', function (evento) {
                    evento.preventDefault();
                    evento.stopPropagation();

                    const xInicial = evento.pageX;
                    const anchoInicial = $th.outerWidth();
                    const anchoMinimo = indice === 0 ? 45 : 65;
                    $('body').addClass('dt-redimensionando-columna');

                    function aplicarAncho(nuevoAncho) {
                        nuevoAncho = Math.max(anchoMinimo, Math.round(nuevoAncho));

                        const settings = dt.settings()[0];
                        const columna = settings && settings.aoColumns ? settings.aoColumns[indice] : null;
                        if (columna) {
                            columna.sWidth = nuevoAncho + 'px';
                            columna.sWidthOrig = nuevoAncho + 'px';
                        }

                        // Aplicar exactamente el mismo ancho a encabezado, cuerpo, pie y colgroups.
                        $wrapper.find('.dataTables_scrollHead table, .dataTables_scrollBody table, .dataTables_scrollFoot table')
                            .each(function () {
                                const $tabla = $(this);
                                $tabla.find('colgroup col').eq(indice).css({
                                    width: nuevoAncho + 'px',
                                    minWidth: nuevoAncho + 'px',
                                    maxWidth: nuevoAncho + 'px'
                                });
                                $tabla.find('thead th').eq(indice).css({
                                    width: nuevoAncho + 'px',
                                    minWidth: nuevoAncho + 'px',
                                    maxWidth: nuevoAncho + 'px'
                                });
                                $tabla.find('tbody tr').each(function () {
                                    $(this).children('td').eq(indice).css({
                                        width: nuevoAncho + 'px',
                                        minWidth: nuevoAncho + 'px',
                                        maxWidth: nuevoAncho + 'px'
                                    });
                                });
                            });

                        // No ejecutar columns.adjust durante el arrastre: redistribuye anchos y descuadra el freezer.
                        if (dt.fixedColumns && dt.fixedColumns().relayout) {
                            dt.fixedColumns().relayout();
                        }
                    }

                    $(document).on('mousemove.redimensionColumna', function (movimiento) {
                        aplicarAncho(anchoInicial + (movimiento.pageX - xInicial));
                    });

                    $(document).one('mouseup.redimensionColumna', function () {
                        $(document).off('mousemove.redimensionColumna');
                        $('body').removeClass('dt-redimensionando-columna');
                        window.requestAnimationFrame(function () {
                            if (dt.fixedColumns && dt.fixedColumns().relayout) dt.fixedColumns().relayout();
                        });
                    });
                });
            });
        }


        // Decide UNA sola ruta de consulta según los filtros actualmente activos.
        // Regla tomada de la lógica que ya usamos en FiveWin:
        //   1) Buscar por + valor tiene prioridad y conserva su índice específico.
        //   2) Si no hay búsqueda específica pero sí Clasificación/Método/u otro filtro,
        //      se usa la ruta normal hasta asignarle su índice rápido correspondiente.
        //   3) Solo cuando TODO está en TODOS/vacío usamos empresa + fecha.
        // Cambiar mes o rango NUNCA borra el filtro activo: únicamente cambia las fechas.
        function activarModoFiltroSegunEstado() {
            const tipoBusqueda = ($('#tipoBusquedaFactura').val() || '').trim();
            const busqueda = ($('#customSearch').val() || '').trim();
            const clasificacion = ($('#filtro_tipo').val() || '').trim();
            const metodo = ($('#filtro_metodo_pago').val() || '').trim();
            const ppdSinComplemento = $('#btnPpdSinComplemento').attr('data-activo') === '1';
            const pueSinPago = $('#btnPueSinPagoEmpresa').attr('data-activo') === '1';

            // Apagar primero todos los modos rápidos para que nunca se mezclen.
            pruebaMesSoloEmpresaFecha = false;
            pruebaMesInicioMs = 0;
            pruebaEmisorSoloEmpresaFecha = false;
            pruebaEmisorInicioMs = 0;
            pruebaReceptorSoloEmpresaFecha = false;
            pruebaReceptorInicioMs = 0;

            // PRIORIDAD 1: si hay clasificación, método o filtros especiales, SIEMPRE usar
            // la consulta normal completa. Las rutas rápidas de emisor/receptor solo filtran
            // empresa + catálogo + fecha y, si se mezclan con clasificación, pueden mostrar
            // filas que NO cumplen el filtro visual (y luego Verificar Status correctamente
            // encuentra cero). Esto era la causa del desfase visto entre la tabla y SAT.
            if (clasificacion !== '' || metodo !== '' || ppdSinComplemento || pueSinPago) {
                return 'filtros_normales';
            }

            // PRIORIDAD 2: búsqueda específica cuando NO existen otros filtros.
            if (tipoBusqueda !== '' && busqueda !== '') {
                // Tercer índice rápido: Nombre/RFC emisor + rango.
                if (tipoBusqueda === 'nombre_emisor' || tipoBusqueda === 'rfc_emisor') {
                    pruebaEmisorSoloEmpresaFecha = true;
                    pruebaEmisorInicioMs = performance.now();
                    return 'emisor';
                }
                // Cuarto índice rápido: Nombre/RFC receptor + rango.
                if (tipoBusqueda === 'nombre_receptor' || tipoBusqueda === 'rfc_receptor') {
                    pruebaReceptorSoloEmpresaFecha = true;
                    pruebaReceptorInicioMs = performance.now();
                    return 'receptor';
                }

                // UUID ya usa acceso directo por PK/índice (id_empresa + uuid) en el backend.
                return 'busqueda_especifica';
            }

            // PRIORIDAD 3: TODO limpio => empresa + rango de fechas.
            pruebaMesSoloEmpresaFecha = true;
            pruebaMesInicioMs = performance.now();
            return 'empresa_fecha';
        }

        function validarYAplicarFiltros() {
            const inicio = $('#f_inicio').val();
            const fin = $('#f_fin').val();

            if (inicio && fin && inicio > fin) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Rango de fechas incorrecto',
                    text: 'La fecha inicial no puede ser mayor que la fecha final.',
                    background: '#161b22',
                    color: '#fff'
                });
                return;
            }

            activarModoFiltroSegunEstado();
            table.search('');
            table.page('first').ajax.reload();
        }

        function verXML(uuid) {
            window.open('ajax/visor_xml.php?uuid=' + encodeURIComponent(uuid) + '&_=' + Date.now(), '_blank');
        }

        function descargarPDF(uuid) { window.open('ajax/generar_pdf.php?uuid=' + encodeURIComponent(uuid), '_blank'); }

        function nombreArchivoSeguro(valor) {
            return String(valor || '')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[<>:"/\\|?*\x00-\x1F]/g, '_')
                .replace(/\s+/g, ' ')
                .trim()
                .substring(0, 140) || 'SIN_NOMBRE';
        }

        function fechaHoraCarpeta() {
            const d = new Date();
            const p = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}${p(d.getMonth()+1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
        }

        async function escribirBlob(carpeta, nombre, blob) {
            const archivo = await carpeta.getFileHandle(nombre, { create: true });
            const writable = await archivo.createWritable();
            await writable.write(blob);
            await writable.close();
        }

        async function esperarCargaIframe(iframe, timeoutMs = 25000) {
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('Tiempo agotado al preparar el comprobante')), timeoutMs);
                iframe.onload = () => {
                    clearTimeout(timer);
                    setTimeout(resolve, 700);
                };
                iframe.onerror = () => {
                    clearTimeout(timer);
                    reject(new Error('No fue posible abrir el comprobante'));
                };
            });
        }

        async function exportarXmlPdfFiltrados() {
            if (!window.showDirectoryPicker) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Selección de carpeta no disponible',
                    html: 'Esta función requiere Microsoft Edge o Google Chrome y abrir el sistema desde <b>localhost</b> o mediante <b>HTTPS</b>.',
                    confirmButtonText: 'Entendido'
                });
                return;
            }

            const registros = await obtenerRegistrosFiltradosExportacion();
            if (!registros.length) {
                Swal.fire({ icon: 'info', title: 'No hay CFDI para exportar', text: 'Aplique otros filtros y vuelva a intentar.' });
                return;
            }

            const respuesta = await Swal.fire({
                icon: 'question',
                title: 'Elegir archivos a exportar',
                html: `
                    <div class="text-start mx-auto" style="max-width:390px">
                        <p class="mb-3">Se procesarán <b>${registros.length}</b> CFDI según los filtros actuales.</p>
                        <div class="form-check border rounded p-3 ps-5 mb-2 bg-light">
                            <input class="form-check-input" type="checkbox" id="expOpcionXml" checked>
                            <label class="form-check-label fw-bold" for="expOpcionXml">
                                <i class="bi bi-filetype-xml text-warning"></i> Exportar XML
                            </label>
                            <div class="small text-muted">Se copia directamente desde el repositorio físico.</div>
                        </div>
                        <div class="form-check border rounded p-3 ps-5 bg-light">
                            <input class="form-check-input" type="checkbox" id="expOpcionPdf">
                            <label class="form-check-label fw-bold" for="expOpcionPdf">
                                <i class="bi bi-file-earmark-pdf text-danger"></i> Exportar PDF
                            </label>
                            <div class="small text-muted">Se regeneran desde el XML con el mismo formato de la descarga individual.</div>
                        </div>
                    </div>`,
                showCancelButton: true,
                confirmButtonText: 'Seleccionar carpeta',
                cancelButtonText: 'Cancelar',
                focusConfirm: false,
                preConfirm: () => {
                    const exportarXml = document.getElementById('expOpcionXml').checked;
                    const exportarPdf = document.getElementById('expOpcionPdf').checked;
                    if (!exportarXml && !exportarPdf) {
                        Swal.showValidationMessage('Seleccione XML, PDF o ambos.');
                        return false;
                    }
                    return { exportarXml, exportarPdf };
                }
            });
            if (!respuesta.isConfirmed) return;

            const { exportarXml, exportarPdf } = respuesta.value;

            let carpetaBase;
            try {
                carpetaBase = await window.showDirectoryPicker({ mode: 'readwrite', startIn: 'downloads' });
            } catch (e) {
                if (e && e.name !== 'AbortError') {
                    Swal.fire({ icon: 'error', title: 'No se pudo abrir la carpeta', text: e.message || String(e) });
                }
                return;
            }

            // XML y PDF se guardan juntos dentro de la misma carpeta de exportación.
            const carpetaExportacion = await carpetaBase.getDirectoryHandle('CFDI_' + fechaHoraCarpeta(), { create: true });

            let exportacionCancelada = false;
            let controladorActual = null;

            Swal.fire({
                title: 'Exportando comprobantes',
                html: `
                    <div id="expTexto">Preparando...</div>
                    <div id="expDetalle" class="small text-muted mt-1"></div>
                    <div class="progress mt-3" style="height:18px">
                        <div id="expBarra" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
                    </div>`,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: '<i class="bi bi-x-circle"></i> Cancelar exportación',
                didOpen: () => {
                    Swal.showLoading();
                    const botonCancelar = Swal.getCancelButton();
                    if (botonCancelar) {
                        botonCancelar.addEventListener('click', () => {
                            exportacionCancelada = true;
                            if (controladorActual) controladorActual.abort();
                        }, { once: true });
                    }
                }
            });

            let xmlCorrectos = 0;
            let pdfCorrectos = 0;
            let pdfOmitidos = 0;
            let comprobantesCompletos = 0;
            const errores = [];

            for (let i = 0; i < registros.length; i++) {
                if (exportacionCancelada) break;

                const r = registros[i];
                const base = nombreArchivoSeguro(`${r.fecha || ''}_${r.tipo || ''}_${r.folio || 'SF'}_${r.uuid}`);
                const porcentaje = Math.round(((i + 1) / registros.length) * 100);
                const texto = document.getElementById('expTexto');
                const detalle = document.getElementById('expDetalle');
                const barra = document.getElementById('expBarra');

                if (texto) texto.textContent = `Procesando ${i + 1} de ${registros.length}: ${r.uuid}`;
                if (detalle) {
                    const partes = [];
                    if (exportarXml) partes.push(`XML: ${xmlCorrectos}`);
                    if (exportarPdf) partes.push(`PDF: ${pdfCorrectos} | omitidos: ${pdfOmitidos}`);
                    detalle.textContent = partes.join('  |  ');
                }
                if (barra) {
                    barra.style.width = porcentaje + '%';
                    barra.textContent = porcentaje + '%';
                }

                let registroCorrecto = true;

                if (exportarXml && !exportacionCancelada) {
                    try {
                        controladorActual = new AbortController();
                        const xmlResp = await fetch(
                            'ajax/archivo_repositorio.php?tipo=xml&uuid=' + encodeURIComponent(r.uuid),
                            { credentials: 'same-origin', signal: controladorActual.signal }
                        );
                        controladorActual = null;
                        if (!xmlResp.ok) throw new Error('XML: ' + await xmlResp.text());
                        await escribirBlob(carpetaExportacion, base + '.xml', await xmlResp.blob());
                        xmlCorrectos++;
                    } catch (e) {
                        controladorActual = null;
                        if (e && e.name === 'AbortError' && exportacionCancelada) break;
                        registroCorrecto = false;
                        errores.push(`${r.uuid} [XML]: ${e.message || e}`);
                    }
                }

                if (exportarPdf && !exportacionCancelada) {
                    try {
                        controladorActual = new AbortController();
                        const pdfResp = await fetch(
                            'ajax/archivo_repositorio.php?tipo=pdf&uuid=' + encodeURIComponent(r.uuid) + '&nombre=' + encodeURIComponent(base),
                            { credentials: 'same-origin', signal: controladorActual.signal }
                        );
                        controladorActual = null;

                        // El PDF se regenera desde el XML respaldado, igual que en la descarga individual.
                        if (!pdfResp.ok) throw new Error('PDF: ' + await pdfResp.text());
                        await escribirBlob(carpetaExportacion, base + '.pdf', await pdfResp.blob());
                        pdfCorrectos++;
                    } catch (e) {
                        controladorActual = null;
                        if (e && e.name === 'AbortError' && exportacionCancelada) break;
                        registroCorrecto = false;
                        errores.push(`${r.uuid} [PDF]: ${e.message || e}`);
                    }
                }

                if (registroCorrecto) comprobantesCompletos++;
            }

            if (errores.length) {
                const log = new Blob([errores.join('\r\n')], { type: 'text/plain;charset=utf-8' });
                await escribirBlob(carpetaExportacion, 'errores_exportacion.txt', log);
            }

            const resumen = [];
            if (exportarXml) resumen.push(`<b>${xmlCorrectos}</b> XML guardados`);
            if (exportarPdf) resumen.push(`<b>${pdfCorrectos}</b> PDF regenerados desde XML`);
            

            if (exportacionCancelada) {
                Swal.fire({
                    icon: 'info',
                    title: 'Exportación cancelada',
                    html: `${resumen.join('<br>')}<br><small>Los archivos ya creados se conservaron en la carpeta seleccionada.</small>`,
                    confirmButtonText: 'Aceptar'
                });
                return;
            }

            const detalleError = errores.length
                ? `<br><b>${errores.length}</b> errores; se generó errores_exportacion.txt.`
                : '';

            Swal.fire({
                icon: errores.length ? 'warning' : 'success',
                title: 'Exportación terminada',
                html: `${resumen.join('<br>')}${detalleError}`,
                confirmButtonText: 'Aceptar'
            });
        }


        async function solicitarTipoExportacionCsv() {
            const hoy = new Date();

            // Para la DIOT sugerimos automáticamente el período tomando
            // el mes y año de la FECHA INICIAL del filtro actual.
            // Ejemplo: 01/07/2026 => JULIO 2026.
            const fechaInicioFiltro = String($('#f_inicio').val() || '').trim();
            let anioActual = hoy.getFullYear();
            let mesActual = hoy.getMonth() + 1;

            if (fechaInicioFiltro) {
                let m = fechaInicioFiltro.match(/^(\d{4})-(\d{2})-(\d{2})$/); // yyyy-mm-dd
                if (m) {
                    anioActual = Number(m[1]);
                    mesActual = Number(m[2]);
                } else {
                    m = fechaInicioFiltro.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); // dd/mm/yyyy
                    if (m) {
                        anioActual = Number(m[3]);
                        mesActual = Number(m[2]);
                    }
                }
            }

            const nombresMes = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];

            const anios = [];
            for (let a = hoy.getFullYear() + 1; a >= hoy.getFullYear() - 8; a--) {
                anios.push(`<option value="${a}" ${a === anioActual ? 'selected' : ''}>${a}</option>`);
            }
            const meses = nombresMes.map((m, i) => `<option value="${i + 1}" ${(i + 1) === mesActual ? 'selected' : ''}>${m}</option>`).join('');

            const resultado = await Swal.fire({
                title: '<i class="bi bi-filetype-csv me-2"></i>Exportar CSV',
                width: 690,
                html: `
                    <div class="text-start px-2">
                        <div class="small text-muted mb-3">Seleccione qué información desea exportar.</div>

                        <label class="d-block border rounded-3 p-3 mb-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="completa" checked>
                                <div>
                                    <div class="fw-bold text-primary"><i class="bi bi-list-check me-1"></i> Exportación completa</div>
                                    <div class="small text-muted">Exporta todo el listado que corresponda a los filtros y al rango de fechas actualmente seleccionados en el visor.</div>
                                </div>
                            </div>
                        </label>

                        <label class="d-block border rounded-3 p-3 mb-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="diot">
                                <div class="flex-grow-1">
                                    <div class="fw-bold text-success"><i class="bi bi-calendar2-check me-1"></i> Exportación para DIOT</div>
                                    <div class="small text-muted">Exporta EGRESOS cuya <b>Fecha fiscal</b> pertenezca al mes y año indicados.</div>
                                    <div id="periodoExportEspecial" class="row g-2 mt-2 d-none">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Año</label>
                                            <select id="exportDiotAnio" class="form-select form-select-sm">${anios.join('')}</select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Mes</label>
                                            <select id="exportDiotMes" class="form-select form-select-sm">${meses}</select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label class="d-block border rounded-3 p-3 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="chequeo_diot">
                                <div>
                                    <div class="fw-bold text-warning"><i class="bi bi-search me-1"></i> Exportación chequeo DIOT</div>
                                    <div class="small text-muted">Exporta <b>todos los EGRESOS emitidos en el mes/año indicado</b>, sin importar si su Fecha fiscal quedó dentro, fuera o vacía.</div>
                                </div>
                            </div>
                        </label>

                        ${modoVisor !== 'pagos' ? `
                        <?php if (in_array('N', $tiposPermitidos, true) && $puedeExportar): ?>
                        <label class="d-block border rounded-3 p-3 mt-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="detalle_nomina_gk">
                                <div>
                                    <div class="fw-bold text-success"><i class="bi bi-file-earmark-excel me-1"></i> Detalle de nómina GK (Excel)</div>
                                    <div class="small text-muted">Las <b>43 columnas del formato solicitado</b>, una fila por percepción, deducción u otro pago. Respeta los filtros aplicados del visor e incluye un <b>Excel separado de campos faltantes</b>, avance y cancelación.</div>
                                </div>
                            </div>
                        </label>
                        <?php endif; ?>
                        <label class="d-block border rounded-3 p-3 mt-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="emitidos_gk">
                                <div>
                                    <div class="fw-bold text-primary"><i class="bi bi-file-earmark-excel me-1"></i> Reporte de emitidos GK (Excel)</div>
                                    <div class="small text-muted">Genera un solo Excel del rango Desde/Hasta con dos pestañas: <b>Facturas</b> y <b>Notas de crédito</b>, ambas con el formato solicitado de 28 columnas.</div>
                                </div>
                            </div>
                        </label>

                        <label class="d-block border rounded-3 p-3 mt-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="detalle_recibidos">
                                <div>
                                    <div class="fw-bold text-info"><i class="bi bi-file-earmark-excel me-1"></i> Detalle XML Recibidos (Excel)</div>
                                    <div class="small text-muted">Exporta <b>todos los CFDI recibidos del rango Desde/Hasta</b>, una línea por concepto, con el formato solicitado de 45 columnas. Incluye medidor de avance y opción de cancelar.</div>
                                </div>
                            </div>
                        </label>

                        <label class="d-block border rounded-3 p-3 mt-2 bg-light" style="cursor:pointer;">
                            <div class="d-flex align-items-start gap-2">
                                <input class="form-check-input mt-1" type="radio" name="tipoExportCsv" value="auditoria_diot">
                                <div>
                                    <div class="fw-bold text-danger"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Sábana auditoría DIOT (Excel)</div>
                                    <div class="small text-muted">Detalle completo de <b>facturas de egreso emitidas en el periodo</b> y, debajo, todos los bloques de conciliación del Resumen DIOT: meses anteriores, pendientes, pagos posteriores, pagos sin REP, IVA especial y moneda extranjera.</div>
                                </div>
                            </div>
                        </label>` : ''}
                    </div>`,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-download me-1"></i> Exportar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#0d6efd',
                focusConfirm: false,
                didOpen: () => {
                    const html = Swal.getHtmlContainer();
                    if (html) {
                        html.style.maxHeight = '62vh';
                        html.style.overflowY = 'auto';
                        html.style.paddingRight = '0.35rem';
                    }
                    const radios = Swal.getHtmlContainer().querySelectorAll('input[name="tipoExportCsv"]');
                    const periodo = Swal.getHtmlContainer().querySelector('#periodoExportEspecial');
                    const actualizarPeriodo = () => {
                        const elegido = Swal.getHtmlContainer().querySelector('input[name="tipoExportCsv"]:checked')?.value;
                        periodo.classList.toggle('d-none', !['diot','chequeo_diot','auditoria_diot'].includes(elegido));
                    };
                    radios.forEach(r => r.addEventListener('change', actualizarPeriodo));
                    actualizarPeriodo();
                },
                preConfirm: () => {
                    const cont = Swal.getHtmlContainer();
                    const modo = cont.querySelector('input[name="tipoExportCsv"]:checked')?.value || 'completa';
                    if (['diot','chequeo_diot','auditoria_diot'].includes(modo)) {
                        const anio = Number(cont.querySelector('#exportDiotAnio')?.value || 0);
                        const mes = Number(cont.querySelector('#exportDiotMes')?.value || 0);
                        if (!anio || !mes) {
                            Swal.showValidationMessage('Seleccione el mes y año para la exportación.');
                            return false;
                        }
                        return { modo, anio, mes };
                    }
                    return { modo };
                }
            });

            return resultado.isConfirmed ? resultado.value : null;
        }

        async function obtenerRegistrosFiltradosExportacion(opciones = {}) {
            const modo = opciones.modo || 'completa';
            const params = new URLSearchParams();
            params.set('export_all', '1');
            params.set('export_mode', modo);
            params.set('_ts', String(Date.now()));

            if (['diot','chequeo_diot'].includes(modo)) {
                params.set('export_anio', String(opciones.anio || ''));
                params.set('export_mes', String(opciones.mes || ''));
            } else {
                params.set('inicio', $('#f_inicio').val() || '');
                params.set('fin', $('#f_fin').val() || '');
            }

            let endpoint = 'ajax/listar_facturas.php';

            if (modoVisor === 'pagos') {
                endpoint = 'ajax/listar_detalles_pagos.php';

                if (modo === 'completa') {
                    params.set('tipo', normalizarClasificacionCfdi($('#filtro_tipo').val() || '')); 
                    params.set('metodo', $('#filtro_metodo_pago').val() || '');
                    params.set('busqueda', ($('#customSearch').val() || '').trim());
                }
            } else {
                if (modo === 'completa') {
                    params.set('tipo', normalizarClasificacionCfdi($('#filtro_tipo').val() || '')); 
                    params.set('metodo', $('#filtro_metodo_pago').val() || '');
                    params.set('ppd_sin_complemento', $('#btnPpdSinComplemento').attr('data-activo') === '1' ? '1' : '0');
                    params.set('pue_sin_pago_empresa', $('#btnPueSinPagoEmpresa').attr('data-activo') === '1' ? '1' : '0');
                    params.set('tipo_busqueda', $('#tipoBusquedaFactura').val() || '');
                    params.set('busqueda', ($('#customSearch').val() || '').trim());
                }
            }

            const resp = await fetch(endpoint + '?' + params.toString(), { cache: 'no-store' });
            const json = await resp.json();
            if (!resp.ok || (json && json.error)) {
                throw new Error((json && json.error) ? json.error : 'No fue posible recuperar la información para exportar.');
            }
            return (json && Array.isArray(json.data)) ? json.data : [];
        }

        function columnasExportacionDetallePagos() {
            return [
                ['Tipo', 'tipo'],
                ['UUID factura', 'uuid_factura'],
                ['Emisor', 'emisor'],
                ['RFC Emisor', 'rfc_emisor'],
                ['Receptor', 'receptor'],
                ['RFC Receptor', 'rfc_receptor'],
                ['Serie', 'serie'],
                ['Folio', 'folio'],
                ['Fecha emisión', 'fecha_factura'],
                ['Fecha fiscal', 'fecha_fiscal'],
                ['Estatus periodo DIOT', 'estatus_periodo_diot'],
                ['Fecha pago SAT', 'fecha_sat'],
                ['Fecha pago banco', 'fecha_banco'],
                ['Fecha pago usuario', 'fecha_usuario'],
                ['Método de pago', 'metodo_pago'],
                ['Forma de pago', 'forma_pago'],
                ['Moneda factura', 'moneda_factura'],
                ['TC factura', 'tc_factura'],
                ['Moneda importes exportados', 'moneda_importes'],
                ['Factor conversión MXN', 'factor_conversion_mxn'],
                ['Moneda pago SAT', 'moneda_pago_sat'],
                ['TC pago SAT', 'tc_pago_sat'],
                ['Total CFDI', 'total_factura'],
                ['Importe aplicado', 'importe_aplicado'],
                ['Saldo anterior', 'saldo_anterior'],
                ['Saldo insoluto', 'saldo_insoluto'],
                ['Base IVA 16%', 'base_iva_16'],
                ['IVA 16%', 'iva_16'],
                ['Base IVA 8%', 'base_iva_8'],
                ['IVA 8%', 'iva_8'],
                ['Base tasa 0%', 'base_tasa_0'],
                ['Base exento', 'base_exento'],
                ['Base no objeto', 'base_no_objeto'],
                ['IVA retenido', 'iva_retenido'],
                ['ISR retenido', 'isr_retenido'],
                ['IEPS / otros', 'ieps_otros'],
                ['Parcialidad', 'parcialidad'],
                ['UUID pago', 'uuid_pago'],
                ['Estatus complemento', 'estatus_complemento'],
                ['UUID sustituto', 'uuid_sustituto'],
                ['Cuenta DIOT', 'cuenta_diot'],
                ['Referencia', 'referencia'],
                ['Aplicado', 'aplicado']
            ];
        }

        function columnasExportacion() {
            if (modoVisor === 'pagos') return columnasExportacionDetallePagos();
            return [
                ['Tipo', 'tipo'],
                ['UUID (Folio Fiscal)', 'uuid'],
                ['Emisor', 'emisor'],
                ['RFC Emisor', 'rfc_emisor'],
                ['Receptor', 'receptor'],
                ['RFC Receptor', 'rfc_receptor'],
                ['Folio', 'folio'],
                ['Fecha emisión', 'fecha'],
                ['Fecha fiscal', 'fecha_fiscal'],
                ['Estatus periodo DIOT', 'estatus_periodo_diot'],
                ['Fecha pago SAT', 'p_sat'],
                ['Fecha pago banco', 'p_banco'],
                ['Fecha pago usuario', 'p_usu'],
                ['Ref. Banco / Folio pago', 'ref_banco'],
                ['Forma de pago', 'forma_pago'],
                ['Método de pago', 'metodo_pago'],
                ['Moneda original', 'moneda'],
                ['TC factura', 'tc_factura'],
                ['Moneda importes exportados', 'moneda_importes'],
                ['Factor conversión MXN', 'factor_conversion_mxn'],
                ['Total CFDI MXN', 'total'],
                ['Subtotal', 'subtotal'],
                ['Base IVA', 'base_iva'],
                ['IVA trasladado', 'iva_t'],
                ['Base retención IVA', 'base_iva_r'],
                ['IVA retenido', 'iva_r'],
                ['Base retención ISR', 'base_isr_r'],
                ['ISR retenido', 'isr_r'],
                ['Base IVA 8%', 'base_iva_8'],
                ['IVA 8%', 'iva_8'],
                ['Base frontera norte', 'base_norte'],
                ['IVA frontera norte', 'iva_norte'],
                ['Base frontera sur', 'base_sur'],
                ['IVA frontera sur', 'iva_sur'],
                ['Número de pagos', 'numero_pagos'],
                ['Uso CFDI', 'uso_cfdi']
            ];
        }

        function valorExportacion(registro, campo) {
            let valor = registro && registro[campo] != null ? registro[campo] : '';
            if (typeof valor === 'object') valor = JSON.stringify(valor);
            return String(valor).replace(/<[^>]*>/g, '').trim();
        }

        function nombreExportacion() {
            const hoy = new Date();
            const dos = n => String(n).padStart(2, '0');
            const prefijo = modoVisor === 'pagos' ? 'PAGOS' : 'CFDI';
            return `${prefijo}_${hoy.getFullYear()}${dos(hoy.getMonth() + 1)}${dos(hoy.getDate())}_${dos(hoy.getHours())}${dos(hoy.getMinutes())}${dos(hoy.getSeconds())}`;
        }

        function descargarBlob(blob, nombre) {
            const url = URL.createObjectURL(blob);
            const enlace = document.createElement('a');
            enlace.href = url;
            enlace.download = nombre;
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1500);
        }

        async function exportarTablaCsv(opciones = {modo:'completa'}) {
            try {
                Swal.fire({
                    title: 'Preparando exportación...',
                    text: 'Recuperando todos los registros correspondientes.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => Swal.showLoading()
                });

                const registros = await obtenerRegistrosFiltradosExportacion(opciones);
                if (!registros.length && opciones.modo !== 'diot') {
                    Swal.fire('Sin registros', 'No hay CFDI que coincidan con la exportación seleccionada.', 'info');
                    return;
                }

                const columnas = columnasExportacion();
                const escapar = valor => '"' + String(valor).replace(/"/g, '""') + '"';
                const lineas = [columnas.map(c => escapar(c[0])).join(',')];

                registros.forEach(registro => {
                    lineas.push(columnas.map(c => escapar(valorExportacion(registro, c[1]))).join(','));
                });

                // En la exportación DIOT agregamos al final los dos grupos que
                // explican el tránsito entre meses. Se dejan 4 renglones en blanco
                // antes de cada grupo para que el contador los identifique rápido.
                let auxiliaresDiot = { anteriores: [], transito: [] };
                if (opciones.modo === 'diot') {
                    const pAux = new URLSearchParams({
                        anio: String(opciones.anio || ''),
                        mes: String(opciones.mes || ''),
                        _ts: String(Date.now())
                    });
                    const rAux = await fetch('ajax/exportar_diot_transitos.php?' + pAux.toString(), { cache: 'no-store' });
                    const jAux = await rAux.json();
                    if (!rAux.ok || !jAux || jAux.success === false) {
                        throw new Error(jAux?.error || 'No fue posible obtener los movimientos en tránsito de la DIOT.');
                    }
                    auxiliaresDiot = jAux;

                    const vacia = Array(columnas.length).fill('').map(escapar).join(',');
                    const separador = () => { for (let i = 0; i < 4; i++) lineas.push(vacia); };
                    const titulo = texto => {
                        const fila = Array(columnas.length).fill('');
                        fila[0] = texto;
                        lineas.push(fila.map(escapar).join(','));
                        lineas.push(columnas.map(c => escapar(c[0])).join(','));
                    };
                    const agregarGrupo = (texto, filas) => {
                        if (!Array.isArray(filas) || !filas.length) return;
                        separador();
                        titulo(texto);
                        filas.forEach(registro => {
                            lineas.push(columnas.map(c => escapar(valorExportacion(registro, c[1]))).join(','));
                        });
                    };

                    agregarGrupo(
                        'FACTURAS DE MESES ANTERIORES PAGADAS / TOMADAS EN CUENTA EN LA DIOT DEL MES',
                        auxiliaresDiot.anteriores
                    );
                    agregarGrupo(
                        'FACTURAS DEL MES EN TRÁNSITO HACIA MES POSTERIOR',
                        auxiliaresDiot.transito
                    );
                }

                const blob = new Blob(['\uFEFF' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                let sufijo = 'COMPLETA';
                if (opciones.modo === 'diot') sufijo = `DIOT_${opciones.anio}_${String(opciones.mes).padStart(2,'0')}`;
                if (opciones.modo === 'chequeo_diot') sufijo = `CHEQUEO_DIOT_${opciones.anio}_${String(opciones.mes).padStart(2,'0')}`;
                descargarBlob(blob, nombreExportacion() + '_' + sufijo + '.csv');

                const totalAux = opciones.modo === 'diot'
                    ? Number((auxiliaresDiot.anteriores || []).length) + Number((auxiliaresDiot.transito || []).length)
                    : 0;
                const detalleAux = opciones.modo === 'diot'
                    ? `<br><span class="small">${(auxiliaresDiot.anteriores || []).length.toLocaleString('es-MX')} de meses anteriores + ${(auxiliaresDiot.transito || []).length.toLocaleString('es-MX')} en tránsito.</span>`
                    : '';
                Swal.fire({
                    icon: 'success',
                    title: 'Exportación lista',
                    html: `<b>${registros.length.toLocaleString('es-MX')}</b> registros del periodo${totalAux ? ` + <b>${totalAux.toLocaleString('es-MX')}</b> auxiliares` : ''}.${detalleAux}`,
                    timer: 2600,
                    showConfirmButton: false
                });
            } catch (e) {
                Swal.fire('Error', e && e.message ? e.message : 'No fue posible generar la exportación.', 'error');
            }
        }

        async function exportarDetalleRecibidosExcel() {
            const inicio = $('#f_inicio').val() || '';
            const fin = $('#f_fin').val() || '';
            if (!inicio || !fin) {
                Swal.fire('Rango requerido', 'Seleccione las fechas Desde y Hasta antes de generar el reporte.', 'warning');
                return;
            }

            let token = '';
            let cancelarSolicitado = false;
            try {
                const fdInicio = new FormData();
                fdInicio.set('inicio', inicio);
                fdInicio.set('fin', fin);
                const r0 = await fetch('ajax/iniciar_xml_recibidos_excel.php', { method: 'POST', body: fdInicio, cache: 'no-store' });
                const j0 = await r0.json();
                if (!r0.ok || !j0 || j0.status === 'error') throw new Error(j0?.msg || 'No fue posible iniciar el reporte.');
                if (j0.status === 'sin_datos') {
                    Swal.fire('Sin registros', j0.msg || 'No hay CFDI recibidos en el rango.', 'info');
                    return;
                }
                token = j0.token;
                const total = Number(j0.total || 0);

                Swal.fire({
                    title: '<i class="bi bi-file-earmark-excel me-2"></i>Generando detalle XML recibidos',
                    html: `
                        <div class="text-start">
                            <div class="small text-muted mb-2">Rango: <b>${inicio}</b> a <b>${fin}</b></div>
                            <div class="progress" style="height:24px;">
                                <div id="rxrBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">0%</div>
                            </div>
                            <div id="rxrTexto" class="small mt-2">Preparando ${total.toLocaleString('es-MX')} CFDI recibidos...</div>
                            <div id="rxrRenglones" class="small text-muted mt-1">Renglones generados: 0</div>
                        </div>`,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: '<i class="bi bi-x-circle me-1"></i> CANCELAR',
                    cancelButtonColor: '#dc3545',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        const btn = Swal.getCancelButton();
                        if (btn) btn.addEventListener('click', () => { cancelarSolicitado = true; });
                    }
                });

                while (!cancelarSolicitado) {
                    const fd = new FormData(); fd.set('token', token);
                    const rp = await fetch('ajax/procesar_xml_recibidos_excel.php', { method:'POST', body:fd, cache:'no-store' });
                    const jp = await rp.json();
                    if (!rp.ok || !jp || jp.status === 'error') throw new Error(jp?.msg || 'Ocurrió un error generando el reporte.');

                    const pct = Math.max(0, Math.min(100, Number(jp.porcentaje || 0)));
                    const bar = document.getElementById('rxrBar');
                    const txt = document.getElementById('rxrTexto');
                    const ren = document.getElementById('rxrRenglones');
                    if (bar) { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
                    if (txt) txt.innerHTML = `CFDI procesados: <b>${Number(jp.procesados||0).toLocaleString('es-MX')}</b> de <b>${Number(jp.total||0).toLocaleString('es-MX')}</b>`;
                    if (ren) ren.textContent = `Renglones generados: ${Number(jp.renglones||0).toLocaleString('es-MX')}`;

                    if (jp.completo) {
                        Swal.close();
                        window.location.href = 'ajax/descargar_xml_recibidos_excel.php?token=' + encodeURIComponent(token);
                        setTimeout(() => Swal.fire({icon:'success',title:'Reporte terminado',html:`Se generaron <b>${Number(jp.renglones||0).toLocaleString('es-MX')}</b> renglones de detalle.`,timer:2200,showConfirmButton:false}), 500);
                        return;
                    }
                    await new Promise(resolve => setTimeout(resolve, 30));
                }

                if (token) {
                    const fdCancel = new FormData(); fdCancel.set('token', token);
                    await fetch('ajax/cancelar_xml_recibidos_excel.php', {method:'POST',body:fdCancel,cache:'no-store'}).catch(()=>{});
                }
                Swal.fire('Proceso cancelado', 'El reporte fue detenido y los archivos temporales fueron eliminados.', 'info');
            } catch (e) {
                if (token) {
                    const fdCancel = new FormData(); fdCancel.set('token', token);
                    await fetch('ajax/cancelar_xml_recibidos_excel.php', {method:'POST',body:fdCancel,cache:'no-store'}).catch(()=>{});
                }
                Swal.fire('Error', e && e.message ? e.message : 'No fue posible generar el detalle XML recibido.', 'error');
            }
        }

        async function exportarEmitidosGkExcel() {
            const inicio = $('#f_inicio').val() || '';
            const fin = $('#f_fin').val() || '';
            if (!inicio || !fin) {
                Swal.fire('Rango requerido', 'Seleccione las fechas Desde y Hasta antes de generar el reporte.', 'warning');
                return;
            }

            let token = '';
            let cancelarSolicitado = false;
            try {
                const fdInicio = new FormData();
                fdInicio.set('inicio', inicio);
                fdInicio.set('fin', fin);
                const r0 = await fetch('ajax/iniciar_emitidos_gk_excel.php', {method:'POST', body:fdInicio, cache:'no-store'});
                const j0 = await r0.json();
                if (!r0.ok || !j0 || j0.status === 'error') throw new Error(j0?.msg || 'No fue posible iniciar el reporte.');
                if (j0.status === 'sin_datos') {
                    Swal.fire('Sin registros', j0.msg || 'No hay CFDI emitidos en el rango.', 'info');
                    return;
                }
                token = j0.token;
                const total = Number(j0.total || 0);

                Swal.fire({
                    title: '<i class="bi bi-file-earmark-excel me-2"></i>Generando reporte de emitidos',
                    html: `
                        <div class="text-start">
                            <div class="small text-muted mb-2">Rango: <b>${inicio}</b> a <b>${fin}</b></div>
                            <div class="progress" style="height:24px;"><div id="regBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div></div>
                            <div id="regTexto" class="small mt-2">Preparando ${total.toLocaleString('es-MX')} CFDI emitidos...</div>
                            <div id="regRenglones" class="small text-muted mt-1">Facturas: 0 renglones · Notas de crédito: 0 renglones</div>
                        </div>`,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: '<i class="bi bi-x-circle me-1"></i> CANCELAR',
                    cancelButtonColor: '#dc3545',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        const btn = Swal.getCancelButton();
                        if (btn) btn.addEventListener('click', () => { cancelarSolicitado = true; });
                    }
                });

                while (!cancelarSolicitado) {
                    const fd = new FormData(); fd.set('token', token);
                    const rp = await fetch('ajax/procesar_emitidos_gk_excel.php', {method:'POST', body:fd, cache:'no-store'});
                    const jp = await rp.json();
                    if (!rp.ok || !jp || jp.status === 'error') throw new Error(jp?.msg || 'Ocurrió un error generando el reporte.');
                    const pct = Math.max(0, Math.min(100, Number(jp.porcentaje || 0)));
                    const bar = document.getElementById('regBar');
                    const txt = document.getElementById('regTexto');
                    const ren = document.getElementById('regRenglones');
                    if (bar) { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
                    if (txt) txt.innerHTML = `CFDI procesados: <b>${Number(jp.procesados||0).toLocaleString('es-MX')}</b> de <b>${Number(jp.total||0).toLocaleString('es-MX')}</b>`;
                    if (ren) ren.textContent = `Facturas: ${Number(jp.facturas||0).toLocaleString('es-MX')} renglones · Notas de crédito: ${Number(jp.notas||0).toLocaleString('es-MX')} renglones`;
                    if (jp.completo) {
                        Swal.close();
                        window.location.href = 'ajax/descargar_emitidos_gk_excel.php?token=' + encodeURIComponent(token);
                        setTimeout(() => Swal.fire({icon:'success',title:'Reporte terminado',html:`Facturas: <b>${Number(jp.facturas||0).toLocaleString('es-MX')}</b> renglones<br>Notas de crédito: <b>${Number(jp.notas||0).toLocaleString('es-MX')}</b> renglones`,timer:2500,showConfirmButton:false}), 500);
                        return;
                    }
                    await new Promise(resolve => setTimeout(resolve, 30));
                }

                if (token) {
                    const fd = new FormData(); fd.set('token', token);
                    await fetch('ajax/cancelar_emitidos_gk_excel.php', {method:'POST',body:fd,cache:'no-store'}).catch(()=>{});
                }
                Swal.fire('Proceso cancelado', 'El reporte fue detenido y sus archivos temporales fueron eliminados.', 'info');
            } catch (e) {
                if (token) {
                    const fd = new FormData(); fd.set('token', token);
                    await fetch('ajax/cancelar_emitidos_gk_excel.php', {method:'POST',body:fd,cache:'no-store'}).catch(()=>{});
                }
                Swal.fire('Error', e && e.message ? e.message : 'No fue posible generar el reporte de emitidos.', 'error');
            }
        }

        function escaparXmlExcel(valor) {
            return String(valor)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&apos;');
        }

        async function exportarTablaExcel() {
            const registros = await obtenerRegistrosFiltradosExportacion();
            if (!registros.length) {
                Swal.fire('Sin registros', 'No hay CFDI que coincidan con los filtros actuales.', 'info');
                return;
            }

            const columnas = columnasExportacion();
            let filas = '<Row>' + columnas.map(c => `<Cell ss:StyleID="Encabezado"><Data ss:Type="String">${escaparXmlExcel(c[0])}</Data></Cell>`).join('') + '</Row>';

            registros.forEach(registro => {
                filas += '<Row>' + columnas.map(c => {
                    const valor = valorExportacion(registro, c[1]);
                    return `<Cell><Data ss:Type="String">${escaparXmlExcel(valor)}</Data></Cell>`;
                }).join('') + '</Row>';
            });

            const libro = `\x3c?xml version="1.0"?>
\x3c?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11"/></Style>
  <Style ss:ID="Encabezado"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#176B91" ss:Pattern="Solid"/></Style>
 </Styles>
 <Worksheet ss:Name="CFDI"><Table>${filas}</Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane></WorksheetOptions>
 </Worksheet>
</Workbook>`;

            const blob = new Blob(['\uFEFF' + libro], { type: 'application/vnd.ms-excel;charset=utf-8;' });
            descargarBlob(blob, nombreExportacion() + '.xls');
        }

        function verDetalle(base64) {
            let d = JSON.parse(decodeURIComponent(escape(atob(base64))));
            $('#edit_uuid').val(d.uuid);
            $('#edit_uuid_disp').val(d.uuid);
            // Validamos existencia de campos por si d.raw no los trae
            $('#edit_tipo').val(d.tipo_raw || d.tipo_comprobante || '');
            $('#edit_emisor').val(d.emisor || d.nombre_emisor || '');
            $('#edit_receptor').val(d.receptor || d.nombre_receptor || '');
            $('#edit_total').val(d.total);
            
            // Fecha Usuario
            let fU = (d.p_usu_raw && d.p_usu_raw !== '0000-00-00' && d.p_usu_raw !== '-') ? d.p_usu_raw : 
                     (d.fecha_pago_usuario && d.fecha_pago_usuario !== '0000-00-00') ? d.fecha_pago_usuario : '';
            $('#edit_fecha_usuario').val(fU);
            const tcRealPago = Number(d.tc_banco ?? d.tc_banco_real ?? 0);
            $('#edit_tc_banco_real').val(tcRealPago > 0 && Math.abs(tcRealPago - 1) > 0.0000001 ? tcRealPago.toFixed(4) : '');

            // Tratamiento DIOT: 01 normal, 02 informar sin efectos fiscales, o excluir totalmente.
            let esExcluido = (d.excluir_diot == 1 || d.excluir_diot === '1' || d.excluir_diot === true);
            let efectoDiot = String(d.efecto_fiscal_diot || '01');
            let tratamiento = esExcluido ? 'NO_INCLUIR' : (efectoDiot === '02' ? 'NO_EFECTOS' : 'NORMAL');
            $('#edit_tratamiento_diot').val(tratamiento);

            const ivaEspecial = (d.iva_tratamiento_especial_diot == 1 || d.iva_tratamiento_especial_diot === '1' || d.iva_tratamiento_especial_diot === true);
            $('#edit_iva_especial').prop('checked', ivaEspecial);
            $('#edit_iva_porcentaje').val(Number(d.iva_porcentaje_acreditable_diot ?? 100).toFixed(2));
            $('#edit_iva_motivo').val(d.iva_motivo_tratamiento_diot || '');
            $('#edit_iva_xml').val(Number(d.iva_xml || 0));
            toggleTratamientoIvaEspecial();
            actualizarPreviewIvaEspecial();

            $('#modalEdicion').modal('show');
        }

        function toggleTratamientoIvaEspecial() {
            const activo = $('#edit_iva_especial').is(':checked');
            $('#bloque_iva_especial').toggle(activo);
            if (activo && (parseFloat($('#edit_iva_porcentaje').val()) >= 100 || isNaN(parseFloat($('#edit_iva_porcentaje').val())))) {
                $('#edit_iva_porcentaje').val('50.00');
            }
            actualizarPreviewIvaEspecial();
        }

        function actualizarPreviewIvaEspecial() {
            const iva = parseFloat($('#edit_iva_xml').val()) || 0;
            let pct = parseFloat($('#edit_iva_porcentaje').val());
            if (isNaN(pct)) pct = 100;
            pct = Math.max(0, Math.min(100, pct));
            const acreditable = iva * pct / 100;
            const noAcreditable = iva - acreditable;
            const money = n => '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
            $('#preview_iva_xml').text(money(iva));
            $('#preview_iva_acred').text(money(acreditable));
            $('#preview_iva_no_acred').text(money(noAcreditable));
        }

        async function guardarCambio(alcanceFecha = 'auto') {
            let formData = new FormData(document.getElementById('formEdicion'));

            const tratamientoDiot = $('#edit_tratamiento_diot').val() || 'NORMAL';
            formData.set('tratamiento_diot', tratamientoDiot);
            // Compatibilidad con el campo histórico: solo NO_INCLUIR activa excluir_diot.
            formData.set('excluir_diot', tratamientoDiot === 'NO_INCLUIR' ? '1' : '0');
            formData.set('efecto_fiscal_diot', tratamientoDiot === 'NO_EFECTOS' ? '02' : '01');
            const ivaEspecial = $('#edit_iva_especial').is(':checked');
            formData.set('iva_tratamiento_especial_diot', ivaEspecial ? '1' : '0');
            formData.set('iva_porcentaje_acreditable_diot', ivaEspecial ? ($('#edit_iva_porcentaje').val() || '50') : '100');
            formData.set('iva_motivo_tratamiento_diot', ivaEspecial ? ($('#edit_iva_motivo').val() || '') : '');
            const tcRealPago = parseFloat($('#edit_tc_banco_real').val());
            formData.set('tc_banco_real', (!isNaN(tcRealPago) && tcRealPago > 0) ? tcRealPago.toFixed(4) : '');
            formData.set('alcance_fecha', alcanceFecha);

            try {
                let res = await fetch('ajax/actualizar_factura.php', { method: 'POST', body: formData });
                let data = await res.json();

                if (data.status === 'requiere_alcance') {
                    const r = await Swal.fire({
                        icon: 'question',
                        title: 'Factura con parcialidades',
                        html: `Esta factura tiene <b>${data.movimientos}</b> movimientos de pago.<br><br>¿Desea aplicar esta fecha a <b>todos los pagos</b> o editar una parcialidad específica?`,
                        showCancelButton: true,
                        showDenyButton: true,
                        confirmButtonText: '<i class="bi bi-calendar-check"></i> Aplicar a todos',
                        denyButtonText: '<i class="bi bi-list-check"></i> Editar parcialidad',
                        cancelButtonText: 'Cancelar'
                    });

                    if (r.isConfirmed) {
                        return guardarCambio('todos');
                    }
                    if (r.isDenied) {
                        const uuid = $('#edit_uuid').val();
                        $('#modalEdicion').modal('hide');
                        setTimeout(() => verDetallePagos(uuid, null), 250);
                    }
                    return;
                }

                if (data.status === 'ok') {
                    Swal.fire({ icon: 'success', title: 'Datos Actualizados', timer: 1000, showConfirmButton: false });
                    $('#modalEdicion').modal('hide');
                    table.ajax.reload(null, false);
                } else {
                    Swal.fire('Error', data.msg || 'No fue posible actualizar', 'error');
                }
            } catch (e) {
                Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
            }
        }

        $(document).off('click.editarFechaDetalle').on('click.editarFechaDetalle', '.btn-editar-fecha-detalle', async function () {
            const idDetalle = Number($(this).data('id') || 0);
            let fechaActual = String($(this).data('fecha') || '');
            // La API del modal entrega dd/mm/yyyy; convertir a yyyy-mm-dd para el input date.
            if (/^\d{2}\/\d{2}\/\d{4}$/.test(fechaActual)) {
                const [d,m,y] = fechaActual.split('/');
                fechaActual = `${y}-${m}-${d}`;
            }

            const r = await Swal.fire({
                title: 'Fecha efectiva de la parcialidad',
                html: '<small>La fecha capturada por el usuario tendrá prioridad sobre SAT/Banco únicamente en este movimiento.</small>',
                input: 'date',
                inputValue: fechaActual,
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: 'Guardar fecha',
                denyButtonText: 'Quitar fecha manual',
                cancelButtonText: 'Cancelar',
                inputAttributes: { 'aria-label': 'Fecha efectiva de pago' }
            });

            if (!r.isConfirmed && !r.isDenied) return;
            const fecha = r.isDenied ? '' : (r.value || '');

            try {
                const fd = new FormData();
                fd.set('id_detalle', idDetalle);
                fd.set('fecha_usuario', fecha);
                const res = await fetch('ajax/actualizar_fecha_detalle_pago.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!res.ok || data.status !== 'ok') throw new Error(data.msg || 'No fue posible actualizar la parcialidad');

                Swal.fire({ icon: 'success', title: 'Parcialidad actualizada', timer: 900, showConfirmButton: false });
                table.ajax.reload(null, false);
                if (detallePagosUuidActual) verDetallePagos(detallePagosUuidActual, null);
            } catch (e) {
                Swal.fire('Error', e.message || 'No fue posible actualizar la parcialidad', 'error');
            }
        });

        var gasolinaUuidActual = null;

        function gasMoney(v) {
            return new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(Number(v || 0));
        }
        function gasEsc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
        function gasFecha(v) {
            if (!v) return '-';
            const s = String(v).substring(0,10);
            const p = s.split('-');
            return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : s;
        }

        async function verGasolina(uuid) {
            gasolinaUuidActual = uuid;
            $('#gasUuid').text(uuid || '');
            $('#gasResumenBody,#gasConsumosBody,#gasImpuestosBody').html('<tr><td colspan="7" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando...</td></tr>');
            $('#modalGasolina').modal('show');

            try {
                const res = await fetch('ajax/listar_gasolina_factura.php?uuid=' + encodeURIComponent(uuid), {cache:'no-store'});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No fue posible leer el complemento de gasolina.');

                const c = json.cabecera || {};
                const det = Array.isArray(json.detalles) ? json.detalles : [];
                const imps = Array.isArray(json.impuestos) ? json.impuestos : [];
                const porId = {};
                det.forEach(d => { porId[String(d.id_detalle)] = d; });

                $('#gasProveedor').text((c.emisor || '-') + (c.rfc_emisor ? ' (' + c.rfc_emisor + ')' : ''));
                $('#gasFechaCfdi').text(gasFecha(c.fecha_emision));
                $('#gasTipoOperacion').text(c.tipo_operacion || '-');
                $('#gasCuenta').text(c.numero_cuenta || '-');
                $('#gasSubtotal').text(gasMoney(c.subtotal_combustible));
                $('#gasIva').text(gasMoney(c.iva_total));
                $('#gasIeps').text(gasMoney(c.ieps_total));
                $('#gasTotal').text(gasMoney(c.total_combustible));
                $('#gasPagado').text(gasMoney(c.total_pagado));
                $('#gasPendiente').text(gasMoney(c.saldo_pendiente));
                $('#gasEstatus').html(Number(c.ya_pago || 0) === 1 ? '<span class="badge bg-success">PAGADO</span>' : '<span class="badge bg-warning text-dark">PENDIENTE</span>');
                $('#gasFechaPago').val(c.fecha_pago ? String(c.fecha_pago).substring(0,10) : '');
                $('#gasObservaciones').val(c.observaciones || '');

                let totalResumen = 0, totalImporte = 0;
                let hr = '', hc = '';
                det.forEach(d => {
                    const totalOp = Number(d.total_operacion || 0);
                    totalResumen += totalOp;
                    totalImporte += Number(d.importe || 0);
                    hr += `<tr><td>${gasFecha(d.fecha_operacion)}</td><td>${gasEsc(d.rfc_gasolinera)}</td><td class="text-end">${gasMoney(totalOp)}</td><td>${gasEsc(d.nombre_gasolinera || d.rfc_gasolinera)}</td><td>${gasEsc(d.folio_operacion)}</td></tr>`;
                    hc += `<tr><td>${gasFecha(d.fecha_operacion)}</td><td>${gasEsc(d.rfc_gasolinera)}</td><td>${gasEsc(d.folio_operacion)}</td><td>${gasEsc(d.nombre_combustible || d.tipo_combustible)}</td><td class="text-end">${Number(d.cantidad||0).toFixed(3)}</td><td class="text-end">${Number(d.valor_unitario||0).toFixed(3)}</td><td class="text-end">${gasMoney(d.importe)}</td></tr>`;
                });
                $('#gasResumenBody').html(hr || '<tr><td colspan="5" class="text-center text-muted py-4">Sin operaciones.</td></tr>');
                $('#gasConsumosBody').html(hc || '<tr><td colspan="7" class="text-center text-muted py-4">Sin operaciones.</td></tr>');
                $('#gasResumenTotal').text(gasMoney(totalResumen));
                $('#gasConsumosTotal').text(gasMoney(totalImporte));

                let hi = '', totalImp = 0;
                imps.forEach(i => {
                    const d = porId[String(i.id_detalle)] || {};
                    totalImp += Number(i.importe || 0);
                    hi += `<tr><td>${gasFecha(d.fecha_operacion)}</td><td>${gasEsc(d.rfc_gasolinera || '')}</td><td class="text-end">${gasMoney(d.importe)}</td><td>${gasEsc(i.impuesto)}</td><td class="text-end">${Number(i.tasa_cuota||0).toFixed(6)}</td><td class="text-end">${gasMoney(i.importe)}</td></tr>`;
                });
                $('#gasImpuestosBody').html(hi || '<tr><td colspan="6" class="text-center text-muted py-4">Sin impuestos.</td></tr>');
                $('#gasImpuestosTotal').text(gasMoney(totalImp));
            } catch (e) {
                $('#gasResumenBody').html(`<tr><td colspan="5" class="text-center text-danger py-4">${gasEsc(e.message)}</td></tr>`);
                $('#gasConsumosBody').empty();
                $('#gasImpuestosBody').empty();
            }
        }

        async function guardarPagoGasolina() {
            if (!gasolinaUuidActual) return;
            try {
                const fd = new FormData();
                fd.set('uuid', gasolinaUuidActual);
                fd.set('fecha_pago', $('#gasFechaPago').val() || '');
                fd.set('observaciones', $('#gasObservaciones').val() || '');
                const res = await fetch('ajax/actualizar_pago_gasolina.php', {method:'POST',body:fd});
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'No fue posible guardar el pago.');
                Swal.fire({icon:'success',title:'Gasolina actualizada',text:json.msg,timer:1200,showConfirmButton:false});
                await verGasolina(gasolinaUuidActual);
                table.ajax.reload(null,false);
            } catch (e) {
                Swal.fire('Error', e.message || 'No fue posible guardar el pago de gasolina.', 'error');
            }
        }

        async function procesarCarpeta() {
            const input = document.getElementById('inputCarpeta');
            const files = Array.from(input.files).filter(f => f.name.toLowerCase().endsWith('.xml'));
            if (!files.length) { Swal.fire('Error', 'No seleccionaste archivos XML', 'error'); return; }
            $('#contenedor_progreso').show();
            $('#log_carga').empty();
            let procesados = 0;
            for (const file of files) {
                let formData = new FormData();
                formData.append('xml', file);
                try {
                    let response = await fetch('ajax/procesar_xml_unico.php', { method: 'POST', body: formData });
                    let res = await response.json();
                    procesados++;
                    let pct = Math.round((procesados / files.length) * 100);
                    $('#bar_progreso').css('width', pct + '%');
                    let color = res.status === 'ok' ? '#0f0' : (res.status === 'duplicado' ? '#ff0' : '#f00');
                    $('#log_carga').prepend(`<div style="color:${color}">[${pct}%] ${file.name} -> ${res.msg}</div>`);
                } catch (e) {
                    $('#log_carga').prepend(`<div class="text-danger">Error fatal en: ${file.name}</div>`);
                }
            }
            table.ajax.reload();
            Swal.fire('Carga Masiva Exitosa', `Se procesaron ${files.length} archivos`, 'success');
        }

        var verSatCancelado = false;
        var verSatAbortController = null;

        function verSatEscapeHtml(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function verSatParamsFiltro() {
            const p = new URLSearchParams();
            p.set('export_all', '1');
            p.set('export_mode', 'completa');
            p.set('_ts', String(Date.now()));
            p.set('inicio', $('#f_inicio').val() || '');
            p.set('fin', $('#f_fin').val() || '');
            p.set('tipo', normalizarClasificacionCfdi($('#filtro_tipo').val() || '')); 
            p.set('metodo', $('#filtro_metodo_pago').val() || '');
            p.set('ppd_sin_complemento', $('#btnPpdSinComplemento').attr('data-activo') === '1' ? '1' : '0');
            p.set('pue_sin_pago_empresa', $('#btnPueSinPagoEmpresa').attr('data-activo') === '1' ? '1' : '0');
            p.set('tipo_busqueda', $('#tipoBusquedaFactura').val() || '');
            p.set('busqueda', ($('#customSearch').val() || '').trim());
            return p;
        }

        var VER_SAT_TAM_BLOQUE = 500;
        var VER_SAT_PAUSA_BLOQUE_MS = 2000;

        function verSatDormir(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        function verSatGuardarEstadoFiltros() {
            return {
                inicio: $('#f_inicio').val() || '',
                fin: $('#f_fin').val() || '',
                tipo: normalizarClasificacionCfdi($('#filtro_tipo').val() || ''),
                metodo: $('#filtro_metodo_pago').val() || '',
                tipoBusqueda: $('#tipoBusquedaFactura').val() || '',
                busqueda: $('#customSearch').val() || '',
                ppd: $('#btnPpdSinComplemento').attr('data-activo') === '1',
                pue: $('#btnPueSinPagoEmpresa').attr('data-activo') === '1'
            };
        }

        function verSatRestaurarEstadoFiltros(f) {
            if (!f) return;
            $('#f_inicio').val(f.inicio || '');
            $('#f_fin').val(f.fin || '');
            $('#filtro_tipo').val(normalizarClasificacionCfdi(f.tipo || ''));
            $('#filtro_metodo_pago').val(f.metodo || '');
            $('#tipoBusquedaFactura').val(f.tipoBusqueda || '');
            $('#customSearch').val(f.busqueda || '');
            $('#btnPpdSinComplemento').attr('data-activo', f.ppd ? '1' : '0');
            $('#btnPueSinPagoEmpresa').attr('data-activo', f.pue ? '1' : '0');
        }

        async function obtenerCfdiFiltradosParaVerificar() {
            const info = table.page.info();
            const totalFiltrado = Number(info && info.recordsDisplay ? info.recordsDisplay : 0);
            if (totalFiltrado <= 0) throw new Error('No hay CFDI con los filtros actuales.');

            const resp = await fetch('ajax/listar_facturas.php?' + verSatParamsFiltro().toString(), { cache: 'no-store' });
            const json = await resp.json();
            if (!resp.ok || (json && json.error)) throw new Error((json && json.error) ? json.error : 'No fue posible obtener los CFDI filtrados.');
            const filas = (json && Array.isArray(json.data)) ? json.data : [];
            if (!filas.length) throw new Error('No hay CFDI con los filtros actuales.');
            return filas;
        }

        async function verificarEstatusSatIndividual(fila) {
            if (!fila || !fila.uuid) {
                Swal.fire({icon:'warning', title:'Verificar estatus SAT', text:'La fila no contiene un UUID válido.', background:'#161b22', color:'#fff'});
                return;
            }

            const uuid = String(fila.uuid || '').trim();
            const folio = String(fila.folio || '').trim();
            const emisor = String(fila.emisor || '').trim();
            const estadoActual = String(fila.estatus_sat || 'Vigente').trim();

            const confirmar = await Swal.fire({
                title:'Verificar estatus SAT',
                html:`<div class="text-start">
                        <div class="mb-2">Se consultará <b>únicamente este CFDI</b> directamente contra el SAT.</div>
                        <div class="border rounded p-2 bg-dark">
                            <div><b>Folio:</b> ${verSatEscapeHtml(folio || 'S/F')}</div>
                            <div><b>UUID:</b> ${verSatEscapeHtml(uuid)}</div>
                            <div><b>Emisor:</b> ${verSatEscapeHtml(emisor || '-')}</div>
                            <div><b>Estatus local:</b> ${verSatEscapeHtml(estadoActual)}</div>
                        </div>
                      </div>`,
                icon:'question',
                showCancelButton:true,
                confirmButtonText:'<i class="bi bi-shield-check"></i> Verificar',
                cancelButtonText:'Cancelar',
                confirmButtonColor:'#198754',
                background:'#161b22', color:'#fff', width:680
            });
            if (!confirmar.isConfirmed) return;

            Swal.fire({
                title:'Consultando SAT',
                html:`<div class="py-3"><span class="spinner-border text-success me-2"></span>Verificando ${verSatEscapeHtml(uuid)}...</div>`,
                showConfirmButton:false, allowOutsideClick:false, allowEscapeKey:false,
                background:'#161b22', color:'#fff'
            });

            try {
                const body = new URLSearchParams();
                body.set('uuid', uuid);
                body.set('id_verificacion', '0'); // consulta individual: no abre corrida masiva

                const resp = await fetch('ajax/verificar_estatus_cfdi_sat.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body:body.toString(),
                    cache:'no-store'
                });
                const r = await resp.json();
                if (!resp.ok || !r.ok) throw new Error(r.error || 'No fue posible consultar el SAT.');

                const nuevo = String(r.estado_sat || '').trim() || 'Sin respuesta';
                const cambio = !!r.cambio;
                const icono = nuevo.toUpperCase() === 'CANCELADO' ? 'warning' : 'success';
                const detalleCambio = cambio
                    ? `<div class="alert alert-warning py-2 mt-2 mb-0"><b>Cambio detectado:</b> ${verSatEscapeHtml(r.estatus_anterior || estadoActual)} → ${verSatEscapeHtml(nuevo)}<br><small>El estatus local fue actualizado automáticamente.</small></div>`
                    : `<div class="alert alert-success py-2 mt-2 mb-0">No hubo cambio de estatus.</div>`;

                await Swal.fire({
                    icon:icono,
                    title:'Estatus SAT: ' + verSatEscapeHtml(nuevo),
                    html:`<div class="text-start">
                            <div><b>Folio:</b> ${verSatEscapeHtml(folio || 'S/F')}</div>
                            <div><b>UUID:</b> ${verSatEscapeHtml(uuid)}</div>
                            <div><b>Código SAT:</b> ${verSatEscapeHtml(r.codigo_estatus || '-')}</div>
                            <div><b>Cancelable:</b> ${verSatEscapeHtml(r.es_cancelable || '-')}</div>
                            <div><b>Estatus cancelación:</b> ${verSatEscapeHtml(r.estatus_cancelacion || '-')}</div>
                            ${detalleCambio}
                          </div>`,
                    confirmButtonText:'Cerrar', background:'#161b22', color:'#fff', width:700
                });

                // Refrescar la misma página para reflejar de inmediato Vigente/Cancelado.
                table.ajax.reload(null, false);
            } catch (e) {
                Swal.fire({icon:'error', title:'No se pudo verificar', text:(e && e.message) ? e.message : 'Error al consultar el SAT.', background:'#161b22', color:'#fff'});
            }
        }

        async function abrirVerificadorEstatusSat() {
            const filtrosOriginales = verSatGuardarEstadoFiltros();
            let filas;
            try {
                filas = await obtenerCfdiFiltradosParaVerificar();
            } catch (e) {
                Swal.fire({ icon:'warning', title:'Verificar status SAT', text:e.message || 'No fue posible preparar la verificación.', background:'#161b22', color:'#fff' });
                return;
            }

            const total = filas.length;
            const totalBloques = Math.ceil(total / VER_SAT_TAM_BLOQUE);
            const confirmar = await Swal.fire({
                title: '<i class="bi bi-shield-check me-2"></i>Verificar status SAT',
                html: `
                    <div class="text-start">
                        <div class="mb-2">Se verificarán directamente contra el SAT los CFDI que cumplen <b>los filtros actuales</b>.</div>
                        <div class="alert alert-info py-2 mb-2">
                            <b>${total.toLocaleString('es-MX')}</b> CFDI preparados.<br>
                            Se procesarán en <b>${totalBloques.toLocaleString('es-MX')}</b> bloque(s) de hasta <b>${VER_SAT_TAM_BLOQUE}</b> CFDI,
                            con una pausa de <b>${VER_SAT_PAUSA_BLOQUE_MS / 1000} segundos</b> entre bloques.
                        </div>
                        <div class="small text-muted">La consulta es individual por UUID. Solo se actualizará el estatus local cuando el SAT responda Vigente o Cancelado.</div>
                    </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-play-fill"></i> Empezar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#198754',
                background: '#161b22',
                color: '#fff',
                width: 700
            });
            if (!confirmar.isConfirmed) return;

            verSatCancelado = false;
            const cambios = [];
            const errores = [];
            let procesados = 0;
            let vigentes = 0;
            let cancelados = 0;
            let sinCambio = 0;
            let bloqueActual = 1;
            let idVerificacionSat = 0;

            // Abrimos primero la corrida de bitácora. A partir de aquí cada UUID
            // queda registrado aunque el navegador se cierre antes de terminar.
            try {
                const bodyInicio = new URLSearchParams();
                bodyInicio.set('total_cfdi', String(total));
                bodyInicio.set('filtros_json', JSON.stringify(filtrosOriginales));
                const respInicio = await fetch('ajax/iniciar_verificacion_sat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: bodyInicio.toString(),
                    cache: 'no-store'
                });
                const jsonInicio = await respInicio.json();
                if (!respInicio.ok || !jsonInicio.ok) throw new Error(jsonInicio.error || 'No fue posible iniciar la bitácora.');
                idVerificacionSat = Number(jsonInicio.id_verificacion || 0);
                if (idVerificacionSat <= 0) throw new Error('La bitácora no devolvió un folio válido.');
            } catch (e) {
                await Swal.fire({
                    icon:'error',
                    title:'No se pudo iniciar la bitácora',
                    text:(e && e.message) ? e.message : 'Revise que las tablas de bitácora estén instaladas.',
                    background:'#161b22',
                    color:'#fff'
                });
                return;
            }

            Swal.fire({
                title: 'Verificando CFDI en SAT',
                html: `
                    <div class="text-start">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span id="verSatTexto">Preparando...</span>
                            <span id="verSatPct">0%</span>
                        </div>
                        <div class="progress mb-2" style="height:24px;background:#30363d;">
                            <div id="verSatBarra" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width:0%">0 / ${total.toLocaleString('es-MX')}</div>
                        </div>
                        <div id="verSatBloque" class="small text-info mb-3">Bloque 1 de ${totalBloques}</div>
                        <div class="row g-2 text-center mb-3 small">
                            <div class="col"><div class="border rounded p-2"><b id="verSatVigentes">0</b><br>Vigentes</div></div>
                            <div class="col"><div class="border rounded p-2"><b id="verSatCancelados">0</b><br>Cancelados</div></div>
                            <div class="col"><div class="border rounded p-2"><b id="verSatCambios">0</b><br>Cambiaron</div></div>
                            <div class="col"><div class="border rounded p-2"><b id="verSatErrores">0</b><br>Errores</div></div>
                        </div>
                        <div id="verSatActual" class="small text-muted text-truncate mb-2"></div>
                        <div class="text-center">
                            <button type="button" id="btnCancelarVerSat" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-stop-circle"></i> Cancelar proceso
                            </button>
                        </div>
                    </div>`,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                background: '#161b22',
                color: '#fff',
                width: 780,
                didOpen: () => {
                    const b = document.getElementById('btnCancelarVerSat');
                    if (b) b.addEventListener('click', () => {
                        verSatCancelado = true;
                        if (verSatAbortController) verSatAbortController.abort();
                        b.disabled = true;
                        b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cancelando...';
                    });
                }
            });

            for (let i = 0; i < filas.length; i++) {
                if (verSatCancelado) break;

                bloqueActual = Math.floor(i / VER_SAT_TAM_BLOQUE) + 1;
                const posicionBloque = (i % VER_SAT_TAM_BLOQUE) + 1;
                const totalEnBloque = Math.min(VER_SAT_TAM_BLOQUE, total - ((bloqueActual - 1) * VER_SAT_TAM_BLOQUE));
                const fila = filas[i];
                const uuid = String(fila.uuid || '').trim();
                if (!uuid) continue;

                $('#verSatBloque').text(`Bloque ${bloqueActual} de ${totalBloques} · ${posicionBloque} de ${totalEnBloque}`);
                const pctAntes = Math.round((procesados / total) * 100);
                $('#verSatTexto').text('Consultando ' + (procesados + 1).toLocaleString('es-MX') + ' de ' + total.toLocaleString('es-MX'));
                $('#verSatPct').text(pctAntes + '%');
                $('#verSatActual').text(uuid + ' · ' + (fila.emisor || ''));

                try {
                    verSatAbortController = new AbortController();
                    const body = new URLSearchParams();
                    body.set('uuid', uuid);
                    body.set('id_verificacion', String(idVerificacionSat));
                    const resp = await fetch('ajax/verificar_estatus_cfdi_sat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString(),
                        cache: 'no-store',
                        signal: verSatAbortController.signal
                    });
                    const r = await resp.json();
                    if (!resp.ok || !r.ok) throw new Error(r.error || 'Respuesta inválida del SAT.');

                    if (String(r.estado_sat || '').toUpperCase() === 'VIGENTE') vigentes++;
                    if (String(r.estado_sat || '').toUpperCase() === 'CANCELADO') cancelados++;
                    if (r.cambio) {
                        cambios.push({ uuid, anterior:r.estatus_anterior || '', nuevo:r.estado_sat || '', codigo:r.codigo_estatus || '' });
                    } else {
                        sinCambio++;
                    }
                } catch (e) {
                    if (verSatCancelado && e && e.name === 'AbortError') break;
                    errores.push({ uuid, error:(e && e.message) ? e.message : 'Error de comunicación' });
                } finally {
                    verSatAbortController = null;
                }

                procesados++;
                const pct = Math.round((procesados / total) * 100);
                $('#verSatBarra').css('width', pct + '%').text(procesados.toLocaleString('es-MX') + ' / ' + total.toLocaleString('es-MX'));
                $('#verSatPct').text(pct + '%');
                $('#verSatVigentes').text(vigentes.toLocaleString('es-MX'));
                $('#verSatCancelados').text(cancelados.toLocaleString('es-MX'));
                $('#verSatCambios').text(cambios.length.toLocaleString('es-MX'));
                $('#verSatErrores').text(errores.length.toLocaleString('es-MX'));

                // Al completar un bloque, damos descanso al servicio SAT antes de continuar.
                const terminoBloque = ((i + 1) % VER_SAT_TAM_BLOQUE === 0) && (i + 1 < filas.length);
                if (terminoBloque && !verSatCancelado) {
                    $('#verSatTexto').text(`Bloque ${bloqueActual} terminado. Pausa de ${VER_SAT_PAUSA_BLOQUE_MS / 1000} segundos...`);
                    $('#verSatActual').text('Esperando antes del siguiente bloque para no golpear continuamente el servicio del SAT.');
                    await verSatDormir(VER_SAT_PAUSA_BLOQUE_MS);
                }
            }

            const canceladoUsuario = verSatCancelado;
            verSatAbortController = null;

            // Cerramos la cabecera de la bitácora con los totales calculados desde
            // el detalle realmente guardado. Si esta llamada falla, la corrida queda
            // PROCESANDO y no perdemos las consultas ya registradas.
            let resumenBitacora = null;
            try {
                const bodyFin = new URLSearchParams();
                bodyFin.set('id_verificacion', String(idVerificacionSat));
                bodyFin.set('cancelada', canceladoUsuario ? '1' : '0');
                const respFin = await fetch('ajax/finalizar_verificacion_sat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: bodyFin.toString(),
                    cache: 'no-store'
                });
                const jsonFin = await respFin.json();
                if (respFin.ok && jsonFin.ok) resumenBitacora = jsonFin.resumen || null;
            } catch (e) {
                console.warn('No se pudo cerrar la bitácora SAT:', e);
            }

            const cambiosHtml = cambios.length
                ? `<div class="table-responsive mt-2" style="max-height:280px;overflow:auto;">
                     <table class="table table-sm table-dark table-striped align-middle mb-0">
                       <thead><tr><th>UUID</th><th>Anterior</th><th>Nuevo</th></tr></thead>
                       <tbody>${cambios.map(c => `<tr><td class="text-start small">${verSatEscapeHtml(c.uuid)}</td><td>${verSatEscapeHtml(c.anterior || '-')}</td><td><b>${verSatEscapeHtml(c.nuevo || '-')}</b></td></tr>`).join('')}</tbody>
                     </table>
                   </div>`
                : '<div class="alert alert-success py-2 mt-2 mb-0">No hubo cambios de estatus.</div>';

            const erroresHtml = errores.length
                ? `<details class="text-start mt-2"><summary class="text-warning">Ver ${errores.length.toLocaleString('es-MX')} error(es)</summary><div class="small mt-1" style="max-height:160px;overflow:auto;">${errores.map(e => `<div>${verSatEscapeHtml(e.uuid)}: ${verSatEscapeHtml(e.error)}</div>`).join('')}</div></details>`
                : '';

            await Swal.fire({
                icon: canceladoUsuario ? 'info' : (errores.length ? 'warning' : 'success'),
                title: canceladoUsuario ? 'Verificación cancelada' : 'Verificación terminada',
                html: `
                    <div class="text-start">
                        <div class="row g-2 text-center mb-2">
                            <div class="col"><div class="border rounded p-2"><b>${procesados.toLocaleString('es-MX')}</b><br>Procesados</div></div>
                            <div class="col"><div class="border rounded p-2"><b>${vigentes.toLocaleString('es-MX')}</b><br>Vigentes</div></div>
                            <div class="col"><div class="border rounded p-2"><b>${cancelados.toLocaleString('es-MX')}</b><br>Cancelados</div></div>
                            <div class="col"><div class="border rounded p-2"><b>${cambios.length.toLocaleString('es-MX')}</b><br>Cambiaron</div></div>
                            <div class="col"><div class="border rounded p-2"><b>${errores.length.toLocaleString('es-MX')}</b><br>Errores</div></div>
                        </div>
                        <div class="small text-info mb-1">Procesado en bloques de hasta ${VER_SAT_TAM_BLOQUE} CFDI.</div>
                        <div class="small text-success mb-2"><i class="bi bi-journal-check"></i> Bitácora SAT #${idVerificacionSat.toLocaleString('es-MX')} guardada.</div>
                        <div class="fw-bold mt-2">CFDI que cambiaron de estatus:</div>
                        ${cambiosHtml}
                        ${erroresHtml}
                    </div>`,
                confirmButtonText: 'Cerrar',
                background: '#161b22',
                color: '#fff',
                width: 920
            });

            // IMPORTANTE: la consulta SAT nunca debe dejar vacío el visor.
            // Restauramos exactamente los filtros que tenía el usuario y recargamos
            // la DataTable al cerrar el resumen. No destruimos índices ni DataTables.
            verSatRestaurarEstadoFiltros(filtrosOriginales);
            if ($.fn.DataTable.isDataTable('#tablaFacturas')) {
                table.ajax.reload(null, false);
            }
        }


        var emdCancelado = false;
        var emdAbortController = null;
        var emdSeleccionandoUuid = false;
        var tablaEmd = null;

        // Modal especializado: dejamos sólo los datos necesarios para localizar
        // rápidamente la factura y buscar dentro de sus conceptos.
        var emdColumnas = [
            ['fecha','Fecha'],
            ['folio','Folio'],
            ['uuid','UUID'],
            ['emisor','Emisor'],
            ['total','Importe'],
            ['desc1','Desc 1'],
            ['desc2','Desc 2'],
            ['desc3','Desc 3']
        ];

        function emdEscape(v){ return $('<div>').text(v == null ? '' : String(v)).html(); }
        function emdXmlExcelEsc(v){ return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;'); }
        function emdExportarExcel(){
            if(!tablaEmd){ Swal.fire('Sin datos','Todavía no hay facturas para exportar.','info'); return; }
            const filas=tablaEmd.rows({search:'applied'}).data().toArray();
            if(!filas.length){ Swal.fire('Sin datos','No hay coincidencias para exportar con la búsqueda actual.','info'); return; }
            const cab=emdColumnas.map(c=>c[1]);
            let rows='<Row>'+cab.map(h=>`<Cell ss:StyleID="Header"><Data ss:Type="String">${emdXmlExcelEsc(h)}</Data></Cell>`).join('')+'</Row>';
            filas.forEach(r=>{
                rows+='<Row>'+emdColumnas.map(c=>{
                    const campo=c[0], v=emdValorFila(r,campo);
                    if(campo==='total'){
                        const n=Number(v||0);
                        return `<Cell ss:StyleID="Money"><Data ss:Type="Number">${Number.isFinite(n)?n:0}</Data></Cell>`;
                    }
                    return `<Cell><Data ss:Type="String">${emdXmlExcelEsc(v)}</Data></Cell>`;
                }).join('')+'</Row>';
            });
            const emisor=($('#customSearch').val()||'').trim();
            const filtro=($('#emdBuscar').val()||'').trim();
            const desde=$('#f_inicio').val()||''; const hasta=$('#f_fin').val()||'';
            const xml=`\x3c?xml version="1.0" encoding="UTF-8"?>\x3c?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Calibri" ss:Size="10"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Center"/></Style><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style><Style ss:ID="Money"><NumberFormat ss:Format="$#,##0.00"/></Style></Styles><Worksheet ss:Name="EMISOR DESCRIPCION"><Table><Row><Cell ss:MergeAcross="7" ss:StyleID="Title"><Data ss:Type="String">Nombre emisor + descripción de conceptos</Data></Cell></Row><Row><Cell ss:MergeAcross="7"><Data ss:Type="String">Emisor: ${emdXmlExcelEsc(emisor)} | Rango: ${emdXmlExcelEsc(desde)} a ${emdXmlExcelEsc(hasta)} | Filtro descripción: ${emdXmlExcelEsc(filtro||'Sin filtro')} | Registros: ${filas.length}</Data></Cell></Row>${rows}</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane></WorksheetOptions></Worksheet></Workbook>`;
            const blob=new Blob(['\ufeff',xml],{type:'application/vnd.ms-excel;charset=utf-8;'});
            const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
            const seguro=(emisor||'EMISOR').replace(/[^A-Za-z0-9_-]+/g,'_').slice(0,40);
            a.download='Emisor_Descripcion_'+seguro+'_'+new Date().toISOString().slice(0,10).replace(/-/g,'')+'.xls';
            document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),1000);
        }
        function emdValorFila(row, campo){
            if (campo === 'revision') return Number(row.revision||0) ? 'REVISAR' : 'OK';
            if (campo === 'ya_pago' || campo === 'conciliado') return Number(row[campo]||0) ? 'Sí' : 'No';
            const v=row[campo]; return v == null ? '' : v;
        }

        function emdPrepararTabla(filas){
            if ($.fn.DataTable.isDataTable('#tablaEmisorDescripcion')) {
                $('#tablaEmisorDescripcion').DataTable().destroy();
            }
            const $tr=$('#tablaEmisorDescripcion thead tr').empty();
            emdColumnas.forEach(c=>$tr.append(`<th>${emdEscape(c[1])}</th>`));
            const columnas=emdColumnas.map(c=>({
                data:c[0],
                defaultContent:'',
                render:function(d,type,row){
                    const v=emdValorFila(row,c[0]);
                    if(type!=='display') return v;
                    if(c[0]==='estatus_sat') {
                        const e=String(v||'').toUpperCase();
                        const cl=(e==='VIGENTE')?'bg-success':((e==='CANCELADO'||e==='CANCELADA')?'bg-danger':'bg-secondary');
                        return `<span class="badge ${cl}">${emdEscape(e||'-')}</span>`;
                    }
                    if(c[0]==='total') {
                        const n=Number(v||0);
                        return `<span class="d-block text-end">${n.toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
                    }
                    if(c[0]==='desc1'||c[0]==='desc2'||c[0]==='desc3'||c[0]==='emisor') {
                        return `<span title="${emdEscape(v)}">${emdEscape(v)}</span>`;
                    }
                    return emdEscape(v);
                }
            }));
            tablaEmd=$('#tablaEmisorDescripcion').DataTable({
                data:filas,
                columns:columnas,
                deferRender:true,
                scrollX:true,
                scrollY:'calc(100vh - 300px)',
                scrollCollapse:true,
                paging:true,
                pageLength:25,
                lengthMenu:[15,25,50,100],
                searching:true,
                autoWidth:false,
                dom:'rt<"d-flex justify-content-between mt-2"lip>',
                order:[[0,'asc'],[3,'asc']],
                language:{
                    emptyTable:'No hay facturas para esta búsqueda',info:'Mostrando _START_ a _END_ de _TOTAL_ facturas',infoEmpty:'0 facturas',
                    infoFiltered:'(filtrado de _MAX_)',lengthMenu:'Mostrar _MENU_',zeroRecords:'No se encontraron coincidencias',paginate:{next:'Siguiente',previous:'Anterior'}
                },
                columnDefs:[
                    {targets:[0],width:'105px'},
                    {targets:[1],width:'105px'},
                    {targets:[2],width:'290px'},
                    {targets:[3],width:'240px'},
                    {targets:[4],width:'125px',className:'text-end'},
                    {targets:[5,6,7],width:'360px'}
                ]
            });
            $('#emdBuscar').off('input.emd').on('input.emd',function(){ if(tablaEmd) tablaEmd.search(this.value).draw(); });
            $('#btnEmdLimpiar').off('click.emd').on('click.emd',function(){ $('#emdBuscar').val(''); if(tablaEmd) tablaEmd.search('').draw(); });
            $('#btnEmdExportarExcel').off('click.emd').on('click.emd',emdExportarExcel);

            // El modal funciona como puente de selección hacia el visor principal.
            // Doble clic: cerrar este modal, buscar el UUID exacto en el visor y
            // dejar marcada visualmente la factura encontrada.
            $('#tablaEmisorDescripcion tbody')
                .off('dblclick.emdSeleccion')
                .on('dblclick.emdSeleccion', 'tr', function(e){
                    if ($(e.target).closest('button,a,input,select,textarea').length) return;
                    if (!tablaEmd) return;

                    const fila = tablaEmd.row(this).data();
                    const uuid = String((fila && fila.uuid) || '').trim();
                    if (!uuid) return;

                    const modalEl = document.getElementById('modalEmisorDescripcion');
                    const modal = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                    emdSeleccionandoUuid = true;
                    modal.hide();

                    // Pasamos el UUID al buscador normal del visor. Así reutilizamos
                    // toda la lógica existente de consulta/detalle sin duplicarla.
                    $('#tipoBusquedaFactura').val('uuid');
                    $('#customSearch').val(uuid);
                    window.estadoVisorBaseDescripcion = null;

                    // Cuando termine de cargar el visor, resaltar la fila localizada.
                    table.one('draw.emdSeleccion', function(){
                        let encontrada = null;
                        table.rows({page:'current'}).every(function(){
                            const d = this.data();
                            if (d && String(d.uuid || '').toUpperCase() === uuid.toUpperCase()) {
                                encontrada = this.node();
                            }
                        });
                        if (encontrada) {
                            $('#tablaFacturas tbody tr').removeClass('fila-seleccionada');
                            $(encontrada).addClass('fila-seleccionada');
                            try { encontrada.scrollIntoView({behavior:'smooth', block:'center'}); } catch(_e) {}
                        }
                    });

                    // Misma acción que si el usuario hubiera elegido UUID y pulsado Filtrar.
                    $('#btnAplicarFiltros').trigger('click');
                });
        }

        $('#modalEmisorDescripcion').off('hidden.bs.modal.emdEstado').on('hidden.bs.modal.emdEstado', function(){
            // Si solo consultó/cerró el modal, regresar a los controles del filtro real
            // que ya estaba aplicado. Doble clic es la excepción: ahí sí pasamos al UUID.
            if (emdSeleccionandoUuid) {
                emdSeleccionandoUuid = false;
                return;
            }
            if (window.estadoVisorBaseDescripcion) {
                aplicarEstadoFiltrosVisor(window.estadoVisorBaseDescripcion);
                activarModoFiltroSegunEstado();
                window.ultimoEstadoAplicadoVisor = Object.assign({}, window.estadoVisorBaseDescripcion);
                window.estadoVisorBaseDescripcion = null;
            }
        });

        function emdActualizarProgreso(actual,total,texto){
            const pct=total>0?Math.min(100,Math.round(actual*100/total)):0;
            $('#emdBarra').css('width',pct+'%').attr('aria-valuenow',pct);
            $('#emdEstado').text(texto || `${actual.toLocaleString('es-MX')} de ${total.toLocaleString('es-MX')} XML procesados (${pct}%)`);
        }

        async function abrirModalEmisorDescripcion(){
            const busqueda=($('#customSearch').val()||'').trim();
            if(!busqueda){
                await Swal.fire({icon:'info',title:'Capture el nombre del emisor',text:'Esta opción primero localiza las facturas por nombre de emisor y después lee sus descripciones desde el XML.',background:'#161b22',color:'#fff'});
                return;
            }
            emdCancelado=false;
            $('#emdBuscar').val('');
            $('#emdResumen').text(`Emisor: ${busqueda} | Rango: ${$('#f_inicio').val()||'-'} a ${$('#f_fin').val()||'-'}`);
            $('#emdEstado').text('Localizando facturas con los mismos filtros del visor...');
            $('#emdBarra').css('width','0%').addClass('progress-bar-animated');
            $('#btnEmdCancelar').prop('disabled',false);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEmisorDescripcion')).show();
            emdPrepararTabla([]);

            $('#btnEmdCancelar').off('click.emd').on('click.emd',function(){
                emdCancelado=true;
                if(emdAbortController) emdAbortController.abort();
                $('#emdEstado').text('Proceso cancelado. Se conservan y pueden buscarse los XML ya procesados.');
                $('#emdBarra').removeClass('progress-bar-animated');
                $(this).prop('disabled',true);
            });

            try{
                // Reutilizamos listar_facturas.php para que el conjunto sea EXACTAMENTE el mismo del visor.
                const p=new URLSearchParams();
                p.set('export_all','1'); p.set('export_mode','completa'); p.set('inicio',$('#f_inicio').val()||''); p.set('fin',$('#f_fin').val()||'');
                p.set('tipo',normalizarClasificacionCfdi($('#filtro_tipo').val())); p.set('metodo',$('#filtro_metodo_pago').val()||'');
                p.set('ppd_sin_complemento',$('#btnPpdSinComplemento').attr('data-activo')==='1'?'1':'0');
                p.set('pue_sin_pago_empresa',$('#btnPueSinPagoEmpresa').attr('data-activo')==='1'?'1':'0');
                p.set('tipo_busqueda','nombre_emisor'); p.set('busqueda',busqueda); p.set('_ts',String(Date.now()));
                emdAbortController=new AbortController();
                const r=await fetch('ajax/listar_facturas.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:p.toString(),cache:'no-store',signal:emdAbortController.signal});
                const j=await r.json();
                if(!r.ok || j.error) throw new Error(j.error||'No se pudieron localizar las facturas.');
                const filas=Array.isArray(j.data)?j.data:[];
                filas.forEach(x=>{x.desc1='';x.desc2='';x.desc3='';});
                emdPrepararTabla(filas);
                $('#emdResumen').text(`${filas.length.toLocaleString('es-MX')} facturas | Emisor: ${busqueda} | ${$('#f_inicio').val()||'-'} a ${$('#f_fin').val()||'-'}`);
                if(!filas.length){ emdActualizarProgreso(0,0,'No se encontraron facturas con ese emisor y filtros.'); $('#btnEmdCancelar').prop('disabled',true); return; }

                const lote=40;
                for(let pos=0;pos<filas.length;pos+=lote){
                    if(emdCancelado) break;
                    const parte=filas.slice(pos,pos+lote);
                    const body=new URLSearchParams(); body.set('uuids',JSON.stringify(parte.map(x=>x.uuid)));
                    emdAbortController=new AbortController();
                    const rr=await fetch('ajax/procesar_descripciones_facturas.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),cache:'no-store',signal:emdAbortController.signal});
                    const jj=await rr.json();
                    if(!rr.ok || !jj.ok) throw new Error(jj.error||'No se pudieron leer las descripciones.');
                    parte.forEach(f=>{
                        const d=(jj.data||{})[String(f.uuid||'').toUpperCase()]||{};
                        f.desc1=d.desc1||''; f.desc2=d.desc2||''; f.desc3=d.desc3||'';
                    });
                    // Actualizar únicamente las filas del lote para no reconstruir toda la DataTable.
                    parte.forEach(f=>{
                        const indexes=tablaEmd.rows(function(idx,data){return data.uuid===f.uuid;}).indexes();
                        indexes.each(function(idx){tablaEmd.row(idx).data(f);});
                    });
                    tablaEmd.draw(false);
                    const hechos=Math.min(pos+lote,filas.length);
                    emdActualizarProgreso(hechos,filas.length);
                    await new Promise(res=>setTimeout(res,20));
                }
                emdAbortController=null;
                $('#btnEmdCancelar').prop('disabled',true);
                $('#emdBarra').removeClass('progress-bar-animated');
                if(!emdCancelado) emdActualizarProgreso(filas.length,filas.length,`Listo: ${filas.length.toLocaleString('es-MX')} facturas procesadas. Ya puede buscar por Desc 1, Desc 2 o Desc 3.`);
            }catch(e){
                emdAbortController=null;
                if(e.name==='AbortError' && emdCancelado) return;
                $('#btnEmdCancelar').prop('disabled',true);
                $('#emdBarra').removeClass('progress-bar-animated');
                $('#emdEstado').text('Error: '+(e.message||String(e)));
            }
        }

        async function procesarAbonosPendientes() {
            const result = await Swal.fire({
                title: '¿Aplicar Pagos Pendientes?',
                text: "Se analizarán los complementos cargados para descontar saldos y actualizar fechas.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, procesar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#238636',
                cancelButtonColor: '#d33',
                background: '#161b22',
                color: '#fff'
            });

            if (result.isConfirmed) {
                Swal.fire({ 
                    title: 'Procesando...', 
                    html: 'Sincronizando saldos...',
                    allowOutsideClick: false, 
                    didOpen: () => { Swal.showLoading(); } 
                });

                try {
                    const response = await fetch('ajax/aplicar_pagos_pendientes.php', { method: 'POST' });
                    const res = await response.json();

                    if (res.status === 'ok') {
                        Swal.fire({ icon: 'success', title: '¡Éxito!', text: res.msg, background: '#161b22', color: '#fff' });
                        table.ajax.reload(null, false); 
                    } else {
                        Swal.fire({ icon: 'info', title: 'Atención', text: res.msg, background: '#161b22', color: '#fff' });
                    }
                } catch (e) {
                    Swal.fire('Error', 'Error de comunicación.', 'error');
                }
            }
        }
    </script>
</div>
