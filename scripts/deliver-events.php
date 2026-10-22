<?php
declare(strict_types=1);

/** Deliver queued events as signed webhooks. Run every minute from cron:  php scripts/deliver-events.php */
require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$r = App\Core\EventDelivery::run();
echo date('c') . " queued={$r['queued']} delivered={$r['delivered']} retrying={$r['retrying']} failed={$r['failed']}\n";
