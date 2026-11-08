<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Edata\Notifier;
use Illuminate\Console\Command;

class EdataAlerts extends Command
{
    protected $signature = 'edata:alerts';

    protected $description = 'Create due-soon, overdue and missed clock-out notifications for every company';

    public function handle(): int
    {
        foreach (User::where('type', 'company')->pluck('id') as $companyId) {
            Notifier::runChecks((int) $companyId);
        }

        $this->info('Alerts checked.');

        return self::SUCCESS;
    }
}
