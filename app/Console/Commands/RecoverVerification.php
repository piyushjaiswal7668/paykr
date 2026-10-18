<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Verification\Services\VerificationRecoveryService;
use Illuminate\Console\Command;

final class RecoverVerification extends Command
{
    protected $signature = 'paykr:verification:recover';

    protected $description = 'Requeue up to 100 overdue persisted verification grace deadlines';

    public function handle(VerificationRecoveryService $recovery): int
    {
        $this->info('Verification deadlines queued: '.$recovery->dispatchDue());

        return self::SUCCESS;
    }
}
