<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditAccount extends Model
{
    use SoftDeletes;

    protected $table = 'credit_accounts';

    protected $guarded = ['id'];

    public function applications()
    {
        return $this->hasMany(CreditAccountApplication::class);
    }
}
