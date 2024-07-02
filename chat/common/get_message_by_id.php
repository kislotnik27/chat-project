<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение автозагрузчика Composer
require '/home/fm451400/vendor/autoload.php';

// Подключение файла конфигурации
$config = require '/home/fm451400/elaliza.com/work/config/config.php';

// Подключение общего модуля для чатов
require '/home/fm451400/elaliza.com/work/modules/common/ChatModule.php';

use Modules\Common\ChatModule;

$message_id = $_GET['message_id'];

$chatModule = new ChatModule($config);

try {
    $message = $chatModule->getMessageById($message_id);

    if ($message) {
        echo json_encode(['status' => 'success', 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Message not found']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
