<?php
// Native metadata characterization plus production bridge parity.
require $argv[1];
require_once __DIR__.'/../../src/Console/ResourceGenerator.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Encore\Admin\Console\ResourceGenerator;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;

class ParityModel extends Model { protected $table = 'records'; protected $connection = 'parity'; }
class ParityRenderer extends ResourceGenerator {
    public array $columns = [];
    protected function getTableColumns() { return $this->columns; }
}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function normalizeDefault($value, $driver, $maria) {
    if ($driver === 'sqlite') {
        if ($value === 'NULL') return null;
        if ($value !== null && preg_match("/^'(.*)'$/s", $value, $m)) return str_replace("''", "'", $m[1]);
        return $value;
    }
    if (!$maria) return $value;
    if ($value === null || $value === 'NULL') return null;
    if (preg_match("/^'(.*)'$/s", $value, $m)) {
        return strtr($m[1], ['\\0'=>"\0", "\\'"=>"'", '\\"'=>'"', '\\b'=>"\b", '\\n'=>"\n", '\\r'=>"\r", '\\t'=>"\t", '\\Z'=>"\x1a", '\\\\'=>'\\', '\\%'=>'%', '\\_'=>'_', "''"=>"'"]);
    }
    return ['current_timestamp()'=>'CURRENT_TIMESTAMP', 'curdate()'=>'CURRENT_DATE', 'curtime()'=>'CURRENT_TIME'][$value] ?? $value;
}
$driver = $argv[2] ?? 'sqlite';
check(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true), 'explicit supported research driver');
if ($driver !== 'sqlite') check(getenv('PARITY_DISPOSABLE') === '1', 'disposable database execution explicitly enabled');
$manager = new Manager();
$config = ['driver'=>$driver, 'prefix'=>'parity_'];
if ($driver === 'sqlite') {
    $file = tempnam(sys_get_temp_dir(), 'schema-parity-');
    $config['database'] = $file;
    $params = ['driver'=>'pdo_sqlite', 'path'=>$file];
} else {
    // This script must only receive a dedicated disposable test database.
    $config += ['unix_socket'=>getenv('PARITY_SOCKET') ?: '', 'host'=>getenv('PARITY_HOST') ?: '127.0.0.1', 'port'=>getenv('PARITY_PORT') ?: '3306', 'database'=>getenv('PARITY_DATABASE') ?: 'schema_parity', 'username'=>getenv('PARITY_USER') ?: 'root', 'password'=>getenv('PARITY_PASSWORD') ?: '', 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci'];
    check($config['database'] === 'schema_parity', 'dedicated schema_parity database required');
    $params = ['driver'=>'pdo_mysql', 'unix_socket'=>$config['unix_socket'], 'host'=>$config['host'], 'port'=>$config['port'], 'dbname'=>$config['database'], 'user'=>$config['username'], 'password'=>$config['password'], 'charset'=>'utf8mb4'];
    if ($params['unix_socket'] === '') unset($params['unix_socket']);
}
$manager->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$manager->addConnection($config, 'parity');
$manager->setAsGlobal(); $manager->bootEloquent();
$conn = $manager->getConnection('parity');
$reference = DriverManager::getConnection($params);
$platform = $reference->getDatabasePlatform();
foreach (['enum','geometry','geometrycollection','linestring','polygon','multilinestring','multipoint','multipolygon','point'] as $type) $platform->registerDoctrineTypeMapping($type, 'string');
$maria = $driver !== 'sqlite' && $conn->isMaria();
// DBAL 2 retains MariaDB JSON as text; only DBAL 3 recovers its check constraint.
$nativeJsonGap = $maria && interface_exists(Doctrine\DBAL\Driver\API\ExceptionConverter::class);
$definitions = [
    'id integer primary key', "email varchar(255) DEFAULT 'test@example.test'", "title varchar(255) DEFAULT 'Hello'", "quoted varchar(255) DEFAULT 'O''Reilly'", "empty_string varchar(30) DEFAULT ''", "literal_null varchar(30) DEFAULT 'NULL'", "zero_string varchar(30) DEFAULT '0'", 'null_value varchar(30) DEFAULT NULL', 'enabled tinyint DEFAULT 1', 'small_value smallint DEFAULT 2', 'big_value bigint DEFAULT 3', 'amount decimal(10,2) DEFAULT 12.50', 'score double DEFAULT 1.25', 'details text', 'image blob', 'published_at timestamp DEFAULT CURRENT_TIMESTAMP', 'appointment datetime', 'birth_date date', 'alarm_time time', 'created_at datetime', 'updated_at datetime', 'deleted_at datetime',
];
if ($driver !== 'sqlite') {
    $definitions = array_merge($definitions, ["slashes varchar(80) DEFAULT 'C:\\\\tmp\\\\sample'", "choice enum('a','b') DEFAULT 'a'", 'document json', 'position point', 'tiny_text tinytext', 'medium_text mediumtext', 'long_text longtext', 'tiny_blob tinyblob', 'medium_blob mediumblob', 'long_blob longblob', "hint text COMMENT '(DC2Type:json)'", 'generated_value integer GENERATED ALWAYS AS (small_value + 1) STORED', 'expression_value integer DEFAULT (1 + 2)']);
}
$created = false;
try {
    $conn->statement('CREATE TABLE parity_records ('.implode(', ', $definitions).')');
    $created = true;
    $model = new ParityModel();
    $nativeRows = $conn->getSchemaBuilder()->getColumns($model->getTable());
    $schema = method_exists($reference, 'createSchemaManager') ? $reference->createSchemaManager() : $reference->getSchemaManager();
    $dbalColumns = $schema->listTableColumns('parity_records');
    $candidate = [];
    foreach ($nativeRows as $row) {
        $type = $platform->getDoctrineTypeMapping($row['type_name']);
        if (preg_match('/\(DC2Type:([^\)]+)\)/', $row['comment'] ?? '', $m)) $type = $m[1];
        $candidate[$row['name']] = new Column($row['name'], Type::getType($type), ['default'=>normalizeDefault($row['default'], $driver, $maria), 'notnull'=>!$row['nullable']]);
    }
    check(array_keys($candidate) === array_keys($dbalColumns), 'native/DBAL ordered column names and prefix parity');
    $capture = [];
    foreach ($nativeRows as $row) {
        $name = $row['name']; $expected = $dbalColumns[$name]; $actual = $candidate[$name];
        $capture[$name] = ['native'=>$row,'dbal'=>['type'=>$expected->getType()->getName(), 'default'=>$expected->getDefault(), 'notnull'=>$expected->getNotnull()]];
        // Print observed evidence before assertions, so CI failures retain the mismatch.
        echo 'OBSERVED '.json_encode($capture[$name], JSON_UNESCAPED_SLASHES)."\n";
        if ($nativeJsonGap && $name === 'document') {
            check($row['type_name'] === 'longtext' && $actual->getType()->getName() === 'text' && $expected->getType()->getName() === 'json', 'KNOWN GAP: MariaDB native metadata omits JSON check-constraint semantics');
        } else {
            check($actual->getType()->getName() === $expected->getType()->getName(), "$name type parity");
        }
        if (in_array($expected->getType()->getName(), ['date', 'datetime', 'time'], true)) {
            check($actual->getNotnull() === $expected->getNotnull(), "$name temporal nullability parity");
        }
        check($actual->getDefault() === $expected->getDefault(), "$name default parity: ".json_encode([$actual->getDefault(), $expected->getDefault()]));
    }
    $native = new ParityRenderer($model); $native->columns = $candidate;
    $legacy = new ParityRenderer($model); $legacy->columns = $dbalColumns;
    $production = new ResourceGenerator($model);
    foreach (['generateForm','generateGrid','generateShow'] as $method) {
        check($production->$method() === $legacy->$method(), "$method production bridge preserves same-version DBAL output");
        token_get_all('<?php '.$production->$method(), TOKEN_PARSE);
    }

    if ($nativeJsonGap) {
        check(str_contains($native->generateForm(), "\$form->textarea('document'") && str_contains($legacy->generateForm(), "\$form->text('document'"), 'KNOWN GAP: native-only MariaDB JSON changes form widget');
        // Deliberately exclude only the separately asserted known gap from remaining parity.
        unset($native->columns['document'], $legacy->columns['document']);
    }
    foreach (['generateForm','generateGrid','generateShow'] as $method) {
        check($native->$method() === $legacy->$method(), "$method byte parity".($nativeJsonGap ? " (excluding characterized JSON gap)" : ""));
        token_get_all('<?php '.$native->$method(), TOKEN_PARSE);
    }
    check(str_contains($native->generateForm(), "\$form->switch('enabled'"), 'tinyint remains boolean');
    check(!str_contains($native->generateForm(), "'created_at'"), 'reserved form fields omitted');
    check($conn->getSchemaBuilder()->getColumns('missing_records') === [], 'native missing table returns empty metadata');
    if ($driver !== 'sqlite') check($conn->getSchemaBuilder()->getColumns($config['database'].'.records') === $nativeRows, 'qualified-table prefix applied once');
    $capture = ['framework'=>class_exists(Illuminate\Foundation\Application::class) ? Illuminate\Foundation\Application::VERSION : 'illuminate/database '.Composer\InstalledVersions::getPrettyVersion('illuminate/database'), 'php'=>PHP_VERSION, 'driver'=>$driver, 'server'=>$driver === 'sqlite' ? $conn->selectOne('select sqlite_version() as v')->v : $conn->selectOne('select version() as v')->v, 'platform'=>get_class($platform), 'columns'=>$capture];
    if (getenv('PARITY_CAPTURE')) file_put_contents(getenv('PARITY_CAPTURE'), json_encode($capture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(array_diff_key($capture, ['columns'=>true]))."\n";
} finally {
    if ($created) $conn->statement('DROP TABLE parity_records');
    $reference->close(); $conn->disconnect();
    if (isset($file)) unlink($file);
}
