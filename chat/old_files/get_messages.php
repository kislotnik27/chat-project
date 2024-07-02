<?php
// get_messages.php
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
$username = $config['db']['username'];
$password = $config['db']['password'];

$chat_id = $_GET['user_id'];
$last_id = isset($_GET['last_id']) ? $_GET['last_id'] : 0;

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare('
        SELECT m.id, m.message, m.media_url, m.message_type, m.message_id_tg, m.reply_to_message_id, m.timestamp, m.sender, u.name as user 
        FROM messages m 
        JOIN users u ON m.user_id = u.chat_id 
        WHERE m.user_id = ? AND m.id > ? 
        ORDER BY m.timestamp ASC
    ');
    $stmt->execute([$chat_id, $last_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'messages' => $messages]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
