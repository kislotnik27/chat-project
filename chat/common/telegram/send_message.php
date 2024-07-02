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

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

file_put_contents($logDir . '/input.log', json_encode($_POST) . PHP_EOL, FILE_APPEND);

// Проверка входных данных
$id = $_POST['user_id'] ?? null;
$message = $_POST['message'] ?? '';
$message_type = $_POST['message_type'] ?? 'text';
$reply_to_message_id = $_POST['reply_to_message_id'] ?? null;

$media_urls = [];

// Проверка наличия файлов для загрузки
if (!empty($_FILES['file'])) {
    $files = $_FILES['file'];

    // Если загружается один файл
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']]
        ];
    }

    $uploadDir = '/home/fm451400/elaliza.com/work/media/admin/';
    
    for ($i = 0; $i < count($files['name']); $i++) {
        $encoded_user_id = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(basename($files['name'][$i])));
        $originalFileName = $encoded_user_id . '.' . pathinfo($files['name'][$i], PATHINFO_EXTENSION); // Оригинальное имя файла
        $convertedFileName = $encoded_user_id . '.mp4'; // Имя файла после конвертации

        $targetFile = $uploadDir . $originalFileName;
        $convertedFile = $uploadDir . $convertedFileName;

        if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
            // Конвертация видео в MP4, если оно не в MP4 формате
            if (strpos($files['type'][$i], 'video') !== false && pathinfo($files['name'][$i], PATHINFO_EXTENSION) !== 'mp4') {
                $convertCommand = "ffmpeg -i $targetFile $convertedFile";
                exec($convertCommand, $output, $return_var);
                if ($return_var === 0) {
                    $public_url = 'https://work.elaliza.com/media/admin/' . $convertedFileName;
                    $media_urls[] = $public_url;
                    $message_type = 'video';
                    unlink($targetFile); // Удаляем оригинальный файл после конвертации
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to convert video']);
                    exit;
                }
            } else {
                $public_url = 'https://work.elaliza.com/media/admin/' . $originalFileName;
                $media_urls[] = $public_url;
                $message_type = strpos($files['type'][$i], 'video') !== false ? 'video' : 'photo';
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to upload file']);
            exit;
        }
    }
}

// Проверка входных данных
if (empty($id) || (empty($message) && empty($media_urls))) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id or message']);
    exit;
}

// Получение chat_id на основе id
try {
    $pdo = new PDO('mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8', $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $chat_id = $stmt->fetchColumn();

    if (!$chat_id) {
        throw new Exception('Chat ID not found for the given ID');
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

$telegramModule = new TelegramModule($config);

try {
    if ($message_type === 'text') {
        // Отправляем текстовое сообщение
        $db_message_id = $telegramModule->saveUserAndMessage('telegram', $chat_id, 'Manager', 'Manager', $message, 'manager', null, $message_type, null, $reply_to_message_id);

        $response = $telegramModule->sendMessage($chat_id, $message, $reply_to_message_id);

        if ($response->isOk()) {
            $telegram_message_id = $response->getResult()->getMessageId();
            $telegramModule->updateMessageWithTelegramId($db_message_id, $telegram_message_id);

            $telegramModule->sendWebSocketMessage([
                'user_id' => $chat_id,
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
    } elseif (($message_type === 'photo' || $message_type === 'video') && !empty($media_urls)) {
        // Отправляем сообщение с медиафайлами
        foreach ($media_urls as $media_url) {
            $db_message_id = $telegramModule->saveUserAndMessage('telegram', $chat_id, 'Manager', 'Manager', '', 'manager', $media_url, $message_type, null, $reply_to_message_id);

            if ($message_type === 'photo') {
                $response = $telegramModule->sendPhoto($chat_id, $media_url, $reply_to_message_id);
            } elseif ($message_type === 'video') {
                $response = $telegramModule->sendVideo($chat_id, $media_url, $reply_to_message_id);
            }

            if ($response->isOk()) {
                $telegram_message_id = $response->getResult()->getMessageId();
                $telegramModule->updateMessageWithTelegramId($db_message_id, $telegram_message_id);

                $telegramModule->sendWebSocketMessage([
                    'user_id' => $chat_id,
                    'sender' => 'manager',
                    'message' => '',
                    'message_type' => $message_type,
                    'media_url' => $media_url,
                    'message_id' => $telegram_message_id,
                    'reply_to_message_id' => $reply_to_message_id,
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => $response->printError()]);
                exit;
            }
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid message type or missing media URL']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
