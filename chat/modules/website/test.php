<?php
class OrderTest {
    public function getOrderInfo($order_id) {
        // Получаем информацию о заказе с API
        $order_data = file_get_contents("https://elaliza.ua/public_api/get_order.php?order_id=$order_id");
        $order = json_decode($order_data, true);

        // Проверяем, есть ли ошибка в ответе API
        if (!isset($order['error'])) {
            

            return $order;
        } else {
            return ['Ошибка' => $order['error']];
        }
    }
}

// Пример использования функции
$orderTest = new OrderTest();
print_r($orderTest->getOrderInfo('74943'));
?>
