<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['category_id', 'sku', 'name', 'base_price', 'is_active'];

public function category()
{
    return $this->belongsTo(Category::class);
}
    
public function branches()
{
    return $this->belongsToMany(Branch::class)
                ->withPivot('stock', 'min_stock')
                ->withTimestamps();
}
}
