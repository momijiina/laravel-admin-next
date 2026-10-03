<?php

namespace Encore\Admin\Console;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\Connection;
use Doctrine\DBAL\Driver\PDO\SQLSrv\Connection as SqlServerConnection;
use PDO;

/**
 * DBAL 3 driver decorator; never creates or reconnects a physical connection.
 *
 * The PDO wrapper constructor is internal to DBAL. Keep compatibility tests at
 * the supported DBAL floor when upgrading the dependency range.
 */
class ExistingPdoDriver extends AbstractDriverMiddleware
{
    private $pdo;
    private $sqlServer;

    public function __construct(Driver $driver, PDO $pdo, $sqlServer = false)
    {
        parent::__construct($driver);
        $this->pdo = $pdo;
        $this->sqlServer = $sqlServer;
    }

    public function connect(array $params)
    {
        $connection = new Connection($this->pdo);

        return $this->sqlServer ? new SqlServerConnection($connection) : $connection;
    }
}
