<?php

require_once __DIR__ . '/../controllers/ChatController.php';

function handleChatRoutes($method, $uri, $db) {
    $controller = new ChatController($db);

    if ($method === 'POST' && $uri === 'chat/ask') {
        $controller->ask();
    }

    if ($method === 'GET' && $uri === 'chat/logs') {
        $controller->getLogs();
    }
}