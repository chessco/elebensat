<?php
function nf_json($value): string {
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
}
function nf_require_schema(PDO $pdo): void {
    try {
        $pdo->query('SELECT id_fuente FROM nomina_fuentes LIMIT 0');
        $pdo->query('SELECT id_fuente FROM nomina_fuente_filas LIMIT 0');
        $pdo->query('SELECT id_fuente FROM nomina_fuente_bloques LIMIT 0');
        $pdo->query('SELECT id_campo FROM nomina_campos LIMIT 0');
    } catch (PDOException $e) {
        throw new RuntimeException('No está disponible el almacenamiento completo. Aplique sql/nomina_reporte_completo_v1.sql antes de importar.',0,$e);
    }
}
function nf_field_key(string $label): string {
    $label=trim(preg_replace('/\s+/u',' ',$label)??$label);
    // Las claves P002/D016/I008/etc. mandan sobre el texto y la posición.
    if (preg_match('/^([A-Za-z]+[0-9]*|TOTALPER|TOTALDED|NETO)\s+-\s+/u',$label,$m)) return 'CODIGO:'.strtoupper($m[1]);
    $label=strtr($label,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','ñ'=>'n','Ñ'=>'N']);
    return 'ENCABEZADO:'.strtoupper($label);
}
function nf_field_defaults(): array {
    static $defaults=null;
    if ($defaults===null) {
        $data=file_get_contents(__DIR__.'/nomina_campos_maestro.json');
        if ($data===false) throw new RuntimeException('Falta el catálogo de campos del maestro.');
        $defaults=[];
        foreach(json_decode($data,true,512,JSON_THROW_ON_ERROR) as $field) $defaults[nf_field_key($field['encabezado'])]=$field;
    }
    return $defaults;
}
/** Sin escribir durante la previsualización. No depende del número de columna. */
function nf_fields(PDO $pdo,int $empresa,array $columns,bool $register=false,int $usuario=0): array {
    $defaults=nf_field_defaults();
    $q=$pdo->prepare('SELECT * FROM nomina_campos WHERE id_empresa=?'); $q->execute([$empresa]);
    $known=[]; foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f) $known[$f['clave_hash']]=$f;
    $counts=[];
    foreach($columns as $c) {$key=nf_field_key($c['encabezado']);$counts[$key]=($counts[$key]??0)+1;}
    $pending=[];
    foreach($columns as &$c) {
        $label=$c['encabezado']; $key=nf_field_key($label); $hash=hash('sha256',$key);
        $field=$known[$hash]??null;
        $blank=trim($label)===''; $duplicate=$counts[$key]>1;
        if (!$field && $register && !$blank) {
            $state=isset($defaults[$key])?'ACTIVO':'PENDIENTE';
            $type=$defaults[$key]['tipo']??'TEXTO';
            try {
                $q=$pdo->prepare('INSERT INTO nomina_campos (id_empresa,clave_hash,clave_campo,encabezado,tipo_valor,estado,id_usuario_alta,fecha_alta) VALUES (?,?,?,?,?,?,?,?)');
                $q->execute([$empresa,$hash,$key,$label,$type,$state,$state==='ACTIVO'?$usuario:null,$state==='ACTIVO'?date('Y-m-d H:i:s'):null]);
            } catch(PDOException $ex) {
                // Otra importación de la misma empresa pudo registrar la clave.
                if (!in_array((string)$ex->getCode(),['23000','23505'],true)) throw $ex;
                $q=$pdo->prepare('SELECT id_campo FROM nomina_campos WHERE id_empresa=? AND clave_hash=?'); $q->execute([$empresa,$hash]);
                if (!$q->fetchColumn()) throw $ex;
            }
            $q=$pdo->prepare('SELECT * FROM nomina_campos WHERE id_empresa=? AND clave_hash=?');$q->execute([$empresa,$hash]);
            $field=$q->fetch(PDO::FETCH_ASSOC); $known[$hash]=$field;
        }
        $state=$field['estado']??(isset($defaults[$key])?'ACTIVO':'PENDIENTE');
        if ($blank || $duplicate) $state='AMBIGUO';
        $c['clave_campo']=$key; $c['id_campo']=(int)($field['id_campo']??0); $c['estado_al_importar']=$state;
        if ($state!=='ACTIVO') $pending[]=['columna'=>$c['letra'],'encabezado'=>$label,'id_campo'=>$c['id_campo'],'estado'=>$state,
            'motivo'=>$blank?'Falta encabezado':($duplicate?'Código o encabezado repetido; requiere revisión':'Falta dar de alta en el catálogo de nómina')];
    }
    unset($c);
    return ['columnas'=>$columns,'pendientes'=>$pending];
}
/** Guarda dentro de la MISMA transacción que el encabezado y los totales.
 * Cada importación/reimportación exitosa conserva una nueva versión de la fuente.
 */
function nf_save(PDO $pdo,int $empresa,int $importacion,int $usuario,array $info,array $snapshot,array $metadata): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('La fuente debe guardarse dentro de la transacción de importación.');
    $q=$pdo->prepare('SELECT id_importacion FROM nomina_importaciones WHERE id_importacion=? AND id_empresa=?');
    $q->execute([$importacion,$empresa]);
    if (!$q->fetchColumn()) throw new RuntimeException('La importación no pertenece a la empresa activa.');
    $fields=nf_fields($pdo,$empresa,$snapshot['columnas'],true,$usuario);
    $snapshot['columnas']=$fields['columnas']; $metadata['campos_pendientes_al_importar']=$fields['pendientes'];
    $size=filesize($info['path']);
    $hash=hash_file('sha256',$info['path']);
    if (!$size || $size>25*1024*1024 || !$hash) throw new RuntimeException('El archivo original no está disponible o supera 25 MB.');
    $st=$pdo->prepare('INSERT INTO nomina_fuentes (id_importacion,id_empresa,nombre_archivo,extension,hash_archivo,bytes_archivo,hoja,fila_encabezado,total_columnas,total_filas,columnas_json,metadata_json,id_usuario) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$importacion,$empresa,$info['name'],$info['ext'],$hash,$size,$snapshot['hoja'],$snapshot['fila_encabezado'],$snapshot['total_columnas'],$snapshot['total_filas'],nf_json($snapshot['columnas']),nf_json($metadata),$usuario]);
    $id=(int)$pdo->lastInsertId();
    $st=$pdo->prepare('INSERT INTO nomina_fuente_filas (id_fuente,renglon,clase,numero_empleado,rfc,valores_json) VALUES (?,?,?,?,?,?)');
    foreach ($snapshot['filas'] as $row) {
        $st->execute([$id,$row['renglon'],$row['clase'],$row['numero_empleado'],$row['rfc'],nf_json($row['valores'])]);
    }
    $fh=fopen($info['path'],'rb');
    if (!$fh) throw new RuntimeException('No se pudo abrir el archivo original.');
    $bytes=0; $block=0; $ctx=hash_init('sha256');
    $st=$pdo->prepare('INSERT INTO nomina_fuente_bloques (id_fuente,numero_bloque,contenido) VALUES (?,?,?)');
    try {
        while (!feof($fh)) {
            $data=fread($fh,256*1024);
            if ($data===false) throw new RuntimeException('Error leyendo el archivo original.');
            if ($data==='') continue;
            $st->bindValue(1,$id,PDO::PARAM_INT); $st->bindValue(2,$block++,PDO::PARAM_INT); $st->bindValue(3,$data,PDO::PARAM_LOB); $st->execute();
            $bytes+=strlen($data); hash_update($ctx,$data);
        }
    } finally { fclose($fh); }
    if ($bytes!==$size || !hash_equals($hash,hash_final($ctx))) throw new RuntimeException('El archivo cambió durante la importación. No se guardó la nómina.');
    return ['id_fuente'=>$id,'pendientes'=>$fields['pendientes']];
}
function nf_latest(PDO $pdo,int $empresa,int $importacion): array {
    $q=$pdo->prepare('SELECT * FROM nomina_fuentes WHERE id_empresa=? AND id_importacion=? ORDER BY id_fuente DESC LIMIT 1');
    $q->execute([$empresa,$importacion]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Esta importación todavía no tiene el reporte completo. Vuelva a importar el archivo COMPLETO original; no una muestra.');
    return $row;
}
/** Recuperación con validación ANTES de iniciar la descarga. */
function nf_restore(PDO $pdo,array $source,string $path): void {
    $q=$pdo->prepare('SELECT numero_bloque,contenido FROM nomina_fuente_bloques WHERE id_fuente=? ORDER BY numero_bloque');
    $q->execute([(int)$source['id_fuente']]);
    $fh=fopen($path,'wb');
    if (!$fh) throw new RuntimeException('No se pudo crear el temporal de descarga.');
    $ctx=hash_init('sha256'); $bytes=0; $block=0;
    try {
        while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$row['numero_bloque']!==$block++) throw new RuntimeException('Falta un bloque del archivo original.');
            $data=is_resource($row['contenido'])?stream_get_contents($row['contenido']):$row['contenido'];
            if (!is_string($data) || fwrite($fh,$data)!==strlen($data)) throw new RuntimeException('No se pudo recuperar el archivo original.');
            $bytes+=strlen($data); hash_update($ctx,$data);
        }
    } finally { fclose($fh); }
    if ($bytes!==(int)$source['bytes_archivo'] || !hash_equals($source['hash_archivo'],hash_final($ctx))) throw new RuntimeException('El original guardado no pasó la verificación de integridad.');
}
