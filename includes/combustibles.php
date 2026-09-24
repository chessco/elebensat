<?php
/**
 * Estado de Cuenta de Combustible 1.2 (ecc12)
 *
 * El proyecto limpia el prefijo ecc12: antes de cargar SimpleXML, por lo que
 * este helper trabaja con nodos sin prefijo: EstadoDeCuentaCombustible,
 * Conceptos, ConceptoEstadoDeCuentaCombustible, Traslados y Traslado.
 */

function combustible_extraer(SimpleXMLElement $xml): ?array
{
    if (!isset($xml->Complemento->EstadoDeCuentaCombustible)) {
        return null;
    }

    $ecc = $xml->Complemento->EstadoDeCuentaCombustible;
    $detalles = [];
    $ivaTotal = 0.0;
    $iepsTotal = 0.0;

    if (isset($ecc->Conceptos->ConceptoEstadoDeCuentaCombustible)) {
        foreach ($ecc->Conceptos->ConceptoEstadoDeCuentaCombustible as $concepto) {
            $impuestos = [];
            $iva = 0.0;
            $ieps = 0.0;

            if (isset($concepto->Traslados->Traslado)) {
                foreach ($concepto->Traslados->Traslado as $traslado) {
                    $impuesto = strtoupper(trim((string)($traslado['Impuesto'] ?? '')));
                    $importeImpuesto = (float)($traslado['Importe'] ?? 0);
                    $tasaCuota = (float)($traslado['TasaOCuota'] ?? 0);

                    $impuestos[] = [
                        'impuesto' => $impuesto,
                        'tasa_cuota' => $tasaCuota,
                        'importe' => $importeImpuesto,
                    ];

                    if ($impuesto === 'IVA') {
                        $iva += $importeImpuesto;
                        $ivaTotal += $importeImpuesto;
                    } elseif ($impuesto === 'IEPS') {
                        $ieps += $importeImpuesto;
                        $iepsTotal += $importeImpuesto;
                    }
                }
            }

            $importe = (float)($concepto['Importe'] ?? 0);
            $detalles[] = [
                'identificador' => trim((string)($concepto['Identificador'] ?? '')),
                'fecha_operacion' => trim((string)($concepto['Fecha'] ?? '')),
                'rfc_gasolinera' => strtoupper(trim((string)($concepto['Rfc'] ?? ''))),
                'clave_estacion' => trim((string)($concepto['ClaveEstacion'] ?? '')),
                'tipo_combustible' => trim((string)($concepto['TipoCombustible'] ?? '')),
                'unidad' => trim((string)($concepto['Unidad'] ?? '')),
                'nombre_combustible' => trim((string)($concepto['NombreCombustible'] ?? '')),
                'folio_operacion' => trim((string)($concepto['FolioOperacion'] ?? '')),
                'cantidad' => (float)($concepto['Cantidad'] ?? 0),
                'valor_unitario' => (float)($concepto['ValorUnitario'] ?? 0),
                'importe' => $importe,
                'iva' => $iva,
                'ieps' => $ieps,
                'total_impuestos' => $iva + $ieps,
                'total_operacion' => $importe + $iva + $ieps,
                'impuestos' => $impuestos,
            ];
        }
    }

    return [
        'version' => trim((string)($ecc['Version'] ?? '')),
        'tipo_operacion' => trim((string)($ecc['TipoOperacion'] ?? '')),
        'numero_cuenta' => trim((string)($ecc['NumeroDeCuenta'] ?? '')),
        'subtotal' => (float)($ecc['SubTotal'] ?? 0),
        'total' => (float)($ecc['Total'] ?? 0),
        'iva_total' => $ivaTotal,
        'ieps_total' => $iepsTotal,
        'detalles' => $detalles,
    ];
}

function combustible_fecha_sql(?string $valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    $ts = strtotime($valor);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function combustible_guardar(PDO $pdo, int $idEmpresa, string $uuid, array $gasolina, ?string $fechaPagoAuto = null): void
{
    $uuid = strtoupper(trim($uuid));
    $fechaPago = combustible_fecha_sql($fechaPagoAuto);
    $total = (float)($gasolina['total'] ?? 0);
    $pagado = $fechaPago ? $total : 0.0;
    $saldo = max(0, $total - $pagado);
    $yaPago = $fechaPago ? 1 : 0;

    $sqlCab = "INSERT INTO facturas_gasolina (
                    uuid,id_empresa,version,tipo_operacion,numero_cuenta,
                    subtotal_combustible,total_combustible,iva_total,ieps_total,
                    fecha_pago,total_pagado,saldo_pendiente,ya_pago
               ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                    id_empresa=VALUES(id_empresa),version=VALUES(version),tipo_operacion=VALUES(tipo_operacion),
                    numero_cuenta=VALUES(numero_cuenta),subtotal_combustible=VALUES(subtotal_combustible),
                    total_combustible=VALUES(total_combustible),iva_total=VALUES(iva_total),ieps_total=VALUES(ieps_total),
                    fecha_pago=COALESCE(fecha_pago,VALUES(fecha_pago)),
                    total_pagado=CASE WHEN fecha_pago IS NULL THEN VALUES(total_pagado) ELSE total_pagado END,
                    saldo_pendiente=CASE WHEN fecha_pago IS NULL THEN VALUES(saldo_pendiente) ELSE saldo_pendiente END,
                    ya_pago=CASE WHEN fecha_pago IS NULL THEN VALUES(ya_pago) ELSE ya_pago END";
    $pdo->prepare($sqlCab)->execute([
        $uuid,$idEmpresa,$gasolina['version'] ?? '',$gasolina['tipo_operacion'] ?? '',$gasolina['numero_cuenta'] ?? '',
        (float)($gasolina['subtotal'] ?? 0),$total,(float)($gasolina['iva_total'] ?? 0),(float)($gasolina['ieps_total'] ?? 0),
        $fechaPago,$pagado,$saldo,$yaPago
    ]);

    // En una importación nueva no debe existir detalle previo; el borrado hace el proceso idempotente
    // si en el futuro se usa este helper para reconstruir información desde el XML almacenado.
    $pdo->prepare("DELETE FROM facturas_gasolina_detalles WHERE uuid=? AND id_empresa=?")->execute([$uuid,$idEmpresa]);

    $sqlDet = "INSERT INTO facturas_gasolina_detalles (
                    id_empresa,uuid,clave_detalle,identificador,fecha_operacion,rfc_gasolinera,
                    clave_estacion,tipo_combustible,unidad,nombre_combustible,folio_operacion,
                    cantidad,valor_unitario,importe,iva,ieps,total_impuestos,total_operacion
               ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stDet = $pdo->prepare($sqlDet);
    $stImp = $pdo->prepare("INSERT INTO facturas_gasolina_impuestos (id_detalle,impuesto,tasa_cuota,importe) VALUES (?,?,?,?)");

    foreach (($gasolina['detalles'] ?? []) as $idx => $d) {
        $clave = hash('sha256', implode('|', [
            $uuid,
            $d['identificador'] ?? '',
            $d['rfc_gasolinera'] ?? '',
            $d['folio_operacion'] ?? '',
            $d['fecha_operacion'] ?? '',
            (string)$idx,
        ]));

        $stDet->execute([
            $idEmpresa,$uuid,$clave,$d['identificador'] ?? '',combustible_fecha_sql($d['fecha_operacion'] ?? null),
            $d['rfc_gasolinera'] ?? '',$d['clave_estacion'] ?? '',$d['tipo_combustible'] ?? '',$d['unidad'] ?? '',
            $d['nombre_combustible'] ?? '',$d['folio_operacion'] ?? '',(float)($d['cantidad'] ?? 0),
            (float)($d['valor_unitario'] ?? 0),(float)($d['importe'] ?? 0),(float)($d['iva'] ?? 0),
            (float)($d['ieps'] ?? 0),(float)($d['total_impuestos'] ?? 0),(float)($d['total_operacion'] ?? 0)
        ]);
        $idDetalle = (int)$pdo->lastInsertId();

        foreach (($d['impuestos'] ?? []) as $imp) {
            $stImp->execute([
                $idDetalle,
                strtoupper((string)($imp['impuesto'] ?? '')),
                (float)($imp['tasa_cuota'] ?? 0),
                (float)($imp['importe'] ?? 0),
            ]);
        }

        // El complemento sólo trae RFC de la estación; si no existe en el catálogo se crea
        // con el RFC como nombre provisional. El usuario puede enriquecer el nombre después.
        $rfcGas = strtoupper(trim((string)($d['rfc_gasolinera'] ?? '')));
        if ($rfcGas !== '') {
            $stExiste = $pdo->prepare("SELECT id_emisor FROM cat_emisores WHERE id_empresa=? AND rfc=? LIMIT 1");
            $stExiste->execute([$idEmpresa,$rfcGas]);
            if (!$stExiste->fetchColumn()) {
                $pdo->prepare("INSERT INTO cat_emisores (id_empresa,rfc,nombre,tipo_tercero,tipo_operacion,aplica_diot) VALUES (?,?,?,'04','85',1)")
                    ->execute([$idEmpresa,$rfcGas,$rfcGas]);
            }
        }
    }

    $pdo->prepare("UPDATE facturas SET es_gasolina=1,total_ieps_gasolina=? WHERE uuid=? AND id_empresa=?")
        ->execute([(float)($gasolina['ieps_total'] ?? 0),$uuid,$idEmpresa]);
}
