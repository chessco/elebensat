<?php
require_once __DIR__ . '/SimpleZipArchive.php';
/**
 * Lector XLSX ligero, sin dependencias externas ni extensiones php_zip/SimpleXML.
 * Usa un lector ZIP puro PHP y extracción XML acotada a la estructura XLSX.
 * Devuelve filas/columnas con índices base 0, compatible con XlsBiff8Reader.
 */
final class XlsxSimpleReader
{
    private string $path;
    private SimpleZipArchive $zip;
    private array $sheetMap = [];
    private array $sharedStrings = [];

    public function __construct(string $path)
    {
        if (!is_file($path)) throw new RuntimeException('No se encontró el archivo XLSX.');
        $this->path = $path;
        $this->zip = new SimpleZipArchive($path);
        $this->loadWorkbook();
    }

    public function getSheetNames(): array { return array_keys($this->sheetMap); }

    public function readSheet(string $sheetName): array
    {
        if (!isset($this->sheetMap[$sheetName])) throw new RuntimeException("No existe la hoja '{$sheetName}' en el XLSX.");
        $xml = $this->zip->getFromName($this->sheetMap[$sheetName]);
        if ($xml === false) throw new RuntimeException('No se pudo leer la hoja del XLSX.');
        $out = [];
        // Una fila vacía <row .../> no debe absorber la fila siguiente:
        // de lo contrario se asignan sus encabezados al renglón anterior.
        if (!preg_match_all('/<row\b([^>]*?)\/>|<row\b([^>]*)>(.*?)<\/row>/si', $xml, $rows, PREG_SET_ORDER)) return $out;
        foreach ($rows as $ri => $rm) {
            if (!isset($rm[3])) continue;
            $rAttr = $this->attr($rm[2], 'r');
            $rIndex = $rAttr !== '' ? max(0,(int)$rAttr-1) : $ri;
            $rowXml = $rm[3];
            if (!preg_match_all('/<c\b([^>]*?)\s*\/>|<c\b([^>]*)>(.*?)<\/c>/si', $rowXml, $cells, PREG_SET_ORDER)) continue;
            foreach ($cells as $cm) {
                $attrs = ($cm[1] ?? '') !== '' ? $cm[1] : ($cm[2] ?? '');
                $inside = ($cm[1] ?? '') !== '' ? '' : ($cm[3] ?? '');
                $ref = $this->attr($attrs,'r');
                $type = $this->attr($attrs,'t');
                $col = $this->columnIndex($ref);
                $value = null;
                if ($type === 'inlineStr') {
                    $value = $this->concatTextNodes($inside);
                } elseif (preg_match('/<v\b[^>]*>(.*?)<\/v>/si',$inside,$vm)) {
                    $raw = $this->xmlDecode(strip_tags($vm[1]));
                    if ($type === 's') $value = $this->sharedStrings[(int)$raw] ?? '';
                    elseif ($type === 'b') $value = trim($raw) === '1';
                    elseif ($type === 'str' || $type === 'e') $value = $raw;
                    else {
                        $raw = trim($raw);
                        $value = is_numeric($raw) ? (float)$raw : $raw;
                        if (is_float($value) && floor($value) == $value) $value = (int)$value;
                    }
                } elseif ($type === 'inlineStr') {
                    $value = $this->concatTextNodes($inside);
                }
                if ($value !== null) $out[$rIndex][$col] = $value;
            }
            if (isset($out[$rIndex])) ksort($out[$rIndex]);
        }
        ksort($out);
        return $out;
    }

    private function loadWorkbook(): void
    {
        $shared = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($shared !== false && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si',$shared,$items,PREG_SET_ORDER)) {
            foreach ($items as $it) $this->sharedStrings[] = $this->concatTextNodes($it[1]);
        }

        $wb = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) throw new RuntimeException('El XLSX no contiene la estructura de libro esperada.');

        $targets = [];
        if (preg_match_all('/<Relationship\b([^>]*)\/?\s*>/si',$rels,$relsMatches,PREG_SET_ORDER)) {
            foreach ($relsMatches as $rm) {
                $id = $this->attr($rm[1],'Id');
                $target = $this->attr($rm[1],'Target');
                if ($id !== '' && $target !== '') $targets[$id] = $target;
            }
        }
        if (preg_match_all('/<sheet\b([^>]*)\/?\s*>/si',$wb,$sheetMatches,PREG_SET_ORDER)) {
            foreach ($sheetMatches as $sm) {
                $name = $this->attr($sm[1],'name');
                $rid = $this->attr($sm[1],'r:id');
                $target = $targets[$rid] ?? '';
                if ($name === '' || $target === '') continue;
                $full = str_starts_with($target,'/') ? ltrim($target,'/') : 'xl/'.ltrim($target,'/');
                $full = preg_replace('#(^|/)\./#','$1',$full) ?? $full;
                while (str_contains($full,'../')) $full = preg_replace('#[^/]+/\.\./#','',$full,1) ?? $full;
                $this->sheetMap[$this->xmlDecode($name)] = $full;
            }
        }
        if (!$this->sheetMap) throw new RuntimeException('No se encontraron hojas en el XLSX.');
    }

    private function attr(string $attrs,string $name): string
    {
        $q = preg_quote($name,'/');
        if (preg_match('/(?:^|\s)'.$q.'\s*=\s*(["\'])(.*?)\1/si',$attrs,$m)) return $this->xmlDecode($m[2]);
        return '';
    }

    private function concatTextNodes(string $xml): string
    {
        $parts=[];
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si',$xml,$ms,PREG_SET_ORDER)) {
            foreach($ms as $m)$parts[]=$this->xmlDecode(strip_tags($m[1]));
        }
        return implode('',$parts);
    }

    private function xmlDecode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnIndex(string $cellRef): int
    {
        if (!preg_match('/^([A-Z]+)/i',$cellRef,$m)) return 0;
        $s=strtoupper($m[1]);$n=0;
        for($i=0,$l=strlen($s);$i<$l;$i++)$n=$n*26+(ord($s[$i])-64);
        return max(0,$n-1);
    }
}
