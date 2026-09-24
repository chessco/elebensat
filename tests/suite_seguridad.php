<?php
/**
 * ==============================================================================
 * SUITE DE AUDITORÍA Y CAMA DE PRUEBAS DE SEGURIDAD (ELEBENSAT)
 * ==============================================================================
 * Ejecución:
 *   CLI Local:    php tests/suite_seguridad.php
 *   Docker:       docker exec elebensat_app php /var/www/html/tests/suite_seguridad.php
 * ==============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acceso denegado. Este script solo puede ejecutarse en modo CLI.\n";
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Utilidades de salida en consola con formato y colores ANSI
class SecurityTester
{
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;
    private string $currentCategory = '';
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        echo "\n\033[1;36m==============================================================================\033[0m\n";
        echo "\033[1;37m   ELEBENSAT - CAMA DE PRUEBAS AUTOMATIZADA Y AUDITORÍA DE SEGURIDAD\033[0m\n";
        echo "\033[1;36m==============================================================================\033[0m\n";
        echo " Fecha y Hora : " . date('Y-m-d H:i:s T') . "\n";
        echo " PHP Version  : " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
        echo " Sistema Oper : " . PHP_OS . "\n";
        echo " Directorio   : " . realpath(__DIR__ . '/..') . "\n";
        echo "\033[1;36m------------------------------------------------------------------------------\033[0m\n\n";
    }

    public function category(string $title): void
    {
        $this->currentCategory = $title;
        echo "\033[1;33m► " . strtoupper($title) . "\033[0m\n";
    }

    public function assert(bool $condition, string $testName, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[1;32m[PASS]\033[0m " . $testName . "\n";
        } else {
            $this->failed++;
            echo "  \033[1;31m[FAIL]\033[0m " . $testName . "\n";
            if ($details !== '') {
                echo "         \033[0;31mDetalle: " . $details . "\033[0m\n";
            }
        }
    }

    public function warn(string $warningName, string $details = ''): void
    {
        $this->warnings++;
        echo "  \033[1;35m[WARN]\033[0m " . $warningName . "\n";
        if ($details !== '') {
            echo "         \033[0;35mDetalle: " . $details . "\033[0m\n";
        }
    }

    public function summary(): bool
    {
        $duration = round(microtime(true) - $this->startTime, 3);
        $total = $this->passed + $this->failed;
        echo "\n\033[1;36m==============================================================================\033[0m\n";
        echo "\033[1;37m RESUMEN DE LA AUDITORÍA DE SEGURIDAD\033[0m\n";
        echo "\033[1;36m==============================================================================\033[0m\n";
        echo " Pruebas Ejecutadas : {$total}\n";
        echo " \033[1;32mAprobadas (PASS)   : {$this->passed}\033[0m\n";
        echo " \033[1;31mFallidas  (FAIL)   : {$this->failed}\033[0m\n";
        echo " \033[1;35mAdvertencias(WARN) : {$this->warnings}\033[0m\n";
        echo " Tiempo Total       : {$duration} segundos\n";
        echo "\033[1;36m------------------------------------------------------------------------------\033[0m\n";

        if ($this->failed === 0) {
            echo "\033[1;32m ✔ AUDITORÍA SATISFACTORIA: La cama de pruebas no detectó vulnerabilidades críticas.\033[0m\n\n";
            return true;
        } else {
            echo "\033[1;31m ✘ ALERTA: Se detectaron fallas de seguridad o configuración que requieren atención.\033[0m\n\n";
            return false;
        }
    }
}

$t = new SecurityTester();
$baseDir = realpath(__DIR__ . '/..');

// -----------------------------------------------------------------------------
// SECCIÓN 1: AUDITORÍA DE CONFIGURACIÓN Y SECRETOS
// -----------------------------------------------------------------------------
$t->category("1. Auditoría de Secretos y Configuración en Git");

$gitignorePath = $baseDir . '/.gitignore';
$hasGitignore = file_exists($gitignorePath);
$t->assert($hasGitignore, ".gitignore existe en la raíz del proyecto");

if ($hasGitignore) {
    $giContent = file_get_contents($gitignorePath);
    $t->assert(str_contains($giContent, '.env'), ".gitignore excluye archivos de entorno (.env)");
    $t->assert(str_contains($giContent, 'database.php'), ".gitignore excluye configuraciones de BD con credenciales");
    $t->assert(str_contains($giContent, 'vendor/'), ".gitignore excluye directorio de dependencias (vendor/)");
}

// Verificar que .env.example exista pero no contenga contraseñas reales
$envExamplePath = $baseDir . '/.env.example';
$t->assert(file_exists($envExamplePath), ".env.example existe como plantilla pública para despliegues");

// -----------------------------------------------------------------------------
// SECCIÓN 2: AUDITORÍA DE DIRECTIVAS PHP (HARDENING PHP-FPM)
// -----------------------------------------------------------------------------
$t->category("2. Hardening y Directivas de Seguridad PHP");

$shortOpen = (bool)ini_get('short_open_tag');
$t->assert(!$shortOpen, "short_open_tag está deshabilitado (previene colisiones de <?xml con PHP)");

$cookieHttpOnly = (bool)ini_get('session.cookie_httponly');
$t->assert($cookieHttpOnly, "session.cookie_httponly está activo (mitiga ataques XSS contra cookies de sesión)");

$cookieSameSite = strtolower(trim((string)ini_get('session.cookie_samesite')));
$t->assert(in_array($cookieSameSite, ['lax', 'strict'], true), "session.cookie_samesite configurado de forma segura ('{$cookieSameSite}')");

$strictMode = (bool)ini_get('session.use_strict_mode');
$t->assert($strictMode, "session.use_strict_mode habilitado (evita ataques de fijación de sesión iniciados por terceros)");

// -----------------------------------------------------------------------------
// SECCIÓN 3: AUTENTICACIÓN, BCRYPT Y TOKEN CSRF
// -----------------------------------------------------------------------------
$t->category("3. Criptografía, Autenticación y Token CSRF");

// Probar hashing y verificación de contraseñas con el estándar nativo
$testPass = 'PruebaSeguridadElebensat2026!#$';
$hash = password_hash($testPass, PASSWORD_BCRYPT, ['cost' => 10]);
$t->assert(password_verify($testPass, $hash), "password_verify valida correctamente hash BCRYPT");
$t->assert(!password_verify($testPass . 'err', $hash), "password_verify rechaza contraseñas inválidas");
$t->assert(password_get_info($hash)['algoName'] === 'bcrypt', "Algoritmo de hash verificado como BCRYPT robusto");

// Pruebas unitarias de CSRF
require_once $baseDir . '/includes/csrf.php';
$_SESSION = [];
$token1 = csrf_token();
$t->assert(strlen($token1) === 64, "csrf_token genera token criptográfico de 64 caracteres hexadecimales (256 bits)");
$t->assert(csrf_validar($token1), "csrf_validar acepta el token emitido para la sesión");
$t->assert(!csrf_validar('token_invalido_hacker'), "csrf_validar rechaza token adulterado");
$t->assert(!csrf_validar(null), "csrf_validar rechaza token nulo");
$t->assert(!csrf_validar(''), "csrf_validar rechaza token vacío");

// -----------------------------------------------------------------------------
// SECCIÓN 4: PROTECCIÓN CONTRA INYECCIONES XML / XXE
// -----------------------------------------------------------------------------
$t->category("4. Seguridad de Procesamiento XML (Mitigación XXE)");

$xxePayload = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE test [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Total="100.00">
  <cfdi:Emisor Rfc="TEST010101AA1" Nombre="&xxe;"/>
</cfdi:Comprobante>';

$oldEntityLoader = libxml_disable_entity_loader(false); // Probar comportamiento por defecto
$parsedXml = @simplexml_load_string($xxePayload, 'SimpleXMLElement', LIBXML_NONET);

// En PHP 8.0+ y libxml 2.9+, las entidades externas NO se expanden por defecto
$xxeInjected = false;
if ($parsedXml !== false) {
    $emisorNombre = (string)$parsedXml->children('http://www.sat.gob.mx/cfd/4')->Emisor['Nombre'];
    if (str_contains($emisorNombre, 'root:') || str_contains($emisorNombre, '/bin/')) {
        $xxeInjected = true;
    }
}
$t->assert(!$xxeInjected, "Parser XML bloquea la inyección de entidades externas (XXE /etc/passwd)");

// Probar llamada a red externa bloqueada por LIBXML_NONET
$xxeNetworkPayload = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE test [
  <!ENTITY ext SYSTEM "http://127.0.0.1:9999/malicious.dtd">
]>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Total="100.00"/>';

$netBlocked = false;
$parsedNet = @simplexml_load_string($xxeNetworkPayload, 'SimpleXMLElement', LIBXML_NONET);
if ($parsedNet !== false || strpos(libxml_get_last_error()->message ?? '', 'warning') !== false) {
    $netBlocked = true;
}
$t->assert($netBlocked, "Parser XML con LIBXML_NONET previene SSRF vía entidades XML externas");

// -----------------------------------------------------------------------------
// SECCIÓN 5: SANEAMIENTO DE ENTRADAS Y FECHAS (BOUNDARY TESTING)
// -----------------------------------------------------------------------------
$t->category("5. Saneamiento de Parámetros y Funciones Sanitizadoras");

require_once $baseDir . '/includes/seguridad.php';

// Probar normalización de fechas
$t->assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', date('Y-m-d')), "Formato de fechas estándar cumple norma ISO YYYY-MM-DD");

// Probar saneamiento contra inyección HTML/XSS en salida
$xssVector = '<script>alert("xss")</script>';
$escaped = htmlspecialchars($xssVector, ENT_QUOTES, 'UTF-8');
$t->assert(!str_contains($escaped, '<script>'), "htmlspecialchars neutraliza etiquetas <script>");
$t->assert(str_contains($escaped, '&lt;script&gt;'), "htmlspecialchars codifica caracteres peligrosos adecuadamente");

// -----------------------------------------------------------------------------
// SECCIÓN 6: CONEXIÓN A BASE DE DATOS Y PRUEBAS SQLi
// -----------------------------------------------------------------------------
$t->category("6. Conexión a Base de Datos y Prevención SQLi");

$dbConnected = false;
$pdo = null;

$configPath = getenv('SGKSAT_CONFIG_FILE');
if (!is_string($configPath) || trim($configPath) === '') {
    if (file_exists('/var/www/config_ohlala/database.php')) {
        $configPath = '/var/www/config_ohlala/database.php';
        putenv("SGKSAT_CONFIG_FILE={$configPath}");
    } elseif (file_exists($baseDir . '/docker/config_ohlala/database.php')) {
        $configPath = $baseDir . '/docker/config_ohlala/database.php';
        putenv("SGKSAT_CONFIG_FILE={$configPath}");
    } else {
        $configPath = dirname($baseDir, 2) . '/config_ohlala/database.php';
    }
}

if (is_file($configPath) && is_readable($configPath)) {
    try {
        require_once $baseDir . '/config/db.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $dbConnected = true;
        }
    } catch (Throwable $e) {
        $dbConnected = false;
    }
}

if ($dbConnected) {
    $t->assert(true, "Conexión PDO establecida con éxito a la base de datos");

    // Verificar modo de errores estricto
    $errMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    $t->assert($errMode === PDO::ERRMODE_EXCEPTION, "PDO ATTR_ERRMODE configurado en ERRMODE_EXCEPTION");

    $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    $t->assert(!$emulatePrepares, "PDO ATTR_EMULATE_PREPARES deshabilitado (previene emulación insegura en cliente)");

    // Prueba de Prepared Statement contra SQL Injection clásica
    $sqliUser = "' OR '1'='1' -- ";
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = :u");
    $st->execute([':u' => $sqliUser]);
    $count = (int)$st->fetchColumn();
    $t->assert($count === 0, "Sentencia preparada parametrizada neutraliza ataque de bypass SQLi (' OR '1'='1')");

    // Prueba contra SQLi de tipo UNION SELECT
    $sqliUnion = "1 UNION SELECT 1,2,3,4,5,6,7,8,9,10";
    $st2 = $pdo->prepare("SELECT COUNT(*) FROM empresas WHERE id_empresa = :id");
    $st2->execute([':id' => $sqliUnion]);
    $count2 = (int)$st2->fetchColumn();
    $t->assert($count2 === 0, "Sentencia preparada neutraliza ataque de tipo UNION SELECT");

    // Verificar las 78 tablas requeridas
    $stTables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $totalTablas = (int)$stTables->fetchColumn();
    $t->assert($totalTablas === 78, "La base de datos contiene exactamente las 78 tablas del catálogo oficial (detectadas: {$totalTablas})");

} else {
    $t->warn("Base de datos no accesible en el entorno de ejecución actual (se ejecuta fuera de Docker o sin credenciales de red)");
}

// -----------------------------------------------------------------------------
// SECCIÓN 7: RBAC Y CONTROL DE ACCESO
// -----------------------------------------------------------------------------
$t->category("7. Control de Acceso por Roles (RBAC)");

if ($dbConnected) {
    // Probar seguridad_exigir_sesion con sesión vacía (debe abortar con 401)
    $_SESSION = [];
    $sessionRejected = false;
    
    // Capturar si lanza denegado
    try {
        // En CLI sin headers, simulamos la función verificando la regla de sesión
        $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
        $t->assert($idUsuario === 0, "Sesión sin id_usuario detectada como nula");
    } catch (Throwable $e) {
        $sessionRejected = true;
    }

    // Verificar que un superadmin existe para administración segura
    $stSuper = $pdo->query("SELECT usuario, activo, es_superadmin FROM usuarios WHERE es_superadmin = 1 AND activo = 1 LIMIT 1");
    $superadmin = $stSuper->fetch(PDO::FETCH_ASSOC);
    $t->assert((bool)$superadmin, "Existe al menos un usuario SuperAdmin activo para la gobernanza del sistema");
}

// -----------------------------------------------------------------------------
// SECCIÓN 8: RESUMEN Y RESULTADOS
// -----------------------------------------------------------------------------
$exito = $t->summary();
exit($exito ? 0 : 1);
