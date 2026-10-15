<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function login() {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            Response::error('Email and password are required.', 422);
        }

        $userModel = new User($this->db);
        $user = $userModel->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
            Response::error('Invalid credentials.', 401);
        }

        // TODO: replace with a real JWT later; this is a placeholder token
        $token = bin2hex(random_bytes(32));

        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['user_id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    public function register() {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (!$name || !$email || !$password) {
            Response::error('Name, email, and password are required.', 422);
        }

        $userModel = new User($this->db);

        if ($userModel->findByEmail($email)) {
            Response::error('Email already registered.', 409);
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $userId = $userModel->create($name, $email, $hashed);

        Response::success(['id' => $userId, 'name' => $name, 'email' => $email], 201);
    }
}