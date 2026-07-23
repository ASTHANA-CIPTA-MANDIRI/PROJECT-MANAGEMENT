<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Email each user yesterday's activity digest, every morning.
        $schedule->command('reports:daily')
            ->dailyAt('07:00')
            ->withoutOverlapping();

        // Archive activities older than 90 days, weekly.
        $schedule->command('cleanup:old-activities --days=90')
            ->weeklyOn(1, '02:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
