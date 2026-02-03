<?php

namespace App\Services\Borrower\Setting;

use App\Models\Setting\Setting;
use Illuminate\Support\Facades\Auth;

class SettingService
{
    /**
     * Get Settings for the authenticated borrower's partner.
     *
     * @return Setting|null
     */
    public function getSettings(): ?Setting
    {
        return Setting::first();
    }
}
