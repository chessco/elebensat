<?php
/**
 * Lector ZIP mínimo en PHP puro para XLSX.
 * Soporta entradas STORE (0) y DEFLATE (8), sin cifrado.
 * Evita depender de la extensión php_zip/ZipArchive.
 */
final class SimpleZipArchive
{
    private string $data;
    private array $entries = [];

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 22) throw new RuntimeException('No se pudo abrir el archivo ZIP/XLSX.');
        $this->data = $data;
        $this->readCentralDirectory();
    }

    public function getFromName(string $name): string|false
    {
        $name = str_replace('\\','/',$name);
        if (!isset($this->entries[$name])) return false;
        $e = $this->entries[$name];
        if (($e['flags'] & 0x0001) !== 0) throw new RuntimeException('El XLSX está cifrado y no puede leerse.');
        $off = $e['offset'];
        if (substr($this->data,$off,4) !== "PK\x03\x04") return false;
        $nameLen = $this->u16($off + 26);
        $extraLen = $this->u16($off + 28);
        $start = $off + 30 + $nameLen + $extraLen;
        $compressed = substr($this->data,$start,$e['csize']);
        if ($e['method'] === 0) return $compressed;
        if ($e['method'] === 8) {
            $out = @gzinflate($compressed);
            if ($out === false) throw new RuntimeException('No se pudo descomprimir una parte del XLSX. Verifique zlib en PHP.');
            return $out;
        }
        throw new RuntimeException('El XLSX usa un método ZIP no soportado: '.$e['method']);
    }

    private function readCentralDirectory(): void
    {
        $len = strlen($this->data);
        $start = max(0,$len - 22 - 65535);
        $tail = substr($this->data,$start);
        $rel = strrpos($tail,"PK\x05\x06");
        if ($rel === false) throw new RuntimeException('El archivo XLSX/ZIP no tiene directorio central válido.');
        $eocd = $start + $rel;
        $entries = $this->u16($eocd + 10);
        $cdOffset = $this->u32($eocd + 16);
        $p = $cdOffset;
        for ($i=0;$i<$entries;$i++) {
            if (substr($this->data,$p,4) !== "PK\x01\x02") break;
            $flags = $this->u16($p+8);
            $method = $this->u16($p+10);
            $csize = $this->u32($p+20);
            $usize = $this->u32($p+24);
            $nameLen = $this->u16($p+28);
            $extraLen = $this->u16($p+30);
            $commentLen = $this->u16($p+32);
            $offset = $this->u32($p+42);
            $name = substr($this->data,$p+46,$nameLen);
            $name = str_replace('\\','/',$name);
            $this->entries[$name] = ['flags'=>$flags,'method'=>$method,'csize'=>$csize,'usize'=>$usize,'offset'=>$offset];
            $p += 46 + $nameLen + $extraLen + $commentLen;
        }
        if (!$this->entries) throw new RuntimeException('No se pudieron leer las entradas del XLSX.');
    }

    private function u16(int $offset): int
    {
        $a = unpack('v',substr($this->data,$offset,2)); return (int)($a[1]??0);
    }
    private function u32(int $offset): int
    {
        $a = unpack('V',substr($this->data,$offset,4)); return (int)($a[1]??0);
    }
}
