<?php
/**
 * SGKSAT - Cargador central de conexión.
 *
 * SEGURIDAD:
 * Este archivo permanece dentro del proyecto, pero NO contiene credenciales.
 * Las credenciales reales viven fuera de htdocs, en la carpeta config_ohlala.
 *
 * Estructura esperada por defecto:
 *   C:\xamppn\htdocs\sateleben\...
 *   C:\xamppn\config_ohlala\database.php
 *
 * Si el servidor usa otra estructura, puede definir SGKSAT_CONFIG_FILE con la
 * ruta absoluta al archivo database.php.
 */
declare(strict_types=1);

if (!function_exists('sgksat_fail_service')) {
    function sgksat_fail_service(string $logMessage): never
    {
        error_log('[SGKSAT] ' . $logMessage);
        http_response_code(503);
        exit('No fue posible conectar con el servicio. Intente nuevamente en unos minutos.');
    }
}

$configPath = getenv('SGKSAT_CONFIG_FILE');

if (!is_string($configPath) || trim($configPath) === '') {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $documentRoot = is_string($documentRoot) ? trim($documentRoot) : '';

    if ($documentRoot !== '') {
        // Ejemplo: C:\xamppn\htdocs -> C:\xamppn\config_ohlala\database.php
        $configPath = dirname(rtrim($documentRoot, "\\/"))
            . DIRECTORY_SEPARATOR . 'config_ohlala'
            . DIRECTORY_SEPARATOR . 'database.php';
    } else {
        // Respaldo para ejecución por CLI o entornos donde DOCUMENT_ROOT no exista.
        // Desde ...\htdocs\sateleben\config\db.php subimos hasta ...\xamppn\
        $configPath = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'config_ohlala'
            . DIRECTORY_SEPARATOR . 'database.php';
    }
}

if (!is_file($configPath) || !is_readable($configPath)) {
    sgksat_fail_service('No se encontró o no se puede leer el archivo externo de configuración: ' . $configPath);
}

try {
    $config = require $configPath;
} catch (Throwable $e) {
    sgksat_fail_service('No fue posible cargar la configuración externa: ' . $e->getMessage());
}

if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
    sgksat_fail_service('El archivo externo de configuración no contiene la sección db esperada.');
}

$dbConfig = $config['db'];
$required = ['host', 'database', 'user', 'password'];
foreach ($required as $key) {
    if (!array_key_exists($key, $dbConfig) || !is_string($dbConfig[$key])) {
        sgksat_fail_service("Falta o es inválido el parámetro de configuración db.$key.");
    }
}

$host = trim($dbConfig['host']);
$db = trim($dbConfig['database']);
$user = trim($dbConfig['user']);
$pass = $dbConfig['password'];
$charset = isset($dbConfig['charset']) && is_string($dbConfig['charset'])
    ? trim($dbConfig['charset'])
    : 'utf8mb4';
$port = isset($dbConfig['port']) ? (int)$dbConfig['port'] : 3306;

if ($host === '' || $db === '' || $user === '' || $port < 1 || $port > 65535) {
    sgksat_fail_service('La configuración externa contiene parámetros de conexión inválidos.');
}

$allowedCharsets = ['utf8mb4', 'utf8'];
if (!in_array(strtolower($charset), $allowedCharsets, true)) {
    $charset = 'utf8mb4';
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    $port,
    $db,
    $charset
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
    PDO::ATTR_PERSISTENT         => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Nunca enviar el error técnico ni credenciales al navegador.
    sgksat_fail_service('Error de conexión a MariaDB: ' . $e->getMessage());
}

if (!function_exists('validar_usuario')) {
    function validar_usuario($usuario)
    {
        $usuario = strtolower(trim((string)$usuario));
        return preg_match('/^[a-z0-9._]{4,50}$/', $usuario) ? $usuario : false;
    }
}

if (!function_exists('filtrar_entrada')) {
    function filtrar_entrada($dato)
    {
        if ($dato === null) {
            return false;
        }
        $limpio = trim(strip_tags((string)$dato));
        return preg_match('/^[a-zA-Z0-9ñÑáéíóúÁÉÍÓÚ .-]+$/u', $limpio)
            ? strtoupper($limpio)
            : false;
    }
}
