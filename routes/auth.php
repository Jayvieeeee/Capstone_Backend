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

    if ($method === 'POST' && $uri === 'auth/forgot-password') {
        $controller->forgotPassword();
    }

    if ($method === 'POST' && $uri === 'auth/reset-password') {
        $controller->resetPassword();
    }

    if ($method === 'POST' && $uri === 'auth/update-password') {
        $controller->updatePassword();
    }

    if ($method === 'POST' && $uri === 'auth/update-profile') {
        $controller->updateProfile();
    }
}