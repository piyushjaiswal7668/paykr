<?php

declare(strict_types=1);

use App\Domain\Observers\Jobs\PollGmail;
use App\Domain\Observers\Jobs\PollGmailNow;
use App\Domain\Observers\Jobs\SweepMessages;
use App\Domain\Verification\Jobs\ReattributePendingObservations;
use App\Domain\Webhooks\Jobs\DeliverWebhook;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\StreamOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    throw new RuntimeException('Clock process tests require an isolated MySQL *_test database.');
}
Bus::fake([
    DeliverWebhook::class,
    PollGmail::class,
    PollGmailNow::class,
    SweepMessages::class,
    ReattributePendingObservations::class,
]);
exit($app->make(Kernel::class)->call('paykr:clock', in_array('--once', $argv, true) ? ['--once' => true] : [], new StreamOutput(STDOUT)));
