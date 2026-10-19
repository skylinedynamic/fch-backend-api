<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class PruneOldLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:prune-months';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove log folders and files older than 6 months';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $logPath = storage_path('logs');
        $directories = File::directories($logPath);
        
        // We keep the current month and the 5 previous months (total 6).
        // Counted back from the 1st: "-1 month" from the 31st overflows past a
        // short month (31 March - 1 month = 3 March) and would drop February.
        $keepMonths = [];
        for ($i = 0; $i < 6; $i++) {
            $keepMonths[] = Carbon::now()->startOfMonth()->subMonths($i)->format('F');
        }

        // The same window as year/month folders (logs/2026/June), which is how
        // logs are written now: month alone could not tell this June from last.
        $keepYearMonths = [];
        for ($i = 0; $i < 6; $i++) {
            $keepYearMonths[] = Carbon::now()->startOfMonth()->subMonths($i)->format('Y/F');
        }

        $this->info("Keeping logs for: " . implode(', ', $keepYearMonths));

        $monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];

        foreach ($directories as $dir) {
            $folderName = basename($dir);

            // A year folder: prune its months by year and month, then the year
            // itself once nothing is left in it.
            if (preg_match('/^\d{4}$/', $folderName)) {
                foreach (File::directories($dir) as $monthDir) {
                    $month = basename($monthDir);
                    if (in_array($month, $monthNames) && !in_array("{$folderName}/{$month}", $keepYearMonths)) {
                        $this->warn("Deleting old log folder: {$folderName}/{$month}");
                        File::deleteDirectory($monthDir);
                    }
                }

                if (File::isEmptyDirectory($dir)) {
                    File::deleteDirectory($dir);
                }

                continue;
            }

            // A folder from before logs carried the year: month name only.
            if (in_array($folderName, $monthNames) && !in_array($folderName, $keepMonths)) {
                $this->warn("Deleting old log folder: $folderName");
                File::deleteDirectory($dir);
            }
        }

        // Also prune loose files in root older than 180 days
        $files = File::files($logPath);
        foreach ($files as $file) {
            if ($file->getExtension() === 'log') {
                $lastModified = Carbon::createFromTimestamp($file->getMTime());
                if ($lastModified->lt(Carbon::now()->subDays(180))) {
                    $this->warn("Deleting old log file: " . $file->getFilename());
                    File::delete($file->getRealPath());
                }
            }
        }

        $this->info('Log pruning complete.');
    }
}
