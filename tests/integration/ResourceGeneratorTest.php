<?php

namespace LaravelAdminNext\Integration;

use Doctrine\DBAL\DriverManager;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Console\ResourceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOStatement;

/** Real Laravel, real PDO and real DBAL. No production metadata is stubbed. */
class ResourceGeneratorTest extends TestCase
{
    private $temporaryFiles = [];
    private $references = [];

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        // An unrelated default connection catches accidental fallback to DB::connection().
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->references as $reference) {
                $reference->close();
            }
            if ($this->app) {
                foreach ($this->app['db']->getConnections() as $connection) {
                    $connection->disconnect();
                }
            }
            foreach ($this->temporaryFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_sqlite_columns_and_generated_bytes_match_an_independent_dbal_connection(): void
    {
        [$connection, $reference] = $this->sqliteFixture();
        $this->assertFalse(method_exists($connection, 'isDoctrineAvailable'));
        $model = new SchemaGeneratorRecord();
        $columns = $this->schema($reference)->listTableColumns('generator_records');
        $this->assertParity($model, $columns);
        $this->assertTemporalLiteralDefaults($model, $columns);
        $this->assertServiceArtisanGeneration('records', $columns);

        $form = (new ResourceGenerator($model))->generateForm();
        $this->assertStringContainsString("\$form->email('email', __('Email'))->default('test@example.test');", $form);
        $this->assertStringContainsString("\$form->switch('enabled'", $form);
        $this->assertStringContainsString("\$form->number('small_value'", $form);
        $this->assertStringContainsString("\$form->decimal('amount'", $form);
        $this->assertStringContainsString("\$form->textarea('details'", $form);
        $this->assertStringContainsString("->default('O\\'Reilly')", $form);
        $this->assertStringContainsString("->default('C:\\\\tmp\\\\sample')", $form);
        $this->assertStringContainsString("->default('NULL')", $form);
        foreach (['id', 'created_at', 'updated_at', 'deleted_at'] as $reserved) {
            $this->assertStringNotContainsString("('{$reserved}',", $form);
        }
        foreach (['empty_string', 'null_value'] as $withoutDefault) {
            $this->assertStringContainsString("\$form->text('{$withoutDefault}', __('".ucfirst(str_replace('_', ' ', $withoutDefault))."'));", $form);
        }
    }

    public function test_real_artisan_model_controller_generation_and_output(): void
    {
        [, $reference] = $this->sqliteFixture();
        $oracle = new SchemaOracleGenerator(new SchemaGeneratorRecord(), $this->schema($reference)->listTableColumns('generator_records'));
        $directory = sys_get_temp_dir().'/admin-schema-command-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->app->getNamespace();
        $this->app->useAppPath($directory);
        config(['admin.directory' => $directory.'/Admin', 'admin.route.namespace' => 'App\\Admin\\Controllers']);

        try {
            foreach ([
                ['admin:make', ['name' => 'GeneratedSchemaController', '--model' => SchemaGeneratorRecord::class], 'GeneratedSchemaController'],
                ['admin:controller', ['model' => SchemaGeneratorRecord::class], 'SchemaGeneratorRecordController'],
            ] as [$command, $arguments, $controller]) {
                $exitCode = Artisan::call($command, $arguments);
                $output = Artisan::output();
                $this->assertSame(0, $exitCode, $output);
                $file = $directory.'/Admin/Controllers/'.$controller.'.php';
                $this->assertFileExists($file);
                $source = file_get_contents($file);
                token_get_all($source, TOKEN_PARSE);
                $this->assertStringContainsString('namespace App\\Admin\\Controllers;', $source);
                $this->assertStringContainsString('use '.SchemaGeneratorRecord::class.';', $source);
                $this->assertStringContainsString('class '.$controller.' extends AdminController', $source);
                $this->assertStringNotContainsString('Dummy', $source);
                foreach (['generateGrid', 'generateShow', 'generateForm'] as $method) {
                    $this->assertStringContainsString($this->indent($oracle->$method()), $source);
                }
                $this->assertStringContainsString("\$router->resource('schema-generator-records', {$controller}::class);", $output);
            }
            foreach ([
                ['admin:make', ['name' => 'OutputOnlyController', '--model' => SchemaGeneratorRecord::class, '--output' => true]],
                ['admin:controller', ['model' => SchemaGeneratorRecord::class, '--output' => true]],
            ] as [$command, $arguments]) {
                $exitCode = Artisan::call($command, $arguments);
                $output = Artisan::output();
                $this->assertSame(0, $exitCode, $output);
                foreach (['generateGrid', 'generateShow', 'generateForm'] as $method) {
                    $this->assertStringContainsString($oracle->$method(), $output);
                }
            }
            $this->assertFileDoesNotExist($directory.'/Admin/Controllers/OutputOnlyController.php');
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    public function test_introspection_uses_named_write_pdo_and_preserves_the_callers_transaction(): void
    {
        [$connection, $reference] = $this->sqliteFixture();
        $write = $connection->getPdo();
        $read = new PDO('sqlite::memory:');
        $read->exec('CREATE TABLE generator_records (replica_only integer)');
        $connection->setReadPdo($read);
        $this->assertSame($read, $connection->getReadPdo());
        $this->assertArrayHasKey('email', (new SchemaInspectableGenerator(new SchemaGeneratorRecord()))->columns());
        $this->assertSame($write, $connection->getPdo());

        $connection->beginTransaction();
        try {
            $write->exec('ALTER TABLE generator_records ADD transaction_only integer');
            $write->exec("INSERT INTO generator_records (email) VALUES ('uncommitted')");
            $generator = new SchemaInspectableGenerator(new SchemaGeneratorRecord());
            $this->assertArrayHasKey('transaction_only', $generator->columns());
            $this->assertStringContainsString("'transaction_only'", $generator->generateForm());
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertTrue($write->inTransaction());
            $this->assertSame(1, (int) $write->query('SELECT COUNT(*) FROM generator_records')->fetchColumn());
            $this->assertSame(0, (int) $reference->fetchOne('SELECT COUNT(*) FROM generator_records'));
            $this->assertSame($write, $connection->getPdo());
        } finally {
            $connection->rollBack();
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($write->inTransaction());
        $this->assertSame(0, (int) $write->query('SELECT COUNT(*) FROM generator_records')->fetchColumn());
        $this->assertArrayNotHasKey('transaction_only', (new SchemaInspectableGenerator(new SchemaGeneratorRecord()))->columns());
        $this->assertSame($read, $connection->getReadPdo());
    }

    public function test_generation_reconnects_and_does_not_cache_the_previous_pdo(): void
    {
        [$connection] = $this->sqliteFixture();
        $generator = new SchemaInspectableGenerator(new SchemaGeneratorRecord());
        $original = $connection->getPdo();
        $this->assertArrayHasKey('email', $generator->columns());
        $reconnects = 0;
        $connection->setReconnector(function ($connection) use (&$reconnects) {
            ++$reconnects;
            $replacement = new PDO('sqlite::memory:');
            $replacement->exec('CREATE TABLE generator_records (replacement_only integer)');
            $connection->setPdo($replacement);
        });
        $connection->disconnect();
        $this->assertNull($connection->getPdo());
        $this->assertSame(['replacement_only'], array_keys($generator->columns()));
        $this->assertSame(1, $reconnects);
        $this->assertNotSame($original, $connection->getPdo());
        $this->assertStringContainsString("'replacement_only'", $generator->generateGrid());
        $this->assertSame(1, $reconnects);
    }

    public function test_failed_reconnect_propagates_and_cannot_reuse_stale_metadata(): void
    {
        [$connection] = $this->sqliteFixture();
        $generator = new SchemaInspectableGenerator(new SchemaGeneratorRecord());
        $this->assertArrayHasKey('email', $generator->columns());
        $failure = new \RuntimeException('Deliberate reconnect failure');
        $reconnects = 0;
        $connection->setReconnector(function () use ($failure, &$reconnects) {
            ++$reconnects;
            throw $failure;
        });
        $connection->disconnect();
        try {
            $generator->generateForm();
            $this->fail('A failed reconnect must not return stale columns or empty successful output.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(1, $reconnects);
        $this->assertNull($connection->getPdo());
        $connection->setReconnector(function ($connection) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE generator_records (recovered integer)');
            $connection->setPdo($pdo);
        });
        $this->assertSame(['recovered'], array_keys($generator->columns()));
    }

    public function test_pdo_attributes_are_restored_on_success_missing_table_and_schema_failure(): void
    {
        [$connection] = $this->sqliteFixture();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE generator_bad_metadata (unsupported unrecognized_generator_type)');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SchemaFixtureStatement::class, []]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $before = $this->pdoAttributes($pdo);

        $this->assertNotEmpty((new SchemaInspectableGenerator(new SchemaGeneratorRecord()))->columns());
        $this->assertSame($before, $this->pdoAttributes($pdo));
        $missing = (new SchemaGeneratorRecord())->setTable('missing_records');
        $this->assertSame([], (new SchemaInspectableGenerator($missing))->columns());
        $this->assertSame('', (new ResourceGenerator($missing))->generateForm());
        $this->assertSame($before, $this->pdoAttributes($pdo));

        $bad = (new SchemaGeneratorRecord())->setTable('bad_metadata');
        try {
            (new SchemaInspectableGenerator($bad))->columns();
            $this->fail('Unknown database types must preserve the real DBAL schema exception.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            $this->assertStringContainsString('unrecognized_generator_type', $exception->getMessage());
        }
        $this->assertSame($before, $this->pdoAttributes($pdo));
        $this->assertSame($pdo, $connection->getPdo());
        $statement = $pdo->query('SELECT 1');
        $this->assertInstanceOf(SchemaFixtureStatement::class, $statement);
        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    public function test_persistent_pdo_succeeds_on_dbal3_or_fails_without_mutation_on_dbal2(): void
    {
        [$connection] = $this->sqliteFixture(true);
        $pdo = $connection->getPdo();
        $this->assertTrue($pdo->getAttribute(PDO::ATTR_PERSISTENT));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $before = $this->pdoAttributes($pdo);
        $generator = new SchemaInspectableGenerator(new SchemaGeneratorRecord());
        if (interface_exists(\Doctrine\DBAL\Driver\API\ExceptionConverter::class)) {
            $this->assertArrayHasKey('email', $generator->columns());
            $this->assertStringContainsString("'email'", $generator->generateForm());
        } else {
            try {
                $generator->columns();
                $this->fail('DBAL 2 cannot safely import a persistent PDO.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('persistent PDO', $exception->getMessage());
                $this->assertStringContainsString('DBAL 3', $exception->getMessage());
            }
        }
        $this->assertSame($before, $this->pdoAttributes($pdo));
        $this->assertSame($pdo, $connection->getPdo());
        $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    public function test_existing_framework_doctrine_branch_still_uses_the_explicit_methods(): void
    {
        [$connection, $reference] = $this->sqliteFixture();
        $legacy = new SchemaLegacyConnection($connection->getPdo(), '', 'generator_', ['driver' => 'sqlite']);
        $legacy->referenceSchema = $this->schema($reference);
        $model = new SchemaExplicitConnectionRecord($legacy);
        $expected = $this->schema($reference)->listTableColumns('generator_records');
        $this->assertParity($model, $expected);
        $this->assertNotEmpty($legacy->requestedTables);
        $this->assertSame(['generator_records'], array_values(array_unique($legacy->requestedTables)));
        $this->assertSame(count($legacy->requestedTables), $legacy->availabilityChecks);
        $this->assertSame('string', $legacy->referenceSchema->getDatabasePlatform()->getDoctrineTypeMapping('point'));
        $legacy->available = false;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You need to require doctrine/dbal');
        (new ResourceGenerator($model))->generateForm();
    }

    public function test_disposable_mysql_or_mariadb_service_has_full_dbal_metadata_and_output_parity(): void
    {
        $family = getenv('MODEL_SCHEMA_DATABASE');
        if (!in_array($family, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Opt-in MySQL/MariaDB disposable service was not selected.');
        }
        $this->requireDisposableService();
        // MariaDB is intentionally exercised through both Laravel connection classes.
        foreach ($family === 'mariadb' ? ['mysql', 'mariadb'] : ['mysql'] as $driver) {
            $this->mysqlFixtureParity($driver, $family);
        }
    }

    public function test_disposable_postgresql_service_has_full_dbal_metadata_and_output_parity(): void
    {
        if (getenv('MODEL_SCHEMA_DATABASE') !== 'pgsql') {
            $this->markTestSkipped('Opt-in PostgreSQL disposable service was not selected.');
        }
        $this->requireDisposableService();
        $config = $this->serviceConfig('pgsql') + ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'];
        $connection = $this->namedConnection($config);
        $reference = $this->reference($this->referenceParams($config));
        $schemaName = 'generator_'.bin2hex(random_bytes(6));
        $table = $schemaName.'.generator_records';
        $created = false;
        try {
            $connection->statement('CREATE SCHEMA '.$schemaName);
            $created = true;
            $connection->statement('CREATE DOMAIN '.$schemaName.'.rating AS integer CHECK (VALUE BETWEEN 0 AND 10)');
            $connection->statement('CREATE TABLE '.$table.' ('.implode(', ', [
                'id bigserial PRIMARY KEY', "email varchar(255) DEFAULT 'test@example.test'",
                "quoted varchar(255) DEFAULT 'O''Reilly'", "empty_string varchar(30) DEFAULT ''",
                "literal_null varchar(30) DEFAULT 'NULL'", "zero_string varchar(30) DEFAULT '0'",
                'null_value varchar(30) DEFAULT NULL', 'enabled boolean DEFAULT true',
                'small_value smallint DEFAULT 2', 'big_value bigint DEFAULT 3', 'amount numeric(10,2) DEFAULT 12.50',
                'score double precision DEFAULT 1.25', 'details text', 'image bytea',
                'document json', 'document_binary jsonb', 'identifier uuid',
                'domain_value '.$schemaName.'.rating DEFAULT 5',
                'published_at timestamp DEFAULT CURRENT_TIMESTAMP', 'birth_date date', 'alarm_time time',
                ...$this->temporalLiteralDefinitions('timestamp'),
                'generated_value integer GENERATED ALWAYS AS (small_value + 1) STORED',
                'expression_value integer DEFAULT (1 + 2)', 'hint text',
                'created_at timestamp', 'updated_at timestamp', 'deleted_at timestamp',
            ]).')');
            $connection->statement("COMMENT ON COLUMN {$table}.hint IS '(DC2Type:json)'");
            $columns = $this->schema($reference)->listTableColumns($table);
            $model = (new SchemaGeneratorRecord())->setTable($schemaName.'.records');
            $this->assertParity($model, $columns);
            $this->assertSame('json', $columns['hint']->getType()->getName());
            $this->assertSame('integer', $columns['domain_value']->getType()->getName());
            $this->assertTemporalLiteralDefaults($model, $columns);
            $this->assertServiceArtisanGeneration($model->getTable(), $columns);
            $this->assertStringContainsString("\$form->text('document_binary'", (new ResourceGenerator($model))->generateForm());
            // Make a different search_path active on the existing session. A second
            // connection reconstructed from config would not see this session state.
            $connection->statement('SET search_path TO '.$schemaName);
            $this->assertParity(new SchemaGeneratorRecord(), $columns);
            $this->assertServiceArtisanGeneration('records', $columns);
        } finally {
            if ($created) {
                $connection->statement('SET search_path TO public');
                $connection->statement('DROP TABLE IF EXISTS '.$table);
                $connection->statement('DROP DOMAIN IF EXISTS '.$schemaName.'.rating');
                $connection->statement('DROP SCHEMA '.$schemaName);
            }
        }
    }

    private function assertServiceArtisanGeneration($table, array $columns): void
    {
        $directory = sys_get_temp_dir().'/admin-schema-service-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->app->getNamespace();
        $originalAppPath = $this->app->path();
        $originalDirectory = config('admin.directory');
        $originalNamespace = config('admin.route.namespace');
        $originalTable = SchemaServiceGeneratorRecord::$fixtureTable;
        $this->app->useAppPath($directory);
        config(['admin.directory' => $directory.'/Admin', 'admin.route.namespace' => 'App\\Admin\\Controllers']);
        SchemaServiceGeneratorRecord::$fixtureTable = $table;
        try {
            $oracle = new SchemaOracleGenerator(new SchemaServiceGeneratorRecord(), $columns);
            $exitCode = Artisan::call('admin:make', [
                'name' => 'ServiceSchemaController', '--model' => SchemaServiceGeneratorRecord::class,
            ]);
            $output = Artisan::output();
            $this->assertSame(0, $exitCode, $output);
            $file = $directory.'/Admin/Controllers/ServiceSchemaController.php';
            $this->assertFileExists($file);
            $source = file_get_contents($file);
            token_get_all($source, TOKEN_PARSE);
            $this->assertStringContainsString('class ServiceSchemaController extends AdminController', $source);
            $this->assertStringContainsString('use '.SchemaServiceGeneratorRecord::class.';', $source);
            $this->assertStringNotContainsString('Dummy', $source);
            foreach (['generateForm', 'generateGrid', 'generateShow'] as $method) {
                $this->assertStringContainsString($this->indent($oracle->$method()), $source);
            }
            $exitCode = Artisan::call('admin:controller', ['model' => SchemaServiceGeneratorRecord::class, '--output' => true]);
            $output = Artisan::output();
            $this->assertSame(0, $exitCode, $output);
            foreach (['generateForm', 'generateGrid', 'generateShow'] as $method) {
                $this->assertStringContainsString($oracle->$method(), $output);
            }
            $this->assertFileDoesNotExist($directory.'/Admin/Controllers/SchemaServiceGeneratorRecordController.php');
        } finally {
            SchemaServiceGeneratorRecord::$fixtureTable = $originalTable;
            $this->app->useAppPath($originalAppPath);
            config(['admin.directory' => $originalDirectory, 'admin.route.namespace' => $originalNamespace]);
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private function temporalLiteralDefinitions($datetimeType = 'datetime'): array
    {
        $definitions = [];
        foreach (['date' => '2024-02-29', 'time' => '12:34:56', 'datetime' => '2024-02-29 12:34:56'] as $type => $literal) {
            foreach (['required' => 'NOT NULL', 'nullable' => 'NULL'] as $suffix => $nullability) {
                $databaseType = $type === 'datetime' ? $datetimeType : $type;
                $definitions[] = 'literal_'.$type.'_'.$suffix.' '.$databaseType.' '.$nullability." DEFAULT '".$literal."'";
            }
        }
        return $definitions;
    }

    private function assertTemporalLiteralDefaults(Model $model, array $columns): void
    {
        $form = (new ResourceGenerator($model))->generateForm();
        foreach (['date' => '2024-02-29', 'time' => '12:34:56', 'datetime' => '2024-02-29 12:34:56'] as $type => $literal) {
            foreach (['required' => true, 'nullable' => false] as $suffix => $notnull) {
                $name = 'literal_'.$type.'_'.$suffix;
                $this->assertSame($type, $columns[$name]->getType()->getName(), $name.' DBAL type');
                // PostgreSQL casts and MariaDB/SQLite SQL quotes are normalized by
                // DBAL before generation. Assert that boundary independently of parity.
                $this->assertSame($literal, $columns[$name]->getDefault(), $name.' canonical DBAL literal');
                $this->assertSame($notnull, $columns[$name]->getNotnull(), $name.' DBAL nullability');
                $label = ucfirst(str_replace('_', ' ', $name));
                $this->assertStringContainsString(
                    '$form->'.$type."('".$name."', __('".$label."'))->default(".var_export($literal, true).");\r\n",
                    $form,
                    $name.' exact literal default source'
                );
            }
        }
        token_get_all('<?php '.$form, TOKEN_PARSE);
    }

    private function sqliteFixture(bool $persistent = false): array
    {
        $file = tempnam(sys_get_temp_dir(), 'admin-schema-fixture-');
        $this->temporaryFiles[] = $file;
        $connection = $this->namedConnection(['driver' => 'sqlite', 'database' => $file, 'prefix' => 'generator_', 'options' => [PDO::ATTR_PERSISTENT => $persistent]]);
        $connection->statement('CREATE TABLE generator_records ('.implode(', ', [
            'id integer PRIMARY KEY', "email varchar(255) DEFAULT 'test@example.test'", "title varchar(255) DEFAULT 'Hello'",
            "quoted varchar(255) DEFAULT 'O''Reilly'", "slashes varchar(80) DEFAULT 'C:\\tmp\\sample'",
            "empty_string varchar(30) DEFAULT ''", "literal_null varchar(30) DEFAULT 'NULL'",
            "zero_string varchar(30) DEFAULT '0'", 'null_value varchar(30) DEFAULT NULL',
            'enabled tinyint DEFAULT 1', 'small_value smallint DEFAULT 2', 'big_value bigint DEFAULT 3',
            'amount decimal(10,2) DEFAULT 12.50', 'score double DEFAULT 1.25', 'details text', 'image blob',
            'published_at timestamp DEFAULT CURRENT_TIMESTAMP', 'appointment datetime', 'birth_date date',
            'alarm_time time', 'created_at datetime', 'updated_at datetime', 'deleted_at datetime',
            ...$this->temporalLiteralDefinitions(),
        ]).')');
        return [$connection, $this->reference(['driver' => 'pdo_sqlite', 'path' => $file])];
    }

    private function namedConnection(array $config)
    {
        $this->app['db']->purge('schema_generator');
        config(['database.connections.schema_generator' => $config]);
        return $this->app['db']->connection('schema_generator');
    }

    private function reference(array $params)
    {
        // Independent real DBAL connection: not the bridge and not Laravel native metadata.
        $reference = DriverManager::getConnection($params);
        $this->references[] = $reference;
        foreach (['enum', 'geometry', 'geometrycollection', 'linestring', 'polygon', 'multilinestring', 'multipoint', 'multipolygon', 'point'] as $type) {
            $reference->getDatabasePlatform()->registerDoctrineTypeMapping($type, 'string');
        }
        return $reference;
    }

    private function schema($reference)
    {
        return method_exists($reference, 'createSchemaManager') ? $reference->createSchemaManager() : $reference->getSchemaManager();
    }

    private function assertParity(Model $model, array $expected): void
    {
        $generator = new SchemaInspectableGenerator($model);
        $actual = $generator->columns();
        $this->assertNotEmpty($expected, 'The independent oracle must inspect an existing fixture.');
        $this->assertSame(array_keys($expected), array_keys($actual), 'Ordered columns and prefix resolution');
        foreach ($expected as $name => $column) {
            $this->assertSame($column->getType()->getName(), $actual[$name]->getType()->getName(), $name.' type');
            $this->assertSame($column->getDefault(), $actual[$name]->getDefault(), $name.' default');
            $expectedMetadata = $column->toArray();
            $actualMetadata = $actual[$name]->toArray();
            $expectedMetadata['type'] = $column->getType()->getName();
            $actualMetadata['type'] = $actual[$name]->getType()->getName();
            $this->assertSame($expectedMetadata, $actualMetadata, $name.' complete DBAL metadata');
        }
        $oracle = new SchemaOracleGenerator($model, $expected);
        foreach (['generateForm', 'generateGrid', 'generateShow'] as $method) {
            $source = $generator->$method();
            $this->assertSame($oracle->$method(), $source, $method.' byte parity');
            token_get_all('<?php '.$source, TOKEN_PARSE);
        }
    }

    private function pdoAttributes(PDO $pdo): array
    {
        return [$pdo->getAttribute(PDO::ATTR_ERRMODE), $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS)];
    }

    private function indent($code): string
    {
        return rtrim('        '.str_replace("\r\n", "\r\n        ", $code));
    }

    private function requireDisposableService(): void
    {
        $this->assertSame('1', getenv('MODEL_SCHEMA_DISPOSABLE'), 'Explicit disposable database opt-in is required before any service DDL.');
        $this->assertSame('schema_generator', getenv('MODEL_SCHEMA_DB_NAME'), 'Only the dedicated schema_generator database is allowed.');
    }

    private function serviceConfig($driver): array
    {
        return [
            'driver' => $driver, 'host' => getenv('MODEL_SCHEMA_HOST') ?: '127.0.0.1',
            'port' => getenv('MODEL_SCHEMA_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('MODEL_SCHEMA_DB_NAME'), 'username' => getenv('MODEL_SCHEMA_USER'),
            'password' => getenv('MODEL_SCHEMA_PASSWORD'), 'prefix' => 'generator_',
        ];
    }

    private function referenceParams(array $config): array
    {
        return [
            'driver' => $config['driver'] === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql',
            'host' => $config['host'], 'port' => $config['port'], 'dbname' => $config['database'],
            'user' => $config['username'], 'password' => $config['password'], 'charset' => $config['charset'],
        ];
    }

    private function mysqlFixtureParity($driver, $family): void
    {
        $config = $this->serviceConfig($driver) + ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
        $connection = $this->namedConnection($config);
        $reference = $this->reference($this->referenceParams($config));
        $table = 'generator_records_'.bin2hex(random_bytes(6));
        $created = false;
        try {
            $connection->statement('CREATE TABLE '.$table.' ('.implode(', ', [
                'id integer PRIMARY KEY', "email varchar(255) DEFAULT 'test@example.test'",
                "quoted varchar(255) DEFAULT 'O''Reilly'", "slashes varchar(80) DEFAULT 'C:\\\\tmp\\\\sample'",
                "empty_string varchar(30) DEFAULT ''", "literal_null varchar(30) DEFAULT 'NULL'",
                "zero_string varchar(30) DEFAULT '0'", 'null_value varchar(30) DEFAULT NULL',
                'enabled tinyint DEFAULT 1', 'small_value smallint DEFAULT 2', 'big_value bigint DEFAULT 3',
                'amount decimal(10,2) DEFAULT 12.50', 'score double DEFAULT 1.25', 'details text', 'image blob',
                'published_at timestamp DEFAULT CURRENT_TIMESTAMP', 'appointment datetime', 'birth_date date', 'alarm_time time',
                ...$this->temporalLiteralDefinitions(),
                "choice enum('a','b') DEFAULT 'a'", 'document json', 'position point',
                'tiny_text tinytext', 'medium_text mediumtext', 'long_text longtext',
                'tiny_blob tinyblob', 'medium_blob mediumblob', 'long_blob longblob', "hint text COMMENT '(DC2Type:json)'",
                'generated_value integer GENERATED ALWAYS AS (small_value + 1) STORED', 'expression_value integer DEFAULT (1 + 2)',
                'created_at datetime', 'updated_at datetime', 'deleted_at datetime',
            ]).')');
            $created = true;
            $columns = $this->schema($reference)->listTableColumns($table, $config['database']);
            $model = (new SchemaGeneratorRecord())->setTable(substr($table, strlen('generator_')));
            $this->assertParity($model, $columns);
            $this->assertTemporalLiteralDefaults($model, $columns);
            $qualified = $config['database'].'.'.$model->getTable();
            $this->assertParity((clone $model)->setTable($qualified), $columns);
            $this->assertServiceArtisanGeneration($qualified, $columns);
            // DBAL 2 does not infer MariaDB JSON from its CHECK constraint.
            // Preserve the installed major's own historical metadata and widget.
            $legacyMariaJson = $family === 'mariadb' && !interface_exists(\Doctrine\DBAL\Driver\API\ExceptionConverter::class);
            $this->assertSame($legacyMariaJson ? 'text' : 'json', $columns['document']->getType()->getName());
            $this->assertSame('json', $columns['hint']->getType()->getName());
            $this->assertSame('string', $columns['choice']->getType()->getName());
            $this->assertSame('string', $columns['position']->getType()->getName());
            $form = (new ResourceGenerator($model))->generateForm();
            $documentField = $legacyMariaJson ? 'textarea' : 'text';
            $this->assertStringContainsString("\$form->{$documentField}('document'", $form);
            $this->assertStringContainsString("\$form->switch('enabled'", $form);
        } finally {
            if ($created) {
                $connection->statement('DROP TABLE '.$table);
            }
        }
    }
}

class SchemaGeneratorRecord extends Model
{
    protected $connection = 'schema_generator';
    protected $table = 'records';
}

class SchemaServiceGeneratorRecord extends SchemaGeneratorRecord
{
    public static $fixtureTable = 'records';

    public function getTable()
    {
        return static::$fixtureTable;
    }
}

class SchemaInspectableGenerator extends ResourceGenerator
{
    public function columns(): array
    {
        return $this->getTableColumns();
    }
}

/** Only the output oracle receives columns directly; production is called separately. */
class SchemaOracleGenerator extends ResourceGenerator
{
    private $oracleColumns;

    public function __construct(Model $model, array $columns)
    {
        parent::__construct($model);
        $this->oracleColumns = $columns;
    }

    protected function getTableColumns()
    {
        return $this->oracleColumns;
    }
}

class SchemaFixtureStatement extends PDOStatement
{
    protected function __construct()
    {
    }
}

/** Real Laravel connection with the explicit pre-removal API restored for branch coverage. */
class SchemaLegacyConnection extends SQLiteConnection
{
    public $referenceSchema;
    public $available = true;
    public $availabilityChecks = 0;
    public $requestedTables = [];

    public function isDoctrineAvailable()
    {
        ++$this->availabilityChecks;
        return $this->available;
    }

    public function getDoctrineSchemaManager($table)
    {
        $this->requestedTables[] = $table;
        return $this->referenceSchema;
    }
}

class SchemaExplicitConnectionRecord extends SchemaGeneratorRecord
{
    private $explicitConnection;

    public function __construct($connection = null)
    {
        parent::__construct();
        $this->explicitConnection = $connection;
    }

    public function getConnection()
    {
        return $this->explicitConnection;
    }
}
