<?php
declare(strict_types=1);

// This harness never loads storage/db_config.json. It connects only to a temporary,
// explicitly named database on the local SQLEXPRESS instance using Windows authentication.
$testDatabase = (string) getenv('SENDMAILS_TEST_DATABASE');
if (!preg_match('/^SendMails_Agility_Test_[a-f0-9]{12}$/D', $testDatabase)) {
    throw new RuntimeException('Define SENDMAILS_TEST_DATABASE con el nombre de una base temporal de pruebas.');
}
require_once __DIR__ . '/../vendor/autoload.php';
$testStorage = sys_get_temp_dir() . '/' . $testDatabase;
if (!is_dir($testStorage)) mkdir($testStorage, 0700, true);
$configSource = file_get_contents(__DIR__ . '/../app/config.php');
$configSource = str_replace("const STORAGE_PATH = BASE_PATH . '/storage';", 'const STORAGE_PATH = ' . var_export($testStorage, true) . ';', $configSource);
eval(preg_replace('/^<\?php\s*/', '', $configSource));
putenv('INVOICE_CRYPTO_KEY=isolated-test-key-not-for-production');
if (!is_file(DEFAULT_TEMPLATE_PATH)) copy(__DIR__ . '/../storage/default_template.html', DEFAULT_TEMPLATE_PATH);
require_once __DIR__ . '/../app/helpers.php';
configure_server_timezone();

final class Database
{
    private static ?PDO $connection = null;
    public static function pdo(): PDO
    {
        if (!self::$connection) {
            self::$connection = new PDO('sqlsrv:Server=.\SQLEXPRESS;Database=' . getenv('SENDMAILS_TEST_DATABASE') . ';Encrypt=no;TrustServerCertificate=yes', null, null,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::SQLSRV_ATTR_ENCODING=>PDO::SQLSRV_ENCODING_UTF8]);
            if (self::$connection->query('SELECT DB_NAME()')->fetchColumn() !== getenv('SENDMAILS_TEST_DATABASE')) throw new RuntimeException('Base inesperada.');
        }
        return self::$connection;
    }
    public static function configExists(): bool { return true; }
    public static function loadConfig(): array
    {
        return ['server'=>'.\SQLEXPRESS','database'=>getenv('SENDMAILS_TEST_DATABASE'),'username'=>'fixture','password'=>'fixture-only','encrypt'=>'no','trust_server_certificate'=>true];
    }
    public static function connect(array $config): PDO
    {
        if (($config['database'] ?? '') !== getenv('SENDMAILS_TEST_DATABASE')) throw new RuntimeException('Conexion fuera de la base de pruebas rechazada.');
        return self::pdo();
    }
}
foreach (glob(__DIR__ . '/../app/*.php') as $file) {
    if (in_array(basename($file), ['bootstrap.php','config.php','helpers.php','Database.php'], true)) continue;
    require_once $file;
}
if (PHP_SAPI !== 'cli') session_start();
$_SESSION['auth_user_id'] = $_SESSION['auth_user_id'] ?? 1;
$_SESSION['branch_id'] = $_SESSION['branch_id'] ?? 1;
$_SESSION['auth_last_activity'] = time();
