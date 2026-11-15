<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require __DIR__ . '/core.php';
require __DIR__ . '/app/mail.php';
date_default_timezone_set(getenv('BILLING_TIMEZONE') ?: 'Asia/Manila');
queueDueReminders();
echo deliverMailJobs() . " notifications sent.\n";
