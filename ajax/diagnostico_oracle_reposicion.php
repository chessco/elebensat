<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';

function rows(PDO $pdo, string $sql, array $params=[]): array {
    $st=$pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

try {
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $factura=strtoupper(trim((string)($_GET['factura']??'')));
    $invoiceId=trim((string)($_GET['invoice_id']??''));

    if($factura==='' && $invoiceId===''){
        // Caso de prueba que vimos en pantalla; puede cambiarse por querystring.
        $invoiceId='3846276';
    }

    if($invoiceId!==''){
        $st=$pdo->prepare('SELECT * FROM oracle_facturas WHERE id_empresa=? AND oracle_invoice_id=? LIMIT 1');
        $st->execute([$idEmpresa,$invoiceId]);
    }else{
        $st=$pdo->prepare('SELECT * FROM oracle_facturas WHERE id_empresa=? AND UPPER(TRIM(invoice_number))=? LIMIT 1');
        $st->execute([$idEmpresa,$factura]);
    }
    $inv=$st->fetch(PDO::FETCH_ASSOC);
    if(!$inv){
        throw new RuntimeException('No se encontró la factura/reposición en oracle_facturas para la empresa activa.');
    }

    $invoiceId=(string)$inv['oracle_invoice_id'];
    $factura=strtoupper(trim((string)$inv['invoice_number']));
    $raw=json_decode((string)($inv['datos_origen_json']??''),true);
    if(!is_array($raw)) $raw=[];

    $referenceKeyOne=trim((string)($raw['ReferenceKeyOne']??''));
    $expNumerico=preg_replace('/^EXP/i','',$factura);
    $expNumerico=ltrim((string)$expNumerico,'0');

    // 1) Lo que usa hoy la pantalla de detalle.
    $normalizados=rows($pdo,
        "SELECT id,oracle_expense_id,oracle_expense_report_id,person_name,merchant_name,
                expense_type,description,receipt_amount,receipt_date,creation_date,uuid_cfdi,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceId')) AS origen_invoice_id,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceNumber')) AS origen_invoice_number,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.ReferenceKeyOne')) AS origen_reference_key_one
           FROM oracle_gastos
          WHERE id_empresa=?
            AND CONCAT('EXP',RIGHT(LPAD(TRIM(oracle_expense_report_id),15,'0'),12))=?
          ORDER BY id",
        [$idEmpresa,$factura]
    );

    // 2) Relación directa guardada por nuestro importador Molinos.
    $porOrigenInvoiceId=rows($pdo,
        "SELECT id,oracle_expense_id,oracle_expense_report_id,person_name,merchant_name,
                expense_type,description,receipt_amount,receipt_date,creation_date,uuid_cfdi,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceId')) AS origen_invoice_id,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceNumber')) AS origen_invoice_number,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.ReferenceKeyOne')) AS origen_reference_key_one
           FROM oracle_gastos
          WHERE id_empresa=?
            AND JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceId'))=?
          ORDER BY id",
        [$idEmpresa,$invoiceId]
    );

    $porOrigenFactura=rows($pdo,
        "SELECT id,oracle_expense_id,oracle_expense_report_id,person_name,merchant_name,
                expense_type,description,receipt_amount,receipt_date,creation_date,uuid_cfdi,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceId')) AS origen_invoice_id,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceNumber')) AS origen_invoice_number,
                JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.ReferenceKeyOne')) AS origen_reference_key_one
           FROM oracle_gastos
          WHERE id_empresa=?
            AND UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceNumber'))))=?
          ORDER BY id",
        [$idEmpresa,$factura]
    );

    // 3) Coincidencias exactas por ReferenceKeyOne / número derivado de EXP.
    $ids=[];
    foreach([$referenceKeyOne,$expNumerico] as $v){
        $v=trim((string)$v);
        if($v!=='' && !in_array($v,$ids,true)) $ids[]=$v;
    }
    $porReport=[];
    if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $porReport=rows($pdo,
            "SELECT id,oracle_expense_id,oracle_expense_report_id,person_name,merchant_name,
                    expense_type,description,receipt_amount,receipt_date,creation_date,uuid_cfdi,
                    JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceId')) AS origen_invoice_id,
                    JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.InvoiceNumber')) AS origen_invoice_number,
                    JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.reposicion.ReferenceKeyOne')) AS origen_reference_key_one
               FROM oracle_gastos
              WHERE id_empresa=? AND TRIM(oracle_expense_report_id) IN ($ph)
              ORDER BY id",
            array_merge([$idEmpresa],$ids)
        );
    }

    // 4) Vista de contexto: report IDs importados para la misma persona/fecha aproximada.
    $contexto=rows($pdo,
        "SELECT oracle_expense_report_id,person_name,COUNT(*) AS movimientos,
                SUM(COALESCE(receipt_amount,0)) AS importe,
                MIN(COALESCE(receipt_date,DATE(creation_date))) AS fecha_min,
                MAX(COALESCE(receipt_date,DATE(creation_date))) AS fecha_max
           FROM oracle_gastos
          WHERE id_empresa=?
            AND COALESCE(receipt_date,DATE(creation_date)) BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY)
          GROUP BY oracle_expense_report_id,person_name
          ORDER BY movimientos DESC,oracle_expense_report_id
          LIMIT 100",
        [$idEmpresa,$inv['invoice_date'],$inv['invoice_date']]
    );

    $diagnostico='SIN_DETALLE';
    if(count($normalizados)>0) $diagnostico='DETALLE_OK_CON_REGLA_ACTUAL';
    elseif(count($porOrigenInvoiceId)>0 || count($porOrigenFactura)>0) $diagnostico='HAY_DETALLE_POR_RELACION_DE_ORIGEN_PERO_NO_POR_REPORT_ID';
    elseif(count($porReport)>0) $diagnostico='HAY_DETALLE_POR_REPORT_ID_EXACTO_PERO_FALLA_NORMALIZACION_EXP';

    echo json_encode([
        'success'=>true,
        'diagnostico'=>$diagnostico,
        'empresa_activa'=>$idEmpresa,
        'reposicion'=>[
            'oracle_invoice_id'=>$invoiceId,
            'invoice_number'=>$factura,
            'invoice_date'=>$inv['invoice_date'],
            'supplier'=>$inv['supplier'],
            'invoice_amount'=>$inv['invoice_amount'],
            'description'=>$inv['description'],
            'reference_key_one'=>$referenceKeyOne,
            'exp_numerico_derivado'=>$expNumerico,
            'invoice_source_code'=>$raw['InvoiceSourceCode']??null
        ],
        'conteos'=>[
            'regla_actual_normalizada'=>count($normalizados),
            'por_origen_invoice_id'=>count($porOrigenInvoiceId),
            'por_origen_invoice_number'=>count($porOrigenFactura),
            'por_report_id_exacto'=>count($porReport)
        ],
        'detalle_regla_actual'=>$normalizados,
        'detalle_por_origen_invoice_id'=>$porOrigenInvoiceId,
        'detalle_por_origen_invoice_number'=>$porOrigenFactura,
        'detalle_por_report_id_exacto'=>$porReport,
        'contexto_reportes_cercanos'=>$contexto,
        'nota'=>'Solo diagnóstico: no inserta, actualiza ni elimina datos.'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'diagnostico_oracle_reposicion');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}
