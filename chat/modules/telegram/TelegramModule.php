<?php
namespace Modules\Telegram;

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use WebSocket\Client;
use PDO;
use PDOException;

class TelegramModule {
    private $telegram;
    private $pdo;
    private $client;
    private $logDir;

    public function __construct($config) {
        $this->telegram = new Telegram($config['telegram']['api_key'], $config['telegram']['bot_username']);
        $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
        $this->pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->client = new Client($config['websocket']['url']);

        // Создаем папку для логов, если она не существует
        $this->logDir = __DIR__ . '/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function handleRequest() {
        try {
            $this->telegram->handle();
            $this->handleCallbackQuery();
        } catch (\Exception $e) {
            $this->sendDebugMessage($e->getMessage());
        }
    }
    
    public function sendMessage($chat_id, $message, $reply_to_message_id = null) {
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
        ];

        if ($reply_to_message_id) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }

        return Request::sendMessage($data);
    }

    public function sendWebSocketMessage($message) {
        $this->client->send(json_encode($message));
    }

    public function saveUserAndMessage($platform, $user_id, $username, $first_name, $text, $sender = 'client', $media_url = null, $message_type = 'text', $message_id = null, $reply_to_message_id = null) {
        try {
            // Проверка, существует ли пользователь в базе данных
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $status = $user ? $user['status'] : 'manual';
            if (strpos($text, '/start order_') === 0) {
                $status = 'order_confirmation';
                $order_number = str_replace('/start order_', '', $text);
                $this->createOrder($user_id, $order_number);
            } elseif (strpos($text, '/start manager_') === 0) {
                $status = 'manual';
                $this->sendMessage($user_id, "Чем можем помочь?");
            }

            if ($user) {
                // Обновление статуса пользователя только если он новый или пришел с новым параметром
                if ($status !== $user['status']) {
                    $stmt = $this->pdo->prepare("UPDATE users SET status = ?, source = ? WHERE chat_id = ?");
                    $stmt->execute([$status, $text, $user_id]);
                }
            } else {
                // Сохранение информации о пользователе, если он не существует
                $stmt = $this->pdo->prepare("INSERT INTO users (chat_id, name, platform, status, source) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $first_name, $platform, $status, $text]);
            }

            // Сохранение сообщения
            $stmt = $this->pdo->prepare("INSERT INTO messages (platform, user_id, message, sender, message_type, media_url, message_id, reply_to_message_id, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$platform, $user_id, $text, $sender, $message_type, $media_url, $message_id, $reply_to_message_id]);

            // Загрузка аватарки пользователя
            $this->checkAndSaveUserAvatar($user_id, $username);

            // Подключение соответствующего файла логики в зависимости от статуса
            switch ($status) {
                case 'order_confirmation':
                    require_once __DIR__ . '/../order/OrderConfirmationModule.php';
                    $orderModule = new \Modules\OrderConfirmation\OrderConfirmationModule($this->pdo);
                    $orderModule->handleMessage($user_id, $text);
                    break;

                case 'manual':
                default:
                    // Логика ручной обработки
                    // $this->handleManual($user_id, $text);
                    break;
            }

            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->sendDebugMessage($user_id, $e->getMessage());
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        } catch (\Exception $e) {
            $this->sendDebugMessage($user_id, $e->getMessage());
            return null;
        }
    }

    private function handleCallbackQuery() {
        $update = json_decode(file_get_contents('php://input'), true);

        if (isset($update['callback_query'])) {
            $callback_query = $update['callback_query'];
            $callback_data = $callback_query['data'];
            $user_id = $callback_query['from']['id'];

            try {
                file_put_contents(__DIR__ . '/telegram_debug.log', 'Received callback query: ' . print_r($callback_query, true) . PHP_EOL, FILE_APPEND);

                require_once __DIR__ . '/../order/OrderConfirmationModule.php';
                $orderModule = new \Modules\OrderConfirmation\OrderConfirmationModule($this->pdo);
                $orderModule->handleCallbackQuery($callback_data, $user_id);
            } catch (\Exception $e) {
                $this->sendDebugMessage($user_id, $e->getMessage());
            }
        }
    }

    public function updateMessageWithTelegramId($db_message_id, $telegram_message_id) {
        try {
            $stmt = $this->pdo->prepare("UPDATE messages SET message_id = ? WHERE id = ?");
            $stmt->execute([$telegram_message_id, $db_message_id]);
        } catch (PDOException $e) {
            $this->sendDebugMessage($db_message_id, $e->getMessage());
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }

    public function createOrder($user_id, $order_number) {
        try {
            // Проверяем наличие заказа с указанным номером
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND order_number = ?");
            $stmt->execute([$user_id, $order_number]);
            $orderExists = $stmt->fetchColumn();

            if ($orderExists == 0) {
                // Заказ не существует, добавляем его в базу данных
                $stmt = $this->pdo->prepare("INSERT INTO orders (user_id, order_number, status) VALUES (?, ?, 'order_confirmation')");
                $stmt->execute([$user_id, $order_number]);
                return $this->pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }


    public function getMessageById($message_id) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT m.message, m.sender, u.name as user 
                FROM messages m 
                JOIN users u ON m.user_id = u.chat_id 
                WHERE m.message_id = ?
            ');
            $stmt->execute([$message_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->sendDebugMessage($message_id, $e->getMessage());
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    public function getMessages($chat_id, $last_id = 0) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT m.id, m.message, m.media_url, m.message_type, m.message_id, m.reply_to_message_id, m.timestamp, m.sender, u.name as user 
                FROM messages m 
                JOIN users u ON m.user_id = u.chat_id 
                WHERE m.user_id = ? AND m.id > ? 
                ORDER BY m.timestamp ASC
            ');
            $stmt->execute([$chat_id, $last_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->sendDebugMessage($chat_id, $e->getMessage());
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    public function getChats() {
        try {
            $stmt = $this->pdo->prepare('
                SELECT u.chat_id, u.name, m.message as last_message, u.platform 
                FROM users u 
                LEFT JOIN messages m ON u.chat_id = m.user_id 
                WHERE m.timestamp = (SELECT MAX(timestamp) FROM messages WHERE user_id = u.chat_id)
            ');
            $stmt->execute();
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Добавление пути к аватарке
            foreach ($chats as &$chat) {
                $avatar_path = '/home/fm451400/elaliza.com/work/avatars/' . $chat['chat_id'] . '.jpg';
                if (file_exists($avatar_path)) {
                    $chat['avatar'] = $chat['chat_id'] . '.jpg';
                } else {
                    $chat['avatar'] = 'default_avatar.jpg'; // Укажите путь к изображению по умолчанию, если аватарка не найдена
                }
            }
            
            return $chats;
        } catch (PDOException $e) {
            $this->sendDebugMessage('debug_chat_id', $e->getMessage());
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    public function checkAndSaveUserAvatar($user_id, $username) {
        $avatarPath = '/home/fm451400/elaliza.com/work/avatars/' . $user_id . '.jpg';

        if (!file_exists($avatarPath)) {
            try {
                $profile_photos = Request::getUserProfilePhotos(['user_id' => $user_id]);
                if ($profile_photos->isOk() && count($profile_photos->getResult()->getPhotos()) > 0) {
                    $photo = $profile_photos->getResult()->getPhotos()[0][0];
                    $file_id = $photo->getFileId();
                    $file = Request::getFile(['file_id' => $file_id]);
                    if ($file->isOk()) {
                        $file_path = $file->getResult()->getFilePath();
                        $avatar_url = 'https://api.telegram.org/file/bot' . $this->telegram->getApiKey() . '/' . $file_path;
                        $avatarContent = file_get_contents($avatar_url);
                        if ($avatarContent === false) {
                            file_put_contents($this->logDir . '/avatar_error.log', "Failed to download avatar for user $user_id from $avatar_url" . PHP_EOL, FILE_APPEND);
                            return;
                        }
                        $result = file_put_contents($avatarPath, $avatarContent);
                        if ($result === false) {
                            file_put_contents($this->logDir . '/avatar_error.log', "Failed to save avatar for user $user_id to $avatarPath" . PHP_EOL, FILE_APPEND);
                        } else {
                            file_put_contents($this->logDir . '/avatar_success.log', "Successfully saved avatar for user $user_id to $avatarPath" . PHP_EOL, FILE_APPEND);
                        }

                        // Обновление аватара в базе данных
                        $stmt = $this->pdo->prepare("UPDATE users SET avatar = ? WHERE chat_id = ?");
                        $stmt->execute([$user_id . '.jpg', $user_id]);
                    }
                }
            } catch (Exception $e) {
                $this->sendDebugMessage($user_id, $e->getMessage());
                file_put_contents($this->logDir . '/avatar_error.log', "Exception while saving avatar for user $user_id: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        } else {
            file_put_contents($this->logDir . '/avatar_skip.log', "Avatar already exists for user $user_id." . PHP_EOL, FILE_APPEND);
        }
    }

    public function sendPhoto($chat_id, $photo, $reply_to_message_id = null) {
        $data = [
            'chat_id' => $chat_id,
            'photo' => $photo,
        ];

        if ($reply_to_message_id) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }

        // Logging data being sent to Telegram for debugging
        file_put_contents($this->logDir . '/send_photo.log', json_encode($data) . PHP_EOL, FILE_APPEND);

        // Attempt to send the photo
        $response = Request::sendPhoto($data);

        // Logging the response from Telegram for debugging
        file_put_contents($this->logDir . '/send_photo_response.log', json_encode($response) . PHP_EOL, FILE_APPEND);

        return $response;
    }

    public function sendVideo($chat_id, $video, $reply_to_message_id = null) {
        return Request::sendVideo([
            'chat_id' => $chat_id,
            'video'   => $video,
            'reply_to_message_id' => $reply_to_message_id
        ]);
    }

    public function deleteMessage($chat_id, $message_id) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];

        return Request::deleteMessage($data);
    }

    public function editMessageText($chat_id, $message_id, $new_text) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $new_text
        ];

        return Request::editMessageText($data);
    }

    private function handleManual($user_id, $text) {
        $data = [
            'chat_id' => $user_id,
            'text' => "Чем можем помочь?"
        ];
        Request::sendMessage($data);
    }

    private function sendDebugMessage($chat_id, $message) {
        $data = [
            'chat_id' => $chat_id,
            'text' => "Debug: " . $message
        ];
        Request::sendMessage($data);
    }


}
?>
