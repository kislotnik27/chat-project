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

$chat_id = $_GET['user_id'];
$last_id = isset($_GET['last_id']) ? $_GET['last_id'] : 0;

$telegramModule = new TelegramModule($config);

try {
    $messages = $telegramModule->getMessages($chat_id, $last_id);

    echo json_encode(['status' => 'success', 'messages' => $messages]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
