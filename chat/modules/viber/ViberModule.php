<?php
namespace Modules\Viber;

use Viber\Bot;
use Viber\Api\Message\Text;
use Viber\Api\Message\Picture;
use Viber\Api\Message\Video;
use PDO;
use PDOException;

class ViberModule {
    private $bot;
    private $pdo;

    public function __construct($config) {
        if (!isset($config['viber']['token'])) {
            throw new \RuntimeException('Viber API token is not specified in config');
        }
        
        $this->bot = new Bot(['token' => $config['viber']['token']]);

        $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
        $this->pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Создаем папку для логов, если она не существует
        $this->logDir = __DIR__ . '/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    public function setWebhook($url) {
        $this->bot->getClient()->setWebhook($url);
    }

    public function handleRequest() {
        $this->bot->onText('|.*|s', function ($event) {
            // Логирование объекта события
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Event details: " . print_r($event, true) . "\n", FILE_APPEND);
            
            $message = $event->getMessage();
            $sender = $event->getSender();
            $text = $message->getText();
            $chat_id = $sender->getId();
            $user_name = $sender->getName();
            $message_id = $event->getMessageToken();

            // Логирование полученного сообщения
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Received text message from {$user_name} (chat_id: {$chat_id}): {$text}\n", FILE_APPEND);

            // Проверка и сохранение аватарки
            $avatar = $sender->getAvatar();
            $avatar_filename = null;
            if ($avatar) {
                $avatar_filename = $this->saveAvatar($chat_id, $avatar);
                file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Saved avatar for user {$user_name} (chat_id: {$chat_id}): {$avatar_filename}\n", FILE_APPEND);
            }

            $this->saveUserAndMessage('viber', $chat_id, $user_name, $text, 'client', null, 'text', $message_id, null, $avatar_filename);
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - User and message saved\n", FILE_APPEND);

            // Отправка сообщения через WebSocket
            $this->sendWebSocketMessage([
                'event' => 'message',
                'user_id' => $chat_id,
                'user' => $user_name,
                'sender' => 'client',
                'message' => $text,
                'message_type' => 'text',
                'media_url' => null,
                'message_id_tg' => $message_id,
                'reply_to_message_id' => null
            ]);
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Sent WebSocket message\n", FILE_APPEND);

            return (new Text())->setText('Message received');
        });

        $this->bot->onPicture(function ($event) {
            $this->saveMediaAndSendMessage($event);
        });

        $this->bot->run();
    }

    private function saveMediaAndSendMessage($event) {
        $message = $event->getMessage();
        $sender = $event->getSender();
        $chat_id = $sender->getId();
        $user_name = $sender->getName();
        $message_id = $event->getMessageToken();
        $media_url = $message->getMedia();

        // Сохранение медиафайла
        $local_media_url = $this->saveMedia($media_url, $chat_id, $message_id); // Передаем $message_id в функцию
        if ($local_media_url) {
            $media_url = $local_media_url;
        }

        // Логирование полученного медиа-сообщения
        file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Received picture message from {$user_name} (chat_id: {$chat_id}): {$media_url}\n", FILE_APPEND);

        // Проверка и сохранение аватарки
        $avatar = $sender->getAvatar();
        $avatar_filename = null;
        if ($avatar) {
            $avatar_filename = $this->saveAvatar($chat_id, $avatar);
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Saved avatar for user {$user_name} (chat_id: {$chat_id}): {$avatar_filename}\n", FILE_APPEND);
        }

        $this->saveUserAndMessage('viber', $chat_id, $user_name, $media_url, 'client', $media_url, 'photo', $message_id, null, $avatar_filename);
        file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - User and message saved\n", FILE_APPEND);

        // Отправка сообщения через WebSocket
        $this->sendWebSocketMessage([
            'event' => 'message',
            'user_id' => $chat_id,
            'user' => $user_name,
            'sender' => 'client',
            'message' => $media_url,
            'message_type' => 'picture',
            'media_url' => $media_url,
            'message_id_tg' => $message_id,
            'reply_to_message_id' => null
        ]);
        file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Sent WebSocket message\n", FILE_APPEND);

        return (new Picture())->setMedia($media_url);
    }



    private function saveAvatar($chat_id, $avatarUrl) {
        $encoded_user_id = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($chat_id));
        $avatarPath = '/home/fm451400/elaliza.com/work/avatars/' . $encoded_user_id . '.jpg';
        
        if (!file_exists($avatarPath)) {
            try {
                $avatarData = file_get_contents($avatarUrl);
                if ($avatarData !== false) {
                    file_put_contents($avatarPath, $avatarData);
                    // Обновление поля avatar в базе данных
                    $stmt = $this->pdo->prepare("UPDATE users SET avatar = ? WHERE chat_id = ?");
                    $stmt->execute([$encoded_user_id . '.jpg', $chat_id]);
                    return $encoded_user_id . '.jpg';
                } else {
                    file_put_contents(__DIR__ . '/avatar_error.log', "Failed to fetch avatar for user $chat_id from $avatarUrl" . PHP_EOL, FILE_APPEND);
                }
            } catch (Exception $e) {
                file_put_contents(__DIR__ . '/avatar_error.log', "Error saving avatar for user $chat_id: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        }
        return null;
    }

    public function saveUserAndMessage($platform, $user_id, $name, $message, $sender = 'client', $media_url = null, $message_type = null, $message_id = null, $reply_to_message_id = null, $avatar_filename = null) {
        try {

            if (!empty($user_id)) {
                // Сохранение информации о пользователе
                if ($avatar_filename) {
                    $stmt = $this->pdo->prepare("INSERT INTO users (chat_id, name, platform, avatar) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE platform = VALUES(platform), avatar = VALUES(avatar)");
                    $stmt->execute([$user_id, $name, $platform, $avatar_filename]);
                } else {
                    $stmt = $this->pdo->prepare("INSERT INTO users (chat_id, name, platform) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE platform = VALUES(platform)");
                    $stmt->execute([$user_id, $name, $platform]);
                }
            }

            // Сохранение сообщения
            $stmt = $this->pdo->prepare("INSERT INTO messages (platform, user_id, message, sender, message_type, media_url, message_id_tg, reply_to_message_id, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$platform, $user_id, $message, $sender, $message_type, $media_url, $message_id, $reply_to_message_id]);

            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Successfully saved user and message\n", FILE_APPEND);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            file_put_contents($this->logDir . '/db_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
    }



    public function sendWebSocketMessage($message) {
        // Реализация отправки сообщения через WebSocket
        try {
            $client = new \WebSocket\Client("wss://ws.elaliza.com");
            $client->send(json_encode($message));
            file_put_contents($this->logDir . '/viber_debug.log', date('Y-m-d H:i:s') . " - Sent WebSocket message: " . json_encode($message) . "\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($this->logDir . '/websocket_error.log', "Failed to connect to WebSocket server: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }

    public function sendMessage($chat_id, $message, $reply_to_message_id = null) {
        $textMessage = new Text();
        $textMessage->setReceiver($chat_id)
                    ->setText($message);

        $response = $this->bot->getClient()->sendMessage($textMessage);

        $responseData = $response->getData();

        if (isset($responseData['status']) && $responseData['status'] != 0) {
            throw new \RuntimeException('Failed to send message: ' . $responseData['status_message']);
        }

        return $responseData;
    }

    private function saveMedia($url, $chat_id, $message_id) {
        // Закодированный ID пользователя для уникальности имен файлов
        $encoded_user_id = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($chat_id));
        
        // Директория для сохранения медиафайлов
        $media_dir = '/home/fm451400/elaliza.com/work/media/user/viber';
        
        // Создание директории, если она не существует
        if (!is_dir($media_dir)) {
            mkdir($media_dir, 0777, true);
        }
        
        // Извлечение расширения файла из URL
        $url_parts = parse_url($url);
        $path_parts = pathinfo($url_parts['path']);
        $extension = $path_parts['extension']; // Получаем расширение файла

        // Имя файла на основе идентификатора сообщения Viber
        $filename = $message_id . '.' . $extension;

        // Полное имя файла с путем на сервере
        $media_filename = $media_dir . '/' . $filename;
        
        // Проверка на существование файла
        if (!file_exists($media_filename)) {
            try {
                // Загрузка данных файла
                $media_data = file_get_contents($url);
                if ($media_data !== false) {
                    // Сохранение файла на сервер
                    file_put_contents($media_filename, $media_data);
                    // Лог успешного сохранения файла
                    file_put_contents($this->logDir . '/media_success.log', "Media saved successfully from $url to $media_filename\n", FILE_APPEND);
                    // Возврат URL для использования в системе
                    return '/media/user/viber/' . $filename;
                } else {
                    // Логирование ошибки, если не удалось загрузить файл
                    file_put_contents($this->logDir . '/media_error.log', "Failed to fetch media from $url for user $chat_id" . PHP_EOL, FILE_APPEND);
                }
            } catch (Exception $e) {
                // Логирование исключений, если произошла ошибка
                file_put_contents($this->logDir . '/media_error.log', "Error saving media from $url for user $chat_id: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        } else {
            // Логирование, если файл уже существует
            file_put_contents($this->logDir . '/media_exists.log', "Media file already exists at $media_filename\n", FILE_APPEND);
        }
        return null;
    }


    public function sendPictureMessage($chat_id, $media_url, $reply_to_message_id = null, $thumbnail_url = null) {
        $pictureMessage = new Picture();
        $pictureMessage->setReceiver($chat_id)
                       ->setMedia($media_url)
                       ->setText('') // можно оставить пустым
                       ->setThumbnail($thumbnail_url);

        $response = $this->bot->getClient()->sendMessage($pictureMessage);

        $responseData = $response->getData();

        if (isset($responseData['status']) && $responseData['status'] != 0) {
            throw new \RuntimeException('Failed to send picture message: ' . $responseData['status_message']);
        }

        return $responseData;
    }

     public function sendVideoMessage($chat_id, $media_url, $media_size) {
        $videoMessage = new Video();
        $videoMessage->setReceiver($chat_id)
            ->setMedia($media_url)
            ->setSize($media_size); // Устанавливаем размер видео

        
        $response = $this->bot->getClient()->sendMessage($videoMessage);

        $responseData = $response->getData();

        if (isset($responseData['status']) && $responseData['status'] != 0) {
            throw new \RuntimeException('Failed to send video message: ' . $responseData['status_message']);
        }

        return $responseData;
    }

}
?>
