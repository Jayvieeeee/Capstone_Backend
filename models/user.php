<?php

class User {
    private $conn;
    private $table = 'users';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function findByEmail($email) {
        $query = "SELECT * FROM {$this->table} WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($name, $email, $hashedPassword, $role = 'user') {
        $query = "INSERT INTO {$this->table} (name, email, password, role)
                  VALUES (:name, :email, :password, :role)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        return $this->conn->lastInsertId();
    }

    public function updatePassword($userId, $hashedPassword) {
        $query = "UPDATE {$this->table} SET password = :password WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':password', $hashedPassword);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateProfile($userId, $name, $email) {
        $query = "UPDATE {$this->table} SET name = :name, email = :email WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
        $stmt->execute();
    }
}