<?php

namespace App\Console\Commands;

use App\Models\Lender\LenderProductCategory;
use App\Support\Funding\FundingTypeName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off data correction (request 2026-11-05, findings F184): Preferred /
 * Additional Funding Types are the Active lender product categories now, so a
 * funding application or an embed form submission not converted yet that
 * still has an older name gets the category with the same meaningful words
 * (FundingTypeName): "Line of Credit" -> "Business Line of Credit", "SBA Loan"
 * -> "SBA Financing". An additional type that becomes the preferred one, or a
 * repeat, is dropped.
 *
 * Left as they are: a name no category matches (listed, to be re-chosen by
 * hand), converted submissions (their application is fixed instead) and
 * pre-qualifications (they store a purpose, not a funding type).
 *
 * updated_at is not touched - this corrects data, it is not an edit - so
 * nothing moves in the lists. Run with --dry-run first; see also the
 * read-only funding-types:check.
 */
class FixFundingTypes extends Command
{
    protected $signature = 'funding-types:fix
        {--dry-run : Show what would change without changing anything}
        {--force : Do not ask for confirmation}';

    protected $description = 'Put the matching Active lender product category in place of older funding type names on funding applications and unconverted embed submissions';

    /** table => label of a record in the report */
    private const TABLES = [
        'funding_applications' => 'Funding application',
        'embedded_form_submissions' => 'Embed submission',
    ];

    /** @var array<int, string> */
    private array $active = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        $this->active = LenderProductCategory::fundingTypeNames();

        [$updates, $changes, $unmatched] = $this->plan();

        $this->info('Records to update: ' . count($updates));
        if ($changes !== []) {
            $this->table(['Record', 'Field', 'Saved type', 'New category'], $changes);
        }

        $this->info('Left as they are (no matching category): ' . count($unmatched));
        if ($unmatched !== []) {
            $this->table(['Record', 'Field', 'Saved type'], $unmatched);
        }

        if ($dryRun || $updates === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Update ' . count($updates) . ' record(s) to the matching category?')) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($updates as $update) {
            try {
                DB::table($update['table'])->where('id', $update['id'])->update($update['values']);
                $done++;
            } catch (Throwable $e) {
                $this->error("{$update['label']}: not updated - {$e->getMessage()}");
                Log::error('funding-types:fix could not update a record', ['table' => $update['table'], 'id' => $update['id'], 'error' => $e->getMessage()]);
            }
        }

        $this->info("Updated: {$done}");
        Log::info('funding-types:fix updated funding types', ['updated' => $done]);

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{table: string, id: int, label: string, values: array<string, mixed>}>, 1: array<int, array<int, string>>, 2: array<int, array<int, string>>}
     */
    private function plan(): array
    {
        $updates = [];
        $changes = [];
        $unmatched = [];

        foreach (self::TABLES as $table => $kind) {
            $query = DB::table($table)->select(['id', 'preferred_funding_type', 'additional_funding_types'])->orderBy('id');
            if ($table === 'funding_applications') {
                $query->addSelect('application_number')->whereNull('deleted_at');
            } else {
                $query->where('status', '!=', 'converted');
            }

            foreach ($query->cursor() as $row) {
                $label = $table === 'funding_applications' ? ($row->application_number ?: "#{$row->id}") : "{$kind} #{$row->id}";

                $preferred = $row->preferred_funding_type;
                $newPreferred = $this->mapped($preferred, $label, 'Preferred', $changes, $unmatched);

                $values = [];
                if ($newPreferred !== $preferred) {
                    $values['preferred_funding_type'] = $newPreferred;
                }

                $raw = $row->additional_funding_types;
                $decoded = blank($raw) ? [] : json_decode((string) $raw, true);
                if (! is_array($decoded) || ! array_is_list($decoded)) {
                    // Not a JSON list: kept exactly as it is, never replaced by an empty one.
                    $unmatched[] = [$label, 'Additional', (string) $raw];
                } else {
                    $additional = array_values(array_filter($decoded, fn ($type) => is_string($type) && $type !== ''));
                    $newAdditional = [];
                    foreach ($additional as $type) {
                        $new = $this->mapped($type, $label, 'Additional', $changes, $unmatched);
                        // Never the preferred one again, each once.
                        if ($new !== $newPreferred && ! in_array($new, $newAdditional, true)) {
                            $newAdditional[] = $new;
                        }
                    }
                    if ($newAdditional !== $additional) {
                        $values['additional_funding_types'] = json_encode($newAdditional);
                    }
                }

                if ($values !== []) {
                    $updates[] = ['table' => $table, 'id' => $row->id, 'label' => $label, 'values' => $values];
                }
            }
        }

        return [$updates, $changes, $unmatched];
    }

    /**
     * The name to keep: itself when it is an Active category, else the
     * matching category - or itself, reported, when none matches.
     *
     * @param  array<int, array<int, string>>  $changes
     * @param  array<int, array<int, string>>  $unmatched
     */
    private function mapped(?string $type, string $label, string $field, array &$changes, array &$unmatched): ?string
    {
        if (blank($type) || in_array($type, $this->active, true)) {
            return $type;
        }

        $category = FundingTypeName::categoryFor($type, $this->active);
        if ($category === null) {
            $unmatched[] = [$label, $field, $type];

            return $type;
        }

        $changes[] = [$label, $field, $type, $category];

        return $category;
    }
}
