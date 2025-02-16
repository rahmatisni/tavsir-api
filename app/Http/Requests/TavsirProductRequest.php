<?php

namespace App\Http\Requests;

use App\Models\Constanta\ProductType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class TavsirProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'category_id' => 'required|exists:ref_category,id',
            'sku' => ['required','string','max:255',function($attribute, $value, $fail){
                $cek = Product::byType(ProductType::PRODUCT)->where('tenant_id',auth()->user()->tenant_id)
                ->where('sku',$value)->exists();
                if($cek){
                    $fail('The '.$attribute.' has been take');
                }
            }],
            'name' => 'required|string|max:50',
            'photo' => 'nullable|max:5000',
            'price' => 'required|numeric|min:0|max:100000000',
            'is_active' => 'required|boolean',
            'description' => 'nullable|string|max:100',
            'customize' => 'nullable|array',
            'customize.*.customize_id' => 'nullable|integer|exists:ref_customize,id',
            'customize.*.must_choose' => 'nullable|boolean',
        ];
    }

    public function attributes()
    {
        return [
            'category_id' => 'Category',
            'sku' => 'SKU',
            'name' => 'Name',
            'photo' => 'Photo',
            'price' => 'Price',
            'is_active' => 'Is Active',
            'description' => 'Description',
            'customize' => 'List Customize',
            'customize.*.customize_id' => 'Customize',
            'customize.*.must_choose' => 'Must Choose',
        ];
    }
}
