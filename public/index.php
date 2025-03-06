<?php
declare(strict_types=1);

ini_set('upload_max_filesize', '10G');
ini_set('post_max_size', '10G');
ini_set('memory_limit', '10G');
ini_set('max_execution_time', '300');
ini_set('max_input_time', '300');

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use App\API\FileManagerAPI;

TcpConnection::$defaultMaxPackageSize = 10 * 1024 * 1024 * 1024;

$cpuCores = (int) trim(shell_exec('nproc') ?: '4');
$worker = new Worker("http://0.0.0.0:8080");
$worker->count = max(1, $cpuCores);

try {
    $api = new FileManagerAPI();
    
    $worker->onMessage = static function(TcpConnection $connection, $request) use ($api) {
        $connection->send($api->handleRequest($request));
    };
    
    Worker::$onMasterStop = function () {
        error_log("Server is shutting down...");
    };

    Worker::runAll();
} catch (Throwable $e) {
    error_log('Server initialization failed: ' . $e->getMessage());
    Worker::stopAll();
    exit(1);
}
