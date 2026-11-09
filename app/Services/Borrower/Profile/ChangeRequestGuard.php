<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Services\FCH\Common\ChangeRequest\BorrowerMappingService;
use Illuminate\Support\Facades\DB;

/**
 * What a borrower's change request is checked against (R2 BUG-039): the value
 * on file - a request for the same value changes nothing - and any request for
 * the same field still waiting for review.
 *
 * A "field" is a profile column, or one column of one of the borrower's own
 * rows (a bank account), named by entity_id.
 */
class ChangeRequestGuard
{
    /** Written with punctuation, compared by their digits. */
    private const DIGIT_FIELDS = ['phone_number', 'home_phone', 'business_phone', 'ssn', 'business_ein', 'duns_number'];

    /** Case does not make another address. */
    private const EMAIL_FIELDS = ['email', 'business_email'];

    private const TYPE_DATE = 'date';
    private const TYPE_DECIMAL = 'decimal';
    private const TYPE_INTEGER = 'integer';

    /**
     * The field's value on file and the entity it belongs to, or null when the
     * field is unknown or the row is not this borrower's.
     *
     * @return array{entity_id: int, value: mixed}|null
     */
    public function current(Borrower $borrower, string $field, ?int $entityId): ?array
    {
        $mapping = BorrowerMappingService::getFieldMapping($field);

        if (!$mapping) {
            return null;
        }

        $column = $mapping['db_column'];

        if (isset($mapping['table'])) {
            $row = $entityId
                ? DB::table($mapping['table'])->where('id', $entityId)->where('borrower_id', $borrower->id)->first()
                : null;

            return $row ? ['entity_id' => (int) $row->id, 'value' => $row->{$column}] : null;
        }

        return ['entity_id' => (int) $borrower->id, 'value' => $borrower->{$column}];
    }

    /** True when the new value is the one on file, however it is written. */
    public function isSameValue(string $field, mixed $current, mixed $new): bool
    {
        $type = BorrowerMappingService::getDataType($field);

        return $this->comparable($field, $type, $current) === $this->comparable($field, $type, $new);
    }

    /** True when a request for this field (of this row) is waiting for review. */
    public function hasPending(Borrower $borrower, string $field, int $entityId): bool
    {
        $mapping = BorrowerMappingService::getFieldMapping($field);

        return BorrowerChangeRequest::where('borrower_id', $borrower->id)
            ->where('field_name', $field)
            ->where('entity_type', $mapping['model'])
            ->where('entity_id', $entityId)
            ->where('status', BorrowerChangeRequest::STATUS_PENDING)
            ->exists();
    }

    /**
     * The requests waiting for review: id (to cancel one), field_name, and
     * entity_id for a row field (null for a profile column). No values - the
     * portal only needs to know which fields are locked.
     *
     * @return array<int, array{id: int, field_name: string, entity_id: int|null}>
     */
    public function pendingFields(Borrower $borrower): array
    {
        return BorrowerChangeRequest::where('borrower_id', $borrower->id)
            ->where('status', BorrowerChangeRequest::STATUS_PENDING)
            ->get(['id', 'field_name', 'entity_type', 'entity_id'])
            ->map(fn (BorrowerChangeRequest $request) => [
                'id' => (int) $request->id,
                'field_name' => $request->field_name,
                'entity_id' => $request->entity_type === Borrower::class ? null : (int) $request->entity_id,
            ])
            ->unique(fn (array $field) => $field['field_name'] . '#' . $field['entity_id'])
            ->values()
            ->all();
    }

    private function comparable(string $field, string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if (in_array($field, self::DIGIT_FIELDS, true)) {
            return preg_replace('/\D/', '', $value);
        }

        if (in_array($field, self::EMAIL_FIELDS, true)) {
            return strtolower($value);
        }

        if (in_array($type, [self::TYPE_DECIMAL, self::TYPE_INTEGER], true) && is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if ($type === self::TYPE_DATE && ($time = strtotime($value)) !== false) {
            return date('Y-m-d', $time);
        }

        return $value;
    }
}
