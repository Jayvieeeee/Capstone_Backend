<?php

class PasswordReset {
    private $conn;
    private $table = 'password_resets';

    public function __construct($db) {
        $this->conn = $db;
    }

    // One active reset per user: creating a new one replaces any old one.
    public function create($userId, $codeHash, $ttlMinutes) {
        $this->deleteByUserId($userId);

        $query = "INSERT INTO {$this->table} (user_id, code_hash, expires_at)
                  VALUES (:user_id, :code_hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->bindValue(':code_hash', $codeHash);
        $stmt->bindValue(':ttl', (int) $ttlMinutes, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function findActiveByUserId($userId) {
        $query = "SELECT * FROM {$this->table}
                  WHERE user_id = :user_id AND expires_at > NOW()
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function requestedRecently($userId, $seconds) {
        $query = "SELECT reset_id FROM {$this->table}
                  WHERE user_id = :user_id
                    AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->bindValue(':seconds', (int) $seconds, PDO::PARAM_INT);
        $stmt->execute();
        return (bool) $stmt->fetch();
    }

    public function incrementAttempts($resetId) {
        $query = "UPDATE {$this->table} SET attempts = attempts + 1 WHERE reset_id = :reset_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':reset_id', (int) $resetId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteByUserId($userId) {
        $query = "DELETE FROM {$this->table} WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->execute();
    }
}