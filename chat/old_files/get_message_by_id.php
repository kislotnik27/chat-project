<?php
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'] . ';charset=utf8';
$username = $config['db']['username'];
$password = $config['db']['password'];

$message_id_tg = $_GET['message_id_tg'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare('
        SELECT m.message, u.name as user 
        FROM messages m 
        JOIN users u ON m.user_id = u.chat_id 
        WHERE m.message_id_tg = ?
    ');
    $stmt->execute([$message_id_tg]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($message) {
        echo json_encode(['status' => 'success', 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Message not found']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
