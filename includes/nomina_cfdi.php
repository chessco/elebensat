<?php
/**
 * Extracción centralizada del complemento Nómina 1.2.
 * Recibe el SimpleXML ya normalizado (sin prefijos cfdi:/nomina12:) utilizado
 * por los procesadores actuales del portal.
 */
function nomina_cfdi_extraer(SimpleXMLElement $xml): ?array {
    $tipo = strtoupper(trim((string)($xml['TipoDeComprobante'] ?? '')));
    if ($tipo !== 'N' || !isset($xml->Complemento->Nomina)) return null;

    $n = $xml->Complemento->Nomina;
    $r = isset($n->Receptor) ? $n->Receptor : null;

    $fecha = static function($v): ?string {
        $s = trim((string)$v);
        if ($s === '') return null;
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d', $ts);
    };
    $num = static function($v): float {
        $s = trim((string)$v);
        return $s === '' ? 0.0 : (float)$s;
    };
    $txt = static function($v): ?string {
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    };

    return [
        'tipo_nomina'             => $txt($n['TipoNomina'] ?? ''),
        'fecha_pago'              => $fecha($n['FechaPago'] ?? ''),
        'fecha_inicial_pago'      => $fecha($n['FechaInicialPago'] ?? ''),
        'fecha_final_pago'        => $fecha($n['FechaFinalPago'] ?? ''),
        'num_dias_pagados'        => $num($n['NumDiasPagados'] ?? 0),
        'periodicidad_pago'       => $r ? $txt($r['PeriodicidadPago'] ?? '') : null,
        'num_empleado'            => $r ? $txt($r['NumEmpleado'] ?? '') : null,
        'total_percepciones'      => $num($n['TotalPercepciones'] ?? 0),
        'total_deducciones'       => $num($n['TotalDeducciones'] ?? 0),
        'total_otros_pagos'       => $num($n['TotalOtrosPagos'] ?? 0),
    ];
}

function nomina_cfdi_guardar(PDO $pdo, int $idEmpresa, string $uuid, ?array $d): void {
    if ($d === null) return;
    $sql = "UPDATE facturas SET
                nomina_tipo_nomina=?,
                nomina_fecha_pago=?,
                nomina_fecha_inicial_pago=?,
                nomina_fecha_final_pago=?,
                nomina_num_dias_pagados=?,
                nomina_periodicidad_pago=?,
                nomina_num_empleado=?,
                nomina_total_percepciones=?,
                nomina_total_deducciones=?,
                nomina_total_otros_pagos=?,
                nomina_fecha_procesado=NOW()
            WHERE uuid=? AND id_empresa=?";
    $pdo->prepare($sql)->execute([
        $d['tipo_nomina'], $d['fecha_pago'], $d['fecha_inicial_pago'], $d['fecha_final_pago'],
        $d['num_dias_pagados'], $d['periodicidad_pago'], $d['num_empleado'],
        $d['total_percepciones'], $d['total_deducciones'], $d['total_otros_pagos'],
        strtoupper($uuid), $idEmpresa
    ]);
}

function nomina_cfdi_desde_xml_raw(string $xmlRaw): ?array {
    if ($xmlRaw === '') return null;
    $xmlClean = str_replace(['cfdi:', 'tfd:', 'pago20:', 'nomina12:', 'ecc12:'], '', $xmlRaw);
    $xml = @simplexml_load_string($xmlClean);
    if (!$xml) return null;
    return nomina_cfdi_extraer($xml);
}
