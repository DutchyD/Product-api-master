<?php

declare(strict_types=1);

namespace MaxServ\Core\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Symfony\Component\Finder\Finder;

/**
 * Applies the SQL migration files of a directory that have not been applied yet.
 */
final readonly class MigrationRunner
{
    private const string MIGRATION_FILE_PATTERN = '*.sql';
    private const string TRIMMED_CHARACTERS = "; \t\n\r\0\x0B";

    private PDO $pdo;

    /**
     * Creates the runner.
     *
     * @param Connection $connection The database to migrate.
     */
    public function __construct(Connection $connection)
    {
        $this->pdo = $connection->getConnection();
    }

    /**
     * Applies, in file name order, every migration file that is not recorded in schema_migrations yet.
     *
     * Each file must hold exactly one SQL statement that is safe to run again, because the statement and its record
     * cannot share a transaction in MySQL. Files applied before a failure stay recorded.
     *
     * @param string $directory The directory that holds the migration files.
     *
     * @return list<string> The versions (file names) applied by this call, in order.
     * @throws MigrationFailed A file is empty or its statement failed.
     * @throws \Symfony\Component\Finder\Exception\DirectoryNotFoundException The directory does not exist.
     * @throws \RuntimeException A migration file could not be read.
     * @throws PDOException The schema_migrations table could not be created or read, or an applied version could not
     *                      be recorded.
     */
    public function migrate(string $directory): array
    {
        $this->createMigrationTable();
        $appliedVersions = $this->appliedVersions();

        $files = new Finder()->files()->in($directory)->depth(0)->name(self::MIGRATION_FILE_PATTERN)->sortByName();

        $newlyApplied = [];
        foreach ($files as $file) {
            $version = $file->getFilename();
            if (isset($appliedVersions[$version])) {
                continue;
            }

            $this->apply($version, $file->getContents());
            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }

    private function createMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(255) NOT NULL, '
            . 'applied_at DATETIME(3) NOT NULL, '
            . 'PRIMARY KEY (version)'
            . ') ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci'
        );
    }

    /**
     * @return array<string, true>
     */
    private function appliedVersions(): array
    {
        $versions = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys($versions, true);
    }

    private function apply(string $version, string $contents): void
    {
        $statement = trim($contents, self::TRIMMED_CHARACTERS);
        if ($statement === '') {
            throw new MigrationFailed("Migration $version is empty.");
        }

        try {
            $this->pdo->exec($statement);
        } catch (PDOException $exception) {
            throw new MigrationFailed("Migration $version failed: {$exception->getMessage()}", previous: $exception);
        }

        $record = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
        $record->execute([
            'version' => $version,
            'applied_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
        ]);
    }
}
