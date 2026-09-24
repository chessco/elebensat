<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';
require_once '../includes/diot_detalles.php';
require_once '../includes/control_diot_pagos.php';

function normalizar_fecha_filtro($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    foreach (['Y-m-d','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    return '';
}

function fecha_fin_exclusiva(string $fecha): string {
    if ($fecha === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$d) return '';
    $d->modify('+1 day');
    return $d->format('Y-m-d') . ' 00:00:00';
}

function resolver_busqueda_dirigida(PDO $pdo, int $idEmpresa, string $tipoBusqueda, string $busqueda, array &$params): string {
    $tipoBusqueda = strtolower(trim($tipoBusqueda));
    $busqueda = trim($busqueda);

    // Sin texto o sin clasificación: no se aplica búsqueda especial.
    // De esta forma la consulta vuelve al índice normal empresa + fecha
    // (y a los índices de tipo/método cuando esos filtros están activos).
    if ($busqueda === '' || $tipoBusqueda === '') return '';

    // Todas las búsquedas son por PREFIJO. Nada de LIKE '%texto%'.
    $prefijo = $busqueda . '%';

    if ($tipoBusqueda === 'uuid') {
        // Cuando ya viene el UUID completo usamos igualdad para ir directo a la PK.
        // Si el usuario apenas está capturando un prefijo, conservamos LIKE 'texto%'.
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $busqueda)) {
            $params[':buscar_uuid_exacta'] = strtoupper($busqueda);
            return ' AND f.uuid = :buscar_uuid_exacta';
        }
        $params[':buscar_uuid'] = $prefijo;
        return ' AND f.uuid LIKE :buscar_uuid';
    }

    if ($tipoBusqueda === 'folio') {
        // Folio siempre respeta empresa + rango + clasificación/método activos.
        // Se busca por prefijo para conservar la misma mecánica del visor y permitir
        // capturar solo los primeros caracteres del folio.
        $params[':buscar_folio'] = $prefijo;
        return ' AND f.folio LIKE :buscar_folio';
    }

    $catalogos = [
        'rfc_emisor'      => ['tabla'=>'cat_emisores',   'campo'=>'rfc',    'id'=>'id_emisor',   'factura'=>'id_emisor'],
        'nombre_emisor'   => ['tabla'=>'cat_emisores',   'campo'=>'nombre', 'id'=>'id_emisor',   'factura'=>'id_emisor'],
        'rfc_receptor'    => ['tabla'=>'cat_receptores', 'campo'=>'rfc',    'id'=>'id_receptor', 'factura'=>'id_receptor'],
        'nombre_receptor' => ['tabla'=>'cat_receptores', 'campo'=>'nombre', 'id'=>'id_receptor', 'factura'=>'id_receptor'],
    ];

    if (!isset($catalogos[$tipoBusqueda])) return '';
    $cfg = $catalogos[$tipoBusqueda];

    // Primero resolvemos el/los IDs del catálogo utilizando su índice específico.
    // Después facturas trabaja únicamente con id_empresa + id_emisor/id_receptor + fecha.
    // Es el equivalente SQL de escoger el TAG correcto antes de buscar en DBF/CDX.
    $sqlIds = "SELECT {$cfg['id']}
               FROM {$cfg['tabla']}
               WHERE id_empresa=:emp_busqueda
                 AND {$cfg['campo']} LIKE :prefijo_busqueda
               ORDER BY {$cfg['campo']}
               LIMIT 500";
    $stIds = $pdo->prepare($sqlIds);
    $stIds->execute([
        ':emp_busqueda'=>$idEmpresa,
        ':prefijo_busqueda'=>$prefijo,
    ]);
    $ids = array_values(array_unique(array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN))));

    if (!$ids) return ' AND 1=0';

    $ph = [];
    foreach ($ids as $i => $id) {
        $k = ':bus_id_' . $i;
        $ph[] = $k;
        $params[$k] = $id;
    }

    return ' AND f.' . $cfg['factura'] . ' IN (' . implode(',', $ph) . ')';
}

function formatear_fila_factura(array $r, array $pagosPorUuid): array {
    $uuid = (string)($r['uuid'] ?? '');
    $pago = $pagosPorUuid[$uuid] ?? null;
    $numeroPagos = $pago ? (int)$pago['numero_pagos'] : 0;
    $fechaPagoSat = $pago && !empty($pago['fecha_pago_sat']) ? $pago['fecha_pago_sat'] : ($r['fecha_pago_sat_factura'] ?? null);

    $fecha = !empty($r['fecha_emision']) ? date('d/m/Y', strtotime($r['fecha_emision'])) : '-';
    $fmtFecha = static function($v){ return $v ? date('d/m/Y', strtotime($v)) : '-'; };
    $tipoSat = strtoupper((string)($r['tipo_sat'] ?? ''));
    $tipoMovimiento = strtoupper((string)($r['tipo_movimiento_empresa'] ?? ''));
    $tipoMostrar = $tipoSat ?: $tipoMovimiento;

    return [
      'tipo'=>$tipoMostrar, 'estatus_sat'=>$r['estatus_sat'] ?: 'Vigente', 'uuid'=>$uuid, 'version'=>$r['version_cfdi'] ?: '-',
      'serie'=>$r['serie'] ?: '-', 'folio'=>$r['folio'] ?: 'S/F', 'fecha'=>$fecha,
      'rfc_emisor'=>$r['rfc_emisor'] ?: '-', 'emisor'=>$r['emisor'] ?: '-',
      'forma_pago'=>$r['forma_pago'] ?: '-', 'metodo_pago'=>$r['metodo_pago'] ?: '-',
      'numero_pagos'=>$numeroPagos,
      'uso_cfdi'=>$r['uso_cfdi'] ?: '-', 'moneda'=>$r['moneda'] ?: '-',
      'tc_factura'=>(float)$r['tc_xml_factura'], 'total'=>(float)$r['total'], 'subtotal'=>(float)$r['subtotal'],
      'base_iva'=>(float)$r['base_iva'], 'iva_t'=>(float)$r['iva_t'],
      'base_iva_r'=>(float)$r['base_ret_iva'], 'iva_r'=>(float)$r['iva_r'],
      'base_isr_r'=>(float)$r['base_ret_isr'], 'isr_r'=>(float)$r['isr_r'],
      'base_iva_8'=>(float)$r['base_iva_8'], 'iva_8'=>(float)$r['iva_8'],
      'base_norte'=>(float)$r['base_iva_frontera_norte'], 'iva_norte'=>(float)$r['iva_frontera_norte'],
      'base_sur'=>(float)$r['base_iva_frontera_sur'], 'iva_sur'=>(float)$r['iva_frontera_sur'],
      'cp'=>$r['lugar_expedicion_xml'] ?: '-', 'region_cp'=>$r['cp_region_fronteriza'] ?: 'RESTO',
      'region_aplicada'=>$r['region_diot_aplicada'] ?: 'RESTO',
      'revision'=>(int)$r['requiere_revision_region'], 'mensaje_revision'=>$r['mensaje_revision_region'] ?: '',
      'rfc_receptor'=>$r['rfc_receptor'] ?: '-', 'receptor'=>$r['receptor'] ?: '-',
      'p_sat'=>$fmtFecha($fechaPagoSat), 'p_banco'=>$fmtFecha($r['p_banco']), 'p_usu'=>$fmtFecha($r['p_usu']),
      'p_contpaq'=>$fmtFecha($r['fecha_pago_empresa'] ?? $r['p_contpaq']),
      'fecha_fiscal'=>$fmtFecha($r['fecha_fiscal']), 'ya_pago'=>(int)$r['ya_pago'],
      'tc_pago'=>(float)$r['tc_xml_pago'], 'tc_banco'=>(float)$r['tc_banco_real'],
      'ref_xml'=>$r['referencia_xml'] ?: '-', 'ref_banco'=>($r['referencias_pago_empresa'] ?: ($r['referencia_banco_real'] ?: '-')),
      'conciliado'=>(int)$r['esta_conciliado'], 'saldo'=>(float)$r['saldo_pendiente'],
      'origen_pago_financiero'=>$r['origen_pago_financiero'] ?? null,
      'folio_pago_origen'=>$r['folio_pago_origen'] ?? null,
      'fecha_pago_origen'=>$fmtFecha($r['fecha_pago_origen'] ?? null),
      'estatus_pago_origen'=>$r['estatus_pago_origen'] ?? null,
      'conciliado_banco_origen'=>(int)($r['conciliado_banco_origen'] ?? 0),
      'fecha_conciliacion_banco_origen'=>$fmtFecha($r['fecha_conciliacion_banco_origen'] ?? null),
      'fecha_valor_origen'=>$fmtFecha($r['fecha_valor_origen'] ?? null),
      'estatus_conciliacion_financiera'=>$r['estatus_conciliacion_financiera'] ?? 'PENDIENTE',
      'es_gasolina'=>(int)$r['es_gasolina'], 'total_ieps_gasolina'=>(float)$r['total_ieps_gasolina'],
      'nomina_tipo_nomina'=>$r['nomina_tipo_nomina'] ?: '-',
      'nomina_fecha_pago'=>$fmtFecha($r['nomina_fecha_pago']),
      'nomina_fecha_inicial_pago'=>$fmtFecha($r['nomina_fecha_inicial_pago']),
      'nomina_fecha_final_pago'=>$fmtFecha($r['nomina_fecha_final_pago']),
      'nomina_num_dias_pagados'=>$r['nomina_num_dias_pagados'] !== null ? (float)$r['nomina_num_dias_pagados'] : null,
      'nomina_periodicidad_pago'=>$r['nomina_periodicidad_pago'] ?: '-',
      'nomina_num_empleado'=>$r['nomina_num_empleado'] ?: '-',
      'nomina_total_percepciones'=>(float)$r['nomina_total_percepciones'],
      'nomina_total_deducciones'=>(float)$r['nomina_total_deducciones'],
      'nomina_total_otros_pagos'=>(float)$r['nomina_total_otros_pagos'],
      'raw'=>[
         'uuid'=>$uuid, 'tipo_raw'=>$tipoSat, 'tipo_movimiento'=>$tipoMovimiento, 'emisor'=>$r['emisor'], 'receptor'=>$r['receptor'],
         'fecha'=>$fecha, 'total'=>number_format((float)$r['total'],2), 'iva_xml'=>(float)$r['iva_t'], 'estatus_sat'=>$r['estatus_sat'],
         'p_usu_raw'=>$r['p_usu'], 'excluir_diot'=>(int)$r['excluir_diot'],
         'es_gasolina'=>(int)$r['es_gasolina'], 'total_ieps_gasolina'=>(float)$r['total_ieps_gasolina'],
         'efecto_fiscal_diot'=>$r['efecto_fiscal_diot'] ?: '01',
         'iva_tratamiento_especial_diot'=>(int)($r['iva_tratamiento_especial_diot'] ?? 0),
         'iva_porcentaje_acreditable_diot'=>(float)($r['iva_porcentaje_acreditable_diot'] ?? 100),
         'iva_motivo_tratamiento_diot'=>$r['iva_motivo_tratamiento_diot'] ?? ''
      ]
    ];
}


/**
 * Completa SOLO las filas ya paginadas del modo rápido.
 * No hace JOIN sobre el rango completo: consulta catálogos y pagos únicamente
 * para los id_emisor/id_receptor/UUID presentes en la página actual.
 */
function hidratar_filas_rapidas(PDO $pdo, int $idEmpresa, array $rows): array {
    if (!$rows) return [];

    $idsEmisor = [];
    $idsReceptor = [];
    $uuids = [];
    foreach ($rows as $r) {
        $ie = (int)($r['id_emisor'] ?? 0);
        $ir = (int)($r['id_receptor'] ?? 0);
        $u = trim((string)($r['uuid'] ?? ''));
        if ($ie > 0) $idsEmisor[$ie] = true;
        if ($ir > 0) $idsReceptor[$ir] = true;
        if ($u !== '') $uuids[$u] = true;
    }

    $mapE = [];
    if ($idsEmisor) {
        $ph=[]; $pa=[':emp'=>$idEmpresa];
        foreach (array_keys($idsEmisor) as $i=>$id) { $k=':e'.$i; $ph[]=$k; $pa[$k]=$id; }
        $st=$pdo->prepare('SELECT id_emisor,rfc,nombre FROM cat_emisores WHERE id_empresa=:emp AND id_emisor IN ('.implode(',', $ph).')');
        $st->execute($pa);
        while($x=$st->fetch(PDO::FETCH_ASSOC)) $mapE[(int)$x['id_emisor']]=$x;
    }

    $mapR = [];
    if ($idsReceptor) {
        $ph=[]; $pa=[':emp'=>$idEmpresa];
        foreach (array_keys($idsReceptor) as $i=>$id) { $k=':r'.$i; $ph[]=$k; $pa[$k]=$id; }
        $st=$pdo->prepare('SELECT id_receptor,rfc,nombre FROM cat_receptores WHERE id_empresa=:emp AND id_receptor IN ('.implode(',', $ph).')');
        $st->execute($pa);
        while($x=$st->fetch(PDO::FETCH_ASSOC)) $mapR[(int)$x['id_receptor']]=$x;
    }

    $pagos = [];
    if ($uuids) {
        foreach (array_chunk(array_keys($uuids), 500) as $chunk) {
            $ph=[]; $pa=[':emp'=>$idEmpresa];
            foreach ($chunk as $i=>$u) { $k=':u'.$i; $ph[]=$k; $pa[$k]=$u; }
            $st=$pdo->prepare('SELECT uuid_relacionado, COUNT(*) numero_pagos, MAX(fecha_pago_sat) fecha_pago_sat FROM facturas_pagos_detalles WHERE id_empresa=:emp AND uuid_relacionado IN ('.implode(',', $ph).') GROUP BY uuid_relacionado');
            $st->execute($pa);
            while($x=$st->fetch(PDO::FETCH_ASSOC)) $pagos[(string)$x['uuid_relacionado']]=$x;
        }
    }

    $data=[];
    foreach ($rows as $r) {
        $e=$mapE[(int)($r['id_emisor'] ?? 0)] ?? ['rfc'=>'','nombre'=>''];
        $re=$mapR[(int)($r['id_receptor'] ?? 0)] ?? ['rfc'=>'','nombre'=>''];

        // Alias iguales a los de la consulta normal para reutilizar exactamente
        // el mismo formateador y no perder ninguna columna del contador.
        $n=$r;
        $n['tipo_sat']=$r['id_tipo_comprobante'] ?? '';
        $n['rfc_emisor']=$e['rfc'] ?? '';
        $n['emisor']=$e['nombre'] ?? '';
        $n['rfc_receptor']=$re['rfc'] ?? '';
        $n['receptor']=$re['nombre'] ?? '';
        $n['total']=$r['total_xml'] ?? 0;
        $n['subtotal']=$r['subtotal_xml'] ?? 0;
        $n['iva_t']=$r['iva_traslado'] ?? 0;
        $n['iva_r']=$r['iva_retenido'] ?? 0;
        $n['isr_r']=$r['isr_retenido'] ?? 0;
        $n['base_iva_8']=$r['base_iva_fronteriza_xml'] ?? 0;
        $n['iva_8']=$r['iva_fronterizo_xml'] ?? 0;
        $n['fecha_pago_sat_factura']=$r['fecha_pago_sat'] ?? null;
        $n['p_banco']=$r['fecha_pago_banco'] ?? null;
        $n['p_usu']=$r['fecha_pago_usuario'] ?? null;
        $n['p_contpaq']=$r['fecha_ultimo_pago_contpaq'] ?? null;
        $n['fecha_pago_empresa']=$r['fecha_pago_origen'] ?? null;
        $n['referencias_pago_empresa']=$r['folio_pago_origen'] ?? null;
        $n['fecha_fiscal']=$r['fecha_aplicacion_fiscal'] ?? null;
        $data[]=formatear_fila_factura($n, $pagos);
    }
    return $data;
}

// DataTables server-side se envía por POST para evitar URLs demasiado largas
// cuando la tabla contiene muchas columnas. Se conserva compatibilidad con GET
// para pruebas directas desde el navegador.
$req = array_merge($_GET, $_POST);

try {
    if (!isset($_SESSION['id_empresa'])) throw new Exception('Sin sesión');
    $idEmpresa = (int)$_SESSION['id_empresa'];

    // Solicitud exclusiva de totales. Si el visor está usando una de las rutas
    // rápidas, la suma debe respetar EXACTAMENTE esa misma ruta/filtro.
    $soloTotalesGlobal = ((int)($req['solo_totales'] ?? 0) === 1);
    if ($soloTotalesGlobal) {
        $modoMesTot = ((int)($req['modo_mes_simple'] ?? 0) === 1);
        $modoEmisorTot = ((int)($req['modo_emisor_simple'] ?? 0) === 1);
        $modoReceptorTot = ((int)($req['modo_receptor_simple'] ?? 0) === 1);

        if ($modoMesTot || $modoEmisorTot || $modoReceptorTot) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $tTot0 = microtime(true);
            $iniTot = normalizar_fecha_filtro($req['inicio'] ?? '');
            $finTot = normalizar_fecha_filtro($req['fin'] ?? '');
            if ($iniTot === '' || $finTot === '') throw new Exception('Rango de fechas inválido para calcular totales.');
            $finExcTot = fecha_fin_exclusiva($finTot);
            $paramsTot = [
                ':emp_tot'=>$idEmpresa,
                ':ini_tot'=>$iniTot . ' 00:00:00',
                ':fin_tot'=>$finExcTot,
            ];
            $whereTot = ' WHERE id_empresa=:emp_tot AND fecha_emision>=:ini_tot AND fecha_emision<:fin_tot ';
            $forceTot = ' FORCE INDEX (idx_facturas_empresa_fecha) ';

            if ($modoEmisorTot || $modoReceptorTot) {
                $pref = trim((string)($req['busqueda'] ?? ''));
                $tipoBus = strtolower(trim((string)($req['tipo_busqueda'] ?? '')));
                if ($pref === '') throw new Exception('Capture el valor que desea buscar antes de calcular totales.');
                $prefLike = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $pref) . '%';

                if ($modoEmisorTot) {
                    $campo = ($tipoBus === 'rfc_emisor') ? 'rfc' : 'nombre';
                    $stIds = $pdo->prepare("SELECT id_emisor FROM cat_emisores WHERE id_empresa=:emp_cat AND {$campo} LIKE :pref ESCAPE '=' ORDER BY {$campo} ASC");
                    $stIds->execute([':emp_cat'=>$idEmpresa, ':pref'=>$prefLike]);
                    $ids = array_values(array_unique(array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN))));
                    if (!$ids) {
                        echo json_encode(['ok'=>true,'totals'=>['registros'=>0],'tiempo_ms'=>round((microtime(true)-$tTot0)*1000,2)], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
                    }
                    $ph=[];
                    foreach($ids as $i=>$id){ $k=':eid_tot_'.$i; $ph[]=$k; $paramsTot[$k]=$id; }
                    $whereTot .= ' AND id_emisor IN ('.implode(',', $ph).')';
                    $forceTot = ' FORCE INDEX (idx_facturas_empresa_emisor_fecha) ';
                } else {
                    $campo = ($tipoBus === 'rfc_receptor') ? 'rfc' : 'nombre';
                    $stIds = $pdo->prepare("SELECT id_receptor FROM cat_receptores WHERE id_empresa=:emp_cat AND {$campo} LIKE :pref ESCAPE '=' ORDER BY {$campo} ASC");
                    $stIds->execute([':emp_cat'=>$idEmpresa, ':pref'=>$prefLike]);
                    $ids = array_values(array_unique(array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN))));
                    if (!$ids) {
                        echo json_encode(['ok'=>true,'totals'=>['registros'=>0],'tiempo_ms'=>round((microtime(true)-$tTot0)*1000,2)], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
                    }
                    $ph=[];
                    foreach($ids as $i=>$id){ $k=':rid_tot_'.$i; $ph[]=$k; $paramsTot[$k]=$id; }
                    $whereTot .= ' AND id_receptor IN ('.implode(',', $ph).')';
                    $forceTot = ' FORCE INDEX (idx_facturas_empresa_receptor_fecha) ';
                }
            }

            $factorTot = "(CASE WHEN UPPER(TRIM(COALESCE(moneda,'MXN'))) IN ('MXN','XXX','') THEN 1 ELSE COALESCE(NULLIF(tc_xml_factura,0),1) END)";
            $sqlTot = "SELECT COUNT(*) registros,
                COALESCE(SUM(total_xml * {$factorTot}),0) total,
                COALESCE(SUM(subtotal_xml * {$factorTot}),0) subtotal,
                COALESCE(SUM(base_iva * {$factorTot}),0) base_iva,
                COALESCE(SUM(iva_traslado * {$factorTot}),0) iva_t,
                COALESCE(SUM(base_ret_iva * {$factorTot}),0) base_iva_r,
                COALESCE(SUM(iva_retenido * {$factorTot}),0) iva_r,
                COALESCE(SUM(base_ret_isr * {$factorTot}),0) base_isr_r,
                COALESCE(SUM(isr_retenido * {$factorTot}),0) isr_r,
                COALESCE(SUM(base_iva_fronteriza_xml * {$factorTot}),0) base_iva_8,
                COALESCE(SUM(iva_fronterizo_xml * {$factorTot}),0) iva_8,
                COALESCE(SUM(base_iva_frontera_norte * {$factorTot}),0) base_norte,
                COALESCE(SUM(iva_frontera_norte * {$factorTot}),0) iva_norte,
                COALESCE(SUM(base_iva_frontera_sur * {$factorTot}),0) base_sur,
                COALESCE(SUM(iva_frontera_sur * {$factorTot}),0) iva_sur,
                COALESCE(SUM(saldo_pendiente * {$factorTot}),0) saldo
                FROM facturas {$forceTot} {$whereTot}";
            $stTot = $pdo->prepare($sqlTot);
            $stTot->execute($paramsTot);
            $tot = $stTot->fetch(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok'=>true,'totals'=>$tot,'tiempo_ms'=>round((microtime(true)-$tTot0)*1000,2)], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    /*
     * PRUEBA TEMPORAL DE VELOCIDAD - PERIODO RAPIDO
     * ------------------------------------------------
     * Cuando visor.php envía modo_mes_simple=1 NO entra a ninguna de las
     * condiciones normales del visor. Solo usa:
     *   id_empresa + fecha_emision
     * y pagina directamente con LIMIT/OFFSET.
     *
     * Se fuerza el índice existente idx_facturas_empresa_fecha para poder
     * medir únicamente la velocidad del acceso por empresa/fecha.
     * No JOIN, no pagos, no sumas, no clasificación, no búsqueda, no DIOT.
     */
    /*
     * FILTRO RAPIDO 3 - NOMBRE EMISOR + RANGO
     * ------------------------------------------------
     * El texto del usuario SOLO se usa para resolver emisores cuyo nombre
     * EMPIECE con el prefijo escrito (prefijo%). Nunca se usa %prefijo%.
     * Después facturas trabaja exclusivamente con:
     *   id_empresa + id_emisor + fecha_emision
     * forzando idx_facturas_empresa_emisor_fecha.
     */
    /*
     * FILTRO RAPIDO 4 - NOMBRE RECEPTOR + RANGO
     * ------------------------------------------------
     * Misma lógica del emisor: resolver por prefijo en cat_receptores y después
     * consultar facturas exclusivamente con id_empresa + id_receptor + fecha_emision,
     * forzando idx_facturas_empresa_receptor_fecha.
     */
    $modoReceptorSimple = ((int)($req['modo_receptor_simple'] ?? 0) === 1);
    if ($modoReceptorSimple) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $t0 = microtime(true);
        $inicioSimple = normalizar_fecha_filtro($req['inicio'] ?? '');
        $finSimple = normalizar_fecha_filtro($req['fin'] ?? '');
        $prefijoReceptor = trim((string)($req['busqueda'] ?? ''));
        $tipoBusquedaSimple = strtolower(trim((string)($req['tipo_busqueda'] ?? 'nombre_receptor')));
        $campoReceptorSimple = ($tipoBusquedaSimple === 'rfc_receptor') ? 'rfc' : 'nombre';
        $drawSimple = max(0, (int)($req['draw'] ?? 0));
        $startSimple = max(0, (int)($req['start'] ?? 0));
        $lengthSimple = (int)($req['length'] ?? 15);
        if ($lengthSimple < 1) $lengthSimple = 15;
        if ($lengthSimple > 200) $lengthSimple = 200;

        if ($inicioSimple === '' || $finSimple === '') {
            throw new Exception('Rango de fechas inválido para el filtro rápido de receptor.');
        }
        if ($prefijoReceptor === '') {
            throw new Exception($campoReceptorSimple === 'rfc' ? 'Capture el RFC del receptor.' : 'Capture el inicio del nombre del receptor.');
        }

        $finExclusivoSimple = fecha_fin_exclusiva($finSimple);

        $tr0 = microtime(true);
        $prefijoLike = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $prefijoReceptor) . '%';
        $stReceptores = $pdo->prepare("SELECT id_receptor, rfc, nombre
                                      FROM cat_receptores
                                      WHERE id_empresa=:emp_cat
                                        AND {$campoReceptorSimple} LIKE :prefijo ESCAPE '='
                                      ORDER BY {$campoReceptorSimple} ASC");
        $stReceptores->execute([
            ':emp_cat' => $idEmpresa,
            ':prefijo' => $prefijoLike,
        ]);
        $receptores = $stReceptores->fetchAll(PDO::FETCH_ASSOC);
        $tr1 = microtime(true);

        if (!$receptores) {
            echo json_encode([
                'draw'=>$drawSimple,
                'recordsTotal'=>0,
                'recordsFiltered'=>0,
                'data'=>[],
                'totals'=>[],
                'serverSide'=>true,
                'prueba_receptor_simple'=>true,
                'receptores_encontrados'=>0,
                'debug_ms'=>[
                    'resolver'=>round(($tr1-$tr0)*1000,2),
                    'count'=>0,
                    'select'=>0,
                    'formato'=>0,
                    'total'=>round((microtime(true)-$t0)*1000,2)
                ]
            ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $paramsSimple = [
            ':id_simple' => $idEmpresa,
            ':ini_simple' => $inicioSimple . ' 00:00:00',
            ':fin_simple' => $finExclusivoSimple,
        ];
        $phReceptores = [];
        foreach ($receptores as $i => $r) {
            $id = (int)$r['id_receptor'];
            $ph = ':rec_' . $i;
            $phReceptores[] = $ph;
            $paramsSimple[$ph] = $id;
        }
        $inReceptores = implode(',', $phReceptores);

        $tc0 = microtime(true);
        $sqlCountSimple = "SELECT COUNT(*)
                           FROM facturas FORCE INDEX (idx_facturas_empresa_receptor_fecha)
                           WHERE id_empresa=:id_simple
                             AND id_receptor IN ({$inReceptores})
                             AND fecha_emision>=:ini_simple
                             AND fecha_emision<:fin_simple";
        $stCountSimple = $pdo->prepare($sqlCountSimple);
        $stCountSimple->execute($paramsSimple);
        $totalSimple = (int)$stCountSimple->fetchColumn();
        $tc1 = microtime(true);

        $ts0 = microtime(true);
        $sqlSimple = "SELECT
                uuid, id_emisor, id_receptor, id_tipo_comprobante, tipo_movimiento_empresa, estatus_sat, excluir_diot,
                version_cfdi, serie, folio, fecha_emision, forma_pago, metodo_pago, uso_cfdi, moneda,
                tc_xml_factura, total_xml, subtotal_xml, base_iva, iva_traslado, base_ret_iva, iva_retenido,
                base_ret_isr, isr_retenido, base_iva_fronteriza_xml, iva_fronterizo_xml,
                base_iva_frontera_norte, iva_frontera_norte, base_iva_frontera_sur, iva_frontera_sur,
                lugar_expedicion_xml, cp_region_fronteriza, region_diot_aplicada, requiere_revision_region,
                mensaje_revision_region, fecha_pago_sat, fecha_pago_banco, fecha_pago_usuario,
                fecha_ultimo_pago_contpaq, fecha_pago_origen, folio_pago_origen, fecha_aplicacion_fiscal,
                ya_pago, tc_xml_pago, tc_banco_real, referencia_xml, referencia_banco_real,
                esta_conciliado, saldo_pendiente, estatus_conciliacion_financiera,
                origen_pago_financiero, estatus_pago_origen, conciliado_banco_origen,
                fecha_conciliacion_banco_origen, fecha_valor_origen,
                es_gasolina, total_ieps_gasolina,
                nomina_tipo_nomina, nomina_fecha_pago, nomina_fecha_inicial_pago, nomina_fecha_final_pago,
                nomina_num_dias_pagados, nomina_periodicidad_pago, nomina_num_empleado,
                nomina_total_percepciones, nomina_total_deducciones, nomina_total_otros_pagos,
                COALESCE(NULLIF(efecto_fiscal_diot,''),'01') efecto_fiscal_diot,
                COALESCE(iva_tratamiento_especial_diot,0) iva_tratamiento_especial_diot,
                COALESCE(iva_porcentaje_acreditable_diot,100) iva_porcentaje_acreditable_diot,
                COALESCE(iva_motivo_tratamiento_diot,'') iva_motivo_tratamiento_diot
            FROM facturas FORCE INDEX (idx_facturas_empresa_receptor_fecha)
            WHERE id_empresa=:id_simple
              AND id_receptor IN ({$inReceptores})
              AND fecha_emision>=:ini_simple
              AND fecha_emision<:fin_simple
            ORDER BY fecha_emision ASC
            LIMIT {$lengthSimple} OFFSET {$startSimple}";
        $stSimple = $pdo->prepare($sqlSimple);
        $stSimple->execute($paramsSimple);
        $rowsSimple = $stSimple->fetchAll(PDO::FETCH_ASSOC);
        $ts1 = microtime(true);

        $tf0 = microtime(true);
        $dataSimple = hidratar_filas_rapidas($pdo, $idEmpresa, $rowsSimple);
        $tf1 = microtime(true);

        echo json_encode([
            'draw'=>$drawSimple,
            'recordsTotal'=>$totalSimple,
            'recordsFiltered'=>$totalSimple,
            'data'=>$dataSimple,
            'totals'=>[],
            'serverSide'=>true,
            'prueba_receptor_simple'=>true,
            'receptores_encontrados'=>count($receptores),
            'debug_ms'=>[
                'resolver'=>round(($tr1-$tr0)*1000,2),
                'count'=>round(($tc1-$tc0)*1000,2),
                'select'=>round(($ts1-$ts0)*1000,2),
                'formato'=>round(($tf1-$tf0)*1000,2),
                'total'=>round((microtime(true)-$t0)*1000,2)
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $modoEmisorSimple = ((int)($req['modo_emisor_simple'] ?? 0) === 1);
    if ($modoEmisorSimple) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $t0 = microtime(true);
        $inicioSimple = normalizar_fecha_filtro($req['inicio'] ?? '');
        $finSimple = normalizar_fecha_filtro($req['fin'] ?? '');
        $prefijoEmisor = trim((string)($req['busqueda'] ?? ''));
        $tipoBusquedaSimple = strtolower(trim((string)($req['tipo_busqueda'] ?? 'nombre_emisor')));
        $campoEmisorSimple = ($tipoBusquedaSimple === 'rfc_emisor') ? 'rfc' : 'nombre';
        $drawSimple = max(0, (int)($req['draw'] ?? 0));
        $startSimple = max(0, (int)($req['start'] ?? 0));
        $lengthSimple = (int)($req['length'] ?? 15);
        if ($lengthSimple < 1) $lengthSimple = 15;
        if ($lengthSimple > 200) $lengthSimple = 200;

        if ($inicioSimple === '' || $finSimple === '') {
            throw new Exception('Rango de fechas inválido para el filtro rápido de emisor.');
        }
        if ($prefijoEmisor === '') {
            throw new Exception($campoEmisorSimple === 'rfc' ? 'Capture el RFC del emisor.' : 'Capture el inicio del nombre del emisor.');
        }

        $finExclusivoSimple = fecha_fin_exclusiva($finSimple);

        // Resolver únicamente nombres que EMPIECEN con el texto escrito.
        // ESCAPE '=' evita que % o _ capturados por el usuario se conviertan en comodines.
        $tr0 = microtime(true);
        $prefijoLike = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $prefijoEmisor) . '%';
        $stEmisores = $pdo->prepare("SELECT id_emisor, rfc, nombre
                                    FROM cat_emisores
                                    WHERE id_empresa=:emp_cat
                                      AND {$campoEmisorSimple} LIKE :prefijo ESCAPE '='
                                    ORDER BY {$campoEmisorSimple} ASC");
        $stEmisores->execute([
            ':emp_cat' => $idEmpresa,
            ':prefijo' => $prefijoLike,
        ]);
        $emisores = $stEmisores->fetchAll(PDO::FETCH_ASSOC);
        $tr1 = microtime(true);

        // Si no hay coincidencias, devolver respuesta inmediata y conservar paginación DataTables.
        if (!$emisores) {
            echo json_encode([
                'draw'=>$drawSimple,
                'recordsTotal'=>0,
                'recordsFiltered'=>0,
                'data'=>[],
                'totals'=>[],
                'serverSide'=>true,
                'prueba_emisor_simple'=>true,
                'emisores_encontrados'=>0,
                'debug_ms'=>[
                    'resolver'=>round(($tr1-$tr0)*1000,2),
                    'count'=>0,
                    'select'=>0,
                    'formato'=>0,
                    'total'=>round((microtime(true)-$t0)*1000,2)
                ]
            ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $mapEmisores = [];
        $paramsSimple = [
            ':id_simple' => $idEmpresa,
            ':ini_simple' => $inicioSimple . ' 00:00:00',
            ':fin_simple' => $finExclusivoSimple,
        ];
        $phEmisores = [];
        foreach ($emisores as $i => $e) {
            $id = (int)$e['id_emisor'];
            $ph = ':em_' . $i;
            $phEmisores[] = $ph;
            $paramsSimple[$ph] = $id;
            $mapEmisores[$id] = [
                'rfc' => (string)($e['rfc'] ?? ''),
                'nombre' => (string)($e['nombre'] ?? ''),
            ];
        }
        $inEmisores = implode(',', $phEmisores);

        // COUNT con el índice compuesto empresa + emisor + fecha.
        $tc0 = microtime(true);
        $sqlCountSimple = "SELECT COUNT(*)
                           FROM facturas FORCE INDEX (idx_facturas_empresa_emisor_fecha)
                           WHERE id_empresa=:id_simple
                             AND id_emisor IN ({$inEmisores})
                             AND fecha_emision>=:ini_simple
                             AND fecha_emision<:fin_simple";
        $stCountSimple = $pdo->prepare($sqlCountSimple);
        $stCountSimple->execute($paramsSimple);
        $totalSimple = (int)$stCountSimple->fetchColumn();
        $tc1 = microtime(true);

        // Página: sin JOIN; el nombre/RFC salen del mapa de emisores resuelto arriba.
        $ts0 = microtime(true);
        $sqlSimple = "SELECT
                uuid, id_emisor, id_receptor, id_tipo_comprobante, tipo_movimiento_empresa, estatus_sat, excluir_diot,
                version_cfdi, serie, folio, fecha_emision, forma_pago, metodo_pago, uso_cfdi, moneda,
                tc_xml_factura, total_xml, subtotal_xml, base_iva, iva_traslado, base_ret_iva, iva_retenido,
                base_ret_isr, isr_retenido, base_iva_fronteriza_xml, iva_fronterizo_xml,
                base_iva_frontera_norte, iva_frontera_norte, base_iva_frontera_sur, iva_frontera_sur,
                lugar_expedicion_xml, cp_region_fronteriza, region_diot_aplicada, requiere_revision_region,
                mensaje_revision_region, fecha_pago_sat, fecha_pago_banco, fecha_pago_usuario,
                fecha_ultimo_pago_contpaq, fecha_pago_origen, folio_pago_origen, fecha_aplicacion_fiscal,
                ya_pago, tc_xml_pago, tc_banco_real, referencia_xml, referencia_banco_real,
                esta_conciliado, saldo_pendiente, estatus_conciliacion_financiera,
                origen_pago_financiero, estatus_pago_origen, conciliado_banco_origen,
                fecha_conciliacion_banco_origen, fecha_valor_origen,
                es_gasolina, total_ieps_gasolina,
                nomina_tipo_nomina, nomina_fecha_pago, nomina_fecha_inicial_pago, nomina_fecha_final_pago,
                nomina_num_dias_pagados, nomina_periodicidad_pago, nomina_num_empleado,
                nomina_total_percepciones, nomina_total_deducciones, nomina_total_otros_pagos,
                COALESCE(NULLIF(efecto_fiscal_diot,''),'01') efecto_fiscal_diot,
                COALESCE(iva_tratamiento_especial_diot,0) iva_tratamiento_especial_diot,
                COALESCE(iva_porcentaje_acreditable_diot,100) iva_porcentaje_acreditable_diot,
                COALESCE(iva_motivo_tratamiento_diot,'') iva_motivo_tratamiento_diot
            FROM facturas FORCE INDEX (idx_facturas_empresa_emisor_fecha)
            WHERE id_empresa=:id_simple
              AND id_emisor IN ({$inEmisores})
              AND fecha_emision>=:ini_simple
              AND fecha_emision<:fin_simple
            ORDER BY fecha_emision ASC
            LIMIT {$lengthSimple} OFFSET {$startSimple}";
        $stSimple = $pdo->prepare($sqlSimple);
        $stSimple->execute($paramsSimple);
        $rowsSimple = $stSimple->fetchAll(PDO::FETCH_ASSOC);
        $ts1 = microtime(true);

        $tf0 = microtime(true);
        $dataSimple = hidratar_filas_rapidas($pdo, $idEmpresa, $rowsSimple);
        $tf1 = microtime(true);

        echo json_encode([
            'draw'=>$drawSimple,
            'recordsTotal'=>$totalSimple,
            'recordsFiltered'=>$totalSimple,
            'data'=>$dataSimple,
            'totals'=>[],
            'serverSide'=>true,
            'prueba_emisor_simple'=>true,
            'emisores_encontrados'=>count($emisores),
            'debug_ms'=>[
                'resolver'=>round(($tr1-$tr0)*1000,2),
                'count'=>round(($tc1-$tc0)*1000,2),
                'select'=>round(($ts1-$ts0)*1000,2),
                'formato'=>round(($tf1-$tf0)*1000,2),
                'total'=>round((microtime(true)-$t0)*1000,2)
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $modoMesSimple = ((int)($req['modo_mes_simple'] ?? 0) === 1);
    if ($modoMesSimple) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $t0 = microtime(true);
        $inicioSimple = normalizar_fecha_filtro($req['inicio'] ?? '');
        $finSimple = normalizar_fecha_filtro($req['fin'] ?? '');
        $drawSimple = max(0, (int)($req['draw'] ?? 0));
        $startSimple = max(0, (int)($req['start'] ?? 0));
        $lengthSimple = (int)($req['length'] ?? 15);
        if ($lengthSimple < 1) $lengthSimple = 15;
        if ($lengthSimple > 200) $lengthSimple = 200;

        if ($inicioSimple === '' || $finSimple === '') {
            throw new Exception('Rango de fechas inválido para la prueba simple.');
        }

        $finExclusivoSimple = fecha_fin_exclusiva($finSimple);
        $paramsSimple = [
            ':id_simple' => $idEmpresa,
            ':ini_simple' => $inicioSimple . ' 00:00:00',
            ':fin_simple' => $finExclusivoSimple,
        ];

        // Conteo únicamente para que DataTables conserve paginación real.
        $tc0 = microtime(true);
        $sqlCountSimple = "SELECT COUNT(*)
                           FROM facturas FORCE INDEX (idx_facturas_empresa_fecha)
                           WHERE id_empresa=:id_simple
                             AND fecha_emision>=:ini_simple
                             AND fecha_emision<:fin_simple";
        $stCountSimple = $pdo->prepare($sqlCountSimple);
        $stCountSimple->execute($paramsSimple);
        $totalSimple = (int)$stCountSimple->fetchColumn();
        $tc1 = microtime(true);

        // SOLO campos de facturas; cero JOIN y cero cálculos externos.
        $ts0 = microtime(true);
        $sqlSimple = "SELECT
                uuid, id_emisor, id_receptor, id_tipo_comprobante, tipo_movimiento_empresa, estatus_sat, excluir_diot,
                version_cfdi, serie, folio, fecha_emision, forma_pago, metodo_pago, uso_cfdi, moneda,
                tc_xml_factura, total_xml, subtotal_xml, base_iva, iva_traslado, base_ret_iva, iva_retenido,
                base_ret_isr, isr_retenido, base_iva_fronteriza_xml, iva_fronterizo_xml,
                base_iva_frontera_norte, iva_frontera_norte, base_iva_frontera_sur, iva_frontera_sur,
                lugar_expedicion_xml, cp_region_fronteriza, region_diot_aplicada, requiere_revision_region,
                mensaje_revision_region, fecha_pago_sat, fecha_pago_banco, fecha_pago_usuario,
                fecha_ultimo_pago_contpaq, fecha_pago_origen, folio_pago_origen, fecha_aplicacion_fiscal,
                ya_pago, tc_xml_pago, tc_banco_real, referencia_xml, referencia_banco_real,
                esta_conciliado, saldo_pendiente, estatus_conciliacion_financiera,
                origen_pago_financiero, estatus_pago_origen, conciliado_banco_origen,
                fecha_conciliacion_banco_origen, fecha_valor_origen,
                es_gasolina, total_ieps_gasolina,
                nomina_tipo_nomina, nomina_fecha_pago, nomina_fecha_inicial_pago, nomina_fecha_final_pago,
                nomina_num_dias_pagados, nomina_periodicidad_pago, nomina_num_empleado,
                nomina_total_percepciones, nomina_total_deducciones, nomina_total_otros_pagos,
                COALESCE(NULLIF(efecto_fiscal_diot,''),'01') efecto_fiscal_diot,
                COALESCE(iva_tratamiento_especial_diot,0) iva_tratamiento_especial_diot,
                COALESCE(iva_porcentaje_acreditable_diot,100) iva_porcentaje_acreditable_diot,
                COALESCE(iva_motivo_tratamiento_diot,'') iva_motivo_tratamiento_diot
            FROM facturas FORCE INDEX (idx_facturas_empresa_fecha)
            WHERE id_empresa=:id_simple
              AND fecha_emision>=:ini_simple
              AND fecha_emision<:fin_simple
            ORDER BY fecha_emision ASC
            LIMIT {$lengthSimple} OFFSET {$startSimple}";
        $stSimple = $pdo->prepare($sqlSimple);
        $stSimple->execute($paramsSimple);
        $rowsSimple = $stSimple->fetchAll(PDO::FETCH_ASSOC);
        $ts1 = microtime(true);

        $tf0 = microtime(true);
        $dataSimple = hidratar_filas_rapidas($pdo, $idEmpresa, $rowsSimple);
        $tf1 = microtime(true);

        echo json_encode([
            'draw'=>$drawSimple,
            'recordsTotal'=>$totalSimple,
            'recordsFiltered'=>$totalSimple,
            'data'=>$dataSimple,
            'totals'=>[],
            'serverSide'=>true,
            'prueba_mes_simple'=>true,
            'debug_ms'=>[
                'count'=>round(($tc1-$tc0)*1000,2),
                'select'=>round(($ts1-$ts0)*1000,2),
                'formato'=>round(($tf1-$tf0)*1000,2),
                'total'=>round((microtime(true)-$t0)*1000,2)
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $permitidos = obtener_permisos_documentos($pdo, $idUsuario, $idEmpresa);

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $inicio = normalizar_fecha_filtro($req['inicio'] ?? '');
    $fin = normalizar_fecha_filtro($req['fin'] ?? '');
    $tipo = strtoupper(trim((string)($req['tipo'] ?? '')));
    $metodo = strtoupper(trim((string)($req['metodo'] ?? '')));
    $ppdSinComplemento = ((int)($req['ppd_sin_complemento'] ?? 0) === 1);
    $pueSinPagoEmpresa = ((int)($req['pue_sin_pago_empresa'] ?? 0) === 1);
    $exportAll = ((int)($req['export_all'] ?? 0) === 1);
    $exportMode = strtolower(trim((string)($req['export_mode'] ?? 'completa')));
    if (!in_array($exportMode, ['completa','diot','chequeo_diot'], true)) $exportMode = 'completa';
    if ($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) {
        diot_sincronizar_pagos_usuario_empresa($pdo, $idEmpresa);
    }
    $exportAnio = (int)($req['export_anio'] ?? 0);
    $exportMes = (int)($req['export_mes'] ?? 0);

    $draw = max(0, (int)($req['draw'] ?? 0));
    $start = max(0, (int)($req['start'] ?? 0));
    $length = (int)($req['length'] ?? 12);
    if ($length < 1) $length = 12;
    if ($length > 200) $length = 200;

    $busqueda = trim((string)($req['busqueda'] ?? ''));
    $tipoBusqueda = strtolower(trim((string)($req['tipo_busqueda'] ?? '')));

    /* RUTA UUID REALMENTE EXCLUSIVA: termina aquí con exit. */
    if ($tipoBusqueda === 'uuid' && $busqueda !== '' && !$exportAll) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $uuidCompleto = (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $busqueda);
        $paramsUuid = [':id_uuid' => $idEmpresa];
        if ($uuidCompleto) {
            $whereUuid = ' WHERE f.id_empresa=:id_uuid AND f.uuid=:uuid_busqueda ';
            $paramsUuid[':uuid_busqueda'] = strtoupper($busqueda);
        } else {
            $whereUuid = " WHERE f.id_empresa=:id_uuid AND f.uuid LIKE :uuid_busqueda ";
            $paramsUuid[':uuid_busqueda'] = $busqueda . '%';
        }

        // SEGURIDAD: una búsqueda directa por UUID nunca puede saltarse los permisos
        // documentales de la empresa activa. Ejemplo: sin permiso N, una nómina no
        // aparece aunque se conozca exactamente su UUID.
        $whereUuid .= ' AND ' . sql_filtro_tipos_permitidos($permitidos, $paramsUuid, 'f');

        // recordsTotal también se limita a documentos visibles para no revelar ni
        // siquiera el conteo de CFDI de tipos que el usuario no puede consultar.
        $paramsTotalUuid = [':id_total_uuid'=>$idEmpresa];
        $filtroPermTotalUuid = sql_filtro_tipos_permitidos($permitidos, $paramsTotalUuid, 'f');
        $stTotalUuid = $pdo->prepare('SELECT COUNT(*) FROM facturas f WHERE f.id_empresa=:id_total_uuid AND ' . $filtroPermTotalUuid);
        $stTotalUuid->execute($paramsTotalUuid);
        $recordsTotalUuid = (int)$stTotalUuid->fetchColumn();

        $stCountUuid = $pdo->prepare('SELECT COUNT(*) FROM facturas f FORCE INDEX (idx_facturas_empresa_uuid) ' . $whereUuid);
        $stCountUuid->execute($paramsUuid);
        $recordsFilteredUuid = (int)$stCountUuid->fetchColumn();

        $joinsUuid = ' LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa'
                   . ' LEFT JOIN cat_receptores r ON r.id_receptor=f.id_receptor AND r.id_empresa=f.id_empresa ';

        $selectUuid = "SELECT
            f.id_tipo_comprobante tipo_sat, f.tipo_movimiento_empresa, f.uuid, f.version_cfdi, f.serie, f.folio,
            f.fecha_emision, e.rfc rfc_emisor, e.nombre emisor,
            f.forma_pago, f.metodo_pago, f.uso_cfdi, f.moneda,
            f.tc_xml_factura, f.total_xml total, f.subtotal_xml subtotal,
            f.base_iva, f.iva_traslado iva_t, f.base_ret_iva, f.iva_retenido iva_r,
            f.base_ret_isr, f.isr_retenido isr_r,
            f.base_iva_fronteriza_xml base_iva_8, f.iva_fronterizo_xml iva_8,
            f.base_iva_frontera_norte, f.iva_frontera_norte,
            f.base_iva_frontera_sur, f.iva_frontera_sur,
            f.lugar_expedicion_xml, f.cp_region_fronteriza, f.region_diot_aplicada,
            f.requiere_revision_region, f.mensaje_revision_region,
            r.rfc rfc_receptor, r.nombre receptor,
            f.fecha_pago_sat fecha_pago_sat_factura,
            f.fecha_pago_banco p_banco, f.fecha_pago_usuario p_usu,
            f.fecha_ultimo_pago_contpaq p_contpaq,
            f.fecha_pago_origen fecha_pago_empresa, f.folio_pago_origen referencias_pago_empresa,
            f.fecha_aplicacion_fiscal fecha_fiscal, f.ya_pago,
            f.tc_xml_pago, f.tc_banco_real, f.referencia_xml, f.referencia_banco_real,
            f.esta_conciliado, f.saldo_pendiente, f.estatus_sat, f.excluir_diot,
            f.origen_pago_financiero,f.folio_pago_origen,f.fecha_pago_origen,f.estatus_pago_origen,
            f.conciliado_banco_origen,f.fecha_conciliacion_banco_origen,f.fecha_valor_origen,f.estatus_conciliacion_financiera,
            f.es_gasolina, f.total_ieps_gasolina,
            f.nomina_tipo_nomina, f.nomina_fecha_pago, f.nomina_fecha_inicial_pago, f.nomina_fecha_final_pago,
            f.nomina_num_dias_pagados, f.nomina_periodicidad_pago, f.nomina_num_empleado,
            f.nomina_total_percepciones, f.nomina_total_deducciones, f.nomina_total_otros_pagos,
            COALESCE(NULLIF(f.efecto_fiscal_diot,''),'01') efecto_fiscal_diot,
            COALESCE(f.iva_tratamiento_especial_diot,0) iva_tratamiento_especial_diot,
            COALESCE(f.iva_porcentaje_acreditable_diot,100) iva_porcentaje_acreditable_diot,
            COALESCE(f.iva_motivo_tratamiento_diot,'') iva_motivo_tratamiento_diot
          FROM facturas f FORCE INDEX (idx_facturas_empresa_uuid) {$joinsUuid} {$whereUuid}
          ORDER BY f.fecha_emision ASC, f.uuid ASC
          LIMIT {$length} OFFSET {$start}";

        // DIAGNOSTICO TEMPORAL UUID: guardamos el SELECT real y una version
        // con valores literales para poder copiarla y probarla directamente en HeidiSQL.
        $sqlUuidDirecto = $selectUuid;
        $reemplazosDebug = $paramsUuid;
        // Reemplazar primero las claves mas largas para evitar coincidencias parciales.
        uksort($reemplazosDebug, static fn($a,$b) => strlen($b) <=> strlen($a));
        foreach ($reemplazosDebug as $k => $v) {
            $literal = is_numeric($v) ? (string)$v : $pdo->quote((string)$v);
            $sqlUuidDirecto = str_replace($k, $literal, $sqlUuidDirecto);
        }
        $sqlFiltroUuidDirecto = $uuidCompleto
            ? "SELECT uuid,id_empresa,id_tipo_comprobante,tipo_movimiento_empresa,fecha_emision,estatus_sat FROM facturas FORCE INDEX (idx_facturas_empresa_uuid) WHERE id_empresa=".$idEmpresa." AND uuid=".$pdo->quote(strtoupper($busqueda)).";"
            : "SELECT uuid,id_empresa,id_tipo_comprobante,tipo_movimiento_empresa,fecha_emision,estatus_sat FROM facturas FORCE INDEX (idx_facturas_empresa_uuid) WHERE id_empresa=".$idEmpresa." AND uuid LIKE ".$pdo->quote($busqueda.'%')." ORDER BY uuid;";

        $stUuid = $pdo->prepare($selectUuid);
        $stUuid->execute($paramsUuid);
        $rowsUuid = $stUuid->fetchAll(PDO::FETCH_ASSOC);

        $pagosPorUuid = [];
        $uuidsPagina = array_values(array_filter(array_map(static fn($x)=>(string)($x['uuid'] ?? ''), $rowsUuid)));
        if ($uuidsPagina) {
            $ph=[]; $pp=[':emp_pago_uuid'=>$idEmpresa];
            foreach($uuidsPagina as $i=>$u){ $k=':up'.$i; $ph[]=$k; $pp[$k]=$u; }
            $sp=$pdo->prepare("SELECT uuid_relacionado, COUNT(*) numero_pagos, MAX(fecha_pago_sat) fecha_pago_sat
                               FROM facturas_pagos_detalles
                               WHERE id_empresa=:emp_pago_uuid AND uuid_relacionado IN (".implode(',', $ph).")
                               GROUP BY uuid_relacionado");
            $sp->execute($pp);
            while($x=$sp->fetch(PDO::FETCH_ASSOC)) $pagosPorUuid[(string)$x['uuid_relacionado']]=$x;
        }

        $dataUuid=[];
        foreach($rowsUuid as $r) $dataUuid[]=formatear_fila_factura($r, $pagosPorUuid);

        echo json_encode([
            'draw'=>$draw,
            'recordsTotal'=>$recordsTotalUuid,
            'recordsFiltered'=>$recordsFilteredUuid,
            'data'=>array_values($dataUuid),
            'totals'=>[],
            'serverSide'=>true,
            'ruta_uuid_exclusiva'=>true,
            'debug_uuid'=>[
                'mensaje'=>'ENTRO A RUTA EXCLUSIVA UUID',
                'tipo_busqueda_recibido'=>$tipoBusqueda,
                'busqueda_recibida'=>$busqueda,
                'id_empresa'=>$idEmpresa,
                'records_filtered'=>$recordsFilteredUuid,
                'sql_filtro_directo'=>$sqlFiltroUuidDirecto,
                'sql_select_usado'=>$sqlUuidDirecto
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // RUTA EXCLUSIVA UUID:
    // El UUID sigue sin mezclarse con rango de fechas, método, clasificación ni PPD/PUE
    // para conservar la búsqueda directa; PERO los permisos documentales SIEMPRE se
    // aplican. Conocer un UUID nunca concede acceso a un tipo de CFDI no autorizado.
    $busquedaUuidDirecta = ($tipoBusqueda === 'uuid' && $busqueda !== '');

    $where = ' WHERE f.id_empresa=:id ';
    $paramsBase = [':id'=>$idEmpresa];

    // Para clasificar RECIBIDO / EMITIDO usamos el RFC REAL de la empresa activa.
    // No dependemos de facturas.tipo_movimiento_empresa porque en históricos puede
    // estar desactualizado y ocultar CFDI válidos en los filtros del visor.
    $stEmpresaTipo = $pdo->prepare('SELECT UPPER(TRIM(rfc)) FROM empresas WHERE id_empresa=? LIMIT 1');
    $stEmpresaTipo->execute([$idEmpresa]);
    $rfcEmpresaTipo = strtoupper(trim((string)($stEmpresaTipo->fetchColumn() ?: '')));

    $idsEmisorEmpresa = [];
    $idsReceptorEmpresa = [];
    if ($rfcEmpresaTipo !== '') {
        $stIdsEmisor = $pdo->prepare('SELECT id_emisor FROM cat_emisores WHERE id_empresa=? AND UPPER(TRIM(rfc))=?');
        $stIdsEmisor->execute([$idEmpresa, $rfcEmpresaTipo]);
        $idsEmisorEmpresa = array_values(array_unique(array_map('intval', $stIdsEmisor->fetchAll(PDO::FETCH_COLUMN))));

        $stIdsReceptor = $pdo->prepare('SELECT id_receptor FROM cat_receptores WHERE id_empresa=? AND UPPER(TRIM(rfc))=?');
        $stIdsReceptor->execute([$idEmpresa, $rfcEmpresaTipo]);
        $idsReceptorEmpresa = array_values(array_unique(array_map('intval', $stIdsReceptor->fetchAll(PDO::FETCH_COLUMN))));
    }

    // Clasificación respecto de la empresa activa:
    // - EMITIDO  = RFC EMISOR   == RFC de la empresa activa.
    // - RECIBIDO = RFC RECEPTOR == RFC de la empresa activa.
    // PROVEEDORES se determina por CFDI SAT tipo I + receptor = empresa activa.
    // La dirección RECIBIDO/EMITIDO se valida por RFC real de la empresa.
    $agregarFiltroParteEmpresa = static function(string &$sqlWhere, array &$params, string $direccion, string $prefijo) use ($idsEmisorEmpresa, $idsReceptorEmpresa): void {
        $direccion = strtoupper($direccion);
        $idsParte = ($direccion === 'EMITIDO') ? $idsEmisorEmpresa : $idsReceptorEmpresa;
        $campo = ($direccion === 'EMITIDO') ? 'f.id_emisor' : 'f.id_receptor';

        if (!$idsParte) {
            $sqlWhere .= ' AND 1=0';
            return;
        }

        $ph = [];
        foreach ($idsParte as $i => $idParte) {
            $k = ':' . $prefijo . '_' . $i;
            $ph[] = $k;
            $params[$k] = $idParte;
        }

        $sqlWhere .= ' AND ' . $campo . ' IN (' . implode(',', $ph) . ')';
    };

    // Modos especiales de exportación CSV:
    // completa      -> respeta todos los filtros actuales del visor.
    // diot          -> EGRESOS usando FECHA FISCAL (fecha_aplicacion_fiscal) del mes/año indicado.
    //                  Aprovecha índice: empresa + tipo_movimiento + fecha_aplicacion_fiscal.
    // chequeo_diot  -> TODOS los EGRESOS EMITIDOS en el mes/año indicado, sin importar
    //                  si la fecha fiscal quedó dentro, fuera o vacía.
    //                  Aprovecha índice: empresa + tipo_movimiento + fecha_emision.
    $periodoInicio = '';
    $periodoFinExclusivo = '';

    if ($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) {
        if (!in_array('E', $permitidos, true)) {
            $where .= ' AND 1=0';
        }
        if ($exportAnio < 2000 || $exportAnio > 2100 || $exportMes < 1 || $exportMes > 12) {
            throw new Exception('Mes/año inválido para exportación DIOT.');
        }

        $periodoInicio = sprintf('%04d-%02d-01', $exportAnio, $exportMes);
        $dPeriodoFin = DateTime::createFromFormat('Y-m-d', $periodoInicio);
        if (!$dPeriodoFin) throw new Exception('Periodo DIOT inválido.');
        $dPeriodoFin->modify('first day of next month');
        $periodoFinExclusivo = $dPeriodoFin->format('Y-m-d');

        // DIOT / chequeo DIOT: documento RECIBIDO = RFC receptor igual al RFC de la empresa.
        $agregarFiltroParteEmpresa($where, $paramsBase, 'RECIBIDO', 'diot_receptor_empresa');
        $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,'')) NOT IN ('P','N','T')";

        if ($exportMode === 'diot') {
            // DIOT real: los CFDI marcados por el contador como NO DIOT no deben
            // aparecer ni sumar en el detalle exportado. El modo chequeo_diot
            // permanece como auditoría amplia para poder localizarlos.
            $where .= ' AND COALESCE(f.excluir_diot,0)=0';
            // Ruta 1: DIOT real por fecha fiscal.
            $where .= ' AND f.fecha_aplicacion_fiscal >= :periodo_inicio';
            $where .= ' AND f.fecha_aplicacion_fiscal < :periodo_fin';
        } else {
            // Ruta 2: auditoría por fecha de emisión, aunque la fecha fiscal esté desfasada.
            $where .= ' AND f.fecha_emision >= :periodo_inicio';
            $where .= ' AND f.fecha_emision < :periodo_fin';
        }

        $paramsBase[':periodo_inicio'] = $periodoInicio . ' 00:00:00';
        $paramsBase[':periodo_fin'] = $periodoFinExclusivo . ' 00:00:00';
    } else {
        // Vista normal: el rango se usa por fecha de emisión, EXCEPTO cuando se busca por UUID.
        // El UUID identifica de forma única al CFDI, por lo que obligarlo a caer dentro del rango
        // de fechas sólo provoca falsos "sin resultados" (por ejemplo, buscar en septiembre un
        // CFDI emitido en abril). En ese caso la consulta va directa al índice/PK de UUID.
        if (!$busquedaUuidDirecta) {
            if ($inicio !== '') {
                $where .= ' AND f.fecha_emision >= :inicio_dt';
                $paramsBase[':inicio_dt'] = $inicio . ' 00:00:00';
            }
            if ($fin !== '') {
                $where .= ' AND f.fecha_emision < :fin_dt';
                $paramsBase[':fin_dt'] = fecha_fin_exclusiva($fin);
            }
        }
    }

    if ($ppdSinComplemento && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        // PPD SIN COMPLEMENTO significa: CFDI PPD del rango que todavía no tiene
        // ningún detalle fiscal proveniente de REP/complemento de pago.
        // NO se exige que ya exista pago CONTPAQ/Oracle/usuario: también deben
        // mostrarse los PPD con cero pagos, porque precisamente siguen pendientes.
        $where .= " AND f.metodo_pago='PPD'
                    AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')
                    AND NOT EXISTS (
                        SELECT 1
                        FROM facturas_pagos_detalles pdx
                        WHERE pdx.id_empresa=f.id_empresa
                          AND pdx.uuid_relacionado=f.uuid
                    )";
    }

    if ($pueSinPagoEmpresa && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        // PUE SIN PAGO EMPRESA:
        //   1) solo movimientos EGRESO de la empresa activa;
        //   2) método PUE;
        //   3) Fecha pago Empresa (fecha_pago_origen) vacía;
        //   4) excluye CFDI especiales P/N/T;
        //   5) el emisor NO puede ser el RFC de la empresa activa.
        //
        // La condición principal queda alineada con el índice específico:
        // id_empresa + tipo_movimiento_empresa + metodo_pago + fecha_pago_origen + fecha_emision.
        if (!in_array('E', $permitidos, true)) {
            $where .= ' AND 1=0';
        } else {
            $where .= " AND f.tipo_movimiento_empresa='E'
                        AND f.metodo_pago='PUE'
                        AND f.fecha_pago_origen IS NULL
                        AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')";

            // Resolver una sola vez el RFC/id_emisor propio; así evitamos hacer JOIN/funciones
            // sobre cada fila del listado y no sacrificamos el índice principal de facturas.
            $stEmpresaPue = $pdo->prepare('SELECT rfc FROM empresas WHERE id_empresa=? LIMIT 1');
            $stEmpresaPue->execute([$idEmpresa]);
            $rfcEmpresaPue = strtoupper(trim((string)$stEmpresaPue->fetchColumn()));

            if ($rfcEmpresaPue !== '') {
                $stEmisorPropio = $pdo->prepare('SELECT id_emisor FROM cat_emisores WHERE id_empresa=? AND rfc=? LIMIT 1');
                $stEmisorPropio->execute([$idEmpresa, $rfcEmpresaPue]);
                $idEmisorPropio = (int)($stEmisorPropio->fetchColumn() ?: 0);

                if ($idEmisorPropio > 0) {
                    $where .= ' AND f.id_emisor<>:pue_id_emisor_empresa';
                    $paramsBase[':pue_id_emisor_empresa'] = $idEmisorPropio;
                }

                // Respaldo para históricos donde el catálogo/id pudiera no coincidir.
                // Si rfc_proveedor_xml está informado, también debe ser distinto al RFC propio.
                $where .= " AND (f.rfc_proveedor_xml IS NULL
                                  OR f.rfc_proveedor_xml=''
                                  OR UPPER(TRIM(f.rfc_proveedor_xml))<>:pue_rfc_empresa)";
                $paramsBase[':pue_rfc_empresa'] = $rfcEmpresaPue;
            }
        }
    } elseif (in_array($metodo, ['PUE','PPD'], true) && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        $where .= ' AND f.metodo_pago=:metodo';
        $paramsBase[':metodo'] = $metodo;
    }

    if (!($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) && $tipo !== '') {
        // IMPORTANTE: la columna Tipo que ve el usuario (I/E) corresponde a
        // facturas.tipo_movimiento_empresa, no a id_tipo_comprobante del SAT.
        // Por eso el filtro debe usar el MISMO campo que se presenta en pantalla:
        // INGRESO = tipo_movimiento_empresa='I'
        // EGRESO  = tipo_movimiento_empresa='E'
        // y después validar si la empresa participa como emisor o receptor.
        $clasificacionesVisor = [
            // La clasificación combina el TipoDeComprobante SAT (I/E)
            // con la dirección respecto de la empresa activa (RECIBIDO/EMITIDO).
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
                $where .= ' AND 1=0';
            } else {
                // PROVEEDORES (antes INGRESOS RECIBIDOS): CFDI SAT tipo I
                // cuyo RFC receptor es exactamente el RFC de la empresa activa.
                // No dependemos de tipo_movimiento_empresa para evitar históricos mal clasificados.
                if (in_array($tipo, ['INGRESO_RECIBIDO','IR'], true)) {
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='I'";
                } elseif (in_array($tipo, ['INGRESO_EMITIDO','IE'], true)) {
                    // INGRESOS EMITIDOS: CFDI SAT tipo I cuyo RFC emisor es la empresa activa.
                    // La dirección EMITIDO se valida abajo con el emisor propio de la empresa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='I'";
                } elseif ($tipo === 'PAGO_PROVEEDORES') {
                    // Complementos tipo P cuyo emisor NO es la empresa activa.
                    // Según la clasificación funcional del visor, éstos son PAGOS PROVEEDORES.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='P'";
                } elseif ($tipo === 'PAGO_EMITIDOS') {
                    // Complementos tipo P cuyo emisor ES la empresa activa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='P'";
                } elseif ($tipo === 'TRASLADO_PROVEEDORES') {
                    // CFDI tipo T cuyo emisor NO es la empresa activa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='T'";
                } elseif ($tipo === 'TRASLADO_EMITIDOS') {
                    // CFDI tipo T cuyo emisor ES la empresa activa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='T'";
                } elseif (in_array($tipo, ['EGRESO_RECIBIDO','ER'], true)) {
                    // EGRESOS RECIBIDOS: notas de crédito de proveedor.
                    // CFDI SAT tipo E cuyo RFC receptor es la empresa activa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='E'";
                } elseif (in_array($tipo, ['EGRESO_EMITIDO','EE'], true)) {
                    // EGRESOS EMITIDOS: notas de crédito generadas por la empresa.
                    // CFDI SAT tipo E cuyo RFC emisor es la empresa activa.
                    $where .= " AND UPPER(COALESCE(f.id_tipo_comprobante,''))='E'";
                } else {
                    $where .= " AND f.tipo_movimiento_empresa=:tipo_mov_clasificacion";
                    $paramsBase[':tipo_mov_clasificacion'] = $clasificacion['mov'];
                }

                if (in_array($tipo, ['PAGO_PROVEEDORES','TRASLADO_PROVEEDORES'], true)) {
                    // Proveedores: el emisor del CFDI es distinto a la empresa activa.
                    // IMPORTANTE: aquí NO usamos aliases e/emp porque los COUNT de DataTables
                    // se ejecutan directamente sobre facturas sin esos JOIN. Usamos los IDs
                    // del emisor propio ya resueltos arriba para que la misma condición funcione
                    // tanto en COUNT como en la consulta del listado y además aproveche índice.
                    if ($idsEmisorEmpresa) {
                        $phNoEmisor = [];
                        foreach ($idsEmisorEmpresa as $iNoEmisor => $idNoEmisor) {
                            $kNoEmisor = ':clas_no_emisor_' . strtolower($tipo) . '_' . $iNoEmisor;
                            $phNoEmisor[] = $kNoEmisor;
                            $paramsBase[$kNoEmisor] = $idNoEmisor;
                        }
                        $where .= ' AND f.id_emisor NOT IN (' . implode(',', $phNoEmisor) . ')';
                    }
                    // Si no existe ID de emisor propio en el catálogo, ningún registro puede
                    // comprobarse como emitido por la empresa mediante ese catálogo; se dejan
                    // los tipo P/T como proveedores en vez de provocar un falso 0.
                } else {
                    $agregarFiltroParteEmpresa($where, $paramsBase, $clasificacion['direccion'], 'clas_' . strtolower($tipo));
                }
            }
        } elseif (!in_array($tipo, $permitidos, true)) {
            $where .= ' AND 1=0';
        } elseif (in_array($tipo, ['I','E'], true)) {
            // Compatibilidad con llamadas anteriores del visor y procesos auxiliares.
            $where .= " AND f.tipo_movimiento_empresa=:tipo AND COALESCE(f.id_tipo_comprobante,'') NOT IN ('P','N','T')";
            $paramsBase[':tipo'] = $tipo;
        } elseif (in_array($tipo, ['P','N','T'], true)) {
            $where .= ' AND f.id_tipo_comprobante=:tipo';
            $paramsBase[':tipo'] = $tipo;
        }
    } elseif (!($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        $faltantes = array_diff(tipos_documento_todos(), $permitidos);
        if (!empty($faltantes)) {
            $where .= ' AND ' . sql_filtro_tipos_permitidos($permitidos, $paramsBase, 'f');
        }
    }

    // La búsqueda dirigida se resuelve ANTES de consultar facturas.
    // Si buscador/tipo están vacíos, whereFiltrado queda igual a $where y MySQL
    // puede tomar el índice normal de empresa + fecha.
    // Cada modo importante debe conservar su propia ruta SQL. UUID es una ruta
    // totalmente independiente: empresa + UUID y nada más. No reutilizamos $where,
    // porque $where puede contener filtros normales construidos arriba.
    if ($busquedaUuidDirecta && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        $paramsFiltrados = [':id' => $idEmpresa];
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $busqueda)) {
            $whereFiltrado = ' WHERE f.id_empresa=:id AND f.uuid=:buscar_uuid_exacta ';
            $paramsFiltrados[':buscar_uuid_exacta'] = strtoupper($busqueda);
        } else {
            $whereFiltrado = ' WHERE f.id_empresa=:id AND f.uuid LIKE :buscar_uuid ';
            $paramsFiltrados[':buscar_uuid'] = $busqueda . '%';
        }
        // Defensa adicional para rutas de exportación/uso auxiliar que lleguen aquí.
        $whereFiltrado .= ' AND ' . sql_filtro_tipos_permitidos($permitidos, $paramsFiltrados, 'f');
    } else {
        $paramsFiltrados = $paramsBase;
        $aplicarBusquedaDirigida = !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true));
        $whereFiltrado = $where . ($aplicarBusquedaDirigida
            ? resolver_busqueda_dirigida($pdo, $idEmpresa, $tipoBusqueda, $busqueda, $paramsFiltrados)
            : '');
    }

    // Buscar por Folio tiene un índice con Folio antes de Fecha. El índice anterior
    // (id_empresa, fecha_emision, folio) es bueno para recorrer periodos, pero no para
    // arrancar una búsqueda por folio. Lo forzamos solo si ya fue instalado; así el
    // PHP sigue funcionando aunque el parche de BD todavía no se haya ejecutado.
    $forceIndexBusqueda = '';
    if ($tipoBusqueda === 'folio' && $busqueda !== '' && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        try {
            $stIdxFolio = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='facturas' AND INDEX_NAME='idx_visor_empresa_folio_fecha'");
            if ((int)$stIdxFolio->fetchColumn() > 0) {
                $forceIndexBusqueda = ' FORCE INDEX (idx_visor_empresa_folio_fecha) ';
            }
        } catch (Throwable $e) {
            // Si no se puede consultar metadata, dejamos decidir al optimizador.
            $forceIndexBusqueda = '';
        }
    }

    // Totales bajo demanda: NO se calculan durante la carga normal del visor.
    // Solo se ejecutan cuando el usuario pulsa el botón "Totales".
    $soloTotales = ((int)($req['solo_totales'] ?? 0) === 1);
    if ($soloTotales) {
        $tTot0 = microtime(true);
        $factorFacturaMxn = "(CASE
            WHEN UPPER(TRIM(COALESCE(f.moneda,'MXN'))) IN ('MXN','XXX','') THEN 1
            ELSE COALESCE(NULLIF(f.tc_xml_factura,0),1)
        END)";

        $sqlTotales = "SELECT
            COUNT(*) registros,
            COALESCE(SUM(f.total_xml * {$factorFacturaMxn}),0) total,
            COALESCE(SUM(f.subtotal_xml * {$factorFacturaMxn}),0) subtotal,
            COALESCE(SUM(f.base_iva * {$factorFacturaMxn}),0) base_iva,
            COALESCE(SUM(f.iva_traslado * {$factorFacturaMxn}),0) iva_t,
            COALESCE(SUM(f.base_ret_iva * {$factorFacturaMxn}),0) base_iva_r,
            COALESCE(SUM(f.iva_retenido * {$factorFacturaMxn}),0) iva_r,
            COALESCE(SUM(f.base_ret_isr * {$factorFacturaMxn}),0) base_isr_r,
            COALESCE(SUM(f.isr_retenido * {$factorFacturaMxn}),0) isr_r,
            COALESCE(SUM(f.base_iva_fronteriza_xml * {$factorFacturaMxn}),0) base_iva_8,
            COALESCE(SUM(f.iva_fronterizo_xml * {$factorFacturaMxn}),0) iva_8,
            COALESCE(SUM(f.base_iva_frontera_norte * {$factorFacturaMxn}),0) base_norte,
            COALESCE(SUM(f.iva_frontera_norte * {$factorFacturaMxn}),0) iva_norte,
            COALESCE(SUM(f.base_iva_frontera_sur * {$factorFacturaMxn}),0) base_sur,
            COALESCE(SUM(f.iva_frontera_sur * {$factorFacturaMxn}),0) iva_sur,
            COALESCE(SUM(f.saldo_pendiente * {$factorFacturaMxn}),0) saldo
          FROM facturas f {$forceIndexBusqueda} {$whereFiltrado}";

        $stTotales = $pdo->prepare($sqlTotales);
        $stTotales->execute($paramsFiltrados);
        $totales = $stTotales->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'ok' => true,
            'totals' => $totales,
            'tiempo_ms' => round((microtime(true)-$tTot0)*1000, 2)
        ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Estos JOIN son sólo para presentar RFC/nombre en la salida.
    // Ya no se usan para decidir qué factura entra en el filtro.
    $joinsPresentacion = ' LEFT JOIN cat_emisores e ON e.id_emisor=f.id_emisor AND e.id_empresa=f.id_empresa'
                       . ' LEFT JOIN cat_receptores r ON r.id_receptor=f.id_receptor AND r.id_empresa=f.id_empresa ';

    // Conteo total con filtros externos (empresa/fecha/tipo/metodo), sin la caja Buscar.
    // En la ruta UUID, recordsTotal sigue mostrando el total de la empresa activa,
    // pero SIN ejecutar los filtros normales (fecha/método/tipo/etc.).
    if ($busquedaUuidDirecta && !($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true))) {
        $stTotal = $pdo->prepare('SELECT COUNT(*) FROM facturas f WHERE f.id_empresa=:id');
        $stTotal->execute([':id' => $idEmpresa]);
    } else {
        $stTotal = $pdo->prepare('SELECT COUNT(*) FROM facturas f ' . $where);
        $stTotal->execute($paramsBase);
    }
    $recordsTotal = (int)$stTotal->fetchColumn();

    // Conteo después de la búsqueda global.
    if ($busqueda === '') {
        $recordsFiltered = $recordsTotal;
    } else {
        $stFil = $pdo->prepare('SELECT COUNT(*) FROM facturas f ' . $forceIndexBusqueda . $whereFiltrado);
        $stFil->execute($paramsFiltrados);
        $recordsFiltered = (int)$stFil->fetchColumn();
    }

    // ================================================================
    // PRUEBA DE RENDIMIENTO 2026-09-07
    // Totales del filtro completo DESACTIVADOS TEMPORALMENTE.
    // La consulta de SUM() sobre todo el rango se comenta para comprobar
    // si es la causa del Ajax error cuando se consultan periodos grandes.
    // Para reactivar los totales, restaurar el bloque original de SUM().
    // ================================================================
    $totales = [
        'total'      => 0,
        'subtotal'   => 0,
        'base_iva'   => 0,
        'iva_t'      => 0,
        'base_iva_r' => 0,
        'iva_r'      => 0,
        'base_isr_r' => 0,
        'isr_r'      => 0,
        'base_iva_8' => 0,
        'iva_8'      => 0,
        'base_norte' => 0,
        'iva_norte'  => 0,
        'base_sur'   => 0,
        'iva_sur'    => 0,
        'saldo'      => 0
    ];

    /*
    // BLOQUE ORIGINAL DE TOTALES - REACTIVAR DESPUES DE LA PRUEBA
    $factorFacturaMxn = "(CASE
        WHEN UPPER(TRIM(COALESCE(f.moneda,'MXN'))) IN ('MXN','XXX','') THEN 1
        ELSE COALESCE(NULLIF(f.tc_xml_factura,0),1)
    END)";
    $sqlTotales = "SELECT
        COALESCE(SUM(f.total_xml * {$factorFacturaMxn}),0) total,
        COALESCE(SUM(f.subtotal_xml * {$factorFacturaMxn}),0) subtotal,
        COALESCE(SUM(f.base_iva * {$factorFacturaMxn}),0) base_iva,
        COALESCE(SUM(f.iva_traslado * {$factorFacturaMxn}),0) iva_t,
        COALESCE(SUM(f.base_ret_iva * {$factorFacturaMxn}),0) base_iva_r,
        COALESCE(SUM(f.iva_retenido * {$factorFacturaMxn}),0) iva_r,
        COALESCE(SUM(f.base_ret_isr * {$factorFacturaMxn}),0) base_isr_r,
        COALESCE(SUM(f.isr_retenido * {$factorFacturaMxn}),0) isr_r,
        COALESCE(SUM(f.base_iva_fronteriza_xml * {$factorFacturaMxn}),0) base_iva_8,
        COALESCE(SUM(f.iva_fronterizo_xml * {$factorFacturaMxn}),0) iva_8,
        COALESCE(SUM(f.base_iva_frontera_norte * {$factorFacturaMxn}),0) base_norte,
        COALESCE(SUM(f.iva_frontera_norte * {$factorFacturaMxn}),0) iva_norte,
        COALESCE(SUM(f.base_iva_frontera_sur * {$factorFacturaMxn}),0) base_sur,
        COALESCE(SUM(f.iva_frontera_sur * {$factorFacturaMxn}),0) iva_sur,
        COALESCE(SUM(f.saldo_pendiente * {$factorFacturaMxn}),0) saldo
      FROM facturas f {$whereFiltrado}";
    $stTotales = $pdo->prepare($sqlTotales);
    $stTotales->execute($paramsFiltrados);
    $totales = $stTotales->fetch(PDO::FETCH_ASSOC) ?: [];
    */

    $select = "SELECT
        f.id_tipo_comprobante tipo_sat, f.tipo_movimiento_empresa, f.uuid, f.version_cfdi, f.serie, f.folio,
        f.fecha_emision, e.rfc rfc_emisor, e.nombre emisor,
        f.forma_pago, f.metodo_pago, f.uso_cfdi, f.moneda,
        f.tc_xml_factura, f.total_xml total, f.subtotal_xml subtotal,
        f.base_iva, f.iva_traslado iva_t, f.base_ret_iva, f.iva_retenido iva_r,
        f.base_ret_isr, f.isr_retenido isr_r,
        f.base_iva_fronteriza_xml base_iva_8, f.iva_fronterizo_xml iva_8,
        f.base_iva_frontera_norte, f.iva_frontera_norte,
        f.base_iva_frontera_sur, f.iva_frontera_sur,
        f.lugar_expedicion_xml, f.cp_region_fronteriza, f.region_diot_aplicada,
        f.requiere_revision_region, f.mensaje_revision_region,
        r.rfc rfc_receptor, r.nombre receptor,
        f.fecha_pago_sat fecha_pago_sat_factura,
        f.fecha_pago_banco p_banco, f.fecha_pago_usuario p_usu,
        f.fecha_ultimo_pago_contpaq p_contpaq,
        f.fecha_pago_origen fecha_pago_empresa, f.folio_pago_origen referencias_pago_empresa,
        f.fecha_aplicacion_fiscal fecha_fiscal, f.ya_pago,
        f.tc_xml_pago, f.tc_banco_real, f.referencia_xml, f.referencia_banco_real,
        f.esta_conciliado, f.saldo_pendiente, f.estatus_sat, f.excluir_diot,
        f.origen_pago_financiero,f.folio_pago_origen,f.fecha_pago_origen,f.estatus_pago_origen,
        f.conciliado_banco_origen,f.fecha_conciliacion_banco_origen,f.fecha_valor_origen,f.estatus_conciliacion_financiera,
        f.es_gasolina, f.total_ieps_gasolina,
        f.nomina_tipo_nomina, f.nomina_fecha_pago, f.nomina_fecha_inicial_pago, f.nomina_fecha_final_pago,
        f.nomina_num_dias_pagados, f.nomina_periodicidad_pago, f.nomina_num_empleado,
        f.nomina_total_percepciones, f.nomina_total_deducciones, f.nomina_total_otros_pagos,
        COALESCE(NULLIF(f.efecto_fiscal_diot,''),'01') efecto_fiscal_diot,
        COALESCE(f.iva_tratamiento_especial_diot,0) iva_tratamiento_especial_diot,
        COALESCE(f.iva_porcentaje_acreditable_diot,100) iva_porcentaje_acreditable_diot,
        COALESCE(f.iva_motivo_tratamiento_diot,'') iva_motivo_tratamiento_diot
      FROM facturas f {$forceIndexBusqueda} {$joinsPresentacion} {$whereFiltrado}";

    $orderMap = [
        0=>'f.tipo_movimiento_empresa', 1=>'f.estatus_sat', 2=>'f.uuid', 3=>'e.nombre', 4=>'f.version_cfdi', 5=>'f.serie', 6=>'f.folio',
        7=>'f.fecha_emision', 8=>'e.rfc', 9=>'f.forma_pago', 10=>'f.metodo_pago',
        12=>'f.fecha_pago_sat', 13=>'f.fecha_pago_origen', 14=>'f.fecha_pago_usuario', 15=>'f.fecha_aplicacion_fiscal',
        16=>'f.uso_cfdi', 17=>'f.moneda', 18=>'f.tc_xml_factura', 19=>'f.total_xml', 21=>'f.subtotal_xml',
        22=>'f.base_iva', 23=>'f.iva_traslado', 24=>'f.base_ret_iva', 25=>'f.iva_retenido', 26=>'f.base_ret_isr',
        27=>'f.isr_retenido', 28=>'f.base_iva_fronteriza_xml', 29=>'f.iva_fronterizo_xml', 30=>'f.base_iva_frontera_norte',
        31=>'f.iva_frontera_norte', 32=>'f.base_iva_frontera_sur', 33=>'f.iva_frontera_sur', 34=>'f.lugar_expedicion_xml',
        35=>'f.cp_region_fronteriza', 36=>'f.region_diot_aplicada', 38=>'r.rfc', 39=>'r.nombre', 40=>'f.ya_pago',
        41=>'f.tc_xml_pago', 42=>'f.tc_banco_real', 43=>'f.referencia_xml', 44=>'f.referencia_banco_real',
        45=>'f.esta_conciliado', 46=>'f.saldo_pendiente',
        47=>'f.nomina_tipo_nomina', 48=>'f.nomina_fecha_pago', 49=>'f.nomina_fecha_inicial_pago', 50=>'f.nomina_fecha_final_pago',
        51=>'f.nomina_num_dias_pagados', 52=>'f.nomina_periodicidad_pago', 53=>'f.nomina_num_empleado',
        54=>'f.nomina_total_percepciones', 55=>'f.nomina_total_deducciones', 56=>'f.nomina_total_otros_pagos'
    ];    $orders = [];
    if (!$exportAll && !empty($req['order']) && is_array($req['order'])) {
        foreach (array_slice($req['order'], 0, 2) as $ord) {
            $idx = (int)($ord['column'] ?? -1);
            if (!isset($orderMap[$idx])) continue;
            $dir = strtolower((string)($ord['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $orders[] = $orderMap[$idx] . ' ' . $dir;
        }
    }
    if (!$orders) $orders = ['f.fecha_emision ASC', 'f.folio ASC'];
    $select .= ' ORDER BY ' . implode(', ', $orders);

    if (!$exportAll) {
        $select .= ' LIMIT ' . $length . ' OFFSET ' . $start;
    }

    $st = $pdo->prepare($select);
    $st->execute($paramsFiltrados);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Los pagos se consultan SOLO para los UUID de la página actual (o del export explícito).
    $pagosPorUuid = [];
    $uuids = array_values(array_filter(array_map(static fn($x)=>(string)($x['uuid'] ?? ''), $rows)));
    if ($uuids) {
        // Para export_all puede haber miles: procesar por bloques evita exceder placeholders.
        foreach (array_chunk($uuids, 500) as $chunk) {
            $ph = [];
            $pp = [':emp_pago'=>$idEmpresa];
            foreach ($chunk as $i => $u) {
                $k = ':u' . $i;
                $ph[] = $k;
                $pp[$k] = $u;
            }
            $sp = $pdo->prepare("SELECT uuid_relacionado, COUNT(*) numero_pagos, MAX(fecha_pago_sat) fecha_pago_sat
                                 FROM facturas_pagos_detalles
                                 WHERE id_empresa=:emp_pago AND uuid_relacionado IN (" . implode(',', $ph) . ")
                                 GROUP BY uuid_relacionado");
            $sp->execute($pp);
            while ($p = $sp->fetch(PDO::FETCH_ASSOC)) {
                $pagosPorUuid[(string)$p['uuid_relacionado']] = $p;
            }
        }
    }

    $data = [];
    foreach ($rows as $r) {
        $fila = formatear_fila_factura($r, $pagosPorUuid);
        $fila['estatus_periodo_diot'] = '';

        if ($exportAll && in_array($exportMode, ['diot','chequeo_diot'], true)) {
            $fechaFiscalRaw = trim((string)($r['fecha_fiscal'] ?? ''));
            if ($fechaFiscalRaw === '' || $fechaFiscalRaw === '0000-00-00' || $fechaFiscalRaw === '0000-00-00 00:00:00') {
                $fila['estatus_periodo_diot'] = 'SIN FECHA FISCAL';
            } else {
                $tsFiscal = strtotime($fechaFiscalRaw);
                $tsIni = strtotime($periodoInicio . ' 00:00:00');
                $tsFin = strtotime($periodoFinExclusivo . ' 00:00:00');
                if ($tsFiscal !== false && $tsFiscal >= $tsIni && $tsFiscal < $tsFin) {
                    $fila['estatus_periodo_diot'] = 'EN PERIODO';
                } else {
                    $fila['estatus_periodo_diot'] = 'FUERA DE PERIODO';
                }
            }
        }

        // En exportación, los importes monetarios del CFDI se entregan en MXN.
        // Moneda y TC originales se conservan para auditoría. En pantalla normal
        // la fila sigue mostrando exactamente los valores originales del XML.
        if ($exportAll) {
            $factorMxn = diot_factor_mxn($r['moneda'] ?? 'MXN', $r['tc_xml_factura'] ?? 1);
            $fila['moneda_importes'] = 'MXN';
            $fila['factor_conversion_mxn'] = $factorMxn;
            foreach (['total','subtotal','base_iva','iva_t','base_iva_r','iva_r','base_isr_r','isr_r','base_iva_8','iva_8','base_norte','iva_norte','base_sur','iva_sur','saldo'] as $campoMxn) {
                $fila[$campoMxn] = (float)$fila[$campoMxn] * $factorMxn;
            }
        }

        $data[] = $fila;
    }

    echo json_encode([
        'draw'=>$draw,
        'recordsTotal'=>$recordsTotal,
        'recordsFiltered'=>$recordsFiltered,
        'data'=>array_values($data),
        'totals'=>$totales,
        'serverSide'=>!$exportAll
    ], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    seguridad_log_error($e, 'listar_facturas');
    echo json_encode([
        'draw'=>(int)($req['draw'] ?? 0),
        'recordsTotal'=>0,
        'recordsFiltered'=>0,
        'data'=>array_values([]),
        'error'=>'No se pudieron consultar las facturas.'
    ], JSON_UNESCAPED_UNICODE);
}
