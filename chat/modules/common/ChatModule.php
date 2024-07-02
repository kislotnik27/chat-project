<?php
namespace Modules\Common;

use PDO;
use PDOException;

class ChatModule {
    private $pdo;

    public function __construct($config) {
        $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
        $this->pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getMessageById($message_id) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT m.message, m.sender, u.name as user 
                FROM messages m 
                JOIN users u ON m.user_id = u.chat_id 
                WHERE m.message_id = ?
            ');
            $stmt->execute([$message_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }
    public function getChats() {
        try {
            $stmt = $this->pdo->prepare('
                SELECT u.id, u.chat_id, u.name, m.message as last_message, m.message_type, u.platform, u.avatar
                    FROM users u
                    LEFT JOIN (
                        SELECT user_id, message, message_type, id as max_id
                        FROM messages
                        WHERE (user_id, id) IN (
                            SELECT user_id, MAX(id)
                            FROM messages
                            GROUP BY user_id
                        )
                    ) m ON u.chat_id = m.user_id
                    ORDER BY m.max_id DESC
            ');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            file_put_contents(__DIR__ . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }


    public function getChatIdById($user_id) {
        try {
            $stmt = $this->pdo->prepare('SELECT chat_id FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            file_put_contents(__DIR__ . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    public function getMessages($chat_id, $last_id = 0) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT m.id, m.message, m.media_url, m.message_type, m.message_id, m.reply_to_message_id, m.timestamp, m.sender, u.name as user 
                FROM messages m 
                JOIN users u ON m.user_id = u.chat_id 
                WHERE m.user_id = ? AND m.id > ? 
                ORDER BY m.timestamp ASC
            ');
            $stmt->execute([$chat_id, $last_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            file_put_contents(__DIR__ . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }
}
?>
