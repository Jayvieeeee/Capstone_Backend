<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/PasswordReset.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Mailer.php';

class AuthController {
    private $db;

    private const MIN_PASSWORD_LENGTH = 8;
    private const RESET_CODE_TTL_MINUTES = 15;
    private const RESET_MAX_ATTEMPTS = 5;
    private const RESET_COOLDOWN_SECONDS = 60;

    public function __construct($db) {
        $this->db = $db;
    }

    private function getJsonInput() {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    public function login() {
        $input = $this->getJsonInput();
        $email = strtolower(trim($input['email'] ?? ''));
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
        $input = $this->getJsonInput();
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (!$name || !$email || !$password) {
            Response::error('Name, email, and password are required.', 422);
        }

        if (mb_strlen($name) > 100) {
            Response::error('Name is too long.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address.', 422);
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            Response::error('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.', 422);
        }

        $userModel = new User($this->db);

        if ($userModel->findByEmail($email)) {
            Response::error('Email already registered.', 409);
        }

        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            // Role is always 'user' here; it is never accepted from the request body.
            $userId = $userModel->create($name, $email, $hashed, 'user');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Email already registered.', 409);
            }
            Response::error('Could not create account.', 500);
        }

        Response::success([
            'user' => [
                'id' => (int) $userId,
                'name' => $name,
                'email' => $email,
                'role' => 'user',
            ],
        ], 201);
    }

    // POST /auth/forgot-password  body: { "email": "..." }
    public function forgotPassword() {
        $input = $this->getJsonInput();
        $email = strtolower(trim($input['email'] ?? ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address.', 422);
        }

        try {
            $userModel = new User($this->db);
            $user = $userModel->findByEmail($email);

            if ($user) {
                $resetModel = new PasswordReset($this->db);

                if (!$resetModel->requestedRecently($user['user_id'], self::RESET_COOLDOWN_SECONDS)) {
                    $code = (string) random_int(100000, 999999);
                    $resetModel->create(
                        $user['user_id'],
                        password_hash($code, PASSWORD_DEFAULT),
                        self::RESET_CODE_TTL_MINUTES
                    );

                    $sent = Mailer::sendResetCode(
                        $user['email'],
                        $user['name'],
                        $code,
                        self::RESET_CODE_TTL_MINUTES
                    );

                    if (!$sent) {
                        // Remove the unusable code so the user can retry right away.
                        $resetModel->deleteByUserId($user['user_id']);
                        Response::error("We couldn't send the email right now. Please try again later.", 500);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Forgot password failed: ' . $e->getMessage());
            Response::error('Something went wrong. Please try again.', 500);
        }

        // Same answer whether or not the email exists, so nobody can probe which emails are registered.
        Response::success([
            'message' => 'If that email is registered, a reset code has been sent. It expires in '
                . self::RESET_CODE_TTL_MINUTES . ' minutes.',
        ]);
    }

    // POST /auth/reset-password  body: { "email": "...", "code": "123456", "password": "..." }
    public function resetPassword() {
        $input = $this->getJsonInput();
        $email = strtolower(trim($input['email'] ?? ''));
        $code = trim((string) ($input['code'] ?? ''));
        $password = $input['password'] ?? '';

        if (!$email || !$code || !$password) {
            Response::error('Email, code, and new password are required.', 422);
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            Response::error('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.', 422);
        }

        $invalid = 'Invalid or expired code.';

        try {
            $userModel = new User($this->db);
            $user = $userModel->findByEmail($email);

            if (!$user) {
                Response::error($invalid, 400);
            }

            $resetModel = new PasswordReset($this->db);
            $reset = $resetModel->findActiveByUserId($user['user_id']);

            if (!$reset) {
                Response::error($invalid, 400);
            }

            if ((int) $reset['attempts'] >= self::RESET_MAX_ATTEMPTS) {
                $resetModel->deleteByUserId($user['user_id']);
                Response::error('Too many attempts. Please request a new code.', 429);
            }

            if (!password_verify($code, $reset['code_hash'])) {
                $resetModel->incrementAttempts($reset['reset_id']);
                Response::error($invalid, 400);
            }

            $userModel->updatePassword($user['user_id'], password_hash($password, PASSWORD_DEFAULT));
            $resetModel->deleteByUserId($user['user_id']);
        } catch (PDOException $e) {
            error_log('Reset password failed: ' . $e->getMessage());
            Response::error('Something went wrong. Please try again.', 500);
        }

        Response::success(['message' => 'Password updated. You can now log in.']);
    }

    // POST /auth/update-password body: { "email": "...", "currentPassword": "...", "newPassword": "..." }
    public function updatePassword() {
        $input = $this->getJsonInput();
        $email = strtolower(trim($input['email'] ?? ''));
        $currentPassword = $input['currentPassword'] ?? '';
        $newPassword = $input['newPassword'] ?? '';

        if (!$email || !$currentPassword || !$newPassword) {
            Response::error('Email, current password, and new password are required.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address.', 422);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            Response::error('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.', 422);
        }

        try {
            $userModel = new User($this->db);
            $user = $userModel->findByEmail($email);

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                Response::error('Current password is incorrect.', 401);
            }

            $userModel->updatePassword(
                $user['user_id'],
                password_hash($newPassword, PASSWORD_DEFAULT)
            );
        } catch (PDOException $e) {
            error_log('Update password failed: ' . $e->getMessage());
            Response::error('Could not update password. Please try again.', 500);
        }

        Response::success(['message' => 'Password updated successfully.']);
    }

    // POST /auth/update-profile body: { "currentEmail": "...", "name": "...", "email": "..." }
    public function updateProfile() {
        $input = $this->getJsonInput();
        $currentEmail = strtolower(trim($input['currentEmail'] ?? ''));
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));

        if (!$currentEmail || !$name || !$email) {
            Response::error('Current email, name, and new email are required.', 422);
        }

        if (mb_strlen($name) > 100) {
            Response::error('Name is too long.', 422);
        }

        if (!filter_var($currentEmail, FILTER_VALIDATE_EMAIL) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address.', 422);
        }

        try {
            $userModel = new User($this->db);
            $user = $userModel->findByEmail($currentEmail);

            if (!$user) {
                Response::error('Account not found.', 404);
            }

            $existingUser = $userModel->findByEmail($email);
            if ($existingUser && (int) $existingUser['user_id'] !== (int) $user['user_id']) {
                Response::error('Email already registered.', 409);
            }

            $userModel->updateProfile($user['user_id'], $name, $email);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Email already registered.', 409);
            }
            error_log('Update profile failed: ' . $e->getMessage());
            Response::error('Could not update profile. Please try again.', 500);
        }

        Response::success([
            'user' => [
                'id' => (int) $user['user_id'],
                'name' => $name,
                'email' => $email,
                'role' => $user['role'],
            ],
            'message' => 'Profile updated successfully.',
        ]);
    }
}