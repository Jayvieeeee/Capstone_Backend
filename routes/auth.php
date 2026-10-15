<?php

require_once __DIR__ . '/../controllers/AuthController.php';

function handleAuthRoutes($method, $uri, $db) {
    $controller = new AuthController($db);

    if ($method === 'POST' && $uri === 'auth/login') {
        $controller->login();
    }

    if ($method === 'POST' && $uri === 'auth/register') {
        $controller->register();
    }
}