<?php

require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware {
    public static function verify() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Unauthorized', 401);
        }

        $token = substr($authHeader, 7);

        if (empty($token)) {
            Response::error('Invalid token', 401);
        }

        return $token;
    }
}