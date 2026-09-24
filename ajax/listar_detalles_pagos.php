<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';

try {
    if (!isset($_SESSION['id_empresa'])) throw new RuntimeException('Sin sesión');
    $idEmpresa = (int)$_SESSION['id_empresa'];
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $permitidos = obtener_permisos_documentos($pdo, $idUsuario, $idEmpresa);
    if (!$permitidos) {
        echo json_encode(['data'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inicio = trim($_GET['inicio'] ?? '');
    $fin = trim($_GET['fin'] ?? '');
    $tipo = strtoupper(trim($_GET['tipo'] ?? ''));
    $metodo = strtoupper(trim($_GET['metodo'] ?? ''));
    $busqueda = trim($_GET['busqueda'] ?? '');
    $exportAll = ((int)($_GET['export_all'] ?? 0) === 1);
    $exportMode = strtolower(trim($_GET['export_mode'] ?? 'completa'));
    if (!in_array($exportMode, ['completa','diot','chequeo_diot'], true)) $exportMode = 'completa';
    $exportAnio = (int)($_GET['export_anio'] ?? 0);
    $exportMes = (int)($_GET['export_mes'] ?? 0);

    $sql = "SELECT
        d.id_detalle, d.uuid_pago, d.uuid_relacionado, d.parcialidad,
        d.saldo_anterior, d.saldo_insoluto, d.monto_pagado, d.importe_aplicado,
        d.origen_pago, d.es_sintetico, d.moneda_factura, d.tc_factura, d.moneda_pago_sat, d.tc_pago_sat, d.moneda_dr, d.equivalencia_dr,
        d.fecha_pago_sat, d.fecha_pago_banco, d.fecha_pago_contpaq, d.moneda_contpaq, d.tc_contpaq,
        d.monto_conciliado_contpaq, d.saldo_por_conciliar, d.estatus_conciliacion_contpaq,
        d.fecha_pago_usuario, d.fecha_aplicacion_fiscal, d.forma_pago AS forma_pago_det,
        d.referencia, d.proporcion_aplicada,
        d.base_iva_16, d.iva_16, d.base_iva_8, d.iva_8,
        d.base_tasa_0, d.base_exento, d.base_no_objeto,
        d.iva_retenido, d.isr_retenido, d.ieps_otros, d.aplicado, d.es_sustituido, d.uuid_sustituto,
        f.id_tipo_comprobante tipo_sat, f.tipo_movimiento_empresa,
        f.fecha_emision, f.serie, f.folio, f.metodo_pago, f.forma_pago AS forma_pago_factura,
        f.moneda, f.tc_xml_factura, f.tc_banco_real, f.total_xml, f.subtotal_xml, f.saldo_pendiente, f.ya_pago,
        f.iva_tratamiento_especial_diot, f.iva_porcentaje_acreditable_diot, f.iva_motivo_tratamiento_diot,
        e.rfc rfc_emisor, e.nombre emisor, e.aplica_diot, r.rfc rfc_receptor, r.nombre receptor, emp.rfc rfc_empresa, f.estatus_sat estatus_factura_sat, f.excluir_diot, fp.estatus_sat estatus_pago_sat, COALESCE(fp.excluir_diot,0) pago_excluir_diot
      FROM facturas_pagos_detalles d
      INNER JOIN facturas f ON f.uuid=d.uuid_relacionado AND f.id_empresa=d.id_empresa
      LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND fp.uuid=d.uuid_pago
      LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor
      LEFT JOIN cat_receptores r ON r.id_receptor=f.id_receptor
      LEFT JOIN empresas emp ON emp.id_empresa=f.id_empresa
      WHERE d.id_empresa=:id";
    $params = [':id'=>$idEmpresa];

    // Exportaciones especiales del detalle:
    // DIOT          = EGRESOS por fecha_aplicacion_fiscal del detalle.
    // CHEQUEO DIOT  = EGRESOS por fecha_emision de la factura, aunque la fecha fiscal
    //                 del detalle esté fuera del mes o vacía.
    $periodoInicio = '';
    $periodoFinExclusivo = '';

    if ($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) {
        if ($exportAnio < 2000 || $exportAnio > 2100 || $exportMes < 1 || $exportMes > 12) {
            throw new RuntimeException('Mes/año inválido para exportación DIOT.');
        }

        $periodoInicio = sprintf('%04d-%02d-01', $exportAnio, $exportMes);
        $dFin = DateTime::createFromFormat('Y-m-d', $periodoInicio);
        if (!$dFin) throw new RuntimeException('Periodo DIOT inválido.');
        $dFin->modify('first day of next month');
        $periodoFinExclusivo = $dFin->format('Y-m-d');

        // Ambos caminos son exclusivamente EGRESOS.
        if (!in_array('E', $permitidos, true)) {
            $sql .= ' AND 1=0';
        } else {
            // Recibido = RFC receptor igual al RFC de la empresa activa.
            $sql .= " AND UPPER(TRIM(COALESCE(r.rfc,'')))=UPPER(TRIM(COALESCE(emp.rfc,'')))";
            $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,'')) NOT IN ('P','N','T')";

            if ($exportMode === 'diot') {
                // DIOT real: excluir tanto la factura marcada NO DIOT como el REP/complemento
                // que haya sido marcado NO DIOT. El detalle puede seguir viéndose en el visor,
                // pero no debe aparecer ni sumar en la exportación DIOT.
                $sql .= ' AND COALESCE(f.excluir_diot,0)=0';
                $sql .= ' AND d.fecha_aplicacion_fiscal>=:periodo_inicio';
                $sql .= ' AND d.fecha_aplicacion_fiscal<:periodo_fin';
                $sql .= ' AND COALESCE(d.es_sustituido,0)=0';
                $sql .= " AND (d.es_sintetico=1 OR (UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0') AND COALESCE(fp.excluir_diot,0)=0))";
            } else {
                // Ruta rápida por índice de facturas.
                $sql .= ' AND f.fecha_emision>=:periodo_inicio';
                $sql .= ' AND f.fecha_emision<:periodo_fin';
            }
            $params[':periodo_inicio'] = $periodoInicio . ' 00:00:00';
            $params[':periodo_fin'] = $periodoFinExclusivo . ' 00:00:00';
        }
    } else {
        // Vista normal / exportación completa: el rango corresponde a la FECHA FISCAL.
        if ($inicio !== '') {
            $sql .= ' AND d.fecha_aplicacion_fiscal>=:inicio';
            $params[':inicio'] = $inicio . ' 00:00:00';
        }
        if ($fin !== '') {
            $dFin = DateTime::createFromFormat('Y-m-d', $fin);
            if ($dFin) {
                $dFin->modify('+1 day');
                $sql .= ' AND d.fecha_aplicacion_fiscal<:fin';
                $params[':fin'] = $dFin->format('Y-m-d') . ' 00:00:00';
            }
        }

        if (in_array($metodo, ['PUE','PPD'], true)) {
            $sql .= " AND UPPER(COALESCE(f.metodo_pago,''))=:metodo";
            $params[':metodo'] = $metodo;
        }

        if ($tipo !== '') {
            $clasificacionesVisor = [
                // I/E aquí significa movimiento respecto de la empresa, igual que la
                // columna Tipo mostrada por el visor (tipo_movimiento_empresa).
                'INGRESO_RECIBIDO'=>['permiso'=>'I','direccion'=>'RECIBIDO','sat'=>'I'],
                'PAGO_PROVEEDORES'=>['permiso'=>'P','direccion'=>'NO_EMITIDO','sat'=>'P'],
                'PAGO_EMITIDOS'=>['permiso'=>'P','direccion'=>'EMITIDO','sat'=>'P'],
            'TRASLADO_PROVEEDORES'=>['permiso'=>'T','direccion'=>'NO_EMITIDO','sat'=>'T'],
            'TRASLADO_EMITIDOS'=>['permiso'=>'T','direccion'=>'EMITIDO','sat'=>'T'],
                'INGRESO_EMITIDO'=>['permiso'=>'I','direccion'=>'EMITIDO','sat'=>'I'],
                'EGRESO_RECIBIDO'=>['permiso'=>'E','direccion'=>'RECIBIDO','sat'=>'E'],
                'EGRESO_EMITIDO'=>['permiso'=>'E','direccion'=>'EMITIDO','sat'=>'E'],
                'IR'=>['permiso'=>'I','direccion'=>'RECIBIDO','sat'=>'I'],
                'IE'=>['permiso'=>'I','direccion'=>'EMITIDO','sat'=>'I'],
                'ER'=>['permiso'=>'E','direccion'=>'RECIBIDO','sat'=>'E'],
                'EE'=>['permiso'=>'E','direccion'=>'EMITIDO','sat'=>'E'],
            ];

            if (isset($clasificacionesVisor[$tipo])) {
                $clasificacion = $clasificacionesVisor[$tipo];
                if (!in_array($clasificacion['permiso'], $permitidos, true)) {
                    $sql .= ' AND 1=0';
                } else {
                    // PROVEEDORES (antes INGRESOS RECIBIDOS): CFDI SAT tipo I
                    // + RFC receptor igual al RFC de la empresa activa.
                    if (in_array($tipo, ['INGRESO_RECIBIDO','IR'], true)) {
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='I'";
                    } elseif (in_array($tipo, ['INGRESO_EMITIDO','IE'], true)) {
                        // INGRESOS EMITIDOS: CFDI SAT tipo I cuyo RFC emisor es la empresa activa.
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='I'";
                    } elseif ($tipo === 'PAGO_PROVEEDORES') {
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='P'";
                    } elseif ($tipo === 'PAGO_EMITIDOS') {
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='P'";
                    } elseif ($tipo === 'TRASLADO_PROVEEDORES') {
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='T'";
                    } elseif ($tipo === 'TRASLADO_EMITIDOS') {
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='T'";
                    } elseif (in_array($tipo, ['EGRESO_RECIBIDO','ER'], true)) {
                        // EGRESOS RECIBIDOS: CFDI SAT tipo E cuyo RFC receptor es la empresa activa.
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='E'";
                    } elseif (in_array($tipo, ['EGRESO_EMITIDO','EE'], true)) {
                        // EGRESOS EMITIDOS: CFDI SAT tipo E cuyo RFC emisor es la empresa activa.
                        $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='E'";
                    } else {
                        $sql .= " AND f.tipo_movimiento_empresa=:tipo_mov_clasificacion";
                        $params[':tipo_mov_clasificacion'] = $clasificacion['mov'];
                    }
                    if (in_array($tipo, ['PAGO_PROVEEDORES','TRASLADO_PROVEEDORES'], true)) {
                        $sql .= " AND UPPER(TRIM(COALESCE(e.rfc,'')))<>UPPER(TRIM(COALESCE(emp.rfc,'')))";
                    } elseif ($clasificacion['direccion'] === 'RECIBIDO') {
                        $sql .= " AND UPPER(TRIM(COALESCE(r.rfc,'')))=UPPER(TRIM(COALESCE(emp.rfc,'')))";
                    } else {
                        $sql .= " AND UPPER(TRIM(COALESCE(e.rfc,'')))=UPPER(TRIM(COALESCE(emp.rfc,'')))";
                    }
                }
            } elseif (!in_array($tipo, $permitidos, true)) {
                $sql .= ' AND 1=0';
            } elseif (in_array($tipo, ['I','E'], true)) {
                // Compatibilidad con llamadas anteriores.
                $sql .= " AND f.tipo_movimiento_empresa=:tipo AND UPPER(COALESCE(f.id_tipo_comprobante,'')) NOT IN ('P','N','T')";
                $params[':tipo']=$tipo;
            } else {
                $sql .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))=:tipo";
                $params[':tipo']=$tipo;
            }
        } else {
            $faltantes = array_diff(tipos_documento_todos(), $permitidos);
            if (!empty($faltantes)) $sql .= ' AND ' . sql_filtro_tipos_permitidos($permitidos, $params, 'f');
        }

        // La búsqueda del detalle se aplica también al exportar "Completa".
        if ($busqueda !== '') {
            $sql .= " AND (
                UPPER(COALESCE(d.uuid_relacionado,'')) LIKE :buscar
                OR UPPER(COALESCE(d.uuid_pago,'')) LIKE :buscar
                OR UPPER(COALESCE(e.rfc,'')) LIKE :buscar
                OR UPPER(COALESCE(e.nombre,'')) LIKE :buscar
                OR UPPER(COALESCE(r.rfc,'')) LIKE :buscar
                OR UPPER(COALESCE(r.nombre,'')) LIKE :buscar
                OR UPPER(COALESCE(f.folio,'')) LIKE :buscar
            )";
            $params[':buscar'] = '%' . strtoupper($busqueda) . '%';
        }
    }

    $sql .= ' ORDER BY d.fecha_aplicacion_fiscal DESC, f.fecha_emision DESC, d.id_detalle DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $fmtFecha = static function($v){ return $v ? date('d/m/Y', strtotime($v)) : '-'; };

    // Cargar las relaciones 04 UNA sola vez para la empresa.
    // Antes se ejecutaba un EXISTS correlacionado por cada detalle y además con UPPER(),
    // lo que hacía muy lenta la vista en empresas con decenas de miles de pagos.
    $sustitutos04 = [];
    $stRel04 = $pdo->prepare("SELECT DISTINCT uuid_origen
                              FROM cfdi_relaciones
                              WHERE id_empresa=? AND tipo_relacion='04'");
    $stRel04->execute([$idEmpresa]);
    while ($uuid04 = $stRel04->fetchColumn()) {
        $uuid04 = strtoupper(trim((string)$uuid04));
        if ($uuid04 !== '') $sustitutos04[$uuid04] = true;
    }

    $data=[];
    while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
        $esSustitutoRel = isset($sustitutos04[strtoupper(trim((string)($r['uuid_pago'] ?? '')))]) ? 1 : 0;
        $tipoSat = strtoupper((string)($r['tipo_sat'] ?? ''));
        $tipoMov = strtoupper((string)($r['tipo_movimiento_empresa'] ?? ''));
        $tipoMostrar = $tipoSat ?: $tipoMov;
        $item = [
            'tipo'=>$tipoMostrar,
            'uuid_factura'=>$r['uuid_relacionado'],
            'serie'=>$r['serie'] ?: '-', 'folio'=>$r['folio'] ?: 'S/F',
            'fecha_factura'=>$fmtFecha($r['fecha_emision']),
            'rfc_emisor'=>$r['rfc_emisor'] ?: '-', 'emisor'=>$r['emisor'] ?: '-',
            'rfc_receptor'=>$r['rfc_receptor'] ?: '-', 'receptor'=>$r['receptor'] ?: '-',
            'metodo_pago'=>$r['metodo_pago'] ?: '-', 'forma_pago_factura'=>$r['forma_pago_factura'] ?: '-',
            'moneda'=>$r['moneda'] ?: '-', 'total_factura'=>(float)$r['total_xml'],
            'saldo_factura'=>(float)$r['saldo_pendiente'], 'ya_pago'=>(int)$r['ya_pago'],
            'id_detalle'=>(int)$r['id_detalle'], 'uuid_pago'=>$r['uuid_pago'] ?: '-',
            'origen'=>$r['origen_pago'] ?: '-', 'sintetico'=>(int)$r['es_sintetico'],
            'parcialidad'=>(int)$r['parcialidad'],
            'fecha_sat'=>$fmtFecha($r['fecha_pago_sat']), 'fecha_banco'=>$fmtFecha($r['fecha_pago_banco']),
            'fecha_contpaq'=>$fmtFecha($r['fecha_pago_contpaq']), 'fecha_usuario'=>$fmtFecha($r['fecha_pago_usuario']), 'fecha_fiscal'=>$fmtFecha($r['fecha_aplicacion_fiscal']),
            'moneda_factura'=>$r['moneda_factura'] ?: ($r['moneda'] ?: '-'), 'tc_factura'=>(float)($r['tc_factura'] ?: 1),
            'moneda_pago_sat'=>$r['moneda_pago_sat'] ?: '-', 'tc_pago_sat'=>(float)($r['tc_pago_sat'] ?: 1),
            'moneda_dr'=>$r['moneda_dr'] ?: '-', 'equivalencia_dr'=>(float)($r['equivalencia_dr'] ?: 1),
            'moneda_contpaq'=>$r['moneda_contpaq'] ?: '-', 'tc_contpaq'=>(float)($r['tc_contpaq'] ?: 0),
            'monto_conciliado_contpaq'=>(float)$r['monto_conciliado_contpaq'], 'saldo_por_conciliar'=>(float)$r['saldo_por_conciliar'],
            'estatus_conciliacion_contpaq'=>$r['estatus_conciliacion_contpaq'] ?: 'PENDIENTE',
            'forma_pago'=>$r['forma_pago_det'] ?: '-', 'referencia'=>$r['referencia'] ?: '-',
            'monto_pagado'=>(float)$r['monto_pagado'], 'importe_aplicado'=>(float)$r['importe_aplicado'],
            'saldo_anterior'=>(float)$r['saldo_anterior'], 'saldo_insoluto'=>(float)$r['saldo_insoluto'],
            'proporcion'=>(float)$r['proporcion_aplicada'],
            'base_iva_16'=>(float)$r['base_iva_16'], 'iva_16'=>(float)$r['iva_16'],
            'base_iva_8'=>(float)$r['base_iva_8'], 'iva_8'=>(float)$r['iva_8'],
            'base_tasa_0'=>(float)$r['base_tasa_0'], 'base_exento'=>(float)$r['base_exento'],
            'base_no_objeto'=>(float)$r['base_no_objeto'], 'iva_retenido'=>(float)$r['iva_retenido'],
            'isr_retenido'=>(float)$r['isr_retenido'], 'ieps_otros'=>(float)$r['ieps_otros'],
            'aplicado'=>(int)$r['aplicado'],
            'es_sustituido'=>(int)($r['es_sustituido']??0),
            'uuid_sustituto'=>$r['uuid_sustituto'] ?: '',
            'estatus_complemento'=>((int)($r['es_sustituido']??0)===1?'SUSTITUIDO':($esSustitutoRel===1?'SUSTITUTO':'VIGENTE')),
            'cuenta_diot'=>(
                (int)($r['es_sustituido']??0)===0
                && in_array(strtoupper(trim((string)($r['estatus_factura_sat']??'VIGENTE'))),['VIGENTE','1'],true)
                && (int)($r['excluir_diot']??0)===0
                && (int)($r['aplica_diot']??0)===1
                && strtoupper(trim((string)($r['rfc_receptor']??'')))===strtoupper(trim((string)($r['rfc_empresa']??'')))
                && ((int)($r['es_sintetico']??0)===1 || (!in_array(strtoupper(trim((string)($r['estatus_pago_sat']??''))),['CANCELADO','CANCELADA','0'],true) && (int)($r['pago_excluir_diot']??0)===0))
                ? 1 : 0
            ),
            'estatus_periodo_diot'=>'',
            'iva_tratamiento_especial_diot'=>(int)($r['iva_tratamiento_especial_diot'] ?? 0),
            'iva_porcentaje_acreditable_diot'=>(float)($r['iva_porcentaje_acreditable_diot'] ?? 100),
            'iva_motivo_tratamiento_diot'=>(string)($r['iva_motivo_tratamiento_diot'] ?? '')
        ];

        // Mantener una fotografía de los importes XML originales para auditoría.
        foreach (['base_iva_16','iva_16','base_iva_8','iva_8','base_no_objeto'] as $campoXml) {
            $item[$campoXml.'_xml'] = (float)$item[$campoXml];
        }

        // Mismo tratamiento especial usado por la generación DIOT:
        // solo la parte acreditable permanece en base/IVA normal; la base de la
        // parte no acreditable se reclasifica a NO OBJETO. El XML no se altera.
        if ((int)$item['iva_tratamiento_especial_diot'] === 1) {
            $pct = max(0.0, min(100.0, (float)$item['iva_porcentaje_acreditable_diot'])) / 100.0;
            $noAcred = 1.0 - $pct;
            $base16Original = (float)$item['base_iva_16'];
            $iva16Original  = (float)$item['iva_16'];
            $base8Original  = (float)$item['base_iva_8'];
            $iva8Original   = (float)$item['iva_8'];
            $item['base_iva_16'] = $base16Original * $pct;
            $item['iva_16']      = $iva16Original * $pct;
            $item['base_iva_8']  = $base8Original * $pct;
            $item['iva_8']       = $iva8Original * $pct;
            $item['base_no_objeto'] = (float)$item['base_no_objeto']
                + ($base16Original * $noAcred)
                + ($base8Original * $noAcred);
            $item['iva_16_no_acreditable'] = $iva16Original * $noAcred;
            $item['iva_8_no_acreditable']  = $iva8Original * $noAcred;
        } else {
            $item['iva_16_no_acreditable'] = 0.0;
            $item['iva_8_no_acreditable']  = 0.0;
        }

        // Conversión al vuelo para totales y exportaciones. La fila visible conserva
        // los importes originales; los campos *_mxn se usan únicamente para sumar.
        $monedaDetalle = $r['moneda_dr'] ?: ($r['moneda_factura'] ?: ($r['moneda'] ?: 'MXN'));
        $tcDetalle = (float)((($r['tc_banco_real'] ?? 0) > 0 && (float)$r['tc_banco_real'] != 1.0) ? $r['tc_banco_real'] : ($r['tc_factura'] ?: ($r['tc_xml_factura'] ?? 1)));
        $factorDetalleMxn = diot_factor_mxn($monedaDetalle, $tcDetalle);
        $factorFacturaMxn = diot_factor_mxn($r['moneda'] ?: 'MXN', (float)($r['tc_xml_factura'] ?: $tcDetalle));

        $camposDetalleMxn = [
            'monto_pagado','importe_aplicado','saldo_anterior','saldo_insoluto',
            'base_iva_16','iva_16','base_iva_8','iva_8','base_tasa_0','base_exento',
            'base_no_objeto','iva_retenido','isr_retenido','ieps_otros'
        ];
        foreach ($camposDetalleMxn as $campoMxn) {
            $item[$campoMxn.'_mxn'] = (float)$item[$campoMxn] * $factorDetalleMxn;
        }
        $item['total_factura_mxn'] = (float)$item['total_factura'] * $factorFacturaMxn;
        $item['saldo_factura_mxn'] = (float)$item['saldo_factura'] * $factorFacturaMxn;
        $item['moneda_importes'] = 'MXN';
        $item['factor_conversion_mxn'] = $factorDetalleMxn;

        // En exportación sí se entregan los importes fiscales ya convertidos a MXN.
        // La moneda y TC originales permanecen como columnas de referencia/auditoría.
        if ($exportAll) {
            foreach ($camposDetalleMxn as $campoMxn) {
                $item[$campoMxn] = $item[$campoMxn.'_mxn'];
            }
            $item['total_factura'] = $item['total_factura_mxn'];
            $item['saldo_factura'] = $item['saldo_factura_mxn'];
        }

        // En la exportación DIOT solo se entregan registros que realmente cuentan para DIOT.
        // La vista normal de DETALLE DE PAGOS conserva todos los movimientos para auditoría.
        // Se filtra aquí (y no en el SQL) para no alterar ni romper la consulta general.
        if ($exportAll && $exportMode === 'diot' && (int)$item['cuenta_diot'] !== 1) {
            continue;
        }

        $data[] = $item;

        if ($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) {
            $idx = count($data) - 1;
            $fechaFiscalRaw = trim((string)($r['fecha_aplicacion_fiscal'] ?? ''));
            if ($fechaFiscalRaw === '' || $fechaFiscalRaw === '0000-00-00' || $fechaFiscalRaw === '0000-00-00 00:00:00') {
                $data[$idx]['estatus_periodo_diot'] = 'SIN FECHA FISCAL';
            } else {
                $tsFiscal = strtotime($fechaFiscalRaw);
                $tsIni = strtotime($periodoInicio . ' 00:00:00');
                $tsFin = strtotime($periodoFinExclusivo . ' 00:00:00');
                $data[$idx]['estatus_periodo_diot'] =
                    ($tsFiscal !== false && $tsFiscal >= $tsIni && $tsFiscal < $tsFin)
                    ? 'EN PERIODO'
                    : 'FUERA DE PERIODO';
            }
        }
    }
    echo json_encode(['data'=>$data], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    seguridad_log_error($e, 'listar_detalles_pagos'); echo json_encode(['data'=>[], 'error'=>'No se pudieron consultar los detalles de pagos.'], JSON_UNESCAPED_UNICODE);
}
