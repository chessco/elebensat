<?php
session_start();
if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_empresa'])) {
    http_response_code(401);
    exit('Sesión no válida');
}
$empresaNombre = trim((string)($_SESSION['empresa_nombre'] ?? 'Empresa activa'));

require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
try { exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'ver_solicitudes_sat'); } catch (Throwable $e) { http_response_code(403); exit($e->getMessage()); }
?>
<style>
.sat-panel{background:#fff;border:1px solid #c9d8e5;border-radius:10px;padding:16px}.sat-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}.sat-filtros{background:#f8fbfd;border:1px solid #c9d8e5;border-radius:8px;padding:10px 12px;margin-bottom:14px}.sat-filtros-linea{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.sat-campo{min-width:0}.sat-campo-tipo{width:145px}.sat-campo-paquete{width:125px}.sat-campo-estatus{width:135px}.sat-campo-fecha{width:145px}.sat-campo-buscar{flex:1 1 240px;min-width:210px}.sat-campo-acciones{display:flex;gap:5px;width:142px}.sat-filtros .form-label,.modal-sat .form-label{color:#34506a;font-size:.68rem;margin-bottom:3px;font-weight:700;white-space:nowrap}.sat-filtros .form-control,.sat-filtros .form-select{border-color:#b7c9d8}.sat-resumen{font-size:.85rem;color:#4d6578}.badge-sat{min-width:88px;display:inline-block;padding:5px 8px}.badge-flujo{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:112px;padding:5px 8px;border-radius:6px;font-weight:700;white-space:nowrap}.flujo-ok{background:#198754;color:#fff}.flujo-proceso{background:#0dcaf0;color:#163b48}.flujo-pendiente{background:#e9ecef;color:#53606c}.flujo-error{background:#dc3545;color:#fff}.flujo-cancelada{background:#495057;color:#fff}.mensaje-sat{max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:help}.sat-origen-manual{color:#0d6efd;font-weight:700}.sat-origen-automatica{color:#198754;font-weight:700}#tablaSolicitudesSat{width:100%!important}#tablaSolicitudesSat thead th{background:#e9f2f8!important;color:#123f5d;border-color:#c9d8e5!important;white-space:nowrap;font-size:.76rem}#tablaSolicitudesSat tbody td{border-color:#d7e2ea!important;font-size:.76rem;vertical-align:middle}.modal-sat{z-index:1085!important}.modal-sat .modal-dialog{height:calc(100vh - 24px);max-height:calc(100vh - 24px);margin:12px auto}.modal-sat .modal-content{height:100%;max-height:100%;display:flex;flex-direction:column;overflow:hidden}.modal-sat .modal-header{background:#0d6b8a;color:#fff;flex:0 0 auto}.modal-sat form{display:flex;flex-direction:column;min-height:0;flex:1 1 auto}.modal-sat .modal-body{overflow-y:auto;overflow-x:hidden;min-height:0;flex:1 1 auto;padding-bottom:18px}.modal-sat .modal-footer{position:sticky;bottom:0;z-index:5;flex:0 0 auto;background:#fff;border-top:1px solid #d4e1ea;box-shadow:0 -3px 10px rgba(0,0,0,.08);padding:12px 16px}.modal-sat .bloque{background:#f8fbfd;border:1px solid #d4e1ea;border-radius:8px;padding:12px}.acciones-sat{white-space:nowrap}.btn-cancelar-sat{background:#dc3545!important;border:1px solid #dc3545!important;color:#fff!important;font-weight:600;border-radius:6px;padding:5px 10px;transition:all .15s ease-in-out}.btn-cancelar-sat:hover,.btn-cancelar-sat:focus{background:#bb2d3b!important;border-color:#b02a37!important;color:#fff!important}.proceso-click{cursor:pointer}.proceso-click:hover{filter:brightness(.94);box-shadow:0 0 0 2px rgba(13,110,253,.12)}.detalle-kpis{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin-bottom:12px}.detalle-kpi{border:1px solid #d4e1ea;border-radius:8px;padding:10px;text-align:center;background:#f8fbfd}.detalle-kpi strong{display:block;font-size:1.25rem}.detalle-tabla-wrap{max-height:52vh;overflow:auto}.detalle-tabla thead th{position:sticky;top:0;background:#e9f2f8;z-index:2;white-space:nowrap}.detalle-filtros .btn.active{font-weight:700}.btn-cancelar-sat:disabled,.btn-cancelar-sat.disabled{background:#e9ecef!important;border-color:#d0d7de!important;color:#8a8f98!important;opacity:1!important;cursor:not-allowed}.sat-estado-resumen{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px 0}.sat-estado-chip{display:inline-flex;align-items:center;gap:6px;border-radius:7px;padding:6px 10px;font-size:.76rem;font-weight:700;border:1px solid transparent}.sat-estado-chip strong{font-size:.9rem}.sat-chip-ok{background:#e8f5ee;color:#146c43;border-color:#b8dfc9}.sat-chip-proceso{background:#e5f8fc;color:#087990;border-color:#a9e3ef}.sat-chip-pendiente{background:#f1f3f5;color:#5c636a;border-color:#d9dee2}.sat-chip-cerrado{background:#e9ecef;color:#343a40;border-color:#c8ced3}.sat-chip-error{background:#fdebed;color:#b02a37;border-color:#f1b8be}.fila-cerrada td{background:#f5f6f7!important;color:#687078}.fila-completa td{background:#f5fbf7!important}.swal2-container{z-index:20050!important}.swal2-popup{z-index:20051!important}@media(max-width:900px){.sat-campo-tipo,.sat-campo-paquete,.sat-campo-estatus,.sat-campo-fecha{flex:1 1 145px;width:auto}.sat-campo-buscar{flex:1 1 100%}.sat-campo-acciones{width:100%}}

.cancelados-kpis{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:8px;margin-bottom:12px}.cancelados-kpi{border:1px solid #d4e1ea;border-radius:8px;background:#f8fbfd;padding:9px;text-align:center}.cancelados-kpi small{display:block;color:#5f7386;font-weight:700}.cancelados-kpi strong{font-size:1.15rem;color:#123f5d}.cancelados-filtros{background:#f8fbfd;border:1px solid #d4e1ea;border-radius:8px;padding:10px;margin-bottom:12px}.uuid-cancelado{font-family:Consolas,Monaco,monospace;font-size:.78rem}.btn-cancelados-sat{background:#dc3545!important;border-color:#dc3545!important;color:#fff!important;opacity:1!important;visibility:visible!important}.btn-cancelados-sat:hover,.btn-cancelados-sat:focus{background:#bb2d3b!important;border-color:#b02a37!important;color:#fff!important}.xml-si{color:#146c43;font-weight:700}.xml-no{color:#b02a37;font-weight:700}#tablaCanceladosSat{width:100%!important}#tablaCanceladosSat thead th{background:#fce8ea!important;color:#842029!important;white-space:nowrap;font-size:.74rem}#tablaCanceladosSat tbody td{font-size:.75rem;vertical-align:middle}.modal-cancelados .modal-dialog{max-width:96vw!important}.modal-cancelados .modal-body{overflow:hidden!important}.cancelados-tabla-wrap{overflow:auto;max-height:56vh}@media(max-width:1000px){.cancelados-kpis{grid-template-columns:repeat(2,minmax(120px,1fr))}}

.sat-context-menu{position:fixed;z-index:21000;display:none;min-width:210px;background:#fff;border:1px solid #c9d8e5;border-radius:8px;box-shadow:0 8px 26px rgba(0,0,0,.20);padding:6px}.sat-context-menu button{width:100%;border:0;background:#fff;text-align:left;padding:9px 11px;border-radius:6px;font-size:.82rem;color:#123f5d}.sat-context-menu button:hover{background:#eef6fb}.sat-context-menu .text-danger{color:#b02a37!important}
</style>

<div class="sat-panel">
  <div class="sat-toolbar">
    <div>
      <button type="button" class="btn btn-primary" id="btnNuevaSolicitudSat"><i class="bi bi-plus-circle"></i> NUEVA SOLICITUD</button>
      <button type="button" class="btn btn-outline-info" id="btnActualizarSolicitudes"><i class="bi bi-arrow-clockwise"></i> Actualizar estados</button>
      <button type="button" class="btn btn-outline-primary" id="btnProcesarMetadataManual"><i class="bi bi-file-earmark-text"></i> Procesar metadata</button>
      <button type="button" class="btn btn-danger btn-cancelados-sat" id="btnCanceladosSat"><i class="bi bi-x-octagon"></i> Cancelados SAT</button>
    </div>
    <div class="sat-resumen">Las solicitudes manuales cargan el histórico inicial. SGKSAT mantendrá después la ventana automática de 5 días.</div>
  </div>

  <div class="sat-filtros">
    <div class="sat-filtros-linea">
      <div class="sat-campo sat-campo-tipo"><label class="form-label">TIPO SOLICITUD</label><select id="satTipo" class="form-select form-select-sm"><option value="">Todos</option><option value="emitidos">Emitidos</option><option value="recibidos">Recibidos</option></select></div>
      <div class="sat-campo sat-campo-paquete"><label class="form-label">PAQUETE</label><select id="satPaquete" class="form-select form-select-sm"><option value="">Todos</option><option value="xml">XML</option><option value="metadata">Metadata</option></select></div>
      <div class="sat-campo sat-campo-estatus"><label class="form-label">ESTATUS</label><select id="satEstatus" class="form-select form-select-sm"><option value="">Todos</option><option value="0">Pendiente</option><option value="1">En proceso</option><option value="2">Terminada</option><option value="3">Error</option><option value="4">Cancelada</option></select></div>
      <div class="sat-campo sat-campo-fecha"><label class="form-label">SOLICITUD DESDE</label><input type="date" id="satFechaInicio" class="form-control form-control-sm" title="Filtra por la fecha en que se creó la solicitud"></div>
      <div class="sat-campo sat-campo-fecha"><label class="form-label">SOLICITUD HASTA</label><input type="date" id="satFechaFin" class="form-control form-control-sm" title="Filtra por la fecha en que se creó la solicitud"></div>
      <div class="sat-campo sat-campo-buscar"><label class="form-label">BUSCAR ID, MENSAJE O MOTIVO</label><input type="text" id="satBuscar" class="form-control form-control-sm" placeholder="ID de solicitud, mensaje o motivo..."></div>
      <div class="sat-campo sat-campo-acciones"><button class="btn btn-primary btn-sm flex-fill" id="btnFiltrarSat"><i class="bi bi-funnel"></i> Filtrar</button><button class="btn btn-outline-secondary btn-sm" id="btnLimpiarSat"><i class="bi bi-x-circle"></i></button></div>
    </div>
  </div>

  <div class="sat-estado-resumen" id="resumenEstadosProcesamiento">
    <span class="sat-estado-chip sat-chip-ok"><i class="bi bi-check-circle-fill"></i> Completas <strong id="resCompleto">0</strong></span>
    <span class="sat-estado-chip sat-chip-proceso"><i class="bi bi-arrow-repeat"></i> En proceso <strong id="resProceso">0</strong></span>
    <span class="sat-estado-chip sat-chip-pendiente"><i class="bi bi-clock"></i> Pendientes <strong id="resPendiente">0</strong></span>
    <span class="sat-estado-chip sat-chip-cerrado"><i class="bi bi-slash-circle"></i> No se procesarán <strong id="resCerrado">0</strong></span>
    <span class="sat-estado-chip sat-chip-error"><i class="bi bi-exclamation-triangle-fill"></i> Con errores <strong id="resError">0</strong></span>
  </div>

  <div class="table-responsive">
    <table id="tablaSolicitudesSat" class="table table-striped table-hover table-bordered w-100">
      <thead><tr><th>ID</th><th>FECHA SOLICITUD</th><th>ID SOLICITUD SAT</th><th>ORIGEN</th><th>TIPO</th><th>PAQUETE</th><th>FECHA DESDE</th><th>FECHA HASTA</th><th>ESTATUS SAT</th><th>DESCARGA</th><th>PROCESAMIENTO</th><th>MENSAJE SAT</th><th>ACCIONES</th></tr></thead>
    </table>
  </div>
</div>

<div class="modal fade modal-sat" id="modalNuevaSolicitudSat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-down"></i> Nueva solicitud SAT</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="formNuevaSolicitudSat"><div class="modal-body">
      <div class="alert alert-info py-2"><strong>Empresa activa:</strong> <?= htmlspecialchars($empresaNombre) ?><br><small>“Ambos” generará dos solicitudes independientes: una de emitidos y otra de recibidos.</small></div>
      <div class="row g-3">
        <div class="col-md-6"><div class="bloque h-100"><label class="form-label">TIPO DE CFDI</label><select class="form-select" name="tipo" required><option value="ambos">Ambos: emitidos y recibidos</option><option value="emitidos">Emitidos</option><option value="recibidos">Recibidos</option></select><div class="form-text">El SAT entrega un folio distinto para cada tipo.</div></div></div>
        <div class="col-md-6"><div class="bloque h-100"><label class="form-label">CONTENIDO</label><select class="form-select" name="paquete_tipo" id="nuevaSatPaquete" required><option value="xml">XML</option><option value="metadata">Metadata</option></select><div class="form-text">Para la carga inicial normalmente se utiliza XML.</div></div></div>
        <div class="col-md-6 d-none" id="bloqueEstadoMetadata"><div class="bloque h-100"><label class="form-label">ESTATUS METADATA</label><select class="form-select" name="estado_comprobante" id="nuevaSatEstadoMetadata"><option value="todos">Todos: vigentes + cancelados</option><option value="cancelado">Solo cancelados</option></select><div class="form-text">Este filtro solo se envía al SAT cuando el contenido es Metadata.</div></div></div>
        <div class="col-md-6"><label class="form-label">FECHA DESDE</label><input type="date" class="form-control" name="fecha_inicio" id="nuevaSatDesde" required></div>
        <div class="col-md-6"><label class="form-label">FECHA HASTA</label><input type="date" class="form-control" name="fecha_fin" id="nuevaSatHasta" required></div>
        <div class="col-md-6"><label class="form-label">MOTIVO</label><select class="form-select" name="motivo"><option value="Carga inicial">Carga inicial</option><option value="Recuperación de periodo">Recuperación de periodo</option><option value="Corrección">Corrección</option><option value="Prueba">Prueba</option><option value="Otro">Otro</option></select></div>
        <div class="col-md-6 d-flex align-items-end"><div class="alert alert-primary py-2 mb-0 w-100"><i class="bi bi-calendar3"></i> <strong>Rango completo.</strong><br><small>Se generará una sola solicitud por tipo para todo el rango capturado. Máximo 2 años.</small></div></div>
        <div class="col-12"><label class="form-label">OBSERVACIONES</label><textarea class="form-control" name="observaciones" rows="2" maxlength="500" placeholder="Notas opcionales de esta carga inicial..."></textarea></div>
      </div>
      <div class="alert alert-warning mt-3 mb-0 py-2"><i class="bi bi-info-circle"></i> Las solicitudes se registrarán como <strong>Pendientes</strong>. SGKSAT debe tomarlas, enviarlas al SAT y actualizar su folio y estatus.</div>
    </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button><button type="submit" class="btn btn-primary" id="btnGenerarSolicitudes"><i class="bi bi-cloud-arrow-down"></i> Generar solicitudes</button></div></form>
  </div></div>
</div>


<div class="modal fade modal-sat" id="modalMetadataManual" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-check"></i> Procesar metadata SAT</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="alert alert-info py-2"><strong>Empresa activa:</strong> <?= htmlspecialchars($empresaNombre) ?><br><small>Seleccione el TXT ya desempacado que genera el SAT. El sistema valida el RFC de la empresa antes de aplicar cambios.</small></div>
      <form id="formMetadataManual">
        <label class="form-label">ARCHIVO METADATA (.TXT)</label>
        <input type="file" class="form-control" name="archivo_metadata" id="archivoMetadataManual" accept=".txt,text/plain" required>
        <div class="form-text">Formato esperado: Uuid~RfcEmisor~NombreEmisor~...~Estatus~FechaCancelacion.</div>
      </form>
      <div id="metadataManualProceso" class="d-none mt-3">
        <div class="d-flex justify-content-between align-items-center mb-1"><strong id="metadataManualEstado">Preparando...</strong><span id="metadataManualPct">0%</span></div>
        <div class="progress" style="height:22px"><div id="metadataManualBarra" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">0%</div></div>
        <div class="row g-2 mt-2 text-center">
          <div class="col-6 col-md-3"><div class="detalle-kpi"><small>LEÍDOS</small><strong id="mmLeidos">0</strong></div></div>
          <div class="col-6 col-md-3"><div class="detalle-kpi"><small>EN VISOR</small><strong id="mmEncontrados">0</strong></div></div>
          <div class="col-6 col-md-3"><div class="detalle-kpi"><small>CANCELADOS</small><strong id="mmCancelados">0</strong></div></div>
          <div class="col-6 col-md-3"><div class="detalle-kpi"><small>NO ENCONTRADOS</small><strong id="mmNoEncontrados">0</strong></div></div>
        </div>
        <div class="small mt-2" id="metadataManualDetalle"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-danger d-none" id="btnCancelarMetadataManual"><i class="bi bi-stop-circle"></i> Cancelar procesamiento</button>
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btnCerrarMetadataManual"><i class="bi bi-x-lg"></i> Cerrar</button>
      <button type="button" class="btn btn-primary" id="btnIniciarMetadataManual"><i class="bi bi-play-circle"></i> Procesar archivo</button>
    </div>
  </div></div>
</div>


<div class="modal fade modal-sat modal-cancelados" id="modalCanceladosSat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
    <div class="modal-header bg-danger"><h5 class="modal-title"><i class="bi bi-x-octagon-fill"></i> CFDI cancelados SAT · <?= htmlspecialchars($empresaNombre) ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="alert alert-light border py-2 mb-2"><i class="bi bi-info-circle text-danger"></i> Este listado nace de la <strong>metadata SAT</strong>. Un cancelado puede aparecer aunque su XML nunca haya llegado al visor; en ese caso se conserva el registro y se muestra como <strong>Sin XML</strong>.</div>
      <div class="cancelados-kpis">
        <div class="cancelados-kpi"><small>TOTAL CANCELADOS</small><strong id="canTotal">0</strong></div>
        <div class="cancelados-kpi"><small>CON XML</small><strong id="canConXml">0</strong></div>
        <div class="cancelados-kpi"><small>SIN XML</small><strong id="canSinXml">0</strong></div>
        <div class="cancelados-kpi"><small>EMITIDOS</small><strong id="canEmitidos">0</strong></div>
        <div class="cancelados-kpi"><small>RECIBIDOS</small><strong id="canRecibidos">0</strong></div>
      </div>
      <div class="cancelados-filtros">
        <div class="row g-2 align-items-end">
          <div class="col-6 col-md-2"><label class="form-label">TIPO</label><select id="canTipo" class="form-select form-select-sm"><option value="">Todos</option><option value="emitidos">Emitidos</option><option value="recibidos">Recibidos</option></select></div>
          <div class="col-6 col-md-2"><label class="form-label">XML EN VISOR</label><select id="canXml" class="form-select form-select-sm"><option value="">Todos</option><option value="si">Con XML</option><option value="no">Sin XML</option></select></div>
          <div class="col-6 col-md-2"><label class="form-label">CANCELADO DESDE</label><input type="date" id="canDesde" class="form-control form-control-sm"></div>
          <div class="col-6 col-md-2"><label class="form-label">CANCELADO HASTA</label><input type="date" id="canHasta" class="form-control form-control-sm"></div>
          <div class="col-12 col-md-4 d-flex gap-2"><button type="button" class="btn btn-danger btn-sm flex-fill" id="btnFiltrarCancelados"><i class="bi bi-funnel"></i> Filtrar</button><button type="button" class="btn btn-success btn-sm" id="btnExportarCancelados" title="Exportar todos los resultados filtrados a CSV"><i class="bi bi-file-earmark-spreadsheet"></i> Exportar</button><button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiarCancelados"><i class="bi bi-x-circle"></i> Limpiar</button><button type="button" class="btn btn-outline-primary btn-sm" id="btnRefrescarCancelados" title="Actualizar listado"><i class="bi bi-arrow-clockwise"></i></button></div>
        </div>
      </div>
      <div class="cancelados-tabla-wrap">
        <table class="table table-striped table-hover table-bordered table-sm" id="tablaCanceladosSat">
          <thead><tr><th>UUID</th><th>TIPO</th><th>FECHA EMISIÓN</th><th>FECHA CANCELACIÓN</th><th>RFC EMISOR</th><th>EMISOR</th><th>RFC RECEPTOR</th><th>RECEPTOR</th><th>MONTO</th><th>XML</th><th>REVISADO</th><th>ACCIÓN</th></tr></thead>
        </table>
      </div>
    </div>
    <div class="modal-footer"><span class="me-auto small text-muted">El buscador de la tabla encuentra UUID, RFC, nombres y monto.</span><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button></div>
  </div></div>
</div>


<div class="modal fade modal-sat" id="modalDetalleProcesamientoSat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-list-check"></i> Detalle de procesamiento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div id="detalleProcesamientoCabecera" class="alert alert-info py-2"></div>
      <div class="detalle-kpis">
        <div class="detalle-kpi"><small>PROCESADOS</small><strong id="kpiProcOk">0</strong></div>
        <div class="detalle-kpi"><small>DUPLICADOS</small><strong id="kpiProcDup">0</strong><span class="text-success small">No representan problema</span></div>
        <div class="detalle-kpi"><small>ERRORES REALES</small><strong id="kpiProcErr">0</strong></div>
        <div class="detalle-kpi"><small>PENDIENTES</small><strong id="kpiProcPend">0</strong></div>
      </div>
      <div class="detalle-filtros btn-group btn-group-sm mb-2" role="group">
        <button type="button" class="btn btn-outline-primary active btnFiltroDetalle" data-tipo="todos">Todos</button>
        <button type="button" class="btn btn-outline-success btnFiltroDetalle" data-tipo="duplicados">Duplicados</button>
        <button type="button" class="btn btn-outline-danger btnFiltroDetalle" data-tipo="errores">Errores</button>
        <button type="button" class="btn btn-outline-info btnFiltroDetalle" data-tipo="procesados">Procesados</button>
        <button type="button" class="btn btn-outline-secondary btnFiltroDetalle" data-tipo="pendientes">Pendientes</button>
      </div>
      <div class="detalle-tabla-wrap">
        <table class="table table-sm table-striped table-bordered detalle-tabla mb-0" id="tablaDetalleProcesamientoSat">
          <thead><tr><th>TIPO</th><th>UUID</th><th>FECHA</th><th>INTENTOS</th><th>PAQUETE</th><th>MOTIVO</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="small text-muted mt-2" id="detalleProcesamientoNota"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button></div>
  </div></div>
</div>

<div id="satContextMenu" class="sat-context-menu" role="menu" aria-hidden="true">
  <button type="button" id="satCtxReprocesar"><i class="bi bi-arrow-repeat me-2"></i>Reprocesar ZIP</button>
</div>

<script>
(function(){
  let tablaSat=null;
  const modalNueva=new bootstrap.Modal(document.getElementById('modalNuevaSolicitudSat'));
  const modalDetalle=new bootstrap.Modal(document.getElementById('modalDetalleProcesamientoSat'));
  const modalMetadata=new bootstrap.Modal(document.getElementById('modalMetadataManual'));
  const modalCancelados=new bootstrap.Modal(document.getElementById('modalCanceladosSat'));
  let tablaCancelados=null;
  let metadataJobId='';
  let metadataActivo=false;
  let idDetalleActual=0;
  function esc(v){return $('<div>').text(v??'').html()}
  function badgeEstatus(tipo,texto){const c={0:'bg-secondary',1:'bg-info text-dark',2:'bg-success',3:'bg-danger',4:'bg-dark'};return `<span class="badge badge-sat ${c[tipo]||'bg-secondary'}">${esc(texto)}</span>`}
  function badgeFlujo(estado,texto,clickable=false,id=0,titulo=''){const iconos={ok:'bi-check-circle-fill',proceso:'bi-arrow-repeat',pendiente:'bi-clock',error:'bi-exclamation-triangle-fill',cancelada:'bi-slash-circle'};const e=['ok','proceso','pendiente','error','cancelada'].includes(estado)?estado:'pendiente';const ayuda=titulo||texto;return `<span class="badge-flujo flujo-${e}${clickable?' proceso-click':''}" ${clickable?`data-id="${id}"`:''} title="${esc(ayuda)}${clickable?' · clic para ver detalle':''}"><i class="bi ${iconos[e]}"></i>${esc(texto)}</span>`}
  function actualizarResumenEstados(rows){let ok=0,proceso=0,pendiente=0,cerrado=0,error=0;(rows||[]).forEach(r=>{if(r.proceso_estado==='ok')ok++;else if(r.proceso_estado==='proceso')proceso++;else if(r.proceso_estado==='cancelada')cerrado++;else if(r.proceso_estado==='error')error++;else pendiente++});$('#resCompleto').text(ok);$('#resProceso').text(proceso);$('#resPendiente').text(pendiente);$('#resCerrado').text(cerrado);$('#resError').text(error)}
  function parametros(){return{tipo:$('#satTipo').val(),paquete_tipo:$('#satPaquete').val(),estatus:$('#satEstatus').val(),fecha_inicio:$('#satFechaInicio').val(),fecha_fin:$('#satFechaFin').val(),buscar:$('#satBuscar').val().trim()}}
  let satContextRow=null;
  function ocultarMenuSat(){ $('#satContextMenu').hide().attr('aria-hidden','true'); }
  function abrirReprocesoSat(row){
    if(!row||!row.id)return;
    Swal.fire({
      title:'Reprocesar todos los ZIP',
      html:`<div class="text-start small mb-2">Solicitud <b>${esc(row.id_solicitud||row.id)}</b></div><div class="text-start small text-muted">Se marcarán <b>todos los ZIP guardados de esta solicitud</b> para volver a procesarse. <b>No se solicitarán ni descargarán otra vez del SAT.</b></div>`,
      icon:'question',
      showCancelButton:true,
      confirmButtonText:'Sí, reprocesar todo',
      cancelButtonText:'Cancelar',
      confirmButtonColor:'#0d6efd'
    }).then(x=>{
      if(!x.isConfirmed)return;
      $.post('ajax/reprocesar_zip_sat.php',{id_descarga:Number(row.id)},null,'json').done(y=>{
        if(!y.success){Swal.fire('No se pudo',y.error||'No fue posible marcar los ZIP para reproceso.','error');return}
        Swal.fire('Listo',y.message||'Todos los ZIP fueron marcados para reprocesar. El Worker los tomará en la siguiente vuelta.','success');
        cargar();
      }).fail(z=>Swal.fire('Error',z.responseJSON?.error||'No fue posible preparar el reproceso.','error'));
    });
  }
  function cargar(){const p=parametros();if(p.fecha_inicio&&p.fecha_fin&&p.fecha_inicio>p.fecha_fin){Swal.fire('Rango incorrecto','La fecha de solicitud desde no puede ser mayor que la fecha de solicitud hasta.','warning');return}$.getJSON('ajax/listar_solicitudes_sat.php',p).done(r=>{if(!r.success){Swal.fire('Error',r.error||'No se pudieron consultar las solicitudes.','error');return}actualizarResumenEstados(r.data||[]);tablaSat.clear().rows.add(r.data||[]).draw()}).fail(x=>{if(x.status===401){location.href='index.php';return}Swal.fire('Error','No fue posible conectarse con el servidor.','error')})}
  tablaSat=$('#tablaSolicitudesSat').DataTable({data:[],pageLength:15,lengthMenu:[[15,30,50,100],[15,30,50,100]],order:[[0,'desc']],language:{search:'Buscar en resultados:',lengthMenu:'Mostrar _MENU_',info:'Mostrando _START_ a _END_ de _TOTAL_ solicitudes',infoEmpty:'No hay solicitudes',zeroRecords:'No se encontraron solicitudes',paginate:{previous:'Anterior',next:'Siguiente'}},columns:[
    {data:'id',className:'text-center'},{data:'fecha_registro',className:'text-center',render:d=>`<span class="text-nowrap" title="Fecha y hora en que se creó la solicitud">${esc(d||'-')}</span>`},{data:'id_solicitud',render:d=>`<span title="${esc(d)}">${esc(d)}</span>`},{data:'origen',className:'text-center',render:d=>`<span class="sat-origen-${String(d).toLowerCase()}">${esc(d)}</span>`},{data:'tipo',className:'text-center'},{data:'paquete_tipo',className:'text-center'},{data:'fecha_inicio',className:'text-center'},{data:'fecha_fin',className:'text-center'},{data:null,className:'text-center',render:r=>badgeEstatus(r.estatus,r.estatus_texto)},{data:null,className:'text-center',render:r=>badgeFlujo(r.descarga_estado,r.descarga_texto)},{data:null,className:'text-center',render:r=>badgeFlujo(r.proceso_estado,r.proceso_texto,Number(r.total_archivos||0)>0,r.id,r.proceso_titulo||'')},{data:'mensaje_sat',render:d=>`<div class="mensaje-sat" title="${esc(d||'-')}">${esc(d||'-')}</div>`},{data:null,orderable:false,searchable:false,className:'text-center acciones-sat',render:r=>r.puede_cancelar?`<button class="btn btn-sm btn-cancelar-sat btnCancelarSolicitudSat" data-id="${r.id}" title="Cancelar sin borrar"><i class="bi bi-ban"></i> Cancelar</button>`:'—'}
  ],rowCallback:function(row,data){$(row).removeClass('fila-cerrada fila-completa');if(data.proceso_estado==='cancelada')$(row).addClass('fila-cerrada');else if(data.proceso_estado==='ok')$(row).addClass('fila-completa')}});
  $('#btnFiltrarSat,#btnActualizarSolicitudes').on('click',cargar);$('#satTipo,#satPaquete,#satEstatus').on('change',cargar);$('#satBuscar').on('keydown',e=>{if(e.key==='Enter')cargar()});$('#btnLimpiarSat').on('click',()=>{$('#satTipo,#satPaquete,#satEstatus,#satFechaInicio,#satFechaFin,#satBuscar').val('');cargar()});
  function actualizarFiltroMetadata(){const esMetadata=$('#nuevaSatPaquete').val()==='metadata';$('#bloqueEstadoMetadata').toggleClass('d-none',!esMetadata);if(!esMetadata)$('#nuevaSatEstadoMetadata').val('todos')}
  $('#nuevaSatPaquete').on('change',actualizarFiltroMetadata);
  $('#btnNuevaSolicitudSat').on('click',()=>{const hoy=new Date().toISOString().slice(0,10);$('#formNuevaSolicitudSat')[0].reset();$('#nuevaSatDesde').val(hoy);$('#nuevaSatHasta').val(hoy);actualizarFiltroMetadata();modalNueva.show()});
  $('#formNuevaSolicitudSat').on('submit',function(e){e.preventDefault();const desde=$('#nuevaSatDesde').val(),hasta=$('#nuevaSatHasta').val();if(!desde||!hasta||desde>hasta){Swal.fire('Rango incorrecto','Revise las fechas de la solicitud.','warning');return}const fd=new FormData(this);const b=$('#btnGenerarSolicitudes').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Generando...');$.ajax({url:'ajax/crear_solicitudes_sat.php',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(r=>{if(!r.success){Swal.fire('No se generaron',r.error||'Ocurrió un error.','error');return}modalNueva.hide();Swal.fire('Solicitudes registradas',r.message,'success');cargar()}).fail(x=>{Swal.fire('Error',x.responseJSON?.error||'No fue posible registrar las solicitudes.','error')}).always(()=>b.prop('disabled',false).html('<i class="bi bi-cloud-arrow-down"></i> Generar solicitudes'))});

  function initCanceladosSat(){
    if(tablaCancelados){tablaCancelados.ajax.reload(null,false);return;}
    tablaCancelados=$('#tablaCanceladosSat').DataTable({
      processing:true,serverSide:true,searchDelay:350,scrollX:true,pageLength:25,lengthMenu:[[25,50,100,250],[25,50,100,250]],order:[[3,'desc']],
      ajax:{url:'ajax/listar_cancelados_sat.php',type:'GET',data:function(d){d.tipo=$('#canTipo').val();d.xml=$('#canXml').val();d.desde=$('#canDesde').val();d.hasta=$('#canHasta').val();},dataSrc:function(j){if(j.error){Swal.fire('Error',j.error,'error');return [];}const r=j.resumen||{};$('#canTotal').text(mmNumero(r.total));$('#canConXml').text(mmNumero(r.con_xml));$('#canSinXml').text(mmNumero(r.sin_xml));$('#canEmitidos').text(mmNumero(r.emitidos));$('#canRecibidos').text(mmNumero(r.recibidos));return j.data||[];}},
      language:{processing:'Consultando cancelados...',search:'Buscar:',lengthMenu:'Mostrar _MENU_',info:'Mostrando _START_ a _END_ de _TOTAL_ cancelados',infoEmpty:'Sin cancelados',infoFiltered:'(filtrado de _MAX_)',zeroRecords:'No se encontraron cancelados',paginate:{previous:'Anterior',next:'Siguiente'}},
      columns:[
        {data:'uuid',render:v=>`<span class="uuid-cancelado">${esc(v)}</span>`},
        {data:'tipo',className:'text-center'},
        {data:'fecha_emision',className:'text-nowrap'},
        {data:'fecha_cancelacion',className:'text-nowrap',render:v=>`<span class="text-danger fw-bold">${esc(v||'-')}</span>`},
        {data:'rfc_emisor',className:'text-nowrap'},
        {data:'nombre_emisor'},
        {data:'rfc_receptor',className:'text-nowrap'},
        {data:'nombre_receptor'},
        {data:'monto',className:'text-end text-nowrap',render:v=>v===null?'':'$'+Number(v).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})},
        {data:null,className:'text-center',orderable:false,render:r=>r.xml_disponible?'<span class="xml-si"><i class="bi bi-file-earmark-code-fill"></i> Con XML</span>':(r.factura_en_visor?'<span class="text-warning fw-bold"><i class="bi bi-exclamation-circle"></i> Registro sin XML</span>':'<span class="xml-no"><i class="bi bi-file-earmark-x"></i> Sin XML</span>')},
        {data:'revisado',className:'text-nowrap'},
        {data:null,className:'text-center',orderable:false,searchable:false,render:r=>r.xml_disponible?`<button type="button" class="btn btn-outline-primary btn-sm btnAbrirXmlCancelado" data-uuid="${esc(r.uuid)}" title="Abrir XML del visor"><i class="bi bi-file-earmark-code"></i> Ver XML</button>`:'<span class="text-muted">—</span>'}
      ]
    });
  }
  $('#btnCanceladosSat').on('click',function(){modalCancelados.show();setTimeout(initCanceladosSat,120);});
  $('#btnFiltrarCancelados,#btnRefrescarCancelados').on('click',function(){if(tablaCancelados)tablaCancelados.ajax.reload();});
  $('#btnExportarCancelados').on('click',function(){
    const q=tablaCancelados?tablaCancelados.search():'';
    const p=new URLSearchParams({tipo:$('#canTipo').val()||'',xml:$('#canXml').val()||'',desde:$('#canDesde').val()||'',hasta:$('#canHasta').val()||'',buscar:q||''});
    window.location.href='ajax/exportar_cancelados_sat.php?'+p.toString();
  });
  $('#canTipo,#canXml').on('change',function(){if(tablaCancelados)tablaCancelados.ajax.reload();});
  $('#btnLimpiarCancelados').on('click',function(){$('#canTipo,#canXml,#canDesde,#canHasta').val('');if(tablaCancelados){tablaCancelados.search('').ajax.reload();}});
  $('#tablaCanceladosSat').on('click','.btnAbrirXmlCancelado',function(){const uuid=String($(this).data('uuid')||'');if(uuid)window.open('ajax/visor_xml.php?uuid='+encodeURIComponent(uuid)+'&_='+Date.now(),'_blank');});

  function mmNumero(v){return Number(v||0).toLocaleString('es-MX')}
  function pintarMetadataJob(j){
    const pct=Math.max(0,Math.min(100,Number(j.porcentaje||0)));
    $('#metadataManualPct').text(pct.toFixed(1)+'%');
    $('#metadataManualBarra').css('width',pct+'%').text(pct.toFixed(1)+'%');
    $('#metadataManualEstado').text(j.mensaje||j.estado||'Procesando...');
    $('#mmLeidos').text(mmNumero(j.leidos));$('#mmEncontrados').text(mmNumero(j.encontrados));$('#mmCancelados').text(mmNumero(j.cancelados_aplicados));$('#mmNoEncontrados').text(mmNumero(j.no_encontrados));
    $('#metadataManualDetalle').html(`Vigentes aplicados: <b>${mmNumero(j.vigentes_aplicados)}</b> · Sin cambio: <b>${mmNumero(j.sin_cambio)}</b> · Omitidos por RFC de otra empresa: <b>${mmNumero(j.omitidos_empresa)}</b> · Errores: <b>${mmNumero(j.errores)}</b>`);
    if(j.estado==='completo'){$('#metadataManualBarra').removeClass('progress-bar-animated').addClass('bg-success');metadataActivo=false;$('#btnCancelarMetadataManual').addClass('d-none');$('#btnIniciarMetadataManual').prop('disabled',false).html('<i class="bi bi-play-circle"></i> Procesar otro archivo')}
    else if(j.estado==='cancelado'){$('#metadataManualBarra').removeClass('progress-bar-animated').addClass('bg-secondary');metadataActivo=false;$('#btnCancelarMetadataManual').addClass('d-none');$('#btnIniciarMetadataManual').prop('disabled',false).html('<i class="bi bi-play-circle"></i> Procesar otro archivo')}
    else if(j.estado==='error'){$('#metadataManualBarra').removeClass('progress-bar-animated').addClass('bg-danger');metadataActivo=false;$('#btnCancelarMetadataManual').addClass('d-none');$('#btnIniciarMetadataManual').prop('disabled',false)}
  }
  function pasoMetadataManual(){
    if(!metadataActivo||!metadataJobId)return;
    $.post('ajax/metadata_manual_procesar.php',{job_id:metadataJobId},null,'json').done(r=>{
      if(!r.success){metadataActivo=false;Swal.fire('Error',r.error||'No fue posible procesar la metadata.','error');return}
      pintarMetadataJob(r.job||{});
      if(r.job&&r.job.estado==='procesando')setTimeout(pasoMetadataManual,80);
      else if(r.job&&r.job.estado==='completo')Swal.fire('Metadata procesada',`Leídos: ${mmNumero(r.job.leidos)} · Cancelados aplicados: ${mmNumero(r.job.cancelados_aplicados)} · No encontrados: ${mmNumero(r.job.no_encontrados)}`,'success');
    }).fail(x=>{metadataActivo=false;$('#btnCancelarMetadataManual').addClass('d-none');$('#btnIniciarMetadataManual').prop('disabled',false);Swal.fire('Error',x.responseJSON?.error||'No fue posible continuar el procesamiento.','error')});
  }
  $('#btnProcesarMetadataManual').on('click',()=>{
    if(metadataActivo){modalMetadata.show();return}
    metadataJobId='';$('#formMetadataManual')[0].reset();$('#metadataManualProceso').addClass('d-none');$('#metadataManualBarra').attr('class','progress-bar progress-bar-striped progress-bar-animated').css('width','0%').text('0%');$('#btnCancelarMetadataManual').addClass('d-none');$('#btnIniciarMetadataManual').prop('disabled',false).html('<i class="bi bi-play-circle"></i> Procesar archivo');modalMetadata.show();
  });
  $('#btnIniciarMetadataManual').on('click',function(){
    const input=document.getElementById('archivoMetadataManual');if(!input.files||!input.files.length){Swal.fire('Falta archivo','Seleccione el TXT de metadata ya desempacado.','warning');return}
    const fd=new FormData(document.getElementById('formMetadataManual'));const b=$(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Preparando...');
    $.ajax({url:'ajax/metadata_manual_iniciar.php',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(r=>{
      if(!r.success){Swal.fire('No se inició',r.error||'No fue posible iniciar.','error');return}
      metadataJobId=r.job.job_id;metadataActivo=true;$('#metadataManualProceso').removeClass('d-none');$('#btnCancelarMetadataManual').removeClass('d-none');b.html('<i class="bi bi-hourglass-split"></i> Procesando...');pintarMetadataJob(r.job);setTimeout(pasoMetadataManual,50);
    }).fail(x=>{
      let msg=x.responseJSON?.error||'';
      if(!msg&&x.responseText){
        try{const j=JSON.parse(x.responseText);msg=j.error||j.message||''}catch(e){
          const limpio=String(x.responseText).replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
          if(limpio)msg=limpio.substring(0,600);
        }
      }
      if(!msg)msg=`No fue posible cargar el archivo. HTTP ${x.status||0}.`;
      Swal.fire({title:'Error al cargar metadata',text:msg,icon:'error',zIndex:20060});
    }).always(()=>{if(!metadataActivo)b.prop('disabled',false).html('<i class="bi bi-play-circle"></i> Procesar archivo')});
  });
  $('#btnCancelarMetadataManual').on('click',function(){
    if(!metadataJobId||!metadataActivo)return;
    Swal.fire({title:'Cancelar procesamiento',text:'Se terminará el bloque actual y se detendrá el proceso de forma segura.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, cancelar',cancelButtonText:'Continuar',confirmButtonColor:'#dc3545'}).then(r=>{if(!r.isConfirmed)return;$(this).prop('disabled',true);$.post('ajax/metadata_manual_cancelar.php',{job_id:metadataJobId},null,'json').done(x=>{if(x.success){$('#metadataManualEstado').text('Cancelación solicitada...')}else Swal.fire('Error',x.error||'No fue posible cancelar.','error')}).always(()=>$(this).prop('disabled',false))});
  });
  $('#modalMetadataManual').on('hide.bs.modal',function(e){if(metadataActivo){e.preventDefault();Swal.fire('Proceso en curso','Para cerrar primero cancele el procesamiento o espere a que termine.','info')}});

  function cargarDetalleProcesamiento(tipo='todos'){
    if(!idDetalleActual)return;
    $('#tablaDetalleProcesamientoSat tbody').html('<tr><td colspan="6" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Consultando...</td></tr>');
    $.getJSON('ajax/detalle_procesamiento_sat.php',{id:idDetalleActual,tipo_detalle:tipo}).done(r=>{
      if(!r.success){Swal.fire('Error',r.error||'No se pudo consultar el detalle.','error');return}
      const s=r.solicitud||{},k=r.resumen||{};
      $('#detalleProcesamientoCabecera').html(`<strong>Solicitud ${esc(s.id)}</strong> · ${esc(s.tipo)} ${esc(s.paquete_tipo)} · ${esc(s.fecha_inicio)} al ${esc(s.fecha_fin)} · ${esc(s.origen)}<br><small>ID SAT: ${esc(s.id_solicitud)}</small>`);
      $('#kpiProcOk').text(Number(k.procesados||0).toLocaleString());$('#kpiProcDup').text(Number(k.duplicados||0).toLocaleString());$('#kpiProcErr').text(Number(k.errores||0).toLocaleString());$('#kpiProcPend').text(Number(k.pendientes||0).toLocaleString());
      const rows=(r.data||[]).map(x=>{const tipo=String(x.tipo_resultado||'');const cls=tipo==='Duplicado'?'text-success':(tipo==='Error'||tipo==='Rechazado'?'text-danger':(tipo==='Procesado'?'text-primary':'text-muted'));return `<tr><td class="${cls} fw-bold">${esc(tipo)}</td><td><code>${esc(x.uuid_mostrar||'-')}</code></td><td class="text-nowrap">${esc(x.fecha||'-')}</td><td class="text-center">${esc(x.intentos||0)}</td><td title="${esc(x.id_paquete_sat||'')}">${esc(String(x.id_paquete_sat||'').slice(0,18))}${String(x.id_paquete_sat||'').length>18?'…':''}</td><td>${esc(x.mensaje||'-')}</td></tr>`}).join('');
      $('#tablaDetalleProcesamientoSat tbody').html(rows||'<tr><td colspan="6" class="text-center py-4 text-muted">No hay registros para este filtro.</td></tr>');
      $('#detalleProcesamientoNota').text(r.limitado?'Se muestran los 5,000 registros más recientes. Use los filtros del modal para acotar el detalle.':'Duplicados son informativos y no se consideran errores.');
    }).fail(x=>Swal.fire('Error',x.responseJSON?.error||'No fue posible consultar el detalle.','error'));
  }
  $('#tablaSolicitudesSat tbody').on('click','.proceso-click',function(){idDetalleActual=Number($(this).data('id')||0);$('.btnFiltroDetalle').removeClass('active');$('.btnFiltroDetalle[data-tipo="todos"]').addClass('active');modalDetalle.show();cargarDetalleProcesamiento('todos')});
  $('.btnFiltroDetalle').on('click',function(){$('.btnFiltroDetalle').removeClass('active');$(this).addClass('active');cargarDetalleProcesamiento($(this).data('tipo'))});
  $('#tablaSolicitudesSat tbody').on('click','.btnCancelarSolicitudSat',function(){const id=$(this).data('id');Swal.fire({title:'Cancelar solicitud',text:'No se borrará. Quedará registrada como cancelada.',input:'textarea',inputLabel:'Motivo de cancelación',inputPlaceholder:'Capture el motivo...',showCancelButton:true,confirmButtonText:'Cancelar solicitud',cancelButtonText:'Regresar',confirmButtonColor:'#dc3545',preConfirm:v=>{if(!String(v||'').trim()){Swal.showValidationMessage('Capture el motivo de cancelación.');return false}return String(v).trim()}}).then(r=>{if(!r.isConfirmed)return;$.post('ajax/cancelar_solicitud_sat.php',{id:id,motivo_cancelacion:r.value},null,'json').done(x=>{if(!x.success){Swal.fire('No se canceló',x.error||'No fue posible cancelar.','error');return}Swal.fire('Cancelada',x.message,'success');cargar()}).fail(x=>Swal.fire('Error',x.responseJSON?.error||'No fue posible cancelar la solicitud.','error'))})});
  $('#tablaSolicitudesSat tbody').on('contextmenu','tr',function(e){
    const row=tablaSat.row(this).data();
    if(!row)return;
    e.preventDefault();
    satContextRow=row;
    $('#satContextMenu').css({left:Math.min(e.clientX,window.innerWidth-230)+'px',top:Math.min(e.clientY,window.innerHeight-70)+'px'}).show().attr('aria-hidden','false');
  });
  $('#satCtxReprocesar').on('click',function(){const r=satContextRow;ocultarMenuSat();abrirReprocesoSat(r)});
  $(document).on('click scroll',function(e){if(!$(e.target).closest('#satContextMenu').length)ocultarMenuSat()});
  $(document).on('keydown',function(e){if(e.key==='Escape')ocultarMenuSat()});
  cargar();
})();
</script>
