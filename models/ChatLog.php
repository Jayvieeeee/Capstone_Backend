<?php

class ChatLog {
    private $conn;
    private $table = 'ai_chat_logs';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create($userId, $question, $response) {
        $query = "INSERT INTO {$this->table} (user_id, question, response)
                  VALUES (:user_id, :question, :response)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':question', $question);
        $stmt->bindValue(':response', $response);
        $stmt->execute();
        return (int) $this->conn->lastInsertId();
    }

    public function getByUser($userId, $limit = 50) {
        $query = "SELECT chat_id, question, response, created_at
                  FROM {$this->table}
                  WHERE user_id = :user_id
                  ORDER BY created_at DESC, chat_id DESC
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}