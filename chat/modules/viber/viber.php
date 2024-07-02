<?php
// Подключение автозагрузчика
try {
    require '/home/fm451400/vendor/autoload.php';
    $config = require '/home/fm451400/elaliza.com/work/config/config.php';
    require '/home/fm451400/elaliza.com/work/modules/viber/ViberModule.php';
    echo "Autoload loaded successfully.<br>";
} catch (Exception $e) {
    echo "Error loading autoload.php: " . $e->getMessage();
    file_put_contents(__DIR__ . '/viber_debug.log', 'Error loading autoload.php: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit;
}

use Modules\Viber\ViberModule;

$viberModule = new ViberModule($config);

try {
    // Получение обновлений
    $viberModule->handleRequest();
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage();
    file_put_contents(__DIR__ . '/viber_error.log', 'Exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    file_put_contents(__DIR__ . '/viber_debug.log', 'Exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}
?>
