<?php
// Подключение автозагрузчика
try {
    require '/home/fm451400/vendor/autoload.php';
    echo "Autoload loaded successfully.<br>";
} catch (Exception $e) {
    echo "Error loading autoload.php: " . $e->getMessage();
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Error loading autoload.php: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit;
}

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use WebSocket\Client;

$config = require __DIR__ . '/config.php';
echo "Config loaded successfully.<br>";
file_put_contents(__DIR__ . '/telegram_debug.log', 'Config loaded' . PHP_EOL, FILE_APPEND);

try {
    $telegram = new Telegram($config['telegram']['api_key'], $config['telegram']['bot_username']);
    echo "Telegram library initialized successfully.<br>";
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Telegram initialized' . PHP_EOL, FILE_APPEND);

    // Обработка входящих обновлений через вебхук
    $telegram->handle();

    // Получение обновлений
    $update = json_decode(file_get_contents('php://input'), true);
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Received update: ' . print_r($update, true) . PHP_EOL, FILE_APPEND);

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $user = $message['from']['first_name'] ?? 'undefined';
        $message_id = $message['message_id'];
        $reply_to_message_id = isset($message['reply_to_message']) ? $message['reply_to_message']['message_id'] : null;

        // Определение типа сообщения и медиа URL, если есть
        $media_url = null;
        $message_type = 'text';

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $file_id = $photo['file_id'];
            $file = Request::getFile(['file_id' => $file_id]);
            if ($file->isOk()) {
                $file_path = $file->getResult()->getFilePath();
                $media_url = 'https://api.telegram.org/file/bot' . $config['telegram']['api_key'] . '/' . $file_path;
                $message_type = 'photo';
            }
        } elseif (isset($message['video'])) {
            $file_id = $message['video']['file_id'];
            $file = Request::getFile(['file_id' => $file_id]);
            if ($file->isOk()) {
                $file_path = $file->getResult()->getFilePath();
                $media_url = 'https://api.telegram.org/file/bot' . $config['telegram']['api_key'] . '/' . $file_path;
                $message_type = 'video';
            }
        } elseif (isset($message['document'])) {
            $file_id = $message['document']['file_id'];
            $file = Request::getFile(['file_id' => $file_id]);
            if ($file->isOk()) {
                $file_path = $file->getResult()->getFilePath();
                $media_url = 'https://api.telegram.org/file/bot' . $config['telegram']['api_key'] . '/' . $file_path;
                $message_type = 'document';
            }
        } elseif (isset($message['audio'])) {
            $file_id = $message['audio']['file_id'];
            $file = Request::getFile(['file_id' => $file_id]);
            if ($file->isOk()) {
                $file_path = $file->getResult()->getFilePath();
                $media_url = 'https://api.telegram.org/file/bot' . $config['telegram']['api_key'] . '/' . $file_path;
                $message_type = 'audio';
            }
        } elseif (isset($message['voice'])) {
            $file_id = $message['voice']['file_id'];
            $file = Request::getFile(['file_id' => $file_id]);
            if ($file->isOk()) {
                $file_path = $file->getResult()->getFilePath();
                $media_url = 'https://api.telegram.org/file/bot' . $config['telegram']['api_key'] . '/' . $file_path;
                $message_type = 'voice';
            }
        }

        // Сохранение пользователя и сообщения в базу данных
        saveUserAndMessage('telegram', $chat_id, $message['from']['username'], $message['from']['first_name'], $text, 'client', $media_url, $message_type, $message_id, $reply_to_message_id);

        // Отправка ответного сообщения
        $response = Request::sendMessage([
            'chat_id' => $chat_id,
            //'text' => 'Ваше сообщение получено и сохранено!',
        ]);

        if ($response->isOk()) {
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Response sent successfully' . PHP_EOL, FILE_APPEND);
        } else {
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Failed to send response: ' . $response->printError() . PHP_EOL, FILE_APPEND);
        }

        // Отправка сообщения через WebSocket
        sendWebSocketMessage([
            'user_id' => $chat_id,
            'user' => $user,
            'sender' => 'client',
            'message' => $text,
            'message_type' => $message_type,
            'media_url' => $media_url,
            'message_id_tg' => $message_id,
            'reply_to_message_id' => $reply_to_message_id
        ]);
    } else if (isset($update['message']['chat']['id'])) {
        $chat_id = $update['message']['chat']['id'];
        $message_id = $update['message']['message_id'];

        // Обновление статуса прочтения сообщений от менеджера
        updateReadStatus($chat_id, $message_id);
    } else {
        file_put_contents(__DIR__ . '/telegram_debug.log', 'No message in update' . PHP_EOL, FILE_APPEND);
    }
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    echo "TelegramException: " . $e->getMessage();
    file_put_contents(__DIR__ . '/telegram_error.log', 'TelegramException: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    file_put_contents(__DIR__ . '/telegram_debug.log', 'TelegramException: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage();
    file_put_contents(__DIR__ . '/telegram_error.log', 'Exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

function saveUserAndMessage($platform, $user_id, $username, $first_name, $text, $sender = 'client', $media_url = null, $message_type = 'text', $message_id = null, $reply_to_message_id = null) {
    $config = require __DIR__ . '/config.php';
    $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
    try {
        $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Сохранение информации о пользователе
        $stmt = $pdo->prepare("INSERT INTO users (chat_id, name, platform) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), platform = VALUES(platform)");
        $stmt->execute([$user_id, $first_name, $platform]);

        // Сохранение сообщения
        $stmt = $pdo->prepare("INSERT INTO messages (platform, user_id, message, sender, message_type, media_url, message_id_tg, reply_to_message_id, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$platform, $user_id, $text, $sender, $message_type, $media_url, $message_id, $reply_to_message_id]);

        file_put_contents(__DIR__ . '/db_success.log', 'Message saved successfully' . PHP_EOL, FILE_APPEND);
    } catch (PDOException $e) {
        file_put_contents(__DIR__ . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
        file_put_contents(__DIR__ . '/telegram_debug.log', 'PDOException: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        echo 'Ошибка соединения: ' . $e->getMessage();
    }
}

function updateReadStatus($chat_id, $message_id) {
    $config = require __DIR__ . '/config.php';
    $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
    try {
        $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Обновление поля read_at для сообщений от менеджера, прочитанных пользователем
        $stmt = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE user_id = ? AND sender = 'manager' AND read_at IS NULL");
        $stmt->execute([$chat_id]);

        file_put_contents(__DIR__ . '/db_success.log', 'Message read status updated successfully' . PHP_EOL, FILE_APPEND);
    } catch (PDOException $e) {
        file_put_contents(__DIR__ . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
        file_put_contents(__DIR__ . '/telegram_debug.log', 'PDOException: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        echo 'Ошибка соединения: ' . $e->getMessage();
    }
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
