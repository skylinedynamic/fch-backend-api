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
        
        // We keep the current month and the 5 previous months (total 6)
        $keepMonths = [];
        for ($i = 0; $i < 6; $i++) {
            $keepMonths[] = date('F', strtotime("-$i months"));
        }

        $this->info("Keeping logs for: " . implode(', ', $keepMonths));

        foreach ($directories as $dir) {
            $folderName = basename($dir);
            
            // Check if folder is a month name
            $isMonthName = in_array($folderName, [
                'January', 'February', 'March', 'April', 'May', 'June', 
                'July', 'August', 'September', 'October', 'November', 'December'
            ]);

            if ($isMonthName && !in_array($folderName, $keepMonths)) {
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
