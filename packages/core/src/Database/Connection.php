<?php

declare(strict_types=1);

namespace MaxServ\Core\Database;

use PDO;
use Pdo\Mysql;

readonly class Connection
{
    private PDO $pdo;

    /**
     * Opens a MySQL connection that uses utf8mb4, real prepared statements and one statement per call.
     *
     * @param string $host The database host name.
     * @param string $user The database user.
     * @param string $password The database password.
     * @param string $database The database name.
     *
     * @throws \PDOException The connection could not be opened.
     */
    public function __construct(
        string $host,
        string $user,
        #[\SensitiveParameter] string $password,
        string $database
    ) {
        $this->pdo = PDO::connect(
            "mysql:host=$host;dbname=$database;charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                Mysql::ATTR_MULTI_STATEMENTS => false,
            ],
        );
    }

    /**
     * Returns the open database connection.
     *
     * @return PDO The connection.
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
