<?php
/**
 * Escritor XLSX mínimo en PHP puro.
 * Genera ZIP sin compresión (STORE) para no depender de php_zip/ZipArchive.
 * Diseñado para reportes tabulares grandes mediante un worksheet XML temporal.
 */
final class XlsxSimpleWriter
{
    private $fh;
    private array $entries = [];
    private int $offset = 0;

    public function __construct(string $outputPath)
    {
        $this->fh = @fopen($outputPath, 'wb');
        if (!$this->fh) throw new RuntimeException('No se pudo crear el archivo XLSX temporal.');
    }

    public function addString(string $name, string $data): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_s_');
        if ($tmp === false) throw new RuntimeException('No se pudo crear temporal XLSX.');
        file_put_contents($tmp, $data);
        try { $this->addFile($name, $tmp); }
        finally { @unlink($tmp); }
    }

    public function addFile(string $name, string $path): void
    {
        if (!is_file($path)) throw new RuntimeException('No existe una parte requerida del XLSX: '.$name);
        $name = str_replace('\\', '/', $name);
        $size = (int)filesize($path);
        $crcHex = hash_file('crc32b', $path);
        $crc = (int)hexdec($crcHex ?: '0');
        [$dosTime, $dosDate] = $this->dosDateTime();
        $nameLen = strlen($name);
        $localOffset = $this->offset;

        $header = pack('VvvvvvVVVvv',
            0x04034b50, 20, 0, 0, $dosTime, $dosDate,
            $crc, $size, $size, $nameLen, 0
        ) . $name;
        $this->write($header);

        $in = fopen($path, 'rb');
        if (!$in) throw new RuntimeException('No se pudo leer una parte temporal del XLSX.');
        while (!feof($in)) {
            $buf = fread($in, 1024 * 1024);
            if ($buf === false) { fclose($in); throw new RuntimeException('Error leyendo temporal XLSX.'); }
            if ($buf !== '') $this->write($buf);
        }
        fclose($in);

        $this->entries[] = compact('name','crc','size','dosTime','dosDate','localOffset');
    }

    public function close(): void
    {
        if (!$this->fh) return;
        $centralOffset = $this->offset;
        foreach ($this->entries as $e) {
            $name = $e['name'];
            $nameLen = strlen($name);
            $central = pack('VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, 0, 0, $e['dosTime'], $e['dosDate'],
                $e['crc'], $e['size'], $e['size'],
                $nameLen, 0, 0, 0, 0, 0, $e['localOffset']
            ) . $name;
            $this->write($central);
        }
        $centralSize = $this->offset - $centralOffset;
        $n = count($this->entries);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $centralSize, $centralOffset, 0);
        $this->write($eocd);
        fclose($this->fh);
        $this->fh = null;
    }

    public function __destruct() { if ($this->fh) $this->close(); }

    private function write(string $data): void
    {
        $len = strlen($data);
        if ($len && fwrite($this->fh, $data) !== $len) throw new RuntimeException('No se pudo escribir el XLSX.');
        $this->offset += $len;
    }

    private function dosDateTime(): array
    {
        $t = getdate();
        $year = max(1980, min(2107, (int)$t['year']));
        $dosTime = ((int)$t['hours'] << 11) | ((int)$t['minutes'] << 5) | ((int)$t['seconds'] >> 1);
        $dosDate = (($year - 1980) << 9) | ((int)$t['mon'] << 5) | (int)$t['mday'];
        return [$dosTime, $dosDate];
    }
}
