<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Partner profile › Mail Notification: the address one type of a partner's
 * notification emails goes to. No row for a type means the partner's own
 * email (Partner::notificationEmail()).
 *
 * A new type is a constant here, added to TYPES, with its label and help in
 * lang/en/partner.php (mail_notification.types.{type}) - no migration, and the
 * admin's tab lists it from the API.
 */
class PartnerNotificationRecipient extends Model
{
    /** A new lead (LeadService::storeDetails, the website's LeadService::store). */
    public const TYPE_LEADS = 'leads';

    /** A new client: +Add Client, or a lead / embedded form submission converted to one (BorrowerService::store). */
    public const TYPE_CLIENTS = 'clients';

    /** Every type, in the order the tab lists them. */
    public const TYPES = [self::TYPE_LEADS, self::TYPE_CLIENTS];

    protected $fillable = ['partner_id', 'type', 'email'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
