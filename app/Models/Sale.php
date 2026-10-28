<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = ['branch_id', 'user_id', 'total', 'status', 'payment_method', 'stripe_checkout_session_id', 'paid_at'];

    public function items() { return $this->hasMany(SaleItem::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }
}