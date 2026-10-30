<?php

namespace App\Console\Commands;

use App\Services\FCH\Common\ManageAgreement\AgreementImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off tidy-up for agreement rows saved before their images moved to disk
 * (see AgreementImageStore and the AgreementImages cast).
 *
 * Those rows hold every logo and signature as base64 inside the HTML/JSON -
 * about 1.4 MB per template and up to 6 MB per signed agreement. This moves
 * each image to disk and leaves a short reference, writing the column straight
 * to the table so `updated_at` - and with it a signed agreement's record - is
 * not touched. A row is only rewritten when it reads back exactly as it was;
 * a re-run skips rows already done. Run with --dry-run first.
 */
class MoveAgreementImagesToDisk extends Command
{
    protected $signature = 'agreements:move-images-to-disk {--dry-run : Report what would change without changing anything}';

    protected $description = 'Move base64 images out of agreement templates and signed agreements in the database onto the agreement image disk';

    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'agreement_templates' => ['template_html', 'template_json'],
        'agreement_signs' => ['content_html'],
    ];

    public function handle(AgreementImageStore $images): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        if ($dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)
                ->select(array_merge(['id'], $columns))
                ->orderBy('id')
                ->chunkById(20, function ($rows) use ($table, $columns, $images, $dryRun, &$updated, &$skipped) {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach ($columns as $column) {
                            $original = $row->{$column};
                            $extracted = $images->extract($original, $dryRun);

                            if ($extracted === $original) {
                                continue;
                            }

                            // Compared as read: a row may already hold references from an earlier run.
                            if (!$dryRun && $images->inline($extracted) !== $images->inline($original)) {
                                $this->warn("{$table} #{$row->id} {$column}: would not read back unchanged, left as it is.");
                                $skipped++;
                                continue 2;
                            }

                            $changes[$column] = $extracted;
                        }

                        if ($changes === []) {
                            continue;
                        }

                        if (!$dryRun) {
                            DB::table($table)->where('id', $row->id)->update($changes);
                        }
                        $updated++;
                    }
                });
        }

        $this->info($dryRun ? "{$updated} rows would be updated." : "{$updated} rows updated.");
        if ($skipped > 0) {
            $this->warn("{$skipped} rows left as they are.");
        }

        return self::SUCCESS;
    }
}
