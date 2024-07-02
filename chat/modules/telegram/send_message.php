<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение автозагрузчика Composer
require '/home/fm451400/vendor/autoload.php';

// Подключение файла конфигурации
$config = require '/home/fm451400/elaliza.com/work/config/config.php';

// Подключение модуля Telegram
require '/home/fm451400/elaliza.com/work/modules/telegram/TelegramModule.php';

use Modules\Telegram\TelegramModule;

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'];
$message = $data['message'];
$message_type = isset($data['message_type']) ? $data['message_type'] : 'text';
$reply_to_message_id = isset($data['reply_to_message_id']) ? $data['reply_to_message_id'] : null;

$telegramModule = new TelegramModule($config);

try {
    // Сохранение сообщения в базу данных
    $db_message_id = $telegramModule->saveUserAndMessage('telegram', $user_id, 'Manager', 'Manager', $message, 'manager', null, $message_type, null, $reply_to_message_id);

    // Отправка сообщения через Telegram API
    $response = $telegramModule->sendMessage($user_id, $message, $reply_to_message_id);

    if ($response->isOk()) {
        // Сохранение Telegram message_id
        $telegram_message_id = $response->getResult()->getMessageId();
        $telegramModule->updateMessageWithTelegramId($db_message_id, $telegram_message_id);

        // Отправка сообщения через WebSocket
        $telegramModule->sendWebSocketMessage([
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
?>
