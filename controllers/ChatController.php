<?php

require_once __DIR__ . '/../models/ChatLog.php';
require_once __DIR__ . '/../utils/Response.php';

class ChatController {
    private $db;
    private $config;

    public function __construct($db) {
        $this->db = $db;
        $this->config = require __DIR__ . '/../config/ai.php';
    }

    // POST /chat/ask  body: { "user_id": 1, "message": "..." }
    public function ask() {
        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        // NOTE: user_id comes from the app because login tokens aren't verified yet.
        // Fine for a demo; replace with the id from a verified token later.
        $userId = (int) ($input['user_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if ($userId <= 0 || $message === '') {
            Response::error('user_id and message are required.', 422);
        }

        $aiResult = $this->callAiService($this->buildAiPayload($message));
        $reply = $this->parseAiReply($aiResult);

        $chatId = null;
        try {
            $chatId = (new ChatLog($this->db))->create($userId, $message, $reply);
        } catch (PDOException $e) {
            // Saving history should never block the answer from reaching the user.
            error_log('Chat log insert failed: ' . $e->getMessage());
        }

        Response::success(
            $this->toChatMessage(
                $chatId ? 'chat-' . $chatId . '-assistant' : 'ask-' . uniqid(),
                $reply,
                date('g:i A'),
                'assistant'
            )
        );
    }

    // GET /chat/logs?user_id=1
    public function getLogs() {
        $userId = (int) ($_GET['user_id'] ?? 0);

        if ($userId <= 0) {
            Response::error('user_id is required.', 422);
        }

        $rows = (new ChatLog($this->db))->getByUser($userId);

        $messages = [];
        foreach (array_reverse($rows) as $row) {
            $timestamp = date('g:i A', strtotime($row['created_at']));
            $messages[] = $this->toChatMessage(
                'chat-' . $row['chat_id'] . '-user',
                $row['question'],
                $timestamp,
                'user'
            );
            $messages[] = $this->toChatMessage(
                'chat-' . $row['chat_id'] . '-assistant',
                $row['response'],
                $timestamp,
                'assistant'
            );
        }

        Response::success([
            'messages' => $messages,
        ]);
    }

    // ---------- Adapt these two methods to your teammate's API ----------

    // What we send to FastAPI.
    private function buildAiPayload($message) {
        return ['message' => $message];
    }

    // Where the answer lives in FastAPI's JSON. Add keys here if theirs differs.
    private function parseAiReply(array $aiResult) {
        foreach (['message', 'response', 'insight', 'answer', 'result'] as $key) {
            if (isset($aiResult[$key]) && is_string($aiResult[$key]) && $aiResult[$key] !== '') {
                return $aiResult[$key];
            }
        }
        error_log('Unexpected AI response shape: ' . json_encode($aiResult));
        Response::error('The AI service returned an unexpected response.', 502);
    }

    // --------------------------------------------------------------------

    private function callAiService(array $payload) {
        $ch = curl_init($this->config['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_TIMEOUT => $this->config['timeout'],
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            error_log('AI service unreachable: ' . $curlError);
            Response::error('The AI service is unreachable right now.', 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('AI service returned HTTP ' . $httpCode . ': ' . $body);
            Response::error('The AI service returned an error.', 502);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('AI service returned non-JSON: ' . $body);
            Response::error('The AI service returned an invalid response.', 502);
        }

        return $decoded;
    }

    private function toChatMessage($id, $message, $time, $role) {
        return [
            'id' => $id,
            'message' => $message,
            'timestamp' => $time,
            'role' => $role,
        ];
    }
}