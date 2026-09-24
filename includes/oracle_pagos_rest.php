<?php
declare(strict_types=1);

require_once __DIR__ . '/oracle_pagos_crypto.php';

/**
 * La empresa SOLO guarda unidad, usuario y contraseña.
 * La URL no se guarda por empresa.
 *
 * Para pruebas puede definirse ORACLE_PAGOS_BASE_URL en el ambiente del servidor:
 *   https://ejes-test.fa.us2.oraclecloud.com
 *
 * Producción, si no existe variable:
 *   https://ejes.fa.us2.oraclecloud.com
 */
function oracle_pagos_base_url(): string
{
    $env = trim((string)getenv('ORACLE_PAGOS_BASE_URL'));
    return $env !== '' ? rtrim($env, '/') : 'https://ejes.fa.us2.oraclecloud.com';
}

function oracle_pagos_api_version(): string
{
    $env = trim((string)getenv('ORACLE_PAGOS_API_VERSION'));
    return $env !== '' ? $env : '11.13.18.05';
}

function oracle_pagos_config(PDO $pdo, int $idEmpresa): array
{
    $st = $pdo->prepare(
        'SELECT id_empresa,unidad_negocio,usuario,password_enc,ultima_prueba,ultimo_error,ultima_sincronizacion
           FROM empresa_oracle_pagos_config
          WHERE id_empresa=?
          LIMIT 1'
    );
    $st->execute([$idEmpresa]);
    $cfg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$cfg) throw new RuntimeException('La empresa activa no tiene configuración de Oracle Pagos.');

    $cfg['password'] = oracle_pagos_descifrar((string)($cfg['password_enc'] ?? ''));
    if (trim((string)$cfg['unidad_negocio']) === '' || trim((string)$cfg['usuario']) === '' || $cfg['password'] === '') {
        throw new RuntimeException('La configuración Oracle Pagos está incompleta.');
    }

    $cfg['base_url'] = oracle_pagos_base_url();
    $cfg['api_version'] = oracle_pagos_api_version();
    return $cfg;
}

function oracle_pagos_api_base(array $cfg): string
{
    return rtrim((string)$cfg['base_url'], '/')
        . '/fscmRestApi/resources/'
        . trim((string)$cfg['api_version'], '/');
}

function oracle_pagos_get(array $cfg, string $url, int $timeout = 180): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL no está disponible.');

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('No fue posible iniciar cURL.');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERPWD => (string)$cfg['usuario'] . ':' . (string)$cfg['password'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json','Cache-Control: no-cache'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException("Oracle REST cURL {$errno}: {$error} | URL: {$url}");
    }

    $json = json_decode((string)$raw, true);
    return ['http'=>$http,'raw'=>(string)$raw,'json'=>is_array($json)?$json:null,'url'=>$url];
}

function oracle_pagos_error_detalle(array $r): string
{
    $partes = [];
    $json = $r['json'] ?? null;

    // Oracle Fusion puede devolver el mensaje en distintas estructuras según el recurso.
    if (is_array($json)) {
        foreach (['detail','title','message','error','error_description'] as $k) {
            if (isset($json[$k]) && is_scalar($json[$k])) {
                $v = trim((string)$json[$k]);
                if ($v !== '') $partes[] = $v;
            }
        }

        // Estructuras frecuentes: o:errorDetails, errorDetails, errors, items.
        foreach (['o:errorDetails','errorDetails','errors'] as $k) {
            if (!isset($json[$k]) || !is_array($json[$k])) continue;
            foreach ($json[$k] as $it) {
                if (is_string($it) && trim($it) !== '') {
                    $partes[] = trim($it);
                    continue;
                }
                if (!is_array($it)) continue;
                foreach (['detail','title','message','errorMessage','instance','code'] as $kk) {
                    if (isset($it[$kk]) && is_scalar($it[$kk])) {
                        $v = trim((string)$it[$kk]);
                        if ($v !== '') $partes[] = $v;
                    }
                }
            }
        }
    }

    $partes = array_values(array_unique(array_filter($partes, static fn($v) => $v !== '')));
    if ($partes) return implode(' | ', array_slice($partes, 0, 6));

    // Si Oracle no devolvió JSON, conservar una muestra del body real para diagnóstico.
    $raw = trim(strip_tags((string)($r['raw'] ?? '')));
    $raw = preg_replace('/\s+/', ' ', $raw ?? '');
    if ($raw !== '') return mb_substr($raw, 0, 1200);
    return 'Oracle no devolvió detalle adicional.';
}

function oracle_pagos_ok(array $r, string $ctx): array
{
    $http = (int)($r['http'] ?? 0);
    if ($http < 200 || $http >= 300) {
        $detalle = oracle_pagos_error_detalle($r);
        $url = (string)($r['url'] ?? '');
        throw new RuntimeException(
            $ctx . ': HTTP ' . $http . '. ' . $detalle .
            ($url !== '' ? ' | URL: ' . $url : '')
        );
    }
    return $r;
}

function oracle_pagos_items(array $r): array
{
    return (is_array($r['json']) && isset($r['json']['items']) && is_array($r['json']['items']))
        ? $r['json']['items'] : [];
}

function oracle_pagos_has_more(array $r): bool
{
    return is_array($r['json']) && !empty($r['json']['hasMore']);
}

function oracle_pagos_total(array $r): ?int
{
    if (!is_array($r['json'])) return null;
    foreach (['totalResults','total'] as $k) {
        if (isset($r['json'][$k]) && is_numeric($r['json'][$k])) return (int)$r['json'][$k];
    }
    return null;
}

function oracle_pagos_uuid($valor): ?string
{
    $valor = strtoupper(trim((string)$valor));
    if ($valor === '') return null;
    if (preg_match('/\b[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\b/i', $valor, $m)) {
        return strtoupper($m[0]);
    }
    return null;
}

function oracle_pagos_uuid_invoice(array $cfg, string $invoiceId): ?string
{
    $url = oracle_pagos_api_base($cfg) . '/invoices/' . rawurlencode($invoiceId) . '/child/invoiceGdf';
    $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'invoiceGdf ' . $invoiceId);
    foreach (oracle_pagos_items($r) as $x) {
        $uuid = oracle_pagos_uuid($x['CFDIUniqueIdentifier'] ?? '');
        if ($uuid) return $uuid;
    }
    return null;
}

function oracle_pagos_uuid_expense(array $cfg, string $expenseId): ?string
{
    $url = oracle_pagos_api_base($cfg) . '/expenses/' . rawurlencode($expenseId) . '/child/ExpenseDff';
    $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'ExpenseDff ' . $expenseId);
    foreach (oracle_pagos_items($r) as $x) {
        $uuid = oracle_pagos_uuid($x['xxGkUuid'] ?? '');
        if ($uuid) return $uuid;
    }
    return null;
}


/**
 * Obtiene todas las líneas de una factura AP. Se usa para las reposiciones
 * de la división Molinos, donde Oracle relaciona cada gasto mediante
 * invoiceLines.ReferenceKeyTwo -> EXM_EXPENSES.
 */
function oracle_pagos_invoice_lines(array $cfg, string $invoiceId): array
{
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '') return [];

    $base = oracle_pagos_api_base($cfg);
    $offset = 0;
    $limit = 200;
    $salida = [];

    do {
        $url = $base . '/invoices/' . rawurlencode($invoiceId) . '/child/invoiceLines?'
            . http_build_query([
                'limit' => $limit,
                'offset' => $offset,
                'onlyData' => 'true'
            ], '', '&', PHP_QUERY_RFC3986);
        $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'InvoiceLines ' . $invoiceId);
        $items = oracle_pagos_items($r);
        foreach ($items as $x) {
            if (is_array($x)) $salida[] = $x;
        }
        $offset += count($items);
        $more = oracle_pagos_has_more($r);
    } while ($more && count($items) > 0);

    return $salida;
}

/** Obtiene un gasto individual del recurso /expenses/{ExpenseId}. */
function oracle_pagos_expense_item(array $cfg, string $expenseId): array
{
    $expenseId = trim($expenseId);
    if ($expenseId === '') return [];

    $url = oracle_pagos_api_base($cfg) . '/expenses/' . rawurlencode($expenseId);
    $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'Expense ' . $expenseId);
    return is_array($r['json']) ? $r['json'] : [];
}

/**
 * Obtiene todos los gastos que pertenecen a un ExpenseReportId.
 * Este camino es importante para reposiciones/no deducibles: pueden no tener UUID,
 * pero Oracle mantiene la relación estable ExpenseReportId -> ExpenseId.
 */
function oracle_pagos_expenses_report(array $cfg, string $expenseReportId): array
{
    $expenseReportId = trim($expenseReportId);
    if ($expenseReportId === '') return [];

    $base = oracle_pagos_api_base($cfg);
    $offset = 0;
    $limit = 200;
    $salida = [];

    do {
        $url = $base . '/expenses?' . http_build_query([
            'q' => 'ExpenseReportId=' . $expenseReportId,
            'limit' => $limit,
            'offset' => $offset,
            'onlyData' => 'true'
        ], '', '&', PHP_QUERY_RFC3986);

        $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'Expenses report ' . $expenseReportId);
        $items = oracle_pagos_items($r);
        foreach ($items as $x) {
            if (!is_array($x)) continue;
            $id = trim((string)($x['ExpenseId'] ?? ''));
            if ($id === '') continue;
            $salida[$id] = $x;
        }

        $offset += count($items);
        $more = oracle_pagos_has_more($r);
    } while ($more && count($items) > 0);

    return array_values($salida);
}

function oracle_pagos_job_cancelado(PDO $pdo, string $job): bool
{
    $st = $pdo->prepare('SELECT cancel_requested FROM oracle_pagos_sync_jobs WHERE id_job=? LIMIT 1');
    $st->execute([$job]);
    return ((int)$st->fetchColumn()) === 1;
}

function oracle_pagos_bool($valor): bool
{
    if (is_bool($valor)) return $valor;
    if (is_int($valor) || is_float($valor)) return ((int)$valor) !== 0;
    $v = strtoupper(trim((string)$valor));
    return in_array($v, ['1','Y','YES','TRUE','SI','SÍ'], true);
}

/**
 * Devuelve todos los pagos Oracle relacionados con una factura AP.
 * Primero usa PaidInvoicesFinder por número y solicita relatedInvoices expandido.
 * Si Oracle no expande el hijo, valida cada CheckId contra /child/relatedInvoices.
 */
function oracle_pagos_pagos_factura(array $cfg, string $invoiceId, string $invoiceNumber): array
{
    $invoiceId = trim($invoiceId);
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceId === '' || $invoiceNumber === '') return [];

    $base = oracle_pagos_api_base($cfg);
    $finder = 'PaidInvoicesFinder;InvoiceNumber=' . $invoiceNumber;
    $url = $base . '/payablesPayments?' . http_build_query([
        'finder' => $finder,
        'expand' => 'relatedInvoices',
        'limit' => 100,
        'offset' => 0,
        'onlyData' => 'true'
    ], '', '&', PHP_QUERY_RFC3986);

    $r = oracle_pagos_ok(oracle_pagos_get($cfg, $url), 'PayablesPayments factura ' . $invoiceNumber);
    $pagos = [];

    foreach (oracle_pagos_items($r) as $pago) {
        $checkId = trim((string)($pago['CheckId'] ?? ''));
        if ($checkId === '') continue;

        $relacionados = [];
        if (isset($pago['relatedInvoices']['items']) && is_array($pago['relatedInvoices']['items'])) {
            $relacionados = $pago['relatedInvoices']['items'];
        } elseif (isset($pago['relatedInvoices']) && is_array($pago['relatedInvoices']) && array_is_list($pago['relatedInvoices'])) {
            $relacionados = $pago['relatedInvoices'];
        }

        if (!$relacionados) {
            $u2 = $base . '/payablesPayments/' . rawurlencode($checkId) . '/child/relatedInvoices?limit=100&onlyData=true';
            $r2 = oracle_pagos_ok(oracle_pagos_get($cfg, $u2), 'RelatedInvoices pago ' . $checkId);
            $relacionados = oracle_pagos_items($r2);
        }

        foreach ($relacionados as $ri) {
            if ((string)($ri['InvoiceId'] ?? '') !== $invoiceId) continue;

            $pagos[] = [
                'CheckId' => $checkId,
                'PaymentId' => $pago['PaymentId'] ?? null,
                'PaymentReference' => $pago['PaymentReference'] ?? null,
                'PaperDocumentNumber' => $pago['PaperDocumentNumber'] ?? null,
                'PaymentNumber' => $pago['PaymentNumber'] ?? null,
                'PaymentAmount' => $pago['PaymentAmount'] ?? null,
                'PaymentCurrency' => $pago['PaymentCurrency'] ?? null,
                'PaymentDate' => $pago['PaymentDate'] ?? null,
                'AccountingDate' => $pago['AccountingDate'] ?? null,
                'PaymentDescription' => $pago['PaymentDescription'] ?? null,
                'PaymentStatus' => $pago['PaymentStatus'] ?? null,
                'AccountingStatus' => $pago['AccountingStatus'] ?? null,
                'ReconciledFlag' => oracle_pagos_bool($pago['ReconciledFlag'] ?? false),
                'ClearingDate' => $pago['ClearingDate'] ?? null,
                'ClearingValueDate' => $pago['ClearingValueDate'] ?? null,
                'ClearingAmount' => $pago['ClearingAmount'] ?? null,
                'PaymentMethodCode' => $pago['PaymentMethodCode'] ?? null,
                'PaymentType' => $pago['PaymentType'] ?? null,
                'Payee' => $pago['Payee'] ?? null,
                'SupplierNumber' => $pago['SupplierNumber'] ?? null,
                'VoidDate' => $pago['VoidDate'] ?? null,
                'VoidAccountingDate' => $pago['VoidAccountingDate'] ?? null,
                'InvoicePaymentId' => $ri['InvoicePaymentId'] ?? null,
                'InvoicePaymentAmount' => $ri['InvoicePaymentAmount'] ?? ($ri['AmountPaidPaymentCurrency'] ?? null),
                'AmountPaidPaymentCurrency' => $ri['AmountPaidPaymentCurrency'] ?? null,
                'AmountPaidInvoiceCurrency' => $ri['AmountPaidInvoiceCurrency'] ?? null,
                'InvoicePaymentStatus' => $ri['InvoicePaymentStatus'] ?? null,
                'InstallmentNumber' => $ri['InstallmentNumber'] ?? null,
                'raw_payment' => $pago,
                'raw_related_invoice' => $ri,
            ];
        }
    }

    usort($pagos, static function(array $a, array $b): int {
        return strcmp((string)($a['PaymentDate'] ?? ''), (string)($b['PaymentDate'] ?? ''));
    });
    return $pagos;
}
