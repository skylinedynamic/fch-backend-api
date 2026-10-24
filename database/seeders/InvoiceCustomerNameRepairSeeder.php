<?php

namespace Database\Seeders;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off: put the partner's own name back where its company was saved.
 *
 * invoices.customer_name is the person billed. The borrower fee
 * (EnrollmentService), the employee seat (EmployeeService) and the seat renewal
 * (SubscriptionService) used to save the paying partner's company_name there
 * instead, so those invoices' emails, PDFs and Stripe descriptions named a
 * company. The writers now save partners.full_name; this fixes the rows they
 * already wrote.
 *
 * ---------------------------------------------------------------------------
 * HOW TO RUN
 * ---------------------------------------------------------------------------
 *   Dry run (DEFAULT - prints every change, writes nothing):
 *     php artisan db:seed --class=InvoiceCustomerNameRepairSeeder
 *
 *   Apply for real (cmd / Laragon terminal):
 *     set "SEED_DRY_RUN=false" && php artisan db:seed --class=InvoiceCustomerNameRepairSeeder
 *     set SEED_DRY_RUN=
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES
 * ---------------------------------------------------------------------------
 * Every invoice a Partner pays whose customer_name is exactly that partner's
 * company_name gets customer_name = the partner's full_name.
 *
 * Left alone:
 *   - a customer_name that is not the company (a person's name, including one
 *     from before the partner was renamed);
 *   - a partner whose company_name is its full_name (nothing to change);
 *   - a partner with no full_name - listed for review rather than blanked;
 *   - every invoice not paid by a Partner.
 */
class InvoiceCustomerNameRepairSeeder extends Seeder
{
    /** Null reads SEED_DRY_RUN, which defaults to a dry run. */
    public ?bool $dryRun = null;

    protected int $renamed = 0;

    /** @var array<int,string> */
    protected array $manualReview = [];

    public function run(): void
    {
        $this->dryRun ??= filter_var(env('SEED_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN);

        $this->line('');
        $this->line('==============================================================');
        $this->line(' Invoice customer name repair');
        $this->line(' MODE: ' . ($this->dryRun ? 'DRY RUN (no writes)' : '*** APPLYING CHANGES ***'));
        $this->line('==============================================================');

        DB::beginTransaction();

        try {
            $this->renameCompanyRows();
            $this->summary();

            // A dry run always rolls back, so nothing slips through a missed guard.
            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function renameCompanyRows(): void
    {
        $invoices = Invoice::query()
            ->join('partners', 'partners.id', '=', 'invoices.entity_id')
            ->where('invoices.entity_type', Partner::class)
            ->whereNotNull('partners.company_name')
            ->whereColumn('invoices.customer_name', 'partners.company_name')
            ->select('invoices.*', 'partners.full_name as partner_full_name')
            ->orderBy('invoices.id')
            ->get();

        $this->line('');
        $this->line(sprintf(' %d invoice(s) saved under the partner\'s company', $invoices->count()));

        foreach ($invoices as $invoice) {
            $name = trim((string) $invoice->partner_full_name);

            if ($name === '') {
                $this->manualReview[] = sprintf(
                    'invoice #%d (%s): partner #%d has no full_name',
                    $invoice->id,
                    $invoice->invoice_id,
                    $invoice->entity_id
                );
                continue;
            }

            if ($name === $invoice->customer_name) {
                continue;
            }

            $this->line(sprintf(
                '     invoice #%d (%s, %s): "%s" -> "%s"',
                $invoice->id,
                $invoice->invoice_id,
                $invoice->type,
                $invoice->customer_name,
                $name
            ));

            if (!$this->dryRun) {
                Invoice::whereKey($invoice->id)->update(['customer_name' => $name]);
            }

            $this->renamed++;
        }
    }

    protected function summary(): void
    {
        $this->line('');
        $this->line(sprintf(' %s %d invoice(s).', $this->dryRun ? 'Would rename' : 'Renamed', $this->renamed));

        if ($this->manualReview) {
            $this->line('');
            $this->line(' Needs a person\'s name on the partner first:');
            foreach ($this->manualReview as $item) {
                $this->line('     ' . $item);
            }
        }

        $this->line('');
    }

    protected function line(string $message): void
    {
        // Silent outside artisan (e.g. under test).
        if (isset($this->command)) {
            $this->command->getOutput()->writeln($message);
        }
    }
}
