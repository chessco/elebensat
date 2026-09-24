<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/oracle_pagos_rest.php';

function diag_pick(array $a, array $keys): array {
    $o=[];
    foreach($keys as $k){ if(array_key_exists($k,$a)) $o[$k]=$a[$k]; }
    return $o;
}

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $invoiceId=trim((string)($_GET['invoice_id']??''));
    $factura=strtoupper(trim((string)($_GET['factura']??'')));
    if($invoiceId==='' && $factura==='') throw new RuntimeException('Indica invoice_id o factura EXP.');

    if($invoiceId!==''){
        $st=$pdo->prepare("SELECT oracle_invoice_id,invoice_number,invoice_date,supplier,invoice_amount,description,datos_origen_json FROM oracle_facturas WHERE id_empresa=? AND oracle_invoice_id=? LIMIT 1");
        $st->execute([$idEmpresa,$invoiceId]);
    }else{
        $st=$pdo->prepare("SELECT oracle_invoice_id,invoice_number,invoice_date,supplier,invoice_amount,description,datos_origen_json FROM oracle_facturas WHERE id_empresa=? AND UPPER(TRIM(invoice_number))=? LIMIT 1");
        $st->execute([$idEmpresa,$factura]);
    }
    $repo=$st->fetch(PDO::FETCH_ASSOC);
    if(!$repo) throw new RuntimeException('No se encontró la reposición en oracle_facturas para la empresa activa.');

    $invoiceId=(string)$repo['oracle_invoice_id'];
    $factura=(string)$repo['invoice_number'];
    $rawInvoice=json_decode((string)($repo['datos_origen_json']??''),true);
    if(!is_array($rawInvoice)) $rawInvoice=[];
    $referenceKeyOne=trim((string)($rawInvoice['ReferenceKeyOne']??''));

    $cfg=oracle_pagos_config($pdo,$idEmpresa);
    $base=oracle_pagos_api_base($cfg);

    $lineas=oracle_pagos_invoice_lines($cfg,$invoiceId);
    $lineasResumen=[]; $expenseIds=[];
    foreach($lineas as $ln){
        $r=diag_pick($ln,['LineNumber','LineAmount','Description','AccountingDate','ProductTable','ReferenceKeyOne','ReferenceKeyTwo','ReferenceKeyThree']);
        $lineasResumen[]=$r;
        $tabla=strtoupper(trim((string)($ln['ProductTable']??'')));
        $eid=trim((string)($ln['ReferenceKeyTwo']??''));
        if($tabla==='EXM_EXPENSES' && $eid!=='') $expenseIds[$eid]=true;
    }

    $gastosPorLinea=[];
    foreach(array_keys($expenseIds) as $eid){
        try{
            $x=oracle_pagos_expense_item($cfg,$eid);
            $gastosPorLinea[]=[
                'expense_id'=>$eid,
                'ok'=>true,
                'campos'=>diag_pick($x,['ExpenseId','ExpenseReportId','PersonName','MerchantName','MerchantTaxRegNumber','ExpenseType','Description','ReceiptAmount','ReceiptCurrencyCode','ReceiptDate','CreationDate','ExpenseReference','ReferenceNumber','MerchantDocumentNumber','ExpenseReportStatus'])
            ];
        }catch(Throwable $e){
            $gastosPorLinea[]=['expense_id'=>$eid,'ok'=>false,'error'=>$e->getMessage()];
        }
    }

    // Segunda vía: buscar /expenses por ExpenseReportId usando ReferenceKeyOne del encabezado EXP.
    $porReport=[]; $urlReport=null; $httpReport=null;
    if($referenceKeyOne!==''){
        $q='ExpenseReportId='.$referenceKeyOne;
        $urlReport=$base.'/expenses?'.http_build_query(['q'=>$q,'limit'=>200,'offset'=>0,'onlyData'=>'true'],'','&',PHP_QUERY_RFC3986);
        $rr=oracle_pagos_get($cfg,$urlReport,180);
        $httpReport=(int)$rr['http'];
        if($httpReport>=200 && $httpReport<300){
            foreach(oracle_pagos_items($rr) as $x){
                if(is_array($x)) $porReport[]=diag_pick($x,['ExpenseId','ExpenseReportId','PersonName','MerchantName','MerchantTaxRegNumber','ExpenseType','Description','ReceiptAmount','ReceiptCurrencyCode','ReceiptDate','CreationDate','ExpenseReference','ReferenceNumber','MerchantDocumentNumber','ExpenseReportStatus']);
            }
        }
    }

    echo json_encode([
        'success'=>true,
        'modo'=>'SOLO_DIAGNOSTICO_ORACLE',
        'empresa_activa'=>$idEmpresa,
        'reposicion'=>[
            'oracle_invoice_id'=>$invoiceId,
            'invoice_number'=>$factura,
            'invoice_date'=>$repo['invoice_date']??null,
            'supplier'=>$repo['supplier']??null,
            'invoice_amount'=>$repo['invoice_amount']??null,
            'description'=>$repo['description']??null,
            'reference_key_one'=>$referenceKeyOne,
            'invoice_source_code'=>$rawInvoice['InvoiceSourceCode']??null
        ],
        'invoice_lines'=>[
            'total'=>count($lineas),
            'expense_ids_exm'=>array_keys($expenseIds),
            'lineas'=>$lineasResumen
        ],
        'expenses_por_invoice_lines'=>$gastosPorLinea,
        'expenses_por_reference_key_one'=>[
            'consulta'=>'ExpenseReportId='.$referenceKeyOne,
            'http'=>$httpReport,
            'total'=>count($porReport),
            'items'=>$porReport
        ],
        'interpretacion'=> count($expenseIds)>0
            ? 'La factura sí expone EXM_EXPENSES en invoiceLines. Revisar los ExpenseId y su ExpenseReportId.'
            : (count($porReport)>0
                ? 'No hubo EXM_EXPENSES en invoiceLines, pero Oracle sí devolvió gastos por ReferenceKeyOne/ExpenseReportId.'
                : 'Oracle no devolvió detalle por ninguna de las dos vías probadas. Revisar estructura de invoiceLines y otro recurso de Expense Reports.'),
        'nota'=>'No inserta, actualiza ni elimina datos. No devuelve usuario ni contraseña Oracle.'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'diagnostico_oracle_reembolso_oracle');
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage(),'nota'=>'Solo diagnóstico; no modifica datos.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}
