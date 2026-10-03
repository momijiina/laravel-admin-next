<?php

namespace Encore\Admin\Console;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\DriverManager;
use Illuminate\Database\Connection;
use PDO;

/**
 * Read DBAL metadata using Laravel's existing write connection.
 *
 * Wrappers are intentionally short-lived: Laravel owns reconnects, transactions
 * and the PDO's settings. No connection configuration or credentials are copied.
 */
class DoctrineSchema
{
    public static function getColumns(Connection $connection, $table, array $typeMappings)
    {
        $connection->reconnectIfMissingConnection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;
        $drivers = [
            'mysql' => \Doctrine\DBAL\Driver\PDO\MySQL\Driver::class,
            'pgsql' => \Doctrine\DBAL\Driver\PDO\PgSQL\Driver::class,
            'sqlite' => \Doctrine\DBAL\Driver\PDO\SQLite\Driver::class,
            'sqlsrv' => \Doctrine\DBAL\Driver\PDO\SQLSrv\Driver::class,
        ];

        if (!isset($drivers[$driver])) {
            throw new \InvalidArgumentException("Unsupported schema driver [$driver].");
        }

        $dbal3 = interface_exists(ExceptionConverter::class);
        if (!$dbal3 && $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new \RuntimeException(
                'DBAL 2 cannot inspect a persistent PDO connection. Use DBAL 3.10.6 or later, or disable persistent PDO for this connection.'
            );
        }

        $errorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        // Only DBAL 2's PDO import replaces the statement class.
        $statementClass = $dbal3 ? null : $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);

        try {
            $params = ['driverClass' => $drivers[$driver]];
            // A raw PDO imported by DBAL 2 is not ServerInfoAwareConnection.
            // Supplying the actual version is essential for MariaDB defaults and
            // platform selection; the Laravel "mysql" name does not identify it.
            if (in_array($driver, ['mysql', 'pgsql'], true)) {
                $params['serverVersion'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            }

            if ($dbal3) {
                $wrappedDriver = new ExistingPdoDriver(new $drivers[$driver](), $pdo, $driver === 'sqlsrv');
                $doctrine = new DoctrineConnection($params, $wrappedDriver);
            } else {
                $doctrine = DriverManager::getConnection($params + ['pdo' => $pdo]);
                if ($driver === 'sqlsrv') {
                    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [\Doctrine\DBAL\Driver\PDO\SQLSrv\Statement::class, []]);
                }
            }

            $schema = $dbal3 ? $doctrine->createSchemaManager() : $doctrine->getSchemaManager();
            $platform = $schema->getDatabasePlatform();
            foreach ($typeMappings as $doctrineType => $databaseTypes) {
                foreach ($databaseTypes as $databaseType) {
                    $platform->registerDoctrineTypeMapping($databaseType, $doctrineType);
                }
            }

            $database = null;
            $qualifier = '';
            if (($position = strrpos($table, '.')) !== false) {
                $qualifier = substr($table, 0, $position);
                $table = substr($table, $position + 1);
            }
            $table = $connection->getTablePrefix().$table;
            if ($qualifier !== '') {
                if ($driver === 'mysql') {
                    $database = $qualifier;
                } else {
                    $table = $qualifier.'.'.$table;
                }
            }

            return $schema->listTableColumns($table, $database);
        } finally {
            // Materialize columns before returning and restore even on an error.
            // Do not disconnect, commit or roll back Laravel's shared PDO.
            if (!$dbal3 && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== $statementClass) {
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
            }
            if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== $errorMode) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, $errorMode);
            }
        }
    }
}
