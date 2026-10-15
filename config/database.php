<?php

class Database {
    private $host = 'localhost';
    private $db_name = 'capstone_db';
    private $username = 'root';
    private $password = '';
    public $conn;

    public function connect() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name}",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
            exit;
        }
        return $this->conn;
    }
}