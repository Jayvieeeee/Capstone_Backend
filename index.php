<?php

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

$database = new Database();
$db = $database->connect();

$method = $_SERVER['REQUEST_METHOD'];
$uri = trim($_GET['url'] ?? '', '/');

handleAuthRoutes($method, $uri, $db);
handleChatRoutes($method, $uri, $db);

Response::error('Route not found.', 404);