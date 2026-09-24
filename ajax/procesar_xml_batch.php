<?php
error_reporting(0);
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/paths.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/validacion_frontera.php';
require_once '../includes/control_diot_pagos.php';
require_once '../includes/cfdi_sustituciones.php';
require_once '../includes/combustibles.php';
require_once '../includes/nomina_cfdi.php';

try {
    if (!isset($_SESSION['id_empresa']))
        throw new Exception('Sin Sesión Activa');
    $id_empresa = $_SESSION['id_empresa'];
    $usuario_id = $_SESSION['id_usuario'] ?? null;

    $stEmpresa = $pdo->prepare("SELECT rfc,criterio_fecha_pue FROM empresas WHERE id_empresa=?");
    $stEmpresa->execute([$id_empresa]);
    $empresaCfg = $stEmpresa->fetch(PDO::FETCH_ASSOC);
    if (!$empresaCfg) throw new Exception('Empresa activa no encontrada.');
    $rfcEmpresaSesion = strtoupper(trim((string)$empresaCfg['rfc']));
    $criterioFechaPue = strtoupper((string)($empresaCfg['criterio_fecha_pue'] ?? 'EMISION'));

    // Ruta del esquema maestro
    $schemaPath = sgksat_private_path('schemas/master.xsd');
    if (!file_exists($schemaPath))
        throw new Exception('Esquema maestro no encontrado');

    $total = count($_FILES['xml']['name']);
    $success = 0;
    $duplicates = 0;
    $errors = [];

    // Iniciar transacción para todo el lote (más rápido y atómico)
    $pdo->beginTransaction();

    for ($i = 0; $i < $total; $i++) {
        $xmlRaw = file_get_contents($_FILES['xml']['tmp_name'][$i]);

        // ---------- VALIDACIÓN ÚNICA CONTRA master.xsd ----------
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadXML($xmlRaw);
        if (!$dom->schemaValidate($schemaPath)) {
            $msg = '';
            foreach (libxml_get_errors() as $e) {
                $msg .= trim($e->message) . ' ';
            }
            libxml_clear_errors();
            // Auditoría de XML inválido (uuid = NULL)
            $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (NULL, 'invalid', ?, ?)")
                ->execute([$msg, $usuario_id]);
            $errors[] = $_FILES['xml']['name'][$i] . ': ' . $msg;
            continue; // pasar al siguiente archivo
        }

        // ---------- POST‑VALIDACIÓN ----------
        // Limpiar namespaces sólo después de validar con éxito
        $xmlClean = str_replace(['cfdi:', 'tfd:', 'pago20:', 'pago10:', 'nomina12:', 'ecc12:'], '', $xmlRaw);
        $xml = @simplexml_load_string($xmlClean);
        if (!$xml) {
            $msg = 'Estructura XML inválida después de limpiar namespaces';
            $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (NULL, 'invalid', ?, ?)")
                ->execute([$msg, $usuario_id]);
            $errors[] = $_FILES['xml']['name'][$i] . ': ' . $msg;
            continue;
        }

        // Extraer UUID
        $uuid = (string) $xml->Complemento->TimbreFiscalDigital['UUID'];
        if (!$uuid) {
            $msg = 'No se encontró UUID';
            $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (NULL, 'invalid', ?, ?)")
                ->execute([$msg, $usuario_id]);
            $errors[] = $_FILES['xml']['name'][$i] . ': ' . $msg;
            continue;
        }

        // ---------- VALIDACIÓN DE PERTENENCIA A LA EMPRESA ----------
        // Se valida ANTES de revisar duplicados o tocar cualquier tabla operativa.
        // El RFC de la empresa activa debe aparecer como Emisor o Receptor del CFDI.
        $rfcEValidacion = strtoupper(trim((string)$xml->Emisor['Rfc']));
        $rfcRValidacion = strtoupper(trim((string)$xml->Receptor['Rfc']));
        if ($rfcEValidacion !== $rfcEmpresaSesion && $rfcRValidacion !== $rfcEmpresaSesion) {
            $msg = "XML NO DADO DE ALTA. El RFC del emisor ($rfcEValidacion) o receptor ($rfcRValidacion) no corresponde al RFC de la empresa seleccionada ($rfcEmpresaSesion).";
            $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?, 'invalid', ?, ?)")
                ->execute([$uuid, $msg, $usuario_id]);
            $errors[] = $_FILES['xml']['name'][$i] . ': ' . $msg;
            continue;
        }

        // Verificar duplicado
        $st = $pdo->prepare("SELECT 1 FROM facturas WHERE uuid = ? AND id_empresa = ?");
        $st->execute([$uuid, $id_empresa]);
        if ($st->fetch()) {
            // Si es un XML histórico con complemento de gasolina, reconstruir su detalle aunque
            // el UUID principal ya exista. Esto permite activar el módulo sin borrar/reimportar facturas.
            $gasolinaDuplicada = combustible_extraer($xml);
            if ($gasolinaDuplicada !== null) {
                $stFechaGas = $pdo->prepare("SELECT fecha_aplicacion_fiscal FROM facturas WHERE uuid=? AND id_empresa=? LIMIT 1");
                $stFechaGas->execute([$uuid,$id_empresa]);
                $fechaGasAuto = $stFechaGas->fetchColumn() ?: null;
                combustible_guardar($pdo, (int)$id_empresa, strtoupper($uuid), $gasolinaDuplicada, $fechaGasAuto);
            }
            $duplicates++;
            $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?, 'valid', 'Duplicado - ya existe', ?)")
                ->execute([$uuid, $usuario_id]);
            continue;
        }

        // ---- Extracción de datos básicos e impuestos ----
        $subtotal = (float)$xml['SubTotal'];
        $totalXml = (float)$xml['Total'];
        $fecha = (string)$xml['Fecha'];
        $serie = (string)$xml['Serie'];
        $folio = (string)$xml['Folio'];
        $moneda = (string)$xml['Moneda'];
        $tipoComp = (string)$xml['TipoDeComprobante'];
        $metodoPago = (string)($xml['MetodoPago'] ?? '');
        $versionCfdi = (string)($xml['Version'] ?? $xml['version'] ?? '');
        $formaPago = (string)($xml['FormaPago'] ?? '');
        $tipoCambioFactura = (float)($xml['TipoCambio'] ?? 1);
        $lugarExpedicionXml = normalizarCodigoPostalXml((string)($xml['LugarExpedicion'] ?? ''));
        $impuestos = extraerImpuestosCfdi($xml);

        // Emisor / Receptor
        $rfcE = (string)$xml->Emisor['Rfc'];
        $nomE = (string)$xml->Emisor['Nombre'];
        $rfcR = (string)$xml->Receptor['Rfc'];
        $nomR = (string)$xml->Receptor['Nombre'];
        $usoCfdi = (string)($xml->Receptor['UsoCFDI'] ?? '');
        $fechaPagoXml = null;
        $tipoCambioPago = 1.0;
        $numeroOperacionPago = null;
        if ($tipoComp === 'P' && isset($xml->Complemento->Pagos->Pago)) {
            foreach ($xml->Complemento->Pagos->Pago as $pagoMeta) {
                $fechaPagoXml = (string)($pagoMeta['FechaPago'] ?? '') ?: null;
                $formaPagoP = (string)($pagoMeta['FormaDePagoP'] ?? '');
                if ($formaPago === '' && $formaPagoP !== '') $formaPago = $formaPagoP;
                $tipoCambioPago = (float)($pagoMeta['TipoCambioP'] ?? 1);
                $numeroOperacionPago = (string)($pagoMeta['NumOperacion'] ?? '') ?: null;
                break;
            }
        }
        $rfcE = strtoupper(trim($rfcE));
        $rfcR = strtoupper(trim($rfcR));
        $tipoMovimientoEmpresa = ($rfcE === $rfcEmpresaSesion) ? 'I' : 'E';

        $fechaAplicacionFiscalAuto = null;
        if ($tipoComp === 'I' && $metodoPago === 'PUE' && $criterioFechaPue === 'EMISION') {
            $fechaAplicacionFiscalAuto = diot_fecha($fecha);
        } elseif ($tipoComp === 'P' && $fechaPagoXml) {
            $fechaAplicacionFiscalAuto = diot_fecha($fechaPagoXml);
        }

        $rfcProveedorXml = $rfcE;
        $esProveedorExtranjeroXml = ($rfcProveedorXml === 'XEXX010101000') ? 1 : 0;

        // Código postal SAT + emisor. Si el emisor es nuevo y el XML/CP acreditan frontera,
        // se crea ya clasificado como RFN o RFS.
        $cpSat = consultarCodigoPostalSat($pdo, $lugarExpedicionXml, $fecha);
        $emisorCfg = obtenerOCrearEmisorConFrontera(
            $pdo, $id_empresa, $rfcE, $nomE, $cpSat,
            $impuestos['tiene_iva_8'], $lugarExpedicionXml
        );
        $idE = $emisorCfg['id_emisor'];
        $validacionFrontera = validarRegionFronteriza($impuestos, $cpSat, $emisorCfg, $lugarExpedicionXml);

        $pdo->prepare("INSERT IGNORE INTO cat_receptores (rfc,nombre,id_empresa) VALUES (?,?,?)")
            ->execute([$rfcR, $nomR, $id_empresa]);
        $stmtR = $pdo->prepare("SELECT id_receptor FROM cat_receptores WHERE rfc=? AND id_empresa=?");
        $stmtR->execute([$rfcR, $id_empresa]);
        $idR = $stmtR->fetchColumn();

        $baseIvaTotal = $impuestos['base_iva_16'] + $impuestos['base_iva_8'];
        $ivaTrasladoTotal = $impuestos['iva_16'] + $impuestos['iva_8'];

        // Insertar factura con los mismos datos fiscales que la carga individual.
        $sql = "INSERT INTO facturas (
                    uuid, id_empresa, id_emisor, id_receptor, fecha_emision, serie, folio,
                    subtotal_xml, total_xml, saldo_pendiente, moneda,
                    id_tipo_comprobante, tipo_movimiento_empresa, metodo_pago, version_cfdi, forma_pago, uso_cfdi, estatus_sat,
                    tc_xml_factura, tc_xml_pago, fecha_pago_sat, fecha_aplicacion_fiscal, referencia_xml,
                    base_iva, iva_16, iva_8, iva_traslado,
                    iva_retinido, isr_retinido, iva_exento, subtotal_no_objeto,
                    rfc_proveedor_xml, es_proveedor_extranjero_xml,
                    lugar_expedicion_xml, cp_catalogo_encontrado, cp_catalogo_vigente,
                    cp_estimulo_fronterizo, cp_region_fronteriza, fecha_validacion_cp,
                    tiene_iva_fronterizo_xml, base_iva_fronteriza_xml, iva_fronterizo_xml,
                    base_iva_frontera_norte, iva_frontera_norte,
                    base_iva_frontera_sur, iva_frontera_sur,
                    region_diot_detectada, region_diot_aplicada,
                    requiere_revision_region, mensaje_revision_region
                ) VALUES (
                    ?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,'Vigente',
                    ?,?,?,?,?,
                    ?,?,?,?,?,?,?,?, ?,?,
                    ?,?,?,?,?,NOW(), ?,?,?, ?,?,?,?, ?,?,?,?
                )";
        $pdo->prepare($sql)->execute([
            $uuid, $id_empresa, $idE, $idR, $fecha, $serie, $folio,
            $subtotal, $totalXml, $totalXml, $moneda,
            $tipoComp, $tipoMovimientoEmpresa, $metodoPago, $versionCfdi, $formaPago, $usoCfdi,
            $tipoCambioFactura ?: 1, $tipoCambioPago ?: 1, $fechaPagoXml, $fechaAplicacionFiscalAuto, $numeroOperacionPago,
            $baseIvaTotal, $impuestos['iva_16'], $impuestos['iva_8'], $ivaTrasladoTotal,
            $impuestos['ret_iva'], $impuestos['ret_isr'], $impuestos['base_exento'], $impuestos['base_no_objeto'],
            $rfcProveedorXml, $esProveedorExtranjeroXml,
            $lugarExpedicionXml ?: null, $cpSat['encontrado'], $cpSat['vigente'],
            $cpSat['estimulo'], $cpSat['region'],
            $impuestos['tiene_iva_8'], $impuestos['base_iva_8'], $impuestos['iva_8'],
            $validacionFrontera['base_norte'], $validacionFrontera['iva_norte'],
            $validacionFrontera['base_sur'], $validacionFrontera['iva_sur'],
            $validacionFrontera['region_detectada'], $validacionFrontera['region_aplicada'],
            $validacionFrontera['requiere_revision'], $validacionFrontera['mensaje']
        ]);


        // Complemento Nómina 1.2: guardar campos resumen para visor/conciliación.
        if ($tipoComp === 'N') {
            nomina_cfdi_guardar($pdo, (int)$id_empresa, strtoupper($uuid), nomina_cfdi_extraer($xml));
        }

        // Estado de Cuenta de Combustible 1.2 (ecc12).
        $gasolina = combustible_extraer($xml);
        if ($gasolina !== null) {
            combustible_guardar($pdo, (int)$id_empresa, strtoupper($uuid), $gasolina, $fechaAplicacionFiscalAuto);
        }

        // Complementos: guardar cada aplicación con su fecha e impuestos del detalle.
        $facturasAfectadasPago = [];
        if ($tipoComp === 'P' && isset($xml->Complemento->Pagos->Pago)) {
            $nPago=0;
            foreach ($xml->Complemento->Pagos->Pago as $pago) {
                $nPago++;
                $nDoc=0;
                foreach ($pago->DoctoRelacionado as $docto) {
                    $nDoc++;
                    $uuidRel=strtoupper(trim((string)$docto['IdDocumento']));
                    if ($uuidRel==='') continue;
                    $montoP=(float)($docto['ImpPagado'] ?? 0);
                    $facturaRel=diot_foto_factura($pdo,$uuidRel,$id_empresa);
                    $foto=diot_extraer_impuestos_dr($docto,$facturaRel,$montoP);
                    $fechaDetalle=diot_fecha((string)($pago['FechaPago'] ?? $fechaPagoXml));
                    diot_upsert_detalle($pdo,array_merge($foto,[
                        'id_empresa'=>$id_empresa,'uuid_pago'=>strtoupper($uuid),'uuid_relacionado'=>$uuidRel,
                        'monto_pagado'=>$montoP,'importe_aplicado'=>$montoP,
                        'parcialidad'=>isset($docto['NumParcialidad'])?(int)$docto['NumParcialidad']:null,
                        'saldo_anterior'=>(float)($docto['ImpSaldoAnt'] ?? 0),
                        'saldo_insoluto'=>(float)($docto['ImpSaldoInsoluto'] ?? 0),
                        'clave_detalle'=>hash('sha256',strtoupper($uuid).'|'.$nPago.'|'.$nDoc),
                        'aplicado'=>1,'origen_pago'=>'COMPLEMENTO_SAT','es_sintetico'=>0,
                        'moneda_factura'=>$facturaRel['moneda'] ?? (string)($docto['MonedaDR'] ?? ''),
                        'tc_factura'=>$facturaRel['tc_xml_factura'] ?? 1,
                        'moneda_pago_sat'=>(string)($pago['MonedaP'] ?? ''),
                        'tc_pago_sat'=>(float)($pago['TipoCambioP'] ?? 1),
                        'moneda_dr'=>(string)($docto['MonedaDR'] ?? ''),
                        'equivalencia_dr'=>(float)($docto['EquivalenciaDR'] ?? 1),
                        'fecha_pago_sat'=>$fechaDetalle,'fecha_pago_banco'=>null,'fecha_pago_contpaq'=>null,
                        'moneda_contpaq'=>null,'tc_contpaq'=>null,'monto_conciliado_contpaq'=>0,
                        'saldo_por_conciliar'=>$montoP,'estatus_conciliacion_contpaq'=>'PENDIENTE','fecha_pago_usuario'=>null,
                        'fecha_aplicacion_fiscal'=>$fechaDetalle,
                        'forma_pago'=>(string)($pago['FormaDePagoP'] ?? $formaPago),
                        'referencia'=>(string)($pago['NumOperacion'] ?? '')
                    ]));
                    $facturasAfectadasPago[$uuidRel] = true;
                }
            }
        }

        if ($tipoComp === 'P') {
        $relacionesCfdi = cfdi_extraer_relaciones($xml);
        cfdi_guardar_relaciones($pdo, (int)$id_empresa, strtoupper($uuid), $relacionesCfdi);
        $uuidsSincronizar = [strtoupper($uuid)];
        foreach ($relacionesCfdi as $relCfdi) if (($relCfdi['tipo_relacion'] ?? '') === '04') $uuidsSincronizar[] = $relCfdi['uuid_relacionado'];
        foreach (cfdi_sincronizar_sustituciones_pago($pdo, (int)$id_empresa, $uuidsSincronizar) as $uuidAnteriorAfectado) {
            $facturasAfectadasPago[$uuidAnteriorAfectado] = true;
        }
    }

        foreach (array_keys($facturasAfectadasPago) as $uuidAfectado) {
            diot_recalcular_factura($pdo, $uuidAfectado, (int)$id_empresa);
        }

        if ($tipoComp === 'I' && $metodoPago === 'PUE' && $criterioFechaPue === 'EMISION') {
            $facturaPue=diot_foto_factura($pdo,$uuid,$id_empresa);
            if ($facturaPue) diot_crear_detalle_pue($pdo,$facturaPue,$fecha,'PUE_EMISION');
        }

        // La factura recién cargada queda conciliada con cualquier detalle existente.
        if ($tipoComp === 'I') {
            diot_recalcular_factura($pdo, strtoupper($uuid), (int)$id_empresa);
        }

        // Guardar XML original en base64
        $pdo->prepare("INSERT INTO facturas_datos (uuid, xml_base64) VALUES (?,?)")->execute([$uuid, base64_encode($xmlRaw)]);

        // Auditoría válida
        $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?,?,?,?)")
            ->execute([$uuid, 'valid', 'XML validado y procesado correctamente', $usuario_id]);

        $success++;
    }

    // Commit de la transacción
    $pdo->commit();

    // Preparar respuesta final
    $msg = "Procesados: $success, Duplicados: $duplicates";
    $status = empty($errors) ? 'ok' : 'partial';
    if (!empty($errors)) {
        $msg .= ", Errores: " . count($errors) . " (" . implode('; ', $errors) . ")";
    }
    echo json_encode(['status' => $status, 'msg' => $msg]);

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    seguridad_log_error($e, 'procesar_xml_batch'); echo json_encode(['status' => 'error', 'msg' => 'No se pudo procesar el lote de XML.']);
}
?>