<?php

// Never print PHP warnings into API responses: a stray notice before the JSON breaks the app.
// Errors are still written to C:\xampp\apache\logs\error.log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Manila'); // so message timestamps show local time

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// TEMPORARY DEBUG LOGGING (method + route only, never request bodies)
$logEntry = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REQUEST_METHOD'] . ' | ' . ($_GET['url'] ?? '') . "\n";
file_put_contents(__DIR__ . '/debug.log', $logEntry, FILE_APPEND);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/chat.php';  
require_once __DIR__ . '/routes/sensor.php';
require_once __DIR__ . '/routes/device.php';


$database = new Database();
$db = $database->connect();

$method = $_SERVER['REQUEST_METHOD'];
$uri = trim($_GET['url'] ?? '', '/');

handleAuthRoutes($method, $uri, $db);
handleChatRoutes($method, $uri, $db);
handleSensorRoutes($method, $uri, $db);
if (handleDeviceRoutes($method, $uri, $db)) {
    exit;
}


Response::error('Route not found.', 404);