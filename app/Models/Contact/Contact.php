<?php

namespace App\Models\Contact;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contacts';

    protected $fillable = [
        'partner_id',
        'first_name',
        'last_name',
        'company_name',
        'mobile_number',
        'email',
        'address',
        'country',
        'state',
        'city',
        'zip_code',
        'website',
        'notes',
        'image',
        'is_referral_partner',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_referral_partner' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 🔹 Full name accessor.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Scope: apply text search across contact fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (! is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('mobile_number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            
            if (stripos('referral partner', $search) !== false || stripos('rp', $search) !== false) {
                $q->orWhere('is_referral_partner', 1);
            }
        });
    }
}
