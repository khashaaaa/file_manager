<?php
declare(strict_types=1);
ini_set('upload_max_filesize', '10G');
ini_set('post_max_size', '10G');
ini_set('memory_limit', '10G');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class Config {
    public const DB_CONFIG = [
        'host' => 'localhost',
        'port' => '5432',
        'dbname' => 'file_manager',
        'user' => 'postgres',
        'password' => '123',
        'sslmode' => 'disable'
    ];
    
    public const UPLOAD_BASE_PATH = __DIR__ . '/uploads';
    public const UPLOAD_PATHS = [
        'image' => self::UPLOAD_BASE_PATH . '/images/',
        'video' => self::UPLOAD_BASE_PATH . '/videos/',
        'document' => self::UPLOAD_BASE_PATH . '/documents/'
    ];
    
    public const MAX_FILE_SIZE = 10 * 1024 * 1024 * 1024;
    
    public const FILE_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/mpeg', 'video/quicktime'],
        'document' => [
            'application/pdf', 
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'application/rtf',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation'
        ]
    ];
    
}

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
    
    public function query(string $query, array $params = []): PgSql\Result {
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

class FileUploadHandler {
    private array $uploadQueue = [];
    private array $results = [];
    
    public function __construct(
        private readonly DatabaseConnection $db,
        private readonly array $allowedTypes,
        private readonly array $uploadPaths,
        private readonly int $maxFileSize
    ) {}
    
    public function processFiles(array $files): array {
        
        if (isset($files['file']) && is_array($files['file']) && isset($files['file'][0]) && is_array($files['file'][0])) {
            return $this->handleWorkermanMultipleFiles($files);
        }
        
        $alternativeFormat = false;
        foreach (array_keys($files) as $key) {
            if (preg_match('/file\[\d+\]/', $key)) {
                $alternativeFormat = true;
                break;
            }
        }
        
        if ($alternativeFormat) {
            $reorganized = ['file' => []];
            foreach ($files as $key => $value) {
                if (preg_match('/file\[(\d+)\]/', $key, $matches)) {
                    $index = $matches[1];
                    $reorganized['file'][$index] = $value;
                }
            }
            return $this->handleWorkermanMultipleFiles($reorganized);
        }
        
        if ($this->isMultiUpload($files)) {
            return $this->handleMultipleFiles($files);
        }
        
        return [$this->handleSingleFile($files)];
    }
    
    private function isMultiUpload(array $files): bool {
        
        if (isset($files['file']['name']) && is_array($files['file']['name'])) {
            return count($files['file']['name']) > 1;
        }
        
        if (isset($files['file']) && is_array($files['file'])) {
            foreach (array_keys($files['file']) as $key) {
                if (is_numeric($key)) {
                    return true;
                }
            }
        }
        
        if (is_array($files) && count($files) > 0) {
            $firstKey = array_key_first($files);
            if (preg_match('/file\[\d+\]/', $firstKey)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function handleWorkermanMultipleFiles(array $files): array {
        $results = [];
        
        foreach ($files['file'] as $index => $fileData) {
            try {
                if (!isset($fileData['name']) || 
                    !isset($fileData['type']) || 
                    !isset($fileData['tmp_name']) || 
                    !isset($fileData['error']) || 
                    !isset($fileData['size'])) {
                    
                    $results[] = [
                        'name' => $fileData['name'] ?? "Unknown file #{$index}",
                        'error' => 'Missing file information'
                    ];
                    continue;
                }
                
                $this->validateFile($fileData);
                
                $category = $this->getFileCategory($fileData['type']);
                
                $extension = pathinfo($fileData['name'], PATHINFO_EXTENSION);
                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
                $uploadPath = $this->uploadPaths[$category] . $storedName;
                
                $upload = [
                    'file' => $fileData,
                    'stored_name' => $storedName,
                    'upload_path' => $uploadPath,
                    'category' => $category
                ];
                
                $result = $this->moveFileAndSave($upload);
                $results[] = $result;
                
            } catch (Throwable $e) {
                error_log("Error processing file #{$index}: " . $e->getMessage());
                $results[] = [
                    'name' => $fileData['name'] ?? "Unknown file #{$index}",
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    private function handleMultipleFiles(array $files): array {
        if (!isset($files['file']['name']) || !is_array($files['file']['name'])) {
            error_log("Invalid file structure received: " . print_r($files, true));
            return [['error' => 'Invalid file upload structure']];
        }
        
        $totalFiles = count($files['file']['name']);
        $this->results = [];
        
        $validFiles = [];
        for ($i = 0; $i < $totalFiles; $i++) {
            if (!isset($files['file']['name'][$i]) || 
                !isset($files['file']['type'][$i]) || 
                !isset($files['file']['tmp_name'][$i]) || 
                !isset($files['file']['error'][$i]) || 
                !isset($files['file']['size'][$i])) {
                
                $this->results[] = [
                    'name' => $files['file']['name'][$i] ?? "Unknown file #{$i}",
                    'error' => 'Missing file information'
                ];
                continue;
            }
            
            try {
                $fileData = [
                    'name' => $files['file']['name'][$i],
                    'type' => $files['file']['type'][$i],
                    'tmp_name' => $files['file']['tmp_name'][$i],
                    'error' => $files['file']['error'][$i],
                    'size' => $files['file']['size'][$i]
                ];
                
                $this->validateFile($fileData);
                $category = $this->getFileCategory($fileData['type']);
                
                $extension = pathinfo($fileData['name'], PATHINFO_EXTENSION);
                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
                $uploadPath = $this->uploadPaths[$category] . $storedName;
                
                $validFiles[] = [
                    'file' => $fileData,
                    'stored_name' => $storedName,
                    'upload_path' => $uploadPath,
                    'category' => $category
                ];
            } catch (Throwable $e) {
                $this->results[] = [
                    'name' => $files['file']['name'][$i] ?? "Unknown file #{$i}",
                    'error' => $e->getMessage()
                ];
            }
        }
        
        foreach ($validFiles as $upload) {
            try {
                $result = $this->moveFileAndSave($upload);
                $this->results[] = $result;
            } catch (Throwable $e) {
                $this->results[] = [
                    'name' => $upload['file']['name'] ?? "Unknown file",
                    'error' => $e->getMessage()
                ];
            }
        }        
        
        return $this->results;
    }
    
    private function handleSingleFile(array $files): array {
        if (!isset($files['file']) || !is_array($files['file'])) {
            error_log("Invalid file structure in handleSingleFile: " . print_r($files, true));
            return [
                'name' => 'Unknown',
                'error' => 'Invalid file structure received'
            ];
        }
        
        $file = $files['file'];
        
        if (!isset($file['name']) || !isset($file['type']) || 
            !isset($file['tmp_name']) || !isset($file['error']) || 
            !isset($file['size'])) {
            
            error_log("Missing required file keys: " . print_r($file, true));
            return [
                'name' => $file['name'] ?? 'Unknown',
                'error' => 'Missing required file information'
            ];
        }
        
        try {
            $this->validateFile($file);
            $category = $this->getFileCategory($file['type']);
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $uploadPath = $this->uploadPaths[$category] . $storedName;
            
            $upload = [
                'file' => $file,
                'stored_name' => $storedName,
                'upload_path' => $uploadPath,
                'category' => $category
            ];
            
            return $this->moveFileAndSave($upload);
        } catch (Throwable $e) {
            return [
                'name' => $file['name'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function moveFileAndSave(array $upload): array {
        $file = $upload['file'];
        $uploadPath = $upload['upload_path'];
        $tmpName = $file['tmp_name'];
        
        if (!file_exists($tmpName)) {
            throw new RuntimeException("Temporary file not found: {$tmpName}");
        }

        if (!is_readable($tmpName)) {
            throw new RuntimeException("Cannot read temporary file: {$tmpName}");
        }

        $uploadDir = dirname($uploadPath);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        if (!is_writable($uploadDir)) {
            chmod($uploadDir, 0777);
            if (!is_writable($uploadDir)) {
                throw new RuntimeException("Upload directory not writable: {$uploadDir}");
            }
        }

        if (file_exists($uploadPath)) {
            $info = pathinfo($uploadPath);
            $uploadPath = $info['dirname'] . '/' . bin2hex(random_bytes(16)) . '.' . $info['extension'];
        }

        if (!copy($tmpName, $uploadPath)) {
            $error = error_get_last();
            throw new RuntimeException(
                sprintf(
                    "Failed to move file. Error: %s",
                    $error['message'] ?? 'Unknown error'
                )
            );
        }
        
        @unlink($tmpName);
        
        chmod($uploadPath, 0666);
        
        $id = $this->saveFileRecord([
            'original_name' => $file['name'],
            'stored_name' => basename($uploadPath),
            'file_path' => $uploadPath,
            'mime_type' => $file['type'],
            'file_size' => $file['size'],
            'category' => $upload['category']
        ]);
        
        return [
            'id' => $id,
            'name' => $file['name'],
            'category' => $upload['category'],
            'status' => 'success'
        ];
    }
    
    private function validateFile(array $file): void {
        if (!isset($file['error']) || !isset($file['tmp_name']) || !isset($file['size']) || !isset($file['type'])) {
            throw new RuntimeException('Invalid file data provided');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->getUploadErrorMessage($file['error']));
        }
        
        if ($file['size'] <= 0) {
            throw new RuntimeException('File is empty');
        }

        if ($file['size'] > $this->maxFileSize) {
            throw new RuntimeException(sprintf(
                'File size %s exceeds limit of %s',
                $this->formatBytes($file['size']),
                $this->formatBytes($this->maxFileSize)
            ));
        }
        
        if (!in_array($file['type'], array_merge(...array_values($this->allowedTypes)), true)) {
            throw new RuntimeException(sprintf(
                'File type %s is not allowed. Allowed types: %s',
                $file['type'],
                implode(', ', array_merge(...array_values($this->allowedTypes)))
            ));
        }
    }

    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    private function getFileCategory(string $mimeType): string {
        foreach ($this->allowedTypes as $category => $types) {
            if (in_array($mimeType, $types, true)) {
                return $category;
            }
        }
        throw new RuntimeException("Unsupported file type: $mimeType");
    }
    
    private function saveFileRecord(array $data): int {
        $result = $this->db->query(
            "INSERT INTO files (original_name, stored_name, file_path, mime_type, file_size, category) 
             VALUES ($1, $2, $3, $4, $5, $6) 
             RETURNING id",
            array_values($data)
        );
        return (int)pg_fetch_result($result, 0, 0);
    }

    private function getUploadErrorMessage(int $error): string {
        return match($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File size exceeds limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => 'Unknown upload error'
        };
    }
}

class FileManagerAPI {
    private DatabaseConnection $db;
    private FileUploadHandler $uploadHandler;
    private const RESPONSE_HEADERS = ['Content-Type' => 'application/json'];
    
    public function __construct() {
        $this->db = DatabaseConnection::getInstance();
        $this->uploadHandler = new FileUploadHandler(
            $this->db,
            Config::FILE_TYPES,
            Config::UPLOAD_PATHS,
            Config::MAX_FILE_SIZE
        );
        $this->initializeStorage();
        $this->initDatabase();
    }
    
    private function initializeStorage(): void {
        $baseDir = Config::UPLOAD_BASE_PATH;
        
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0777, true)) {
                throw new RuntimeException("Failed to create base directory: $baseDir");
            }
        }
        chmod($baseDir, 0777);
        
        foreach (Config::UPLOAD_PATHS as $path) {
            if (!is_dir($path)) {
                if (!mkdir($path, 0777, true)) {
                    throw new RuntimeException("Failed to create directory: $path");
                }
            }
            chmod($path, 0777);
            
            error_log(sprintf(
                "Storage directory %s: exists=%s, writable=%s, perms=%s, owner=%s",
                $path,
                is_dir($path) ? 'yes' : 'no',
                is_writable($path) ? 'yes' : 'no',
                substr(sprintf('%o', fileperms($path)), -4),
                posix_getpwuid(fileowner($path))['name']
            ));
        }
    }
    
    private function initDatabase(): void {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS files (
                id SERIAL PRIMARY KEY,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT NOT NULL,
                category VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    public function handleRequest(Request $request): Response {
        if ($request->method() === 'OPTIONS') {
            return new Response(200, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With, Authorization',
                'Access-Control-Max-Age' => '86400',
            ]);
        }
        
        try {
            $method = $request->method();
            $path = parse_url($request->path(), PHP_URL_PATH);
            
            $response = match(true) {
                $method === 'GET' && preg_match('/^\/files\/(\d+)$/', $path, $matches) => $this->getFile((int)$matches[1]),
                $method === 'GET' && $path === '/files' => $this->listFiles(),
                $method === 'POST' && $path === '/files' => $this->handleFileUpload($request),
                $method === 'PUT' && preg_match('/^\/files\/(\d+)$/', $path, $matches) => $this->updateFile((int)$matches[1], $request),
                $method === 'DELETE' && preg_match('/^\/files\/(\d+)$/', $path, $matches) => $this->deleteFile((int)$matches[1]),
                default => $this->createResponse(['error' => 'Not Found'], 404)
            };
            
            $response->withHeader('Access-Control-Allow-Origin', '*');
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization');
            
            return $response;
        } catch (Throwable $e) {
            error_log("Unhandled exception in handleRequest: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $response = $this->createResponse([
                'error' => 'Internal Server Error', 
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
            
            $response->withHeader('Access-Control-Allow-Origin', '*');
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization');
            
            return $response;
        }
    }
    
    private function createResponse(array $data, int $status = 200): Response {
        $headers = array_merge(self::RESPONSE_HEADERS, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With, Authorization'
        ]);
        
        return new Response($status, $headers, json_encode($data));
    }
    
    private function listFiles(): Response {
        try {
            $result = $this->db->query("SELECT * FROM files ORDER BY created_at DESC");
            
            $files = [];
            while ($row = pg_fetch_assoc($result)) {
                $files[] = $this->sanitizeFileRecord($row);
            }
            
            return $this->createResponse(['files' => $files]);
        } catch (Throwable $e) {
            error_log("Error in listFiles: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return $this->createResponse(['error' => 'Failed to list files', 'message' => $e->getMessage()], 500);
        }
    }
    
    private function getFile(int $id): Response {
        $result = $this->db->query("SELECT * FROM files WHERE id = $1", [$id]);
        if ($row = pg_fetch_assoc($result)) {
            return $this->createResponse($this->sanitizeFileRecord($row));
        }
        return $this->createResponse(['error' => 'File not found'], 404);
    }
    
    private function handleFileUpload(Request $request): Response {
        $files = $this->debugFileUpload($request);
        
        if (empty($files['file'])) {
            return $this->createResponse(['error' => 'No files uploaded'], 400);
        }
        
        $results = $this->uploadHandler->processFiles($files);
        
        $success = array_filter($results, fn($r) => !isset($r['error']));
        $failures = array_filter($results, fn($r) => isset($r['error']));
        
        return $this->createResponse([
            'message' => 'File upload processing complete',
            'successful' => count($success),
            'failed' => count($failures),
            'results' => $results
        ], !empty($success) ? 201 : 400);
    }
    
    private function updateFile(int $id, Request $request): Response {
        $data = json_decode($request->rawBody(), true);
        
        if (!isset($data['original_name']) || trim($data['original_name']) === '') {
            return $this->createResponse(['error' => 'Invalid file name'], 400);
        }
        
        $result = $this->db->query(
            "UPDATE files SET original_name = $1, updated_at = CURRENT_TIMESTAMP WHERE id = $2 RETURNING *",
            [trim($data['original_name']), $id]
        );
        
        if ($row = pg_fetch_assoc($result)) {
            return $this->createResponse($this->sanitizeFileRecord($row));
        }
        
        return $this->createResponse(['error' => 'File not found'], 404);
    }
    
    private function deleteFile(int $id): Response {
        $result = $this->db->query("SELECT * FROM files WHERE id = $1", [$id]);
        
        if ($file = pg_fetch_assoc($result)) {
            $filePath = $file['file_path'];
            
            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    throw new RuntimeException('Failed to delete file from disk');
                }
            }
            
            $this->db->query("DELETE FROM files WHERE id = $1", [$id]);
            return $this->createResponse(['message' => 'File deleted successfully']);
        }
        
        return $this->createResponse(['error' => 'File not found'], 404);
    }
    
    private function sanitizeFileRecord(array $record): array {
        unset($record['file_path']);
        return $record;
    }

    private function debugFileUpload(Request $request): array {
        $files = $request->file();
                
        $normalizedFiles = $this->normalizeFileStructure($files);
        
        return $normalizedFiles;
    }
    
    private function normalizeFileStructure(array $files): array {
        $normalized = [];
        
        if (isset($files['file']['name']) && is_array($files['file']['name'])) {
            $normalized['file'] = [];
            $count = count($files['file']['name']);
            
            for ($i = 0; $i < $count; $i++) {
                if (isset($files['file']['name'][$i])) {
                    $normalized['file'][$i] = [
                        'name' => $files['file']['name'][$i] ?? null,
                        'type' => $files['file']['type'][$i] ?? null,
                        'tmp_name' => $files['file']['tmp_name'][$i] ?? null,
                        'error' => $files['file']['error'][$i] ?? null,
                        'size' => $files['file']['size'][$i] ?? null
                    ];
                }
            }
            return $normalized;
        }
        
        $hasIndexedFiles = false;
        foreach (array_keys($files) as $key) {
            if (preg_match('/file\[(\d+)\]/', $key, $matches)) {
                $hasIndexedFiles = true;
                $index = $matches[1];
                if (!isset($normalized['file'])) {
                    $normalized['file'] = [];
                }
                $normalized['file'][$index] = $files[$key];
            }
        }
        
        if ($hasIndexedFiles) {
            return $normalized;
        }
        
        if (isset($files['file']) && !is_array($files['file']['name'] ?? null)) {
            return $files;
        }
        
        if (isset($files['file']) && is_array($files['file']) && isset($files['file'][0]) && is_array($files['file'][0])) {
            return $files;
        }
        
        return $files;
    }
}

TcpConnection::$defaultMaxPackageSize = 10 * 1024 * 1024 * 1024;

$worker = new Worker("http://0.0.0.0:8080");
$worker->count = max(1, (int)shell_exec('nproc') ?: 4);

try {
    $api = new FileManagerAPI();
    $worker->onMessage = static fn(TcpConnection $connection, Request $request) => $connection->send($api->handleRequest($request));
    Worker::runAll();
} catch (Throwable $e) {
    error_log('Server initialization failed: ' . $e->getMessage());
    exit(1);
}