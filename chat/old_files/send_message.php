<?php
header('Content-Type: application/json');

require '/home/fm451400/vendor/autoload.php'; // Подключение автозагрузчика Composer

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use WebSocket\Client;

$config = require __DIR__ . '/config.php';
$dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
$username = $config['db']['username'];
$password = $config['db']['password'];

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'];
$message = $data['message'];
$message_type = isset($data['message_type']) ? $data['message_type'] : 'text';
$reply_to_message_id = isset($data['reply_to_message_id']) ? $data['reply_to_message_id'] : null;

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Сохранение сообщения в базу данных
    $stmt = $pdo->prepare("INSERT INTO messages (platform, user_id, message, sender, message_type, reply_to_message_id, timestamp) VALUES ('telegram', ?, ?, 'manager', ?, ?, NOW())");
    $stmt->execute([$user_id, $message, $message_type, $reply_to_message_id]);

    // Получаем ID вставленного сообщения
    $message_id_db = $pdo->lastInsertId();

    // Отправка сообщения через Telegram API
    $telegram = new Telegram($config['telegram']['api_key'], $config['telegram']['bot_username']);
    $telegram_data = [
        'chat_id' => $user_id,
        'text' => $message,
    ];

    if ($reply_to_message_id) {
        $telegram_data['reply_to_message_id'] = $reply_to_message_id;
    }

    $response = Request::sendMessage($telegram_data);

    if ($response->isOk()) {
        // Сохранение Telegram message_id
        $telegram_message_id = $response->getResult()->getMessageId();
        $stmt = $pdo->prepare("UPDATE messages SET message_id_tg = ? WHERE id = ?");
        $stmt->execute([$telegram_message_id, $message_id_db]);

        // Отправка сообщения через WebSocket
        sendWebSocketMessage([
            'user_id' => $user_id,
            'sender' => 'manager',
            'message' => $message,
            'message_type' => $message_type,
            'message_id' => $telegram_message_id,
            'reply_to_message_id' => $reply_to_message_id,
        ]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $response->printError()]);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

function sendWebSocketMessage($message) {
    try {
        $client = new \WebSocket\Client("wss://ws.elaliza.com");
        $client->send(json_encode($message));
    } catch (Exception $e) {
        echo "Failed to connect to WebSocket server: " . $e->getMessage();
        file_put_contents(__DIR__ . '/websocket_error.log', "Failed to connect to WebSocket server: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}
?>
