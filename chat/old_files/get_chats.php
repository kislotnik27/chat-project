<?php
// get_chats.php
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
$username = $config['db']['username'];
$password = $config['db']['password'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query('
        SELECT u.chat_id, u.name, u.platform, u.avatar, MAX(m.timestamp) AS last_message_time, SUBSTRING(MAX(CONCAT(m.timestamp, m.message)), 20) AS last_message
        FROM users u
        LEFT JOIN messages m ON u.chat_id = m.user_id
        GROUP BY u.chat_id
        ORDER BY last_message_time DESC
    ');

    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'chats' => $chats]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
