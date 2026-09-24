<?php
declare(strict_types=1);

require_once __DIR__ . '/contpaq_crypto.php';

/**
 * Abre SQL Server de CONTPAQi usando el driver nativo sqlsrv.
 * Esta es la misma vía validada con sqlsrv_connect() en el equipo.
 *
 * @return resource
 */
function contpaq_sqlserver_conectar(array $cfg)
{
    $servidor = trim((string)($cfg['servidor'] ?? ''));
    $base = trim((string)($cfg['base_datos'] ?? ''));
    $usuario = trim((string)($cfg['usuario'] ?? ''));
    $password = contpaq_descifrar((string)($cfg['password_enc'] ?? ''));

    if ($servidor === '' || $base === '' || $usuario === '' || $password === '') {
        throw new RuntimeException('La configuración CONTPAQi está incompleta.');
    }

    if (!function_exists('sqlsrv_connect')) {
        throw new RuntimeException(
            'PHP no tiene disponible el driver sqlsrv para conectarse a SQL Server.'
        );
    }

    $opciones = [
        'Database' => $base,
        'UID' => $usuario,
        'PWD' => $password,
        'CharacterSet' => 'UTF-8',
        'Encrypt' => false,
        'TrustServerCertificate' => true,
        'LoginTimeout' => 15,
        // Evita objetos DateTime al copiar fechas a MySQL.
        'ReturnDatesAsStrings' => true,
    ];

    $conn = @sqlsrv_connect($servidor, $opciones);
    if ($conn === false) {
        contpaq_sqlserver_log_errores('conectar');
        throw new RuntimeException(
            'No fue posible conectar con SQL Server de CONTPAQi. Revise servidor, base, usuario y contraseña.'
        );
    }

    return $conn;
}

/**
 * Ejecuta una consulta SQL Server y corta con un mensaje limpio si falla.
 *
 * @param resource $conn
 * @return resource
 */
function contpaq_sqlserver_query($conn, string $sql, array $params = [])
{
    $stmt = @sqlsrv_query($conn, $sql, $params, ['Scrollable' => SQLSRV_CURSOR_FORWARD]);
    if ($stmt === false) {
        $detalle = contpaq_sqlserver_detalle_error();
        contpaq_sqlserver_log_errores('query');
        throw new RuntimeException(
            $detalle !== ''
                ? 'CONTPAQi SQL: ' . $detalle
                : 'No fue posible consultar SQL Server de CONTPAQi.'
        );
    }
    return $stmt;
}

/**
 * @param resource $stmt
 */
function contpaq_sqlserver_fetch($stmt): ?array
{
    $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($fila === false) {
        contpaq_sqlserver_log_errores('fetch');
        throw new RuntimeException('No fue posible leer la respuesta de SQL Server de CONTPAQi.');
    }
    return $fila === null ? null : $fila;
}

/** @param resource|null $stmt */
function contpaq_sqlserver_liberar($stmt): void
{
    if ($stmt) {
        @sqlsrv_free_stmt($stmt);
    }
}

/** @param resource|null $conn */
function contpaq_sqlserver_cerrar($conn): void
{
    if ($conn) {
        @sqlsrv_close($conn);
    }
}


function contpaq_sqlserver_detalle_error(): string
{
    if (!function_exists('sqlsrv_errors')) {
        return '';
    }
    $errores = sqlsrv_errors(SQLSRV_ERR_ALL);
    if (!$errores || !is_array($errores)) {
        return '';
    }
    $mensajes = [];
    foreach ($errores as $e) {
        $m = trim((string)($e['message'] ?? ''));
        if ($m !== '') {
            $mensajes[] = $m;
        }
    }
    return implode(' | ', array_unique($mensajes));
}

function contpaq_sqlserver_log_errores(string $contexto): void
{
    if (!function_exists('sqlsrv_errors')) {
        return;
    }
    $errores = sqlsrv_errors(SQLSRV_ERR_ALL);
    if ($errores) {
        error_log('[CONTPAQ SQLSRV ' . $contexto . '] ' . json_encode($errores, JSON_UNESCAPED_UNICODE));
    }
}

function contpaq_config_empresa(PDO $pdo, int $idEmpresa): array
{
    $st = $pdo->prepare(
        'SELECT id_empresa, activo, servidor, base_datos, base_datos_comercial, usuario, password_enc,
                ultima_prueba, ultimo_error, ultima_sincronizacion
           FROM empresa_contpaq_config
          WHERE id_empresa = ?
          LIMIT 1'
    );
    $st->execute([$idEmpresa]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        throw new RuntimeException('La empresa no tiene configuración CONTPAQi.');
    }
    if ((int)$r['activo'] !== 1) {
        throw new RuntimeException('La conexión CONTPAQi de esta empresa está desactivada.');
    }
    return $r;
}



/**
 * Obtiene las columnas reales de una tabla de CONTPAQi.
 * Esto permite trabajar con distintas versiones de CONTPAQi donde algunos
 * campos cambian o no existen.
 *
 * @param resource $conn
 * @return array<string,string> mapa lowercase => nombre real
 */
function contpaq_sqlserver_columnas($conn, string $tabla): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) {
        throw new RuntimeException('Nombre de tabla CONTPAQi inválido.');
    }

    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION";
    $stmt = contpaq_sqlserver_query($conn, $sql, [$tabla]);
    $cols = [];
    while (($r = contpaq_sqlserver_fetch($stmt)) !== null) {
        $nombre = (string)($r['COLUMN_NAME'] ?? '');
        if ($nombre !== '') {
            $cols[strtolower($nombre)] = $nombre;
        }
    }
    contpaq_sqlserver_liberar($stmt);
    return $cols;
}

/**
 * Devuelve una expresión SELECT segura: usa la columna cuando existe o NULL
 * con el alias esperado cuando esa versión de CONTPAQi no la contiene.
 */
function contpaq_sqlserver_campo_compatible(array $columnas, string $aliasTabla, string $campo): string
{
    $real = $columnas[strtolower($campo)] ?? null;
    if ($real !== null) {
        return $aliasTabla . '.[' . str_replace(']', ']]', $real) . '] AS [' . str_replace(']', ']]', $campo) . ']';
    }
    return 'NULL AS [' . str_replace(']', ']]', $campo) . ']';
}

/**
 * Exige una columna indispensable y devuelve su nombre real.
 */
function contpaq_sqlserver_columna_requerida(array $columnas, string $tabla, string $campo): string
{
    $real = $columnas[strtolower($campo)] ?? null;
    if ($real === null) {
        throw new RuntimeException("La tabla {$tabla} de CONTPAQi no contiene la columna requerida {$campo}.");
    }
    return $real;
}

function contpaq_fecha_sql(string $fecha, bool $fin = false): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        throw new RuntimeException('La fecha indicada no es válida.');
    }

    /*
     * IMPORTANTE CON SQL SERVER / CONTPAQi:
     * No enviar YYYY-MM-DD como nvarchar a una columna DATETIME. Dependiendo
     * del idioma/DATEFORMAT de la instancia, SQL Server puede interpretarlo
     * como YDM/DMY y fechas como 2026-07-25 terminan en:
     *   "La conversión del tipo de datos nvarchar en datetime produjo un
     *    valor fuera de intervalo".
     *
     * El formato ISO sin separadores YYYYMMDD es inequívoco para SQL Server.
     */
    return $d->format('Ymd') . ($fin ? ' 23:59:59' : ' 00:00:00');
}
