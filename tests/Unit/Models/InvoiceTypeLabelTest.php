<?php

namespace Tests\Unit\Models;

use App\Models\Invoice\Invoice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defect (BUG-194, OP-PF-01): the partner's $800 fee per client was renamed
 * "Client Activation Fee" on Plan & Fee, but every invoice still named it
 * "Borrower Fee" - the label was the stored type with its underscores turned
 * into spaces. The stored type stays `borrower_fee`; only the name shown changes.
 */
#[CoversClass(Invoice::class)]
class InvoiceTypeLabelTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function types(): iterable
    {
        yield 'borrower fee' => [Invoice::INVOICE_TYPE_BORROWER_FEE, 'Client Activation Fee'];
        yield 'enrollment' => [Invoice::INVOICE_TYPE_ENROLLMENT, 'Enrollment'];
        yield 'down payment' => [Invoice::INVOICE_TYPE_DOWN_PAYMENT, 'Down Payment'];
        yield 'installment' => [Invoice::INVOICE_TYPE_INSTALLMENT, 'Installment'];
        yield 'platform fee' => [Invoice::INVOICE_TYPE_PLATFORM_FEE, 'Platform Fee'];
        yield 'user seat' => [Invoice::INVOICE_TYPE_USER_SEAT, 'User Seat'];
        yield 'commission payout' => [Invoice::INVOICE_TYPE_COMMISSION_PAYOUT, 'Commission Payout'];
        yield 'no type (older rows)' => [null, ''];
    }

    #[Test]
    #[DataProvider('types')]
    public function an_invoice_type_is_shown_by_its_name(?string $type, string $label): void
    {
        $this->assertSame($label, Invoice::typeLabel($type));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function typedLabels(): iterable
    {
        yield 'full new name' => ['Client Activation Fee', [Invoice::INVOICE_TYPE_BORROWER_FEE]];
        yield 'part of the new name, any case' => ['client ACT', [Invoice::INVOICE_TYPE_BORROWER_FEE]];
        yield 'another type' => ['enroll', [Invoice::INVOICE_TYPE_ENROLLMENT]];
        yield 'shared word' => ['fee', [Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::INVOICE_TYPE_BORROWER_FEE]];
        yield 'surrounding spaces' => ['  Commission Payout ', [Invoice::INVOICE_TYPE_COMMISSION_PAYOUT]];
        yield 'no type has this name' => ['nonsense', []];
        yield 'blank' => ['   ', []];
    }

    /**
     * Filters are typed as the name people see ("Client Activation Fee") but
     * run on the stored type (borrower_fee).
     *
     * @param list<string> $types
     */
    #[Test]
    #[DataProvider('typedLabels')]
    public function a_typed_name_gives_the_stored_types_it_names(string $typed, array $types): void
    {
        $this->assertEqualsCanonicalizing($types, Invoice::typesMatchingLabel($typed));
    }

    #[Test]
    public function a_borrower_fee_invoice_is_shown_as_a_client_activation_fee(): void
    {
        $invoice = new Invoice(['type' => Invoice::INVOICE_TYPE_BORROWER_FEE]);

        $this->assertSame('Client Activation Fee', $invoice->typeName());
        $this->assertSame(Invoice::INVOICE_TYPE_BORROWER_FEE, $invoice->type);
    }
}
