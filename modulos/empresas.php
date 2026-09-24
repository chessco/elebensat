<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/csrf.php';
seguridad_exigir_superadmin($pdo, false);
$csrfContpaq = csrf_token();
$stmt_zonas = $pdo->query("SELECT id_zona, nombre_zona FROM zonas ORDER BY nombre_zona ASC");
$zonas = $stmt_zonas->fetchAll();
?>
<style>
    #tablaEmpresas td { font-size: 0.8rem; vertical-align: middle; padding: 4px !important; }
    .form-control-min { background: #000 !important; border: 1px solid #30363d !important; color: #fff !important; font-size: 0.75rem !important; height: 26px; padding: 2px 6px; }
    .label-min { font-size: 0.62rem; color: #8b949e; font-weight: bold; text-transform: uppercase; margin-bottom: 1px; display: block; }
    .nav-tabs .nav-link { color: #8b949e; font-size: 0.7rem; border: none; }
    .nav-tabs .nav-link.active { background: #21262d !important; color: #58a6ff !important; border-bottom: 2px solid #58a6ff !important; }
    .vigencia-box { font-size: 0.65rem; padding: 6px; border-radius: 4px; background: #0d1117; border: 1px solid #30363d; color: #58a6ff; min-height: 35px; display: flex; align-items: center; }
    .preview-logo { width: 50px; height: 50px; border: 1px dashed #444; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #000; }
    .preview-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }

    .mail-template-box { background:#f8fbfd; border:1px solid #cfe3ee; border-radius:6px; padding:8px; }
    .mail-token { font-size:.64rem; padding:2px 6px; margin:2px 3px 2px 0; border:1px solid #0dcaf0; color:#087990; background:#fff; border-radius:12px; cursor:pointer; display:inline-block; }
    .mail-token:hover { background:#cff4fc; }
    #mensaje { display:none; }
    .html-mail-toolbar { display:flex; gap:4px; flex-wrap:wrap; align-items:center; padding:5px; border:1px solid #b8d4e2; border-bottom:0; background:#f5fbfe; border-radius:6px 6px 0 0; }
    .html-mail-toolbar .btn { min-width:28px; padding:1px 6px; line-height:1.35; }
    #mensaje_html_editor { min-height:220px; max-height:360px; overflow:auto; padding:10px; border:1px solid #8ccbea; background:#fff; color:#172b3a; border-radius:0 0 6px 6px; line-height:1.45; font-size:.78rem; outline:none; }
    #mensaje_html_editor:focus { border-color:#0dcaf0; box-shadow:0 0 0 .15rem rgba(13,202,240,.12); }
    #mensaje_html_editor p { margin:0 0 .55rem; }
    #mensaje_html_editor ul, #mensaje_html_editor ol { margin:.25rem 0 .55rem 1.25rem; }
    #mensaje_html_source { min-height:220px; max-height:360px; display:none; font-family:Consolas,monospace; font-size:.72rem; line-height:1.35; }
    #asunto { width:100%; }

    /* Modal de empresas: encabezado y pie visibles, contenido con desplazamiento */
    #modalEmpresa .modal-dialog {
        max-height: calc(100vh - 24px);
        margin-top: 12px;
        margin-bottom: 12px;
    }
    #modalEmpresa .modal-content {
        max-height: calc(100vh - 24px);
        overflow: hidden;
    }
    #modalEmpresa #formEmpresa {
        display: flex;
        flex-direction: column;
        min-height: 0;
        max-height: calc(100vh - 24px);
    }
    #modalEmpresa .modal-header,
    #modalEmpresa .modal-footer {
        flex: 0 0 auto;
        background: #fff;
        z-index: 2;
    }
    #modalEmpresa .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto !important;
        overflow-x: hidden;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }
    #modalEmpresa .modal-footer {
        box-shadow: 0 -3px 10px rgba(15, 43, 64, .08);
    }
    @media (max-height: 700px) {
        #modalEmpresa .modal-dialog,
        #modalEmpresa .modal-content,
        #modalEmpresa #formEmpresa {
            max-height: calc(100vh - 12px);
        }
        #modalEmpresa .modal-dialog {
            margin-top: 6px;
            margin-bottom: 6px;
        }
    }

</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between mb-2">
        <h6 class="text-white fw-bold"><i class="bi bi-building me-2 text-info"></i>ADMINISTRACIÓN DE EMPRESAS</h6>
        <button class="btn btn-info btn-sm fw-bold text-dark" onclick="nuevaEmpresa()">+ NUEVA EMPRESA</button>
    </div>

    <div class="card bg-dark border-secondary shadow">
        <div class="table-responsive">
            <table id="tablaEmpresas" class="table table-dark table-hover mb-0">
                <thead>
                    <tr class="small text-muted text-uppercase">
                        <th>RFC</th><th>RAZÓN SOCIAL</th><th>MODO DIOT</th><th>CRITERIO PUE</th><th>CIUDAD</th><th>ESTADO</th><th>ACTIVA</th><th class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT e.*, z.nombre_zona FROM empresas e LEFT JOIN zonas z ON e.id_zona = z.id_zona ORDER BY e.id_empresa DESC");
                    while ($r = $stmt->fetch()) {
                        // Mostramos visualmente qué modo tiene
                        $modoLabel = ($r['modo_diot'] === 'simplificada') 
                            ? '<span class="badge bg-warning text-dark">SIMPLIFICADA</span>' 
                            : '<span class="badge bg-secondary">NORMAL</span>';

                        $rJson = $r;
                        $rJson['smtp_password_configurada'] = !empty($rJson['smtp_password_enc']) ? 1 : 0;
                        unset($rJson['smtp_password_enc']);
                        $filaJson = htmlspecialchars(json_encode($rJson), ENT_QUOTES, 'UTF-8');
                        echo "<tr class='fila-empresa' data-empresa='{$filaJson}' title='Doble clic para editar'>
                            <td>{$r['rfc']}</td>
                            <td>".strtoupper($r['razon_social'])."</td>
                            <td>{$modoLabel}</td>
                            <td><span class='badge bg-info text-dark'>" . ($r['criterio_fecha_pue'] ?? 'EMISION') . "</span></td>
                            <td>{$r['ciudad']}</td>
                            <td>{$r['estado']}</td>
                            <td class='text-center'>" . (((int)($r['activo'] ?? 1) === 1)
                                ? "<span class='badge bg-success'>ACTIVA</span>"
                                : "<span class='badge bg-secondary'>INACTIVA</span>") . "</td>
                            <td class='text-center'>
                                <button type='button' class='btn btn-outline-warning btn-sm py-0 btn-editar-empresa' title='Editar empresa'><i class='bi bi-pencil'></i></button>
                                <button type='button' class='btn " . (((int)($r['activo'] ?? 1) === 1) ? "btn-outline-danger" : "btn-outline-success") . " btn-sm py-0 btn-estado-empresa'
                                        data-id='{$r['id_empresa']}' data-rfc='" . htmlspecialchars($r['rfc'], ENT_QUOTES, 'UTF-8') . "' data-activo='" . ((int)($r['activo'] ?? 1)) . "'
                                        title='" . (((int)($r['activo'] ?? 1) === 1) ? "Desactivar empresa" : "Reactivar empresa") . "'>
                                    <i class='bi " . (((int)($r['activo'] ?? 1) === 1) ? "bi-pause-circle" : "bi-play-circle") . "'></i>
                                </button>
                            </td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEmpresa" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg">
            <form id="formEmpresa" onsubmit="return false;">
                <input type="hidden" id="id_empresa" name="id_empresa">
                <div class="modal-header py-2 border-secondary align-items-center">
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-1 gap-md-3">
                        <b class="text-info small">GESTIÓN DE DATOS MAESTROS</b>
                        <span id="empresa_modal_contexto" class="badge bg-primary px-3 py-2" style="font-size:.74rem; letter-spacing:.02em;">
                            NUEVA EMPRESA
                        </span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <ul class="nav nav-tabs" id="tabEmpresa">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-gen" type="button">1. DATOS FISCALES</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-cert" type="button">2. SELLOS / FIEL</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-mail" type="button">3. CORREO / LOGOS</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-contpaq" type="button">4. CONTPAQ</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-oracle-pagos" type="button">5. ORACLE PAGOS</button></li>
                    </ul>

                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" id="t-gen">
                            <div class="row g-1">
                                <div class="col-md-2"><label class="label-min">RFC</label><input type="text" id="rfc" name="rfc" class="form-control form-control-min text-uppercase" maxlength="13" required></div>
                                <div class="col-md-2"><label class="label-min">TIPO DE PERSONA</label><select id="tipopersona" name="tipopersona" class="form-control form-control-min" required><option value="">SELECCIONAR</option><option value="MORAL">PERSONA MORAL</option><option value="FISICA">PERSONA FÍSICA</option></select></div>
                                <div class="col-md-3"><label class="label-min">ZONA</label><select name="id_zona" id="id_zona" class="form-control form-control-min"><?php foreach($zonas as $z) echo "<option value='{$z['id_zona']}'>{$z['nombre_zona']}</option>"; ?></select></div>
                                
                                <div class="col-md-3">
                                    <label class="label-min text-warning">MODO REPORTE DIOT</label>
                                    <select name="modo_diot" id="modo_diot" class="form-control form-control-min border-warning text-warning">
                                        <option value="normal">NORMAL</option>
                                        <option value="simplificada">SIMPLIFICADA</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="label-min text-info">CRITERIO PUE PARA DIOT</label>
                                    <select name="criterio_fecha_pue" id="criterio_fecha_pue" class="form-control form-control-min border-info text-info">
                                        <option value="EMISION">FECHA DE EMISIÓN XML</option>
                                        <option value="PAGO">FECHA EFECTIVA DE PAGO</option>
                                    </select>
                                    <small class="text-muted" style="font-size:.58rem">La fecha capturada por usuario siempre tiene prioridad.</small>
                                </div>
                                <div class="col-md-2"></div> <div class="col-md-5"><label class="label-min">RAZÓN SOCIAL</label><input type="text" id="razon_social" name="razon_social" class="form-control form-control-min text-uppercase" required></div>
                                <div class="col-md-4"><label class="label-min">COMERCIAL</label><input type="text" name="comercial" id="comercial" class="form-control form-control-min text-uppercase"></div>
                                <div class="col-md-3"><label class="label-min">CALLE</label><input type="text" name="calle" id="calle" class="form-control form-control-min"></div>
                                <div class="col-md-4"><label class="label-min">COLONIA</label><input type="text" name="colonia" id="colonia" class="form-control form-control-min"></div>
                                <div class="col-md-1"><label class="label-min">EXT</label><input type="text" name="nexterior" id="nexterior" class="form-control form-control-min"></div>
                                <div class="col-md-1"><label class="label-min">INT</label><input type="text" name="ninterior" id="ninterior" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min">REFERENCIA</label><input type="text" name="referencia" id="referencia" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min">CIUDAD</label><input type="text" name="ciudad" id="ciudad" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min">MUNICIPIO</label><input type="text" name="municipio" id="municipio" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min">ESTADO</label><input type="text" name="estado" id="estado" class="form-control form-control-min"></div>
                                <div class="col-md-2"><label class="label-min">PAÍS</label><input type="text" name="pais" id="pais" class="form-control form-control-min"></div>
                                <div class="col-md-1"><label class="label-min">CP</label><input type="text" name="codigopostal" id="codigopostal" class="form-control form-control-min"></div>
                                <div class="col-md-2"><label class="label-min">TELÉFONO</label><input type="text" name="telefono" id="telefono" class="form-control form-control-min"></div>
                                <div class="col-md-2"><label class="label-min">LUGAR EXP.</label><input type="text" name="lugarexpedicion" id="lugarexpedicion" class="form-control form-control-min"></div>
                                <div class="col-md-2"><label class="label-min text-info">RÉGIMEN FISCAL</label><input type="text" name="regimenfiscal" id="regimenfiscal" class="form-control form-control-min"></div>
                                <div class="col-md-12"><label class="label-min">CORREO CONTACTO</label><input type="text" name="correo" id="correo" class="form-control form-control-min"></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="t-cert">
                            <div class="row g-2">
                                <div class="col-md-12 border-bottom border-secondary text-info small fw-bold mb-1">SELLOS FISCALES (CSD)</div>
                                <div class="col-md-3"><label class="label-min text-warning">CSD .CER</label><input type="file" class="form-control form-control-min" id="csd_cer_file" onchange="procesarDoc(this, 'csd')"><input type="hidden" name="csd_b64" id="csd_b64"></div>
                                <div class="col-md-3"><label class="label-min text-warning">CSD .KEY</label><input type="file" class="form-control form-control-min" id="csd_key_file" onchange="procesarDoc(this, 'csd_key')"><input type="hidden" name="csd_key_b64" id="csd_key_b64"></div>
                                <div class="col-md-3"><label class="label-min">PASS CSD</label><input type="password" name="passcsd" id="passcsd" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min text-success">TEST</label><button type="button" class="btn btn-outline-success btn-sm w-100 py-0" onclick="testPareja('csd')">VALIDAR MATCH</button></div>
                                <div class="col-md-12"><div id="vig_csd" class="vigencia-box">SIN INFO</div></div>
                                <div class="col-md-12 mt-1">
                                    <div class="row g-2 align-items-end border border-secondary rounded p-2">
                                        <div class="col-md-3"><label class="label-min text-warning">PFX CSD</label><input type="file" accept=".pfx,.p12,application/x-pkcs12" class="form-control form-control-min" onchange="procesarPfx(this, 'csd')"><input type="hidden" name="pfx_csd_b64" id="pfx_csd_b64"></div>
                                        <div class="col-md-3"><label class="label-min">CONTRASEÑA PFX</label><input type="password" name="pass_pfx_csd" id="pass_pfx_csd" class="form-control form-control-min" autocomplete="new-password"></div>
                                        <div class="col-md-3"><button type="button" class="btn btn-outline-warning btn-sm w-100 py-0" onclick="generarPfx('csd')"><i class="bi bi-gear"></i> GENERAR PFX</button></div>
                                        <div class="col-md-3"><button type="button" class="btn btn-outline-info btn-sm w-100 py-0" onclick="validarPfx('csd')"><i class="bi bi-shield-check"></i> VALIDAR PFX</button></div>
                                        <div class="col-md-12"><div id="estado_pfx_csd" class="vigencia-box">SIN PFX CSD</div></div>
                                    </div>
                                </div>

                                <div class="col-md-12 border-bottom border-secondary text-info small fw-bold mb-1 mt-2">E-FIRMA (FIEL)</div>
                                <div class="col-md-3"><label class="label-min text-info">FIEL .CER</label><input type="file" class="form-control form-control-min" id="fiel_cer_file" onchange="procesarDoc(this, 'fiel')"><input type="hidden" name="fiel_b64" id="fiel_b64"></div>
                                <div class="col-md-3"><label class="label-min text-info">FIEL .KEY</label><input type="file" class="form-control form-control-min" id="fiel_key_file" onchange="procesarDoc(this, 'fiel_key')"><input type="hidden" name="fiel_key_b64" id="fiel_key_b64"></div>
                                <div class="col-md-3"><label class="label-min">PASS FIEL</label><input type="password" name="passfiel" id="passfiel" class="form-control form-control-min"></div>
                                <div class="col-md-3"><label class="label-min text-success">TEST</label><button type="button" class="btn btn-outline-info btn-sm w-100 py-0" onclick="testPareja('fiel')">VALIDAR MATCH</button></div>
                                <div class="col-md-12"><div id="vig_fiel" class="vigencia-box">SIN INFO</div></div>
                                <div class="col-md-12 mt-1">
                                    <div class="row g-2 align-items-end border border-info rounded p-2">
                                        <div class="col-md-3"><label class="label-min text-info">PFX E.FIRMA</label><input type="file" accept=".pfx,.p12,application/x-pkcs12" class="form-control form-control-min" onchange="procesarPfx(this, 'fiel')"><input type="hidden" name="pfx_fiel_b64" id="pfx_fiel_b64"><input type="hidden" name="pfx_fiel_modificado" id="pfx_fiel_modificado" value="0"><input type="hidden" name="pfx_fiel_validacion_confirmada" id="pfx_fiel_validacion_confirmada" value="0"></div>
                                        <div class="col-md-3"><label class="label-min">CONTRASEÑA PFX <span class="text-muted">(si ya lo tienes)</span></label><input type="password" name="pass_pfx_fiel" id="pass_pfx_fiel" class="form-control form-control-min" autocomplete="new-password" placeholder="Solo para PFX cargado"></div>
                                        <div class="col-md-3"><button type="button" class="btn btn-outline-info btn-sm w-100 py-0" onclick="generarPfx('fiel')"><i class="bi bi-gear"></i> GENERAR PFX</button></div>
                                        <div class="col-md-3"><button type="button" class="btn btn-outline-success btn-sm w-100 py-0" onclick="validarPfx('fiel')"><i class="bi bi-shield-check"></i> VALIDAR PFX</button></div>
                                        <div class="col-md-12"><div id="estado_pfx_fiel" class="vigencia-box">SIN PFX E.FIRMA</div></div>
                                    </div>
                                </div>

                                <div class="col-md-12 mt-2">
                                    <div class="border rounded p-2 bg-light sat-auto-box">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-bold text-primary"><i class="bi bi-cloud-arrow-down me-1"></i>DESCARGA AUTOMÁTICA SAT</div>
                                            <div id="badge_sat_auto" class="badge bg-secondary">NO CONFIGURADA</div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-2"><label class="label-min">ESTADO PFX FIEL</label><input type="text" id="pfx_fiel_estado_txt" class="form-control form-control-min" value="SIN VALIDAR" readonly></div>
                                            <div class="col-md-2"><label class="label-min">RFC CERTIFICADO</label><input type="text" id="pfx_fiel_rfc" name="pfx_fiel_rfc" class="form-control form-control-min" readonly></div>
                                            <div class="col-md-2"><label class="label-min">VÁLIDO DESDE</label><input type="text" id="pfx_fiel_fecha_inicio" name="pfx_fiel_fecha_inicio" class="form-control form-control-min" readonly></div>
                                            <div class="col-md-2"><label class="label-min">VENCE EL</label><input type="text" id="pfx_fiel_fecha_vencimiento" name="pfx_fiel_fecha_vencimiento" class="form-control form-control-min" readonly></div>
                                            <div class="col-md-2"><label class="label-min">DÍAS RESTANTES</label><input type="text" id="pfx_fiel_dias_restantes" class="form-control form-control-min" readonly></div>
                                            <div class="col-md-2"><label class="label-min">ÚLTIMA VALIDACIÓN</label><input type="text" id="pfx_fiel_ultima_validacion" name="pfx_fiel_ultima_validacion" class="form-control form-control-min" readonly></div>
                                            <div class="col-md-4 d-flex align-items-end">
                                                <input type="hidden" name="descarga_sat_automatica_val" id="descarga_sat_automatica_val" value="0">
                                                <input type="checkbox" id="descarga_sat_automatica" value="1" class="d-none" tabindex="-1" aria-hidden="true">
                                                <button type="button" id="btn_estado_descarga_sat" class="btn btn-outline-secondary btn-sm w-100 fw-bold" onclick="cambiarEstadoDescargaSat()">
                                                    <i class="bi bi-cloud-slash me-1"></i> DESCARGA SAT DESACTIVADA
                                                </button>
                                            </div>
                                            <div class="col-md-4"><button type="button" class="btn btn-success btn-sm w-100" onclick="validarYActivarSat()"><i class="bi bi-shield-check me-1"></i>VALIDAR Y ACTIVAR</button></div>
                                            <div class="col-md-4"><button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="validarPfx('fiel')"><i class="bi bi-arrow-repeat me-1"></i>VALIDAR PFX FIEL</button></div>
                                            <div class="col-md-12"><div id="mensaje_sat_auto" class="small text-muted">El worker solo procesará empresas con PFX FIEL válido, vigente, RFC coincidente y automatización activada.</div></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="t-mail">
                             <div class="row g-2">
                                <div class="col-md-5"><label class="label-min">SMTP</label><input type="text" name="smptp_correo" id="smptp_correo" class="form-control form-control-min" placeholder="smtp.office365.com"></div>
                                <div class="col-md-2"><label class="label-min">PUERTO</label><input type="number" name="puerto_correo" id="puerto_correo" class="form-control form-control-min" placeholder="587"></div>
                                <div class="col-md-2"><label class="label-min">SEGURIDAD</label><select name="seguridad_correo" id="seguridad_correo" class="form-control form-control-min"><option value="STARTTLS">STARTTLS</option><option value="SSL">SSL/TLS</option><option value="NINGUNA">NINGUNA</option></select></div>
                                <div class="col-md-1 text-center"><label class="label-min">AUT</label><input type="checkbox" name="usa_aut" id="usa_aut" value="1"></div>
                                <div class="col-md-1 text-center"><label class="label-min">SSL LEGACY</label><input type="checkbox" name="usa_ssl" id="usa_ssl" value="1"></div>

                                <div class="col-md-4"><label class="label-min">CORREO REMITENTE</label><input type="email" name="correo_remitente" id="correo_remitente" class="form-control form-control-min" placeholder="facturacion@empresa.com"></div>
                                <div class="col-md-4"><label class="label-min">NOMBRE REMITENTE</label><input type="text" name="nombre_remitente" id="nombre_remitente" class="form-control form-control-min" placeholder="Facturación / Cuentas por pagar"></div>
                                <div class="col-md-4"><label class="label-min">RESPONDER A</label><input type="email" name="reply_to_correo" id="reply_to_correo" class="form-control form-control-min" placeholder="opcional"></div>
                                <div class="col-md-6"><label class="label-min">USUARIO SMTP</label><input type="text" name="smtp_usuario" id="smtp_usuario" class="form-control form-control-min" autocomplete="off"></div>
                                <div class="col-md-6"><label class="label-min">CONTRASEÑA SMTP</label><input type="password" name="smtp_password" id="smtp_password" class="form-control form-control-min" autocomplete="new-password" placeholder="Dejar vacío para conservar la actual"><small id="smtp_password_estado" class="text-muted" style="font-size:.58rem"></small></div>

                                <div class="col-md-12 d-flex justify-content-end mt-1">
                                    <button type="button" class="btn btn-outline-success btn-sm fw-bold" id="btn_probar_correo" onclick="probarCorreoEmpresa()">
                                        <i class="bi bi-envelope-check me-1"></i> PROBAR CORREO
                                    </button>
                                </div>

                                <div class="col-md-12"><label class="label-min">ASUNTO PREDETERMINADO</label><input type="text" name="asunto" id="asunto" class="form-control form-control-min" placeholder="Solicitud de complemento de pago - {{uuid}}"></div>

                                <div class="col-md-12">
                                    <div class="mail-template-box">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                                            <label class="label-min mb-0">CAMPOS PREDETERMINADOS · CLIC PARA INSERTAR</label>
                                            <button type="button" class="btn btn-outline-info btn-sm py-0" onclick="cargarPlantillaComplemento()"><i class="bi bi-envelope-paper me-1"></i>CARGAR PLANTILLA COMPLEMENTO</button>
                                        </div>
                                        <div id="mail_tokens">
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{uuid}}')">{{uuid}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{fecha_factura}}')">{{fecha_factura}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{serie}}')">{{serie}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{folio_factura}}')">{{folio_factura}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{cheque}}')">{{cheque}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{fecha_cheque}}')">{{fecha_cheque}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{importe}}')">{{importe}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{moneda}}')">{{moneda}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{emisor}}')">{{emisor}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{rfc_emisor}}')">{{rfc_emisor}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{forma_pago}}')">{{forma_pago}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{metodo_pago}}')">{{metodo_pago}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{correo_proveedor}}')">{{correo_proveedor}}</span>
                                            <span class="mail-token" onclick="insertarTokenCorreo('{{empresa}}')">{{empresa}}</span>
                                        </div>
                                        <small class="text-muted" style="font-size:.60rem">Estos campos se sustituirán con los datos reales de la factura/pago al preparar el correo. Si un dato no existe, el envío deberá marcarlo antes de continuar.</small>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="label-min mb-0">MENSAJE PREDETERMINADO · HTML</label>
                                        <small class="text-muted" style="font-size:.58rem">Se guarda como HTML y conserva formato al enviar.</small>
                                    </div>
                                    <div class="html-mail-toolbar" id="mensaje_html_toolbar">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Negrita" onclick="formatoCorreoHtml('bold')"><b>B</b></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Cursiva" onclick="formatoCorreoHtml('italic')"><i>I</i></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Subrayado" onclick="formatoCorreoHtml('underline')"><u>U</u></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Lista" onclick="formatoCorreoHtml('insertUnorderedList')">• Lista</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Lista numerada" onclick="formatoCorreoHtml('insertOrderedList')">1. Lista</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Alinear izquierda" onclick="formatoCorreoHtml('justifyLeft')">≡</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Centrar" onclick="formatoCorreoHtml('justifyCenter')">≡</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Insertar enlace" onclick="insertarEnlaceCorreoHtml()">🔗</button>
                                        <select id="mensaje_html_formato" class="form-select form-select-sm" style="width:120px;height:25px;font-size:.68rem" onchange="aplicarBloqueCorreoHtml(this.value);this.value=''">
                                            <option value="">Formato</option>
                                            <option value="p">Párrafo</option>
                                            <option value="h2">Título</option>
                                            <option value="h3">Subtítulo</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Quitar formato" onclick="formatoCorreoHtml('removeFormat')">Limpiar</button>
                                        <button type="button" class="btn btn-outline-info btn-sm ms-auto" id="btn_html_fuente" onclick="alternarFuenteHtmlCorreo()">&lt;/&gt; HTML</button>
                                    </div>
                                    <div id="mensaje_html_editor" contenteditable="true" data-placeholder="Capture el cuerpo del correo..."></div>
                                    <textarea id="mensaje_html_source" class="form-control" spellcheck="false"></textarea>
                                    <textarea name="mensaje" id="mensaje"></textarea>
                                </div>
                                
                                <div class="col-md-6 mt-2">
                                    <div class="d-flex align-items-center gap-2 border p-1 border-secondary rounded">
                                        <div id="prev_logo1" class="preview-logo"></div>
                                        <div class="flex-grow-1"><input type="file" accept="image/*" class="d-none" id="f_l1" onchange="procesarImg(this, 'logo1')"><button type="button" class="btn btn-outline-info btn-sm w-100 py-0" onclick="$('#f_l1').click()">LOGO 1</button><input type="hidden" name="logo1_b64" id="logo1_b64"></div>
                                    </div>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <div class="d-flex align-items-center gap-2 border p-1 border-secondary rounded">
                                        <div id="prev_logo2" class="preview-logo"></div>
                                        <div class="flex-grow-1"><input type="file" accept="image/*" class="d-none" id="f_l2" onchange="procesarImg(this, 'logo2')"><button type="button" class="btn btn-outline-info btn-sm w-100 py-0" onclick="$('#f_l2').click()">LOGO 2</button><input type="hidden" name="logo2_b64" id="logo2_b64"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="t-contpaq">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="alert alert-info py-2 mb-2" style="font-size:.72rem">
                                        <i class="bi bi-shield-lock me-1"></i>
                                        La contraseña de SQL Server se guarda cifrada en MySQL. La llave de cifrado permanece fuera de la base de datos y fuera de htdocs.
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label class="label-min text-success">ESTADO CONEXIÓN</label>
                                    <input type="hidden" id="contpaq_activo_val" value="0">
                                    <button type="button" id="btn_estado_contpaq" class="btn btn-outline-secondary btn-sm w-100 fw-bold" onclick="cambiarEstadoContpaq()">
                                        <i class="bi bi-plug me-1"></i> DESACTIVADA
                                    </button>
                                </div>
                                <div class="col-md-5">
                                    <label class="label-min">SERVIDOR / INSTANCIA</label>
                                    <input type="text" id="contpaq_servidor" class="form-control form-control-min" value="172.16.13.11\OHLALA" placeholder="172.16.13.11\OHLALA">
                                </div>
                                <div class="col-md-5">
                                    <label class="label-min">BASE DE DATOS BANCOS</label>
                                    <input type="text" id="contpaq_base_datos" class="form-control form-control-min" placeholder="Ej. ctPRODUCTORA_2025">
                                </div>
                                <div class="col-md-5">
                                    <label class="label-min text-info">BASE DE DATOS COMERCIAL</label>
                                    <input type="text" id="contpaq_base_datos_comercial" class="form-control form-control-min border-info" placeholder="Ej. adPAOH">
                                </div>
                                <div class="col-md-3">
                                    <label class="label-min">USUARIO SQL SERVER</label>
                                    <input type="text" id="contpaq_usuario" class="form-control form-control-min" autocomplete="off">
                                </div>
                                <div class="col-md-3">
                                    <label class="label-min">CONTRASEÑA SQL SERVER</label>
                                    <input type="password" id="contpaq_password" class="form-control form-control-min" autocomplete="new-password" placeholder="Dejar vacío para conservar">
                                </div>
                                <div class="col-md-6">
                                    <label class="label-min">ESTADO</label>
                                    <div id="contpaq_estado" class="vigencia-box">SIN CONFIGURAR</div>
                                </div>
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-outline-info btn-sm w-100" onclick="probarConfigContpaq()">
                                        <i class="bi bi-plug me-1"></i> PROBAR CONEXIÓN
                                    </button>
                                </div>
                                <div class="col-md-6">
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">
                                        Recomendado: usar un usuario SQL Server exclusivo de solo lectura para Cheques y DispersionesPagos; no usar <b>sa</b>.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="t-oracle-pagos">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="alert alert-info py-2 mb-2" style="font-size:.72rem">
                                        <i class="bi bi-shield-lock me-1"></i>
                                        Configuración independiente de CONTPAQi. Aquí solo se guardan <b>unidad de negocio, usuario y contraseña Oracle</b>.
                                        La contraseña se almacena cifrada.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="label-min">UNIDAD DE NEGOCIO</label>
                                    <input type="text" id="oracle_pagos_unidad" class="form-control form-control-min text-uppercase" placeholder="Ej. FERRO_UN">
                                </div>
                                <div class="col-md-4">
                                    <label class="label-min">USUARIO ORACLE</label>
                                    <input type="text" id="oracle_pagos_usuario" class="form-control form-control-min" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="label-min">CONTRASEÑA ORACLE</label>
                                    <input type="password" id="oracle_pagos_password" class="form-control form-control-min" autocomplete="new-password" placeholder="Dejar vacío para conservar">
                                </div>
                                <div class="col-md-8">
                                    <div id="oracle_pagos_estado" class="vigencia-box">SIN CONFIGURAR</div>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-info btn-sm w-100" onclick="probarOraclePagos()">
                                        <i class="bi bi-plug"></i> PROBAR
                                    </button>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-success btn-sm w-100" onclick="guardarOraclePagos()">
                                        <i class="bi bi-save"></i> GUARDAR ORACLE
                                    </button>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">
                                        Esta pestaña se guarda por separado. <b>No modifica la configuración CONTPAQi</b> ni participa en el botón Guardar Empresa.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1 border-secondary text-end">
                    <button type="submit" class="btn btn-info btn-sm fw-bold text-dark">GUARDAR EMPRESA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Control de carga del modal para que el autocompletado del navegador no
// apague visualmente la automatización SAT al abrir una empresa.
window.cargandoEmpresa = false;
window.estadoDescargaEmpresaEdicion = false;
window.passwordPfxFielOriginal = '';
window.cambiandoEstadoContpaq = false;
window.CONTPAQ_SERVIDOR_DEFAULT = '172.16.13.11\\OHLALA';


function limpiarOraclePagos() {
    $('#oracle_pagos_unidad').val('');
    $('#oracle_pagos_usuario').val('');
    $('#oracle_pagos_password').val('');
    $('#oracle_pagos_estado').html('SIN CONFIGURAR');
}

function cargarOraclePagos(idEmpresa) {
    limpiarOraclePagos();
    if(!idEmpresa){
        $('#oracle_pagos_estado').html('<span class="text-warning">GUARDA PRIMERO LA EMPRESA</span>');
        return;
    }
    $.getJSON('backend/oracle_pagos_config.php',{accion:'obtener',id_empresa:idEmpresa})
      .done(function(r){
        if(!r.success||!r.config){
            $('#oracle_pagos_estado').html('<span class="text-muted">SIN CONFIGURAR</span>');
            return;
        }
        const c=r.config;
        $('#oracle_pagos_unidad').val(c.unidad_negocio||'');
        $('#oracle_pagos_usuario').val(c.usuario||'');
        $('#oracle_pagos_password').val('');
        let txt='<span class="text-success"><i class="bi bi-check-circle"></i> CONFIGURADO';
        if(Number(c.tiene_password||0)===1)txt+=' · CONTRASEÑA CIFRADA';
        txt+='</span>';
        if(c.ultima_prueba)txt+='<br><small>Última prueba: '+$('<div>').text(c.ultima_prueba).html()+'</small>';
        if(c.ultima_sincronizacion)txt+='<br><small>Última importación: '+$('<div>').text(c.ultima_sincronizacion).html()+'</small>';
        $('#oracle_pagos_estado').html(txt);
      })
      .fail(function(){
        $('#oracle_pagos_estado').html('<span class="text-danger">NO SE PUDO LEER LA CONFIGURACIÓN</span>');
      });
}

function datosOraclePagos(accion) {
    const id=$('#id_empresa').val();
    if(!id){
        Swal.fire('Guarda primero','Primero guarda la empresa y después configura Oracle Pagos.','warning');
        return null;
    }
    return {
        accion:accion,id_empresa:id,
        unidad_negocio:($('#oracle_pagos_unidad').val()||'').trim().toUpperCase(),
        usuario:($('#oracle_pagos_usuario').val()||'').trim(),
        password:$('#oracle_pagos_password').val()||'',
        csrf_token:<?=json_encode($csrfContpaq)?>
    };
}

function probarOraclePagos() {
    const d=datosOraclePagos('probar'); if(!d)return;
    Swal.fire({title:'Probando Oracle...',text:'Validando la unidad y el usuario.',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    $.post('backend/oracle_pagos_config.php',d,function(r){
        if(r.success)Swal.fire('Conexión correcta',r.message,'success');
        else Swal.fire('Error',r.error||'No fue posible conectar.','error');
    },'json').fail(function(xhr){
        let m='No fue posible conectar con Oracle.';
        try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
        Swal.fire('Error',m,'error');
    });
}

function guardarOraclePagos() {
    const d=datosOraclePagos('guardar'); if(!d)return;
    $.post('backend/oracle_pagos_config.php',d,function(r){
        if(!r.success)return Swal.fire('Error',r.error||'No se pudo guardar Oracle Pagos.','error');
        $('#oracle_pagos_password').val('');
        cargarOraclePagos($('#id_empresa').val());
        Swal.fire({icon:'success',title:'Oracle Pagos guardado',text:r.message,timer:1300,showConfirmButton:false});
    },'json').fail(function(xhr){
        let m='No se pudo guardar Oracle Pagos.';
        try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
        Swal.fire('Error',m,'error');
    });
}

function aplicarEstadoDescargaSat(activa) {
    estadoDescargaEmpresaEdicion = !!activa;
    $('#descarga_sat_automatica').prop('checked', estadoDescargaEmpresaEdicion);
    $('#descarga_sat_automatica_val').val(estadoDescargaEmpresaEdicion ? '1' : '0');

    const $boton = $('#btn_estado_descarga_sat');
    if (estadoDescargaEmpresaEdicion) {
        $boton
            .removeClass('btn-outline-secondary btn-outline-danger')
            .addClass('btn-success')
            .html('<i class="bi bi-cloud-check-fill me-1"></i> DESCARGA SAT ACTIVADA');
    } else {
        $boton
            .removeClass('btn-success btn-outline-danger')
            .addClass('btn-outline-secondary')
            .html('<i class="bi bi-cloud-slash me-1"></i> DESCARGA SAT DESACTIVADA');
    }
}

function aplicarEstadoContpaq(activo) {
    const encendido = !!activo;
    $('#contpaq_activo_val').val(encendido ? '1' : '0');

    const $boton = $('#btn_estado_contpaq');
    if (encendido) {
        $boton
            .removeClass('btn-outline-secondary btn-outline-danger btn-warning')
            .addClass('btn-success')
            .html('<i class="bi bi-plug-fill me-1"></i> ACTIVADA');
    } else {
        $boton
            .removeClass('btn-success btn-warning')
            .addClass('btn-outline-secondary')
            .html('<i class="bi bi-plug me-1"></i> DESACTIVADA');
    }
}

function cambiarEstadoContpaq() {
    if (cargandoEmpresa || cambiandoEstadoContpaq) return;

    const id = $('#id_empresa').val();
    if (!id) {
        aplicarEstadoContpaq(false);
        Swal.fire('Guarda primero', 'Primero guarda la empresa y después activa CONTPAQi.', 'warning');
        return;
    }

    const estadoActual = Number($('#contpaq_activo_val').val() || 0) === 1;
    const nuevoEstado = estadoActual ? 0 : 1;

    const ejecutar = function() {
        cambiandoEstadoContpaq = true;
        const $boton = $('#btn_estado_contpaq');
        $boton.prop('disabled', true);

        $.post('backend/contpaq_config.php', {
            accion: 'estado',
            id_empresa: id,
            activo: nuevoEstado,
            csrf_token: <?=json_encode($csrfContpaq)?>
        }, function(r) {
            if (!r.success) {
                aplicarEstadoContpaq(estadoActual);
                Swal.fire('No se pudo guardar', r.error || 'No fue posible cambiar el estado.', 'error');
                return;
            }

            aplicarEstadoContpaq(Number(r.activo) === 1);
            cargarConfigContpaq(id);
        }, 'json').fail(function(xhr) {
            aplicarEstadoContpaq(estadoActual);
            let m = 'No fue posible guardar el estado de CONTPAQi.';
            try { const r = JSON.parse(xhr.responseText); if (r.error) m = r.error; } catch(e) {}
            Swal.fire('Error', m, 'error');
        }).always(function() {
            $boton.prop('disabled', false);
            cambiandoEstadoContpaq = false;
        });
    };

    if (nuevoEstado === 0) {
        Swal.fire({
            title: '¿Desactivar CONTPAQi?',
            text: 'Se detendrán las sincronizaciones de Cheques y Dispersiones de esta empresa.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, desactivar',
            cancelButtonText: 'Cancelar'
        }).then(function(resultado) {
            if (resultado.isConfirmed) ejecutar();
        });
        return;
    }

    ejecutar();
}

function cambiarEstadoDescargaSat() {
    if (estadoDescargaEmpresaEdicion) {
        Swal.fire({
            title: '¿Desactivar descarga SAT?',
            text: 'El worker dejará de procesar automáticamente esta empresa.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, desactivar',
            cancelButtonText: 'Cancelar'
        }).then(function(resultado) {
            if (resultado.isConfirmed) aplicarEstadoDescargaSat(false);
        });
        return;
    }

    validarYActivarSat();
}

$(document).ready(function() {
    const tablaEmpresasDT = $('#tablaEmpresas').DataTable({
        language: {url:'//cdn.datatables.net/plug-ins/1.13.4/i18n/es-MX.json'},
        pageLength: 10,
        dom: 'ftip'
    });

    // Edición rápida con doble clic. El evento se delega sobre document porque
    // cargarModulo('empresas') reemplaza por completo la tabla después de guardar
    // o cerrar el módulo. Así continúa funcionando con la tabla recién creada.
    $(document)
        .off('dblclick.empresas', '#tablaEmpresas tbody tr.fila-empresa')
        .on('dblclick.empresas', '#tablaEmpresas tbody tr.fila-empresa', function(e) {
            if ($(e.target).closest('button, a, input, select').length) return;

            const datos = $(this).attr('data-empresa');
            if (!datos) return;

            try {
                editarEmpresa(JSON.parse(datos));
            } catch (error) {
                console.error('No se pudieron cargar los datos de la empresa:', error);
                Swal.fire('Error', 'No se pudieron abrir los datos de la empresa.', 'error');
            }
        });


    $(document)
        .off('click.empresasEditar', '#tablaEmpresas .btn-editar-empresa')
        .on('click.empresasEditar', '#tablaEmpresas .btn-editar-empresa', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const datos = $(this).closest('tr.fila-empresa').attr('data-empresa');
            if (!datos) return;
            try { editarEmpresa(JSON.parse(datos)); }
            catch (error) {
                console.error('No se pudieron cargar los datos de la empresa:', error);
                Swal.fire('Error', 'No se pudieron abrir los datos de la empresa.', 'error');
            }
        });

    $(document)
        .off('click.empresasEstado', '#tablaEmpresas .btn-estado-empresa')
        .on('click.empresasEstado', '#tablaEmpresas .btn-estado-empresa', function(e) {
            e.preventDefault();
            e.stopPropagation();
            cambiarEstadoEmpresa(Number($(this).data('id')), String($(this).data('rfc') || ''), Number($(this).data('activo')) === 1);
        });


    $('#rfc').on('input', function() {
        let v = $(this).val().trim().toUpperCase(); $(this).val(v);
        $('#tipopersona').val(v.length === 12 ? 'MORAL' : (v.length === 13 ? 'FISICA' : ''));
    });

    $('#pass_pfx_fiel').on('input change', function(){
        if (cargandoEmpresa || !$('#id_empresa').val()) return;

        const passwordActual = String($(this).val() || '');
        if (passwordActual === passwordPfxFielOriginal) return;

        $('#pfx_fiel_modificado').val('1');
        $('#pfx_fiel_validacion_confirmada').val('0');
        aplicarEstadoDescargaSat(false);
    });

    $('#descarga_sat_automatica').on('change', function(){
        if (this.checked && $('#pfx_fiel_estado_txt').val().indexOf('VÁLIDO') !== 0) {
            this.checked = false;
            $('#descarga_sat_automatica_val').val('0');
            Swal.fire('Primero valida', 'Usa el botón VALIDAR Y ACTIVAR para habilitar la descarga automática.', 'warning');
            return;
        }
        $('#descarga_sat_automatica_val').val(this.checked ? '1' : '0');
    });

    // Guardado delegado: sigue funcionando aunque el módulo de empresas se vuelva
    // a cargar por AJAX y evita que el formulario navegue a main.php con parámetros.
    $(document)
        .off('submit.empresas', '#formEmpresa')
        .on('submit.empresas', '#formEmpresa', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const $form = $(this);
            sincronizarMensajeHtml();
            const $btnGuardar = $form.find('button[type="submit"]');
            $('#descarga_sat_automatica_val').val(estadoDescargaEmpresaEdicion ? '1' : '0');
            $btnGuardar.prop('disabled', true).text('GUARDANDO...');

            $.post('backend/empresas_operaciones.php', $form.serialize() + '&accion=guardar', function(res) {
                if (res.success) {
                    const idGuardado = Number(res.id_empresa || $('#id_empresa').val() || 0);
                    if (idGuardado > 0) {
                        $('#id_empresa').val(idGuardado);
                    }

                    // La configuración CONTPAQi se guarda desde su propia pestaña.
                    // Antes se intentaba guardar/probar SQL Server aquí y eso podía
                    // dejar GUARDANDO... varios segundos si el servidor no respondía.
                    $('#contpaq_password').val('');

                    Swal.fire({
                        icon: 'success',
                        title: 'Guardado',
                        text: 'Empresa guardada correctamente.',
                        timer: 900,
                        showConfirmButton: false
                    });

                    const $modal = $('#modalEmpresa');
                    $modal.one('hidden.bs.modal.empresas', function() {
                        cargarModulo('empresas');
                    });
                    $modal.modal('hide');
                } else {
                    $btnGuardar.prop('disabled', false).text('GUARDAR EMPRESA');
                    Swal.fire('Error', res.error || 'No fue posible guardar la empresa.', 'error');
                }
            }, 'json').fail(function(xhr) {
                $btnGuardar.prop('disabled', false).text('GUARDAR EMPRESA');
                Swal.fire('Error', 'No fue posible comunicarse con el servidor.', 'error');
                console.error(xhr.responseText || xhr.statusText);
            });

            return false;
        });
});

function probarCorreoEmpresa() {
    const idEmpresa = Number($('#id_empresa').val() || 0);
    if (idEmpresa <= 0) {
        Swal.fire('Guarde primero', 'Primero guarde la empresa y después pruebe la configuración de correo.', 'warning');
        return;
    }

    const sugerido = ($('#correo_remitente').val() || $('#smtp_usuario').val() || '').trim();
    Swal.fire({
        title: 'Probar configuración de correo',
        html: '<div class="text-start small mb-2">Se enviará un mensaje real para comprobar conexión, seguridad, autenticación y entrega SMTP.</div>' +
              '<label class="form-label small fw-bold mb-1">Correo destino de prueba</label>' +
              '<input id="correo_prueba_destino" type="email" class="swal2-input m-0 w-100" style="box-sizing:border-box" value="' + $('<div>').text(sugerido).html() + '" placeholder="usuario@dominio.com">',
        showCancelButton: true,
        confirmButtonText: 'ENVIAR PRUEBA',
        cancelButtonText: 'CANCELAR',
        focusConfirm: false,
        preConfirm: () => {
            const correo = ($('#correo_prueba_destino').val() || '').trim();
            if (!correo || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
                Swal.showValidationMessage('Capture un correo destino válido.');
                return false;
            }
            return correo;
        }
    }).then(function(r) {
        if (!r.isConfirmed) return;

        const destino = r.value;
        const $btn = $('#btn_probar_correo');
        const textoOriginal = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> PROBANDO...');

        const datos = {
            id_empresa: idEmpresa,
            destino: destino,
            smptp_correo: ($('#smptp_correo').val() || '').trim(),
            puerto_correo: ($('#puerto_correo').val() || '').trim(),
            seguridad_correo: $('#seguridad_correo').val() || 'STARTTLS',
            usa_aut: $('#usa_aut').is(':checked') ? 1 : 0,
            smtp_usuario: ($('#smtp_usuario').val() || '').trim(),
            smtp_password: $('#smtp_password').val() || '',
            correo_remitente: ($('#correo_remitente').val() || '').trim(),
            nombre_remitente: ($('#nombre_remitente').val() || '').trim(),
            reply_to_correo: ($('#reply_to_correo').val() || '').trim()
        };

        $.ajax({
            url: 'backend/probar_correo_empresa.php',
            method: 'POST',
            dataType: 'json',
            data: datos,
            timeout: 30000
        }).done(function(resp) {
            if (resp && resp.success) {
                const tiempo = resp.elapsed_ms ? '<br><small>Tiempo: ' + resp.elapsed_ms + ' ms</small>' : '';
                Swal.fire({
                    icon: 'success',
                    title: 'Correo de prueba enviado',
                    html: 'La configuración SMTP respondió correctamente y se envió el mensaje a <b>' + $('<div>').text(destino).html() + '</b>.' + tiempo
                });
            } else {
                Swal.fire('No se pudo enviar', (resp && resp.error) ? resp.error : 'La prueba SMTP no fue satisfactoria.', 'error');
            }
        }).fail(function(xhr) {
            let mensaje = 'No fue posible completar la prueba SMTP.';
            try {
                const rr = JSON.parse(xhr.responseText || '{}');
                if (rr.error) mensaje = rr.error;
            } catch (e) {}
            Swal.fire('No se pudo enviar', mensaje, 'error');
        }).always(function() {
            $btn.prop('disabled', false).html(textoOriginal);
        });
    });
}

function cargarVigencia(b64, d) {
    if(!b64) return;
    $.post('backend/validar_sellos.php', { cert_b64: b64 }, function(res) {
        if(res.success) {
            $(`#vig_${d}`).html(`<div class="w-100 d-flex justify-content-between px-2"><span><b>CREACIÓN:</b> ${res.creacion}</span><span><b>VENCIMIENTO:</b> <b class="text-info">${res.vencimiento}</b></span></div>`);
        }
    }, 'json');
}

function procesarDoc(input, d) {
    if (input.files && input.files[0]) {
        let r = new FileReader();
        r.onload = e => { 
            let b = e.target.result.split(',')[1];
            $(`#${d}_b64`).val(b); 
            if(d === 'csd' || d === 'fiel') cargarVigencia(b, d);
        };
        r.readAsDataURL(input.files[0]);
    }
}

function procesarPfx(input, tipo) {
    if (!input.files || !input.files[0]) return;
    const archivo = input.files[0];
    const lector = new FileReader();
    lector.onload = function(e) {
        const b64 = e.target.result.split(',')[1];
        $(`#pfx_${tipo}_b64`).val(b64);
        if (tipo === 'fiel') {
            $('#pfx_fiel_modificado').val('1');
            $('#pfx_fiel_validacion_confirmada').val('0');
            aplicarEstadoDescargaSat(false);
        }
        $(`#estado_pfx_${tipo}`).html(`<span class="text-warning"><i class="bi bi-file-earmark-lock"></i> PFX SELECCIONADO: ${archivo.name}</span>`);
    };
    lector.readAsDataURL(archivo);
}

function generarPfx(tipo) {
    const idEmpresa = $('#id_empresa').val();
    const cert = $(`#${tipo}_b64`).val();
    const key = $(`#${tipo}_key_b64`).val();
    const passKey = $(`#pass${tipo}`).val();
    const rfc = $('#rfc').val().trim().toUpperCase();

    // Para generar un PFX usamos la contraseña de la llave (FIEL/CSD)
    // también como contraseña del PFX generado. El campo CONTRASEÑA PFX
    // queda reservado principalmente para cuando el usuario YA trae un PFX.
    //
    // Si la empresa ya está guardada, el backend puede tomar CER, KEY y
    // contraseña directamente de la base; no se obliga a volver a cargarlos.
    const hayArchivosEnPantalla = !!(cert && key && passKey);
    if (!idEmpresa && !hayArchivosEnPantalla) {
        return Swal.fire(
            'Datos incompletos',
            'Primero selecciona CER, KEY y captura la contraseña de la FIEL, o guarda la empresa para reutilizar la FIEL almacenada.',
            'warning'
        );
    }

    Swal.fire({
        title:'Generando PFX...',
        text: idEmpresa ? 'Usando la FIEL almacenada de la empresa' : 'Usando CER, KEY y contraseña de la FIEL',
        allowOutsideClick:false,
        didOpen:()=>Swal.showLoading()
    });

    const datos = {
        accion: 'generar',
        tipo: tipo,
        id_empresa: idEmpresa || '',
        rfc_empresa: rfc
    };

    // Si el usuario acaba de seleccionar una FIEL nueva, ésta tiene prioridad.
    if (hayArchivosEnPantalla) {
        datos.cert_b64 = cert;
        datos.key_b64 = key;
        datos.password_key = passKey;
    }

    $.post('backend/generar_pfx.php', datos, function(res) {
        if (!res.success) {
            return Swal.fire('No se pudo generar', res.error || 'Error desconocido', 'error');
        }

        if (res.pfx_b64) {
            $(`#pfx_${tipo}_b64`).val(res.pfx_b64);
        }

        // El PFX generado usa la misma contraseña de la FIEL/KEY.
        if (res.password_pfx) {
            $(`#pass_pfx_${tipo}`).val(res.password_pfx);
        } else if (passKey) {
            $(`#pass_pfx_${tipo}`).val(passKey);
        }

        if (tipo === 'fiel') {
            // Si el backend lo guardó directamente por tratarse de una empresa
            // existente, ya no marcamos el PFX como pendiente de guardado.
            $('#pfx_fiel_modificado').val(res.guardado ? '0' : '1');
            $('#pfx_fiel_validacion_confirmada').val('0');
            aplicarEstadoDescargaSat(false);
        }

        $(`#estado_pfx_${tipo}`).html(
            `<div class="w-100 d-flex justify-content-between">` +
            `<span class="text-success"><i class="bi bi-check-circle"></i> PFX GENERADO Y VALIDADO${res.guardado ? ' / ALMACENADO' : ''}</span>` +
            `<span>RFC: <b>${res.rfc || rfc}</b> | VENCE: <b>${res.vencimiento || ''}</b></span>` +
            `</div>`
        );

        Swal.fire(
            'PFX generado',
            res.guardado
                ? 'Se generó desde la FIEL almacenada y quedó guardado para esta empresa.'
                : 'Se generó desde CER, KEY y contraseña de la FIEL. Guarda la empresa para almacenarlo.',
            'success'
        );
    }, 'json').fail(function(xhr){
        Swal.fire('Error', xhr.responseText || 'No respondió el servidor', 'error');
    });
}

function validarPfx(tipo) {
    const pfx = $(`#pfx_${tipo}_b64`).val();
    const pass = $(`#pass_pfx_${tipo}`).val();
    if (!pfx || !pass) return Swal.fire('Datos incompletos', 'Selecciona o genera el PFX y captura su contraseña.', 'warning');
    $.post('backend/generar_pfx.php', {accion:'validar', tipo:tipo, pfx_b64:pfx, password_pfx:pass, rfc_empresa:$('#rfc').val()}, function(res){
        if (!res.success) return Swal.fire('PFX inválido', res.error || 'No se pudo abrir', 'error');
        $(`#estado_pfx_${tipo}`).html(`<div class="w-100 d-flex justify-content-between"><span class="text-success"><i class="bi bi-shield-check"></i> PFX VÁLIDO</span><span>RFC: <b>${res.rfc || ''}</b> | VENCE: <b>${res.vencimiento || ''}</b></span></div>`);
        if (tipo === 'fiel') actualizarEstadoSat(res);
        Swal.fire('Correcto', tipo === 'fiel' ? 'El PFX FIEL es válido. Usa VALIDAR Y ACTIVAR para habilitar el worker.' : 'El PFX y su contraseña son válidos.', 'success');
    }, 'json');
}

function diasEntre(fechaIso) {
    if (!fechaIso) return '';
    const hoy = new Date(); hoy.setHours(0,0,0,0);
    const vence = new Date(fechaIso + 'T00:00:00');
    return Math.ceil((vence - hoy) / 86400000);
}

function actualizarEstadoSat(datos) {
    const validado = String(datos.pfx_fiel_validado || '0') === '1';
    const vence = datos.pfx_fiel_fecha_vencimiento || '';
    const dias = diasEntre(vence);
    $('#pfx_fiel_rfc').val(datos.pfx_fiel_rfc || '');
    $('#pfx_fiel_fecha_inicio').val(datos.pfx_fiel_fecha_inicio || '');
    $('#pfx_fiel_fecha_vencimiento').val(vence || '');
    $('#pfx_fiel_dias_restantes').val(dias === '' ? '' : dias);
    $('#pfx_fiel_ultima_validacion').val(datos.pfx_fiel_ultima_validacion || '');
    const automaticaActiva = Number(datos.descarga_sat_automatica || 0) === 1;
    aplicarEstadoDescargaSat(automaticaActiva);
    let texto='SIN VALIDAR', clase='bg-secondary';
    if (validado && dias >= 0) { texto = dias <= 30 ? 'VÁLIDO - VENCE PRONTO' : 'VÁLIDO Y VIGENTE'; clase = dias <= 30 ? 'bg-warning text-dark' : 'bg-success'; }
    if (validado && dias < 0) { texto='VENCIDO'; clase='bg-danger'; }
    $('#pfx_fiel_estado_txt').val(texto);
    $('#badge_sat_auto').attr('class','badge ' + clase).text(texto);
}

function validarYActivarSat() {
    const pfx = $('#pfx_fiel_b64').val();
    const pass = $('#pass_pfx_fiel').val();
    const id = $('#id_empresa').val();
    if (!id) return Swal.fire('Guarda primero', 'Primero guarda la empresa y después activa la descarga automática.', 'warning');
    if (!pfx || !pass) return Swal.fire('Datos incompletos', 'Se necesita el PFX de la FIEL y su contraseña.', 'warning');
    Swal.fire({title:'Validando PFX FIEL...', text:'Comprobando RFC, vigencia y contraseña', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    $.post('backend/generar_pfx.php', {accion:'validar_activar', id_empresa:id, pfx_b64:pfx, password_pfx:pass, rfc_empresa:$('#rfc').val()}, function(res){
        if (!res.success) {
            aplicarEstadoDescargaSat(false);
            return Swal.fire('No se puede activar', res.error || 'El PFX no es válido', 'error');
        }
        // La validación ya quedó persistida por generar_pfx.php.
        // Evitamos que GUARDAR EMPRESA la invalide de nuevo.
        $('#pfx_fiel_modificado').val('0');
        $('#pfx_fiel_validacion_confirmada').val('1');
        aplicarEstadoDescargaSat(true);
        actualizarEstadoSat(res);
        aplicarEstadoDescargaSat(true);
        $('#estado_pfx_fiel').html(`<div class="w-100 d-flex justify-content-between"><span class="text-success"><i class="bi bi-shield-check"></i> PFX FIEL VÁLIDO PARA DESCARGA SAT</span><span>RFC: <b>${res.pfx_fiel_rfc || ''}</b> | VENCE: <b>${res.pfx_fiel_fecha_vencimiento || ''}</b></span></div>`);
        Swal.fire('Automatización activada', 'La empresa quedó lista para que el worker procese descargas SAT.', 'success');
    }, 'json').fail(function(xhr){ Swal.fire('Error', xhr.responseText || 'No respondió el servidor', 'error'); });
}

function procesarImg(input, d) {
    if (input.files && input.files[0]) {
        let r = new FileReader();
        r.onload = e => { $(`#prev_${d}`).html(`<img src="${e.target.result}">`); $(`#${d}_b64`).val(e.target.result.split(',')[1]); };
        r.readAsDataURL(input.files[0]);
    }
}

function testPareja(p) {
    const cerInput = document.getElementById(`${p}_cer_file`);
    const keyInput = document.getElementById(`${p}_key_file`);
    const ps = $(`#pass${p}`).val();

    if (!cerInput || !cerInput.files.length || !keyInput || !keyInput.files.length || !ps) {
        return Swal.fire('Error', 'Faltan archivos o clave', 'error');
    }

    // Enviar los archivos binarios originales, igual que verificador.php.
    const fd = new FormData();
    fd.append('file_cer', cerInput.files[0]);
    fd.append('file_key', keyInput.files[0]);
    fd.append('password', ps);
    fd.append('validar_match', '1');

    $.ajax({
        url: 'backend/validar_sellos.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json'
    }).done(function(res) {
        if (res.success) Swal.fire('Éxito', 'La llave y el certificado coinciden', 'success');
        else Swal.fire('Error', res.error || 'No fue posible validar CER/KEY', 'error');
    }).fail(function(xhr) {
        Swal.fire('Error', xhr.responseText || 'No respondió el servidor', 'error');
    });
}

function cambiarEstadoEmpresa(id, rfc, estaActiva) {
    const nuevoEstado = estaActiva ? 0 : 1;
    Swal.fire({
        title: estaActiva ? '¿Desactivar empresa?' : '¿Reactivar empresa?',
        text: 'RFC: ' + rfc + (estaActiva ? ' · Se conservará todo su historial y configuración.' : ' · La empresa volverá a estar disponible.'),
        icon: estaActiva ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: estaActiva ? '#d33' : '#198754',
        confirmButtonText: estaActiva ? 'Sí, desactivar' : 'Sí, reactivar',
        cancelButtonText: 'Cancelar',
        background: '#161b22', color: '#fff'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('backend/empresas_operaciones.php', {id_empresa:id, accion:'estado', activo:nuevoEstado}, function(res) {
            if (res.success) cargarModulo('empresas');
            else Swal.fire('Error', res.error || 'No fue posible cambiar el estado de la empresa.', 'error');
        }, 'json').fail(function(xhr){
            Swal.fire('Error', 'No fue posible comunicarse con el servidor.', 'error');
            console.error(xhr.responseText || xhr.statusText);
        });
    });
}


function limpiarConfigContpaq() {
    aplicarEstadoContpaq(false);
    $('#contpaq_servidor').val(CONTPAQ_SERVIDOR_DEFAULT);
    $('#contpaq_base_datos').val('');
    $('#contpaq_base_datos_comercial').val('');
    $('#contpaq_usuario').val('sgkconta');
    $('#contpaq_password').val('');
    $('#contpaq_estado').html('SIN CONFIGURAR');
}

function cargarConfigContpaq(idEmpresa) {
    limpiarConfigContpaq();
    if (!idEmpresa) {
        $('#contpaq_estado').html('<span class="text-warning">GUARDA PRIMERO LA EMPRESA</span>');
        return;
    }
    $.getJSON('backend/contpaq_config.php', {accion:'obtener', id_empresa:idEmpresa})
        .done(function(r){
            if (!r.success || !r.config) {
                $('#contpaq_estado').html('<span class="text-muted">SIN CONFIGURAR</span>');
                return;
            }
            const c=r.config;
            aplicarEstadoContpaq(Number(c.activo||0)===1);
            $('#contpaq_servidor').val(c.servidor || CONTPAQ_SERVIDOR_DEFAULT);
            $('#contpaq_base_datos').val(c.base_datos || '');
            $('#contpaq_base_datos_comercial').val(c.base_datos_comercial || '');
            $('#contpaq_usuario').val(c.usuario || 'sgkconta');
            $('#contpaq_password').val('');
            let txt = Number(c.activo||0)===1 ? '<span class="text-success"><i class="bi bi-check-circle"></i> CONFIGURADO' : '<span class="text-warning">DESACTIVADO';
            if (Number(c.tiene_password||0)===1) txt += ' · CONTRASEÑA CIFRADA';
            txt += '</span>';
            if (c.ultima_prueba) txt += '<br><small>Última prueba: '+ $('<div>').text(c.ultima_prueba).html() +'</small>';
            $('#contpaq_estado').html(txt);
        })
        .fail(function(){ $('#contpaq_estado').html('<span class="text-danger">NO SE PUDO LEER LA CONFIGURACIÓN</span>'); });
}

function datosConfigContpaq(accion) {
    const id=$('#id_empresa').val();
    if(!id) {
        Swal.fire('Guarda primero','Primero guarda la empresa y vuelve a abrirla para configurar CONTPAQi.','warning');
        return null;
    }
    return {
        accion:accion,
        id_empresa:id,
        activo:Number($('#contpaq_activo_val').val() || 0) === 1 ? 1 : 0,
        servidor:$('#contpaq_servidor').val().trim(),
        base_datos:$('#contpaq_base_datos').val().trim(),
        base_datos_comercial:$('#contpaq_base_datos_comercial').val().trim(),
        usuario:$('#contpaq_usuario').val().trim(),
        password:$('#contpaq_password').val(),
        csrf_token:<?=json_encode($csrfContpaq)?>
    };
}


function guardarConfigContpaqSilencioso(idEmpresa) {
    const servidor = ($('#contpaq_servidor').val() || '').trim();
    const baseBancos = ($('#contpaq_base_datos').val() || '').trim();
    const baseComercial = ($('#contpaq_base_datos_comercial').val() || '').trim();
    const usuario = ($('#contpaq_usuario').val() || '').trim();
    const password = $('#contpaq_password').val() || '';

    if (!servidor && !baseBancos && !baseComercial && !usuario && !password) {
        return $.Deferred().resolve({success:true, omitido:true}).promise();
    }

    return $.ajax({
        url: 'backend/contpaq_config.php',
        type: 'POST',
        dataType: 'json',
        data: {
            accion: 'guardar',
            id_empresa: idEmpresa,
            activo: Number($('#contpaq_activo_val').val() || 0) === 1 ? 1 : 0,
            servidor: servidor,
            base_datos: baseBancos,
            base_datos_comercial: baseComercial,
            usuario: usuario,
            password: password,
            csrf_token: <?=json_encode($csrfContpaq)?>
        }
    });
}

function probarConfigContpaq() {
    const d=datosConfigContpaq('probar'); if(!d)return;
    d.csrf_token=<?=json_encode($csrfContpaq)?>;
    Swal.fire({title:'Probando SQL Server...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    $.post('backend/contpaq_config.php',d,function(r){
        if(r.success) Swal.fire('Conexión correcta',r.message,'success');
        else Swal.fire('No se pudo conectar',r.error||'Error desconocido','error');
    },'json').fail(function(xhr){
        let m='No fue posible conectar con CONTPAQi.';
        try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
        Swal.fire('Error',m,'error');
    });
}

function guardarConfigContpaq() {
    const d=datosConfigContpaq('guardar'); if(!d)return;
    d.csrf_token=<?=json_encode($csrfContpaq)?>;
    Swal.fire({title:'Guardando CONTPAQi...',text:'Se guardará cifrado y se intentará validar la conexión.',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    $.post('backend/contpaq_config.php',d,function(r){
        if(!r.success) return Swal.fire('No se pudo guardar',r.error||'Error desconocido','error');
        $('#contpaq_password').val('');
        Swal.fire(r.connection_ok===false ? 'Guardado con aviso' : 'Guardado',r.message,r.connection_ok===false ? 'warning' : 'success');
        cargarConfigContpaq($('#id_empresa').val());
    },'json').fail(function(xhr){
        let m='No fue posible guardar la configuración.';
        try{const r=JSON.parse(xhr.responseText);if(r.error)m=r.error;}catch(e){}
        Swal.fire('Error',m,'error');
    });
}

function actualizarContextoEmpresaModal(rfc, razonSocial, idEmpresa) {
    const $ctx = $('#empresa_modal_contexto');
    if (!$ctx.length) return;

    const r = String(rfc || '').trim().toUpperCase();
    const rs = String(razonSocial || '').trim().toUpperCase();
    const id = String(idEmpresa || '').trim();

    if (!r && !rs) {
        $ctx.removeClass('bg-success bg-warning text-dark').addClass('bg-primary')
            .text('NUEVA EMPRESA');
        return;
    }

    const partes = [];
    if (r) partes.push(r);
    if (rs) partes.push(rs);

    $ctx.removeClass('bg-primary bg-warning text-dark').addClass('bg-success')
        .text('EMPRESA: ' + partes.join('  |  '));
}

// Mantener visible en el encabezado qué empresa se está editando, incluso
// cuando el usuario navega entre SELLOS/FIEL, CORREO/LOGOS o CONTPAQ.
$(document).on('input', '#rfc, #razon_social', function() {
    actualizarContextoEmpresaModal(
        $('#rfc').val(),
        $('#razon_social').val(),
        $('#id_empresa').val()
    );
});

function nuevaEmpresa() { 
    cargandoEmpresa = true;
    limpiarConfigContpaq();
    limpiarOraclePagos();
    estadoDescargaEmpresaEdicion = false;
    passwordPfxFielOriginal = '';
    $('#formEmpresa')[0].reset(); 
    establecerMensajeHtml('');
    $('#id_empresa').val(''); 
    actualizarContextoEmpresaModal('', '', '');
    $('#modo_diot').val('normal');
    $('#criterio_fecha_pue').val('EMISION');
    $('#seguridad_correo').val('STARTTLS');
    $('#smtp_password').val('');
    $('#smtp_password_estado').text('');
    $('.preview-logo').html(''); 
    $('.vigencia-box').text('SIN INFO');
    $('#estado_pfx_csd').text('SIN PFX CSD');
    $('#estado_pfx_fiel').text('SIN PFX E.FIRMA');
    actualizarEstadoSat({});
    $('#descarga_sat_automatica_val').val('0');
    $('#pfx_fiel_modificado').val('0');
    $('#pfx_fiel_validacion_confirmada').val('0');
    $('#modalEmpresa').modal('show');
    setTimeout(() => { cargandoEmpresa = false; }, 500); 
}

function editarEmpresa(d) {
    cargandoEmpresa = true;
    cargarConfigContpaq(d.id_empresa);
    cargarOraclePagos(d.id_empresa);
    const descargaActivaBD = Number(d.descarga_sat_automatica || 0) === 1;
    estadoDescargaEmpresaEdicion = descargaActivaBD;
    passwordPfxFielOriginal = String(d.pass_pfx_fiel || '');

    $('#id_empresa').val(d.id_empresa); 
    $('#rfc').val(d.rfc).trigger('input');
    $('#tipopersona').val((d.tipopersona || '').toUpperCase());
    
    // Asignar el modo DIOT (Si no tiene, default a 'normal')
    $('#modo_diot').val(d.modo_diot || 'normal');
    $('#criterio_fecha_pue').val(d.criterio_fecha_pue || 'EMISION');

    $('#razon_social').val(d.razon_social); 
    actualizarContextoEmpresaModal(d.rfc, d.razon_social, d.id_empresa);
    $('#comercial').val(d.comercial);
    $('#id_zona').val(d.id_zona); 
    $('#calle').val(d.calle); 
    $('#colonia').val(d.colonia);
    $('#nexterior').val(d.nexterior); 
    $('#ninterior').val(d.ninterior); 
    $('#referencia').val(d.referencia);
    $('#ciudad').val(d.ciudad); 
    $('#municipio').val(d.municipio); 
    $('#estado').val(d.estado);
    $('#pais').val(d.pais); 
    $('#codigopostal').val(d.codigopostal); 
    $('#telefono').val(d.telefono);
    $('#correo').val(d.correo); 
    $('#lugarexpedicion').val(d.lugarexpedicion); 
    $('#regimenfiscal').val(d.regimenfiscal);
    $('#smptp_correo').val(d.smptp_correo); 
    $('#puerto_correo').val(d.puerto_correo);
    $('#seguridad_correo').val(d.seguridad_correo || (d.usa_ssl == 1 ? 'SSL' : 'STARTTLS'));
    $('#correo_remitente').val(d.correo_remitente || '');
    $('#nombre_remitente').val(d.nombre_remitente || '');
    $('#reply_to_correo').val(d.reply_to_correo || '');
    $('#smtp_usuario').val(d.smtp_usuario || '');
    $('#smtp_password').val('');
    $('#smtp_password_estado').text(d.smtp_password_configurada == 1 ? 'Contraseña almacenada de forma cifrada. Déjela vacía para conservarla.' : 'Sin contraseña SMTP almacenada.');
    $('#asunto').val(d.asunto); 
    establecerMensajeHtml(d.mensaje || ''); 
    $('#passcsd').val(d.passcsd); 
    $('#passfiel').val(d.passfiel);
    $('#pass_pfx_csd').val(d.pass_pfx_csd || '');
    $('#pass_pfx_fiel').val(d.pass_pfx_fiel || '');
    $('#pfx_csd_b64').val(d.pfx_csd || '');
    $('#pfx_fiel_b64').val(d.pfx_fiel || '');
    $('#estado_pfx_csd').html(d.pfx_csd ? '<span class="text-success"><i class="bi bi-check-circle"></i> PFX CSD ALMACENADO</span>' : 'SIN PFX CSD');
    $('#estado_pfx_fiel').html(d.pfx_fiel ? '<span class="text-success"><i class="bi bi-check-circle"></i> PFX E.FIRMA ALMACENADO</span>' : 'SIN PFX E.FIRMA');
    actualizarEstadoSat(d);
    $('#pfx_fiel_modificado').val('0');
    $('#pfx_fiel_validacion_confirmada').val(String(d.pfx_fiel_validado || '0') === '1' ? '1' : '0');
    $('#usa_aut').prop('checked', d.usa_aut == 1); 
    $('#usa_ssl').prop('checked', d.usa_ssl == 1);
    
    if(d.certificado_csd) cargarVigencia(d.certificado_csd, 'csd');
    if(d.certificado_fiel) cargarVigencia(d.certificado_fiel, 'fiel');
    
    if(d.logo1) $('#prev_logo1').html(`<img src="data:image/png;base64,${d.logo1}">`);
    if(d.logo2) $('#prev_logo2').html(`<img src="data:image/png;base64,${d.logo2}">`);
    
    $('#logo1_b64').val(d.logo1); 
    $('#logo2_b64').val(d.logo2);

    aplicarEstadoDescargaSat(descargaActivaBD);
    $('#modalEmpresa').modal('show');

    // Después de que el modal y el autocompletado del navegador terminen,
    // se vuelve a aplicar el valor real que llegó de la base de datos.
    setTimeout(function() {
        aplicarEstadoDescargaSat(descargaActivaBD);
        cargandoEmpresa = false;

    }, 700);
}

window.campoCorreoActivo = 'mensaje_html_editor';
window.rangoCorreoHtml = null;
window.fuenteHtmlActiva = false;

$(document)
    .off('focus.mailPlantilla', '#asunto,#mensaje_html_editor,#mensaje_html_source')
    .on('focus.mailPlantilla', '#asunto,#mensaje_html_editor,#mensaje_html_source', function(){
        campoCorreoActivo = this.id;
    });

$(document)
    .off('keyup.mailHtml mouseup.mailHtml input.mailHtml', '#mensaje_html_editor')
    .on('keyup.mailHtml mouseup.mailHtml input.mailHtml', '#mensaje_html_editor', function(){
        guardarRangoCorreoHtml();
        sincronizarMensajeHtml();
    });

$(document)
    .off('input.mailHtmlSource', '#mensaje_html_source')
    .on('input.mailHtmlSource', '#mensaje_html_source', function(){
        $('#mensaje').val(this.value || '');
    });

function establecerMensajeHtml(html) {
    html = html || '';
    fuenteHtmlActiva = false;
    $('#mensaje_html_editor').html(html).show();
    $('#mensaje_html_source').val(html).hide();
    $('#mensaje_html_toolbar .btn, #mensaje_html_toolbar select').not('#btn_html_fuente').prop('disabled', false);
    $('#btn_html_fuente').removeClass('btn-info').addClass('btn-outline-info').html('&lt;/&gt; HTML');
    $('#mensaje').val(html);
}

function sincronizarMensajeHtml() {
    const html = fuenteHtmlActiva ? ($('#mensaje_html_source').val() || '') : ($('#mensaje_html_editor').html() || '');
    $('#mensaje').val(html);
    return html;
}

function guardarRangoCorreoHtml() {
    const sel = window.getSelection ? window.getSelection() : null;
    if (!sel || sel.rangeCount === 0) return;
    const editor = document.getElementById('mensaje_html_editor');
    const range = sel.getRangeAt(0);
    if (editor && editor.contains(range.commonAncestorContainer)) rangoCorreoHtml = range.cloneRange();
}

function restaurarRangoCorreoHtml() {
    const editor = document.getElementById('mensaje_html_editor');
    if (!editor) return;
    editor.focus();
    if (rangoCorreoHtml && window.getSelection) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(rangoCorreoHtml);
    }
}

function formatoCorreoHtml(comando, valor = null) {
    if (fuenteHtmlActiva) return;
    restaurarRangoCorreoHtml();
    document.execCommand(comando, false, valor);
    guardarRangoCorreoHtml();
    sincronizarMensajeHtml();
}

function aplicarBloqueCorreoHtml(tag) {
    if (!tag || fuenteHtmlActiva) return;
    formatoCorreoHtml('formatBlock', tag);
}

function insertarEnlaceCorreoHtml() {
    if (fuenteHtmlActiva) return;
    const url = prompt('URL del enlace:', 'https://');
    if (!url) return;
    formatoCorreoHtml('createLink', url);
}

function alternarFuenteHtmlCorreo() {
    if (!fuenteHtmlActiva) {
        const html = $('#mensaje_html_editor').html() || '';
        $('#mensaje_html_source').val(html).show();
        $('#mensaje_html_editor').hide();
        fuenteHtmlActiva = true;
        $('#btn_html_fuente').removeClass('btn-outline-info').addClass('btn-info').text('Vista diseño');
        $('#mensaje_html_toolbar .btn, #mensaje_html_toolbar select').not('#btn_html_fuente').prop('disabled', true);
    } else {
        const html = $('#mensaje_html_source').val() || '';
        $('#mensaje_html_editor').html(html).show();
        $('#mensaje_html_source').hide();
        fuenteHtmlActiva = false;
        $('#btn_html_fuente').removeClass('btn-info').addClass('btn-outline-info').html('&lt;/&gt; HTML');
        $('#mensaje_html_toolbar .btn, #mensaje_html_toolbar select').not('#btn_html_fuente').prop('disabled', false);
    }
    sincronizarMensajeHtml();
}

function insertarTokenCorreo(token) {
    if (campoCorreoActivo === 'asunto') {
        const el = document.getElementById('asunto');
        const inicio = Number.isInteger(el.selectionStart) ? el.selectionStart : el.value.length;
        const fin = Number.isInteger(el.selectionEnd) ? el.selectionEnd : el.value.length;
        el.value = el.value.substring(0, inicio) + token + el.value.substring(fin);
        el.focus();
        const pos = inicio + token.length;
        if (el.setSelectionRange) el.setSelectionRange(pos, pos);
        return;
    }

    if (fuenteHtmlActiva || campoCorreoActivo === 'mensaje_html_source') {
        const el = document.getElementById('mensaje_html_source');
        const inicio = Number.isInteger(el.selectionStart) ? el.selectionStart : el.value.length;
        const fin = Number.isInteger(el.selectionEnd) ? el.selectionEnd : el.value.length;
        el.value = el.value.substring(0, inicio) + token + el.value.substring(fin);
        el.focus();
        const pos = inicio + token.length;
        if (el.setSelectionRange) el.setSelectionRange(pos, pos);
        sincronizarMensajeHtml();
        return;
    }

    restaurarRangoCorreoHtml();
    document.execCommand('insertText', false, token);
    guardarRangoCorreoHtml();
    sincronizarMensajeHtml();
}

function cargarPlantillaComplemento() {
    const asunto = 'Solicitud de complemento de pago - CFDI {{folio_factura}} / {{uuid}}';
    const mensaje = `<p>Buen día <strong>{{emisor}}</strong>,</p>
<p>Solicitamos de su apoyo con el <strong>complemento de pago</strong> correspondiente al siguiente CFDI:</p>
<table style="border-collapse:collapse;width:100%;max-width:720px;font-family:Arial,sans-serif;font-size:13px" cellpadding="6">
<tr><td style="border:1px solid #d9e2e8"><strong>UUID</strong></td><td style="border:1px solid #d9e2e8">{{uuid}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Factura</strong></td><td style="border:1px solid #d9e2e8">{{serie}} {{folio_factura}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Fecha factura</strong></td><td style="border:1px solid #d9e2e8">{{fecha_factura}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>RFC emisor</strong></td><td style="border:1px solid #d9e2e8">{{rfc_emisor}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Importe</strong></td><td style="border:1px solid #d9e2e8">{{importe}} {{moneda}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Forma de pago</strong></td><td style="border:1px solid #d9e2e8">{{forma_pago}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Método de pago</strong></td><td style="border:1px solid #d9e2e8">{{metodo_pago}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Cheque / pago</strong></td><td style="border:1px solid #d9e2e8">{{cheque}}</td></tr>
<tr><td style="border:1px solid #d9e2e8"><strong>Fecha cheque / pago</strong></td><td style="border:1px solid #d9e2e8">{{fecha_cheque}}</td></tr>
</table>
<p>Agradeceremos nos hagan llegar el complemento de pago correspondiente para completar nuestra conciliación y expediente fiscal.</p>
<p>Saludos,<br><strong>{{empresa}}</strong></p>`;

    if (($('#asunto').val() || '').trim() !== '' || (sincronizarMensajeHtml() || '').replace(/<[^>]*>/g, '').trim() !== '') {
        if (!confirm('Ya existe un asunto o mensaje capturado. ¿Desea reemplazarlo con la plantilla predeterminada?')) return;
    }
    $('#asunto').val(asunto);
    establecerMensajeHtml(mensaje);
}

</script>