<?php
namespace Modules\OrderConfirmation;

use PDO;
use Longman\TelegramBot\Request;

class OrderConfirmationModule {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'OrderConfirmationModule instantiated.' . PHP_EOL, FILE_APPEND);
    }

    public function handleMessage($user_id, $text) {
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Handling message: ' . $text . ' for User ID: ' . $user_id . PHP_EOL, FILE_APPEND);
        $order_number = $this->extractOrderNumber($text);
        if ($order_number) {
            file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Extracted order number: ' . $order_number . PHP_EOL, FILE_APPEND);
            if ($this->isOrderValid($user_id, $order_number)) {
                file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Order is valid.' . PHP_EOL, FILE_APPEND);
                $order = $this->getOrder($user_id, $order_number);
                switch ($order['funnel_stage']) {
                    case 'order_confirmation':
                        $this->handleOrderConfirmation($user_id, $order_number, $text);
                        break;
                    case 'payment_method':
                        $this->handlePaymentMethod($user_id, $order_number, $text);
                        break;
                }
            } else {
                $this->sendInvalidOrderMessage($user_id);
                $this->updateUserStatus($user_id, 'manual');
            }
        } else {
            if ($text == 'Так' || $text == 'Ні') {
                $this->handleCallbackQuery($text, $user_id);
            } elseif ($text == 'Варіант 1' || $text == 'Варіант 2') {
                $this->handlePaymentMethod($user_id, null, $text);
                $order_number = $this->getOrderNumberByUser($user_id);
                if ($order_number) {
                    $this->updateFunnelStage($user_id, $order_number, 'waiting_payment');
                }
            } elseif (strtolower($text) == 'товар') {
                // Добавим отправку информации о товарах
                $order_number = $this->getOrderNumberByUser($user_id);
                if ($order_number) {

                    $this->sendProductDetails($user_id, $order_number);
                    $this->sendMessage($user_id, 'номер заказа.');
                } else {
                    $this->sendMessage($user_id, 'Не удалось найти номер заказа.');
                }
            }
        }
    }

    public function handleCallbackQuery($callback_data, $user_id) {
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Received callback query: ' . $callback_data . ' for User ID: ' . $user_id . PHP_EOL, FILE_APPEND);

        if ($callback_data == 'Так') {
            $order_number = $this->getOrderNumberByUser($user_id);
            if ($order_number) {
                // Отправляем информацию о товарах после подтверждения заказа
                //$this->sendProductDetails($user_id, $order_number); 
                $this->updateFunnelStage($user_id, $order_number, 'choice_payment');
                $this->sendPaymentMethodMessage($user_id);
            }
        } elseif ($callback_data == 'Ні') {
            $this->updateUserStatus($user_id, 'manual');
        }
    }

    private function getOrderNumberByUser($user_id) {
        $stmt = $this->pdo->prepare("SELECT order_number FROM orders WHERE user_id = ? AND status = 'order_confirmation'");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    }

    private function extractOrderNumber($text) {
        if (preg_match('/\/start order_(\d+)/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function isOrderValid($user_id, $order_number) {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$order_number]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Order validation. User ID: ' . $user_id . ', Order Number: ' . $order_number . ', Valid: ' . ($order && $order['user_id'] == $user_id ? 'true' : 'false') . PHP_EOL, FILE_APPEND);
        return $order && $order['user_id'] == $user_id;
    }

    private function getOrder($user_id, $order_number) {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE user_id = ? AND order_number = ?");
        $stmt->execute([$user_id, $order_number]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Fetched order: ' . print_r($order, true) . PHP_EOL, FILE_APPEND);
        return $order;
    }

    private function handleOrderConfirmation($user_id, $order_number, $text) {
        $this->sendOrderConfirmationMessage($user_id, $order_number);
        $this->sendProductDetails($user_id, $order_number);
        file_put_contents(__DIR__ . '/order_confirmation_debug.log', 'Sent order confirmation message to User ID: ' . $user_id . ' for Order Number: ' . $order_number . PHP_EOL, FILE_APPEND);
    }

    private function handlePaymentMethod($user_id, $order_number, $text) {
        if ($text == 'Варіант 1' || $text == 'Варіант 2') {
            $this->sendCardNumberMessage($user_id);
        } else {
            $this->sendPaymentMethodMessage($user_id);
        }
    }

    private function sendProductDetails($user_id, $order_number) {
        // Получаем информацию о заказе
        $orderInfo = $this->getOrderInfo($order_number);
        foreach ($orderInfo['purchases'] as $product) {
            $productName = $product['product_name'];
            $productPrice = $product['price'] . ' грн';
            $productImage = $product['product_image_url'];
            $productImage_url = "https://elaliza.ua/files/fromcms/$productImage";

            // Отправляем изображение товара, название и цену в чат
            $data = [
                'chat_id' => $user_id,
                'photo' => $productImage_url,
                'caption' => "Товар: {$productName}\nЦена: {$productPrice}"
            ];
            Request::sendPhoto($data);
        }
    }

    private function updateFunnelStage($user_id, $order_number, $stage) {
        $stmt = $this->pdo->prepare("UPDATE orders SET funnel_stage = ? WHERE user_id = ? AND order_number = ?");
        $stmt->execute([$stage, $user_id, $order_number]);
    }

    private function sendOrderConfirmationMessage($user_id, $order_number) {
        $data = [
            'chat_id' => $user_id,
            'text' => "Вітаю. Ви оформили замовлення №-{$order_number} на сайті ELALIZA.)  Вам зручно підтвердити ?)",
            'reply_markup' => json_encode([
                'keyboard' => [
                    ['Так', 'Ні']
                ],
                'one_time_keyboard' => true,
                'resize_keyboard' => true
            ])
        ];
        Request::sendMessage($data);
    }

    private function sendPaymentMethodMessage($user_id) {
        $data = [
            'chat_id' => $user_id,
            'text' => "Оберіть, будь ласка, спосіб оплати:
            1. Повна оплата на карту
            2. Накладний платіж з предоплатою 50 грн",
            'reply_markup' => json_encode([
                'keyboard' => [
                    ['Варіант 1', 'Варіант 2']
                ],
                'one_time_keyboard' => true,
                'resize_keyboard' => true
            ])
        ];
        Request::sendMessage($data);
    }

    private function sendCardNumberMessage($user_id) {
        $data = [
            'chat_id' => $user_id,
            'text' => "Добре💛 Надсилаємо дані для сплати: 

5169 3305 2250 3611

ПриватБанк 
Курдогло Олександр 


Як оплатите, надішліть, будь ласка, скрін підтвердження. Дякуємо за замовлення💙💛"
        ];
        Request::sendMessage($data);
    }

    private function sendInvalidOrderMessage($user_id) {
        $data = [
            'chat_id' => $user_id,
            'text' => "Этот заказ принадлежит другому пользователю. Мы подключаем менеджера, чтобы помочь вам."
        ];
        Request::sendMessage($data);
    }

    private function sendMessageToManager($user_id, $order_number) {
        $manager_chat_id = 'ID менеджера'; // замените на ID чата менеджера
        $data = [
            'chat_id' => $manager_chat_id,
            'text' => "Пользователь с ID {$user_id} пытается подтвердить заказ номер {$order_number}, принадлежащий другому пользователю."
        ];
        Request::sendMessage($data);
    }

    private function updateUserStatus($user_id, $status) {
        $stmt = $this->pdo->prepare("UPDATE users SET status = ? WHERE chat_id = ?");
        $stmt->execute([$status, $user_id]);
    }

    public function sendMessage($chat_id, $message) {
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
        ];

        return Request::sendMessage($data);
    }

    public function getOrderInfo($order_number) {
        // Получаем информацию о заказе с API
        $order_data = file_get_contents("https://elaliza.ua/public_api/get_order.php?order_id=$order_number");
        $order = json_decode($order_data, true);

        // Проверяем, есть ли ошибка в ответе API
        if (!isset($order['error'])) {
            return $order;
        } else {
            return ['error' => $order['error']];
        }
    }
}
?>
