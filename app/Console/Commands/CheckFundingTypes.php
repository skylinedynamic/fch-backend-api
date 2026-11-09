<?php

namespace App\Console\Commands;

use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Support\Funding\FundingTypeName;
use Illuminate\Console\Command;

/**
 * Read only - a dry run of what the funding types change (2026-11-05) means for
 * the data already in the database. Preferred / Additional Funding Types are now
 * the Active lender product categories (GET /common/funding-types); this lists
 * what does not fit, so it can be fixed before anyone runs into it:
 *
 *  1. funding applications whose Preferred / Additional type is not an Active
 *     category - the wizard clears it, and its Funding Request step can't be
 *     saved until one is chosen. Each gets the category with the same
 *     meaningful words, if there is one (FundingTypeName).
 *  2. embed form submissions not converted yet, likewise - converting copies
 *     their types onto the new application.
 *  3. pre-qualification purposes no funding type finds in the Pre-Qualification
 *     list's filter.
 *  4. Inactive categories still used by lender rules - those lenders can't be
 *     matched from an application any more.
 *  5. Active categories no lender has - choosing one shows no lenders.
 *
 * It never changes anything.
 */
class CheckFundingTypes extends Command
{
    protected $signature = 'funding-types:check';

    protected $description = 'Read-only report of existing funding types that are not Active lender product categories';

    private const NO_MATCH = '(no matching category)';

    /** @var array<int, string> */
    private array $active = [];

    public function handle(): int
    {
        $this->warn('Read only: nothing is changed.');
        $this->active = LenderProductCategory::fundingTypeNames();
        $this->line('Active lender product categories (the funding types offered): ' . count($this->active));

        $this->applications();
        $this->submissions();
        $this->preQualifications();
        $this->inactiveCategoriesInUse();
        $this->categoriesWithoutLenders();

        return self::SUCCESS;
    }

    private function applications(): void
    {
        $rows = [];
        $applications = 0;
        FundingApplication::query()
            ->select(['id', 'application_number', 'status', 'preferred_funding_type', 'additional_funding_types'])
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$rows, &$applications) {
                foreach ($chunk as $application) {
                    $found = $this->unknownTypes($application->preferred_funding_type, (array) ($application->additional_funding_types ?? []));
                    if ($found === []) {
                        continue;
                    }
                    $applications++;
                    foreach ($found as [$field, $value]) {
                        $rows[] = [
                            $application->application_number ?? "#{$application->id}",
                            $application->status,
                            $application->isEditable() ? 'Yes' : 'No (locked)',
                            $field,
                            $value,
                            $this->suggestion($value),
                        ];
                    }
                }
            });

        $this->newLine();
        $this->info("Funding applications with a funding type to re-choose: {$applications}");
        if ($rows !== []) {
            $this->table(['Application', 'Status', 'Editable', 'Field', 'Saved type', 'Suggested category'], $rows);
        }
    }

    private function submissions(): void
    {
        $rows = [];
        $submissions = 0;
        EmbedFormSubmission::query()
            ->where('status', '!=', EmbedFormSubmission::STATUS_CONVERTED)
            ->select(['id', 'first_name', 'last_name', 'status', 'preferred_funding_type', 'additional_funding_types'])
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$rows, &$submissions) {
                foreach ($chunk as $submission) {
                    $found = $this->unknownTypes($submission->preferred_funding_type, (array) ($submission->additional_funding_types ?? []));
                    if ($found === []) {
                        continue;
                    }
                    $submissions++;
                    foreach ($found as [$field, $value]) {
                        $rows[] = ["#{$submission->id}", trim("{$submission->first_name} {$submission->last_name}"), $submission->status, $field, $value, $this->suggestion($value)];
                    }
                }
            });

        $this->newLine();
        $this->info("Embed form submissions not converted yet with a funding type to re-choose: {$submissions}");
        if ($rows !== []) {
            $this->table(['Submission', 'Applicant', 'Status', 'Field', 'Saved type', 'Suggested category'], $rows);
        }
    }

    private function preQualifications(): void
    {
        $counts = PreQualificationRequest::query()
            ->where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->whereNotNull('purpose_of_funding')
            ->where('purpose_of_funding', '!=', '')
            ->selectRaw('purpose_of_funding, COUNT(*) as total')
            ->groupBy('purpose_of_funding')
            ->pluck('total', 'purpose_of_funding');

        $unmatched = $counts->filter(fn ($total, $purpose) => $this->suggestion((string) $purpose) === self::NO_MATCH);

        $this->newLine();
        $this->info("Pre-qualification purposes no funding type filter finds: {$unmatched->count()} ({$unmatched->sum()} pre-qualifications)");
        if ($unmatched->isNotEmpty()) {
            $this->table(['Purpose', 'Pre-qualifications'], $unmatched->map(fn ($total, $purpose) => [$purpose, $total])->values()->all());
        }
    }

    private function inactiveCategoriesInUse(): void
    {
        $inactive = LenderProductCategory::where('status', '!=', LenderProductCategory::STATUS_ACTIVE)->pluck('name', 'id');
        $rows = LenderVisibility::with('lender:id,name')
            ->whereIn('lender_product_category_id', $inactive->keys())
            ->get()
            ->filter(fn ($visibility) => $visibility->lender)
            ->map(fn ($visibility) => [$inactive[$visibility->lender_product_category_id], $visibility->lender->name])
            ->unique(fn ($row) => implode('|', $row))
            ->values();

        $this->newLine();
        $this->info('Inactive categories still used by lender rules: ' . $rows->pluck(0)->unique()->count());
        if ($rows->isNotEmpty()) {
            $this->table(['Inactive category', 'Lender'], $rows->all());
        }
    }

    private function categoriesWithoutLenders(): void
    {
        $withLender = LenderVisibility::whereHas('lender', fn ($q) => $q->where('status', Lender::STATUS_ACTIVE))
            ->pluck('lender_product_category_id')
            ->unique();
        $empty = LenderProductCategory::where('status', LenderProductCategory::STATUS_ACTIVE)
            ->whereNotIn('id', $withLender)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name');

        $this->newLine();
        $this->info("Active categories with no lender: {$empty->count()}");
        if ($empty->isNotEmpty()) {
            $this->table(['Category'], $empty->map(fn ($name) => [$name])->all());
        }
    }

    /**
     * The saved types that are not Active categories.
     *
     * @param  array<int, mixed>  $additional
     * @return array<int, array{string, string}> [field, value]
     */
    private function unknownTypes(?string $preferred, array $additional): array
    {
        $found = [];
        if (filled($preferred) && ! in_array($preferred, $this->active, true)) {
            $found[] = ['Preferred', $preferred];
        }
        foreach ($additional as $type) {
            if (filled($type) && ! in_array($type, $this->active, true)) {
                $found[] = ['Additional', (string) $type];
            }
        }

        return $found;
    }

    /** The Active category with the same meaningful words, if any. */
    private function suggestion(string $value): string
    {
        return FundingTypeName::categoryFor($value, $this->active) ?? self::NO_MATCH;
    }
}
