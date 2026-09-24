<?php
error_reporting(0);
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/validacion_frontera.php';
require_once '../includes/control_diot_pagos.php';
require_once '../includes/cfdi_sustituciones.php';
require_once '../includes/combustibles.php';
require_once '../includes/nomina_cfdi.php';

try {
    // 1. Validar Sesión
    if (!isset($_SESSION['id_empresa'])) throw new Exception("Sin Sesión Activa");
    $id_empresa = $_SESSION['id_empresa'];
    $id_usuario = $_SESSION['id_usuario'] ?? null;

    // 2. Validar Archivo
    if (!isset($_FILES['xml'])) throw new Exception("Archivo no recibido");
    
    // Validación de extensión
    $ext = strtolower(pathinfo($_FILES['xml']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xml') throw new Exception("El archivo no tiene extensión .xml");

    $xml_raw = file_get_contents($_FILES['xml']['tmp_name']);

    // Limpieza de Namespaces
    $xml_clean = str_replace(['cfdi:', 'tfd:', 'pago20:', 'nomina12:', 'ecc12:'], '', $xml_raw);
    $xml = @simplexml_load_string($xml_clean);
    
    if (!$xml) throw new Exception("Estructura XML inválida");

    // 3. Identificar Datos Clave
    $uuid = (string) $xml->Complemento->TimbreFiscalDigital['UUID'];
    $tipo_comprobante = (string) $xml['TipoDeComprobante']; 
    $metodo_pago = (string) $xml['MetodoPago'];
    $version_cfdi = (string)($xml['Version'] ?? $xml['version'] ?? '');
    $forma_pago = (string)($xml['FormaPago'] ?? '');
    $tipo_cambio_factura = (float)($xml['TipoCambio'] ?? 1);

    $rfcE = (string)$xml->Emisor['Rfc']; 
    $nomE = (string)$xml->Emisor['Nombre'];
    $rfcR = (string)$xml->Receptor['Rfc']; 
    $nomR = (string)$xml->Receptor['Nombre'];
    $uso_cfdi = (string)($xml->Receptor['UsoCFDI'] ?? '');
    $fechaEmision = (string)$xml['Fecha'];
    $lugarExpedicionXml = (string)($xml['LugarExpedicion'] ?? '');

    // Evidencia original del XML para validar la clasificación DIOT del proveedor.
    // En CFDI recibidos, el proveedor es el emisor. Se conserva el RFC tal como vino
    // en el XML para no depender únicamente de la captura del catálogo de proveedores.
    $rfcProveedorXml = $rfcE;
    $esProveedorExtranjeroXml = ($rfcProveedorXml === 'XEXX010101000') ? 1 : 0;

    if (!$uuid) throw new Exception("No se encontró UUID");

    // Datos del complemento de pago para conciliación con Oracle/CONTPAQi.
    $fecha_pago_xml = null;
    $forma_pago_complemento = '';
    $tipo_cambio_pago = 1.0;
    $numero_operacion_pago = null;
    if ($tipo_comprobante === 'P' && isset($xml->Complemento->Pagos->Pago)) {
        foreach ($xml->Complemento->Pagos->Pago as $pagoMeta) {
            $fecha_pago_xml = (string)($pagoMeta['FechaPago'] ?? '') ?: $fecha_pago_xml;
            $forma_pago_complemento = (string)($pagoMeta['FormaDePagoP'] ?? '') ?: $forma_pago_complemento;
            $tipo_cambio_pago = (float)($pagoMeta['TipoCambioP'] ?? $tipo_cambio_pago);
            $numero_operacion_pago = (string)($pagoMeta['NumOperacion'] ?? '') ?: $numero_operacion_pago;
            break;
        }
    }
    if ($forma_pago === '' && $forma_pago_complemento !== '') {
        $forma_pago = $forma_pago_complemento;
    }

    // 4. VALIDACIÓN DE PERTENENCIA
    $stmtEmp = $pdo->prepare("SELECT rfc, modo_diot, criterio_fecha_pue FROM empresas WHERE id_empresa = ?");
    $stmtEmp->execute([$id_empresa]);
    $rowEmpresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$rowEmpresa) throw new Exception("Error al verificar datos de la empresa.");
    
    $rfcEmpresaSesion = strtoupper(trim((string)$rowEmpresa['rfc']));
    $rfcE = strtoupper(trim($rfcE));
    $rfcR = strtoupper(trim($rfcR));
    $modo_diot = $rowEmpresa['modo_diot'];
    $criterioFechaPue = strtoupper($rowEmpresa['criterio_fecha_pue'] ?? 'EMISION');

    if ($rfcE !== $rfcEmpresaSesion && $rfcR !== $rfcEmpresaSesion) {
        $motivo = "XML NO DADO DE ALTA. El RFC del emisor ($rfcE) o receptor ($rfcR) no corresponde al RFC de la empresa seleccionada ($rfcEmpresaSesion).";
        $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?, 'invalid', ?, ?)")
            ->execute([$uuid, $motivo, $id_usuario]);
        echo json_encode([
            'status' => 'error',
            'error_code' => 'RFC_EMPRESA_NO_COINCIDE',
            'msg' => $motivo,
            'rfc_empresa' => $rfcEmpresaSesion,
            'rfc_emisor' => $rfcE,
            'rfc_receptor' => $rfcR
        ]);
        exit;
    }

    // Efecto del CFDI respecto de la empresa activa.
    // Los tipos especiales P/N/T conservan su tipo SAT en el visor, pero también podemos
    // guardar la posición de la empresa para trazabilidad.
    $tipoMovimientoEmpresa = null;
    if ($rfcE === $rfcEmpresaSesion) {
        $tipoMovimientoEmpresa = 'I';
    } elseif ($rfcR === $rfcEmpresaSesion) {
        $tipoMovimientoEmpresa = 'E';
    }

    // La fecha capturada por usuario queda siempre vacía al importar.
    // La fecha fiscal PUE depende de la configuración de la empresa.
    $fecha_pago_auto = null;
    $fechaAplicacionFiscalAuto = null;
    if ($tipo_comprobante === 'I' && $metodo_pago === 'PUE' && $criterioFechaPue === 'EMISION') {
        $fechaAplicacionFiscalAuto = diot_fecha($fechaEmision);
    } elseif ($tipo_comprobante === 'P' && $fecha_pago_xml) {
        $fechaAplicacionFiscalAuto = diot_fecha($fecha_pago_xml);
    }

    // 5. VALIDACIÓN DE DUPLICADOS
    $st = $pdo->prepare("SELECT uuid FROM facturas WHERE uuid = ? AND id_empresa = ?");
    $st->execute([$uuid, $id_empresa]);
    if ($st->fetch()) {
        // Permitir reconstruir el detalle de gasolina de XML históricos sin borrar la factura.
        $gasolinaDuplicada = combustible_extraer($xml);
        if ($gasolinaDuplicada !== null) {
            combustible_guardar($pdo, (int)$id_empresa, strtoupper($uuid), $gasolinaDuplicada, $fechaAplicacionFiscalAuto);
        }
        $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?, 'invalid', 'UUID DUPLICADO', ?)")
            ->execute([$uuid, $id_usuario]);
        echo json_encode(['status' => 'duplicado', 'msg' => $gasolinaDuplicada !== null
            ? 'Factura ya registrada; detalle de gasolina reconstruido/actualizado.'
            : 'Factura ya registrada']);
        exit;
    }

    $pdo->beginTransaction();

    // 6. EXTRACCIÓN CENTRALIZADA DE IMPUESTOS
    $impuestos = extraerImpuestosCfdi($xml);
    $base_iva_16 = $impuestos['base_iva_16'];
    $monto_iva_16 = $impuestos['iva_16'];
    $base_iva_8 = $impuestos['base_iva_8'];
    $monto_iva_8 = $impuestos['iva_8'];
    $base_exento = $impuestos['base_exento'];
    $base_no_objeto = $impuestos['base_no_objeto'];
    $ret_iva = $impuestos['ret_iva'];
    $ret_isr = $impuestos['ret_isr'];
    $tieneIvaFronterizoXml = $impuestos['tiene_iva_8'];

    $subtotal = (float)($xml['SubTotal'] ?? 0);
    $total    = (float)($xml['Total'] ?? 0);
    $moneda   = (string)$xml['Moneda'];
    $base_iva_total = $base_iva_16 + $base_iva_8;
    $iva_traslado_total = $monto_iva_16 + $monto_iva_8;

    // 7. VALIDACIÓN DE CÓDIGO POSTAL SAT Y ALTA/CONSULTA DEL EMISOR
    $lugarExpedicionXml = normalizarCodigoPostalXml($lugarExpedicionXml);
    $cpSat = consultarCodigoPostalSat($pdo, $lugarExpedicionXml, $fechaEmision);
    $emisorCfg = obtenerOCrearEmisorConFrontera(
        $pdo, $id_empresa, $rfcE, $nomE, $cpSat,
        $tieneIvaFronterizoXml, $lugarExpedicionXml
    );
    $idE = $emisorCfg['id_emisor'];
    $validacionFrontera = validarRegionFronteriza($impuestos, $cpSat, $emisorCfg, $lugarExpedicionXml);

    $regionDetectada = $validacionFrontera['region_detectada'];
    $regionAplicada = $validacionFrontera['region_aplicada'];
    $requiereRevisionRegion = $validacionFrontera['requiere_revision'];
    $mensajeRevisionRegion = $validacionFrontera['mensaje'];

    $pdo->prepare("INSERT IGNORE INTO cat_receptores (rfc, nombre, id_empresa) VALUES (?, ?, ?)")
        ->execute([$rfcR, $nomR, $id_empresa]);
    $stmtR = $pdo->prepare("SELECT id_receptor FROM cat_receptores WHERE rfc = ? AND id_empresa = ?");
    $stmtR->execute([$rfcR, $id_empresa]);
    $idR = $stmtR->fetchColumn();

    // 8. INSERTAR FACTURA CON EVIDENCIA DE CP Y TOTALES NORTE/SUR
    $sqlMain = "INSERT INTO facturas (
        uuid, id_empresa, id_emisor, id_receptor, fecha_emision,
        serie, folio,
        subtotal_xml, total_xml, saldo_pendiente, moneda,
        id_tipo_comprobante, tipo_movimiento_empresa, metodo_pago, version_cfdi, forma_pago, uso_cfdi, estatus_sat,
        fecha_pago_usuario, fecha_pago_sat, fecha_aplicacion_fiscal,
        tc_xml_factura, tc_xml_pago, referencia_xml,
        base_iva, iva_16, iva_8, iva_traslado,
        iva_retinido, isr_retinido, iva_exento,
        subtotal_no_objeto,
        rfc_proveedor_xml, es_proveedor_extranjero_xml,
        lugar_expedicion_xml, cp_catalogo_encontrado, cp_catalogo_vigente,
        cp_estimulo_fronterizo, cp_region_fronteriza, fecha_validacion_cp,
        tiene_iva_fronterizo_xml, base_iva_fronteriza_xml, iva_fronterizo_xml,
        base_iva_frontera_norte, iva_frontera_norte,
        base_iva_frontera_sur, iva_frontera_sur,
        region_diot_detectada, region_diot_aplicada,
        requiere_revision_region, mensaje_revision_region
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, 'Vigente',
        ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?,
        ?,
        ?, ?,
        ?, ?, ?, ?, ?, NOW(),
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?
    )";

    $pdo->prepare($sqlMain)->execute([
        $uuid, $id_empresa, $idE, $idR, $fechaEmision,
        (string)$xml['Serie'], (string)$xml['Folio'],
        $subtotal, $total, $total, $moneda,
        $tipo_comprobante, $tipoMovimientoEmpresa, $metodo_pago, $version_cfdi, $forma_pago, $uso_cfdi,
        $fecha_pago_auto, $fecha_pago_xml ?: null, $fechaAplicacionFiscalAuto,
        $tipo_cambio_factura ?: 1, $tipo_cambio_pago ?: 1, $numero_operacion_pago,
        $base_iva_total, $monto_iva_16, $monto_iva_8, $iva_traslado_total,
        $ret_iva, $ret_isr, $base_exento,
        $base_no_objeto,
        $rfcProveedorXml, $esProveedorExtranjeroXml,
        $lugarExpedicionXml ?: null,
        $cpSat['encontrado'], $cpSat['vigente'], $cpSat['estimulo'], $cpSat['region'],
        $tieneIvaFronterizoXml, $base_iva_8, $monto_iva_8,
        $validacionFrontera['base_norte'], $validacionFrontera['iva_norte'],
        $validacionFrontera['base_sur'], $validacionFrontera['iva_sur'],
        $regionDetectada, $regionAplicada,
        $requiereRevisionRegion, $mensajeRevisionRegion
    ]);


    // 8A. COMPLEMENTO NÓMINA 1.2: campos resumen para visor/conciliación.
    if ($tipo_comprobante === 'N') {
        nomina_cfdi_guardar($pdo, (int)$id_empresa, strtoupper($uuid), nomina_cfdi_extraer($xml));
    }

    // 8B. ESTADO DE CUENTA DE COMBUSTIBLE 1.2 (ecc12)
    // El total del complemento puede ser distinto al total del CFDI (por ejemplo, comisión de monedero),
    // por eso se conserva en tablas propias y con control de pago independiente.
    $gasolina = combustible_extraer($xml);
    if ($gasolina !== null) {
        combustible_guardar($pdo, (int)$id_empresa, strtoupper($uuid), $gasolina, $fechaAplicacionFiscalAuto);
    }

    // 9. PROCESAR PAGOS (Si es complemento)
    // Guardamos las facturas afectadas para recalcular saldo/ya_pago al terminar.
    $facturasAfectadasPago = [];
    if ($tipo_comprobante === 'P') {
        $nodoPagos = $xml->Complemento->Pagos;
        if (isset($nodoPagos->Pago)) {
            $numeroPago = 0;
            foreach ($nodoPagos->Pago as $pago) {
                $numeroPago++;
                if (isset($pago->DoctoRelacionado)) {
                    $numeroDocumento = 0;
                    foreach ($pago->DoctoRelacionado as $docto) {
                        $numeroDocumento++;
                        $uuid_rel = strtoupper(trim((string)$docto['IdDocumento']));
                        $montoP   = (float)$docto['ImpPagado'];
                        $parc     = isset($docto['NumParcialidad']) ? (int)$docto['NumParcialidad'] : null;
                        $s_ant    = (float)$docto['ImpSaldoAnt'];
                        $s_ins    = (float)$docto['ImpSaldoInsoluto'];

                        // La identidad de la línea no depende de NumParcialidad,
                        // porque ese dato puede ser capturado incorrectamente por el usuario.
                        // Se identifica por UUID del complemento + posición del Pago + posición del documento.
                        $claveDetalle = hash('sha256', strtoupper($uuid) . '|' . $numeroPago . '|' . $numeroDocumento);

                        $fechaPagoDetalle = diot_fecha((string)($pago['FechaPago'] ?? $fecha_pago_xml));
                        $facturaRel = diot_foto_factura($pdo, $uuid_rel, $id_empresa);
                        $foto = diot_extraer_impuestos_dr($docto, $facturaRel, $montoP);
                        diot_upsert_detalle($pdo, array_merge($foto, [
                            'id_empresa'=>$id_empresa,
                            'uuid_pago'=>strtoupper($uuid),
                            'uuid_relacionado'=>$uuid_rel,
                            'monto_pagado'=>$montoP,
                            'importe_aplicado'=>$montoP,
                            'parcialidad'=>$parc,
                            'saldo_anterior'=>$s_ant,
                            'saldo_insoluto'=>$s_ins,
                            'clave_detalle'=>$claveDetalle,
                            'aplicado'=>1,
                            'origen_pago'=>'COMPLEMENTO_SAT',
                            'es_sintetico'=>0,
                            'moneda_factura'=>$facturaRel['moneda'] ?? (string)($docto['MonedaDR'] ?? ''),
                            'tc_factura'=>$facturaRel['tc_xml_factura'] ?? 1,
                            'moneda_pago_sat'=>(string)($pago['MonedaP'] ?? ''),
                            'tc_pago_sat'=>(float)($pago['TipoCambioP'] ?? 1),
                            'moneda_dr'=>(string)($docto['MonedaDR'] ?? ''),
                            'equivalencia_dr'=>(float)($docto['EquivalenciaDR'] ?? 1),
                            'fecha_pago_sat'=>$fechaPagoDetalle,
                            'fecha_pago_banco'=>null,
                            'fecha_pago_contpaq'=>null,'moneda_contpaq'=>null,'tc_contpaq'=>null,
                            'monto_conciliado_contpaq'=>0,'saldo_por_conciliar'=>$montoP,'estatus_conciliacion_contpaq'=>'PENDIENTE',
                            'fecha_pago_usuario'=>null,
                            'fecha_aplicacion_fiscal'=>$fechaPagoDetalle,
                            'forma_pago'=>(string)($pago['FormaDePagoP'] ?? $forma_pago),
                            'referencia'=>(string)($pago['NumOperacion'] ?? '')
                        ]));
                        $facturasAfectadasPago[$uuid_rel] = true;
                    }
                }
            }
        }
    }

    // Un complemento puede liquidar o parcializar varias facturas.
    // Recalculamos cada factura relacionada inmediatamente al cargar el XML.
    if ($tipo_comprobante === 'P') {
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

    // PUE con criterio EMISIÓN: crear un detalle fiscal sintético al 100%.
    if ($tipo_comprobante === 'I' && $metodo_pago === 'PUE' && $criterioFechaPue === 'EMISION') {
        $facturaPue = diot_foto_factura($pdo, $uuid, $id_empresa);
        if ($facturaPue) {
            diot_crear_detalle_pue($pdo, $facturaPue, $fechaEmision, 'PUE_EMISION');
        }
    }

    // Recalcular también la factura recién cargada. Esto cubre PUE por emisión y
    // el caso en que un complemento se haya importado antes que la factura.
    if ($tipo_comprobante === 'I') {
        diot_recalcular_factura($pdo, strtoupper($uuid), (int)$id_empresa);
    }

    // 10. FINALIZAR
    $pdo->prepare("INSERT INTO facturas_datos (uuid, xml_base64) VALUES (?,?)")
        ->execute([$uuid, base64_encode($xml_raw)]);
        
    $pdo->prepare("INSERT INTO auditoria_xml (uuid, status, mensaje, usuario_id) VALUES (?, 'valid', 'UUID ALTA OK', ?)")
        ->execute([$uuid, $id_usuario]);

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'msg' => "Procesado correctamente.",
        'region_detectada' => $regionDetectada,
        'requiere_revision' => $requiereRevisionRegion,
        'mensaje_revision' => $mensajeRevisionRegion
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    seguridad_log_error($e, 'procesar_xml_unico'); echo json_encode(['status' => 'error', 'msg' => 'No se pudo procesar el XML.']);
}
?>