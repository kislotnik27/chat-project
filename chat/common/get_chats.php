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

try {
    $chats = $chatModule->getChats();
    $html = '';
//print_r($chats);
    foreach ($chats as $chat) {
        $html .= '<div class="chat-item" data-id="' . $chat['id'] . '" data-platform="' . $chat['platform'] . '">';
        $html .= '<img src="avatars/' . $chat['avatar'] . '" alt="Avatar">';
        $html .= '<div class="chat-info">';
        $html .= '<div class="name">' . $chat['name'] . '</div>';
        if ($chat['message_type']=='text') {
            $html .= '<div class="last-message">' . $chat['last_message'] . '</div>';
        }elseif ($chat['message_type']=='photo') {
            $html .= '<div class="last-message">Зображення</div>';
        }else{
            $html .= '<div class="last-message">' . $chat['last_message'] . '</div>';
        }
        if ($chat['platform']=='telegram') {
            $html .= '<div class="platform"><img src="/public/images/tg.png" /></div>';
        }elseif ($chat['platform']=='viber')  {
            $html .= '<div class="platform"><img src="/public/images/vb.png" /></div>';
        }else{
            $html .= '<div class="platform">' . $chat['platform'] . '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
    }

    echo json_encode(['status' => 'success', 'html' => $html]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
