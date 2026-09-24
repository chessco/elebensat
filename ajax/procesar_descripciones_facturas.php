<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
seguridad_exigir_sesion($pdo, true);
require_once '../includes/permisos_documentos.php';

try {
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) throw new RuntimeException('Empresa no seleccionada.');

    $uuids = $_POST['uuids'] ?? [];
    if (is_string($uuids)) {
        $tmp = json_decode($uuids, true);
        if (is_array($tmp)) $uuids = $tmp;
    }
    if (!is_array($uuids)) $uuids = [];
    $uuids = array_values(array_unique(array_filter(array_map(static function($u){
        $u = strtoupper(trim((string)$u));
        return preg_match('/^[0-9A-F-]{36}$/', $u) ? $u : '';
    }, $uuids))));
    if (!$uuids) {
        echo json_encode(['ok'=>true,'data'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($uuids) > 60) throw new RuntimeException('El lote excede 60 CFDI.');

    // Seguridad: sólo consultar UUID pertenecientes a la empresa activa.
    $ph = [];
    $params = [':empresa'=>$idEmpresa];
    foreach ($uuids as $i=>$uuid) {
        $k = ':u'.$i;
        $ph[] = $k;
        $params[$k] = $uuid;
    }

    $sql = "SELECT f.uuid, d.xml_base64
            FROM facturas f
            LEFT JOIN facturas_datos d ON d.uuid=f.uuid
            WHERE f.id_empresa=:empresa AND f.uuid IN (".implode(',', $ph).")";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $resultado = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $uuid = strtoupper((string)$r['uuid']);
        $desc = ['', '', ''];
        $xmlB64 = trim((string)($r['xml_base64'] ?? ''));
        if ($xmlB64 !== '') {
            $raw = base64_decode($xmlB64, true);
            if ($raw === false) $raw = $xmlB64;
            $raw = ltrim((string)$raw, "\xEF\xBB\xBF \r\n\t");
            if ($raw !== '') {
                $prev = libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                if (@$dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    $xp = new DOMXPath($dom);
                    $nodes = $xp->query('//*[local-name()="Concepto"]');
                    if ($nodes) {
                        $i = 0;
                        foreach ($nodes as $n) {
                            if ($i >= 3) break;
                            $v = trim((string)$n->attributes?->getNamedItem('Descripcion')?->nodeValue);
                            if ($v === '') $v = trim((string)$n->attributes?->getNamedItem('descripcion')?->nodeValue);
                            $desc[$i] = $v;
                            $i++;
                        }
                    }
                }
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
            }
        }
        $resultado[$uuid] = ['desc1'=>$desc[0], 'desc2'=>$desc[1], 'desc3'=>$desc[2]];
    }

    // Devolver también UUID sin XML/registro para que el cliente pueda marcar el lote como terminado.
    foreach ($uuids as $uuid) {
        if (!isset($resultado[$uuid])) $resultado[$uuid] = ['desc1'=>'','desc2'=>'','desc3'=>''];
    }

    echo json_encode(['ok'=>true,'data'=>$resultado], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    seguridad_log_error($e, 'procesar_descripciones_facturas');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudieron leer las descripciones de los XML.'], JSON_UNESCAPED_UNICODE);
}
