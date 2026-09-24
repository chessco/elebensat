<?php
/**
 * Generador PDF mínimo y compatible con Adobe Acrobat a partir del XML CFDI.
 * No usa el PDF físico almacenado. El PDF se construye completamente en memoria.
 */

function pdf_cfdi_win1252(string $texto): string
{
    // Adobe-safe: normaliza todo el texto a ASCII imprimible.
    // Evita problemas de codificacion de fuentes Base-14 en Acrobat.
    $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);
    $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($out === false) {
        $out = preg_replace('/[^\x20-\x7E]/', '?', $texto) ?? '';
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $out) ?? '';
}

function pdf_cfdi_escape(string $texto): string
{
    $texto = pdf_cfdi_win1252($texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function pdf_cfdi_attr($nodo, string $nombre): string
{
    if (!$nodo) return '';
    $attrs = $nodo->attributes();
    if (isset($attrs[$nombre])) return trim((string)$attrs[$nombre]);
    foreach ($attrs as $k => $v) {
        if (strcasecmp((string)$k, $nombre) === 0) return trim((string)$v);
    }
    return '';
}

function pdf_cfdi_money($valor): string
{
    return '$' . number_format((float)$valor, 2, '.', ',');
}

/**
 * Devuelve una etiqueta legible para los datos generales del CFDI.
 * Se conserva la clave SAT para que el PDF sea útil tanto para operación
 * como para revisión contable/fiscal.
 */
function pdf_cfdi_tipo_comprobante_etiqueta(string $clave): string
{
    $clave = strtoupper(trim($clave));
    $catalogo = [
        'I' => 'Ingreso',
        'E' => 'Egreso',
        'T' => 'Traslado',
        'N' => 'Nomina',
        'P' => 'Pago',
    ];
    return $clave === '' ? '-' : ($clave . ' - ' . ($catalogo[$clave] ?? 'Tipo de comprobante'));
}

function pdf_cfdi_metodo_pago_etiqueta(string $clave): string
{
    $clave = strtoupper(trim($clave));
    $catalogo = [
        'PUE' => 'Pago en una sola exhibicion',
        'PPD' => 'Pago en parcialidades o diferido',
    ];
    return $clave === '' ? 'No aplica' : ($clave . ' - ' . ($catalogo[$clave] ?? 'Metodo de pago'));
}

function pdf_cfdi_regimen_fiscal_etiqueta(string $clave): string
{
    $clave = trim($clave);
    $catalogo = [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Regimen de Enajenacion o Adquisicion de Bienes',
        '608' => 'Demas ingresos',
        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en Mexico',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Fisicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '615' => 'Regimen de los ingresos por obtencion de premios',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Produccion que optan por diferir sus ingresos',
        '621' => 'Incorporacion Fiscal',
        '622' => 'Actividades Agricolas, Ganaderas, Silvicolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Regimen de las Actividades Empresariales con ingresos a traves de Plataformas Tecnologicas',
        '626' => 'Regimen Simplificado de Confianza',
    ];
    if ($clave === '') return '-';
    return $clave . (isset($catalogo[$clave]) ? ' - ' . $catalogo[$clave] : '');
}

function pdf_cfdi_forma_pago_etiqueta(string $clave): string
{
    $clave = strtoupper(trim($clave));
    $catalogo = [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electronica de fondos',
        '04' => 'Tarjeta de credito',
        '05' => 'Monedero electronico',
        '06' => 'Dinero electronico',
        '08' => 'Vales de despensa',
        '12' => 'Dacion en pago',
        '13' => 'Pago por subrogacion',
        '14' => 'Pago por consignacion',
        '15' => 'Condonacion',
        '17' => 'Compensacion',
        '23' => 'Novacion',
        '24' => 'Confusion',
        '25' => 'Remision de deuda',
        '26' => 'Prescripcion o caducidad',
        '27' => 'A satisfaccion del acreedor',
        '28' => 'Tarjeta de debito',
        '29' => 'Tarjeta de servicios',
        '30' => 'Aplicacion de anticipos',
        '31' => 'Intermediario pagos',
        '99' => 'Por definir',
    ];
    return $clave === '' ? 'No aplica' : ($clave . ' - ' . ($catalogo[$clave] ?? 'Forma de pago'));
}

function pdf_cfdi_wrap(string $texto, int $max = 95): array
{
    $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);
    if ($texto === '') return [''];
    $palabras = preg_split('/\s+/u', $texto) ?: [];
    $lineas = [];
    $linea = '';
    foreach ($palabras as $p) {
        $candidato = $linea === '' ? $p : ($linea . ' ' . $p);
        if (mb_strlen($candidato, 'UTF-8') <= $max) {
            $linea = $candidato;
        } else {
            if ($linea !== '') $lineas[] = $linea;
            while (mb_strlen($p, 'UTF-8') > $max) {
                $lineas[] = mb_substr($p, 0, $max, 'UTF-8');
                $p = mb_substr($p, $max, null, 'UTF-8');
            }
            $linea = $p;
        }
    }
    if ($linea !== '') $lineas[] = $linea;
    return $lineas ?: [''];
}

function pdf_cfdi_extraer_datos(string $xmlRaw): array
{
    // Conserva la misma estrategia del visor: quitar prefijos para simplificar lectura,
    // sin alterar el XML original almacenado.
    $xmlClean = preg_replace('/(<\/?)([A-Za-z0-9_\-]+):/u', '$1', $xmlRaw);
    if (!is_string($xmlClean)) $xmlClean = $xmlRaw;

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($xmlClean, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        $msg = $errs ? trim((string)$errs[0]->message) : 'XML inválido';
        throw new RuntimeException('No fue posible interpretar el XML para generar el PDF. ' . $msg);
    }

    $emisor = $xml->Emisor ?? null;
    $receptor = $xml->Receptor ?? null;
    $tfd = null;
    if (isset($xml->Complemento)) {
        foreach ($xml->Complemento->children() as $hijo) {
            if (strcasecmp($hijo->getName(), 'TimbreFiscalDigital') === 0) { $tfd = $hijo; break; }
        }
    }

    $tipo = strtoupper(pdf_cfdi_attr($xml, 'TipoDeComprobante'));
    $uuid = $tfd ? strtoupper(pdf_cfdi_attr($tfd, 'UUID')) : '';
    $serie = pdf_cfdi_attr($xml, 'Serie');
    $folio = pdf_cfdi_attr($xml, 'Folio');
    $version = pdf_cfdi_attr($xml, 'Version');
    if ($version === '') $version = pdf_cfdi_attr($xml, 'version');

    $conceptos = [];
    if (isset($xml->Conceptos->Concepto)) {
        foreach ($xml->Conceptos->Concepto as $c) {
            $traslados = [];
            if (isset($c->Impuestos->Traslados->Traslado)) {
                foreach ($c->Impuestos->Traslados->Traslado as $t) {
                    $traslados[] = [
                        'impuesto' => pdf_cfdi_attr($t, 'Impuesto'),
                        'base' => pdf_cfdi_attr($t, 'Base'),
                        'tasa' => pdf_cfdi_attr($t, 'TasaOCuota'),
                        'importe' => pdf_cfdi_attr($t, 'Importe'),
                    ];
                }
            }
            $conceptos[] = [
                'clave' => pdf_cfdi_attr($c, 'ClaveProdServ'),
                'cantidad' => pdf_cfdi_attr($c, 'Cantidad'),
                'descripcion' => pdf_cfdi_attr($c, 'Descripcion'),
                'unitario' => pdf_cfdi_attr($c, 'ValorUnitario'),
                'descuento' => pdf_cfdi_attr($c, 'Descuento'),
                'importe' => pdf_cfdi_attr($c, 'Importe'),
                'objeto_imp' => pdf_cfdi_attr($c, 'ObjetoImp'),
                'traslados' => $traslados,
            ];
        }
    }

    $pagos = [];
    if (isset($xml->Complemento)) {
        foreach ($xml->Complemento->children() as $comp) {
            if (strcasecmp($comp->getName(), 'Pagos') !== 0) continue;
            if (isset($comp->Pago)) {
                foreach ($comp->Pago as $p) {
                    $docs = [];
                    if (isset($p->DoctoRelacionado)) {
                        foreach ($p->DoctoRelacionado as $dr) {
                            $docs[] = [
                                'uuid' => pdf_cfdi_attr($dr, 'IdDocumento'),
                                'serie' => pdf_cfdi_attr($dr, 'Serie'),
                                'folio' => pdf_cfdi_attr($dr, 'Folio'),
                                'moneda' => pdf_cfdi_attr($dr, 'MonedaDR'),
                                'parcialidad' => pdf_cfdi_attr($dr, 'NumParcialidad'),
                                'saldo_ant' => pdf_cfdi_attr($dr, 'ImpSaldoAnt'),
                                'pagado' => pdf_cfdi_attr($dr, 'ImpPagado'),
                                'saldo' => pdf_cfdi_attr($dr, 'ImpSaldoInsoluto'),
                                'objeto_imp' => pdf_cfdi_attr($dr, 'ObjetoImpDR'),
                            ];
                        }
                    }
                    $pagos[] = [
                        'fecha' => pdf_cfdi_attr($p, 'FechaPago'),
                        'forma' => pdf_cfdi_attr($p, 'FormaDePagoP'),
                        'moneda' => pdf_cfdi_attr($p, 'MonedaP'),
                        'monto' => pdf_cfdi_attr($p, 'Monto'),
                        'docs' => $docs,
                    ];
                }
            }
        }
    }

    // Complemento Nomina 1.2: detalle completo para los CFDI tipo N.
    $nomina = null;
    if ($tipo === 'N' && isset($xml->Complemento)) {
        foreach ($xml->Complemento->children() as $comp) {
            if (strcasecmp($comp->getName(), 'Nomina') !== 0) continue;
            $nr = isset($comp->Receptor) ? $comp->Receptor : null;
            $ne = isset($comp->Emisor) ? $comp->Emisor : null;
            $percepciones = [];
            if (isset($comp->Percepciones->Percepcion)) {
                foreach ($comp->Percepciones->Percepcion as $x) {
                    $percepciones[] = [
                        'tipo' => pdf_cfdi_attr($x, 'TipoPercepcion'),
                        'clave' => pdf_cfdi_attr($x, 'Clave'),
                        'concepto' => pdf_cfdi_attr($x, 'Concepto'),
                        'gravado' => pdf_cfdi_attr($x, 'ImporteGravado'),
                        'exento' => pdf_cfdi_attr($x, 'ImporteExento'),
                    ];
                }
            }
            $deducciones = [];
            if (isset($comp->Deducciones->Deduccion)) {
                foreach ($comp->Deducciones->Deduccion as $x) {
                    $deducciones[] = [
                        'tipo' => pdf_cfdi_attr($x, 'TipoDeduccion'),
                        'clave' => pdf_cfdi_attr($x, 'Clave'),
                        'concepto' => pdf_cfdi_attr($x, 'Concepto'),
                        'importe' => pdf_cfdi_attr($x, 'Importe'),
                    ];
                }
            }
            $otrosPagos = [];
            if (isset($comp->OtrosPagos->OtroPago)) {
                foreach ($comp->OtrosPagos->OtroPago as $x) {
                    $otrosPagos[] = [
                        'tipo' => pdf_cfdi_attr($x, 'TipoOtroPago'),
                        'clave' => pdf_cfdi_attr($x, 'Clave'),
                        'concepto' => pdf_cfdi_attr($x, 'Concepto'),
                        'importe' => pdf_cfdi_attr($x, 'Importe'),
                    ];
                }
            }
            $nomina = [
                'tipo_nomina' => pdf_cfdi_attr($comp, 'TipoNomina'),
                'fecha_pago' => pdf_cfdi_attr($comp, 'FechaPago'),
                'fecha_inicial' => pdf_cfdi_attr($comp, 'FechaInicialPago'),
                'fecha_final' => pdf_cfdi_attr($comp, 'FechaFinalPago'),
                'dias_pagados' => pdf_cfdi_attr($comp, 'NumDiasPagados'),
                'total_percepciones' => pdf_cfdi_attr($comp, 'TotalPercepciones'),
                'total_deducciones' => pdf_cfdi_attr($comp, 'TotalDeducciones'),
                'total_otros_pagos' => pdf_cfdi_attr($comp, 'TotalOtrosPagos'),
                'registro_patronal' => $ne ? pdf_cfdi_attr($ne, 'RegistroPatronal') : '',
                'num_empleado' => $nr ? pdf_cfdi_attr($nr, 'NumEmpleado') : '',
                'curp' => $nr ? pdf_cfdi_attr($nr, 'Curp') : '',
                'nss' => $nr ? pdf_cfdi_attr($nr, 'NumSeguridadSocial') : '',
                'fecha_inicio_rel' => $nr ? pdf_cfdi_attr($nr, 'FechaInicioRelLaboral') : '',
                'antiguedad' => $nr ? pdf_cfdi_attr($nr, 'Antigüedad') : '',
                'tipo_contrato' => $nr ? pdf_cfdi_attr($nr, 'TipoContrato') : '',
                'sindicalizado' => $nr ? pdf_cfdi_attr($nr, 'Sindicalizado') : '',
                'tipo_jornada' => $nr ? pdf_cfdi_attr($nr, 'TipoJornada') : '',
                'tipo_regimen' => $nr ? pdf_cfdi_attr($nr, 'TipoRegimen') : '',
                'departamento' => $nr ? pdf_cfdi_attr($nr, 'Departamento') : '',
                'puesto' => $nr ? pdf_cfdi_attr($nr, 'Puesto') : '',
                'riesgo_puesto' => $nr ? pdf_cfdi_attr($nr, 'RiesgoPuesto') : '',
                'periodicidad' => $nr ? pdf_cfdi_attr($nr, 'PeriodicidadPago') : '',
                'salario_base' => $nr ? pdf_cfdi_attr($nr, 'SalarioBaseCotApor') : '',
                'salario_diario_integrado' => $nr ? pdf_cfdi_attr($nr, 'SalarioDiarioIntegrado') : '',
                'clave_ent_fed' => $nr ? pdf_cfdi_attr($nr, 'ClaveEntFed') : '',
                'percepciones' => $percepciones,
                'deducciones' => $deducciones,
                'otros_pagos' => $otrosPagos,
            ];
            break;
        }
    }

    // Complemento Carta Porte (2.x / 3.x). Se extrae completo para CFDI tipo T.
    $cartaPorte = null;
    if ($tipo === 'T' && isset($xml->Complemento)) {
        foreach ($xml->Complemento->children() as $comp) {
            if (strcasecmp($comp->getName(), 'CartaPorte') !== 0) continue;

            $domicilio = static function ($nodo): array {
                if (!$nodo) return [];
                return [
                    'calle' => pdf_cfdi_attr($nodo, 'Calle'),
                    'num_ext' => pdf_cfdi_attr($nodo, 'NumeroExterior'),
                    'num_int' => pdf_cfdi_attr($nodo, 'NumeroInterior'),
                    'colonia' => pdf_cfdi_attr($nodo, 'Colonia'),
                    'localidad' => pdf_cfdi_attr($nodo, 'Localidad'),
                    'referencia' => pdf_cfdi_attr($nodo, 'Referencia'),
                    'municipio' => pdf_cfdi_attr($nodo, 'Municipio'),
                    'estado' => pdf_cfdi_attr($nodo, 'Estado'),
                    'pais' => pdf_cfdi_attr($nodo, 'Pais'),
                    'cp' => pdf_cfdi_attr($nodo, 'CodigoPostal'),
                ];
            };

            $ubicaciones = [];
            if (isset($comp->Ubicaciones->Ubicacion)) {
                foreach ($comp->Ubicaciones->Ubicacion as $u) {
                    $ubicaciones[] = [
                        'tipo' => pdf_cfdi_attr($u, 'TipoUbicacion'),
                        'id' => pdf_cfdi_attr($u, 'IDUbicacion'),
                        'rfc' => pdf_cfdi_attr($u, 'RFCRemitenteDestinatario'),
                        'nombre' => pdf_cfdi_attr($u, 'NombreRemitenteDestinatario'),
                        'num_reg_id_trib' => pdf_cfdi_attr($u, 'NumRegIdTrib'),
                        'residencia_fiscal' => pdf_cfdi_attr($u, 'ResidenciaFiscal'),
                        'estacion' => pdf_cfdi_attr($u, 'NumEstacion'),
                        'nombre_estacion' => pdf_cfdi_attr($u, 'NombreEstacion'),
                        'navegacion' => pdf_cfdi_attr($u, 'NavegacionTrafico'),
                        'fecha_hora' => pdf_cfdi_attr($u, 'FechaHoraSalidaLlegada'),
                        'tipo_estacion' => pdf_cfdi_attr($u, 'TipoEstacion'),
                        'distancia' => pdf_cfdi_attr($u, 'DistanciaRecorrida'),
                        'domicilio' => isset($u->Domicilio) ? $domicilio($u->Domicilio) : [],
                    ];
                }
            }

            $mercancias = [];
            $merc = isset($comp->Mercancias) ? $comp->Mercancias : null;
            if ($merc && isset($merc->Mercancia)) {
                foreach ($merc->Mercancia as $m) {
                    $mercancias[] = [
                        'bienes' => pdf_cfdi_attr($m, 'BienesTransp'),
                        'clave_stcc' => pdf_cfdi_attr($m, 'ClaveSTCC'),
                        'descripcion' => pdf_cfdi_attr($m, 'Descripcion'),
                        'cantidad' => pdf_cfdi_attr($m, 'Cantidad'),
                        'clave_unidad' => pdf_cfdi_attr($m, 'ClaveUnidad'),
                        'unidad' => pdf_cfdi_attr($m, 'Unidad'),
                        'dimensiones' => pdf_cfdi_attr($m, 'Dimensiones'),
                        'material_peligroso' => pdf_cfdi_attr($m, 'MaterialPeligroso'),
                        'cve_material_peligroso' => pdf_cfdi_attr($m, 'CveMaterialPeligroso'),
                        'embalaje' => pdf_cfdi_attr($m, 'Embalaje'),
                        'descrip_embalaje' => pdf_cfdi_attr($m, 'DescripEmbalaje'),
                        'peso_kg' => pdf_cfdi_attr($m, 'PesoEnKg'),
                        'valor' => pdf_cfdi_attr($m, 'ValorMercancia'),
                        'moneda' => pdf_cfdi_attr($m, 'Moneda'),
                        'fraccion_arancelaria' => pdf_cfdi_attr($m, 'FraccionArancelaria'),
                        'uuid_comercio_ext' => pdf_cfdi_attr($m, 'UUIDComercioExt'),
                    ];
                }
            }

            $autotransporte = null;
            if ($merc && isset($merc->Autotransporte)) {
                $a = $merc->Autotransporte;
                $iv = isset($a->IdentificacionVehicular) ? $a->IdentificacionVehicular : null;
                $seg = isset($a->Seguros) ? $a->Seguros : null;
                $remolques = [];
                if (isset($a->Remolques->Remolque)) {
                    foreach ($a->Remolques->Remolque as $r) {
                        $remolques[] = [
                            'subtipo' => pdf_cfdi_attr($r, 'SubTipoRem'),
                            'placa' => pdf_cfdi_attr($r, 'Placa'),
                        ];
                    }
                }
                $autotransporte = [
                    'perm_sct' => pdf_cfdi_attr($a, 'PermSCT'),
                    'num_permiso_sct' => pdf_cfdi_attr($a, 'NumPermisoSCT'),
                    'config_vehicular' => $iv ? pdf_cfdi_attr($iv, 'ConfigVehicular') : '',
                    'peso_bruto_vehicular' => $iv ? pdf_cfdi_attr($iv, 'PesoBrutoVehicular') : '',
                    'placa_vm' => $iv ? pdf_cfdi_attr($iv, 'PlacaVM') : '',
                    'anio_modelo_vm' => $iv ? pdf_cfdi_attr($iv, 'AnioModeloVM') : '',
                    'asegura_resp_civil' => $seg ? pdf_cfdi_attr($seg, 'AseguraRespCivil') : '',
                    'poliza_resp_civil' => $seg ? pdf_cfdi_attr($seg, 'PolizaRespCivil') : '',
                    'asegura_med_ambiente' => $seg ? pdf_cfdi_attr($seg, 'AseguraMedAmbiente') : '',
                    'poliza_med_ambiente' => $seg ? pdf_cfdi_attr($seg, 'PolizaMedAmbiente') : '',
                    'asegura_carga' => $seg ? pdf_cfdi_attr($seg, 'AseguraCarga') : '',
                    'poliza_carga' => $seg ? pdf_cfdi_attr($seg, 'PolizaCarga') : '',
                    'prima_seguro' => $seg ? pdf_cfdi_attr($seg, 'PrimaSeguro') : '',
                    'remolques' => $remolques,
                ];
            }

            $figuras = [];
            if (isset($comp->FiguraTransporte->TiposFigura)) {
                foreach ($comp->FiguraTransporte->TiposFigura as $f) {
                    $partes = [];
                    if (isset($f->PartesTransporte)) {
                        foreach ($f->PartesTransporte as $pt) {
                            $partes[] = pdf_cfdi_attr($pt, 'ParteTransporte');
                        }
                    }
                    $figuras[] = [
                        'tipo' => pdf_cfdi_attr($f, 'TipoFigura'),
                        'rfc' => pdf_cfdi_attr($f, 'RFCFigura'),
                        'num_licencia' => pdf_cfdi_attr($f, 'NumLicencia'),
                        'nombre' => pdf_cfdi_attr($f, 'NombreFigura'),
                        'num_reg_id_trib' => pdf_cfdi_attr($f, 'NumRegIdTribFigura'),
                        'residencia_fiscal' => pdf_cfdi_attr($f, 'ResidenciaFiscalFigura'),
                        'partes' => array_values(array_filter($partes, static fn($x) => $x !== '')),
                        'domicilio' => isset($f->Domicilio) ? $domicilio($f->Domicilio) : [],
                    ];
                }
            }

            $cartaPorte = [
                'version' => pdf_cfdi_attr($comp, 'Version'),
                'id_ccp' => pdf_cfdi_attr($comp, 'IdCCP'),
                'transp_internac' => pdf_cfdi_attr($comp, 'TranspInternac'),
                'entrada_salida' => pdf_cfdi_attr($comp, 'EntradaSalidaMerc'),
                'pais_origen_destino' => pdf_cfdi_attr($comp, 'PaisOrigenDestino'),
                'via_entrada_salida' => pdf_cfdi_attr($comp, 'ViaEntradaSalida'),
                'total_dist_rec' => pdf_cfdi_attr($comp, 'TotalDistRec'),
                'ubicaciones' => $ubicaciones,
                'peso_bruto_total' => $merc ? pdf_cfdi_attr($merc, 'PesoBrutoTotal') : '',
                'unidad_peso' => $merc ? pdf_cfdi_attr($merc, 'UnidadPeso') : '',
                'peso_neto_total' => $merc ? pdf_cfdi_attr($merc, 'PesoNetoTotal') : '',
                'num_total_mercancias' => $merc ? pdf_cfdi_attr($merc, 'NumTotalMercancias') : '',
                'cargo_tasacion' => $merc ? pdf_cfdi_attr($merc, 'CargoPorTasacion') : '',
                'mercancias' => $mercancias,
                'autotransporte' => $autotransporte,
                'figuras' => $figuras,
            ];
            break;
        }
    }

    $impuestos = ['trasladados' => 0.0, 'retenidos' => 0.0];
    if (isset($xml->Impuestos)) {
        $impuestos['trasladados'] = (float)pdf_cfdi_attr($xml->Impuestos, 'TotalImpuestosTrasladados');
        $impuestos['retenidos'] = (float)pdf_cfdi_attr($xml->Impuestos, 'TotalImpuestosRetenidos');
    }

    return [
        'uuid' => $uuid,
        'version' => $version,
        'tipo' => $tipo,
        'serie' => $serie,
        'folio' => $folio,
        'fecha' => pdf_cfdi_attr($xml, 'Fecha'),
        'subtotal' => pdf_cfdi_attr($xml, 'SubTotal'),
        'descuento' => pdf_cfdi_attr($xml, 'Descuento'),
        'total' => pdf_cfdi_attr($xml, 'Total'),
        'moneda' => pdf_cfdi_attr($xml, 'Moneda'),
        'tipo_cambio' => pdf_cfdi_attr($xml, 'TipoCambio'),
        'forma_pago' => pdf_cfdi_attr($xml, 'FormaPago'),
        'metodo_pago' => pdf_cfdi_attr($xml, 'MetodoPago'),
        'lugar' => pdf_cfdi_attr($xml, 'LugarExpedicion'),
        'emisor_rfc' => $emisor ? pdf_cfdi_attr($emisor, 'Rfc') : '',
        'emisor_nombre' => $emisor ? pdf_cfdi_attr($emisor, 'Nombre') : '',
        'emisor_regimen' => $emisor ? pdf_cfdi_attr($emisor, 'RegimenFiscal') : '',
        'receptor_rfc' => $receptor ? pdf_cfdi_attr($receptor, 'Rfc') : '',
        'receptor_nombre' => $receptor ? pdf_cfdi_attr($receptor, 'Nombre') : '',
        'receptor_regimen' => $receptor ? pdf_cfdi_attr($receptor, 'RegimenFiscalReceptor') : '',
        'uso_cfdi' => $receptor ? pdf_cfdi_attr($receptor, 'UsoCFDI') : '',
        'cp_receptor' => $receptor ? pdf_cfdi_attr($receptor, 'DomicilioFiscalReceptor') : '',
        'fecha_timbrado' => $tfd ? pdf_cfdi_attr($tfd, 'FechaTimbrado') : '',
        'no_cert_sat' => $tfd ? pdf_cfdi_attr($tfd, 'NoCertificadoSAT') : '',
        'rfc_pac' => $tfd ? pdf_cfdi_attr($tfd, 'RfcProvCertif') : '',
        'sello_cfdi' => pdf_cfdi_attr($xml, 'Sello'),
        'sello_sat' => $tfd ? pdf_cfdi_attr($tfd, 'SelloSAT') : '',
        'conceptos' => $conceptos,
        'pagos' => $pagos,
        'nomina' => $nomina,
        'carta_porte' => $cartaPorte,
        'impuestos' => $impuestos,
    ];
}

function pdf_cfdi_desde_xml(string $xmlRaw): string
{
    $d = pdf_cfdi_extraer_datos($xmlRaw);

    $tipoTitulos = [
        'I' => 'FACTURA DE INGRESO', 'E' => 'COMPROBANTE DE EGRESO',
        'P' => 'COMPLEMENTO DE PAGOS', 'N' => 'RECIBO DE NOMINA',
        'T' => 'COMPROBANTE DE TRASLADO'
    ];
    $titulo = $tipoTitulos[$d['tipo']] ?? 'COMPROBANTE FISCAL DIGITAL';

    // El escritor sigue siendo el mismo PDF clasico "Adobe safe" que ya abrio Acrobat.
    // Solo agregamos formato visual con operadores PDF validos.
    $pages = [[]];
    $page = 0;
    $y = 748.0;
    // Cabecera opcional que se dibuja automaticamente cada vez que el contenido
    // salta a una pagina nueva. Se asigna despues de crear las utilidades graficas.
    $onNewPage = null;

    $cmd = function(string $c) use (&$pages, &$page) { $pages[$page][] = $c; };
    $newPage = function() use (&$pages, &$page, &$y, &$onNewPage) {
        $page++;
        $pages[$page] = [];
        $y = 748.0;
        if (is_callable($onNewPage)) $onNewPage();
    };
    $ensure = function(float $needed = 40) use (&$y, $newPage) {
        if ($y - $needed < 48) $newPage();
    };
    $text = function(float $x, float $yy, string $txt, float $size = 9, bool $bold = false, array $rgb = [0,0,0]) use ($cmd) {
        $font = $bold ? 'F2' : 'F1';
        $cmd(sprintf('%.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, $yy, pdf_cfdi_escape($txt)));
    };
    $rightText = function(float $rightX, float $yy, string $txt, float $size = 9, bool $bold = false, array $rgb = [0,0,0]) use ($text) {
        $plain = pdf_cfdi_win1252($txt);
        $factor = $bold ? 0.56 : 0.52;
        $width = strlen($plain) * $size * $factor;
        $text($rightX - $width, $yy, $txt, $size, $bold, $rgb);
    };
    $line = function(float $x1, float $y1, float $x2, float $y2, float $w = .5, array $rgb = [.75,.80,.85]) use ($cmd) {
        $cmd(sprintf('%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S', $rgb[0],$rgb[1],$rgb[2],$w,$x1,$y1,$x2,$y2));
    };
    $box = function(float $x, float $yy, float $w, float $h, array $fill = [.96,.97,.98], array $stroke = [.78,.82,.86]) use ($cmd) {
        $cmd(sprintf('q %.3f %.3f %.3f rg %.3f %.3f %.3f RG %.2f %.2f %.2f %.2f re B Q',
            $fill[0],$fill[1],$fill[2],$stroke[0],$stroke[1],$stroke[2],$x,$yy,$w,$h));
    };
    $wrapAt = function(float $x, float &$yy, string $txt, int $max, float $size = 8, bool $bold = false, array $rgb=[0,0,0], float $lh=11) use ($text) {
        foreach (pdf_cfdi_wrap($txt, $max) as $ln) {
            $text($x, $yy, $ln, $size, $bold, $rgb);
            $yy -= $lh;
        }
    };

    $navy = [.05,.18,.52];
    $red  = [.72,.04,.04];
    $gray = [.33,.37,.40];
    $light = [.94,.96,.98];

    // Cabecera compacta para paginas 2 en adelante. Repite los datos que permiten
    // identificar el CFDI aunque una hoja se imprima o consulte por separado.
    // Emisor y receptor tambien se repiten, pero en formato reducido para no quitar
    // demasiado espacio al detalle de Carta Porte / nomina / conceptos.
    $onNewPage = function() use (&$y, $text, $rightText, $line, $box, $wrapAt, $d, $titulo, $navy, $red, $gray) {
        $nombre = $d['emisor_nombre'] ?: 'EMISOR';
        $nombreCorto = $nombre;
        if (function_exists('mb_substr')) $nombreCorto = mb_substr($nombreCorto, 0, 34, 'UTF-8');
        else $nombreCorto = substr($nombreCorto, 0, 34);

        $text(48, 753, $nombreCorto, 10.5, true, $navy);
        $rightText(564, 753, $titulo, 11.2, true, $red);

        if ($d['uuid'] !== '') $text(48, 738, 'UUID: ' . $d['uuid'], 6.8, false, $navy);
        $sf = trim(($d['serie'] !== '' ? $d['serie'] . ' ' : '') . $d['folio']);
        if ($sf !== '') $rightText(564, 738, 'Folio: ' . $sf, 7.2, false, $gray);
        if ($d['fecha'] !== '') $text(48, 726, 'Fecha: ' . $d['fecha'], 7.1, false, $gray);
        $moneda = $d['moneda'] !== '' ? $d['moneda'] : '-';
        $tc = $d['tipo_cambio'] !== '' ? $d['tipo_cambio'] : '-';
        $rightText(564, 726, 'Moneda: ' . $moneda . ' | Tipo de cambio: ' . $tc, 7.1, false, $gray);
        $line(48, 716, 564, 716, .7, [.62,.70,.78]);

        // Emisor / receptor resumidos en dos fichas compactas.
        $box(48, 656, 248, 50, [.98,.985,.99]);
        $box(316, 656, 248, 50, [.98,.985,.99]);
        $text(58, 694, 'EMISOR', 6.6, true, $navy);
        $text(58, 683, $d['emisor_nombre'] ?: '-', 7.2, true, $gray);
        $text(58, 673, 'RFC: ' . ($d['emisor_rfc'] ?: '-'), 6.7, false, $gray);
        $text(58, 662, 'Regimen: ' . ($d['emisor_regimen'] ?: '-'), 6.7, false, $gray);
        $text(326, 694, 'RECEPTOR', 6.6, true, $navy);
        $text(326, 683, $d['receptor_nombre'] ?: '-', 7.2, true, $gray);
        $text(326, 673, 'RFC: ' . ($d['receptor_rfc'] ?: '-'), 6.7, false, $gray);
        $text(326, 662, 'Regimen: ' . ($d['receptor_regimen'] ?: '-'), 6.7, false, $gray);

        $y = 637.0;
    };

    // ===== Encabezado =====
    // Reservamos dos columnas claras: izquierda para emisor y derecha
    // para tipo de comprobante y datos generales, evitando empalmes.
    $nombreEncabezado = $d['emisor_nombre'] ?: 'EMISOR';
    $lenNombre = function_exists('mb_strlen') ? mb_strlen($nombreEncabezado, 'UTF-8') : strlen($nombreEncabezado);
    $tamNombre = 16;
    if ($lenNombre > 28) $tamNombre = 13;
    if ($lenNombre > 38) $tamNombre = 11.5;
    if ($lenNombre > 50) $tamNombre = 10;

    $headerY = 748.0;
    $yyHeader = $headerY;
    // El emisor se envuelve a otro renglón cuando es largo para no invadir el título.
    $wrapAt(48, $yyHeader, $nombreEncabezado, 34, $tamNombre, true, $navy, $tamNombre + 2);
    $text(48, $yyHeader, 'RFC: ' . ($d['emisor_rfc'] ?: '-'), 8.5, false, $gray);

    $lenTitulo = function_exists('mb_strlen') ? mb_strlen($titulo, 'UTF-8') : strlen($titulo);
    $tamTitulo = 15;
    if ($lenTitulo > 20) $tamTitulo = 14;
    if ($lenTitulo > 26) $tamTitulo = 13;
    $aproxAnchoTitulo = $lenTitulo * ($tamTitulo * 0.58);
    $xTitulo = 564 - $aproxAnchoTitulo;
    if ($xTitulo < 350) $xTitulo = 350;
    $text($xTitulo, $headerY, $titulo, $tamTitulo, true, $red);

    $xInfo = 392;
    if ($d['uuid'] !== '') $text($xInfo, 731, $d['uuid'], 7.2, false, $navy);
    $sf = trim(($d['serie'] !== '' ? $d['serie'] . ' ' : '') . $d['folio']);
    if ($sf !== '') $text(430, 718, 'Folio: ' . $sf, 8.5, false, $gray);
    if ($d['fecha'] !== '') $text(394, 705, 'Fecha: ' . $d['fecha'], 8.5, false, $gray);
    $monedaHeader = $d['moneda'] !== '' ? $d['moneda'] : '-';
    $tipoCambioHeader = $d['tipo_cambio'] !== '' ? $d['tipo_cambio'] : '-';
    $text(394, 692, 'Moneda: ' . $monedaHeader . ' | Tipo de cambio: ' . $tipoCambioHeader, 8.5, false, $gray);
    $line(48, 676, 564, 676, .8, [.62,.70,.78]);
    $y = 656;

    // ===== Emisor / Receptor =====
    $text(48, $y, 'EMISOR / RECEPTOR', 9.5, true, $navy);
    $y -= 10;
    $box(48, $y-85, 248, 85, [.98,.985,.99]);
    $box(316, $y-85, 248, 85, [.98,.985,.99]);
    $yy1=$y-15; $yy2=$y-15;
    $wrapAt(58,$yy1,$d['emisor_nombre'] ?: '-',40,8.5,true,$gray,11);
    $text(58,$yy1,'RFC: '.($d['emisor_rfc'] ?: '-'),8,false,$gray); $yy1-=11;
    $wrapAt(58,$yy1,'Regimen fiscal: '.pdf_cfdi_regimen_fiscal_etiqueta($d['emisor_regimen']),42,7.3,false,$gray,9);
    $text(58,$yy1,'Lugar Exp.: '.($d['lugar'] ?: '-'),8,false,$gray);
    $wrapAt(326,$yy2,$d['receptor_nombre'] ?: '-',40,8.5,true,$gray,11);
    $text(326,$yy2,'RFC: '.($d['receptor_rfc'] ?: '-'),8,false,$gray); $yy2-=11;
    $wrapAt(326,$yy2,'Regimen fiscal: '.pdf_cfdi_regimen_fiscal_etiqueta($d['receptor_regimen']),42,7.3,false,$gray,9);
    $text(326,$yy2,'CP Receptor: '.($d['cp_receptor'] ?: '-'),8,false,$gray); $yy2-=11;
    $text(326,$yy2,'Uso CFDI: '.($d['uso_cfdi'] ?: '-'),8,false,$gray);
    $y -= 104;

    // ===== Datos generales del comprobante =====
    // Solicitud de usuarios del Visor: mostrar explicitamente en la representacion
    // impresa el tipo de comprobante, metodo de pago y forma de pago.
    // Tanto la vista en pantalla como la descarga usan este mismo generador.
    $ensure(62);
    $text(48, $y, 'DATOS DEL COMPROBANTE', 9.5, true, $navy);
    $y -= 12;
    $box(48, $y-42, 516, 42, [.98,.985,.99]);
    $valorIzqX = 143; // alinear los valores de la columna izquierda
    $text(58, $y-14, 'Tipo de comprobante:', 7.4, true, $navy);
    $text($valorIzqX, $y-14, pdf_cfdi_tipo_comprobante_etiqueta($d['tipo']), 7.4, false, $gray);
    $text(58, $y-29, 'Metodo de pago:', 7.4, true, $navy);
    $text($valorIzqX, $y-29, pdf_cfdi_metodo_pago_etiqueta($d['metodo_pago']), 7.4, false, $gray);
    $text(326, $y-29, 'Forma de pago:', 7.4, true, $navy);
    $text(402, $y-29, pdf_cfdi_forma_pago_etiqueta($d['forma_pago']), 7.4, false, $gray);
    $y -= 57;

    if ($d['tipo'] === 'N' && !empty($d['nomina'])) {
        // ===== Nomina 1.2 =====
        $n = $d['nomina'];
        $text(48,$y,'DATOS GENERALES DE NOMINA',9.5,true,$navy); $y-=14;
        $box(48,$y-100,516,100,[.98,.985,.99]);
        // Dos columnas limpias para que los datos generales se lean como ficha de empleado.
        $yy=$y-16;
        $text(58,$yy,'EMPLEADO',6.8,true,$navy);
        $text(105,$yy,$n['num_empleado']?:'-',7.4,true,$gray);
        $text(150,$yy,'NOMBRE',6.8,true,$navy);
        $wrapName=$d['receptor_nombre']?:'-';
        if(function_exists('mb_substr')) $wrapName=mb_substr($wrapName,0,38,'UTF-8'); else $wrapName=substr($wrapName,0,38);
        $text(195,$yy,$wrapName,7.4,true,$gray);
        $yy-=16;
        $text(58,$yy,'RFC',6.8,true,$navy); $text(82,$yy,$d['receptor_rfc']?:'-',7.1,false,$gray);
        $text(190,$yy,'CURP',6.8,true,$navy); $text(220,$yy,$n['curp']?:'-',7.1,false,$gray);
        $text(382,$yy,'NSS',6.8,true,$navy); $text(407,$yy,$n['nss']?:'-',7.1,false,$gray);
        $yy-=15;
        $text(58,$yy,'PUESTO',6.8,true,$navy); $text(98,$yy,$n['puesto']?:'-',7.0,false,$gray);
        $text(320,$yy,'DEPARTAMENTO',6.8,true,$navy); $text(395,$yy,$n['departamento']?:'-',7.0,false,$gray);
        $yy-=15;
        $text(58,$yy,'PERIODO',6.8,true,$navy); $text(105,$yy,($n['fecha_inicial']?:'-').' al '.($n['fecha_final']?:'-'),7.0,false,$gray);
        $text(300,$yy,'FECHA PAGO',6.8,true,$navy); $text(363,$yy,$n['fecha_pago']?:'-',7.0,false,$gray);
        $text(470,$yy,'DIAS',6.8,true,$navy); $text(500,$yy,$n['dias_pagados']?:'-',7.0,false,$gray);
        $yy-=15;
        $text(58,$yy,'PERIODICIDAD',6.8,true,$navy); $text(130,$yy,$n['periodicidad']?:'-',7.0,false,$gray);
        $text(205,$yy,'REG. PATRONAL',6.8,true,$navy); $text(285,$yy,$n['registro_patronal']?:'-',7.0,false,$gray);
        $text(410,$yy,'REGIMEN',6.8,true,$navy); $text(458,$yy,$n['tipo_regimen']?:'-',7.0,false,$gray);
        $yy-=15;
        $sal = $n['salario_diario_integrado']!=='' ? pdf_cfdi_money($n['salario_diario_integrado']) : '-';
        $sbc = $n['salario_base']!=='' ? pdf_cfdi_money($n['salario_base']) : '-';
        $text(58,$yy,'SALARIO DIARIO INT.',6.8,true,$navy); $text(158,$yy,$sal,7.0,false,$gray);
        $text(245,$yy,'SALARIO BASE',6.8,true,$navy); $text(318,$yy,$sbc,7.0,false,$gray);
        $text(402,$yy,'INICIO REL.',6.8,true,$navy); $text(458,$yy,$n['fecha_inicio_rel']?:'-',7.0,false,$gray);
        $y-=116;

        // Percepciones y deducciones lado a lado, como el recibo de nomina de referencia.
        $text(48,$y,'PERCEPCIONES',9,true,$navy);
        $text(316,$y,'DEDUCCIONES',9,true,$navy); $y-=14;
        $box(48,$y-18,248,18,[.91,.94,.97]);
        $box(316,$y-18,248,18,[.91,.94,.97]);
        $text(54,$y-12,'Cve',6.8,true,$gray); $text(83,$y-12,'SAT',6.8,true,$gray); $text(112,$y-12,'Concepto',6.8,true,$gray); $rightText(260,$y-12,'Grav.',6.8,true,$gray); $rightText(292,$y-12,'Ex.',6.8,true,$gray);
        $text(322,$y-12,'Cve',6.8,true,$gray); $text(351,$y-12,'SAT',6.8,true,$gray); $text(380,$y-12,'Concepto',6.8,true,$gray); $rightText(558,$y-12,'Importe',6.8,true,$gray);
        $y-=27;
        $maxRows=max(count($n['percepciones']),count($n['deducciones']));
        for($i=0;$i<$maxRows;$i++) {
            $ensure(18);
            if(isset($n['percepciones'][$i])) {
                $r=$n['percepciones'][$i];
                $text(54,$y,$r['clave']?:'-',6.5,false,$gray); $text(83,$y,$r['tipo']?:'-',6.5,false,$gray);
                $concepto=$r['concepto']?:'-'; if(function_exists('mb_substr')) $concepto=mb_substr($concepto,0,24,'UTF-8'); else $concepto=substr($concepto,0,24);
                $text(112,$y,$concepto,6.5,false,$gray); $rightText(260,$y,pdf_cfdi_money($r['gravado']),6.3,false,$gray); $rightText(292,$y,pdf_cfdi_money($r['exento']),6.3,false,$gray);
            }
            if(isset($n['deducciones'][$i])) {
                $r=$n['deducciones'][$i];
                $text(322,$y,$r['clave']?:'-',6.5,false,$gray); $text(351,$y,$r['tipo']?:'-',6.5,false,$gray);
                $concepto=$r['concepto']?:'-'; if(function_exists('mb_substr')) $concepto=mb_substr($concepto,0,26,'UTF-8'); else $concepto=substr($concepto,0,26);
                $text(380,$y,$concepto,6.5,false,$gray); $rightText(558,$y,pdf_cfdi_money($r['importe']),6.3,false,$gray);
            }
            $y-=11;
        }
        $line(48,$y+3,564,$y+3,.45,[.82,.85,.88]); $y-=8;

        if(!empty($n['otros_pagos'])) {
            $ensure(45);
            $text(48,$y,'OTROS PAGOS',8.5,true,$navy); $y-=12;
            foreach($n['otros_pagos'] as $r) {
                $text(54,$y,($r['clave']?:'-').' | '.($r['tipo']?:'-').' | '.($r['concepto']?:'-'),6.8,false,$gray);
                $rightText(558,$y,pdf_cfdi_money($r['importe']),6.8,false,$gray); $y-=10;
            }
            $y-=4;
        }

        $ensure(70);
        $box(350,$y-67,214,67,[.98,.985,.99],[.78,.82,.86]);
        $text(365,$y-15,'Total percepciones:',8,false,$gray); $rightText(550,$y-15,pdf_cfdi_money($n['total_percepciones']),8,true,$gray);
        $text(365,$y-29,'Total deducciones:',8,false,$gray); $rightText(550,$y-29,'-'.pdf_cfdi_money($n['total_deducciones']),8,true,$gray);
        if((float)$n['total_otros_pagos']!=0.0) { $text(365,$y-43,'Otros pagos:',8,false,$gray); $rightText(550,$y-43,pdf_cfdi_money($n['total_otros_pagos']),8,true,$gray); }
        $text(365,$y-57,'NETO:',10,true,$navy); $rightText(550,$y-57,pdf_cfdi_money($d['total']),10,true,$navy);
        $y-=82;
    } elseif ($d['tipo'] === 'P' && $d['pagos']) {
        // ===== Complementos de pago =====
        $text(48,$y,'DETALLE DE PAGOS',9.5,true,$navy); $y-=16;
        foreach ($d['pagos'] as $i=>$p) {
            $ensure(75);
            $box(48,$y-22,516,22,$light);
            $text(56,$y-14,'Pago '.($i+1).' | Fecha '.($p['fecha']?:'-').' | Forma '.($p['forma']?:'-').' | Moneda '.($p['moneda']?:'-').' | Monto '.pdf_cfdi_money($p['monto']),8.2,true,$navy);
            $y-=31;
            foreach ($p['docs'] as $dr) {
                $ensure(55);
                // Columnas separadas para evitar que el UUID se empalme con Serie/Folio.
                $text(54,$y,'UUID relacionado',7.3,true,$gray);
                $text(202,$y,'Ser/Fol',7.3,true,$gray);
                $text(282,$y,'Moneda',7.3,true,$gray);
                $text(326,$y,'Parc.',7.3,true,$gray);
                $text(360,$y,'Saldo ant.',7.3,true,$gray);
                $text(430,$y,'Pagado',7.3,true,$gray);
                $text(497,$y,'Saldo',7.3,true,$gray);
                $y-=13;
                $text(54,$y,substr($dr['uuid']?:'-',0,36),5.8,false,$gray);
                $text(202,$y,trim(($dr['serie']?:'').' '.($dr['folio']?:'')),6.8,false,$gray);
                $text(282,$y,$dr['moneda']?:'-',6.8,false,$gray);
                $text(326,$y,$dr['parcialidad']?:'-',6.8,false,$gray);
                $text(360,$y,pdf_cfdi_money($dr['saldo_ant']),6.8,false,$gray);
                $text(430,$y,pdf_cfdi_money($dr['pagado']),6.8,false,$gray);
                $text(497,$y,pdf_cfdi_money($dr['saldo']),6.8,false,$gray);
                $y-=13;
                if ($dr['objeto_imp']!=='') { $text(54,$y,'ObjImp: '.$dr['objeto_imp'],7,false,$gray); $y-=11; }
                $line(48,$y,564,$y,.4,[.85,.87,.89]); $y-=9;
            }
        }
        $ensure(40);
        $totalPagos=array_sum(array_map(fn($p)=>(float)$p['monto'],$d['pagos']));
        $box(360,$y-27,204,27,[.95,.97,1.0],[.65,.72,.82]);
        $text(370,$y-18,'MONTO TOTAL PAGOS',9,true,$navy);
        $text(486,$y-18,pdf_cfdi_money($totalPagos),10,true,$navy);
        $y-=43;
    } elseif ($d['tipo'] === 'T' && !empty($d['carta_porte'])) {
        // ===== CFDI Traslado + Carta Porte =====
        $cp = $d['carta_porte'];

        // Domicilios de Carta Porte: no se imprimen como una cadena separada por '|'.
        // Cada dato lleva su etiqueta para que la representacion impresa sea legible.
        $domVal = static function(array $dom, string $k): string {
            $v = trim((string)($dom[$k] ?? ''));
            return $v !== '' ? $v : '-';
        };
        $campoDom = function(float $x, float $yy, string $etiqueta, string $valor, float $offset = 40) use ($text, $navy, $gray) {
            $text($x,$yy,$etiqueta,6.25,true,$navy);
            $text($x+$offset,$yy,$valor !== '' ? $valor : '-',6.45,false,$gray);
        };

        // Resumen Carta Porte.
        $ensure(76);
        $text(48,$y,'DATOS CARTA PORTE',9.5,true,$navy); $y-=14;
        $box(48,$y-54,516,54,[.98,.985,.99]);
        $text(58,$y-15,'Version:',7.2,true,$navy); $text(101,$y-15,$cp['version']?:'-',7.2,false,$gray);
        $text(152,$y-15,'IdCCP:',7.2,true,$navy); $text(188,$y-15,$cp['id_ccp']?:'-',7.0,false,$gray);
        $text(58,$y-31,'Transp. internacional:',7.2,true,$navy); $text(155,$y-31,$cp['transp_internac']?:'-',7.2,false,$gray);
        $text(235,$y-31,'Distancia total:',7.2,true,$navy); $text(312,$y-31,($cp['total_dist_rec']!==''?$cp['total_dist_rec'].' km':'-'),7.2,false,$gray);
        $text(405,$y-31,'Mercancias:',7.2,true,$navy); $text(468,$y-31,$cp['num_total_mercancias']?:'-',7.2,false,$gray);
        $text(58,$y-45,'Peso bruto total:',7.2,true,$navy); $text(139,$y-45,trim(($cp['peso_bruto_total']?:'-').' '.($cp['unidad_peso']?:'')),7.2,false,$gray);
        if ($cp['entrada_salida']!=='' || $cp['pais_origen_destino']!=='' || $cp['via_entrada_salida']!=='') {
            $text(285,$y-45,'Comercio ext.:',7.2,true,$navy);
            $text(355,$y-45,trim(($cp['entrada_salida']?:'-').' '.($cp['pais_origen_destino']?:'').' '.($cp['via_entrada_salida']?:'')),7.0,false,$gray);
        }
        $y-=70;

        // Origen(es) y destino(s). Cada componente del domicilio se identifica por nombre.
        if (!empty($cp['ubicaciones'])) {
            $text(48,$y,'ORIGEN / DESTINO',9.5,true,$navy); $y-=14;
            foreach ($cp['ubicaciones'] as $u) {
                $ensure(132);
                $tipoU = strtoupper((string)($u['tipo'] ?: 'UBICACION'));
                $esOrigen = $tipoU === 'ORIGEN';
                $fillU = $esOrigen ? [.95,.98,1.0] : [1.0,.97,.95];
                $dom = is_array($u['domicilio'] ?? null) ? $u['domicilio'] : [];
                $box(48,$y-112,516,112,$fillU,[.78,.82,.86]);

                $text(58,$y-15,$tipoU,8.3,true,$esOrigen?$navy:$red);
                $text(128,$y-15,($u['nombre']?:'-'),8.0,true,$gray);
                $text(390,$y-15,'Fecha / hora:',6.4,true,$navy);
                $rightText(554,$y-15,($u['fecha_hora']?:'-'),6.5,false,$gray);

                $text(58,$y-30,'RFC:',6.6,true,$navy); $text(84,$y-30,$u['rfc']?:'-',6.7,false,$gray);
                $text(250,$y-30,'Distancia:',6.6,true,$navy); $text(300,$y-30,$u['distancia']!==''?$u['distancia'].' km':'-',6.7,false,$gray);
                $text(410,$y-30,'ID ubicacion:',6.6,true,$navy); $text(472,$y-30,$u['id']?:'-',6.4,false,$gray);

                $campoDom(58,$y-47,'Calle:',$domVal($dom,'calle'),35);
                $campoDom(340,$y-47,'No. ext.:',$domVal($dom,'num_ext'),43);
                $campoDom(445,$y-47,'No. int.:',$domVal($dom,'num_int'),43);

                $campoDom(58,$y-63,'Colonia:',$domVal($dom,'colonia'),43);
                $campoDom(235,$y-63,'Localidad:',$domVal($dom,'localidad'),50);
                $campoDom(385,$y-63,'Municipio:',$domVal($dom,'municipio'),53);

                $campoDom(58,$y-79,'Estado:',$domVal($dom,'estado'),39);
                $campoDom(185,$y-79,'Pais:',$domVal($dom,'pais'),28);
                $campoDom(310,$y-79,'Codigo postal:',$domVal($dom,'cp'),68);

                $campoDom(58,$y-95,'Referencia:',$domVal($dom,'referencia'),55);
                $y-=122;
            }
        }

        // Mercancias transportadas.
        if (!empty($cp['mercancias'])) {
            $ensure(55);
            $text(48,$y,'MERCANCIAS TRANSPORTADAS',9.5,true,$navy); $y-=14;
            $box(48,$y-20,516,20,[.91,.94,.97]);
            $text(54,$y-13,'Bienes transp.',6.9,true,$gray);
            $text(125,$y-13,'Cantidad',6.9,true,$gray);
            $text(178,$y-13,'Unidad',6.9,true,$gray);
            $text(230,$y-13,'Descripcion',6.9,true,$gray);
            $rightText(555,$y-13,'Peso kg',6.9,true,$gray);
            $y-=30;
            foreach ($cp['mercancias'] as $m) {
                $ensure(42);
                $text(54,$y,$m['bienes']?:'-',6.7,false,$gray);
                $text(125,$y,$m['cantidad']?:'-',6.7,false,$gray);
                $text(178,$y,trim(($m['clave_unidad']?:'').' '.($m['unidad']?:'')),6.7,false,$gray);
                $descY=$y;
                $wrapAt(230,$descY,$m['descripcion']?:'-',47,6.7,false,$gray,9);
                $rightText(555,$y,$m['peso_kg']!==''?number_format((float)$m['peso_kg'],3,'.',','):'-',6.7,false,$gray);
                $y=min($descY,$y-10);
                $extras=[];
                if ($m['material_peligroso']!=='') $extras[]='Mat. peligroso: '.$m['material_peligroso'];
                if ($m['cve_material_peligroso']!=='') $extras[]='Clave: '.$m['cve_material_peligroso'];
                if ($m['embalaje']!=='') $extras[]='Embalaje: '.$m['embalaje'];
                if ($m['fraccion_arancelaria']!=='') $extras[]='Fraccion: '.$m['fraccion_arancelaria'];
                if ($extras) { $text(230,$y,implode(' | ',$extras),6.2,false,$gray); $y-=9; }
                $line(48,$y+3,564,$y+3,.35,[.87,.89,.91]); $y-=7;
            }
            $ensure(38);
            $box(345,$y-29,219,29,[.98,.985,.99]);
            $text(355,$y-18,'Peso bruto total:',7.4,true,$gray);
            $rightText(554,$y-18,trim(($cp['peso_bruto_total']?:'-').' '.($cp['unidad_peso']?:'')),8.0,true,$navy);
            $y-=43;
        }

        // Datos del autotransporte, seguros y remolques.
        if (!empty($cp['autotransporte'])) {
            $a=$cp['autotransporte'];
            $ensure(120);
            $text(48,$y,'AUTOTRANSPORTE FEDERAL',9.5,true,$navy); $y-=14;
            $box(48,$y-88,516,88,[.98,.985,.99]);
            $text(58,$y-15,'Permiso SCT:',7.0,true,$navy); $text(122,$y-15,$a['perm_sct']?:'-',7.0,false,$gray);
            $text(210,$y-15,'No. permiso:',7.0,true,$navy); $text(273,$y-15,$a['num_permiso_sct']?:'-',6.7,false,$gray);
            $text(58,$y-31,'Config. vehicular:',7.0,true,$navy); $text(145,$y-31,$a['config_vehicular']?:'-',7.0,false,$gray);
            $text(210,$y-31,'Placa:',7.0,true,$navy); $text(245,$y-31,$a['placa_vm']?:'-',7.0,false,$gray);
            $text(335,$y-31,'Modelo:',7.0,true,$navy); $text(374,$y-31,$a['anio_modelo_vm']?:'-',7.0,false,$gray);
            $text(430,$y-31,'PBV:',7.0,true,$navy); $text(457,$y-31,($a['peso_bruto_vehicular']!==''?$a['peso_bruto_vehicular']:'-'),7.0,false,$gray);
            $text(58,$y-47,'Aseguradora RC:',7.0,true,$navy); $text(137,$y-47,$a['asegura_resp_civil']?:'-',6.9,false,$gray);
            $text(335,$y-47,'Poliza:',7.0,true,$navy); $text(372,$y-47,$a['poliza_resp_civil']?:'-',6.9,false,$gray);
            $yyA=$y-63;
            if (!empty($a['remolques'])) {
                $rem=[];
                foreach($a['remolques'] as $r) $rem[]=trim(($r['subtipo']?:'-').' / Placa '.($r['placa']?:'-'));
                $wrapAt(58,$yyA,'Remolques: '.implode(' | ',$rem),90,6.9,false,$gray,9);
            } else {
                $text(58,$yyA,'Remolques: -',6.9,false,$gray);
            }
            $y-=104;
        }

        // Operadores, propietarios, arrendadores y demas figuras.
        // Igual que origen/destino, el domicilio lleva campos identificados.
        if (!empty($cp['figuras'])) {
            $ensure(45);
            $text(48,$y,'FIGURAS DE TRANSPORTE',9.5,true,$navy); $y-=14;
            $labelsFigura=['01'=>'OPERADOR','02'=>'PROPIETARIO','03'=>'ARRENDADOR','04'=>'NOTIFICADO'];
            foreach($cp['figuras'] as $f) {
                $ensure(128);
                $label=$labelsFigura[$f['tipo']]??('TIPO '.$f['tipo']);
                $domF = is_array($f['domicilio'] ?? null) ? $f['domicilio'] : [];
                $box(48,$y-108,516,108,[.98,.985,.99]);
                $text(58,$y-15,$label,7.6,true,$navy);
                $text(125,$y-15,$f['nombre']?:'-',7.6,true,$gray);

                $text(58,$y-30,'RFC:',6.6,true,$navy); $text(84,$y-30,$f['rfc']?:'-',6.7,false,$gray);
                $text(245,$y-30,'Licencia:',6.6,true,$navy); $text(289,$y-30,$f['num_licencia']!==''?$f['num_licencia']:'-',6.7,false,$gray);
                $text(405,$y-30,'Parte transporte:',6.6,true,$navy); $text(486,$y-30,!empty($f['partes'])?implode(', ',$f['partes']):'-',6.5,false,$gray);

                $campoDom(58,$y-47,'Calle:',$domVal($domF,'calle'),35);
                $campoDom(340,$y-47,'No. ext.:',$domVal($domF,'num_ext'),43);
                $campoDom(445,$y-47,'No. int.:',$domVal($domF,'num_int'),43);

                $campoDom(58,$y-63,'Colonia:',$domVal($domF,'colonia'),43);
                $campoDom(235,$y-63,'Localidad:',$domVal($domF,'localidad'),50);
                $campoDom(385,$y-63,'Municipio:',$domVal($domF,'municipio'),53);

                $campoDom(58,$y-79,'Estado:',$domVal($domF,'estado'),39);
                $campoDom(185,$y-79,'Pais:',$domVal($domF,'pais'),28);
                $campoDom(310,$y-79,'Codigo postal:',$domVal($domF,'cp'),68);

                $campoDom(58,$y-95,'Referencia:',$domVal($domF,'referencia'),55);
                $y-=118;
            }
        }

        // Concepto fiscal del CFDI de traslado. El total fiscal debe permanecer en cero,
        // pero mostramos el valor de referencia de la mercancía cuando el XML lo trae.
        if (!empty($d['conceptos'])) {
            $ensure(55);
            $text(48,$y,'CONCEPTO FISCAL DEL TRASLADO',9.5,true,$navy); $y-=14;
            $box(48,$y-20,516,20,[.91,.94,.97]);
            $text(54,$y-13,'Clave P/S',6.9,true,$gray); $text(118,$y-13,'Cant.',6.9,true,$gray);
            $text(157,$y-13,'Descripcion',6.9,true,$gray); $text(408,$y-13,'V. unitario',6.9,true,$gray); $rightText(555,$y-13,'Referencia',6.9,true,$gray);
            $y-=30;
            $valorReferencia=0.0;
            foreach($d['conceptos'] as $c){
                $ensure(35);
                $text(54,$y,$c['clave']?:'-',6.6,false,$gray); $text(118,$y,$c['cantidad']?:'-',6.6,false,$gray);
                $dy=$y; $wrapAt(157,$dy,$c['descripcion']?:'-',43,6.6,false,$gray,9);
                $rightText(463,$y,pdf_cfdi_money($c['unitario']),6.6,false,$gray);
                $rightText(555,$y,pdf_cfdi_money($c['importe']),6.6,false,$gray);
                $valorReferencia+=(float)$c['importe'];
                $y=min($dy,$y-10); $line(48,$y+3,564,$y+3,.35,[.87,.89,.91]); $y-=7;
            }
            $ensure(46);
            $box(345,$y-37,219,37,[.98,.985,.99]);
            $text(355,$y-15,'Valor mercancia ref.:',7.3,true,$gray); $rightText(554,$y-15,pdf_cfdi_money($valorReferencia),7.6,true,$gray);
            $text(355,$y-29,'Total fiscal CFDI:',7.3,true,$gray); $rightText(554,$y-29,pdf_cfdi_money($d['total']),7.6,true,$navy);
            $y-=51;
        }
    } else {
        // ===== Conceptos =====
        $text(48,$y,'CONCEPTOS E IMPUESTOS DETALLADOS',9.5,true,$navy); $y-=15;
        $box(48,$y-20,516,20,[.91,.94,.97]);
        $text(54,$y-13,'Clave P/S',7.4,true,$gray);
        $text(115,$y-13,'Cant.',7.4,true,$gray);
        $text(155,$y-13,'Descripcion',7.4,true,$gray);
        $text(406,$y-13,'Unitario',7.4,true,$gray);
        $text(478,$y-13,'Importe',7.4,true,$gray);
        $y-=29;
        foreach ($d['conceptos'] as $c) {
            $ensure(55);
            $rowTop=$y;
            $text(54,$y,$c['clave']?:'-',7,false,$gray);
            $text(115,$y,$c['cantidad']?:'-',7,false,$gray);
            $descY=$y;
            $wrapAt(155,$descY,$c['descripcion']?:'-',44,7,false,$gray,10);
            $text(406,$y,pdf_cfdi_money($c['unitario']),7,false,$gray);
            $text(478,$y,pdf_cfdi_money($c['importe']),7,false,$gray);
            $y=min($descY,$y-10);
            foreach ($c['traslados'] as $t) {
                $text(155,$y,'Traslado '.($t['impuesto']?:'-').' | Base '.pdf_cfdi_money($t['base']).' | Tasa '.($t['tasa']?:'-').' | Importe '.pdf_cfdi_money($t['importe']),6.6,false,$gray);
                $y-=10;
            }
            $line(48,$y+3,564,$y+3,.35,[.87,.89,.91]);
            $y-=7;
        }

        $ensure(75);
        $box(350,$y-67,214,67,[.98,.985,.99],[.78,.82,.86]);
        $text(365,$y-15,'Subtotal:',8.5,true,$gray); $text(480,$y-15,pdf_cfdi_money($d['subtotal']),8.5,true,$gray);
        $yy=$y-29;
        if ((float)$d['descuento'] != 0.0) { $text(365,$yy,'Descuento:',8,false,$gray); $text(480,$yy,'-'.pdf_cfdi_money($d['descuento']),8,false,$gray); $yy-=13; }
        if ((float)$d['impuestos']['trasladados'] != 0.0) { $text(365,$yy,'Impuestos trasladados:',8,false,$gray); $text(480,$yy,pdf_cfdi_money($d['impuestos']['trasladados']),8,false,$gray); $yy-=13; }
        if ((float)$d['impuestos']['retenidos'] != 0.0) { $text(365,$yy,'Impuestos retenidos:',8,false,$gray); $text(480,$yy,'-'.pdf_cfdi_money($d['impuestos']['retenidos']),8,false,$gray); $yy-=13; }
        $text(365,$y-57,'TOTAL:',10,true,$navy); $rightText(550,$y-57,pdf_cfdi_money($d['total']),10,true,$navy);
        $y-=82;
    }

    // ===== Certificacion =====
    $ensure(125);
    $text(48,$y,'DATOS DE CERTIFICACION',9.5,true,$navy); $y-=15;
    if ($d['fecha_timbrado']!=='') { $text(48,$y,'Fecha de timbrado: '.$d['fecha_timbrado'],7.5,false,$gray); $y-=11; }
    if ($d['no_cert_sat']!=='') { $text(48,$y,'No. certificado SAT: '.$d['no_cert_sat'],7.5,false,$gray); $y-=11; }
    if ($d['rfc_pac']!=='') { $text(48,$y,'PAC: '.$d['rfc_pac'],7.5,false,$gray); $y-=13; }
    if ($d['sello_cfdi']!=='') {
        $text(48,$y,'Sello digital del CFDI:',7.5,true,$gray); $y-=10;
        $wrapAt(48,$y,$d['sello_cfdi'],118,5.5,false,$gray,8);
    }
    if ($d['sello_sat']!=='') {
        $y-=2; $text(48,$y,'Sello digital del SAT:',7.5,true,$gray); $y-=10;
        $wrapAt(48,$y,$d['sello_sat'],118,5.5,false,$gray,8);
    }
    $y-=6;
    $line(48,$y,564,$y,.5,[.72,.77,.82]); $y-=12;
    $text(170,$y,'Este documento es una representacion impresa de un CFDI '.($d['version']?:''),7,false,$gray);

    // Numeracion de paginas. Se agrega al final, cuando ya conocemos el total.
    // Esto aplica a cualquier CFDI que genere mas de una hoja.
    $totalPaginas = count($pages);
    $paginaActual = $page;
    foreach ($pages as $idxPagina => $_paginaCmds) {
        $page = $idxPagina;
        $rightText(564, 22, 'Pagina ' . ($idxPagina + 1) . ' de ' . $totalPaginas, 7.0, false, $gray);
    }
    $page = $paginaActual;

    // ===== PDF clasico / Adobe-safe =====
    $objects=[];
    $objects[1]='<< /Type /Catalog /Pages 2 0 R /PageMode /UseNone >>';
    $kids=[];
    $baseObj=6;
    foreach ($pages as $idx=>$cmds) {
        $pageObj=$baseObj+($idx*2);
        $contentObj=$pageObj+1;
        $kids[]=$pageObj.' 0 R';
        $stream=implode("\r\n",$cmds)."\r\n";
        $objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /ProcSet [/PDF /Text] /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentObj.' 0 R >>';
        $objects[$contentObj]='<< /Length '.strlen($stream)." >>\r\nstream\r\n".$stream.'endstream';
    }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
    $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5]='<< /Producer (SGKSAT Web PDF XML Adobe Safe Formatted) /Creator (Visor XML Pro) >>';
    ksort($objects);

    $pdf="%PDF-1.4\r\n%\xE2\xE3\xCF\xD3\r\n";
    $offsets=[0=>0];
    $maxObj=max(array_keys($objects));
    for($i=1;$i<=$maxObj;$i++) {
        if(!isset($objects[$i])) continue;
        $offsets[$i]=strlen($pdf);
        $pdf.=$i." 0 obj\r\n".$objects[$i]."\r\nendobj\r\n";
    }
    $xref=strlen($pdf);
    $pdf.="xref\r\n0 ".($maxObj+1)."\r\n";
    $pdf.="0000000000 65535 f \r\n";
    for($i=1;$i<=$maxObj;$i++) {
        $pdf.=isset($offsets[$i]) ? sprintf('%010d 00000 n ',$offsets[$i])."\r\n" : "0000000000 00000 f \r\n";
    }
    $pdf.="trailer\r\n<< /Size ".($maxObj+1)." /Root 1 0 R /Info 5 0 R >>\r\n";
    $pdf.="startxref\r\n".$xref."\r\n%%EOF\r\n";
    return $pdf;
}
