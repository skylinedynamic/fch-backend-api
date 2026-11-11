<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function __construct(protected EmailChangeService $emailChange) {}

    /**
     * Update borrower profile.
     *
     * A new email isn't saved with the rest: like every sign-in email change it
     * waits as pending until it's confirmed (with the password) from the link
     * sent to the new address, and the old address is told and can cancel.
     * While a change waits the form shows the pending email, so typing the
     * current one back in withdraws it (BUG-203) and leaving it alone keeps it.
     *
     * @return string the message for the save: link sent, change withdrawn, or plain save
     * @throws ValidationException when another account already uses the new email
     * @throws Exception
     */
    public function updateProfile(User $user, array $data): string
    {
        // Checked before anything is written, so a taken email fails the whole save.
        $newEmail = $this->emailChange->takeFrom($data, $user, $user->email);
        $withdraw = $newEmail !== null && $this->emailChange->withdraws($user, $newEmail);

        try {
            DB::beginTransaction();

            // Map the request fields to database columns
            $updateData = [
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'phone_number' => $data['phone_number'],
                'country' => $data['country'],
                'country_code' => $data['country_code'],
                'state' => $data['state'],
                'city' => $data['city'],
                'postal_code' => $data['zip_code'],
                'home_address' => $data['home_address'],
                // Nullable in the form request, so validated() omits the key
                // entirely when the client does not send it - reading it
                // unguarded turned a blank optional field into a 500.
                'home_phone' => $data['home_phone'] ?? null,
                'date_of_birth' => $data['date_of_birth'],
            ];

            // Left blank, the SSN on file stays: the form never receives the
            // full number back, so it has nothing to resend.
            if (!empty($data['ssn'])) {
                $updateData['ssn'] = $data['ssn'];
            }

            // Update the borrower — via a fetched model instance (not a bulk
            // Borrower::where(...)->update()) so the last_4_ssn/ssn accessors
            // and mutators on the model actually run.
            $borrower = Borrower::find($user->entity_id);
            if ($borrower) {
                $borrower->update($updateData);
            }

            User::where('id', $user->id)->update([
                // personName() rather than concatenation: it drops the blank
                // parts, so someone with no middle name does not end up with a
                // double space in the middle of their name.
                'name' => personName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']),
                'phone_number' => $data['phone_number'],
            ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception(__('borrower.failed_to_update_profile: ' . $e->getMessage()));
        }

        if (!$newEmail) {
            return __('borrower.update_personal_profile');
        }

        $this->emailChange->request($user, $newEmail);

        return $withdraw
            ? __('email_change.cancelled')
            : __('email_change.requested', ['email' => $newEmail]);
    }


    /**
     * Get borrower profile
     *
     * @param User $user
     * @return User
     */
    public function getProfile(User $user): User
    {
        return $user->fresh();
    }
}
