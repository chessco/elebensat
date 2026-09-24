<?php
require_once __DIR__ . '/XlsBiff8Reader.php';
require_once __DIR__ . '/XlsxSimpleReader.php';

final class NominaArchivoParser
{
    private array $rows;
    private string $sheetName;
    private string $extension;

    public function __construct(string $path, string $sheetName = 'Sheet', ?string $extension = null)
    {
        $this->extension = strtolower($extension ?: pathinfo($path, PATHINFO_EXTENSION));
        if ($this->extension === 'xlsx') $reader = new XlsxSimpleReader($path);
        elseif ($this->extension === 'xls') $reader = new XlsBiff8Reader($path);
        else throw new RuntimeException('Formato no soportado. Use XLS o XLSX.');

        $names = $reader->getSheetNames();
        if (!in_array($sheetName, $names, true)) {
            // Algunos archivos pueden renombrar la hoja; si solo existe una, úsela.
            if (count($names) === 1) $sheetName = (string)$names[0];
            else throw new RuntimeException("El archivo no contiene la hoja requerida '{$sheetName}'.");
        }
        $this->sheetName = $sheetName;
        $this->rows = $reader->readSheet($sheetName);
    }

    public function parse(string $rfcEmpresaEsperado = '', string $empresaEsperada = ''): array
    {
        $header = $this->detectEmployeeHeader();
        $headerRow = $header['row'];
        $cols = $header['cols'];
        $meta = $this->detectMetadata($headerRow, $rfcEmpresaEsperado, $empresaEsperada);
        $employees = $this->extractEmployees($headerRow, $cols, $meta['formato_archivo']);

        $errors = [];
        $isAcumulado = $meta['formato_archivo'] === 'ACUMULADO_PERIODOS';
        if (!$isAcumulado && $meta['rfc_empresa'] === '') $errors[] = 'No se encontró el RFC de la empresa en el encabezado.';
        if ($rfcEmpresaEsperado !== '' && $meta['rfc_empresa'] !== '' && self::cleanRfc($meta['rfc_empresa']) !== self::cleanRfc($rfcEmpresaEsperado)) {
            $errors[] = 'El RFC del archivo no corresponde a la empresa activa.';
        }
        if (!$meta['anio']) $errors[] = 'No se encontró el año de la nómina.';
        if (!$meta['periodo_desde'] || !$meta['periodo_hasta']) $errors[] = 'No se encontró el periodo o rango de periodos de la nómina.';
        if ($meta['periodo_desde'] && $meta['periodo_hasta'] && $meta['periodo_desde'] > $meta['periodo_hasta']) $errors[] = 'El rango de periodos es inválido.';
        if (!$isAcumulado && (!$meta['fecha_desde'] || !$meta['fecha_hasta'])) $errors[] = 'No se encontró el rango de fechas Del / Al.';
        if ($meta['fecha_desde'] && $meta['fecha_hasta'] && $meta['fecha_desde'] > $meta['fecha_hasta']) $errors[] = 'El rango de fechas del archivo es inválido.';
        if (!$employees) $errors[] = 'No se encontraron empleados válidos debajo del encabezado.';
        if ($isAcumulado && (!isset($cols['total_percepciones']) || !isset($cols['total_deducciones']))) {
            $errors[] = 'El acumulado debe contener TOTALPER - Total de percepciones y TOTALDED - Total de deducciones.';
        }

        $totalNeto = $totalPer = $totalDed = 0.0;
        $renglonesAdicionales = 0;
        foreach ($employees as $e) {
            $totalNeto += (float)$e['neto'];
            $totalPer += (float)$e['total_percepciones'];
            $totalDed += (float)$e['total_deducciones'];
            $renglonesAdicionales += (int)($e['renglones_adicionales'] ?? 0);
        }

        return [
            'sheet' => $this->sheetName,
            'extension' => $this->extension,
            'header_row' => $headerRow + 1,
            'metadata' => $meta,
            'employees' => $employees,
            'totals' => [
                'empleados' => count($employees),
                'percepciones' => round($totalPer, 2),
                'deducciones' => round($totalDed, 2),
                'neto' => round($totalNeto, 2),
                'renglones_adicionales' => $renglonesAdicionales,
            ],
            'valid' => !$errors,
            'errors' => $errors,
        ];
    }

    /** Copia íntegra de valores de la hoja, sin consolidar renglones ni conceptos.
     * Las fórmulas, estilos y demás hojas se conservan en el archivo original.
     */
    public function fullReport(array $parsed): array
    {
        $width = 0;
        foreach ($this->rows as $row) if ($row) $width = max($width, max(array_keys($row)) + 1);
        $height = $this->rows ? max(array_keys($this->rows)) + 1 : 0;
        $header = (int)$parsed['header_row'];
        $columns = [];
        for ($i=0;$i<$width;$i++) {
            $n=$i+1; $letter='';
            while ($n>0) {$n--; $letter=chr(65+$n%26).$letter; $n=intdiv($n,26);}
            $label=(string)($this->rows[$header-1][$i]??'');
            $code=preg_match('/^\s*([A-Za-z]+[0-9]*|TOTALPER|TOTALDED|NETO)\s+-\s+/u',$label,$m)?$m[1]:null;
            $columns[]=['indice'=>$i,'letra'=>$letter,'encabezado'=>$label,'codigo'=>$code];
        }
        $links=[];
        foreach ($parsed['employees'] as $employee) {
            foreach (($employee['renglones']??[$employee['renglon']]) as $j=>$r) {
                $links[(int)$r]=['numero_empleado'=>$employee['numero_empleado'],'rfc'=>$employee['rfc'],'clase'=>$j===0?'EMPLEADO':'ADICIONAL'];
            }
        }
        $rows=[];
        for ($r=1;$r<=$height;$r++) {
            $values=array_fill(0,$width,null);
            foreach (($this->rows[$r-1]??[]) as $c=>$value) $values[$c]=$value;
            $link=$links[$r]??null;
            $class=$link['clase']??($r===$header?'ENCABEZADO':($r<$header?'METADATO':(self::rowIsEmpty($values)?'VACIO':'OTRO')));
            $rows[]=['renglon'=>$r,'clase'=>$class,'numero_empleado'=>$link['numero_empleado']??null,'rfc'=>$link['rfc']??null,'valores'=>$values];
        }
        return ['version'=>1,'hoja'=>$this->sheetName,'fila_encabezado'=>$header,'total_columnas'=>$width,'total_filas'=>$height,'columnas'=>$columns,'filas'=>$rows];
    }

    private function detectEmployeeHeader(): array
    {
        $best = null;
        $maxRow = $this->rows ? max(array_keys($this->rows)) : 0;
        $limit = min($maxRow, 80);
        for ($r = 0; $r <= $limit; $r++) {
            $map = [];
            foreach (($this->rows[$r] ?? []) as $c => $value) {
                $n = self::label((string)$value);
                if ($n === '') continue;
                if (in_array($n, ['NUMERO DE EMPLEADO','NO DE EMPLEADO','NUM EMPLEADO','ID EMPLEADO','NUMERO EMPLEADO','NO EMPLEADO'], true)) $map['numero_empleado'] = $c;
                elseif ($n === 'NOMBRE') $map['nombre'] = $c;
                elseif (in_array($n, ['APELLIDO PATERNO','APELLIDO PAT','APELLIDO P'], true)) $map['apellido_paterno'] = $c;
                elseif (in_array($n, ['APELLIDO MATERNO','APELLIDO MAT','APELLIDO M'], true)) $map['apellido_materno'] = $c;
                elseif ($n === 'RFC') $map['rfc'] = $c;
                elseif (in_array($n, ['TIPO NOMINA PROCESADO','TIPO DE NOMINA PROCESADA'], true)) $map['tipo_nomina_procesada'] = $c;
            }
            // El tipo de nómina es informativo; no debe sustituir ninguno de
            // los cinco campos obligatorios al calificar el encabezado.
            $requiredHeaderKeys = ['numero_empleado','nombre','apellido_paterno','apellido_materno','rfc'];
            $score = count(array_intersect_key($map,array_flip($requiredHeaderKeys)));
            if ($score >= 5 && (!$best || $score > $best['score'])) $best = ['row'=>$r,'cols'=>$map,'score'=>$score];
        }
        if (!$best) throw new RuntimeException('No se localizaron los encabezados de empleados (Id/Número de Empleado, Nombre, Apellidos y RFC).');

        // Conceptos pueden estar en la misma fila o unas filas arriba/abajo.
        for ($r = max(0, $best['row'] - 4); $r <= min($best['row'] + 2, $maxRow); $r++) {
            foreach (($this->rows[$r] ?? []) as $c => $value) {
                $n = self::label((string)$value);
                if ($n === 'NETO' || str_starts_with($n, 'NETO -')) $best['cols']['neto'] = $c;
                if ($n === 'TOTALPER' || str_starts_with($n, 'TOTALPER -')) $best['cols']['total_percepciones'] = $c;
                if ($n === 'TOTALDED' || str_starts_with($n, 'TOTALDED -')) $best['cols']['total_deducciones'] = $c;
            }
        }
        if (!isset($best['cols']['neto']) && !(isset($best['cols']['total_percepciones']) && isset($best['cols']['total_deducciones']))) {
            throw new RuntimeException('No se encontró NETO ni las columnas TOTALPER/TOTALDED en el encabezado de nómina.');
        }
        return $best;
    }

    private function detectMetadata(int $headerRow, string $rfcEsperado, string $empresaEsperada): array
    {
        $searchRows = min(max(0, $headerRow - 1), 40);
        $meta = [
            'empresa'=>'','rfc_empresa'=>'','rfc_inferido'=>false,
            'tipo_nomina_empleado'=>'','tipo_nomina_procesada'=>'',
            'anio'=>null,'periodo'=>null,'periodo_desde'=>null,'periodo_hasta'=>null,
            'fecha_desde'=>null,'fecha_hasta'=>null,
            'formato_archivo'=>'SEMANAL','tipo_sugerido'=>'SEMANAL','requiere_fechas'=>false,
        ];

        $labelMap = [
            'TIPO DE NOMINA DEL EMPLEADO'=>'tipo_nomina_empleado',
            'TIPO DE NOMINA PROCESADA'=>'tipo_nomina_procesada',
            'ANO'=>'anio','PERIODO'=>'periodo',
            'PERIODO INICIAL'=>'periodo_desde','PERIODO FINAL'=>'periodo_hasta',
            'DEL'=>'fecha_desde','AL'=>'fecha_hasta',
        ];
        for ($r=0;$r<=$searchRows;$r++) {
            foreach (($this->rows[$r] ?? []) as $c=>$value) {
                $n = self::label((string)$value);
                if (!isset($labelMap[$n])) continue;
                $k = $labelMap[$n]; $v = $this->nearestValue($r,$c);
                if (in_array($k,['anio','periodo','periodo_desde','periodo_hasta'],true)) $meta[$k] = $v!==null && $v!=='' ? (int)round((float)$v) : null;
                elseif (in_array($k,['fecha_desde','fecha_hasta'],true)) $meta[$k] = self::excelDate($v);
                else $meta[$k] = trim((string)$v);
            }
        }

        if ($meta['periodo_desde'] || $meta['periodo_hasta']) {
            $meta['formato_archivo'] = 'ACUMULADO_PERIODOS';
            $meta['tipo_sugerido'] = 'ACUMULADO_PERIODOS';
            $meta['requiere_fechas'] = true;
            if (!$meta['periodo_desde']) $meta['periodo_desde'] = $meta['periodo_hasta'];
            if (!$meta['periodo_hasta']) $meta['periodo_hasta'] = $meta['periodo_desde'];
            $meta['periodo'] = $meta['periodo_hasta']; // compatibilidad con consultas existentes
        } else {
            $meta['periodo_desde'] = $meta['periodo'];
            $meta['periodo_hasta'] = $meta['periodo'];
        }

        // RFC de empresa: en el formato semanal se busca en el bloque superior.
        $candidateRfc = ''; $candidateCompany = '';
        for ($r=0;$r<=min($searchRows,18);$r++) {
            foreach (($this->rows[$r] ?? []) as $c=>$value) {
                $s = self::cleanRfc((string)$value);
                if ($s!=='' && preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u',$s)) {
                    if ($rfcEsperado!=='' && $s===self::cleanRfc($rfcEsperado)) $candidateRfc=$s;
                    elseif ($candidateRfc==='') $candidateRfc=$s;
                    for ($rr=max(0,$r-3);$rr<=$r;$rr++) {
                        $v=trim((string)($this->rows[$rr][$c]??''));
                        if ($v!=='' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u',self::cleanRfc($v)) && strlen($v)>4) {$candidateCompany=$v;break;}
                    }
                }
            }
        }
        if ($candidateRfc==='' && $meta['formato_archivo']==='ACUMULADO_PERIODOS' && $rfcEsperado!=='') {
            // El acumulado de GKM no incluye RFC de empresa; se amarra de forma segura a la empresa activa.
            $candidateRfc = self::cleanRfc($rfcEsperado);
            $meta['rfc_inferido'] = true;
        }
        $meta['rfc_empresa']=$candidateRfc;
        if ($candidateCompany==='' && $empresaEsperada!=='') $candidateCompany=trim($empresaEsperada);
        $meta['empresa']=$candidateCompany;
        return $meta;
    }

    private function nearestValue(int $row, int $col): mixed
    {
        for ($d=1;$d<=8;$d++) {
            if (array_key_exists($col+$d,$this->rows[$row]??[])) {
                $v=$this->rows[$row][$col+$d]; if ($v!=='' && $v!==null) return $v;
            }
        }
        for ($d=1;$d<=3;$d++) {
            if (array_key_exists($col,$this->rows[$row+$d]??[])) {
                $v=$this->rows[$row+$d][$col]; if ($v!=='' && $v!==null) return $v;
            }
        }
        return null;
    }

    private function extractEmployees(int $headerRow, array $cols, string $format): array
    {
        $out=[]; $maxRow=$this->rows ? max(array_keys($this->rows)) : $headerRow;
        $lastEmployeeIndex=null; $employeeRowsStarted=false;
        for ($r=$headerRow+1;$r<=$maxRow;$r++) {
            $row=$this->rows[$r]??[];

            // Una columna A vacía no significa fin: Workbeat puede colocar en el
            // siguiente renglón otro tipo de nómina del mismo empleado. El fin
            // real se reconoce únicamente cuando TODO el renglón está vacío.
            if (self::rowIsEmpty($row)) {
                if ($employeeRowsStarted) break;
                continue;
            }

            $num=trim((string)($row[$cols['numero_empleado']]??''));
            $rfc=self::cleanRfc((string)($row[$cols['rfc']]??''));
            $nombre=trim((string)($row[$cols['nombre']]??''));
            $per=isset($cols['total_percepciones']) ? self::money($row[$cols['total_percepciones']]??0) : 0.0;
            $ded=isset($cols['total_deducciones']) ? self::money($row[$cols['total_deducciones']]??0) : 0.0;
            if ($format==='ACUMULADO_PERIODOS') $neto=round($per-$ded,2);
            else $neto=isset($cols['neto']) ? self::money($row[$cols['neto']]??0) : round($per-$ded,2);

            $validEmployee = $num!==''
                && stripos($num,'TOTAL')===false
                && $nombre!==''
                && preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u',$rfc);

            if (!$validEmployee) {
                // Renglón complementario del último empleado: las columnas de
                // identidad vienen totalmente vacías, pero desde Tipo de Nómina
                // y/o TOTALPER/TOTALDED sí contiene el otro acumulado.
                $identityBlank = true;
                foreach (['numero_empleado','nombre','apellido_paterno','apellido_materno','rfc'] as $identityColumn) {
                    if (trim((string)($row[$cols[$identityColumn]]??''))!=='') {
                        $identityBlank = false;
                        break;
                    }
                }
                $tipoProcesado = isset($cols['tipo_nomina_procesada'])
                    ? trim((string)($row[$cols['tipo_nomina_procesada']]??''))
                    : '';
                $hasTotals = (isset($cols['total_percepciones']) && trim((string)($row[$cols['total_percepciones']]??''))!=='')
                    || (isset($cols['total_deducciones']) && trim((string)($row[$cols['total_deducciones']]??''))!=='')
                    || (isset($cols['neto']) && trim((string)($row[$cols['neto']]??''))!=='');

                if ($format==='ACUMULADO_PERIODOS' && $lastEmployeeIndex!==null && $identityBlank && ($tipoProcesado!=='' || $hasTotals)) {
                    $out[$lastEmployeeIndex]['total_percepciones'] = round((float)$out[$lastEmployeeIndex]['total_percepciones'] + $per, 2);
                    $out[$lastEmployeeIndex]['total_deducciones'] = round((float)$out[$lastEmployeeIndex]['total_deducciones'] + $ded, 2);
                    $out[$lastEmployeeIndex]['neto'] = round((float)$out[$lastEmployeeIndex]['total_percepciones'] - (float)$out[$lastEmployeeIndex]['total_deducciones'], 2);
                    $out[$lastEmployeeIndex]['renglones'][] = $r + 1;
                    $out[$lastEmployeeIndex]['renglones_texto'] = implode(', ', $out[$lastEmployeeIndex]['renglones']);
                    $out[$lastEmployeeIndex]['renglones_adicionales']++;
                    if ($tipoProcesado!=='' && !in_array($tipoProcesado,$out[$lastEmployeeIndex]['tipos_nomina'],true)) {
                        $out[$lastEmployeeIndex]['tipos_nomina'][] = $tipoProcesado;
                    }
                    $out[$lastEmployeeIndex]['tipos_nomina_texto'] = implode(' + ', $out[$lastEmployeeIndex]['tipos_nomina']);
                }
                continue;
            }

            $tipoProcesado = isset($cols['tipo_nomina_procesada'])
                ? trim((string)($row[$cols['tipo_nomina_procesada']]??''))
                : '';
            $out[]=[
                'renglon'=>$r+1,'numero_empleado'=>$num,'nombre'=>$nombre,
                'apellido_paterno'=>trim((string)($row[$cols['apellido_paterno']]??'')),
                'apellido_materno'=>trim((string)($row[$cols['apellido_materno']]??'')),
                'rfc'=>$rfc,'total_percepciones'=>$per,'total_deducciones'=>$ded,'neto'=>$neto,
                'renglones'=>[$r+1],'renglones_texto'=>(string)($r+1),'renglones_adicionales'=>0,
                'tipos_nomina'=>$tipoProcesado!==''?[$tipoProcesado]:[],
                'tipos_nomina_texto'=>$tipoProcesado,
            ];
            $lastEmployeeIndex=array_key_last($out);
            $employeeRowsStarted=true;
        }
        return $out;
    }

    private static function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value)!=='') return false;
        }
        return true;
    }

    private static function money(mixed $v): float
    {
        if (is_int($v)||is_float($v)) return round((float)$v,2);
        $s=str_replace([',','$',' '],'',trim((string)$v));
        return ($s!=='' && is_numeric($s)) ? round((float)$s,2) : 0.0;
    }

    private static function excelDate(mixed $v): ?string
    {
        if ($v===null||$v==='') return null;
        if (is_numeric($v)) {
            $serial=(int)floor((float)$v); if ($serial<1||$serial>100000) return null;
            $base=new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC'));
            return $base->modify('+'.$serial.' days')->format('Y-m-d');
        }
        $s=trim((string)$v);
        foreach (['d/m/Y','Y-m-d','d-m-Y'] as $fmt) { $d=DateTimeImmutable::createFromFormat('!'.$fmt,$s); if($d)return $d->format('Y-m-d'); }
        return null;
    }

    private static function cleanRfc(string $s): string
    {
        $s=self::norm($s,false); return preg_replace('/[^A-Z0-9Ñ&]/u','',$s)??'';
    }

    private static function label(string $s): string
    {
        $s=self::norm($s,true);
        $s=preg_replace('/\s*:\s*$/u','',$s)??$s;
        $s=preg_replace('/\s+/u',' ',trim($s))??$s;
        return trim($s);
    }

    private static function norm(string $s,bool $stripAccents=true): string
    {
        $s=trim($s);
        if($stripAccents)$s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','ñ'=>'n','Ñ'=>'N']);
        $s=function_exists('mb_strtoupper')?mb_strtoupper($s,'UTF-8'):strtoupper($s);
        $s=preg_replace('/\s+/u',' ',$s)??$s; return trim($s);
    }
}
