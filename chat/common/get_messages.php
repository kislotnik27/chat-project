<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение автозагрузчика Composer
require '/home/fm451400/vendor/autoload.php';

// Подключение файла конфигурации
$config = require '/home/fm451400/elaliza.com/work/config/config.php';

// Подключение общего модуля для обработки чатов
require '/home/fm451400/elaliza.com/work/modules/common/ChatModule.php';

use Modules\Common\ChatModule;

$chatModule = new ChatModule($config);

$user_id = $_GET['user_id']; // id из таблицы users
$platform = $_GET['platform']; // Получаем платформу из URL

try {
    // Сначала получаем chat_id из таблицы users
    $chat_id = $chatModule->getChatIdById($user_id);

    if ($chat_id) {
        $messages = $chatModule->getMessages($chat_id);
        $html = '';
        if ($platform === 'viber') {
            // Оболочка для Viber чата
            $html .= '<div class="viber">';
        } else if ($platform === 'telegram') {
            // Оболочка для Telegram чата
            $html .= '<div class="telegram">';
        } else {
            // Общая оболочка на случай, если платформа неизвестна
            $html .= '<div class="chat_okno">';
        }
        
        $currentDate = null;

        foreach ($messages as $message) {
            $messageDate = date('Y-m-d', strtotime($message['timestamp']));
            if ($messageDate !== $currentDate) {
                $currentDate = $messageDate;
                $html .= '<div class="message-date">' . date('d M Y', strtotime($currentDate)) . '</div>';
            }

            $html .= '<div class="message" id="message-' . $message['message_id'] . '">';
            $html .= '<div class="tam_messages ' . $message['sender'] . '">';

            // Проверка, является ли сообщение ответом
            if ($message['reply_to_message_id']) {
                $replyMessage = $chatModule->getMessageById($message['reply_to_message_id']);
                if ($replyMessage) {
                    $replySenderName = $replyMessage['sender'] === 'manager' ? 'Manager' : $replyMessage['user'];
                    $html .= '<div class="replied-message" data-reply-to="' . $message['reply_to_message_id'] . '"><strong>' . $replySenderName . ':</strong> ' . $replyMessage['message'] . '</div>';
                }
            }

            $senderName = $message['sender'] === 'manager' ? 'Manager' : $message['user'];
            $html .= '<div class="message-text"><strong>' . $senderName . ':</strong>';

            switch ($message['message_type']) {
                case 'text':
                    $html .= ' ' . $message['message'];
                    break;
                case 'photo':
                    $html .= ' <img src="' . $message['media_url'] . '" alt="Photo">';
                    break;
                case 'video':
                    $html .= ' <video controls src="' . $message['media_url'] . '"></video>';
                    break;
                case 'document':
                    $html .= ' <a href="' . $message['media_url'] . '" target="_blank">Document</a>';
                    break;
                case 'audio':
                    $html .= ' <audio controls src="' . $message['media_url'] . '"></audio>';
                    break;
                case 'voice':
                    $html .= ' <audio controls src="' . $message['media_url'] . '"></audio>';
                    break;
                default:
                    $html .= ' Unsupported message type';
            }

            $html .= '</div>';
            if ($platform === 'telegram') {
                $html .= '<button class="reply-button" data-reply-to="' . $message['message_id'] . '">Ответить</button>';
            }
            if ($message['message_type'] === 'text') {
                $html .= '<button class="edit-button" data-message-id="' . $message['message_id'] . '">Редактировать</button>';
            }
            $html .= '<button class="delete-button" data-message-id="' . $message['message_id'] . '">Удалить</button>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div id="last-message"></div>';
        $html .= '</div>';

        echo json_encode(['status' => 'success', 'html' => $html]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Chat ID not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
