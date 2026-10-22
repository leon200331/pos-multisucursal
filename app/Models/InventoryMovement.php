<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = ['branch_id', 'product_id', 'user_id', 'type', 'quantity', 'previous_stock', 'new_stock', 'reference'];
    public function product() { return $this->belongsTo(Product::class); }
    public function user() { return $this->belongsTo(User::class); }
}
