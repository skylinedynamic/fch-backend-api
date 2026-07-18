<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class RestoreDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore {--latest : Pick the latest backup} {--file= : The file path on the disk} {--disk= : The disk to pull from (defaults based on environment)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a database backup from the specified disk (Local or S3)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $diskName = $this->option('disk') ?: env('BACKUP_DISK', (env('APP_ENV') === 'production' ? 's3' : 'local'));
        $disk = Storage::disk($diskName);
        $file = $this->option('file');

        if ($this->option('latest')) {
            $appName = config('backup.backup.name');
            $files = collect($disk->allFiles($appName))
                ->filter(fn($f) => str_ends_with($f, '.zip'))
                ->sortByDesc(fn($f) => $disk->lastModified($f))
                ->values();

            if ($files->isEmpty()) {
                $this->warn("No backups found for app '{$appName}' on disk '{$diskName}'.");
                
                if ($this->confirm("Would you like to generate the first backup now and then restore it?", true)) {
                    $this->info("Triggering first backup creation...");
                    \Illuminate\Support\Facades\Artisan::call('backup:run', ['--only-db' => true]);
                    
                    // Re-scan for the new file
                    $files = collect($disk->allFiles($appName))
                        ->filter(fn($f) => str_ends_with($f, '.zip'))
                        ->sortByDesc(fn($f) => $disk->lastModified($f))
                        ->values();
                } else {
                    return;
                }
            }
            $file = $files->first();
        }

        if (!$file) {
            $this->error('Please specify a --file or use the --latest flag.');
            return;
        }

        if (!$disk->exists($file)) {
            $this->error("File {$file} does not exist on disk '{$diskName}'.");
            return;
        }

        $this->info("Downloading backup: {$file}");
        
        $tempDir = storage_path('app/backup-restore-temp');
        File::makeDirectory($tempDir, 0755, true, true);
        File::cleanDirectory($tempDir);

        $localFilePath = $tempDir . DIRECTORY_SEPARATOR . basename($file);
        file_put_contents($localFilePath, $disk->get($file));

        $this->info("Extracting backup...");
        $zip = new ZipArchive;
        if ($zip->open($localFilePath) === true) {
            $zip->extractTo($tempDir);
            $zip->close();
        } else {
            $this->error("Failed to open the zip file.");
            return;
        }

        $sqlFiles = collect(File::allFiles($tempDir))
            ->filter(fn($f) => str_ends_with($f->getFilename(), '.sql'))
            ->values();

        if ($sqlFiles->isEmpty()) {
            $this->error("No SQL dumps found in the backup.");
            $this->cleanup($tempDir);
            return;
        }

        $sqlFile = $sqlFiles->first()->getRealPath();
        $this->info("Restoring database from: " . basename($sqlFile));

        $dbConn = config('database.default');
        $dbHost = config("database.connections.{$dbConn}.host");
        $dbPort = config("database.connections.{$dbConn}.port");
        $dbUser = config("database.connections.{$dbConn}.username");
        $dbPass = config("database.connections.{$dbConn}.password");
        $dbName = config("database.connections.{$dbConn}.database");

        $passwordFlag = $dbPass ? "-p\"{$dbPass}\"" : "";
        $command = "mysql -h {$dbHost} -P {$dbPort} -u \"{$dbUser}\" {$passwordFlag} \"{$dbName}\" < \"{$sqlFile}\"";

        // Using Laravel Process facade (available in Laravel >= 10)
        $result = Process::run($command);

        if ($result->successful()) {
            $this->info("Database restored successfully!");
        } else {
            $this->error("Failed to restore the database:");
            $this->error($result->errorOutput());
        }

        $this->cleanup($tempDir);
    }

    private function cleanup(string $tempDir)
    {
        $this->info("Cleaning up temporary files...");
        File::deleteDirectory($tempDir);
    }
}
