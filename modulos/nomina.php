<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
seguridad_exigir_sesion($pdo, true, false, true);
$idUsuario=(int)($_SESSION['id_usuario']??0);$idEmpresa=(int)($_SESSION['id_empresa']??0);
try{exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'nomina_archivo');}catch(Throwable $e){http_response_code(403);echo '<div class="alert alert-danger">No tienes permiso para consultar/importar Nómina en esta empresa.</div>';exit;}
$st=$pdo->prepare('SELECT razon_social,rfc FROM empresas WHERE id_empresa=? LIMIT 1');$st->execute([$idEmpresa]);$empresa=$st->fetch(PDO::FETCH_ASSOC)?:[];
$puedeBorrarConciliacion = !empty($_SESSION['es_superadmin']);
if (empty($_SESSION['nomina_campos_csrf'])) $_SESSION['nomina_campos_csrf']=bin2hex(random_bytes(32));
?>
<style>
.nomina-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.nomina-card{background:#fff;border:1px solid #d6e2ea;border-radius:8px;padding:14px;color:#173244}.nomina-drop{border:2px dashed #87a9be;border-radius:8px;padding:18px;text-align:center;background:#f7fbfd}.nomina-kpis{display:grid;grid-template-columns:repeat(8,minmax(115px,1fr));gap:8px;margin:12px 0}.nomina-kpi{border:1px solid #d8e5ed;border-radius:7px;padding:8px 10px;background:#f8fbfd}.nomina-kpi small{display:block;color:#587284;font-weight:700;font-size:.68rem;text-transform:uppercase}.nomina-kpi b{font-size:.88rem}.nomina-ok{color:#198754}.nomina-bad{color:#dc3545}.nomina-warn{color:#b36b00}.nomina-filtros{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.nomina-filtros .form-control{min-width:110px}.nomina-filtros label{font-size:.68rem;font-weight:700;color:#38586c}.nomina-tabla{font-size:.74rem}.nomina-tabla th{white-space:nowrap}.nomina-tabla td{vertical-align:middle}.nomina-conc-kpis{display:grid;grid-template-columns:repeat(9,minmax(105px,1fr));gap:7px;margin:10px 0}.nomina-conc-kpi{border:1px solid #d8e5ed;border-radius:7px;padding:7px 9px;background:#f8fbfd}.nomina-conc-kpi small{display:block;color:#587284;font-weight:700;font-size:.62rem;text-transform:uppercase}.nomina-conc-kpi b{font-size:.82rem}.nomina-money{text-align:right;white-space:nowrap}.nomina-conc-row{cursor:pointer}.nomina-conc-row:hover td{background:#eaf5fb!important}.nc-emp-kpis{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:8px}.nc-emp-kpi{border:1px solid #d8e5ed;border-radius:7px;padding:8px 10px;background:#f8fbfd}.nc-emp-kpi small{display:block;color:#587284;font-weight:700;font-size:.65rem;text-transform:uppercase}.nc-emp-kpi b{font-size:.84rem}.nc-cfdi-table{font-size:.72rem}.nc-cfdi-table th{white-space:nowrap}.btnBorrarNomina{background:#dc3545!important;color:#fff!important;border-color:#b02a37!important;opacity:1!important;visibility:visible!important;font-weight:700!important}.btnBorrarNomina:hover{background:#bb2d3b!important;color:#fff!important;border-color:#b02a37!important}@media(max-width:1100px){.nomina-kpis{grid-template-columns:repeat(3,1fr)}.nomina-conc-kpis{grid-template-columns:repeat(3,1fr)}}
</style>

<div class="nomina-head">
 <div><span class="fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>NÓMINA</span><span class="text-muted ms-2 small">Importación semanal y acumulada por periodos; visor de percepciones, deducciones y netos por empleado.</span></div>
</div>

<ul class="nav nav-tabs mb-3" id="tabsNomina">
 <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#nomina-visor" type="button"><i class="bi bi-table me-1"></i>VISOR NÓMINA</button></li>
 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#nomina-conciliacion" type="button"><i class="bi bi-arrow-left-right me-1"></i>CONCILIACIÓN</button></li>
 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#nomina-conceptos" type="button"><i class="bi bi-list-check me-1"></i>CONCILIACIÓN POR CAMPOS</button></li>
 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#nomina-importar" type="button"><i class="bi bi-file-earmark-arrow-up me-1"></i>IMPORTAR ARCHIVO</button></li>
 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#nomina-config-campos" type="button"><i class="bi bi-sliders me-1"></i>CONFIGURACIÓN DE CAMPOS</button></li>
</ul>

<div class="tab-content">
 <div class="tab-pane fade" id="nomina-config-campos"></div>
 <div class="tab-pane fade" id="nomina-conceptos"></div>
 <div class="tab-pane fade" id="nomina-importar">
  <div class="nomina-card">
   <div class="nomina-drop">
    <div class="fw-bold mb-1"><i class="bi bi-file-earmark-excel text-success me-1"></i>Listado de Nómina (.xls / .xlsx)</div>
    <div class="small text-muted mb-3">Soporta nómina <b>semanal</b> y <b>acumulada por periodos</b>. En acumulados se detectan AÑO, PERIODO INICIAL/FINAL, TOTALPER y TOTALDED aunque cambien de columna; las fechas reales se capturan antes de importar. Se conservarán también todas las columnas, cada renglón y el archivo original para el futuro cruce por conceptos.</div>
    <div class="d-flex flex-wrap gap-2 justify-content-center align-items-center">
     <input type="file" id="nominaArchivo" class="form-control form-control-sm" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="max-width:520px">
     <button class="btn btn-primary btn-sm" id="btnNominaPreview"><i class="bi bi-eye me-1"></i>PREVISUALIZAR</button>
    </div>
   </div>

   <div id="nominaResultado" class="mt-3" style="display:none">
    <div id="nominaValidacion" class="mb-2"></div>
    <div class="nomina-kpis">
     <div class="nomina-kpi"><small>Empresa archivo</small><b id="nEmpresa">—</b></div>
     <div class="nomina-kpi"><small>RFC</small><b id="nRfc">—</b></div>
     <div class="nomina-kpi"><small>Año / Periodo(s)</small><b id="nPeriodo">—</b></div>
     <div class="nomina-kpi"><small>Rango fechas</small><b id="nRango">—</b></div>
     <div class="nomina-kpi"><small>Empleados</small><b id="nEmpleados">0</b></div>
     <div class="nomina-kpi"><small>Percepciones</small><b id="nPercepciones">$0.00</b></div>
     <div class="nomina-kpi"><small>Deducciones</small><b id="nDeducciones">$0.00</b></div>
     <div class="nomina-kpi"><small>Total Neto</small><b id="nTotal">$0.00</b></div>
    </div>
    <div class="row g-2 mb-3 align-items-end">
     <div class="col-md-3"><label class="form-label small fw-bold mb-1">TIPO DE NÓMINA</label><select id="nTipoNomina" class="form-select form-select-sm"><option value="SEMANAL" selected>SEMANAL</option><option value="ACUMULADO_PERIODOS">ACUMULADO POR PERIODOS</option><option value="FINIQUITO">FINIQUITO</option><option value="AGUINALDO">AGUINALDO</option><option value="EXTRAORDINARIA">EXTRAORDINARIA</option><option value="PTU">PTU</option><option value="PRIMA_VACACIONAL">PRIMA VACACIONAL</option><option value="OTRO">OTRO</option></select></div>
     <div class="col-md-3"><label class="form-label small fw-bold mb-1">REFERENCIA</label><input id="nReferenciaNomina" class="form-control form-control-sm" placeholder="Se genera automáticamente"></div>
     <div class="col-md-6"><label class="form-label small fw-bold mb-1">OBSERVACIONES</label><input id="nObservaciones" class="form-control form-control-sm" maxlength="500" placeholder="Opcional"></div>
    </div>
    <div id="nFechasAcumulado" class="alert alert-warning py-2 mb-3" style="display:none">
     <div class="fw-bold mb-2"><i class="bi bi-calendar-range me-1"></i>CALENDARIO DE PERIODOS</div>
     <div class="small mb-2">Capture una sola vez la <b>fecha de inicio del PERIODO 1</b>. Queda guardada por empresa y año, puede cambiarla cuando sea necesario y el sistema calcula automáticamente Del/Al de todos los periodos.</div>
     <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small fw-bold mb-1">FECHA INICIO PERIODO 1 <span id="nFechaPeriodo1Guardada" class="badge bg-success ms-1" style="display:none">GUARDADA</span></label><input type="date" id="nFechaPeriodo1" class="form-control form-control-sm"></div>
      <div class="col-md-3"><label class="form-label small fw-bold mb-1">RANGO DEL ARCHIVO</label><input type="text" id="nRangoCalculado" class="form-control form-control-sm" readonly></div>
      <div class="col-md-6"><span class="small text-muted">Base semanal de 7 días. Al importar, esta fecha queda fija para la empresa/año y se reutiliza en los siguientes archivos.</span></div>
     </div>
     <div id="nPeriodosCalculados" class="mt-2"></div>
     <input type="hidden" id="nFechaDesde"><input type="hidden" id="nFechaHasta">
    </div>
    <div class="table-responsive" style="max-height:46vh;overflow:auto">
     <table class="table table-sm table-striped table-hover nomina-tabla mb-0" id="tablaNominaPreview">
      <thead class="table-info" style="position:sticky;top:0;z-index:2"><tr><th>Renglón(es)</th><th>Tipo(s) procesado(s)</th><th>No. empleado</th><th>Nombre</th><th>Apellido paterno</th><th>Apellido materno</th><th>RFC</th><th class="text-end">Percepciones</th><th class="text-end">Deducciones</th><th class="text-end">Neto</th></tr></thead><tbody></tbody>
     </table>
    </div>
    <div class="d-flex justify-content-end mt-3"><button id="btnNominaImportar" class="btn btn-success" disabled><i class="bi bi-database-check me-1"></i>IMPORTAR NÓMINA</button></div>
   </div>
  </div>
 </div>

 <div class="tab-pane fade show active" id="nomina-visor">
  <div class="nomina-card mb-3">
   <div class="nomina-filtros">
    <div><label>AÑO</label><input type="number" id="nvAnio" class="form-control form-control-sm" min="2000" max="2100"></div>
    <div><label>MES</label><select id="nvMes" class="form-select form-select-sm">
     <option value="1">ENERO</option><option value="2">FEBRERO</option><option value="3">MARZO</option><option value="4">ABRIL</option><option value="5">MAYO</option><option value="6">JUNIO</option>
     <option value="7">JULIO</option><option value="8">AGOSTO</option><option value="9">SEPTIEMBRE</option><option value="10">OCTUBRE</option><option value="11">NOVIEMBRE</option><option value="12">DICIEMBRE</option>
    </select></div>
    <div><label>DESDE</label><input type="date" id="nvDesde" class="form-control form-control-sm"></div>
    <div><label>HASTA</label><input type="date" id="nvHasta" class="form-control form-control-sm"></div>
    <div><label>TIPO</label><select id="nvTipo" class="form-select form-select-sm"><option value="">TODOS</option><option>SEMANAL</option><option value="ACUMULADO_PERIODOS">ACUMULADO POR PERIODOS</option><option>FINIQUITO</option><option>AGUINALDO</option><option>EXTRAORDINARIA</option><option>PTU</option><option value="PRIMA_VACACIONAL">PRIMA VACACIONAL</option><option>OTRO</option></select></div>
    <button class="btn btn-primary btn-sm" id="btnNvFiltrar"><i class="bi bi-funnel-fill me-1"></i>FILTRAR</button>
    <button class="btn btn-outline-secondary btn-sm" id="btnNvLimpiar">LIMPIAR</button>
   </div>
  </div>
  <div class="nomina-card mb-3">
   <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-bold"><i class="bi bi-clock-history me-1"></i>Periodos importados</div><div class="small text-muted"><i class="bi bi-trash3 text-danger me-1"></i>Use <b>FECHAS</b> para corregir Del/Al o <b>BORRAR</b> para eliminar toda una nómina importada.</div></div>
   <div class="table-responsive"><table class="table table-sm table-striped nomina-tabla w-100" id="tablaNominaPeriodos"><thead><tr><th>Acciones</th><th>Año</th><th>Periodo(s)</th><th>Tipo</th><th>Referencia</th><th>Del</th><th>Al</th><th>Empleados</th><th>Percepciones</th><th>Deducciones</th><th>Neto</th><th>Archivo</th><th>Estatus</th><th>Importado</th></tr></thead></table></div>
  </div>
  <div class="nomina-card">
   <div class="fw-bold mb-2"><i class="bi bi-person-lines-fill me-1"></i>Detalle de empleados</div>
   <div class="table-responsive"><table class="table table-sm table-striped table-hover nomina-tabla w-100" id="tablaNominaVisor"><thead><tr><th>Año</th><th>Periodo(s)</th><th>Tipo</th><th>Del</th><th>Al</th><th>No. empleado</th><th>Nombre</th><th>Apellido paterno</th><th>Apellido materno</th><th>RFC</th><th>Percepciones</th><th>Deducciones</th><th>Neto</th></tr></thead></table></div>
  </div>
 </div>

 <div class="tab-pane fade" id="nomina-conciliacion">
  <div class="nomina-card mb-3">
   <div class="d-flex justify-content-between align-items-center mb-2">
    <div><div class="fw-bold"><i class="bi bi-arrow-left-right me-1"></i>Conciliación Nómina vs CFDI del visor</div><div class="small text-muted">Compara por RFC usando el periodo trabajado del complemento Nómina (FechaInicialPago/FechaFinalPago), no la fecha de emisión ni la fecha de pago.</div></div>
   </div>
   <div class="nomina-filtros">
    <div><label>AÑO</label><input type="number" id="ncAnio" class="form-control form-control-sm" min="2000" max="2100"></div>
    <div><label>MES</label><select id="ncMes" class="form-select form-select-sm">
     <option value="1">ENERO</option><option value="2">FEBRERO</option><option value="3">MARZO</option><option value="4">ABRIL</option><option value="5">MAYO</option><option value="6">JUNIO</option>
     <option value="7">JULIO</option><option value="8">AGOSTO</option><option value="9">SEPTIEMBRE</option><option value="10">OCTUBRE</option><option value="11">NOVIEMBRE</option><option value="12">DICIEMBRE</option>
    </select></div>
    <div><label>DESDE</label><input type="date" id="ncFechaDesde" class="form-control form-control-sm"></div>
    <div><label>HASTA</label><input type="date" id="ncFechaHasta" class="form-control form-control-sm"></div>
    <button class="btn btn-success btn-sm" id="btnNominaConciliar"><i class="bi bi-check2-circle me-1"></i>CONCILIAR</button>
   </div>
   <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>La conciliación es global. Año y mes llenan automáticamente el rango; Desde/Hasta pueden ajustarse manualmente. Se compara por RFC usando el período trabajado del CFDI de nómina.</div>
  </div>

  <div class="nomina-card mb-3" id="ncResumenCard" style="display:none">
   <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <div class="fw-bold"><i class="bi bi-speedometer2 me-1"></i>Resumen de conciliación <span id="ncRangoUsado" class="text-muted fw-normal small ms-2"></span></div>
    <div class="d-flex align-items-center gap-2">
     <button type="button" class="btn btn-success btn-sm" id="btnExportarNominaConciliacion" style="display:none"><i class="bi bi-file-earmark-excel me-1"></i>EXPORTAR EXCEL</button>
     <span id="ncIdActual" class="badge bg-secondary"></span>
    </div>
   </div>
   <div class="nomina-conc-kpis">
    <div class="nomina-conc-kpi"><small>Empleados reporte</small><b id="ncKRep">0</b></div>
    <div class="nomina-conc-kpi"><small>Empleados visor</small><b id="ncKVis">0</b></div>
    <div class="nomina-conc-kpi"><small>Conciliados</small><b id="ncKOk" class="nomina-ok">0</b></div>
    <div class="nomina-conc-kpi"><small>Con diferencia</small><b id="ncKDif" class="nomina-bad">0</b></div>
    <div class="nomina-conc-kpi"><small>Solo reporte</small><b id="ncKSoloRep" class="nomina-warn">0</b></div>
    <div class="nomina-conc-kpi"><small>Solo visor</small><b id="ncKSoloVis" class="nomina-warn">0</b></div>
    <div class="nomina-conc-kpi"><small>Total reporte</small><b id="ncKTotalRep">$0.00</b></div>
    <div class="nomina-conc-kpi"><small>Total visor</small><b id="ncKTotalVis">$0.00</b></div>
    <div class="nomina-conc-kpi"><small>Diferencia</small><b id="ncKTotalDif">$0.00</b></div>
   </div>
   <div class="table-responsive"><table class="table table-sm table-striped table-hover nomina-tabla w-100" id="tablaNominaConciliacion"><thead><tr><th>No. empleado</th><th>RFC</th><th>Nombre completo</th><th>Percep. visor</th><th>Percep. reporte</th><th>Dif. percep.</th><th>Deduc. visor</th><th>Deduc. reporte</th><th>Dif. deduc.</th><th>Neto visor</th><th>Neto reporte</th><th>Dif. neto</th><th>CFDI vigentes</th><th>Cancelados</th><th>Resultado</th></tr></thead><tbody></tbody></table></div>
   <div class="small text-muted mt-2"><i class="bi bi-mouse2 me-1"></i><b>Doble clic</b> sobre un empleado para ver su conciliación y todos los CFDI de nómina considerados en el rango.</div>
  </div>

  <div class="nomina-card">
   <div class="fw-bold mb-2"><i class="bi bi-clock-history me-1"></i>Conciliaciones realizadas</div>
   <div class="table-responsive"><table class="table table-sm table-striped table-hover nomina-tabla w-100" id="tablaNominaConciliacionesHist"><thead><tr><th>Acciones</th><th>Fecha</th><th>Año</th><th>Rango trabajado</th><th>Reporte</th><th>Visor</th><th>Diferencia</th><th>Conciliados</th><th>Dif.</th></tr></thead></table></div>
  </div>
 </div>
</div>

<div class="modal fade" id="modalNominaConcEmpleado" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-xl modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header bg-light">
    <div>
     <h5 class="modal-title mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>Detalle de conciliación por empleado</h5>
     <div class="small text-muted" id="ncEmpRango">—</div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
   </div>
   <div class="modal-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
     <span class="badge bg-primary" id="ncEmpNumero">Empleado —</span>
     <span class="fw-bold" id="ncEmpNombre">—</span>
     <span class="text-muted">RFC:</span><code id="ncEmpRfc">—</code>
     <span id="ncEmpEstatus"></span>
    </div>
    <div class="nc-emp-kpis mb-3">
     <div class="nc-emp-kpi"><small>Percepciones visor</small><b id="ncEmpPV">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Percepciones reporte</small><b id="ncEmpPR">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Dif. percepciones</small><b id="ncEmpPD">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Deducciones visor</small><b id="ncEmpDV">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Deducciones reporte</small><b id="ncEmpDR">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Dif. deducciones</small><b id="ncEmpDD">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Neto visor</small><b id="ncEmpNV">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Neto reporte</small><b id="ncEmpNR">$0.00</b></div>
     <div class="nc-emp-kpi"><small>Dif. neto</small><b id="ncEmpND">$0.00</b></div>
     <div class="nc-emp-kpi"><small>CFDI vigentes</small><b id="ncEmpCV">0</b></div>
     <div class="nc-emp-kpi"><small>CFDI cancelados</small><b id="ncEmpCC">0</b></div>
     <div class="nc-emp-kpi"><small>Otros pagos CFDI</small><b id="ncEmpOP">$0.00</b></div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
     <div class="fw-bold"><i class="bi bi-file-earmark-code me-1"></i>CFDI de nómina considerados en esta conciliación</div>
     <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
      <span class="small text-muted">Los cancelados se muestran para diagnóstico, pero no suman al importe del visor.</span>
      <button type="button" class="btn btn-sm btn-success" id="btnNcEmpExportarExcel" title="Exportar todos los CFDI mostrados de este empleado">
       <i class="bi bi-file-earmark-excel me-1"></i>EXPORTAR EXCEL
      </button>
     </div>
    </div>
    <div class="table-responsive" style="max-height:48vh;overflow:auto">
     <table class="table table-sm table-striped table-hover nc-cfdi-table mb-0">
      <thead class="table-info" style="position:sticky;top:0;z-index:2"><tr>
       <th>Acciones</th><th>UUID</th><th>Estatus</th><th>Serie/Folio</th><th>Fecha emisión</th><th>Periodo trabajado</th><th>Fecha pago</th><th>Tipo</th><th>No. empleado</th><th class="text-end">Percepciones</th><th class="text-end">Deducciones</th><th class="text-end">Otros pagos</th><th class="text-end">Neto CFDI</th>
      </tr></thead><tbody id="ncEmpCfdis"></tbody>
     </table>
    </div>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">CERRAR</button></div>
  </div>
 </div>
</div>

<script>
<?php readfile(__DIR__ . "/../includes/nomina_fuente_ui.js"); ?>
window.nominaCamposCsrf = <?= json_encode($_SESSION['nomina_campos_csrf']) ?>;
<?php readfile(__DIR__ . "/../includes/nomina_conceptos_ui.js"); ?>;
<?php readfile(__DIR__ . "/../includes/nomina_config_campos_ui.js"); ?>;
</script>
<script>
(function(){
 let previewToken=''; let previewValid=false; let previewRequiresDates=false; let previewMeta={}; let duplicate=null; let duplicates=[]; let tablaVisor=null; let tablaPeriodos=null; let tablaConc=null; let tablaConcHist=null; let ncConciliacionActual=0;
 const money=n=>Number(n||0).toLocaleString('es-MX',{style:'currency',currency:'MXN'});
 const fecha=s=>{if(!s)return '—';const a=String(s).split('-');return a.length===3?`${a[2]}/${a[1]}/${a[0]}`:s};
 const renderFecha=(d,t)=>t==='display'?fecha(d):(d||''); const renderMoney=(d,t)=>t==='display'?money(d):Number(d||0);
 const esc=s=>$('<div>').text(s??'').html();
 const puedeBorrarConciliacion=<?= $puedeBorrarConciliacion ? 'true' : 'false' ?>;
 function fechaInput(d){
   const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dia=String(d.getDate()).padStart(2,'0');
   return `${y}-${m}-${dia}`;
 }
 // VISOR NÓMINA: Año/Mes son únicamente auxiliares para llenar el rango.
 // El filtro real se realiza con DESDE/HASTA.
 function aplicarRangoMesVisor(){
   const anio=parseInt($('#nvAnio').val(),10), mes=parseInt($('#nvMes').val(),10);
   if(!anio || !mes) return;
   const hoy=new Date();
   const desde=new Date(anio,mes-1,1);
   let hasta=new Date(anio,mes,0);
   // Para el mes actual, no mostrar días futuros: HASTA = hoy.
   if(anio===hoy.getFullYear() && mes===(hoy.getMonth()+1)){
     hasta=new Date(hoy.getFullYear(),hoy.getMonth(),hoy.getDate());
   }
   $('#nvDesde').val(fechaInput(desde));
   $('#nvHasta').val(fechaInput(hasta));
 }
 function ponerMesActualVisor(){
   const hoy=new Date();
   $('#nvAnio').val(hoy.getFullYear());
   $('#nvMes').val(hoy.getMonth()+1);
   aplicarRangoMesVisor();
 }
 ponerMesActualVisor();
 function duplicadoTipo(){const t=$('#nTipoNomina').val()||'SEMANAL';return duplicates.find(x=>(x.tipo_nomina||'SEMANAL')===t)||null;}
 function fechasAcumuladoValidas(){if(!previewRequiresDates)return true;return !!$('#nFechaPeriodo1').val()&&!!$('#nFechaDesde').val()&&!!$('#nFechaHasta').val();}
 function isoLocal(d){const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),da=String(d.getDate()).padStart(2,'0');return `${y}-${m}-${da}`;}
 function calcularCalendarioPeriodos(){
   if(!previewRequiresDates||!previewMeta)return;
   const baseTxt=$('#nFechaPeriodo1').val(),pd=parseInt(previewMeta.periodo_desde||previewMeta.periodo||0,10),ph=parseInt(previewMeta.periodo_hasta||previewMeta.periodo||0,10);
   if(!baseTxt||!pd||!ph){$('#nFechaDesde,#nFechaHasta').val('');$('#nRangoCalculado').val('');$('#nPeriodosCalculados').empty();$('#nRango').text('CAPTURAR FECHA PERIODO 1');refrescarImportar();return;}
   const parts=baseTxt.split('-').map(Number),base=new Date(parts[0],parts[1]-1,parts[2],12,0,0);
   let rows='',rangoDesde='',rangoHasta='';
   for(let per=pd;per<=ph;per++){
     const ini=new Date(base);ini.setDate(base.getDate()+((per-1)*7));
     const fin=new Date(ini);fin.setDate(ini.getDate()+6);
     const di=isoLocal(ini),df=isoLocal(fin);if(per===pd)rangoDesde=di;if(per===ph)rangoHasta=df;
     rows+=`<tr><td class="fw-bold">${per}</td><td>${fecha(di)}</td><td>${fecha(df)}</td></tr>`;
   }
   $('#nFechaDesde').val(rangoDesde);$('#nFechaHasta').val(rangoHasta);$('#nRangoCalculado').val(fecha(rangoDesde)+' al '+fecha(rangoHasta));$('#nRango').text(fecha(rangoDesde)+' al '+fecha(rangoHasta));
   $('#nPeriodosCalculados').html(`<div class="small fw-bold mb-1">Fechas calculadas</div><div class="table-responsive"><table class="table table-sm table-bordered bg-white mb-0"><thead><tr><th>Periodo</th><th>Del</th><th>Al</th></tr></thead><tbody>${rows}</tbody></table></div>`);
   refrescarImportar();
 }
 function refrescarImportar(){
   duplicate=duplicadoTipo();
   const habilitar=previewValid&&fechasAcumuladoValidas();
   const b=$('#btnNominaImportar').prop('disabled',!habilitar);
   if(previewValid)b.html(`<i class="bi bi-database-check me-1"></i>${duplicate?'REIMPORTAR / ACTUALIZAR':'IMPORTAR NÓMINA'}`);
   const badge=$('#badgeNominaDuplicada');
   if(badge.length){if(duplicate)badge.text('YA IMPORTADA COMO '+($('#nTipoNomina').val()||'SEMANAL')+': SE ACTUALIZARÁ').show();else badge.hide();}
   if(previewRequiresDates){
     const d=$('#nFechaDesde').val(),h=$('#nFechaHasta').val();
     $('#nRango').text(d&&h?fecha(d)+' al '+fecha(h):'CAPTURAR FECHA PERIODO 1');
   }
 }
 function periodoTexto(r){const d=parseInt(r.periodo_desde||r.periodo||0,10),h=parseInt(r.periodo_hasta||r.periodo||0,10);return d&&h?(d===h?String(d):`${d} - ${h}`):(r.periodo||'—');}
 function pintarPreview(r){
   $('#nominaResultado').show();previewToken=r.token||'';previewValid=!!r.valid;previewMeta=r.metadata||{};previewRequiresDates=!!previewMeta.requiere_fechas;duplicates=r.duplicates||[];
   const m=previewMeta,t=r.totals||{};
   $('#nEmpresa').text(m.empresa||r.empresa_activa?.razon_social||'—');$('#nRfc').text(m.rfc_empresa||r.empresa_activa?.rfc||'—');
   $('#nPeriodo').text((m.anio||'—')+' / '+periodoTexto(m));
   $('#nEmpleados').text(t.empleados||0);$('#nPercepciones').text(money(t.percepciones));$('#nDeducciones').text(money(t.deducciones));$('#nTotal').text(money(t.neto));
   if(m.tipo_sugerido&&$('#nTipoNomina option[value="'+m.tipo_sugerido+'"]').length)$('#nTipoNomina').val(m.tipo_sugerido);
   if(previewRequiresDates){
     $('#nFechasAcumulado').show();$('#nFechaDesde,#nFechaHasta').val('');
     $('#nFechaPeriodo1').val((r.calendario_periodos&&r.calendario_periodos.fecha_periodo1)||''); $('#nFechaPeriodo1Guardada').toggle(!!$('#nFechaPeriodo1').val());
     $('#nRango').text($('#nFechaPeriodo1').val()?'CALCULANDO...':'CAPTURAR FECHA PERIODO 1');
     calcularCalendarioPeriodos();
   } else {$('#nFechasAcumulado').hide();$('#nFechaDesde').val(m.fecha_desde||'');$('#nFechaHasta').val(m.fecha_hasta||'');$('#nRango').text(fecha(m.fecha_desde)+' al '+fecha(m.fecha_hasta));}
   let html='';
   if(r.valid){
     const extra=previewRequiresDates?' <b>Falta únicamente confirmar la fecha de inicio del periodo 1.</b>':'';
     const rfcNota=m.rfc_inferido?' <span class="badge bg-info text-dark ms-1">RFC TOMADO DE EMPRESA ACTIVA</span>':'';
     const filasExtra=Number(t.renglones_adicionales||0); const extraNota=filasExtra>0?` <span class="badge bg-primary ms-1">${filasExtra} RENGLÓN(ES) ADICIONAL(ES) INTEGRADO(S)</span>`:'';
     html='<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-1"></i><b>Archivo reconocido.</b> Año, periodo(s) y encabezados validados.'+extra+rfcNota+extraNota+` <span class="badge bg-secondary">${Number(r.reporte_completo?.columnas||0)} COLUMNAS COMPLETAS</span>`+' <span id="badgeNominaDuplicada" class="badge bg-warning text-dark ms-2" style="display:none"></span></div>';
   }else html='<div class="alert alert-danger py-2 mb-0"><b>No se puede importar.</b><ul class="mb-0 mt-1">'+(r.errors||[]).map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul></div>';
   const pendientes=r.reporte_completo?.pendientes||[];
   if(pendientes.length)html+='<div class="alert alert-warning mt-2 mb-0"><b>Estos campos no quedan habilitados para el cruce: falta alta o revisión.</b> Se importarán los conocidos y se conservará el original completo.<ul class="mb-0">'+pendientes.map(c=>'<li>'+esc(c.columna)+' · '+esc(c.encabezado||'(sin encabezado)')+': '+esc(c.motivo)+'</li>').join('')+'</ul></div>';
   $('#nominaValidacion').html(html);
   const tb=$('#tablaNominaPreview tbody').empty();
   (r.employees||[]).forEach(e=>tb.append(`<tr><td>${esc(e.renglones_texto||e.renglon)}</td><td>${esc(e.tipos_nomina_texto||'—')}</td><td>${esc(e.numero_empleado)}</td><td>${esc(e.nombre)}</td><td>${esc(e.apellido_paterno)}</td><td>${esc(e.apellido_materno)}</td><td>${esc(e.rfc)}</td><td class="nomina-money">${money(e.total_percepciones)}</td><td class="nomina-money">${money(e.total_deducciones)}</td><td class="nomina-money fw-bold">${money(e.neto)}</td></tr>`));
   refrescarImportar();
 }

 $('#btnNominaPreview').off('click.nomina').on('click.nomina',function(){
   const f=$('#nominaArchivo')[0].files[0]; if(!f){Swal.fire('Seleccione archivo','Seleccione el XLS o XLSX de nómina.','warning');return;}
   const fd=new FormData();fd.append('archivo',f);
   const b=$(this).prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>VALIDANDO...');
   $.ajax({url:'ajax/nomina_previsualizar.php',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(r=>{if(!r.success)throw new Error(r.error||'Error');pintarPreview(r);})
    .fail(xhr=>{let m='No se pudo leer el archivo.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Archivo no válido',m,'error');})
    .always(()=>b.prop('disabled',false).html('<i class="bi bi-eye me-1"></i>PREVISUALIZAR'));
 });

 $('#nFechaPeriodo1').off('input.nomina').on('input.nomina',calcularCalendarioPeriodos);
 $('#nFechaPeriodo1').off('change.nomina').on('change.nomina',function(){
   calcularCalendarioPeriodos();
   const fecha=$(this).val();
   const anio=parseInt((previewMeta&&previewMeta.anio)||0,10);
   if(!fecha||!anio)return;
   const input=$(this);
   input.removeClass('is-valid is-invalid');
   $.post('ajax/nomina_guardar_periodo1.php',{anio:anio,fecha_periodo1:fecha},null,'json')
    .done(r=>{
      if(!r.success){input.addClass('is-invalid');Swal.fire('No se pudo guardar',r.error||'No se pudo guardar la fecha del periodo 1.','error');return;}
      input.addClass('is-valid');
      if($('#nFechaPeriodo1Guardada').length)$('#nFechaPeriodo1Guardada').text('GUARDADA').show();
    })
    .fail(xhr=>{let m='No se pudo guardar la fecha del periodo 1.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}input.addClass('is-invalid');Swal.fire('No se pudo guardar',m,'error');});
 });

 $('#btnNominaImportar').off('click.nomina').on('click.nomina',function(){
   if(!previewValid||!previewToken)return;
   if(previewRequiresDates&&!fechasAcumuladoValidas()){Swal.fire('Faltan fechas','Capture la fecha inicial y final reales del acumulado.','warning');return;}
   Swal.fire({title:duplicate?'¿Actualizar este periodo?':'¿Importar esta nómina?',text:duplicate?'Se reemplazarán los totales del periodo y se conservará una versión completa del nuevo archivo. Use el archivo COMPLETO, no una muestra.':'Se guardarán los totales, todas las columnas y renglones, y el archivo original del periodo validado.',icon:'question',showCancelButton:true,confirmButtonText:duplicate?'Sí, actualizar':'Sí, importar',cancelButtonText:'Cancelar'}).then(x=>{
    if(!x.isConfirmed)return;
    Swal.fire({title:'Importando nómina...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    $.post('ajax/nomina_importar.php',{token:previewToken,tipo_nomina:$('#nTipoNomina').val(),referencia_nomina:$('#nReferenciaNomina').val(),observaciones:$('#nObservaciones').val(),fecha_periodo1:$('#nFechaPeriodo1').val(),fecha_desde:$('#nFechaDesde').val(),fecha_hasta:$('#nFechaHasta').val()},null,'json').done(r=>{
      if(!r.success){Swal.fire('Error',r.error||'No se pudo importar.','error');return;}
      const extraRows=Number(r.renglones_adicionales||0); const extraMsg=extraRows?` Renglones adicionales integrados: ${extraRows}.`:'';
      const pendientes=r.reporte_completo?.pendientes||[];
      const aviso=pendientes.length?' Campos pendientes de alta/revisión: '+pendientes.map(c=>c.columna+' '+(c.encabezado||'(sin encabezado)')).join('; ')+'. Se conservaron en el original; revise REPORTE COMPLETO.':'';
      Swal.fire(pendientes.length?'Importado con campos pendientes':'Listo',r.message+` Empleados: ${r.empleados}.${extraMsg} Neto: ${money(r.total_neto)}. Reporte completo: ${Number(r.reporte_completo?.columnas||0)} columnas y ${Number(r.reporte_completo?.filas||0)} filas guardadas.`+aviso,pendientes.length?'warning':'success');
      previewToken='';previewValid=false;previewRequiresDates=false;previewMeta={};$('#btnNominaImportar').prop('disabled',true);cargarPeriodos();if(tablaVisor)tablaVisor.ajax.reload();
    }).fail(xhr=>{let m='No se pudo importar.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});
   });
 });

 function cargarPeriodos(){
   if($.fn.DataTable.isDataTable('#tablaNominaPeriodos')){$('#tablaNominaPeriodos').DataTable().destroy();}
   tablaPeriodos=$('#tablaNominaPeriodos').DataTable({ajax:{url:'ajax/listar_nomina_periodos.php',data:d=>{d.anio=$('#nvAnio').val();d.desde=$('#nvDesde').val();d.hasta=$('#nvHasta').val();d.tipo_nomina=$('#nvTipo').val();},dataSrc:'data'},pageLength:10,order:[[1,'desc'],[2,'desc']],scrollX:true,columns:[
    {data:null,orderable:false,searchable:false,className:'text-center',render:r=>`<div class="btn-group btn-group-sm"><button class="btn btn-outline-primary btnFechasNomina" title="Corregir fechas" data-id="${r.id_importacion}" data-desde="${esc(r.fecha_desde)}" data-hasta="${esc(r.fecha_hasta)}" data-periodos="${esc(periodoTexto(r))}"><i class="bi bi-calendar3"></i> FECHAS</button><button class="btn btn-outline-success btnFuenteNomina" title="Ver todas las columnas y descargar el original" data-id="${r.id_importacion}"><i class="bi bi-file-earmark-spreadsheet"></i> REPORTE COMPLETO</button><button class="btn btn-outline-primary btnConceptosNomina" title="Nuevo informe por campo" data-id="${r.id_importacion}"><i class="bi bi-list-check"></i> CONCILIAR CAMPOS</button><button class="btn btn-danger btnBorrarNomina" title="Borrar toda esta nómina" data-id="${r.id_importacion}" data-tipo="${esc(r.tipo_nomina||'SEMANAL')}" data-periodo="${esc(periodoTexto(r))}"><i class="bi bi-trash3-fill"></i> BORRAR</button></div>`},
    {data:'anio'},{data:null,render:r=>periodoTexto(r)},{data:'tipo_nomina',render:d=>`<span class="badge ${d==='ACUMULADO_PERIODOS'?'bg-warning text-dark':'bg-primary'}">${esc(d==='ACUMULADO_PERIODOS'?'ACUMULADO PERIODOS':(d||'SEMANAL'))}</span>`},{data:'referencia_nomina',defaultContent:''},{data:'fecha_desde',render:renderFecha},{data:'fecha_hasta',render:renderFecha},{data:'total_empleados'},{data:'total_percepciones',className:'text-end',render:renderMoney},{data:'total_deducciones',className:'text-end',render:renderMoney},{data:'total_neto',className:'text-end fw-bold',render:renderMoney},{data:'nombre_archivo'},{data:'estatus',render:d=>`<span class="badge ${d==='REIMPORTADO'?'bg-warning text-dark':'bg-success'}">${esc(d)}</span>`},{data:'fecha_importacion'}]});
 }
 function cargarVisor(){
   if($.fn.DataTable.isDataTable('#tablaNominaVisor')){$('#tablaNominaVisor').DataTable().destroy();}
   tablaVisor=$('#tablaNominaVisor').DataTable({ajax:{url:'ajax/listar_nomina.php',data:d=>{d.anio=$('#nvAnio').val();d.desde=$('#nvDesde').val();d.hasta=$('#nvHasta').val();d.tipo_nomina=$('#nvTipo').val();},dataSrc:'data'},pageLength:25,order:[[0,'desc'],[1,'desc'],[5,'asc']],scrollX:true,scrollY:'44vh',scrollCollapse:true,columns:[
    {data:'anio'},{data:null,render:r=>periodoTexto(r)},{data:'tipo_nomina',render:d=>esc(d==='ACUMULADO_PERIODOS'?'ACUMULADO PERIODOS':(d||'SEMANAL'))},{data:'fecha_desde',render:renderFecha},{data:'fecha_hasta',render:renderFecha},{data:'numero_empleado'},{data:'nombre'},{data:'apellido_paterno'},{data:'apellido_materno'},{data:'rfc'},{data:'total_percepciones',className:'text-end',render:renderMoney},{data:'total_deducciones',className:'text-end',render:renderMoney},{data:'neto',className:'text-end fw-bold',render:renderMoney}]});
 }
 $('#btnNvFiltrar').off('click.nomina').on('click.nomina',function(){
   if(tablaPeriodos)tablaPeriodos.ajax.reload();else cargarPeriodos();
   if(tablaVisor)tablaVisor.ajax.reload();else cargarVisor();
 });
 // Año + mes solamente ayudan a llenar DESDE/HASTA.
 $('#nvAnio,#nvMes').off('change.nomina input.nomina').on('change.nomina input.nomina',function(){
   aplicarRangoMesVisor();
 });
 // Si el usuario modifica manualmente DESDE/HASTA, ese rango se conserva;
 // al volver a tocar Año o Mes se recalcula el rango del mes seleccionado.
 $('#btnNvLimpiar').off('click.nomina').on('click.nomina',function(){
   $('#nvTipo').val('');
   ponerMesActualVisor();
   if(tablaPeriodos)tablaPeriodos.ajax.reload();else cargarPeriodos();
   if(tablaVisor)tablaVisor.ajax.reload();else cargarVisor();
 });

 $('#nTipoNomina').off('change.nomina').on('change.nomina',function(){refrescarImportar();});
 $('#nFechaDesde,#nFechaHasta').off('change.nomina input.nomina').on('change.nomina input.nomina',refrescarImportar);
 $('#tablaNominaPeriodos').off('click.nomina','.btnConceptosNomina').on('click.nomina','.btnConceptosNomina',function(){window.abrirConciliacionCampos($(this).data('id'));});
 $('#tablaNominaPeriodos').off('click.nomina','.btnFuenteNomina').on('click.nomina','.btnFuenteNomina',function(){window.verNominaFuente($(this).data('id'));});
 $('#tablaNominaPeriodos').off('click.nomina','.btnFechasNomina').on('click.nomina','.btnFechasNomina',function(){
   const b=$(this),id=parseInt(b.data('id'),10)||0,desde=String(b.data('desde')||''),hasta=String(b.data('hasta')||''),periodos=String(b.data('periodos')||'');
   Swal.fire({title:'Corregir rango de fechas',html:`<div class="text-start small mb-2">Periodo(s): <b>${esc(periodos)}</b></div><label class="form-label small fw-bold">FECHA INICIAL</label><input id="swNomDesde" type="date" class="form-control mb-2" value="${esc(desde)}"><label class="form-label small fw-bold">FECHA FINAL</label><input id="swNomHasta" type="date" class="form-control" value="${esc(hasta)}">`,showCancelButton:true,confirmButtonText:'Guardar fechas',cancelButtonText:'Cancelar',preConfirm:()=>{const d=$('#swNomDesde').val(),h=$('#swNomHasta').val();if(!d||!h){Swal.showValidationMessage('Capture ambas fechas.');return false;}if(d>h){Swal.showValidationMessage('La fecha inicial no puede ser mayor.');return false;}return {d,h};}}).then(x=>{if(!x.isConfirmed)return;$.post('ajax/nomina_actualizar_fechas.php',{id_importacion:id,fecha_desde:x.value.d,fecha_hasta:x.value.h},null,'json').done(r=>{if(!r.success){Swal.fire('Error',r.error||'No se pudieron cambiar las fechas.','error');return;}Swal.fire({title:'Fechas actualizadas',text:r.message,icon:'success',timer:1400,showConfirmButton:false});cargarPeriodos();if(tablaVisor)tablaVisor.ajax.reload(null,false);}).fail(xhr=>{let m='No se pudieron cambiar las fechas.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});});
 });
 $('#tablaNominaPeriodos').off('click.nomina','.btnBorrarNomina').on('click.nomina','.btnBorrarNomina',function(){
   const id=$(this).data('id'), tipo=$(this).data('tipo'), periodo=$(this).data('periodo');
   Swal.fire({title:'¿Borrar toda esta nómina?',html:`Se eliminarán <b>el encabezado, todos los empleados y las versiones guardadas del reporte original</b> del periodo <b>${periodo}</b> tipo <b>${esc(tipo)}</b>.<br><br>Esta acción no se puede deshacer desde pantalla.`,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Sí, borrar nómina',cancelButtonText:'Cancelar'}).then(x=>{
     if(!x.isConfirmed)return;
     $.post('ajax/nomina_eliminar.php',{id_importacion:id},null,'json').done(r=>{if(!r.success){Swal.fire('Error',r.error||'No se pudo borrar.','error');return;}Swal.fire('Eliminada',r.message,'success');cargarPeriodos();if(tablaVisor)tablaVisor.ajax.reload();}).fail(xhr=>{let m='No se pudo borrar.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});
   });
 });


 function ncBadge(e){
   const m={CONCILIADO:'bg-success',DIFERENCIA:'bg-danger',SOLO_REPORTE:'bg-warning text-dark',SOLO_VISOR:'bg-info text-dark',CANCELADO_EN_VISOR:'bg-dark'};
   const t={CONCILIADO:'CONCILIADO',DIFERENCIA:'DIFERENCIA',SOLO_REPORTE:'SOLO REPORTE',SOLO_VISOR:'SOLO VISOR',CANCELADO_EN_VISOR:'CANCELADO EN VISOR'};
   return `<span class="badge ${m[e]||'bg-secondary'}">${t[e]||esc(e||'')}</span>`;
 }
 function ncMostrarResumen(resumen,data,id,rango){
   ncConciliacionActual=parseInt(id||0,10)||0;
   $('#ncResumenCard').show(); $('#ncIdActual').text(ncConciliacionActual?'Conciliación #'+ncConciliacionActual:'');
   $('#btnExportarNominaConciliacion').toggle(!!ncConciliacionActual);
   $('#ncRangoUsado').text(rango&&rango.desde?`${fecha(rango.desde)} al ${fecha(rango.hasta)}`:$('#ncRangoUsado').text());
   $('#ncKRep').text(resumen.empleados_reporte??resumen.total_empleados_reporte??0); $('#ncKVis').text(resumen.empleados_visor??resumen.total_empleados_visor??0);
   $('#ncKOk').text(resumen.conciliados??resumen.total_conciliados??0); $('#ncKDif').text(resumen.diferencias??resumen.total_diferencias??0);
   $('#ncKSoloRep').text(resumen.solo_reporte??resumen.total_solo_reporte??0); $('#ncKSoloVis').text(resumen.solo_visor??resumen.total_solo_visor??0);
   $('#ncKTotalRep').text(money(resumen.total_reporte)); $('#ncKTotalVis').text(money(resumen.total_visor));
   const dif=Number(resumen.diferencia||0); $('#ncKTotalDif').text(money(dif)).removeClass('nomina-ok nomina-bad').addClass(Math.abs(dif)<=0.01?'nomina-ok':'nomina-bad');
   if($.fn.DataTable.isDataTable('#tablaNominaConciliacion')) $('#tablaNominaConciliacion').DataTable().destroy();
   const moneyDesglose=(d,t,row)=>{if(!Number(row.usa_desglose||0))return t==='display'?'—':0;return renderMoney(d,t);};
   const diffDesglose=(d,t,row)=>{if(!Number(row.usa_desglose||0))return t==='display'?'—':0;return t==='display'?`<span class="${Math.abs(Number(d||0))<=.01?'nomina-ok':'nomina-bad'} fw-bold">${money(d)}</span>`:Number(d||0);};
   tablaConc=$('#tablaNominaConciliacion').DataTable({data:data||[],pageLength:50,order:[[14,'asc'],[0,'asc']],scrollX:true,scrollY:'46vh',scrollCollapse:true,createdRow:(row)=>$(row).addClass('nomina-conc-row').attr('title','Doble clic para ver detalle del empleado'),columns:[
     {data:'numero_empleado',defaultContent:''},{data:'rfc'},{data:'nombre_completo',defaultContent:''},
     {data:'percepciones_visor',className:'text-end',render:moneyDesglose},{data:'percepciones_reporte',className:'text-end',render:moneyDesglose},
     {data:'diferencia_percepciones',className:'text-end',render:diffDesglose},
     {data:'deducciones_visor',className:'text-end',render:moneyDesglose},{data:'deducciones_reporte',className:'text-end',render:moneyDesglose},
     {data:'diferencia_deducciones',className:'text-end',render:diffDesglose},
     {data:'total_visor',className:'text-end',render:renderMoney},{data:'total_reporte',className:'text-end',render:renderMoney},
     {data:'diferencia',className:'text-end',render:(d,t)=>t==='display'?`<span class="${Math.abs(Number(d||0))<=.01?'nomina-ok':'nomina-bad'} fw-bold">${money(d)}</span>`:Number(d||0)},
     {data:'documentos_visor',className:'text-center'},{data:'documentos_cancelados',className:'text-center',render:(d,t)=>t==='display'&&Number(d)>0?`<span class="badge bg-dark">${d}</span>`:d},
     {data:'estatus',render:(d,t)=>t==='display'?ncBadge(d):d}
   ]});
 }
 function ncPintaDif(selector,valor){
   const n=Number(valor||0); $(selector).text(money(n)).removeClass('nomina-ok nomina-bad').addClass(Math.abs(n)<=.01?'nomina-ok':'nomina-bad');
 }
 function ncAbrirEmpleado(row){
   if(!ncConciliacionActual || !row || !row.rfc) return;
   const modalEl=document.getElementById('modalNominaConcEmpleado');
   const modal=bootstrap.Modal.getOrCreateInstance(modalEl);
   $('#ncEmpNombre').text(row.nombre_completo||'—'); $('#ncEmpRfc').text(row.rfc||'—'); $('#ncEmpNumero').text('Empleado '+(row.numero_empleado||'—'));
   $('#ncEmpEstatus').html(ncBadge(row.estatus)); $('#ncEmpCfdis').html('<tr><td colspan="13" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando CFDI...</td></tr>');
   modal.show();
   $.getJSON('ajax/nomina_conciliacion_empleado.php',{id_conciliacion:ncConciliacionActual,rfc:row.rfc}).done(r=>{
     if(!r.success){ $('#ncEmpCfdis').html('<tr><td colspan="13" class="text-danger text-center py-4">'+esc(r.error||'No se pudo cargar.')+'</td></tr>'); return; }
     const e=r.empleado||{},c=r.conciliacion||{},t=r.totales_cfdi||{};
     $('#ncEmpNombre').text(e.nombre_completo||'—'); $('#ncEmpRfc').text(e.rfc||'—'); $('#ncEmpNumero').text('Empleado '+(e.numero_empleado||'—')); $('#ncEmpEstatus').html(ncBadge(e.estatus));
     $('#ncEmpRango').text('Conciliación #'+ncConciliacionActual+' · periodo trabajado '+fecha(c.fecha_desde)+' al '+fecha(c.fecha_hasta));
     $('#ncEmpPV').text(money(e.percepciones_visor)); $('#ncEmpPR').text(money(e.percepciones_reporte)); ncPintaDif('#ncEmpPD',e.diferencia_percepciones);
     $('#ncEmpDV').text(money(e.deducciones_visor)); $('#ncEmpDR').text(money(e.deducciones_reporte)); ncPintaDif('#ncEmpDD',e.diferencia_deducciones);
     $('#ncEmpNV').text(money(e.total_visor)); $('#ncEmpNR').text(money(e.total_reporte)); ncPintaDif('#ncEmpND',e.diferencia);
     $('#ncEmpCV').text(t.vigentes??e.documentos_visor??0); $('#ncEmpCC').text(t.cancelados??e.documentos_cancelados??0); $('#ncEmpOP').text(money(t.otros_pagos));
     const rows=r.cfdis||[];
     if(!rows.length){ $('#ncEmpCfdis').html('<tr><td colspan="13" class="text-center text-muted py-4">No hay CFDI de nómina para este RFC dentro del rango de la conciliación.</td></tr>'); return; }
     $('#ncEmpCfdis').html(rows.map(x=>{
       const vigente=Number(x.vigente_conciliacion||0)===1;
       const st=vigente?'<span class="badge bg-success">VIGENTE</span>':'<span class="badge bg-dark">'+esc(x.estatus_sat||'CANCELADO')+'</span>';
       const sf=[x.serie||'',x.folio||''].filter(Boolean).join(' / ')||'—';
       const periodo=fecha(x.nomina_fecha_inicial_pago)+' - '+fecha(x.nomina_fecha_final_pago);
       const tipo=(x.nomina_tipo_nomina==='O'?'ORDINARIA':(x.nomina_tipo_nomina==='E'?'EXTRAORDINARIA':(x.nomina_tipo_nomina||'—')));
       const acciones='<button type="button" class="btn btn-sm btn-warning me-1 ncVerXml" data-uuid="'+esc(x.uuid)+'" title="Ver XML"><i class="bi bi-code-slash"></i> XML</button>'+
                      '<button type="button" class="btn btn-sm btn-outline-danger ncVerPdf" data-uuid="'+esc(x.uuid)+'" title="Ver PDF"><i class="bi bi-file-earmark-pdf"></i></button>';
       return '<tr class="'+(vigente?'':'table-secondary')+'"><td class="text-nowrap">'+acciones+'</td><td><code>'+esc(x.uuid)+'</code></td><td>'+st+'</td><td>'+esc(sf)+'</td><td>'+fecha(String(x.fecha_emision||'').substring(0,10))+'</td><td class="text-nowrap">'+periodo+'</td><td>'+fecha(x.nomina_fecha_pago)+'</td><td>'+esc(tipo)+'</td><td>'+esc(x.nomina_num_empleado||'')+'</td><td class="text-end">'+money(x.nomina_total_percepciones)+'</td><td class="text-end">'+money(x.nomina_total_deducciones)+'</td><td class="text-end">'+money(x.nomina_total_otros_pagos)+'</td><td class="text-end fw-bold">'+money(x.total_xml)+'</td></tr>';
     }).join(''));
   }).fail(xhr=>{let m='No se pudo cargar el detalle del empleado.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}$('#ncEmpCfdis').html('<tr><td colspan="13" class="text-danger text-center py-4">'+esc(m)+'</td></tr>');});
 }
 $('#tablaNominaConciliacion tbody').off('dblclick.nomina').on('dblclick.nomina','tr',function(){ if(!tablaConc)return; const d=tablaConc.row(this).data(); if(d)ncAbrirEmpleado(d); });
 $('#ncEmpCfdis').off('click.nomina','.ncVerXml').on('click.nomina','.ncVerXml',function(){ window.open('ajax/visor_xml.php?uuid='+encodeURIComponent($(this).data('uuid')),'_blank'); });
 $('#ncEmpCfdis').off('click.nomina','.ncVerPdf').on('click.nomina','.ncVerPdf',function(){ window.open('ajax/generar_pdf.php?uuid='+encodeURIComponent($(this).data('uuid')),'_blank'); });
 $('#btnNcEmpExportarExcel').off('click.nomina').on('click.nomina',function(){
   const rfc=String($('#ncEmpRfc').text()||'').trim();
   if(!ncConciliacionActual || !rfc){ Swal.fire('Aviso','Primero abre el detalle de un empleado.','warning'); return; }
   const url='ajax/exportar_nomina_conciliacion_empleado_excel.php?id_conciliacion='+encodeURIComponent(ncConciliacionActual)+'&rfc='+encodeURIComponent(rfc);
   window.location.href=url;
 });
 function ncCargarHistorial(){
   if($.fn.DataTable.isDataTable('#tablaNominaConciliacionesHist')) $('#tablaNominaConciliacionesHist').DataTable().destroy();
   tablaConcHist=$('#tablaNominaConciliacionesHist').DataTable({ajax:{url:'ajax/listar_nomina_conciliaciones.php',dataSrc:'data'},pageLength:10,order:[[1,'desc']],scrollX:true,columns:[
    {data:'id_conciliacion',orderable:false,searchable:false,render:id=>{
      let h=`<button class="btn btn-sm btn-outline-primary btnVerNominaConc me-1" data-id="${id}" title="Ver conciliación"><i class="bi bi-eye"></i></button>`;
      if(puedeBorrarConciliacion) h+=`<button class="btn btn-sm btn-danger btnBorrarNominaConc" data-id="${id}" title="Borrar conciliación"><i class="bi bi-trash3"></i> BORRAR</button>`;
      return h;
    }},
    {data:'fecha_conciliacion'},{data:'anio'},
    {data:null,render:r=>`${fecha(r.fecha_desde)} - ${fecha(r.fecha_hasta)}`},
    {data:'total_reporte',className:'text-end',render:renderMoney},{data:'total_visor',className:'text-end',render:renderMoney},{data:'diferencia',className:'text-end',render:renderMoney},{data:'total_conciliados'},{data:'total_diferencias'}
   ]});
 }
 let ncInicializado=false;
 function ncAplicarMes(){
   const anio=parseInt($('#ncAnio').val(),10), mes=parseInt($('#ncMes').val(),10);
   if(!anio || !mes) return;
   const hoy=new Date();
   const desde=new Date(anio,mes-1,1);
   let hasta=new Date(anio,mes,0);
   // Si se selecciona el mes/año actual, Hasta = hoy. Para meses anteriores, último día del mes.
   if(anio===hoy.getFullYear() && mes===(hoy.getMonth()+1)) hasta=new Date(hoy.getFullYear(),hoy.getMonth(),hoy.getDate());
   $('#ncFechaDesde').val(fechaInput(desde));
   $('#ncFechaHasta').val(fechaInput(hasta));
 }
 $('#ncAnio,#ncMes').off('change.nomina input.nomina').on('change.nomina input.nomina',ncAplicarMes);
 function ncInicializar(){
   const hoy=new Date();
   // Siempre que se abre por primera vez la pestaña arrancamos en el mes actual.
   // Evita valores restaurados por el navegador de una conciliación anterior.
   if(!ncInicializado){
     $('#ncAnio').val(hoy.getFullYear());
     $('#ncMes').val(hoy.getMonth()+1);
     ncAplicarMes();
     ncInicializado=true;
   }
   if(!tablaConcHist)ncCargarHistorial();
 }
 $('#btnNominaConciliar').off('click.nomina').on('click.nomina',function(){
   const payload={fecha_desde:$('#ncFechaDesde').val(),fecha_hasta:$('#ncFechaHasta').val()};
   Swal.fire({title:'Conciliando nómina...',html:'Agrupando reporte y CFDI de nómina por RFC y periodo trabajado.',allowOutsideClick:false,allowEscapeKey:false,didOpen:()=>Swal.showLoading()});
   $.post('ajax/nomina_conciliar.php',payload,null,'json').done(r=>{
     if(!r.success){Swal.fire('Error',r.error||'No se pudo conciliar.','error');return;}
     Swal.close(); ncMostrarResumen(r.resumen,r.data,r.id_conciliacion,r.rango); if(tablaConcHist)tablaConcHist.ajax.reload(null,false);else ncCargarHistorial();
   }).fail(xhr=>{let m='No se pudo realizar la conciliación.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});
 });
 $('#btnExportarNominaConciliacion').off('click.nomina').on('click.nomina',function(){
   if(!ncConciliacionActual){ Swal.fire('Sin conciliación','Primero realice o abra una conciliación.','warning'); return; }
   window.location.href='ajax/exportar_nomina_conciliacion_excel.php?id_conciliacion='+encodeURIComponent(ncConciliacionActual);
 });

 $('#tablaNominaConciliacionesHist').off('click.nomina','.btnVerNominaConc').on('click.nomina','.btnVerNominaConc',function(){
   const id=$(this).data('id'); $.getJSON('ajax/listar_nomina_conciliacion_detalle.php',{id_conciliacion:id}).done(r=>{
     if(!r.success){Swal.fire('Error',r.error||'No se pudo abrir.','error');return;}
     const h=r.resumen||{}; ncMostrarResumen(h,r.data,id,{desde:h.fecha_desde,hasta:h.fecha_hasta});
     $('#ncAnio').val(h.anio||'');
     if(h.fecha_desde){ const pa=String(h.fecha_desde).split('-'); if(pa.length===3) $('#ncMes').val(parseInt(pa[1],10)); }
     $('#ncFechaDesde').val(h.fecha_desde||'');$('#ncFechaHasta').val(h.fecha_hasta||'');
     window.scrollTo({top:$('#ncResumenCard').offset().top-120,behavior:'smooth'});
   }).fail(xhr=>{let m='No se pudo abrir la conciliación.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});
 });
 $('#tablaNominaConciliacionesHist').off('click.nomina','.btnBorrarNominaConc').on('click.nomina','.btnBorrarNominaConc',function(){
   const id=parseInt($(this).data('id'),10)||0;
   if(!id) return;
   Swal.fire({
     title:'¿Borrar conciliación?',
     html:`Se eliminará la conciliación <b>#${id}</b> y todo su detalle guardado.<br><br><b>No se borrarán XML, facturas ni nóminas importadas.</b>`,
     icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Sí, borrar',cancelButtonText:'Cancelar',reverseButtons:true
   }).then(x=>{
     if(!x.isConfirmed) return;
     $.post('ajax/nomina_conciliacion_borrar.php',{id_conciliacion:id},null,'json').done(r=>{
       if(!r.success){Swal.fire('Error',r.error||'No se pudo borrar la conciliación.','error');return;}
       if(ncConciliacionActual===id){
         ncConciliacionActual=0;
         $('#ncResumenCard').hide();
         $('#btnExportarNominaConciliacion').hide();
         if($.fn.DataTable.isDataTable('#tablaNominaConciliacion')) $('#tablaNominaConciliacion').DataTable().clear().draw();
       }
       if(tablaConcHist) tablaConcHist.ajax.reload(null,false);
       Swal.fire({title:'Conciliación borrada',text:'Se eliminó el encabezado y todo su detalle.',icon:'success',timer:1400,showConfirmButton:false});
     }).fail(xhr=>{let m='No se pudo borrar la conciliación.';try{m=JSON.parse(xhr.responseText).error||m}catch(e){}Swal.fire('Error',m,'error');});
   });
 });

 $('button[data-bs-target="#nomina-conciliacion"]').off('shown.bs.tab.nomina').on('shown.bs.tab.nomina',function(){ncInicializar();if(tablaConcHist)setTimeout(()=>tablaConcHist.columns.adjust(),80);});

 $('button[data-bs-target="#nomina-visor"]').off('shown.bs.tab.nomina').on('shown.bs.tab.nomina',function(){if(!tablaPeriodos)cargarPeriodos();if(!tablaVisor)cargarVisor();else setTimeout(()=>tablaVisor.columns.adjust(),80);});

 // VISOR NÓMINA es la pestaña inicial: cargar sus tablas desde la entrada al módulo.
 cargarPeriodos();
 cargarVisor();
})();
</script>
