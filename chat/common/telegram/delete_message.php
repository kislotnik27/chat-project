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
$user_id = $data['chat_id'];
$message_id = $data['message_id'];

try {
    // Подключение к базе данных
    $pdo = new PDO('mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8', $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $chat_id = $stmt->fetchColumn();

    if (!$chat_id) {
        throw new Exception('Chat ID not found for the given user_id');
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
    $response = $telegramModule->deleteMessage($chat_id, $message_id);
    if ($response->isOk()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $response->printError()]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
