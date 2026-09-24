<?php
/**
 * Reglas compartidas para validar IVA 8% y región fronteriza usando
 * LugarExpedicion + catálogo SAT + configuración del emisor.
 */

function normalizarCodigoPostalXml($valor)
{
    $cp = preg_replace('/\D+/', '', trim((string)$valor));
    return strlen($cp) === 5 ? $cp : '';
}

function extraerImpuestosCfdi($xml)
{
    $r = [
        'base_iva_16' => 0.0,
        'iva_16' => 0.0,
        'base_iva_8' => 0.0,
        'iva_8' => 0.0,
        'base_iva_0' => 0.0,
        'base_exento' => 0.0,
        'base_no_objeto' => 0.0,
        'ret_iva' => 0.0,
        'ret_isr' => 0.0,
        'tiene_iva_8' => 0,
    ];

    if (!isset($xml->Conceptos->Concepto)) {
        return $r;
    }

    foreach ($xml->Conceptos->Concepto as $concepto) {
        $importeConcepto = (float)($concepto['Importe'] ?? 0);
        if ((string)($concepto['ObjetoImp'] ?? '') === '01') {
            $r['base_no_objeto'] += $importeConcepto;
        }

        if (isset($concepto->Impuestos->Traslados->Traslado)) {
            foreach ($concepto->Impuestos->Traslados->Traslado as $t) {
                if ((string)$t['Impuesto'] !== '002') {
                    continue;
                }

                $tipo = (string)($t['TipoFactor'] ?? '');
                $tasa = (float)($t['TasaOCuota'] ?? 0);
                $base = (float)($t['Base'] ?? 0);
                $importe = (float)($t['Importe'] ?? 0);

                if (strcasecmp($tipo, 'Exento') === 0) {
                    $r['base_exento'] += $base > 0 ? $base : $importeConcepto;
                } elseif ($tasa >= 0.1599 && $tasa <= 0.1601) {
                    $r['base_iva_16'] += $base;
                    $r['iva_16'] += $importe;
                } elseif ($tasa >= 0.0799 && $tasa <= 0.0801) {
                    $r['base_iva_8'] += $base;
                    $r['iva_8'] += $importe;
                    $r['tiene_iva_8'] = 1;
                } elseif (abs($tasa) < 0.000001) {
                    $r['base_iva_0'] += $base;
                }
            }
        }

        if (isset($concepto->Impuestos->Retenciones->Retencion)) {
            foreach ($concepto->Impuestos->Retenciones->Retencion as $ret) {
                $impuesto = (string)$ret['Impuesto'];
                $importe = (float)($ret['Importe'] ?? 0);
                if ($impuesto === '002') $r['ret_iva'] += $importe;
                if ($impuesto === '001') $r['ret_isr'] += $importe;
            }
        }
    }

    return $r;
}

function consultarCodigoPostalSat(PDO $pdo, $codigoPostal, $fechaEmision = null)
{
    $resultado = [
        'codigo_postal' => $codigoPostal,
        'encontrado' => 0,
        'vigente' => 0,
        'estimulo' => 0,
        'region' => 'RESTO',
        'estado' => null,
        'municipio' => null,
        'localidad' => null,
    ];

    if ($codigoPostal === '') return $resultado;

    $stmt = $pdo->prepare("SELECT codigo_postal, estado, municipio, localidad,
                                  estimulo_franja_fronteriza,
                                  fecha_inicio_vigencia, fecha_fin_vigencia
                           FROM sat_codigos_postales
                           WHERE codigo_postal = ? LIMIT 1");
    $stmt->execute([$codigoPostal]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) return $resultado;

    $resultado['encontrado'] = 1;
    $resultado['estado'] = $fila['estado'];
    $resultado['municipio'] = $fila['municipio'];
    $resultado['localidad'] = $fila['localidad'];
    $resultado['estimulo'] = (int)$fila['estimulo_franja_fronteriza'];
    $resultado['region'] = $resultado['estimulo'] === 1 ? 'RFN' : ($resultado['estimulo'] === 2 ? 'RFS' : 'RESTO');

    $fecha = $fechaEmision ? substr((string)$fechaEmision, 0, 10) : date('Y-m-d');
    $inicioOk = empty($fila['fecha_inicio_vigencia']) || $fila['fecha_inicio_vigencia'] <= $fecha;
    $finOk = empty($fila['fecha_fin_vigencia']) || $fila['fecha_fin_vigencia'] >= $fecha;
    $resultado['vigente'] = ($inicioOk && $finOk) ? 1 : 0;

    return $resultado;
}

function obtenerOCrearEmisorConFrontera(PDO $pdo, $idEmpresa, $rfc, $nombre, array $cpSat, $tieneIva8, $codigoPostal)
{
    $stmt = $pdo->prepare("SELECT id_emisor, region_diot, aplica_estimulo_fronterizo,
                                  codigo_postal_fiscal, origen_region_diot
                           FROM cat_emisores WHERE rfc = ? AND id_empresa = ? LIMIT 1");
    $stmt->execute([$rfc, $idEmpresa]);
    $emisor = $stmt->fetch(PDO::FETCH_ASSOC);
    $esNuevo = !$emisor;

    if ($esNuevo) {
        $regionInicial = ($tieneIva8 && $cpSat['encontrado'] && $cpSat['vigente'] && in_array($cpSat['region'], ['RFN','RFS'], true))
            ? $cpSat['region'] : 'RESTO';
        $aplicaInicial = in_array($regionInicial, ['RFN','RFS'], true) ? 1 : 0;
        $origen = $aplicaInicial ? 'XML_CP_SAT' : 'ALTA_XML';

        $ins = $pdo->prepare("INSERT INTO cat_emisores
            (rfc, nombre, id_empresa, region_diot, aplica_estimulo_fronterizo,
             tasa_iva_fronteriza, codigo_postal_fiscal, origen_region_diot, fecha_validacion_region)
            VALUES (?, ?, ?, ?, ?, 0.080000, ?, ?, NOW())");
        $ins->execute([$rfc, $nombre, $idEmpresa, $regionInicial, $aplicaInicial,
                       $codigoPostal !== '' ? $codigoPostal : null, $origen]);

        $stmt->execute([$rfc, $idEmpresa]);
        $emisor = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $emisor['es_nuevo'] = $esNuevo ? 1 : 0;
    return $emisor;
}

function validarRegionFronteriza(array $impuestos, array $cpSat, array $emisor, $codigoPostal)
{
    $tiene8 = (int)$impuestos['tiene_iva_8'] === 1;
    $regionCp = ($cpSat['encontrado'] && $cpSat['vigente']) ? $cpSat['region'] : 'RESTO';
    $regionEmisor = strtoupper((string)($emisor['region_diot'] ?? 'RESTO'));
    $aplicaEmisor = (int)($emisor['aplica_estimulo_fronterizo'] ?? 0) === 1;

    $mensajes = [];
    $requiereRevision = 0;
    $regionDetectada = 'RESTO';
    $regionAplicada = 'RESTO';

    if ($codigoPostal === '') {
        $requiereRevision = 1;
        $mensajes[] = 'LugarExpedicion no contiene un código postal válido de 5 dígitos.';
    } elseif (!$cpSat['encontrado']) {
        $requiereRevision = 1;
        $mensajes[] = 'LugarExpedicion no existe en el catálogo SAT de códigos postales.';
    } elseif (!$cpSat['vigente']) {
        $requiereRevision = 1;
        $mensajes[] = 'El código postal de LugarExpedicion no estaba vigente en la fecha del CFDI.';
    }

    if ($tiene8) {
        if (!in_array($regionCp, ['RFN','RFS'], true)) {
            $requiereRevision = 1;
            $regionDetectada = 'PENDIENTE';
            $regionAplicada = 'PENDIENTE';
            $mensajes[] = 'El XML contiene IVA 8%, pero el código postal no tiene estímulo de franja fronteriza.';
        } else {
            $regionDetectada = $regionCp;
            if (!$aplicaEmisor || !in_array($regionEmisor, ['RFN','RFS'], true)) {
                $requiereRevision = 1;
                $regionAplicada = 'PENDIENTE';
                $mensajes[] = 'El XML y el código postal son fronterizos, pero el emisor no está configurado para el estímulo.';
            } elseif ($regionEmisor !== $regionCp) {
                $requiereRevision = 1;
                $regionAplicada = 'PENDIENTE';
                $mensajes[] = 'La región del código postal (' . $regionCp . ') no coincide con la región configurada del emisor (' . $regionEmisor . ').';
            } else {
                $regionAplicada = $regionCp;
            }
        }
    } elseif (in_array($regionCp, ['RFN','RFS'], true) && $aplicaEmisor) {
        // No siempre es error fiscal, pero sí amerita aviso para evitar una tasa incorrecta.
        $requiereRevision = 1;
        $regionDetectada = $regionCp;
        $regionAplicada = 'RESTO';
        $mensajes[] = 'El código postal y el emisor son fronterizos, pero el XML no contiene IVA 8%; revisar si la tasa aplicada es correcta.';
    }

    $baseNorte = ($tiene8 && $regionCp === 'RFN') ? (float)$impuestos['base_iva_8'] : 0.0;
    $ivaNorte = ($tiene8 && $regionCp === 'RFN') ? (float)$impuestos['iva_8'] : 0.0;
    $baseSur = ($tiene8 && $regionCp === 'RFS') ? (float)$impuestos['base_iva_8'] : 0.0;
    $ivaSur = ($tiene8 && $regionCp === 'RFS') ? (float)$impuestos['iva_8'] : 0.0;

    return [
        'region_detectada' => $regionDetectada,
        'region_aplicada' => $regionAplicada,
        'requiere_revision' => $requiereRevision,
        'mensaje' => $mensajes ? implode(' ', array_unique($mensajes)) : null,
        'base_norte' => $baseNorte,
        'iva_norte' => $ivaNorte,
        'base_sur' => $baseSur,
        'iva_sur' => $ivaSur,
    ];
}
