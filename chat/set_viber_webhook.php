<?php
require '/home/fm451400/vendor/autoload.php';
$config = require '/home/fm451400/elaliza.com/work/config/config.php';
require '/home/fm451400/elaliza.com/work/modules/viber/ViberModule.php';

use Modules\Viber\ViberModule;

$viberModule = new ViberModule($config);

$webhookUrl = 'https://work.elaliza.com/modules/viber/viber.php';

try {
    $viberModule->setWebhook($webhookUrl);
    echo "Webhook successfully set to: $webhookUrl";
} catch (Exception $e) {
    echo "Failed to set webhook: " . $e->getMessage();
}
