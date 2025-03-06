<?php
declare(strict_types=1);

namespace App\Handlers;

use App\Database\DatabaseConnection;
use RuntimeException;
use Throwable;

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