<?php
/**
 * Lector ligero de archivos Excel 97-2003 (.xls / BIFF8).
 * Diseñado para los listados de nómina del sistema actual.
 * No requiere Composer ni librerías externas.
 */
final class XlsBiff8Reader
{
    private string $data;
    private int $sectorSize = 512;
    private int $miniSectorSize = 64;
    private int $miniCutoff = 4096;
    private array $fat = [];
    private array $miniFat = [];
    private string $rootMiniStream = '';
    private string $workbook = '';
    private array $sst = [];
    private array $sheets = [];

    public function __construct(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo XLS.');
        }
        $this->data = (string)file_get_contents($path);
        if (strlen($this->data) < 512 || substr($this->data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('El archivo no es un XLS válido de Excel 97-2003.');
        }
        $this->openOleWorkbook();
        $this->parseGlobals();
    }

    public function getSheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /** @return array<int,array<int,mixed>> filas/columnas 0-based */
    public function readSheet(string $sheetName): array
    {
        if (!isset($this->sheets[$sheetName])) {
            throw new RuntimeException("No existe la hoja '{$sheetName}' en el archivo.");
        }
        return $this->parseSheetAt((int)$this->sheets[$sheetName]);
    }

    private function u16(string $b, int $o): int
    {
        $v = unpack('v', substr($b, $o, 2));
        return (int)($v[1] ?? 0);
    }

    private function u32(string $b, int $o): int
    {
        $v = unpack('V', substr($b, $o, 4));
        return (int)($v[1] ?? 0);
    }

    private function getSector(int $sid): string
    {
        $off = 512 + ($sid * $this->sectorSize);
        return substr($this->data, $off, $this->sectorSize);
    }

    private function sectorChain(int $start, ?array $fat = null): array
    {
        $fat = $fat ?? $this->fat;
        $end = 0xFFFFFFFE;
        $free = 0xFFFFFFFF;
        $out = [];
        $seen = [];
        $sid = $start;
        while ($sid !== $end && $sid !== $free && $sid >= 0 && $sid < count($fat) && !isset($seen[$sid])) {
            $seen[$sid] = true;
            $out[] = $sid;
            $sid = (int)$fat[$sid];
        }
        return $out;
    }

    private function readRegularStream(int $start, int $size = 0): string
    {
        $buf = '';
        foreach ($this->sectorChain($start) as $sid) {
            $buf .= $this->getSector($sid);
            if ($size > 0 && strlen($buf) >= $size) break;
        }
        return $size > 0 ? substr($buf, 0, $size) : $buf;
    }

    private function readMiniStream(int $start, int $size): string
    {
        $end = 0xFFFFFFFE;
        $free = 0xFFFFFFFF;
        $buf = '';
        $seen = [];
        $sid = $start;
        while ($sid !== $end && $sid !== $free && $sid >= 0 && $sid < count($this->miniFat) && !isset($seen[$sid]) && strlen($buf) < $size) {
            $seen[$sid] = true;
            $off = $sid * $this->miniSectorSize;
            $buf .= substr($this->rootMiniStream, $off, $this->miniSectorSize);
            $sid = (int)$this->miniFat[$sid];
        }
        return substr($buf, 0, $size);
    }

    private function openOleWorkbook(): void
    {
        $this->sectorSize = 1 << $this->u16($this->data, 30);
        $this->miniSectorSize = 1 << $this->u16($this->data, 32);
        $firstDir = $this->u32($this->data, 48);
        $this->miniCutoff = $this->u32($this->data, 56);
        $firstMiniFat = $this->u32($this->data, 60);
        $numMiniFat = $this->u32($this->data, 64);
        $firstDifat = $this->u32($this->data, 68);
        $numDifat = $this->u32($this->data, 72);
        $free = 0xFFFFFFFF;
        $end = 0xFFFFFFFE;

        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($this->data, 76 + ($i * 4));
            if ($sid !== $free) $difat[] = $sid;
        }
        $sid = $firstDifat;
        for ($n = 0; $n < $numDifat && $sid !== $end && $sid !== $free; $n++) {
            $sec = $this->getSector($sid);
            $cnt = intdiv($this->sectorSize, 4) - 1;
            for ($i = 0; $i < $cnt; $i++) {
                $v = $this->u32($sec, $i * 4);
                if ($v !== $free) $difat[] = $v;
            }
            $sid = $this->u32($sec, $this->sectorSize - 4);
        }

        $this->fat = [];
        foreach ($difat as $fatSid) {
            $sec = $this->getSector((int)$fatSid);
            for ($i = 0; $i < intdiv($this->sectorSize, 4); $i++) {
                $this->fat[] = $this->u32($sec, $i * 4);
            }
        }

        $dir = $this->readRegularStream($firstDir);
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $e = substr($dir, $o, 128);
            $nlen = $this->u16($e, 64);
            $name = '';
            if ($nlen >= 2) {
                $raw = substr($e, 0, $nlen - 2);
                $name = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) ?: '';
            }
            $entries[] = [
                'name' => $name,
                'type' => ord($e[66] ?? "\0"),
                'start' => $this->u32($e, 116),
                'size' => $this->u32($e, 120),
            ];
        }

        $root = null;
        $wb = null;
        foreach ($entries as $e) {
            if ($e['type'] === 5) $root = $e;
            if (($e['name'] === 'Workbook' || $e['name'] === 'Book') && $e['type'] === 2) $wb = $e;
        }
        if (!$wb) throw new RuntimeException('El XLS no contiene el flujo Workbook esperado.');

        if ($root && $root['start'] !== $end && $root['size'] > 0) {
            $this->rootMiniStream = $this->readRegularStream((int)$root['start'], (int)$root['size']);
        }

        if ($numMiniFat > 0 && $firstMiniFat !== $end && $firstMiniFat !== $free) {
            $this->miniFat = [];
            foreach ($this->sectorChain($firstMiniFat) as $miniFatSid) {
                $sec = $this->getSector($miniFatSid);
                for ($i = 0; $i < intdiv($this->sectorSize, 4); $i++) {
                    $this->miniFat[] = $this->u32($sec, $i * 4);
                }
            }
        }

        $size = (int)$wb['size'];
        if ($size < $this->miniCutoff && $this->rootMiniStream !== '' && $this->miniFat) {
            $this->workbook = $this->readMiniStream((int)$wb['start'], $size);
        } else {
            $this->workbook = $this->readRegularStream((int)$wb['start'], $size);
        }
        if ($this->workbook === '') throw new RuntimeException('El flujo Workbook del XLS está vacío.');
    }

    private function parseGlobals(): void
    {
        $len = strlen($this->workbook);
        $o = 0;
        while ($o + 4 <= $len) {
            $rid = $this->u16($this->workbook, $o);
            $rlen = $this->u16($this->workbook, $o + 2);
            $p = substr($this->workbook, $o + 4, $rlen);
            if ($rid === 0x00FC) {
                $this->parseSst($p);
            } elseif ($rid === 0x0085 && $rlen >= 8) {
                $offset = $this->u32($p, 0);
                $nameLen = ord($p[6] ?? "\0");
                $flags = ord($p[7] ?? "\0");
                $wide = ($flags & 0x01) !== 0;
                $bytes = $nameLen * ($wide ? 2 : 1);
                $raw = substr($p, 8, $bytes);
                $name = $wide
                    ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) ?: '')
                    : (@iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw);
                $this->sheets[$name] = $offset;
            }
            // El primer EOF cierra globals. Las hojas arrancan por offsets BOUNDSHEET.
            if ($rid === 0x000A && $this->sheets) break;
            $o += 4 + $rlen;
        }
        if (!$this->sheets) throw new RuntimeException('No se detectaron hojas en el XLS.');
    }

    private function parseSst(string $p): void
    {
        if (strlen($p) < 8) return;
        $unique = $this->u32($p, 4);
        $pos = 8;
        $this->sst = [];
        for ($n = 0; $n < $unique && $pos + 3 <= strlen($p); $n++) {
            $cch = $this->u16($p, $pos); $pos += 2;
            $flags = ord($p[$pos] ?? "\0"); $pos++;
            $rich = ($flags & 0x08) !== 0;
            $ext = ($flags & 0x04) !== 0;
            $wide = ($flags & 0x01) !== 0;
            $crun = 0;
            $extLen = 0;
            if ($rich) { $crun = $this->u16($p, $pos); $pos += 2; }
            if ($ext) { $extLen = $this->u32($p, $pos); $pos += 4; }
            $blen = $cch * ($wide ? 2 : 1);
            if ($pos + $blen > strlen($p)) {
                throw new RuntimeException('El XLS usa una tabla de cadenas BIFF8 segmentada no compatible con este importador.');
            }
            $raw = substr($p, $pos, $blen); $pos += $blen;
            $s = $wide
                ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) ?: '')
                : (@iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw);
            $this->sst[] = $s;
            $pos += ($crun * 4) + $extLen;
        }
    }

    private function parseSheetAt(int $offset): array
    {
        $cells = [];
        $len = strlen($this->workbook);
        $o = $offset;
        while ($o + 4 <= $len) {
            $rid = $this->u16($this->workbook, $o);
            $rlen = $this->u16($this->workbook, $o + 2);
            $p = substr($this->workbook, $o + 4, $rlen);
            if ($rid === 0x000A) break;

            if ($rid === 0x00FD && $rlen >= 10) { // LABELSST
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $idx = $this->u32($p, 6);
                $cells[$row][$col] = $this->sst[$idx] ?? '';
            } elseif ($rid === 0x0203 && $rlen >= 14) { // NUMBER
                $row = $this->u16($p, 0); $col = $this->u16($p, 2);
                $u = unpack('e', substr($p, 6, 8));
                $cells[$row][$col] = (float)($u[1] ?? 0.0);
            } elseif ($rid === 0x027E && $rlen >= 10) { // RK
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $rk = $this->u32($p, 6);
                $cells[$row][$col] = $this->decodeRk($rk);
            } elseif ($rid === 0x00BD && $rlen >= 12) { // MULRK
                $row = $this->u16($p, 0); $firstCol = $this->u16($p, 2);
                $lastCol = $this->u16($p, $rlen - 2);
                $pos = 4;
                for ($col = $firstCol; $col <= $lastCol && $pos + 6 <= $rlen - 2; $col++, $pos += 6) {
                    $rk = $this->u32($p, $pos + 2);
                    $cells[$row][$col] = $this->decodeRk($rk);
                }
            } elseif ($rid === 0x0204 && $rlen >= 8) { // LABEL antiguo
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $slen = $this->u16($p, 6);
                $raw = substr($p, 8, $slen);
                $cells[$row][$col] = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw;
            } elseif ($rid === 0x0006 && $rlen >= 14) { // FORMULA: solo resultado numérico
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $res = substr($p, 6, 8);
                if (substr($res, 6, 2) !== "\xFF\xFF") {
                    $u = unpack('e', $res);
                    $cells[$row][$col] = (float)($u[1] ?? 0.0);
                }
            }
            $o += 4 + $rlen;
        }
        ksort($cells);
        foreach ($cells as &$row) ksort($row);
        unset($row);
        return $cells;
    }

    private function decodeRk(int $rk): float
    {
        $mult100 = ($rk & 0x01) !== 0;
        $isInt = ($rk & 0x02) !== 0;
        $raw = $rk & 0xFFFFFFFC;
        if ($isInt) {
            $signed = ($raw & 0x80000000) ? ($raw - 4294967296) : $raw;
            $val = (float)($signed >> 2);
        } else {
            $bytes = pack('V2', 0, $raw);
            $u = unpack('e', $bytes);
            $val = (float)($u[1] ?? 0.0);
        }
        return $mult100 ? ($val / 100.0) : $val;
    }
}
