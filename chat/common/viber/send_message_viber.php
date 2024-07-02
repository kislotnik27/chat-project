<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '/home/fm451400/vendor/autoload.php';

$config = require '/home/fm451400/elaliza.com/work/config/config.php';
require '/home/fm451400/elaliza.com/work/modules/viber/ViberModule.php';

use Modules\Viber\ViberModule;

$data = $_POST;
$id = $data['user_id'] ?? null;
$message = $data['message'] ?? '';
$message_type = $data['message_type'] ?? 'text';
$reply_to_message_id = $data['reply_to_message_id'] ?? null;

$media_urls = [];
$uploadDir = '/home/fm451400/elaliza.com/work/media/admin/';
$max_file_size = 1024 * 1024; // Максимальный размер файла в байтах (1MB)

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function compressImage($source, $destination, $quality) {
    $info = getimagesize($source);

    if ($info === false) {
        return false;
    }

    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } else {
        return false;
    }

    return imagejpeg($image, $destination, $quality);
}

function compressImageStepwise($source, $destination, $max_file_size) {
    $quality = 90;
    do {
        if (!compressImage($source, $destination, $quality)) {
            return false;
        }
        $quality -= 10;
        clearstatcache();
        $file_size = filesize($destination);
    } while ($file_size > $max_file_size && $quality > 0);

    return $file_size <= $max_file_size;
}

if (!empty($_FILES['file'])) {
    $files = $_FILES['file'];

    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']]
        ];
    }

    for ($i = 0; $i < count($files['name']); $i++) {
        $encoded_user_id = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(basename($files['name'][$i])));
        $fileName = 'compressed_' . $encoded_user_id . '.' . pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $targetFile = $uploadDir . $fileName;

        if (strpos($files['type'][$i], 'image') !== false) {
            if ($files['size'][$i] > $max_file_size) {
                if (!compressImageStepwise($files['tmp_name'][$i], $targetFile, $max_file_size)) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to compress image to acceptable size']);
                    exit;
                }
            } else {
                if (!move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to upload image. Move failed.']);
                    exit;
                }
            }
            $message_type = 'photo';
        } elseif (strpos($files['type'][$i], 'video') !== false) {
            if (!move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to upload video. Move failed.']);
                exit;
            }
            $message_type = 'video';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unsupported file type']);
            exit;
        }

        if (file_exists($targetFile)) {
            $public_url = 'https://work.elaliza.com/media/admin/' . $fileName;
            $media_urls[] = [
                'url' => $public_url,
                'size' => filesize($targetFile)
            ];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to process file: ' . $files['name'][$i]]);
            exit;
        }
    }
}

if (empty($id) || (empty($message) && empty($media_urls))) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id or message']);
    exit;
}

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

$viberModule = new ViberModule($config);

try {
    if ($message_type === 'text') {
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
    } elseif (!empty($media_urls)) {
        foreach ($media_urls as $media) {
            $media_url = $media['url'];
            $media_size = $media['size'];

            $db_message_id = $viberModule->saveUserAndMessage('viber', $chat_id, null, $message, 'manager', $media_url, $message_type, null, $reply_to_message_id);

            if ($message_type === 'photo') {
                $response = $viberModule->sendPictureMessage($chat_id, $media_url, $reply_to_message_id);
            } elseif ($message_type === 'video') {
                $response = $viberModule->sendVideoMessage($chat_id, $media_url, $media_size);
            }

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
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid message type or missing media URL']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
