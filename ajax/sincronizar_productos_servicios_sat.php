<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('memory_limit','1024M');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/permisos_documentos.php';
require_once __DIR__ . '/../includes/XlsBiff8Reader.php';
require_once __DIR__ . '/../includes/XlsxSimpleReader.php';

seguridad_exigir_sesion($pdo,true);
exigir_permiso_accion($pdo,(int)($_SESSION['id_usuario']??0),(int)($_SESSION['id_empresa']??0),'consulta_codigos_postales');
session_write_close();

const JOB_PREFIX='prodserv_sync_';

function failx(string $msg,int $code=400,array $extra=[]):void{
    http_response_code($code);
    echo json_encode(array_merge(['status'=>'error','msg'=>$msg],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function jobsDir():string{
    $d=sgksat_private_path('catalogos_sat/jobs');
    if(!is_dir($d)&&!mkdir($d,0775,true)&&!is_dir($d))throw new RuntimeException('No se pudo crear la carpeta de control.');
    return $d;
}
function cleanJobId(?string $v):string{
    $v=preg_replace('/[^a-zA-Z0-9_-]/','',(string)$v);
    if($v===''||strlen($v)<12||strlen($v)>80)throw new InvalidArgumentException('Identificador de proceso inválido.');
    return $v;
}
function jobPath(string $id):string{return jobsDir().'/'.JOB_PREFIX.$id.'.json';}
function readJob(string $id):array{
    $p=jobPath($id); if(!is_file($p))return[];
    $r=@file_get_contents($p); $d=$r!==false?json_decode($r,true):null;
    return is_array($d)?$d:[];
}
function writeJob(string $id,array $chg):array{
    $p=jobPath($id); $fp=fopen($p,'c+');
    if(!$fp)throw new RuntimeException('No se pudo guardar avance.');
    flock($fp,LOCK_EX); $raw=stream_get_contents($fp); $cur=$raw?json_decode($raw,true):[];
    if(!is_array($cur))$cur=[]; $cur=array_merge($cur,$chg,['updated_at'=>date('c')]);
    ftruncate($fp,0); rewind($fp); fwrite($fp,json_encode($cur,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); fflush($fp); flock($fp,LOCK_UN); fclose($fp);
    return $cur;
}
function isCancelled(string $id):bool{return !empty(readJob($id)['cancel_requested']);}
function checkCancelled(string $id):void{if(isCancelled($id))throw new RuntimeException('__CANCELLED__');}
function normHeader($s):string{
    $s=trim((string)$s);
    $s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N']);
    $s=strtoupper($s);
    return preg_replace('/[^A-Z0-9]/','',$s)??'';
}
function normalizeDate($v):?string{
    if($v===null||$v==='')return null;
    if(is_numeric($v)){
        $n=(float)$v;
        if($n>20000&&$n<100000){
            $ts=(int)round(($n-25569)*86400);
            return gmdate('Y-m-d',$ts);
        }
    }
    $s=trim((string)$v);
    foreach(['d/m/Y','Y-m-d','d-m-Y'] as $f){$d=DateTime::createFromFormat($f,$s);if($d)return $d->format('Y-m-d');}
    return null;
}
function q(PDO $pdo,$v):string{return($v===null||$v==='')?'NULL':$pdo->quote((string)$v);}

function getReader(string $path,string $original):object{
    $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    $sig=(string)@file_get_contents($path,false,null,0,8);
    if($ext==='xlsx' || substr($sig,0,2)==='PK')return new XlsxSimpleReader($path);
    if($ext==='xls' || $sig==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")return new XlsBiff8Reader($path);
    throw new RuntimeException('Formato no reconocido. Seleccione un archivo .xls o .xlsx del catálogo SAT.');
}


/**
 * Normaliza texto recibido desde Excel/COM.
 * En algunos equipos PHP recibe cadenas Windows-1252 aunque Excel sea Unicode.
 * Si ya es UTF-8 válido, lo conserva.
 */
function excelTextUtf8($value): string{
    if($value===null)return '';

    $s=(string)$value;
    $s=str_replace("\0",'',$s);

    // Si ya es UTF-8 válido, no tocarlo.
    if(function_exists('mb_check_encoding') && mb_check_encoding($s,'UTF-8')){
        return trim($s);
    }

    // Excel/COM en Windows normalmente entrega ANSI Windows-1252.
    if(function_exists('mb_convert_encoding')){
        $c=@mb_convert_encoding($s,'UTF-8','Windows-1252');
        if(is_string($c) && $c!==''){
            return trim($c);
        }
    }

    // Fallback si mbstring no está disponible.
    if(function_exists('iconv')){
        $c=@iconv('Windows-1252','UTF-8//IGNORE',$s);
        if($c!==false){
            return trim($c);
        }
    }

    return trim($s);
}

function rowValue(array $rows,int $row1Based,int $col0,$default=''){
    $ri=$row1Based-1;
    return $rows[$ri][$col0]??$default;
}

function normalizeSatKey($value):string{
    if($value===null)return '';
    if(is_float($value)||is_int($value)){
        $s=(string)(int)$value;
    }else{
        $s=trim((string)$value);
        if(preg_match('/^\\d+(?:\\.0+)?$/',$s))$s=(string)(int)((float)$s);
    }
    $s=preg_replace('/\\D/','',$s)??'';
    if($s!=='' && strlen($s)<8)$s=str_pad($s,8,'0',STR_PAD_LEFT);
    return $s;
}

function processTest(string $tmp,string $name,string $job,PDO $pdo):array{
    writeJob($job,['percent'=>2,'message'=>'Archivo recibido','detail'=>$name]);
    checkCancelled($job);

    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(!in_array($ext,['xls','xlsx'],true)){
        throw new RuntimeException('Seleccione un archivo .xls o .xlsx.');
    }

    $tmpDir=sgksat_private_path('catalogos_sat/tmp');
    if(!is_dir($tmpDir)&&!mkdir($tmpDir,0775,true)&&!is_dir($tmpDir)){
        throw new RuntimeException('No se pudo crear la carpeta temporal.');
    }

    $localFile=$tmpDir.'/prodserv_sync_'.$job.'.'.$ext;
    @unlink($localFile);

    writeJob($job,['percent'=>5,'message'=>'Preparando archivo','detail'=>'Copiando archivo temporal']);
    if(!copy($tmp,$localFile))throw new RuntimeException('No se pudo copiar el archivo seleccionado.');
    if(!is_file($localFile)||filesize($localFile)<100){
        @unlink($localFile);
        throw new RuntimeException('El archivo temporal quedó vacío.');
    }

    try{
        writeJob($job,[
            'percent'=>8,
            'message'=>'Abriendo archivo con PHP',
            'detail'=>'Sin Microsoft Excel, sin COM y sin Office'
        ]);
        checkCancelled($job);

        try{
            $reader=getReader($localFile,$name);
        }catch(Throwable $e){
            throw new RuntimeException('No se pudo abrir el Excel con el lector PHP: '.$e->getMessage());
        }

        $sheetNames=$reader->getSheetNames();
        if(!$sheetNames)throw new RuntimeException('El archivo no contiene hojas.');

        writeJob($job,[
            'percent'=>11,
            'message'=>'Buscando hoja de Productos y Servicios',
            'detail'=>'Hojas detectadas: '.count($sheetNames)
        ]);

        $sheetName='';
        if(count($sheetNames)===1){
            $sheetName=(string)$sheetNames[0];
        }else{
            foreach($sheetNames as $sn){
                if(normHeader($sn)==='CCLAVEPRODSERV'){
                    $sheetName=(string)$sn;
                    break;
                }
            }
        }
        if($sheetName===''){
            throw new RuntimeException('No se encontró la hoja c_ClaveProdServ. Hojas detectadas: '.implode(', ',array_slice($sheetNames,0,40)));
        }

        writeJob($job,[
            'percent'=>14,
            'message'=>'Leyendo hoja c_ClaveProdServ',
            'detail'=>'Procesando la hoja directamente con PHP'
        ]);
        checkCancelled($job);

        try{
            $rows=$reader->readSheet($sheetName);
        }catch(Throwable $e){
            $extra=$ext==='xls'?' Si el XLS del SAT usa una variante BIFF no compatible, guárdelo como Libro de Excel (.xlsx) y vuelva a intentarlo.':'';
            throw new RuntimeException('No se pudo leer la hoja '.$sheetName.': '.$e->getMessage().$extra);
        }
        unset($reader);

        writeJob($job,[
            'percent'=>17,
            'message'=>'Hoja cargada en memoria',
            'detail'=>'Hoja: '.$sheetName.' · filas físicas detectadas: '.number_format(count($rows))
        ]);

        $a5=excelTextUtf8(rowValue($rows,5,0,''));
        $b5=excelTextUtf8(rowValue($rows,5,1,''));
        $na=normHeader($a5);
        $nb=normHeader($b5);
        $b5Ok=($nb==='DESCRIPCION'||strpos($nb,'DESCRIPCI')===0);

        writeJob($job,[
            'percent'=>19,
            'message'=>'Encabezados leídos',
            'detail'=>'A5=['.$a5.'] · B5=['.$b5.']'
        ]);

        if($na!=='CCLAVEPRODSERV'||!$b5Ok){
            throw new RuntimeException('Encabezados inesperados en hoja '.$sheetName.'. A5=['.$a5.'] B5=['.$b5.']');
        }

        $firstRow=6;
        $lastRow=5;
        $maxRow=$rows ? (max(array_keys($rows))+1) : 5;

        writeJob($job,[
            'percent'=>20,
            'message'=>'Contando renglones',
            'detail'=>'Revisando filas de datos desde la fila 6'
        ]);

        for($r=$firstRow;$r<=$maxRow;$r++){
            checkCancelled($job);
            $clave=normalizeSatKey(rowValue($rows,$r,0,''));
            $desc=excelTextUtf8(rowValue($rows,$r,1,''));
            if($clave===''&&$desc==='')break;
            $lastRow=$r;
            if((($r-$firstRow+1)%500)===0){
                writeJob($job,[
                    'percent'=>20,
                    'message'=>'Contando renglones',
                    'detail'=>number_format($r-$firstRow+1).' detectados'
                ]);
            }
        }

        $totalRows=max(0,$lastRow-$firstRow+1);
        if($totalRows===0)throw new RuntimeException('No se detectaron renglones con datos desde la fila 6.');

        writeJob($job,[
            'percent'=>24,
            'message'=>'Total de renglones detectado',
            'detail'=>number_format($totalRows).' renglones con datos',
            'processed'=>0,
            'total'=>$totalRows
        ]);

        $revision=excelTextUtf8(rowValue($rows,3,2,''));
        $fechaPublicacion=normalizeDate(rowValue($rows,3,3,''));

        $sql="
            INSERT INTO sat_productos_servicios (
                clave_prod_serv, descripcion,
                incluir_iva_trasladado, incluir_ieps_trasladado,
                complemento_debe_incluir, fecha_inicio_vigencia,
                fecha_fin_vigencia, estimulo_franja_fronteriza,
                revision_catalogo, fecha_publicacion,
                archivo_origen, fecha_sincronizacion
            ) VALUES (
                :clave,:descripcion,:iva,:ieps,:complemento,
                :inicio,:fin,:frontera,:revision,:fecha_publicacion,
                :archivo,NOW()
            )
            ON DUPLICATE KEY UPDATE
                descripcion=VALUES(descripcion),
                incluir_iva_trasladado=VALUES(incluir_iva_trasladado),
                incluir_ieps_trasladado=VALUES(incluir_ieps_trasladado),
                complemento_debe_incluir=VALUES(complemento_debe_incluir),
                fecha_inicio_vigencia=VALUES(fecha_inicio_vigencia),
                fecha_fin_vigencia=VALUES(fecha_fin_vigencia),
                estimulo_franja_fronteriza=VALUES(estimulo_franja_fronteriza),
                revision_catalogo=VALUES(revision_catalogo),
                fecha_publicacion=VALUES(fecha_publicacion),
                archivo_origen=VALUES(archivo_origen),
                fecha_sincronizacion=NOW()
        ";
        $stmt=$pdo->prepare($sql);

        writeJob($job,[
            'percent'=>25,
            'message'=>'Iniciando sincronización',
            'detail'=>'Procesando en bloques de 500 · lector PHP sin Office',
            'processed'=>0,
            'total'=>$totalRows
        ]);

        $batchSize=500;
        $leidos=0;$guardados=0;$omitidos=0;$bloqueActual=1;$enBloque=0;
        $pdo->beginTransaction();

        for($r=$firstRow;$r<=$lastRow;$r++){
            checkCancelled($job);
            $leidos++;
            $pct=25+(int)floor(($leidos/$totalRows)*68);
            if($pct>93)$pct=93;

            $clave=normalizeSatKey(rowValue($rows,$r,0,''));
            $desc=excelTextUtf8(rowValue($rows,$r,1,''));
            $iva=excelTextUtf8(rowValue($rows,$r,2,''));
            $ieps=excelTextUtf8(rowValue($rows,$r,3,''));
            $complemento=excelTextUtf8(rowValue($rows,$r,4,''));
            $inicio=normalizeDate(rowValue($rows,$r,5,''));
            $fin=normalizeDate(rowValue($rows,$r,6,''));
            $frontera=excelTextUtf8(rowValue($rows,$r,7,''));

            if(!preg_match('/^\\d{8}$/',$clave)||$desc===''){
                $omitidos++;$enBloque++;
                if($totalRows<=100){
                    writeJob($job,[
                        'percent'=>$pct,'message'=>'Renglón '.$leidos.' omitido',
                        'detail'=>'Fila '.$r.' · clave=['.$clave.'] · descripción=['.$desc.']',
                        'processed'=>$leidos,'total'=>$totalRows
                    ]);
                }
            }else{
                $stmt->execute([
                    ':clave'=>$clave, ':descripcion'=>$desc,
                    ':iva'=>$iva!==''?$iva:null,
                    ':ieps'=>$ieps!==''?$ieps:null,
                    ':complemento'=>$complemento!==''?$complemento:null,
                    ':inicio'=>$inicio, ':fin'=>$fin,
                    ':frontera'=>$frontera!==''?$frontera:null,
                    ':revision'=>$revision!==''?$revision:null,
                    ':fecha_publicacion'=>$fechaPublicacion,
                    ':archivo'=>$name
                ]);
                $guardados++;$enBloque++;
            }

            if($totalRows<=100||($leidos%25)===0||$leidos===$totalRows){
                writeJob($job,[
                    'percent'=>$pct,
                    'message'=>'Sincronizando '.$leidos.' de '.$totalRows,
                    'detail'=>'Clave '.$clave.' · '.$desc.' · guardados '.number_format($guardados).' · omitidos '.number_format($omitidos),
                    'processed'=>$leidos,'total'=>$totalRows
                ]);
            }

            if($enBloque>=$batchSize){
                $pdo->commit();
                writeJob($job,[
                    'percent'=>$pct,
                    'message'=>'Bloque '.$bloqueActual.' confirmado',
                    'detail'=>'500 renglones procesados · guardados acumulados '.number_format($guardados).' · omitidos '.number_format($omitidos),
                    'processed'=>$leidos,'total'=>$totalRows
                ]);
                $enBloque=0;
                if($r<$lastRow){$pdo->beginTransaction();$bloqueActual++;}
            }
        }

        if($pdo->inTransaction())$pdo->commit();
        unset($rows,$stmt);
        @unlink($localFile);

        writeJob($job,[
            'status'=>'completed','stage'=>'completed','percent'=>100,
            'message'=>'SINCRONIZACIÓN COMPLETADA',
            'detail'=>'Sin Office · bloques de 500 · Total '.$totalRows.' · guardados/actualizados '.$guardados.' · omitidos '.$omitidos,
            'processed'=>$leidos,'total'=>$totalRows,
            'guardados'=>$guardados,'omitidos'=>$omitidos,'warning'=>'','done'=>true
        ]);

        return ['total'=>$totalRows,'leidos'=>$leidos,'guardados'=>$guardados,'omitidos'=>$omitidos,'warning'=>''];

    }catch(Throwable $e){
        if($pdo->inTransaction()){
            try{$pdo->rollBack();}catch(Throwable $x){}
        }
        @unlink($localFile);
        throw $e;
    }
}

$action=$_POST['action']??'start';
try{$job=cleanJobId($_POST['job_id']??'');}catch(Throwable $e){failx($e->getMessage());}
if($action==='status'){$j=readJob($job);if(!$j)failx('No se encontró el proceso.',404);echo json_encode(['status'=>'ok','job'=>$j],JSON_UNESCAPED_UNICODE);exit;}
if($action==='cancel'){$j=writeJob($job,['cancel_requested'=>true,'message'=>'Cancelando sincronización…','detail'=>'Espere un momento']);echo json_encode(['status'=>'ok','job'=>$j],JSON_UNESCAPED_UNICODE);exit;}
if($action!=='start')failx('Acción no válida.');

writeJob($job,['status'=>'running','stage'=>'starting','percent'=>1,'message'=>'Esperando archivo','detail'=>'Lector PHP sin Microsoft Excel · bloques de 500','cancel_requested'=>false,'started_at'=>date('c')]);
try{
    if(empty($_FILES['archivo_excel']['tmp_name']))throw new RuntimeException('Seleccione primero el archivo Excel del catálogo SAT.');
    $tmp=$_FILES['archivo_excel']['tmp_name'];
    $name=basename((string)($_FILES['archivo_excel']['name']??'catalogo_sat'));
    $result=processTest($tmp,$name,$job,$pdo);
    $leidos=(int)($result['leidos']??0);
    $total=(int)($result['total']??$leidos);
    $guardados=(int)($result['guardados']??0);
    $omitidos=(int)($result['omitidos']??0);
    $warning=(string)($result['warning']??'');

    writeJob($job,[
        'status'=>'completed',
        'stage'=>'completed',
        'percent'=>100,
        'message'=>'Sincronización completada',
        'detail'=>'Total '.$total.' · guardados/actualizados '.$guardados.' · omitidos '.$omitidos,
        'processed'=>$leidos,
        'total'=>$total,
        'guardados'=>$guardados,
        'omitidos'=>$omitidos,
        'warning'=>$warning,
        'finished_at'=>date('c')
    ]);

    echo json_encode([
        'status'=>'ok',
        'msg'=>'SINCRONIZACION OK. Total: '.$total.
               '. Guardados/actualizados: '.$guardados.
               '. Omitidos: '.$omitidos.'.',
        'registros'=>$guardados,
        'total'=>$total,
        'omitidos'=>$omitidos,
        'warning'=>'',
        'job_id'=>$job
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $cancelled=$e->getMessage()==='__CANCELLED__';
    $msg=$cancelled?'Sincronización cancelada.':$e->getMessage();
    writeJob($job,['status'=>$cancelled?'cancelled':'error','stage'=>$cancelled?'cancelled':'error','percent'=>100,'message'=>$cancelled?'Sincronización cancelada':'No se pudo sincronizar','detail'=>$msg,'finished_at'=>date('c')]);
    failx($msg,$cancelled?409:500,['cancelled'=>$cancelled,'job_id'=>$job]);
}
