<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Company\Company;
use App\Models\Employee\Employee;

class Borrower extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'borrowers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'partner_id',
        'business_id',
        'employee_id',
        'assign_to',
        'status',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the borrower.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the partner that owns the borrower.
     */
    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Get the business that owns the borrower.
     */
    public function business()
    {
        return $this->belongsTo(Company::class, 'business_id');
    }

    /**
     * Get the employee that owns the borrower.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user assigned to this borrower.
     */
    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assign_to');
    }
}