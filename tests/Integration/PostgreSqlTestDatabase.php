<?php

declare(strict_types=1);

namespace Tests\Integration;

final class PostgreSqlTestDatabase
{
    public readonly string $schema;

    private ?\PDO $connection = null;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {
        $this->schema = 'phpunit_' . bin2hex(random_bytes(12));
    }

    public static function fromEnvironment(): self
    {
        $database = getenv('TEST_DB_NAME');
        if ($database === false || trim($database) === '') {
            throw new \RuntimeException('Set TEST_DB_NAME explicitly before running integration tests; application DB_* settings are not used.');
        }

        $portValue = getenv('TEST_DB_PORT');
        $port = filter_var($portValue !== false && $portValue !== '' ? $portValue : '5432', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if (!\is_int($port)) {
            throw new \RuntimeException('TEST_DB_PORT must be an integer between 1 and 65535.');
        }

        $password = getenv('TEST_DB_PASSWORD');

        return new self(
            host: getenv('TEST_DB_HOST') ?: 'localhost',
            port: $port,
            database: $database,
            username: getenv('TEST_DB_USER') ?: 'app',
            password: $password !== false ? $password : 'password',
        );
    }

    public function connect(): \PDO
    {
        return new \PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
            $this->username,
            $this->password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    public function create(): void
    {
        if ($this->connection !== null) {
            throw new \LogicException('The integration schema has already been created.');
        }

        $connection = $this->connect();
        $connection->exec('CREATE SCHEMA "' . $this->schema . '"');
        // Keep ownership only after CREATE succeeds, so a collision can never delete an existing schema.
        $this->connection = $connection;
    }

    /**
     * @return array<string, string>
     */
    public function applicationEnvironment(): array
    {
        if ($this->connection === null) {
            throw new \LogicException('Create the isolated schema before configuring the application.');
        }

        return [
            'DB_HOST' => $this->host,
            'DB_PORT' => (string) $this->port,
            'DB_NAME' => $this->database,
            'DB_USER' => $this->username,
            'DB_PASSWORD' => $this->password,
            'DB_SCHEMA' => $this->schema,
        ];
    }

    public function cleanup(): void
    {
        if ($this->connection === null) {
            return;
        }

        if (preg_match('/\Aphpunit_[a-f0-9]{24}\z/', $this->schema) !== 1) {
            throw new \LogicException('Refusing to drop a schema outside the integration sandbox.');
        }

        $this->connection->exec('DROP SCHEMA "' . $this->schema . '" CASCADE');
        $this->connection = null;
    }
}
