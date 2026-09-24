<?php
/**
 * Utilidades para leer XML CFDI almacenado en facturas_datos.xml_base64.
 * La reparación es SOLO en memoria para visualización/generación; nunca altera la BD.
 */

function xml_respaldo_decodificar(string $base64): string
{
    $xml = base64_decode($base64, true);
    if ($xml === false) {
        throw new RuntimeException('El XML almacenado no tiene un Base64 válido.');
    }

    // Quitar BOM UTF-8 y bytes NUL accidentales que rompen libxml/navegador.
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
    $xml = str_replace("\0", '', $xml);
    return trim($xml);
}

function xml_respaldo_es_valido(string $xml, ?array &$errores = null): bool
{
    $anterior = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA) !== false;
    $errores = array_map(static function ($e) {
        return trim((string)$e->message) . ' (línea ' . (int)$e->line . ', columna ' . (int)$e->column . ')';
    }, libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    return $ok;
}

/**
 * Corrige únicamente un cierre FINAL truncado de cfdi:Comprobante/Comprobante.
 * Ejemplo real: </cfdi:Comproban  -> </cfdi:Comprobante>
 * No intenta reconstruir nodos internos ni modifica la BD.
 */
function xml_respaldo_reparar_cierre_final(string $xml, bool &$reparado = false): string
{
    $reparado = false;
    $trim = rtrim($xml);

    if (xml_respaldo_es_valido($trim)) {
        return $trim;
    }

    // Si el archivo acaba en un cierre incompleto del nodo raíz, sustituir sólo ese fragmento final.
    $patrones = [
        '/<\/cfdi:Compro(?:b(?:a(?:n(?:t(?:e?)?)?)?)?)?\s*$/i',
        '/<\/Compro(?:b(?:a(?:n(?:t(?:e?)?)?)?)?)?\s*$/i',
    ];
    $reemplazos = ['</cfdi:Comprobante>', '</Comprobante>'];

    foreach ($patrones as $i => $patron) {
        $candidato = preg_replace($patron, $reemplazos[$i], $trim, 1, $cuantos);
        if ($cuantos === 1 && is_string($candidato) && xml_respaldo_es_valido($candidato)) {
            $reparado = true;
            return $candidato;
        }
    }

    return $trim;
}
