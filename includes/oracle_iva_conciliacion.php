<?php
require_once __DIR__ . '/XlsxSimpleReader.php';
require_once __DIR__ . '/diot_detalles.php';
require_once __DIR__ . '/../config/paths.php';

function oic_norm(string $s): string {
    // Normalización independiente de iconv. En algunos servidores iconv no está
    // habilitado o no translitera igual y encabezados como Débito/Crédito/Número
    // no eran reconocidos aunque el Excel fuera correcto.
    $s = trim(str_replace("\xC2\xA0", ' ', $s)); // NBSP UTF-8
    $s = strtr($s, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'
    ]);
    // iconv queda sólo como apoyo para cualquier otro carácter acentuado.
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') $s = $t;
    }
    $s = strtoupper($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function oic_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS oracle_iva_archivos (
        id_archivo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        anio SMALLINT UNSIGNED NOT NULL,
        mes TINYINT UNSIGNED NOT NULL,
        nombre_original VARCHAR(255) NOT NULL,
        ruta_archivo VARCHAR(1200) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        hoja_principal VARCHAR(190) DEFAULT NULL,
        filas_importadas INT NOT NULL DEFAULT 0,
        encabezados_detectados INT NOT NULL DEFAULT 0,
        id_usuario INT DEFAULT NULL,
        fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id_archivo),
        KEY idx_oia_empresa_periodo(id_empresa,anio,mes,fecha_subida),
        CONSTRAINT fk_oia_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE,
        CONSTRAINT fk_oia_usuario FOREIGN KEY(id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oracle_iva_movimientos (
        id_movimiento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_archivo BIGINT UNSIGNED NOT NULL,
        id_empresa INT NOT NULL,
        hoja VARCHAR(190) NOT NULL,
        renglon_excel INT NOT NULL,
        cuenta VARCHAR(100) DEFAULT NULL,
        cuenta_descripcion VARCHAR(700) DEFAULT NULL,
        cuenta_tipo VARCHAR(20) NOT NULL DEFAULT 'OTRO',
        origen VARCHAR(120) DEFAULT NULL,
        categoria VARCHAR(120) DEFAULT NULL,
        fecha_contable DATE DEFAULT NULL,
        clase_evento VARCHAR(120) DEFAULT NULL,
        numero_transaccion VARCHAR(190) DEFAULT NULL,
        descripcion TEXT,
        debito DECIMAL(20,6) NOT NULL DEFAULT 0,
        credito DECIMAL(20,6) NOT NULL DEFAULT 0,
        referencia_factura VARCHAR(190) DEFAULT NULL,
        tipo_referencia VARCHAR(20) NOT NULL DEFAULT 'OTRO',
        PRIMARY KEY(id_movimiento),
        KEY idx_oim_archivo(id_archivo,renglon_excel),
        KEY idx_oim_empresa_ref(id_empresa,referencia_factura),
        KEY idx_oim_archivo_tipo(id_archivo,cuenta_tipo),
        CONSTRAINT fk_oim_archivo FOREIGN KEY(id_archivo) REFERENCES oracle_iva_archivos(id_archivo) ON DELETE CASCADE,
        CONSTRAINT fk_oim_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oracle_iva_conciliaciones (
        id_conciliacion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_archivo BIGINT UNSIGNED NOT NULL,
        id_empresa INT NOT NULL,
        anio SMALLINT UNSIGNED NOT NULL,
        mes TINYINT UNSIGNED NOT NULL,
        tolerancia DECIMAL(10,4) NOT NULL DEFAULT 0.0500,
        resultado_json LONGTEXT NOT NULL,
        id_usuario INT DEFAULT NULL,
        fecha_conciliacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id_conciliacion),
        KEY idx_oic_empresa_periodo(id_empresa,anio,mes,fecha_conciliacion),
        KEY idx_oic_archivo(id_archivo),
        CONSTRAINT fk_oic_archivo FOREIGN KEY(id_archivo) REFERENCES oracle_iva_archivos(id_archivo) ON DELETE CASCADE,
        CONSTRAINT fk_oic_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE,
        CONSTRAINT fk_oic_usuario FOREIGN KEY(id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function oic_excel_date($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 20000 && $n < 80000) return gmdate('Y-m-d', (int)round(($n - 25569) * 86400));
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    foreach (['Y-m-d','d/m/Y','m/d/Y','d-m-Y'] as $f) {
        $d = DateTime::createFromFormat($f, substr($s,0,10));
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d',$ts) : null;
}

function oic_num($v): float {
    if ($v === null || $v === '') return 0.0;
    if (is_numeric($v)) return (float)$v;
    $s = str_replace([',','$',' '], '', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}

function oic_ref(string $descripcion, string $transaccion=''): array {
    $ref='';
    if (preg_match('/Factura\s*:\s*([A-Z0-9\-]+)/i',$descripcion,$m)) $ref=strtoupper(trim($m[1]));
    if ($ref==='' && preg_match('/\b(EXP\d{6,})\b/i',$descripcion,$m)) $ref=strtoupper($m[1]);
    if ($ref==='') {
        $t=strtoupper(trim($transaccion));
        if (preg_match('/^EXP\d{6,}$/',$t) || preg_match('/^[0-9A-F]{8}$/',$t)) $ref=$t;
    }
    $tipo='OTRO';
    if (preg_match('/^EXP\d{6,}$/',$ref)) $tipo='EXP';
    elseif (preg_match('/^[0-9A-F]{8}$/',$ref)) $tipo='UUID_CORTO';
    return [$ref,$tipo];
}

function oic_detect_header(array $row): ?array {
    $map=[];
    foreach ($row as $c=>$v) {
        $n=oic_norm((string)$v);
        if ($n==='FECHA CONTABLE') $map['fecha']=$c;
        elseif (in_array($n,['NUMERO DE TRANSACCION','NUMERO TRANSACCION'],true)) $map['trans']=$c;
        elseif (in_array($n,['DESCRIPCION DE LINEA','DESCRIPCION LINEA'],true)) $map['desc']=$c;
        elseif ($n==='DEBITO') $map['debito']=$c;
        elseif ($n==='CREDITO') $map['credito']=$c;
        elseif ($n==='ORIGEN') $map['origen']=$c;
        elseif ($n==='CATEGORIA') $map['categoria']=$c;
        elseif ($n==='CLASE DE EVENTO') $map['evento']=$c;
    }
    foreach(['fecha','trans','desc','debito','credito'] as $k) if(!array_key_exists($k,$map)) return null;
    return $map;
}

function oic_cuenta_tipo(string $desc): string {
    $n=oic_norm($desc);
    if (str_contains($n,'IVA ACREDITABLE NO PAGADO')) return 'NO_PAGADO';
    if (str_contains($n,'IVA ACREDITABLE PAGADO')) return 'PAGADO';
    return 'OTRO';
}

function oic_importar_xlsx(PDO $pdo,int $idEmpresa,int $anio,int $mes,string $tmp,string $nombre,int $idUsuario): array {
    oic_tables($pdo);
    $reader=new XlsxSimpleReader($tmp);
    $sheets=$reader->getSheetNames();
    if(!$sheets) throw new RuntimeException('El Excel no contiene hojas.');

    $base=sgksat_private_path('conciliacion_iva/empresa_'.$idEmpresa.'/'.sprintf('%04d/%02d',$anio,$mes));
    if(!is_dir($base) && !@mkdir($base,0775,true) && !is_dir($base)) throw new RuntimeException('No se pudo crear la carpeta privada de conciliación IVA.');
    $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',basename($nombre)) ?: 'oracle_iva.xlsx';
    if(!preg_match('/\.xlsx$/i',$safe)) $safe.='.xlsx';
    $dest=$base.'/'.date('Ymd_His').'_'.$safe;
    if(!@copy($tmp,$dest)) throw new RuntimeException('No se pudo guardar el Excel en la carpeta privada.');
    $sha=hash_file('sha256',$dest) ?: '';

    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('INSERT INTO oracle_iva_archivos(id_empresa,anio,mes,nombre_original,ruta_archivo,sha256,hoja_principal,id_usuario) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute([$idEmpresa,$anio,$mes,$nombre,$dest,$sha,$sheets[0],$idUsuario?:null]);
        $idArchivo=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO oracle_iva_movimientos(id_archivo,id_empresa,hoja,renglon_excel,cuenta,cuenta_descripcion,cuenta_tipo,origen,categoria,fecha_contable,clase_evento,numero_transaccion,descripcion,debito,credito,referencia_factura,tipo_referencia) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $total=0;$headers=0;
        foreach($sheets as $sheet){
            $rows=$reader->readSheet($sheet);
            $cuenta='';$cuentaDesc='';$cuentaTipo='OTRO';$map=null;$lastId=null;
            foreach($rows as $ri=>$row){
                $first=oic_norm((string)($row[0]??''));
                if($first==='CUENTA'){
                    $cuenta=trim((string)($row[2]??''));
                    $cuentaDesc=trim((string)($row[9]??''));
                    $cuentaTipo=oic_cuenta_tipo($cuentaDesc);
                    $map=null;$lastId=null;
                    continue;
                }
                $hm=oic_detect_header($row);
                if($hm!==null){$map=$hm;$headers++;$lastId=null;continue;}
                if($map===null) continue;
                $desc=trim((string)($row[$map['desc']]??''));
                $trans=trim((string)($row[$map['trans']]??''));
                $fecha=oic_excel_date($row[$map['fecha']]??null);
                $deb=oic_num($row[$map['debito']]??null);$cre=oic_num($row[$map['credito']]??null);
                if($fecha===null && $trans==='' && abs($deb)<0.000001 && abs($cre)<0.000001){
                    // Renglón de continuación de una descripción larga: se anexa al movimiento anterior.
                    if($desc!=='' && $lastId){
                        $up=$pdo->prepare("UPDATE oracle_iva_movimientos SET descripcion=CONCAT(COALESCE(descripcion,''),' ',?) WHERE id_movimiento=?");
                        $up->execute([$desc,$lastId]);
                    }
                    continue;
                }
                if(abs($deb)<0.000001 && abs($cre)<0.000001) continue;
                [$ref,$tipo]=oic_ref($desc,$trans);
                $ins->execute([$idArchivo,$idEmpresa,$sheet,$ri+1,$cuenta,$cuentaDesc,$cuentaTipo,
                    trim((string)($row[$map['origen']]??'')),trim((string)($row[$map['categoria']]??'')),$fecha,
                    trim((string)($row[$map['evento']]??'')),$trans,$desc,$deb,$cre,$ref,$tipo]);
                $lastId=(int)$pdo->lastInsertId();$total++;
            }
        }
        if($total===0) throw new RuntimeException('No se encontraron movimientos con Débito/Crédito. Revise que el archivo sea el reporte de análisis de cuenta de Oracle.');
        $pdo->prepare('UPDATE oracle_iva_archivos SET filas_importadas=?,encabezados_detectados=? WHERE id_archivo=?')->execute([$total,$headers,$idArchivo]);
        $pdo->commit();
        return ['id_archivo'=>$idArchivo,'filas'=>$total,'encabezados'=>$headers,'hojas'=>$sheets,'ruta'=>$dest];
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); @unlink($dest); throw $e; }
}

function oic_detalle_periodo(PDO $pdo,int $idEmpresa,int $anio,int $mes): array {
    [$ini,$fin]=diot_periodo_limites($anio,$mes);
    $sql="SELECT f.uuid,e.rfc,e.nombre,f.serie,f.folio,f.fecha_emision,f.total_xml,
                 MAX(COALESCE(NULLIF(d.folio_pago_origen,''),NULLIF(f.folio_pago_origen,''),'')) ref_pago,
                 SUM(COALESCE(d.base_iva_16,0)+COALESCE(d.base_iva_8,0)) base_iva,
                 SUM(COALESCE(d.iva_16,0)+COALESCE(d.iva_8,0)) iva,
                 SUM(COALESCE(d.iva_retenido,0)) iva_retenido,
                 MAX(d.fecha_aplicacion_fiscal) fecha_fiscal
          FROM facturas_pagos_detalles d
          JOIN facturas f ON f.id_empresa=d.id_empresa AND f.uuid=d.uuid_relacionado
          LEFT JOIN facturas fp ON fp.id_empresa=d.id_empresa AND fp.uuid=d.uuid_pago
          JOIN cat_emisores e ON e.id_empresa=f.id_empresa AND e.id_emisor=f.id_emisor
          WHERE d.id_empresa=? AND d.fecha_aplicacion_fiscal>=? AND d.fecha_aplicacion_fiscal<?
            AND COALESCE(d.es_sustituido,0)=0
            -- Debe usar exactamente las mismas exclusiones que el detalle DIOT.
            -- 1) Si la factura origen está marcada como NO CONSIDERAR DIOT, no entra.
            AND COALESCE(f.excluir_diot,0)=0
            -- 2) Si el detalle viene de un REP/complemento real, también se respeta
            --    la exclusión del propio complemento y su estatus SAT.
            AND (COALESCE(d.es_sintetico,0)=1 OR COALESCE(fp.excluir_diot,0)=0)
            AND (COALESCE(d.es_sintetico,0)=1 OR UPPER(TRIM(COALESCE(fp.estatus_sat,''))) NOT IN ('CANCELADO','CANCELADA','0'))
            AND COALESCE(e.aplica_diot,1)=1
            AND UPPER(TRIM(COALESCE(f.estatus_sat,'VIGENTE'))) IN ('VIGENTE','1')
          GROUP BY f.uuid,e.rfc,e.nombre,f.serie,f.folio,f.fecha_emision,f.total_xml";
    $st=$pdo->prepare($sql);$st->execute([$idEmpresa,$ini,$fin]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function oic_grupos_oracle(PDO $pdo,int $idArchivo,int $anio,int $mes): array {
    [$ini,$fin]=diot_periodo_limites($anio,$mes);
    // La conciliación mensual debe considerar EXCLUSIVAMENTE movimientos contables
    // cuya Fecha contable pertenezca al mes seleccionado. El archivo de Oracle puede
    // traer saldos, bloques u otros periodos y no deben contaminar el mes en turno.
    $st=$pdo->prepare("SELECT group_ref AS referencia_factura,group_tipo AS tipo_referencia,
        SUM(debito) debito,SUM(credito) credito,SUM(debito-credito) neto,
        GROUP_CONCAT(DISTINCT numero_transaccion ORDER BY renglon_excel SEPARATOR ', ') transacciones,
        GROUP_CONCAT(DISTINCT LEFT(descripcion,180) ORDER BY renglon_excel SEPARATOR ' || ') descripciones,
        MIN(fecha_contable) fecha_min,MAX(fecha_contable) fecha_max,COUNT(*) renglones
        FROM (
            SELECT m.*,
                   CASE WHEN TRIM(COALESCE(referencia_factura,''))='' THEN CONCAT('#ROW',id_movimiento) ELSE referencia_factura END AS group_ref,
                   CASE WHEN TRIM(COALESCE(referencia_factura,''))='' THEN 'OTRO' ELSE tipo_referencia END AS group_tipo
            FROM oracle_iva_movimientos m
            WHERE id_archivo=? AND cuenta_tipo='PAGADO'
              AND fecha_contable>=? AND fecha_contable<?
        ) z
        GROUP BY group_ref,group_tipo ORDER BY MIN(renglon_excel)");
    $st->execute([$idArchivo,$ini,$fin]); return $st->fetchAll(PDO::FETCH_ASSOC);
}

function oic_match_group(array $g,array $visor,array &$used,float $tol): array {
    $ref=strtoupper(trim((string)$g['referencia_factura']));
    $tipo=(string)$g['tipo_referencia'];
    $oracle=round((float)$g['neto'],2);$matches=[];$regla='';
    if($tipo==='UUID_CORTO'){
        foreach($visor as $v){$u=strtoupper((string)$v['uuid']); if(str_starts_with($u,$ref))$matches[]=$v;}
        $regla='UUID CORTO';
    }elseif($tipo==='EXP'){
        foreach($visor as $v){ if(stripos((string)($v['ref_pago']??''),$ref)!==false)$matches[]=$v; }
        $regla='EXP / CAJA CHICA';
    }
    $iva=0.0;foreach($matches as $v){$iva+=(float)$v['iva'];$used[(string)$v['uuid']]=true;}
    $iva=round($iva,2);$dif=round($oracle-$iva,2);
    $estado='NO_CONCILIADO';
    if($matches && abs($dif)<=$tol)$estado='CONCILIADO';
    elseif($matches)$estado='DIFERENCIA';
    return ['referencia'=>$ref,'tipo'=>$tipo,'regla'=>$regla,'descripcion'=>(string)($g['descripciones']??''),'debito'=>round((float)$g['debito'],2),'credito'=>round((float)$g['credito'],2),'oracle'=>$oracle,'visor'=>$iva,'diferencia'=>$dif,'estado'=>$estado,'renglones'=>(int)$g['renglones'],'transacciones'=>$g['transacciones'],'detalles'=>$matches];
}

function oic_conciliar(PDO $pdo,int $idEmpresa,int $idArchivo,int $anio,int $mes,int $idUsuario,float $tol=0.05): array {
    oic_tables($pdo);
    $st=$pdo->prepare('SELECT * FROM oracle_iva_archivos WHERE id_archivo=? AND id_empresa=? LIMIT 1');$st->execute([$idArchivo,$idEmpresa]);$archivo=$st->fetch(PDO::FETCH_ASSOC);
    if(!$archivo) throw new RuntimeException('El archivo seleccionado no pertenece a la empresa activa.');
    if((int)$archivo['anio']!==$anio||(int)$archivo['mes']!==$mes) throw new RuntimeException('El periodo seleccionado no coincide con el periodo del archivo cargado.');
    $visor=oic_detalle_periodo($pdo,$idEmpresa,$anio,$mes);$grupos=oic_grupos_oracle($pdo,$idArchivo,$anio,$mes);$used=[];$res=[];
    foreach($grupos as $g)$res[]=oic_match_group($g,$visor,$used,$tol);

    // Si Oracle trae referencias UUID pero ninguna coincide con el detalle DIOT de la
    // empresa activa, casi siempre significa que se cargó el archivo de otra empresa.
    // Mejor detener aquí que presentar totales absurdos como si fueran una conciliación.
    $refsUuid=count(array_filter($grupos,fn($g)=>($g['tipo_referencia']??'')==='UUID_CORTO'));
    $coincidenciasUuid=0;
    foreach($res as $r){
        if(($r['tipo']??'')==='UUID_CORTO' && !empty($r['detalles'])) $coincidenciasUuid++;
    }
    if($refsUuid>=3 && $coincidenciasUuid===0){
        $stEmp=$pdo->prepare('SELECT razon_social FROM empresas WHERE id_empresa=? LIMIT 1');
        $stEmp->execute([$idEmpresa]);
        $nomEmp=(string)$stEmp->fetchColumn();
        throw new RuntimeException('No se encontró ninguna factura de Oracle en el detalle DIOT de '.($nomEmp!==''?$nomEmp:'la empresa activa').' para el periodo seleccionado. Revise que el Excel corresponda a la EMPRESA ACTIVA y al mes en turno.');
    }
    $visorSin=[];foreach($visor as $v)if(empty($used[(string)$v['uuid']]))$visorSin[]=$v;
    $conc=array_values(array_filter($res,fn($r)=>$r['estado']==='CONCILIADO'));
    $dif=array_values(array_filter($res,fn($r)=>$r['estado']==='DIFERENCIA'));
    $orSin=array_values(array_filter($res,fn($r)=>$r['estado']==='NO_CONCILIADO' && abs((float)$r['oracle'])>$tol));
    $exp=array_values(array_filter($res,fn($r)=>$r['tipo']==='EXP'));

    // Primer folder: mostrar exactamente lo que se importó de Oracle para el mes
    // y señalar, renglón por renglón, si la referencia quedó conciliada.
    [$iniPeriodo,$finPeriodo]=diot_periodo_limites($anio,$mes);
    $stImp=$pdo->prepare("SELECT id_movimiento,hoja,renglon_excel,cuenta,cuenta_descripcion,cuenta_tipo,
        origen,categoria,fecha_contable,clase_evento,numero_transaccion,descripcion,
        debito,credito,referencia_factura,tipo_referencia
        FROM oracle_iva_movimientos
        WHERE id_archivo=? AND fecha_contable>=? AND fecha_contable<?
        ORDER BY hoja,renglon_excel,id_movimiento");
    $stImp->execute([$idArchivo,$iniPeriodo,$finPeriodo]);
    $importados=$stImp->fetchAll(PDO::FETCH_ASSOC);
    $mapRes=[];
    foreach($res as $rr){
        $k=strtoupper((string)($rr['tipo']??'')).'|'.strtoupper((string)($rr['referencia']??''));
        if($k!=='|') $mapRes[$k]=$rr;
    }
    $impSi=0;$impNo=0;$impNa=0;
    foreach($importados as &$im){
        $tipo=strtoupper(trim((string)($im['tipo_referencia']??'')));
        $ref=strtoupper(trim((string)($im['referencia_factura']??'')));
        $key=$tipo.'|'.$ref;
        $estadoGrupo=$mapRes[$key]['estado']??'';
        if(($im['cuenta_tipo']??'')!=='PAGADO'){
            $im['concilio']='NO APLICA';
            $im['estado_conciliacion']='CUENTA '.($im['cuenta_tipo']??'OTRO');
            $impNa++;
        }elseif($estadoGrupo==='CONCILIADO'){
            $im['concilio']='SI';
            $im['estado_conciliacion']='CONCILIADO';
            $impSi++;
        }else{
            $im['concilio']='NO';
            $im['estado_conciliacion']=$estadoGrupo!==''?$estadoGrupo:($ref===''?'SIN REFERENCIA':'NO CONCILIADO');
            $impNo++;
        }
        $im['neto']=round((float)$im['debito']-(float)$im['credito'],2);
    }
    unset($im);

    $oracleDeb=0;$oracleCre=0;foreach($grupos as $g){$oracleDeb+=(float)$g['debito'];$oracleCre+=(float)$g['credito'];}
    $visorIva=array_sum(array_map(fn($v)=>(float)$v['iva'],$visor));
    $oracleNeto=$oracleDeb-$oracleCre;

    // Tránsitos y pagos de factura de mes anterior, reutilizando las mismas reglas del resumen DIOT.
    $anteriores=diot_pagos_facturas_anteriores($pdo,$idEmpresa,$anio,$mes);
    $pend=diot_cfdi_mes_no_pagados($pdo,$idEmpresa,$anio,$mes);
    $post=diot_cfdi_mes_pagados_posterior($pdo,$idEmpresa,$anio,$mes);
    $usr=diot_cfdi_mes_pago_usuario_sin_rep($pdo,$idEmpresa,$anio,$mes);
    $idxOracle=[];foreach($res as $r)if($r['referencia']!=='')$idxOracle[$r['referencia']]=$r;
    $transito=[];
    $mk=function(array $r,string $grupo,bool $esperaAplicado)use($idxOracle,$tol){
        $u=strtoupper(trim((string)($r['uuid_factura']??$r['uuid']??'')));$short=substr($u,0,8);$o=$idxOracle[$short]??null;
        $iva=(float)($r['iva_16_mxn']??$r['iva_16']??0)+(float)($r['iva_8_mxn']??$r['iva_8']??0);
        $aplico=$o && abs((float)$o['oracle'])>$tol;
        return ['grupo'=>$grupo,'uuid'=>$u,'proveedor'=>(string)($r['nombre']??''),'iva'=>round($iva,2),'oracle_aplico'=>$aplico,'esperado'=>$esperaAplicado?'APLICAR':'NO APLICAR','estado'=>($aplico===$esperaAplicado?'OK':'REVISAR'),'referencia_oracle'=>$o['referencia']??''];
    };
    foreach($anteriores as $r)$transito[]=$mk($r,'MES ANTERIOR PAGADO EN ESTE MES',true);
    $seen=[];
    foreach($pend as $r){$u=(string)($r['uuid']??'');if($u==='')continue;$seen[$u]=1;$transito[]=$mk($r,'TRÁNSITO A MES POSTERIOR - PENDIENTE',false);}
    foreach($post as $r){$u=(string)($r['uuid_factura']??'');if($u===''||isset($seen[$u]))continue;$seen[$u]=1;$transito[]=$mk($r,'TRÁNSITO A MES POSTERIOR - PAGADO DESPUÉS',false);}
    foreach($usr as $r){$u=(string)($r['uuid_factura']??'');if($u===''||isset($seen[$u]))continue;$seen[$u]=1;$transito[]=$mk($r,'TRÁNSITO A MES POSTERIOR - SIN REP',false);}

    $resultado=['archivo'=>['id'=>(int)$archivo['id_archivo'],'nombre'=>$archivo['nombre_original'],'fecha_subida'=>$archivo['fecha_subida']],
      'periodo'=>['anio'=>$anio,'mes'=>$mes],
      'resumen'=>['oracle_debito'=>round($oracleDeb,2),'oracle_credito'=>round($oracleCre,2),'oracle_neto'=>round($oracleNeto,2),'visor_iva'=>round($visorIva,2),'diferencia_global'=>round($oracleNeto-$visorIva,2),'grupos_oracle'=>count($res),'conciliados'=>count($conc),'diferencias'=>count($dif),'oracle_sin_conciliar'=>count($orSin),'visor_sin_conciliar'=>count($visorSin),'exp'=>count($exp),'importados'=>count($importados),'importados_conciliados'=>$impSi,'importados_no_conciliados'=>$impNo,'importados_no_aplica'=>$impNa],
      'importados_oracle'=>$importados,'conciliados'=>$conc,'diferencias'=>$dif,'oracle_sin_conciliar'=>$orSin,'visor_sin_conciliar'=>$visorSin,'exp'=>$exp,'transito'=>$transito];
    $json=json_encode($resultado,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    $st=$pdo->prepare('INSERT INTO oracle_iva_conciliaciones(id_archivo,id_empresa,anio,mes,tolerancia,resultado_json,id_usuario) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$idArchivo,$idEmpresa,$anio,$mes,$tol,$json,$idUsuario?:null]);
    $resultado['id_conciliacion']=(int)$pdo->lastInsertId();
    return $resultado;
}
