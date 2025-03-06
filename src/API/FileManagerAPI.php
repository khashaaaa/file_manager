<?php
declare(strict_types=1);

namespace App\API;

use App\Config\Config;
use App\Database\DatabaseConnection;
use App\Handlers\FileUploadHandler;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use RuntimeException;
use Throwable;

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
                $method === 'POST' && $path === '/files/bulk-delete' => $this->bulkDeleteFiles($request),
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
    
    private function bulkDeleteFiles(Request $request): Response {
        $data = json_decode($request->rawBody(), true);
        
        if (!isset($data['fileIds']) || !is_array($data['fileIds']) || empty($data['fileIds'])) {
            return $this->createResponse(['error' => 'No file IDs provided for deletion'], 400);
        }
        
        $fileIds = array_map('intval', $data['fileIds']);
        
        $placeholders = implode(',', array_map(function($i) {
            return '$' . ($i + 1);
        }, range(0, count($fileIds) - 1)));
        
        $result = $this->db->query(
            "SELECT * FROM files WHERE id IN ($placeholders)",
            $fileIds
        );
        
        $deletedCount = 0;
        $errors = [];
        $deletedFiles = [];
        
        while ($file = pg_fetch_assoc($result)) {
            try {
                $filePath = $file['file_path'];
                $deletedFiles[] = (int)$file['id'];
                
                if (file_exists($filePath)) {
                    if (!unlink($filePath)) {
                        $errors[] = [
                            'id' => (int)$file['id'],
                            'name' => $file['original_name'],
                            'error' => 'Failed to delete file from disk'
                        ];
                        continue;
                    }
                }
                
                $deletedCount++;
            } catch (Throwable $e) {
                $errors[] = [
                    'id' => (int)$file['id'],
                    'name' => $file['original_name'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        if (!empty($deletedFiles)) {
            $dbPlaceholders = implode(',', array_map(function($i) {
                return '$' . ($i + 1);
            }, range(0, count($deletedFiles) - 1)));
            
            $this->db->query(
                "DELETE FROM files WHERE id IN ($dbPlaceholders)",
                $deletedFiles
            );
        }
        
        return $this->createResponse([
            'message' => 'Bulk delete operation completed',
            'deleted_count' => $deletedCount,
            'total' => count($fileIds),
            'requested_ids' => $fileIds,
            'deleted_ids' => $deletedFiles,
            'errors' => $errors
        ]);
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