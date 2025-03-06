<?php
declare(strict_types=1);

namespace App\Config;

class Config {
    public const DB_CONFIG = [
        'host' => 'localhost',
        'port' => '5432',
        'dbname' => 'file_manager',
        'user' => 'postgres',
        'password' => '123',
        'sslmode' => 'disable'
    ];
    
    public const UPLOAD_BASE_PATH = __DIR__ . '/../../uploads';
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