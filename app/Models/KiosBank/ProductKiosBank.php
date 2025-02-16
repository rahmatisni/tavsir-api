<?php

namespace App\Models\KiosBank;

use App\Models\BaseModel;

class ProductKiosBank extends BaseModel
{
    protected $table = 'ref_product_kios_bank';
    protected $appends = ['logo_url'];

    protected $fillable = [
        'kategori',
        'sub_kategori',
        'kode',
        'name',
        'prefix_id',
        'harga',
        'is_active'
    ];

    public function getBasePriceAttribute()
    {
        $convert = (int) preg_replace("/[^0-9]/", '', $this->name);
        return $convert;
    }

    public function getLogoUrlAttribute()
    {
        $name = str_replace(' ','_',$this->kategori);
        $name = strtolower($name);
        $path = "logo/{$name}.png";
        return asset($path);
    }
}
