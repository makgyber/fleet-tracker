<?php

namespace App\Console\Commands;

use App\Services\ScheduleImporter;
use Illuminate\Console\Command;

/**
 * Pulls the tbss daily field schedule and builds optimized trips per team.
 *
 * Usage:
 *   php artisan fleet:import-schedule           (today)
 *   php artisan fleet:import-schedule 2026-10-16
 */
class ImportSchedule extends Command
{
    protected $signature = 'fleet:import-schedule {date? : Date to import (Y-m-d); defaults to today}';

    protected $description = 'Import teams and destinations from the tbss daily field schedule and optimize their trips.';

    public function handle(ScheduleImporter $importer): int
    {
        $date = $this->argument('date');

        $this->info('Importing tbss schedule' . ($date ? " for {$date}" : ' for today') . ' ...');

        try {
            $result = $importer->import($date);
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d team(s) for %s: %d trip(s) optimized, %d skipped (no destinations).',
            $result['teams_imported'],
            $result['date'],
            $result['trips_optimized'],
            $result['skipped_no_destinations'],
        ));

        return self::SUCCESS;
    }
}
