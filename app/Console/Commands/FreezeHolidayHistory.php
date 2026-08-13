<?php

namespace App\Console\Commands;

use App\Models\PublicHoliday;
use Illuminate\Console\Command;

class FreezeHolidayHistory extends Command
{
    protected $signature = 'holidays:freeze-history';

    protected $description = 'Freeze completed public-holiday dates for historically accurate reports';

    public function handle(): int
    {
        $through = now()->subDay()->startOfDay();
        $count = PublicHoliday::freezeHistoryThrough($through);
        $this->info("Holiday history is frozen through {$through->toDateString()} ({$count} occurrence(s) added).");

        return self::SUCCESS;
    }
}
