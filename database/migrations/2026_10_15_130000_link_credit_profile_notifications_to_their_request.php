<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point the host's existing credit profile bell notifications at the request
 * they are about.
 *
 * "Credit profile update requested" linked to the Request Credit Profile list,
 * so clicking it never opened the borrower's request; the type now links to the
 * request itself. And "New credit profile request" linked to a request id that
 * the one-per-borrower merge may since have removed. A stored notification keeps
 * the link it was sent with, so both are repaired here.
 *
 * The notification holds only the borrower's name (in its text), not their id,
 * so it is matched by name - and only where that name belongs to exactly one
 * request. Anything ambiguous keeps the link it has.
 */
return new class extends Migration
{
    private const LIST_URL = '/intake-review/credit-profile-requests';

    /** Type key => the text after the borrower's name in its body. */
    private const TYPES = [
        'host.credit_profile.update_requested' => ' asked to update their credit profile.',
        'host.credit_profile.requested' => ' submitted a credit profile request.',
    ];

    public function up(): void
    {
        $requestIdsByName = [];
        $liveIds = [];

        DB::table('credit_profile_requests')
            ->join('borrowers', 'borrowers.id', '=', 'credit_profile_requests.borrower_id')
            ->whereNull('credit_profile_requests.deleted_at')
            ->select('credit_profile_requests.id', 'borrowers.first_name', 'borrowers.middle_name', 'borrowers.last_name')
            ->orderBy('credit_profile_requests.id')
            ->each(function ($row) use (&$requestIdsByName, &$liveIds) {
                $liveIds[$row->id] = true;
                $requestIdsByName[personName($row->first_name, $row->middle_name, $row->last_name)][] = $row->id;
            });

        DB::table('notifications')
            ->whereIn('type', array_keys(self::TYPES))
            ->orderBy('id')
            ->chunkById(200, function ($notifications) use ($requestIdsByName, $liveIds) {
                foreach ($notifications as $notification) {
                    $data = json_decode($notification->data, true);
                    $url = $data['url'] ?? null;
                    $body = (string) ($data['body'] ?? '');
                    $suffix = self::TYPES[$notification->type];

                    if (!$this->needsRepair($url, $liveIds) || !str_ends_with($body, $suffix)) {
                        continue;
                    }

                    $ids = $requestIdsByName[substr($body, 0, -strlen($suffix))] ?? [];
                    if (count($ids) !== 1) {
                        continue;
                    }

                    $data['url'] = self::LIST_URL . '/' . $ids[0];
                    DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode($data)]);
                }
            });
    }

    /** The list page, or a request that no longer exists. */
    private function needsRepair(?string $url, array $liveIds): bool
    {
        if ($url === self::LIST_URL) {
            return true;
        }

        if ($url !== null && preg_match('#^' . preg_quote(self::LIST_URL, '#') . '/(\d+)$#', $url, $match)) {
            return !isset($liveIds[(int) $match[1]]);
        }

        return false;
    }

    public function down(): void
    {
        // The repaired links are the correct ones; nothing to undo.
    }
};
