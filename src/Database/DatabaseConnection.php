<?php
declare(strict_types=1);

namespace App\Database;

use App\Config\Config;
use RuntimeException;
use Throwable;
use PgSql\Result;

class DatabaseConnection {
    private static ?DatabaseConnection $instance = null;
    private $connection;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance(): self {
        return self::$instance ??= new self();
    }
    
    private function connect(): void {
        try {
            $config = Config::DB_CONFIG;
            
            $connString = sprintf(
                "host=%s port=%s dbname=%s user=%s password=%s sslmode=%s",
                $config['host'],
                $config['port'],
                $config['dbname'],
                $config['user'],
                $config['password'],
                $config['sslmode']
            );
            
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            
            $this->connection = pg_connect($connString);
            
            if ($this->connection === false) {
                throw new RuntimeException('Database connection failed: ' . pg_last_error());
            }
            
            $testResult = pg_query($this->connection, "SELECT 1");
            if ($testResult === false) {
                throw new RuntimeException('Connection test failed: ' . pg_last_error($this->connection));
            }
            pg_free_result($testResult);
            
        } catch (Throwable $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new RuntimeException('Failed to establish database connection: ' . $e->getMessage());
        }
    }
    
    public function query(string $query, array $params = []): Result {
        try {
            if (empty($params)) {
                $result = pg_query($this->connection, $query);
            } else {
                $result = pg_query_params($this->connection, $query, $params);
            }
            
            if ($result === false) {
                throw new RuntimeException(pg_last_error($this->connection));
            }
            
            return $result;
            
        } catch (Throwable $e) {
            error_log('Database query error: ' . $e->getMessage());
            throw new RuntimeException('Database query failed: ' . $e->getMessage());
        }
    }
}