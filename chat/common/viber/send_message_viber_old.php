<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение автозагрузчика Composer
require '/home/fm451400/vendor/autoload.php';

// Подключение файла конфигурации
$config = require '/home/fm451400/elaliza.com/work/config/config.php';

// Подключение модуля Viber
require '/home/fm451400/elaliza.com/work/modules/viber/ViberModule.php';

use Modules\Viber\ViberModule;

$data = $_POST;
$id = $data['user_id'] ?? null;
$message = $data['message'] ?? '';
$message_type = $data['message_type'] ?? 'text';
$reply_to_message_id = $data['reply_to_message_id'] ?? null;

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
        $fileName = $encoded_user_id . '.' . pathinfo($files['name'][$i], PATHINFO_EXTENSION); // Добавляем расширение к имени файла

        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
            $public_url = 'https://work.elaliza.com/media/admin/' . $fileName;
            $media_urls[] = $public_url;
            $message_type = 'photo';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to upload image']);
            exit;
        }
    }
}

// Получение chat_id на основе id
try {
    $pdo = new PDO('mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8', $config['db']['username'], $config['db']['password']);
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

$viberModule = new ViberModule($config);

try {
    if (empty($media_urls)) {
        // Если файлов нет, отправляем текстовое сообщение
        $db_message_id = $viberModule->saveUserAndMessage('viber', $chat_id, null, $message, 'manager', null, $message_type, null, $reply_to_message_id);

        $response = $viberModule->sendMessage($chat_id, $message, $reply_to_message_id);

        if (isset($response['status']) && $response['status'] == 0) {
            $viberModule->sendWebSocketMessage([
                'user_id' => $chat_id,
                'sender' => 'manager',
                'message' => $message,
                'message_type' => $message_type,
                'media_url' => null,
                'message_id' => null,
                'reply_to_message_id' => $reply_to_message_id,
            ]);
            echo json_encode(['status' => 'success']);
        } else {
            $errorMessage = isset($response['status_message']) ? $response['status_message'] : 'Unknown error';
            echo json_encode(['status' => 'error', 'message' => $errorMessage]);
        }
    } else {
        // Если есть файлы, отправляем сообщения с медиа
        foreach ($media_urls as $media_url) {
            $db_message_id = $viberModule->saveUserAndMessage('viber', $chat_id, null, $message, 'manager', $media_url, $message_type, null, $reply_to_message_id);

            $response = $viberModule->sendPictureMessage($chat_id, $media_url, $reply_to_message_id);

            if (isset($response['status']) && $response['status'] == 0) {
                $viberModule->sendWebSocketMessage([
                    'user_id' => $chat_id,
                    'sender' => 'manager',
                    'message' => $message,
                    'message_type' => $message_type,
                    'media_url' => $media_url,
                    'message_id' => null,
                    'reply_to_message_id' => $reply_to_message_id,
                ]);
            } else {
                $errorMessage = isset($response['status_message']) ? $response['status_message'] : 'Unknown error';
                echo json_encode(['status' => 'error', 'message' => $errorMessage]);
                exit;
            }
        }
        echo json_encode(['status' => 'success']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
