<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/permisos_documentos.php';
require_once '../includes/oracle_pagos_rest.php';

@set_time_limit(0);
@ini_set('max_execution_time','0');

function job_estado(PDO $pdo,string $job,int $empresa,int $usuario): array
{
    $st=$pdo->prepare('SELECT * FROM oracle_pagos_sync_jobs WHERE id_job=? AND id_empresa=? AND id_usuario=? LIMIT 1');
    $st->execute([$job,$empresa,$usuario]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new RuntimeException('Proceso Oracle no encontrado o no pertenece a la sesión.');
    return $r;
}

/**
 * Normaliza fechas devueltas por Oracle (ISO-8601, con milisegundos y/o zona horaria)
 * al formato que acepta MySQL. No convierte la zona horaria: conserva la fecha/hora
 * representada por Oracle y elimina milisegundos/offset para el almacenamiento local.
 */
function oracle_pagos_mysql_fecha($valor, bool $conHora=false): ?string
{
    if($valor===null) return null;
    $valor=trim((string)$valor);
    if($valor==='' || strtolower($valor)==='null') return null;

    try{
        $dt=new DateTimeImmutable($valor);
        return $dt->format($conHora ? 'Y-m-d H:i:s' : 'Y-m-d');
    }catch(Throwable $e){
        return null;
    }
}

$faseDiagnostico='INICIO';
$contextoDiagnostico='Preparando importación';

try{
    seguridad_exigir_sesion($pdo,true,false,true);
    $idUsuario=(int)($_SESSION['id_usuario']??0);
    $idEmpresa=(int)($_SESSION['id_empresa']??0);
    exigir_permiso_accion($pdo,$idUsuario,$idEmpresa,'oracle_pagos');

    $job=trim((string)($_POST['job']??''));
    if($job==='') throw new RuntimeException('Falta identificador del proceso.');

    $j=job_estado($pdo,$job,$idEmpresa,$idUsuario);

    if((int)$j['cancel_requested']===1 || $j['estado']==='CANCELADO'){
        $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
        echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
        exit;
    }

    if($j['estado']==='TERMINADO'){
        echo json_encode(['success'=>true,'terminado'=>true,'estado'=>'TERMINADO']);
        exit;
    }

    $cfg=oracle_pagos_config($pdo,$idEmpresa);
    $base=oracle_pagos_api_base($cfg);
    $unidad=(string)$cfg['unidad_negocio'];

    // TEMPORAL / solicitado: división Granos por RFC fijo.
    // Granos conserva /expenses; Molinos obtiene el detalle desde
    // invoices -> invoiceLines -> ReferenceKeyTwo (EXM_EXPENSES) -> /expenses/{id}.
    $RFC_DIVISION_GRANOS='FSO0311035U8';
    $stEmp=$pdo->prepare('SELECT UPPER(TRIM(rfc)) FROM empresas WHERE id_empresa=? LIMIT 1');
    $stEmp->execute([$idEmpresa]);
    $rfcEmpresa=(string)$stEmp->fetchColumn();
    $esGranos=($rfcEmpresa===$RFC_DIVISION_GRANOS);

    // Usar SIEMPRE el rango guardado al iniciar el job.
    // El iniciador ya valida que DESDE/HASTA permanezcan dentro del mes seleccionado.
    $desde=(string)$j['desde'];
    $hasta=(string)$j['hasta'];
    if($desde==='' || $hasta==='') throw new RuntimeException('El proceso no tiene rango de fechas válido.');
    $fase=(string)$j['fase'];
    $faseDiagnostico=$fase;
    $contextoDiagnostico='Job '.$job.' / fase '.$fase.' / offset '.(int)$j['offset_actual'];
    $offset=(int)$j['offset_actual'];

    // 10 elementos por petición = meter sensible y cancelación rápida.
    $limit=10;
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    $errores=[];
    $procesadosLote=0;

    if($fase==='FACTURAS'){
        $contextoDiagnostico='Consultando facturas Oracle. Unidad '.$unidad.', rango '.$desde.' a '.$hasta.', offset '.$offset;
        $q="BusinessUnit={$unidad};InvoiceDate>={$desde} and <= {$hasta}";
        $url=$base.'/invoices?'.http_build_query(['q'=>$q,'limit'=>$limit,'offset'=>$offset],'','&',PHP_QUERY_RFC3986);
        $r=oracle_pagos_ok(oracle_pagos_get($cfg,$url),'Invoices');
        $items=oracle_pagos_items($r);

        $up=$pdo->prepare(
            'INSERT INTO oracle_facturas
                (id_empresa,oracle_invoice_id,invoice_number,invoice_currency,payment_currency,invoice_amount,
                 amount_paid,invoice_date,business_unit,supplier,supplier_number,supplier_tax_registration_number,
                 description,invoice_type,payment_method_code,payment_method,legal_entity,legal_entity_identifier,
                 paid_status,validation_status,approval_status,accounting_status,canceled_flag,canceled_date,canceled_by,purchase_order_number,
                 payment_check_id,payment_number,payment_reference,payment_date,payment_status,payment_reconciled_flag,
                 payment_clearing_date,payment_clearing_value_date,payment_amount,payment_currency_detail,payment_void_date,payment_count,
                 uuid_cfdi,datos_origen_json,fecha_primera_sync,fecha_ultima_sync)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                invoice_number=VALUES(invoice_number),invoice_currency=VALUES(invoice_currency),
                payment_currency=VALUES(payment_currency),invoice_amount=VALUES(invoice_amount),
                amount_paid=VALUES(amount_paid),invoice_date=VALUES(invoice_date),business_unit=VALUES(business_unit),
                supplier=VALUES(supplier),supplier_number=VALUES(supplier_number),
                supplier_tax_registration_number=VALUES(supplier_tax_registration_number),
                description=VALUES(description),invoice_type=VALUES(invoice_type),
                payment_method_code=VALUES(payment_method_code),payment_method=VALUES(payment_method),
                legal_entity=VALUES(legal_entity),legal_entity_identifier=VALUES(legal_entity_identifier),
                paid_status=VALUES(paid_status),validation_status=VALUES(validation_status),
                approval_status=VALUES(approval_status),accounting_status=VALUES(accounting_status),
                canceled_flag=VALUES(canceled_flag),canceled_date=VALUES(canceled_date),canceled_by=VALUES(canceled_by),
                purchase_order_number=VALUES(purchase_order_number),
                payment_check_id=VALUES(payment_check_id),payment_number=VALUES(payment_number),
                payment_reference=VALUES(payment_reference),payment_date=VALUES(payment_date),
                payment_status=VALUES(payment_status),payment_reconciled_flag=VALUES(payment_reconciled_flag),
                payment_clearing_date=VALUES(payment_clearing_date),payment_clearing_value_date=VALUES(payment_clearing_value_date),
                payment_amount=VALUES(payment_amount),payment_currency_detail=VALUES(payment_currency_detail),
                payment_void_date=VALUES(payment_void_date),payment_count=VALUES(payment_count),
                uuid_cfdi=COALESCE(VALUES(uuid_cfdi),uuid_cfdi),
                datos_origen_json=VALUES(datos_origen_json),fecha_ultima_sync=NOW()'
        );

        foreach($items as $x){
            if(oracle_pagos_job_cancelado($pdo,$job)){
                $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
                echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
                exit;
            }
            $invoiceId=(string)($x['InvoiceId']??'');
            if($invoiceId==='') continue;

            $invoiceNumber=(string)($x['InvoiceNumber']??'');
            $uuid=null;
            try{$uuid=oracle_pagos_uuid_invoice($cfg,$invoiceId);}
            catch(Throwable $e){$errores[]='Invoice '.$invoiceId.' UUID: '.$e->getMessage();}

            // Traer pagos reales y conciliación bancaria. Se conserva TODO el historial
            // en oracle_factura_pagos y en oracle_facturas se deja el pago más reciente
            // para mostrarlo rápido en el visor.
            $pagos=[];
            try{
                $pagos=oracle_pagos_pagos_factura($cfg,$invoiceId,$invoiceNumber);

                $pdo->prepare('DELETE FROM oracle_factura_pagos WHERE id_empresa=? AND oracle_invoice_id=?')
                    ->execute([$idEmpresa,$invoiceId]);

                if($pagos){
                    $ip=$pdo->prepare(
                        'INSERT INTO oracle_factura_pagos
                            (id_empresa,oracle_invoice_id,invoice_number,check_id,invoice_payment_id,payment_id,
                             payment_reference,paper_document_number,payment_number,payment_amount,invoice_payment_amount,
                             amount_paid_payment_currency,amount_paid_invoice_currency,payment_currency,payment_date,
                             accounting_date,payment_description,payment_status,invoice_payment_status,accounting_status,
                             reconciled_flag,clearing_date,clearing_value_date,clearing_amount,payment_method_code,
                             payment_type,payee,supplier_number,installment_number,void_date,void_accounting_date,
                             datos_origen_json,fecha_primera_sync,fecha_ultima_sync)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
                    );
                    foreach($pagos as $pg){
                        $ip->execute([
                            $idEmpresa,$invoiceId,$invoiceNumber,(string)($pg['CheckId']??''),
                            (string)($pg['InvoicePaymentId']??''),(string)($pg['PaymentId']??''),
                            (string)($pg['PaymentReference']??''),(string)($pg['PaperDocumentNumber']??''),
                            (string)($pg['PaymentNumber']??''),$pg['PaymentAmount']??null,$pg['InvoicePaymentAmount']??null,
                            $pg['AmountPaidPaymentCurrency']??null,$pg['AmountPaidInvoiceCurrency']??null,
                            (string)($pg['PaymentCurrency']??''),oracle_pagos_mysql_fecha($pg['PaymentDate']??null),oracle_pagos_mysql_fecha($pg['AccountingDate']??null),
                            (string)($pg['PaymentDescription']??''),(string)($pg['PaymentStatus']??''),
                            (string)($pg['InvoicePaymentStatus']??''),(string)($pg['AccountingStatus']??''),
                            !empty($pg['ReconciledFlag'])?1:0,oracle_pagos_mysql_fecha($pg['ClearingDate']??null),oracle_pagos_mysql_fecha($pg['ClearingValueDate']??null),
                            $pg['ClearingAmount']??null,(string)($pg['PaymentMethodCode']??''),(string)($pg['PaymentType']??''),
                            (string)($pg['Payee']??''),(string)($pg['SupplierNumber']??''),
                            $pg['InstallmentNumber']??null,oracle_pagos_mysql_fecha($pg['VoidDate']??null),oracle_pagos_mysql_fecha($pg['VoidAccountingDate']??null),
                            json_encode(['payment'=>$pg['raw_payment']??[],'relatedInvoice'=>$pg['raw_related_invoice']??[]],
                                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)
                        ]);
                    }
                }
            }catch(Throwable $e){
                // No abortar la importación completa si el usuario Oracle todavía no tiene
                // permiso sobre payablesPayments. La factura sí se actualiza y se informa el detalle.
                $errores[]='Invoice '.$invoiceId.' pagos: '.$e->getMessage();
            }

            $ultimoPago=$pagos ? $pagos[count($pagos)-1] : [];
            $up->execute([
                $idEmpresa,$invoiceId,$invoiceNumber,
                (string)($x['InvoiceCurrency']??''),(string)($x['PaymentCurrency']??''),
                $x['InvoiceAmount']??null,$x['AmountPaid']??null,oracle_pagos_mysql_fecha($x['InvoiceDate']??null),
                (string)($x['BusinessUnit']??''),(string)($x['Supplier']??''),
                (string)($x['SupplierNumber']??''),(string)($x['SupplierTaxRegistrationNumber']??''),
                (string)($x['Description']??''),(string)($x['InvoiceType']??''),
                (string)($x['PaymentMethodCode']??''),(string)($x['PaymentMethod']??''),
                (string)($x['LegalEntity']??''),(string)($x['LegalEntityIdentifier']??''),
                (string)($x['PaidStatus']??''),(string)($x['ValidationStatus']??''),
                (string)($x['ApprovalStatus']??''),(string)($x['AccountingStatus']??''),
                oracle_pagos_bool($x['CanceledFlag']??false)?1:0,oracle_pagos_mysql_fecha($x['CanceledDate']??null),(string)($x['CanceledBy']??''),
                (string)($x['PurchaseOrderNumber']??''),
                (string)($ultimoPago['CheckId']??''),(string)($ultimoPago['PaymentNumber']??''),
                (string)($ultimoPago['PaymentReference']??''),oracle_pagos_mysql_fecha($ultimoPago['PaymentDate']??null),
                (string)($ultimoPago['PaymentStatus']??''),!empty($ultimoPago['ReconciledFlag'])?1:0,
                oracle_pagos_mysql_fecha($ultimoPago['ClearingDate']??null),oracle_pagos_mysql_fecha($ultimoPago['ClearingValueDate']??null),
                $ultimoPago['PaymentAmount']??null,(string)($ultimoPago['PaymentCurrency']??''),
                oracle_pagos_mysql_fecha($ultimoPago['VoidDate']??null),count($pagos),
                $uuid,json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)
            ]);
            $procesadosLote++;
        }

        $nuevoOffset=$offset+count($items);
        $hasMore=oracle_pagos_has_more($r);

        if($hasMore){
            $pdo->prepare(
                'UPDATE oracle_pagos_sync_jobs
                    SET offset_actual=?,procesados_facturas=procesados_facturas+?,ultimo_error=?
                  WHERE id_job=?'
            )->execute([$nuevoOffset,$procesadosLote,$errores?implode("\n",$errores):null,$job]);
        }else{
            $pdo->prepare(
                'UPDATE oracle_pagos_sync_jobs
                    SET fase="GASTOS",offset_actual=0,procesados_facturas=procesados_facturas+?,ultimo_error=?
                  WHERE id_job=?'
            )->execute([$procesadosLote,$errores?implode("\n",$errores):null,$job]);
        }

    }elseif($fase==='GASTOS'){
        $up=$pdo->prepare(
            'INSERT INTO oracle_gastos
                (id_empresa,oracle_expense_id,oracle_expense_report_id,business_unit,person_id,person_name,
                 merchant_name,merchant_taxpayer_id,expense_type,description,receipt_amount,receipt_currency_code,
                 reimbursable_amount,reimbursement_currency_code,receipt_date,creation_date,expense_report_status,
                 payment_due_from_code,expense_reference,reference_number,uuid_cfdi,datos_origen_json,
                 fecha_primera_sync,fecha_ultima_sync)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                oracle_expense_report_id=VALUES(oracle_expense_report_id),business_unit=VALUES(business_unit),
                person_id=VALUES(person_id),person_name=VALUES(person_name),merchant_name=VALUES(merchant_name),
                merchant_taxpayer_id=VALUES(merchant_taxpayer_id),expense_type=VALUES(expense_type),
                description=VALUES(description),receipt_amount=VALUES(receipt_amount),
                receipt_currency_code=VALUES(receipt_currency_code),reimbursable_amount=VALUES(reimbursable_amount),
                reimbursement_currency_code=VALUES(reimbursement_currency_code),receipt_date=VALUES(receipt_date),
                creation_date=VALUES(creation_date),expense_report_status=VALUES(expense_report_status),
                payment_due_from_code=VALUES(payment_due_from_code),expense_reference=VALUES(expense_reference),
                reference_number=VALUES(reference_number),uuid_cfdi=COALESCE(VALUES(uuid_cfdi),uuid_cfdi),
                datos_origen_json=VALUES(datos_origen_json),fecha_ultima_sync=NOW()'
        );

        if($esGranos){
            // -------------------------------------------------------------
            // DIVISIÓN GRANOS - RFC FSO0311035U8 (HARDCODEADO TEMPORAL)
            // Mantener exactamente la lógica que ya funciona en Ferropuerto.
            // -------------------------------------------------------------
            $contextoDiagnostico='Consultando gastos Oracle /expenses. Unidad '.$unidad.', rango '.$desde.' a '.$hasta.', offset '.$offset;
            $q="BusinessUnit={$unidad};CreationDate>={$desde}T00:00:00 and <= {$hasta}T23:59:59";
            $url=$base.'/expenses?'.http_build_query(['q'=>$q,'limit'=>$limit,'offset'=>$offset],'','&',PHP_QUERY_RFC3986);
            $r=oracle_pagos_ok(oracle_pagos_get($cfg,$url),'Expenses');
            $items=oracle_pagos_items($r);

            foreach($items as $x){
                if(oracle_pagos_job_cancelado($pdo,$job)){
                    $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
                    echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
                    exit;
                }

                $expenseId=(string)($x['ExpenseId']??'');
                if($expenseId==='') continue;

                $uuid=null;
                try{$uuid=oracle_pagos_uuid_expense($cfg,$expenseId);}
                catch(Throwable $e){$errores[]='Expense '.$expenseId.': '.$e->getMessage();}

                $up->execute([
                    $idEmpresa,$expenseId,(string)($x['ExpenseReportId']??''),
                    (string)($x['BusinessUnit']??''),(string)($x['PersonId']??''),
                    (string)($x['PersonName']??''),(string)($x['MerchantName']??''),
                    (string)($x['MerchantTaxRegNumber']??($x['MerchantTaxpayerId']??'')),(string)($x['ExpenseType']??''),
                    (string)($x['Description']??''),$x['DailyAmount']??($x['ReceiptAmount']??null),
                    (string)($x['ReceiptCurrencyCode']??''),$x['ReimbursableAmount']??null,
                    (string)($x['ReimbursementCurrencyCode']??''),oracle_pagos_mysql_fecha($x['ReceiptDate']??null),
                    oracle_pagos_mysql_fecha($x['CreationDate']??null,true),(string)($x['ExpenseReportStatus']??''),
                    (string)($x['PaymentDueFromCode']??''),(string)($x['ExpenseReference']??''),
                    (string)($x['MerchantDocumentNumber']??($x['ReferenceNumber']??'')),$uuid,
                    json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)
                ]);
                $procesadosLote++;
            }

            $nuevoOffset=$offset+count($items);
            $hasMore=oracle_pagos_has_more($r);

            if($hasMore){
                $pdo->prepare(
                    'UPDATE oracle_pagos_sync_jobs
                        SET offset_actual=?,procesados_gastos=procesados_gastos+?,ultimo_error=?
                      WHERE id_job=?'
                )->execute([$nuevoOffset,$procesadosLote,$errores?implode("\n",$errores):null,$job]);
            }else{
                $pdo->prepare(
                    'UPDATE oracle_pagos_sync_jobs
                        SET estado="TERMINADO",fase="TERMINADO",procesados_gastos=procesados_gastos+?,
                            ultimo_error=?,fecha_fin=NOW()
                      WHERE id_job=?'
                )->execute([$procesadosLote,$errores?implode("\n",$errores):null,$job]);

                $pdo->prepare('UPDATE empresa_oracle_pagos_config SET ultima_sincronizacion=NOW(),ultimo_error=NULL WHERE id_empresa=?')
                    ->execute([$idEmpresa]);
            }
        }else{
            // -------------------------------------------------------------
            // DIVISIÓN MOLINOS
            // Oracle no devuelve estos comprobantes en /expenses filtrado por
            // BusinessUnit. La relación comprobada es:
            //   factura EXP -> invoiceLines -> ProductTable=EXM_EXPENSES
            //   -> ReferenceKeyTwo = ExpenseId -> /expenses/{ExpenseId}
            // El gasto individual sí trae los mismos campos usados por Granos,
            // por lo que se guarda en la MISMA tabla oracle_gastos.
            // -------------------------------------------------------------
            // Una reposición por petición AJAX. Así cada llamada web queda corta y
            // evitamos que nginx/proxy acumule decenas de consultas en una sola petición.
            $reposPorLote=1;
            $stRepos=$pdo->prepare(
                "SELECT oracle_invoice_id,invoice_number,datos_origen_json
                   FROM oracle_facturas
                  WHERE id_empresa=?
                    AND invoice_date>=? AND invoice_date<=?
                    AND (UPPER(TRIM(invoice_number)) LIKE 'EXP%'
                         OR JSON_UNQUOTE(JSON_EXTRACT(datos_origen_json,'$.InvoiceSourceCode'))='EMP_EXPENSE_REPORT')
                  ORDER BY invoice_date,oracle_invoice_id
                  LIMIT {$reposPorLote} OFFSET {$offset}"
            );
            $stRepos->execute([$idEmpresa,$desde,$hasta]);
            $repos=$stRepos->fetchAll(PDO::FETCH_ASSOC);

            foreach($repos as $repo){
                if(oracle_pagos_job_cancelado($pdo,$job)){
                    $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
                    echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
                    exit;
                }

                $invoiceId=trim((string)($repo['oracle_invoice_id']??''));
                if($invoiceId==='') continue;

                $rawInvoice=json_decode((string)($repo['datos_origen_json']??''),true);
                if(!is_array($rawInvoice)) $rawInvoice=[];
                $reportIdFallback=trim((string)($rawInvoice['ReferenceKeyOne']??''));

                $contextoDiagnostico='Molinos: reposición '.($repo['invoice_number']??$invoiceId).' (InvoiceId '.$invoiceId.'): leyendo invoiceLines';

                // 1) Leer invoiceLines para conocer los ExpenseId y conservar el contexto
                //    contable de la reposición.
                try{
                    $lineas=oracle_pagos_invoice_lines($cfg,$invoiceId);
                }catch(Throwable $e){
                    $lineas=[];
                    $errores[]='Reposición '.($repo['invoice_number']??$invoiceId).' líneas: '.$e->getMessage();
                }

                $lineasPorExpense=[];
                foreach($lineas as $ln){
                    $tabla=strtoupper(trim((string)($ln['ProductTable']??'')));
                    $expenseId=trim((string)($ln['ReferenceKeyTwo']??''));
                    if($tabla!=='EXM_EXPENSES' || $expenseId==='') continue;
                    $lineasPorExpense[$expenseId]=$ln;
                }

                // 2) Camino principal: consultar TODOS los movimientos por ExpenseReportId.
                //    Esto incluye deducibles y NO DEDUCIBLES. El UUID es opcional y nunca
                //    se usa como requisito para guardar/mostrar el movimiento.
                $gastosPorId=[];
                if($reportIdFallback!==''){
                    $contextoDiagnostico='Molinos: reposición '.($repo['invoice_number']??$invoiceId).' (InvoiceId '.$invoiceId.'): leyendo gastos por ExpenseReportId '.$reportIdFallback;
                    try{
                        foreach(oracle_pagos_expenses_report($cfg,$reportIdFallback) as $gx){
                            $eid=trim((string)($gx['ExpenseId']??''));
                            if($eid!=='') $gastosPorId[$eid]=$gx;
                        }
                    }catch(Throwable $e){
                        $errores[]='Reposición '.($repo['invoice_number']??$invoiceId).' gastos por ExpenseReportId '.$reportIdFallback.': '.$e->getMessage();
                    }
                }

                // 3) Respaldo: cualquier ExpenseId visto en invoiceLines que no haya venido
                //    en la consulta por ExpenseReportId se solicita individualmente.
                foreach($lineasPorExpense as $expenseId=>$linea){
                    if(isset($gastosPorId[$expenseId])) continue;
                    $contextoDiagnostico='Molinos: reposición '.($repo['invoice_number']??$invoiceId).': leyendo ExpenseId '.$expenseId;
                    try{
                        $gx=oracle_pagos_expense_item($cfg,(string)$expenseId);
                        if($gx) $gastosPorId[$expenseId]=$gx;
                    }catch(Throwable $e){
                        $errores[]='Expense '.$expenseId.' de reposición '.($repo['invoice_number']??$invoiceId).': '.$e->getMessage();
                    }
                }

                foreach($gastosPorId as $expenseId=>$x){
                    if(oracle_pagos_job_cancelado($pdo,$job)){
                        $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
                        echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
                        exit;
                    }

                    $linea=$lineasPorExpense[$expenseId]??[];

                    // La relación maestra es ExpenseReportId. Si Oracle la omite en algún
                    // movimiento, usamos ReferenceKeyOne de la factura EXP.
                    $expenseReportId=trim((string)($x['ExpenseReportId']??''));
                    if($expenseReportId==='') $expenseReportId=$reportIdFallback;

                    // El UUID se consulta en una fase separada (UUID_GASTOS), por bloques
                    // pequeños. Esto evita hacer N llamadas ExpenseDff dentro de la misma
                    // petición que leyó invoiceLines + expenses, que era lo que podía disparar
                    // el 504 por tiempo acumulado.
                    $uuid=null;

                    $origen=[
                        'expense'=>$x,
                        'invoiceLine'=>$linea,
                        'reposicion'=>[
                            'InvoiceId'=>$invoiceId,
                            'InvoiceNumber'=>$repo['invoice_number']??'',
                            'ReferenceKeyOne'=>$reportIdFallback
                        ],
                        'relacion'=>[
                            'ExpenseReportId'=>$expenseReportId,
                            'ExpenseId'=>(string)$expenseId,
                            'UuidOpcional'=>true,
                            'UuidConsultado'=>false
                        ]
                    ];

                    $up->execute([
                        $idEmpresa,(string)$expenseId,$expenseReportId,
                        (string)($x['BusinessUnit']??$unidad),(string)($x['PersonId']??''),
                        (string)($x['PersonName']??''),(string)($x['MerchantName']??''),
                        (string)($x['MerchantTaxRegNumber']??($x['MerchantTaxpayerId']??'')),(string)($x['ExpenseType']??''),
                        (string)($x['Description']??($linea['Description']??'')),$x['DailyAmount']??($x['ReceiptAmount']??($linea['LineAmount']??null)),
                        (string)($x['ReceiptCurrencyCode']??($rawInvoice['InvoiceCurrency']??'')),$x['ReimbursableAmount']??null,
                        (string)($x['ReimbursementCurrencyCode']??($rawInvoice['InvoiceCurrency']??'')),oracle_pagos_mysql_fecha($x['ReceiptDate']??($linea['AccountingDate']??null)),
                        oracle_pagos_mysql_fecha($x['CreationDate']??null,true),(string)($x['ExpenseReportStatus']??''),
                        (string)($x['PaymentDueFromCode']??''),(string)($x['ExpenseReference']??''),
                        (string)($x['MerchantDocumentNumber']??($x['ReferenceNumber']??'')),$uuid,
                        json_encode($origen,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)
                    ]);
                    $procesadosLote++;
                }
            }

            $nuevoOffset=$offset+count($repos);
            $hayMas=(count($repos)===$reposPorLote);

            if($hayMas){
                $pdo->prepare(
                    'UPDATE oracle_pagos_sync_jobs
                        SET offset_actual=?,procesados_gastos=procesados_gastos+?,ultimo_error=?
                      WHERE id_job=?'
                )->execute([$nuevoOffset,$procesadosLote,$errores?implode("\n",$errores):null,$job]);
            }else{
                $totalGastosFinal=(int)$j['procesados_gastos']+$procesadosLote;
                // Ya quedaron guardados los detalles. Ahora pasamos a una fase independiente
                // para consultar los DFF/UUID de pocos gastos por llamada AJAX.
                $pdo->prepare(
                    'UPDATE oracle_pagos_sync_jobs
                        SET fase="UUID_GASTOS",offset_actual=0,procesados_gastos=?,
                            total_gastos=?,ultimo_error=?
                      WHERE id_job=?'
                )->execute([$totalGastosFinal,$totalGastosFinal,$errores?implode("\n",$errores):null,$job]);
            }
        }

    }elseif($fase==='UUID_GASTOS'){
        // Procesar pocos UUID por llamada evita que una reposición con cientos de
        // comprobantes mantenga una sola petición PHP abierta durante demasiado tiempo.
        $uuidPorLote=10;
        $contextoDiagnostico='Molinos: consultando UUID/DFF de gastos en bloques de '.$uuidPorLote;

        $stUuid=$pdo->prepare(
            "SELECT g.id,g.oracle_expense_id,g.datos_origen_json
               FROM oracle_gastos g
               INNER JOIN oracle_facturas f
                 ON f.id_empresa=g.id_empresa
                AND f.oracle_invoice_id=JSON_UNQUOTE(JSON_EXTRACT(g.datos_origen_json,'$.reposicion.InvoiceId'))
              WHERE g.id_empresa=?
                AND f.invoice_date>=? AND f.invoice_date<=?
                AND (g.uuid_cfdi IS NULL OR TRIM(g.uuid_cfdi)='')
                AND (
                    JSON_EXTRACT(g.datos_origen_json,'$.relacion.UuidConsultado') IS NULL
                    OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(g.datos_origen_json,'$.relacion.UuidConsultado'))) NOT IN ('true','1')
                )
              ORDER BY f.invoice_date,g.id
              LIMIT {$uuidPorLote}"
        );
        $stUuid->execute([$idEmpresa,$desde,$hasta]);
        $pendientes=$stUuid->fetchAll(PDO::FETCH_ASSOC);

        $upUuid=$pdo->prepare(
            'UPDATE oracle_gastos
                SET uuid_cfdi=COALESCE(?,uuid_cfdi),datos_origen_json=?,fecha_ultima_sync=NOW()
              WHERE id=? AND id_empresa=?'
        );

        foreach($pendientes as $g){
            if(oracle_pagos_job_cancelado($pdo,$job)){
                $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="CANCELADO",fecha_fin=NOW() WHERE id_job=?')->execute([$job]);
                echo json_encode(['success'=>true,'cancelado'=>true,'estado'=>'CANCELADO']);
                exit;
            }

            $expenseId=trim((string)($g['oracle_expense_id']??''));
            if($expenseId==='') continue;
            $urlUuid=$base.'/expenses/'.rawurlencode($expenseId).'/child/ExpenseDff';
            $contextoDiagnostico='Molinos: ExpenseId '.$expenseId.' consultando UUID | URL: '.$urlUuid;

            $uuid=null;
            $errorUuid=null;
            try{
                $uuid=oracle_pagos_uuid_expense($cfg,$expenseId);
            }catch(Throwable $e){
                $errorUuid=$e->getMessage();
                $errores[]='Expense '.$expenseId.' UUID: '.$errorUuid.' | Consulta: '.$urlUuid;
            }

            $origen=json_decode((string)($g['datos_origen_json']??''),true);
            if(!is_array($origen)) $origen=[];
            if(!isset($origen['relacion']) || !is_array($origen['relacion'])) $origen['relacion']=[];
            $origen['relacion']['UuidConsultado']=true;
            $origen['relacion']['UuidConsultaFecha']=date('Y-m-d H:i:s');
            if($errorUuid!==null) $origen['relacion']['UuidConsultaError']=$errorUuid;
            else unset($origen['relacion']['UuidConsultaError']);

            $upUuid->execute([
                $uuid,
                json_encode($origen,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),
                (int)$g['id'],$idEmpresa
            ]);
        }

        if(count($pendientes)<$uuidPorLote){
            // Contabilizar también el último lote antes de marcar terminado.
            $pdo->prepare(
                'UPDATE oracle_pagos_sync_jobs
                    SET estado="TERMINADO",fase="TERMINADO",
                        offset_actual=offset_actual+?,ultimo_error=?,fecha_fin=NOW()
                  WHERE id_job=?'
            )->execute([count($pendientes),$errores?implode("\n",$errores):($j['ultimo_error']??null),$job]);

            $pdo->prepare('UPDATE empresa_oracle_pagos_config SET ultima_sincronizacion=NOW(),ultimo_error=NULL WHERE id_empresa=?')
                ->execute([$idEmpresa]);
        }else{
            $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET offset_actual=offset_actual+?,ultimo_error=? WHERE id_job=?')
                ->execute([count($pendientes),$errores?implode("\n",$errores):null,$job]);
        }

    }else{
        throw new RuntimeException('Fase Oracle Pagos no reconocida: '.$fase);
    }

    $j=job_estado($pdo,$job,$idEmpresa,$idUsuario);

    // En la fase UUID el total general ya está al 100%, pero todavía puede haber
    // cientos de DFF por consultar. Informamos progreso real para que la pantalla
    // no parezca congelada mientras el proceso sigue trabajando.
    $uuidRevisados=null;
    $uuidPendientes=null;
    if($j['fase']==='UUID_GASTOS' || $j['fase']==='TERMINADO'){
        $uuidRevisados=(int)$j['offset_actual'];
        $stPendUuid=$pdo->prepare(
            "SELECT COUNT(*)
               FROM oracle_gastos g
               INNER JOIN oracle_facturas f
                 ON f.id_empresa=g.id_empresa
                AND f.oracle_invoice_id=JSON_UNQUOTE(JSON_EXTRACT(g.datos_origen_json,'$.reposicion.InvoiceId'))
              WHERE g.id_empresa=?
                AND f.invoice_date>=? AND f.invoice_date<=?
                AND (g.uuid_cfdi IS NULL OR TRIM(g.uuid_cfdi)='')
                AND (
                    JSON_EXTRACT(g.datos_origen_json,'$.relacion.UuidConsultado') IS NULL
                    OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(g.datos_origen_json,'$.relacion.UuidConsultado'))) NOT IN ('true','1')
                )"
        );
        $stPendUuid->execute([$idEmpresa,$desde,$hasta]);
        $uuidPendientes=(int)$stPendUuid->fetchColumn();
    }

    echo json_encode([
        'success'=>true,
        'job'=>$job,
        'estado'=>$j['estado'],
        'fase'=>$j['fase'],
        'total_facturas'=>$j['total_facturas']!==null?(int)$j['total_facturas']:null,
        'total_gastos'=>$j['total_gastos']!==null?(int)$j['total_gastos']:null,
        'procesados_facturas'=>(int)$j['procesados_facturas'],
        'procesados_gastos'=>(int)$j['procesados_gastos'],
        'uuid_revisados'=>$uuidRevisados,
        'uuid_pendientes'=>$uuidPendientes,
        'terminado'=>$j['estado']==='TERMINADO',
        'cancelado'=>$j['estado']==='CANCELADO',
        'errores'=>$errores,
        'ultimo_error'=>$j['ultimo_error']??null,
        'diagnostico'=>$contextoDiagnostico
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);

}catch(Throwable $e){
    seguridad_log_error($e,'oracle_pagos_procesar');
    if(!empty($job)){
        try{
            $pdo->prepare('UPDATE oracle_pagos_sync_jobs SET estado="ERROR",ultimo_error=?,fecha_fin=NOW() WHERE id_job=?')
                ->execute([substr($e->getMessage(),0,2000),$job]);
        }catch(Throwable $ignorar){}
    }
    $mensaje='Fase '.$faseDiagnostico.' | '.$contextoDiagnostico.' | '.$e->getMessage();
    http_response_code(400);
    echo json_encode([
        'success'=>false,
        'error'=>$mensaje,
        'fase'=>$faseDiagnostico,
        'contexto'=>$contextoDiagnostico,
        'tipo_error'=>get_class($e)
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
