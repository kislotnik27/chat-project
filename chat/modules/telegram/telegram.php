<?php
// Подключение автозагрузчика
try {
    require '/home/fm451400/vendor/autoload.php';
    $config = require '/home/fm451400/elaliza.com/work/config/config.php';
    require '/home/fm451400/elaliza.com/work/modules/telegram/TelegramModule.php';
    require '/home/fm451400/elaliza.com/work/modules/order/OrderConfirmationModule.php';

    echo "Autoload loaded successfully.<br>";
} catch (Exception $e) {
    echo "Error loading autoload.php: " . $e->getMessage();
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Error loading autoload.php: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit;
}

use Longman\TelegramBot\Request;
use Modules\Telegram\TelegramModule;
use Modules\OrderConfirmation\OrderConfirmationModule;

try {
    $telegramModule = new TelegramModule($config);
    $orderModule = new OrderConfirmationModule($telegramModule->getPdo());
    echo "Modules instantiated successfully.<br>";
} catch (Exception $e) {
    echo "Error instantiating modules: " . $e->getMessage();
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Error instantiating modules: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit;
}

try {
    // Получение обновлений
    $update = json_decode(file_get_contents('php://input'), true);
    file_put_contents(__DIR__ . '/telegram_debug.log', 'Received update: ' . print_r($update, true) . PHP_EOL, FILE_APPEND);

    if (isset($update['message'])) {
        // Обработка сообщений
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
        $telegramModule->saveUserAndMessage('telegram', $chat_id, $message['from']['username'], $message['from']['first_name'], $text, 'client', $media_url, $message_type, $message_id, $reply_to_message_id);

        // Отправка сообщения через WebSocket
        $telegramModule->sendWebSocketMessage([
            'event' => 'message',
            'user_id' => $chat_id,
            'user' => $user,
            'sender' => 'client',
            'message' => $text,
            'message_type' => $message_type,
            'media_url' => $media_url,
            'message_id' => $message_id,
            'reply_to_message_id' => $reply_to_message_id
        ]);
    } elseif (isset($update['callback_query'])) {
        // Обработка callback-запросов
        file_put_contents(__DIR__ . '/telegram_debug.log', 'Received callback query: ' . print_r($update['callback_query'], true) . PHP_EOL, FILE_APPEND);
        $callback_query = $update['callback_query'];
        $callback_data = $callback_query['data'];
        $user_id = $callback_query['from']['id'];
        $callback_query_id = $callback_query['id'];

        //$telegramModule->sendMessage($user_id, $callback_data); // Вызов функции sendMessage

        // Ответ на callback-запрос
        $answer_response = Request::answerCallbackQuery(['callback_query_id' => $callback_query_id]);
        file_put_contents(__DIR__ . '/telegram_debug.log', 'AnswerCallbackQuery response: ' . print_r($answer_response, true) . PHP_EOL, FILE_APPEND);

        try {
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Order module instantiated successfully.' . PHP_EOL, FILE_APPEND);
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Callback data: ' . $callback_data . ', User ID: ' . $user_id . PHP_EOL, FILE_APPEND);
            $orderModule->handleCallbackQuery($callback_data, $user_id);
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Callback query handled successfully.' . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            $telegramModule->sendDebugMessage($user_id, "Error handling callback query: " . $e->getMessage());
            file_put_contents(__DIR__ . '/telegram_debug.log', 'Error handling callback query: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    } else {
        file_put_contents(__DIR__ . '/telegram_debug.log', 'No valid message or event in update' . PHP_EOL, FILE_APPEND);
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
?>
