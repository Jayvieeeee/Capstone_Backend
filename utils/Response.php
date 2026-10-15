<?php

class Response {
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function error($message, $statusCode = 400) {
        self::json(['error' => $message], $statusCode);
    }

    public static function success($data = [], $statusCode = 200) {
        self::json(['success' => true, 'data' => $data], $statusCode);
    }
}