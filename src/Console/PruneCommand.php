<?php

namespace Nepicsoft\Osync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Nepicsoft\Osync\Journal;

final class PruneCommand extends Command
{
    protected $signature = 'osync:prune {--days= : Keep tombstones this many days (default: config)}';

    protected $description = 'Drop sync tombstones past the retention window';

    public function handle(Journal $journal): int
    {
        $days = (int) ($this->option('days') ?? config('osync.tombstone_retention_days', 90));
        $pruned = $journal->prune(Carbon::now()->subDays($days));
        $this->info("Pruned {$pruned} tombstones older than {$days} days.");

        return self::SUCCESS;
    }
}
