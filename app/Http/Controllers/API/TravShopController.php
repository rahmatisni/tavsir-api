<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\Tavsir\TrOrderResource;
use App\Models\LogKiosbank;
use App\Http\Middleware\CustomRateLimitMiddleware;
use App\Models\LaJmto;
use App\Models\Sharing;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\TsCreatePaymentRequest;
use App\Http\Requests\TsOrderConfirmRequest;
use App\Http\Requests\TsOrderRequest;
use App\Http\Requests\VerifikasiOrderReqeust;
use App\Http\Resources\SaldoResource;
use App\Http\Resources\TravShop\TsOrderResource;
use App\Http\Resources\TravShop\TsOrderResourceFlo;
use App\Http\Resources\TravShop\TsProducDetiltResource;
use App\Http\Resources\TravShop\TsProductResource;
use App\Http\Resources\TravShop\TsTenantResource;
use App\Http\Resources\TravShop\TsRestAreaResource;
use App\Http\Resources\TsPaymentresource;
use App\Models\AddonPrice;
use App\Models\Bind;
use App\Models\Category;
use App\Models\Constanta\ProductType;
use App\Models\ExtraPrice;
use App\Models\KiosBank\ProductKiosBank;
use App\Models\PaymentMethod;
use App\Models\PgJmto;
use App\Models\Product;
use App\Models\RestArea;
use App\Models\Tenant;
use App\Models\TenantLa;
use App\Models\User;
use App\Models\TransOrder;
use App\Models\TransOrderDetil;
use App\Models\TransPayment;
use App\Models\Voucher;
use App\Services\External\JatelindoService;
use App\Models\NumberTable;
use App\Services\External\KiosBankService;
use App\Services\External\TravoyService;
use App\Services\Payment\PaymentService;
use App\Services\StockServices;
use App\Services\TransSharingServices;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class TravShopController extends Controller
{
    public function __construct(
        protected StockServices $stock_service,
        protected TransSharingServices $trans_sharing_service,
        protected KiosBankService $kiosBankService,
        protected TravoyService $travoyService,
        protected PaymentService $servicePayment,
    ) {
    }

    public function tableList(Request $request)
    {
        return response()->json(NumberTable::where('tenant_id', $request->tenant_id)->get());
    }
    public function restArea(Request $request)
    {
        $data = RestArea::when($name = $request->name, function ($q) use ($name) {
            return $q->where('name', 'like', "%$name%");
        });
        $data = $data->get();

        if ($request->lat && $request->lon) {
            $data = $data->filter(function ($item) use ($request) {
                return $this->haversine($item->latitude, $item->longitude, $request->lat, $request->lon, $request->distance ?? 1);
            });
        }

        return response()->json(TsRestAreaResource::collection($data));
    }

    public function tenant(Request $request)
    {
        $data = Tenant::with('order', 'category_tenant')->when($rest_area_id = $request->rest_area_id, function ($q) use ($rest_area_id) {
            return $q->where('rest_area_id', $rest_area_id);
        })->when($category = $request->category, function ($q) use ($category) {
            return $q->where('category', $category);
        })->when($name = $request->name, function ($q) use ($name) {
            return $q->where('name', 'like', "%$name%");
        })->when($product = $request->product, function ($q) use ($product) {
            return $q->whereHas('product', function ($q) use ($product) {
                return $q->where('name', 'like', "%$product%");
            });
        })->get();

        return response()->json(TsTenantResource::collection($data));
    }

    public function tenantById($id)
    {
        $data = Tenant::findOrFail($id);

        return response()->json(new TsTenantResource($data));
    }

    public function categoryProductTenant($tenant_id)
    {
        $data = Category::byType(ProductType::PRODUCT)->where('tenant_id', $tenant_id)->select('id', 'name', 'tenant_id')->get();
        return response()->json($data);
    }

    public function tenantByCategory()
    {
        $data = Tenant::with('category_tenant', 'order')
            ->get()
            ->groupBy('category_tenant.name')
            ->map(function ($item, $key) {
                return TsTenantResource::collection($item);
            });
        return response()->json($data);
    }

    public function product(Request $request)
    {
        $data = Product::byType(ProductType::PRODUCT)->with('customize', 'category')->when($name = $request->name, function ($q) use ($name) {
            return $q->where('name', 'like', "%$name%");
        })->when($tenant_id = $request->tenant_id, function ($q) use ($tenant_id) {
            return $q->where('tenant_id', $tenant_id);
        })->when($category_id = $request->category_id, function ($q) use ($category_id) {
            return $q->where('category_id', $category_id);
        })->orderByRaw('stock = 0')->orderByRaw('is_active = 0')->orderBy('name', 'asc')->get();

        $active = [];
        $inactive = [];
        foreach ($data as $value) {
            $cek_product_have_not_active = $value->trans_product_raw->where('is_active', 0)->count();
            $stock = $value->stock;
            // $value->stock_sort = $stock > 0 ? 0:1;
            $value->stock_sort = $value->stock === 0 ? 1 : ($value->is_active === 0 ? 1 : 0);
            if ($value->is_composit === 1 && $value->is_active === 1) {
                if ($cek_product_have_not_active > 0) {
                    $value->stock_sort = 1;
                } else {
                    $liststock = [];
                    foreach ($value->trans_product_raw as $item) {
                        $liststock[] = floor($item->stock / $item->pivot->qty);
                    }
                    $temp_stock = count($liststock) == 0 ? 0 : min($liststock);

                    $value->stock_sort = $temp_stock > 0 ? 0 : 1;
                }

            }
            if ($value->stock_sort == 0) {
                $active[] = $value;

            } else {
                $inactive[] = $value;
            }
        }
        $sortedArray = array_merge($active, $inactive);

        return response()->json(TsProductResource::collection($sortedArray));
    }

    public function productById($id)
    {
        $data = Product::byType(ProductType::PRODUCT)->findOrfail($id);
        return response()->json(new TsProducDetiltResource($data));
    }

    public function extraPrice($id)
    {
        $data = ExtraPrice::byTenant($id)->aktif()->get();
        return response()->json($data);
    }

    public function orderList(Request $request)
    {
        $data = TransOrder::with(['detil', 'tenant', 'rest_area', 'payment', 'payment_method'])->where('order_type', 'POS')
            ->when($tenant_id = request()->tenant_id, function ($q) use ($tenant_id) {
                return $q->where('tenant_id', $tenant_id);
            })
            ->when($rest_area_id = request()->rest_area_id, function ($q) use ($rest_area_id) {
                return $q->where('rest_area_id', $rest_area_id);
            })->orderByDesc('created_at')->get();


        $resource = TsOrderResource::collection($data);
        return response()->json($resource);
    }

    public function order(TsOrderRequest $request)
    {
        try {
            DB::beginTransaction();
            $data = new TransOrder;
            $tenant = Tenant::find($request->tenant_id);
            $data->order_type = TransOrder::ORDER_TAKE_N_GO;
            $data->order_id = ($tenant->rest_area_id ?? '0') . '-' . ($tenant->id ?? '0') . '-TNG-' . date('YmdHis') . rand(0, 100);
            $data->rest_area_id = $tenant->rest_area_id;
            $data->tenant_id = $request->tenant_id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->nomor_name = $request->nomor_name;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $order_detil_many = [];
            $data->save();

            $sub_total = 0;
            $margin = 0;

            foreach ($request->product as $k => $v) {

                $product = Product::byType(ProductType::PRODUCT)->find($v['product_id']);
                $order_detil = new TransOrderDetil();
                $order_detil->trans_order_id = $data->id;
                $order_detil->product_id = $product->id;
                $order_detil->product_name = $product->name;
                $order_detil->price_capital = $product->price_capital;
                $order_detil->base_price = $product->price;
                $order_detil->price = $product->price;
                $customize_x = array();
                foreach ($v['customize'] as $key => $value) {
                    $customize_y = collect($product->customize)->where('id', $value)->first();
                    if ($customize_y) {
                        $pilihan = collect($customize_y->pilihan);
                        $pilihan = $pilihan->where('id', $v['pilihan'][$key])->first();
                        if ($pilihan) {
                            $customize_z = [
                                'customize_id' => $customize_y->id,
                                'customize_name' => $customize_y->name,
                                'pilihan_id' => $pilihan->id,
                                'pilihan_name' => $pilihan->name,
                                'pilihan_price' => (int) $pilihan->price,
                            ];
                            $customize_x[] = $customize_z;
                            $order_detil->price += $pilihan->price;
                        }
                    }
                }
                $order_detil->customize = json_encode($customize_x);
                $order_detil->qty = $v['qty'];
                $raw_margin = $order_detil->price_capital * $v['qty'];

                $order_detil->total_price = $order_detil->price * $v['qty'];
                $order_detil->note = $v['note'];

                $sub_total += $order_detil->total_price;
                $margin += $raw_margin;

                $order_detil_many[] = $order_detil;
            }
            $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            foreach ($extra_price as $value) {
                $addon = new AddonPrice();
                $addon->trans_order_id = $data->id;
                $addon->name = $value->name;
                $addon->price = $value->price;
                if ($value->is_percent == 1) {
                    $addon->price = ($sub_total * $value->price) / 100;
                }

                $addon->save();
                $data->addon_total += $addon->price;
            }

            $data->fee = env('PLATFORM_FEE');
            $data->margin = $sub_total - $margin;
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
                ->where('waktu_mulai', '<=', $now)
                ->where('waktu_selesai', '>=', $now)->first();
            if ($sharing?->sharing_config) {
                $nilai_sharing = json_decode($sharing->sharing_config);
                foreach ($nilai_sharing as $value) {
                    $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
                    $sharing_amount_unround = (($value / 100) * $harga);
                    // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
                    $sharing_amount[] = $sharing_amount_unround;
                }
                $data->sharing_code = $sharing->sharing_code ?? null;
                $data->sharing_amount = $sharing_amount ?? null;
                $data->sharing_proportion = $sharing->sharing_config ?? null;
            } else {
                $data->sharing_code = [(string) $data->tenant_id];
                $data->sharing_proportion = [100];
                $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            }

            $data->status = TransOrder::WAITING_CONFIRMATION_TENANT;
            $data->save();
            $data->detil()->saveMany($order_detil_many);


            DB::commit();
            $data = TransOrder::findOrfail($data->id);

            $fcm_token = User::where([['tenant_id', $request->tenant_id]])->get();
            $ids = array();
            foreach ($fcm_token as $val) {
                if ($val['fcm_token'] != null && $val['fcm_token'] != '')
                    array_push($ids, $val['fcm_token']);
            }
            if ($ids != '') {
                $payload = array(
                    'id' => $data->id,
                    'type' => 'click',
                    'action' => 'tavsir_order_takengo'
                );
                $result = sendNotif($ids, '🛎 Yeay! Ada Pesanan Baru Take N Go', 'Kamu mendapatkan pesanan baru dengan ID ' . $data->order_id . '. Segera lakukan konfirmasi pesanan tersedia dan siapkan pesanan!', $payload);
            }
            if ($data->order_type == TransOrder::ORDER_TRAVOY) {
                $product_kios = ProductKiosBank::select(
                    'kategori',
                    'sub_kategori',
                    'kode',
                    'name'
                )->get();
                $data->map(function ($i) use ($product_kios) {
                    $i->getProductKios = $product_kios;
                });
            }

            return response()->json(new TsOrderResource($data));
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function selfOrder(TsOrderRequest $request)
    {
        try {
            DB::beginTransaction();
            $data = new TransOrder;
            $tenant = Tenant::find($request->tenant_id);
            $data->order_type = TransOrder::ORDER_SELF_ORDER;
            $data->consume_type = $request->consume_type;
            $data->order_id = ($tenant->rest_area_id ?? '0') . '-' . ($tenant->id ?? '0') . '-SO-' . date('YmdHis') . rand(0, 100);
            $data->rest_area_id = $tenant->rest_area_id;
            $data->tenant_id = $request->tenant_id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->nomor_name = $request->nomor_name;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $order_detil_many = [];
            $data->save();

            $margin = 0;
            $sub_total = 0;
            foreach ($request->product as $k => $v) {

                $product = Product::byType(ProductType::PRODUCT)->find($v['product_id']);
                $order_detil = new TransOrderDetil();
                $order_detil->trans_order_id = $data->id;
                $order_detil->product_id = $product->id;
                $order_detil->product_name = $product->name;
                $order_detil->price_capital = $product->price_capital;
                $order_detil->base_price = $product->price;
                $order_detil->price = $product->price;
                $customize_x = array();
                foreach ($v['customize'] as $key => $value) {
                    $customize_y = collect($product->customize)->where('id', $value)->first();
                    if ($customize_y) {
                        $pilihan = collect($customize_y->pilihan);
                        $pilihan = $pilihan->where('id', $v['pilihan'][$key])->first();
                        if ($pilihan) {
                            $customize_z = [
                                'customize_id' => $customize_y->id,
                                'customize_name' => $customize_y->name,
                                'pilihan_id' => $pilihan->id,
                                'pilihan_name' => $pilihan->name,
                                'pilihan_price' => (int) $pilihan->price,
                            ];
                            $customize_x[] = $customize_z;
                            $order_detil->price += $pilihan->price;
                        }
                    }
                }
                $order_detil->customize = json_encode($customize_x);
                $order_detil->qty = $v['qty'];
                $order_detil->total_price = $order_detil->price * $v['qty'];
                $raw_margin = $order_detil->price_capital * $v['qty'];
                $order_detil->note = $v['note'];

                $sub_total += $order_detil->total_price;
                $margin += $raw_margin;
                $order_detil_many[] = $order_detil;
            }
            $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            foreach ($extra_price as $value) {
                $addon = new AddonPrice();
                $addon->trans_order_id = $data->id;
                $addon->name = $value->name;
                $addon->price = $value->price;
                if ($value->is_percent == 1) {
                    $addon->price = ($sub_total * $value->price) / 100;
                }

                $addon->save();
                $data->addon_total += $addon->price;
            }

            $data->fee = env('PLATFORM_FEE');
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            // $tenant = Tenant::where('id', $request->tenant_id)->first();
            // if ($tenant->sharing_config) {
            //     $tenant_sharing = json_decode($tenant->sharing_config);
            //     foreach ($tenant_sharing as $value) {
            //         $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
            //         $sharing_amount_unround = (($value / 100) * $harga);
            //         // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
            //         $sharing_amount[] = $sharing_amount_unround;
            //     }
            //     $data->sharing_code = $tenant->sharing_code ?? null;
            //     $data->sharing_amount = $sharing_amount ?? null;
            //     $data->sharing_proportion = $tenant->sharing_config ?? null;
            // }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
                ->where('waktu_mulai', '<=', $now)
                ->where('waktu_selesai', '>=', $now)->first();
            if ($sharing?->sharing_config) {
                $nilai_sharing = json_decode($sharing->sharing_config);
                foreach ($nilai_sharing as $value) {
                    $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
                    $sharing_amount_unround = (($value / 100) * $harga);
                    // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
                    $sharing_amount[] = $sharing_amount_unround;
                }
                $data->sharing_code = $sharing->sharing_code ?? null;
                $data->sharing_amount = $sharing_amount ?? null;
                $data->sharing_proportion = $sharing->sharing_config ?? null;
            } else {
                $data->sharing_code = [(string) $data->tenant_id];
                $data->sharing_proportion = [100];
                $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            }
            switch ($tenant->in_selforder) {
                case 1:
                    $data->status = TransOrder::WAITING_CONFIRMATION_TENANT;
                    break;

                case 2:
                    $data->status = TransOrder::WAITING_PAYMENT;
                    break;

                case 3:
                    $data->status = TransOrder::WAITING_CONFIRMATION_TENANT;
                    break;

                case 4:
                    $data->status = TransOrder::WAITING_PAYMENT;
                    break;

                default:
                    return response()->json(['error' => 'Hubungi Admin Untuk Aktivasi Fitur Self ORder'], 422);
                    break;
            }


            // $data->status = TransOrder::WAITING_CONFIRMATION_TENANT;
            $data->save();
            $data->detil()->saveMany($order_detil_many);


            DB::commit();
            $data = TransOrder::findOrfail($data->id);

            $fcm_token = User::where([['tenant_id', $request->tenant_id]])->get();
            $ids = array();
            foreach ($fcm_token as $val) {
                if ($val['fcm_token'] != null && $val['fcm_token'] != '')
                    array_push($ids, $val['fcm_token']);
            }
            if ($ids != '') {
                $payload = array(
                    'id' => $data->id,
                    'type' => 'click',
                    'action' => 'tavsir_order_so'
                );
                $result = sendNotif($ids, '🛎 Yeay! Ada Pesanan Baru-Self Order', 'Kamu mendapatkan pesanan baru dengan ID ' . $data->order_id . '. Segera lakukan konfirmasi pesanan tersedia dan siapkan pesanan!', $payload);
            }
            if ($data->order_type == TransOrder::ORDER_TRAVOY) {
                $product_kios = ProductKiosBank::select(
                    'kategori',
                    'sub_kategori',
                    'kode',
                    'name'
                )->get();
                $data->map(function ($i) use ($product_kios) {
                    $i->getProductKios = $product_kios;
                });
            }

            return response()->json(new TsOrderResource($data));
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function derekOrder(Request $request)
    {
        try {
            DB::beginTransaction();
            $data = new TransOrder;
            $tenant = Tenant::find($request->tenant_id);
            // dd($tenant);
            $data->order_type = TransOrder::ORDER_DEREK_ONLINE;
            // $data->order_id = ($tenant->rest_area_id ?? '0') . '-' . ($tenant->id ?? '0') . '-DO-' . date('YmdHis');
            $data->order_id = $request->order_id;
            $data->rest_area_id = $tenant->rest_area_id;
            $data->tenant_id = $request->tenant_id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $data->sub_total = $request->sub_total;

            $data->save();

            $sub_total = $request->sub_total;

            $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            foreach ($extra_price as $value) {
                $addon = new AddonPrice();
                $addon->trans_order_id = $data->id;
                $addon->name = $value->name;
                $addon->price = $value->price;
                if ($value->is_percent == 1) {
                    $addon->price = ($sub_total * $value->price) / 100;
                }

                $addon->save();
                $data->addon_total += $addon->price;
            }

            $data->fee = env('PLATFORM_FEE');
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            $data->status = TransOrder::WAITING_PAYMENT;

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
                ->where('waktu_mulai', '<=', $now)
                ->where('waktu_selesai', '>=', $now)->first();
            if ($sharing?->sharing_config) {
                $nilai_sharing = json_decode($sharing->sharing_config);
                foreach ($nilai_sharing as $value) {
                    $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
                    $sharing_amount_unround = (($value / 100) * $harga);
                    // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
                    $sharing_amount[] = $sharing_amount_unround;
                }
                $data->sharing_code = $sharing->sharing_code ?? null;
                $data->sharing_amount = $sharing_amount ?? null;
                $data->sharing_proportion = $sharing->sharing_config ?? null;
            } else {
                $data->sharing_code = [(string) $data->tenant_id];
                $data->sharing_proportion = [100];
                $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            }

            $data->save();

            DB::commit();
            $data = TransOrder::findOrfail($data->id);
            // return ('oke');
            return response()->json(new TsOrderResource($data));
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => 'Format Data Salah'], 500);
        }
    }

    public function floOrder(Request $request)
    {

        $authorizationHeader = $request->header('Authorization');
        // Jika Basic Auth tidak ditemukan
        if (!$authorizationHeader || !str_starts_with($authorizationHeader, 'Basic ')) {
            return response()->json(['message' => 'Authorization header is missing or invalid.'], 401);
        }



        try {
            $credentials = base64_decode(substr($authorizationHeader, 6));

            [$username, $password] = explode(':', $credentials, 2);
            $validUsername = env('usr_flo'); // Contoh username valid
            $validPassword = env('pwd_flo'); // Contoh password valid

            if ($username !== $validUsername || $password !== $validPassword) {
                return response()->json(['message' => 'Invalid credentials.'], 401)
                    ->header('WWW-Authenticate', 'Basic realm="Access to the API"');
            }
            $validator = Validator::make($request->all(), [
                'customer_name' => 'required|string',
                'customer_phone' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            DB::beginTransaction();
            $data = new TransOrder;


            $tenant = Tenant::find(env('LET_IT_FLO_TID'));
            $data->order_type = TransOrder::ORDER_SELF_ORDER;
            // $data->order_id = ($tenant->rest_area_id ?? '0') . '-' . ($tenant->id ?? '0') . '-DO-' . date('YmdHis');
            $data->tenant_id = $tenant->id;
            $data->order_id = $request->order_id;
            $data->rest_area_id = $tenant->rest_area_id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id ?? null;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $product = Product::find(ENV('LET_IT_FLO_PID'));


            $data->save();


            $request->product = [

                [
                    "product_id" => ENV('LET_IT_FLO_PID'),
                    "customize" => [],
                    "pilihan" => [],
                    "note" => "",
                    "qty" => 1
                ]

            ];
            $margin = 0;
            $sub_total = 0;

            foreach ($request->product as $k => $v) {
                $product = Product::byType(ProductType::PRODUCT)->find($v['product_id']);
                $order_detil = new TransOrderDetil();
                $order_detil->trans_order_id = $data->id;
                $order_detil->product_id = $product->id;
                $order_detil->product_name = $product->name;
                $order_detil->price_capital = $product->price_capital;
                $order_detil->base_price = $product->price;
                $order_detil->price = $product->price;
                $customize_x = array();
                foreach ($v['customize'] as $key => $value) {
                    $customize_y = collect($product->customize)->where('id', $value)->first();
                    if ($customize_y) {
                        $pilihan = collect($customize_y->pilihan);
                        $pilihan = $pilihan->where('id', $v['pilihan'][$key])->first();
                        if ($pilihan) {
                            $customize_z = [
                                'customize_id' => $customize_y->id,
                                'customize_name' => $customize_y->name,
                                'pilihan_id' => $pilihan->id,
                                'pilihan_name' => $pilihan->name,
                                'pilihan_price' => (int) $pilihan->price,
                            ];
                            $customize_x[] = $customize_z;
                            $order_detil->price += $pilihan->price;
                        }
                    }
                }
                $order_detil->customize = json_encode($customize_x);
                $order_detil->qty = $v['qty'];
                $raw_margin = $order_detil->price_capital * $v['qty'];

                $order_detil->total_price = $order_detil->price * $v['qty'];
                $order_detil->note = $v['note'];

                $sub_total += $order_detil->total_price;
                $margin += $raw_margin;

                $order_detil_many[] = $order_detil;
            }

            $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            foreach ($extra_price as $value) {
                $addon = new AddonPrice();
                $addon->trans_order_id = $data->id;
                $addon->name = $value->name;
                $addon->price = $value->price;
                if ($value->is_percent == 1) {
                    $addon->price = ($sub_total * $value->price) / 100;
                }

                $addon->save();
                $data->addon_total += $addon->price;
            }

            $data->fee = env('PLATFORM_FEE');
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            $data->status = TransOrder::WAITING_PAYMENT;

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
                ->where('waktu_mulai', '<=', $now)
                ->where('waktu_selesai', '>=', $now)->first();
            if ($sharing?->sharing_config) {
                $nilai_sharing = json_decode($sharing->sharing_config);
                foreach ($nilai_sharing as $value) {
                    $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
                    $sharing_amount_unround = (($value / 100) * $harga);
                    // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
                    $sharing_amount[] = $sharing_amount_unround;
                }
                $data->sharing_code = $sharing->sharing_code ?? null;
                $data->sharing_amount = $sharing_amount ?? null;
                $data->sharing_proportion = $sharing->sharing_config ?? null;
            } else {
                $data->sharing_code = [(string) $data->tenant_id];
                $data->sharing_proportion = [100];
                $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            }

            $data->detil()->saveMany($order_detil_many);

            $data->save();

            DB::commit();
            $data = TransOrder::findOrfail($data->id);
            return response()->json(new TsOrderResourceFlo($data));
        } catch (\Throwable $th) {
            DB::rollback();
            dd($th);
            return response()->json(['error' => 'Format Data Salah'], 500);
        }
    }
    public function huOrder(Request $request)
    {
        try {
            DB::beginTransaction();
            $data = new TransOrder;
            $tenant_id = 86;
            $tenant = Tenant::find($tenant_id);
            // dd($tenant);
            $data->order_type = TransOrder::ORDER_HU;
            $data->order_id = $request->order_id;
            $data->order_id = $request->order_id;
            $data->rest_area_id = $tenant->rest_area_id;
            $data->tenant_id = $tenant->id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $data->sub_total = $request->sub_total;

            $data->save();

            $sub_total = $request->sub_total;

            // $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            // foreach ($extra_price as $value) {
            //     $addon = new AddonPrice();
            //     $addon->trans_order_id = $data->id;
            //     $addon->name = $value->name;
            //     $addon->price = $value->price;
            //     if ($value->is_percent == 1) {
            //         $addon->price = ($sub_total * $value->price) / 100;
            //     }

            //     $addon->save();
            //     $data->addon_total += $addon->price;
            // }

            $data->fee = env('PLATFORM_FEE');
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            $data->status = TransOrder::WAITING_PAYMENT;
            // 
            // $now = Carbon::now()->format('Y-m-d H:i:s');
            // $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
            //     ->where('waktu_mulai', '<=', $now)
            //     ->where('waktu_selesai', '>=', $now)->first();
            // if ($sharing?->sharing_config) {
            //     $nilai_sharing = json_decode($sharing->sharing_config);
            //     foreach ($nilai_sharing as $value) {
            //         $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
            //         $sharing_amount_unround = (($value / 100) * $harga);
            //         // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
            //         $sharing_amount[] = $sharing_amount_unround;
            //     }
            //     $data->sharing_code = $sharing->sharing_code ?? null;
            //     $data->sharing_amount = $sharing_amount ?? null;
            //     $data->sharing_proportion = $sharing->sharing_config ?? null;
            // } else {
            //     $data->sharing_code = [(string) $data->tenant_id];
            //     $data->sharing_proportion = [100];
            //     $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            // }

            $data->save();

            DB::commit();
            $data = TransOrder::findOrfail($data->id);
            // return ('oke');
            return response()->json(new TsOrderResource($data));
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => 'Format Data Salah'], 500);
        }
    }


    public function parkirOrder(Request $request)
    {
        try {
            DB::beginTransaction();
            $data = new TransOrder;
            $tenant = Tenant::find($request->tenant_id);
            $data->order_type = TransOrder::ORDER_PARKIR;
            $data->order_id = $request->order_id;
            $data->tenant_id = $request->tenant_id;
            $data->business_id = $tenant->business_id;
            $data->customer_id = $request->customer_id;
            $data->customer_name = $request->customer_name;
            $data->customer_phone = $request->customer_phone;
            $data->merchant_id = $tenant->merchant_id;
            $data->sub_merchant_id = $tenant->sub_merchant_id;
            $data->sub_total = $request->sub_total;

            $data->save();

            $sub_total = $request->sub_total;

            $extra_price = ExtraPrice::byTenant($data->tenant_id)->aktif()->get();
            foreach ($extra_price as $value) {
                $addon = new AddonPrice();
                $addon->trans_order_id = $data->id;
                $addon->name = $value->name;
                $addon->price = $value->price;
                if ($value->is_percent == 1) {
                    $addon->price = ($sub_total * $value->price) / 100;
                }

                $addon->save();
                $data->addon_total += $addon->price;
            }

            $data->fee = env('PLATFORM_FEE');
            $data->sub_total = $sub_total;
            $data->total = $data->sub_total + $data->fee + $data->service_fee + $data->addon_total;
            $data->status = TransOrder::WAITING_PAYMENT;

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $sharing = Sharing::where('tenant_id', $request->tenant_id)->whereIn('status', ['sedang_berjalan', 'belum_berjalan'])
                ->where('waktu_mulai', '<=', $now)
                ->where('waktu_selesai', '>=', $now)->first();
            if ($sharing?->sharing_config) {
                $nilai_sharing = json_decode($sharing->sharing_config);
                foreach ($nilai_sharing as $value) {
                    $harga = (int) ($data->sub_total) + (int) ($data->addon_total);
                    $sharing_amount_unround = (($value / 100) * $harga);
                    // $sharing_amount[] = ($value/100).'|'.$harga.'|'.$sharing_amount_unround;
                    $sharing_amount[] = $sharing_amount_unround;
                }
                $data->sharing_code = $sharing->sharing_code ?? null;
                $data->sharing_amount = $sharing_amount ?? null;
                $data->sharing_proportion = $sharing->sharing_config ?? null;
            } else {
                $data->sharing_code = [(string) $data->tenant_id];
                $data->sharing_proportion = [100];
                $data->sharing_amount = [$data->sub_total + (int) ($data->addon_total)];
            }

            $data->save();

            DB::commit();
            $data = TransOrder::findOrfail($data->id);
            // return ('oke');
            return response()->json(new TsOrderResource($data));
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => 'Format Data Salah'], 500);
        }
    }

    public function orderByMeja($id, Request $request)
    {
        // dd($request->id);
        $data = TransOrder::where('nomor_name', $request->no_meja)->where('status', 'cart')->where('rest_area_id', $request->rest_area_id)->where('business_id', $request->business_id)->get();
        return response()->json($data);
    }

    public function paymentonCasheer($id)
    {
        $data = TransOrder::findOrfail($id);
        if ($data->status == TransOrder::PAYMENT_SUCCESS || $data->status == TransOrder::READY || $data->status == TransOrder::DONE) {
            return response()->json(['status' => $data->status, 'responseData' => $data->payment ?? '']);
        }

        $error = [];
        foreach ($data->detil as $value) {
            if ($value->product) {
                if ($value->product->stock < $value->qty) {
                    $error['product'][] = $value->qty . ' qty order ' . $value->product->name . ' is invalid. stock available is ' . $value->product->stock;
                }

                if (!$value->product->is_active) {
                    $error['product'][] = $value->product->name . ' is not active';
                }
            } else {
                $error[]['Product '] = 'Product not available';
            }
        }
        if (count($error) > 0) {
            throw ValidationException::withMessages($error);
        }

        $data->status = 'QUEUE';
        $data->service_fee = 0;
        $data->total = $data->sub_total + $data->addon_total + $data->fee + $data->service_fee;
        $data->save();
        DB::commit();

        $fcm_token = User::where([['tenant_id', $data->tenant_id]])->get();
        $ids = array();
        foreach ($fcm_token as $val) {
            if ($val['fcm_token'] != null && $val['fcm_token'] != '')
                array_push($ids, $val['fcm_token']);
        }
        if ($ids != '') {
            $payload = array(
                'id' => $data->id,
                'type' => 'click',
                'action' => 'payment_casheer'
            );
            $result = sendNotif($ids, '💵 Terdapat Request Pembayaran dengan ID ' . $data->order_id, 'Customer dengan nomor meja ' . $data->nomor_name . ' mengajukan pembayaran di kasir', $payload);
        }
        return response()->json($data);
    }

    public function orderCustomer($id, Request $request)
    {
        $tanggal_awal = $request->tanggal_awal;
        $tanggal_akhir = $request->tanggal_akhir;

        $tanggal_sub = null;
        $tanggal_end = null;

        if ($request->hari) {
            $now = CarbonImmutable::now();
            $tanggal_sub = $now->subDay($request->hari);
            $tanggal_end = $now->endOfDay();
        }

        $kategori = [];
        if ($request->kategori) {
            $kategori = ProductKiosBank::where('sub_kategori', $request->kategori)->pluck('kode')->toArray();
        }

        $data = TransOrder::with(['detil', 'tenant', 'rest_area', 'payment', 'log_kiosbank', 'payment_method'])->where('customer_id', $id)
            ->when($status = request()->status, function ($q) use ($status) {
                return $q->where('status', $status);
            })->when($order_id = request()->order_id, function ($q) use ($order_id) {
                return $q->where('order_id', 'like', "%$order_id%");
            })->when($order_type = request()->order_type, function ($q) use ($order_type) {
                if (request()->order_type == 'ORDER_TRAVOY') {
                    return $q->where('order_type', $order_type)->whereNotNull('payment_method_id');
                } else {
                    return $q->where('order_type', $order_type);
                }
            })->when($tenant_id = request()->tenant_id, function ($q) use ($tenant_id) {
                return $q->where('tenant_id', $tenant_id);
            })->when(($tanggal_awal && $tanggal_akhir), function ($q) use ($tanggal_awal, $tanggal_akhir) {
                return $q->whereBetween(
                    'created_at',
                    [
                        $tanggal_awal,
                        $tanggal_akhir . ' 23:59:59'
                    ]
                );
            })->when($request->hari, function ($q) use ($tanggal_sub, $tanggal_end) {
                return $q->whereBetween(
                    'created_at',
                    [
                        $tanggal_sub,
                        $tanggal_end,
                    ]
                );
            })
            ->orderByDesc('created_at')->get();

        if (count($kategori) > 0) {
            $data = $data->filter(function ($item) use ($kategori) {
                if ($item->order_type == TransOrder::ORDER_TRAVOY) {
                    $kategori_id = explode('-', $item->order_id)[0];
                    if (in_array($kategori_id, $kategori)) {
                        return $item;
                    }
                }
            });
        }
        if ($data->contains('order_type', TransOrder::ORDER_TRAVOY)) {
            $product_kios = ProductKiosBank::select(
                'kategori',
                'sub_kategori',
                'kode',
                'name'
            )->get();
            $data->map(function ($i) use ($product_kios) {
                $i->getProductKios = $product_kios;
            });
        }

        $resource = TsOrderResource::collection($data);
        return response()->json($resource);
    }

    public function orderById($id, Request $request)
    {
        $data = TransOrder::findOrfail($id);
        if ($data->order_type === TransOrder::ORDER_HU) {
            $travoy = $this->travoyService->detailHUPlan($id);
            $response = new TsOrderResource($data);
            $responseArray = $response->toArray(request());
            $responseArray['detil_hu'] = $travoy?->message ?? [];

            return response()->json($responseArray);
        }

        // if ($data->order_type === TransOrder::ORDER_FLO) {
        //     return response()->json(new TsOrderResourceFlo($data));

        // }
        if ($data?->tenant_id === env('LET_IT_FLO_TID')) {
            return response()->json(new TsOrderResourceFlo($data));

        }

        return response()->json(new TsOrderResource($data));
    }

    public function orderConfirm($id)
    {
        $data = TransOrder::findOrfail($id);
        if ($data->status != TransOrder::WAITING_CONFIRMATION_USER) {
            return response()->json(['error' => 'Order ' . $data->status], 500);
        }

        $data->status = TransOrder::WAITING_PAYMENT;
        $data->save();

        $data = TransOrder::findOrfail($id);
        if ($data->order_type == TransOrder::ORDER_TRAVOY) {
            $product_kios = ProductKiosBank::select(
                'kategori',
                'sub_kategori',
                'kode',
                'name'
            )->get();
            $data->getProductKios = $product_kios;
        }
        return response()->json(new TsOrderResource($data));
    }

    public function orderCancel($id)
    {
        $data = TransOrder::findOrfail($id);
        $data->status = TransOrder::CANCEL;
        $data->canceled_by = TransOrder::CANCELED_BY_CUSTOMER;
        $data->canceled_name = request()->name;
        $data->save();

        if ($data->order_type == TransOrder::ORDER_TRAVOY) {
            $product_kios = ProductKiosBank::select(
                'kategori',
                'sub_kategori',
                'kode',
                'name'
            )->get();
            $data->getProductKios = $product_kios;
        }

        return response()->json(new TsOrderResource($data));
    }

    public function paymentMethod(Request $request)
    {
        $paymentMethods = PaymentMethod::all();
        $self_order = [4, 5, 7, 9, 11, 12, 13, 14, 16, 17, 18];
        $travshop = [4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 15, 16, 17, 18];
        $tavsir = [1, 2, 3, 4, 10];

        if ($request->trans_order_id) {
            $trans_order = TransOrder::with('tenant')->findOrfail($request->trans_order_id);
            $param_removes = Tenant::where('id', $trans_order->tenant_id)->first();
            if ($param_removes == null && $trans_order->order_type == 'ORDER_TRAVOY') {
                $self_order = [];
                $travshop = [5, 6, 7, 8, 9, 11, 12, 13, 14, 15, 16, 17, 18];
                $tavsir = [];

                // } else if ($param_removes == null && $trans_order->order_type == 'HU_ORDER') {
                //     $self_order = [];
                //     $travshop = [5, 6, 7, 8, 9, 11, 12, 13, 14];
                //     $tavsir = [];

            } else {
                $intersect = json_decode($param_removes->list_payment);
                if ($param_removes->list_payment == null) {
                    $self_order = [];
                    $travshop = [];
                    $tavsir = [1, 2];
                } else {
                    $self_order = array_intersect($self_order, $intersect);
                    $travshop = array_intersect($travshop, $intersect);
                    $tavsir = array_intersect($tavsir, $intersect);
                }

            }

            $tenant = $trans_order->tenant;
            $tenant_is_verified = $tenant?->is_verified;

            if ($tenant_is_verified === false && $trans_order->order_type != TransOrder::ORDER_TRAVOY) {
                $merchant = PgJmto::listSubMerchant();
                if ($merchant->successful()) {
                    $merchant = $merchant->json();
                    if ($merchant['status'] == 'success') {
                        $merchant = $merchant['responseData'];
                        foreach ($merchant as $key => $value) {
                            if ($value['email'] == $tenant->email) {
                                $trans_order->tenant()->update([
                                    'is_verified' => 1,
                                    'sub_merchant_id' => $value['merchant_id']
                                ]);
                            }
                        }
                    }
                }
            }

            foreach ($paymentMethods as $value) {
                // Log::warning($value);
                $value->platform_fee = env('PLATFORM_FEE');
                $value->fee = 0;

                $value->self_order = false;
                $value->travshop = false;
                $value->tavsir = false;

                if (in_array($value->id, $self_order)) {
                    $value->self_order = true;
                }

                if (in_array($value->id, $travshop)) {
                    $value->travshop = true;
                }
                if (in_array($value->id, $tavsir)) {
                    $value->tavsir = true;
                }

                if ($value?->sof_id == null) {
                    $value->percentage = null;
                    $value->fee = null;
                } else {
                    if ($value->integrator == 'getoll') {
                        $data = PgJmto::tarifFee($value->sof_id, $value->payment_method_id, $trans_order->sub_merchant_id, $trans_order->sub_total);
                    }
                    if ($value->sof_id == null) {
                        $value->percentage = null;
                        $value->fee = null;
                    }

                    $value->percentage = $data['is_presentage'] ?? null;
                    $x = $data['value'] ?? 'x';
                    $state = $data['is_presentage'] ?? null;


                    if ($state == (false || null)) {
                        $value->fee = $data['value'] ?? null;
                    } else {
                        $value->fee = (int) ceil((float) $x / 100 * $trans_order->sub_total);
                    }
                }

                if ($value->id == 4 || $value->id == 16 || $value->id == 17 || $value->id == 18) {
                    $value->fee = 0;
                }

                if ($value->id == 14) {
                    $value->fee = 0;
                }


                // if ($value->id == 13 && $trans_order->customer_phone == '082113088725') {
                //     if ($value->integrator == 'getoll') {
                //         $data = PgJmto::tarifFee(4, $value->payment_method_id, $trans_order->sub_merchant_id, $trans_order->sub_total);
                //     }
                //     $value['sof_id'] = 4;
                //     $value->percentage = $data['is_presentage'] ?? null;
                //     $x = $data['value'] ?? 'x';
                //     $state = $data['is_presentage'] ?? null;


                //     if ($state == (false || null)) {
                //         $value->fee = $data['value'] ?? null;
                //     } else {
                //         $value->fee = (int) ceil((float) $x / 100 * $trans_order->sub_total);
                //     }

                // }
                // if ($value->id == 12 && $trans_order->customer_phone == '082113088725') {
                //     if ($value->integrator == 'getoll') {
                //         $data = PgJmto::tarifFee(3, $value->payment_method_id, $trans_order->sub_merchant_id, $trans_order->sub_total);
                //     }
                //     $value['sof_id'] = 3;
                //     $value->percentage = $data['is_presentage'] ?? null;
                //     $x = $data['value'] ?? 'x';
                //     $state = $data['is_presentage'] ?? null;


                //     if ($state == (false || null)) {
                //         $value->fee = $data['value'] ?? null;
                //     } else {
                //         $value->fee = (int) ceil((float) $x / 100 * $trans_order->sub_total);
                //     }

                // }
            }

        }
        return response()->json($paymentMethods);
    }

    public function createPayment(TsCreatePaymentRequest $request, $id)
    {
        $payment_payload = [];
        $data = TransOrder::findOrfail($id);

        try {
            DB::beginTransaction();
            if (request()->order_from_qr == true) {
                if ($data->status == TransOrder::CART || $data->status == TransOrder::PENDING || $data->status == null) {
                    $data->status = TransOrder::WAITING_PAYMENT;
                    $data->customer_id = $request->customer_id;
                }
            }
            if ($data->status == TransOrder::QUEUE) {
                // $data->total = $data->sub_total;
                // $data->save();
                // DB::commit();
                $data->status = TransOrder::WAITING_PAYMENT;
            }

            if ($data->status != TransOrder::WAITING_PAYMENT) {

                return response()->json(['info' => $data->status], 422);
            }
            //Cek deposit
            if ($data->order_type === TransOrder::ORDER_TRAVOY) {
                $cekProduct = ProductKiosBank::where('kode', $data->codeProductKiosbank())->first();
                //Skip jika jatelindo
                if ($cekProduct?->integrator != 'JATELINDO') {
                    $deposit = $this->kiosBankService->cekDeposit();
                    // $deposit['rc'] = '00';
                    // $deposit['deposit'] = '100000000000';
                    if ($deposit['rc'] == '00') {
                        if ((int) $deposit['deposit'] < $data->sub_total) {
                            return response()->json(['info' => 'Deposit ' . $deposit['deposit'] . ' < ' . $data->sub_total], 422);
                        }
                    } else {
                        return response()->json(['info' => 'Deposit ', 'data' => $deposit], 422);
                    }
                }
            }

            $data->payment_method_id = $request->payment_method_id;

            $res = 'Invalid';
            $payment_method = PaymentMethod::find($request->payment_method_id);
            if ($data->order_type != TransOrder::ORDER_TRAVOY || $data->order_type != TransOrder::ORDER_HU) {
                $data->total = $data->sub_total + $data->addon_total + $data->fee;

                //Cek stok
                $error = [];
                foreach ($data->detil as $value) {
                    if ($value->product) {
                        if ($value->product->stock < $value->qty) {
                            $error['product'][] = $value->qty . ' qty order ' . $value->product->name . ' is invalid. stock available is ' . $value->product->stock;
                        }

                        if (!$value->product->is_active) {
                            $error['product'][] = $value->product->name . ' is not active';
                        }
                    } else {
                        $error[]['Product '] = 'Product not available';
                    }
                }

                if (count($error) > 0) {
                    throw ValidationException::withMessages($error);
                }
            }

            $paymentResult = $this->servicePayment->create($payment_method, $data, $request->all());
            $payment_payload = $paymentResult->data;


            if ($paymentResult->status == false) {
                return response()->json($paymentResult, 422);
            }
            $data->service_fee = (float) $paymentResult->data['responseData']['fee'];
            // $data->total = $data->total + $data->service_fee;
            $data->total = $data->sub_total + $data->addon_total + $data->fee + $data->service_fee;
            $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            $data->save();
            $response = $paymentResult->data;
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $response['responseData']['exp_date'])) {
            } else {
                // The date is not in the desired format, so convert it
                $carbonDate = Carbon::parse($response['responseData']['exp_date']);
                $response['responseData']['exp_date'] = $carbonDate->format('Y-m-d H:i:s');
            }
            // unset($response['responseSnap']);
            DB::commit();

            if ($data->order_type === 'HU_ORDER') {
                $this->travoyService->PaymentRequesthu($data->id);
            }
            return response()->json($response);

            //END REFACTOR
            //CODE DIBWAH INI TIDAK DI EKSEUSI, SEMENTARA UNTUK KOMPARE DATA DENGAN REFACTOR

            // switch ($payment_method->code_name) {
            //     case 'pg_va_mandiri':
            //         $payment_payload = [
            //             "sof_code" => $payment_method->code,
            //             'bill_id' => $data->order_id,
            //             'bill_name' => 'GetPay',
            //             'amount' => (string) $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             "exp_date" => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
            //             "va_type" => "close",
            //             'phone' => $request->customer_phone,
            //             'email' => $request->customer_email,
            //             'customer_name' => $request->customer_name,
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //         ];

            //         $res = PgJmto::vaCreate(
            //             $payment_method->code,
            //             $data->order_id,
            //             'GetPay',
            //             $data->total,
            //             $data->tenant->name ?? 'Travoy',
            //             $request->customer_phone,
            //             $request->customer_email,
            //             $request->customer_name,
            //             $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id
            //         );
            //         if ($res['status'] == 'success') {
            //             $pay = null;
            //             if ($data->payment === null) {
            //                 $pay = new TransPayment();
            //                 $pay->data = $res['responseData'];
            //                 $data->payment()->save($pay);
            //             } else {
            //                 $pay = $data->payment;
            //                 $pay->data = $res['responseData'];
            //                 $pay->save();
            //             }
            //             $data->service_fee = $pay->data['fee'];
            //             $data->total = $data->total + $data->service_fee;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //         } else {
            //             return response()->json([$res], 500);
            //         }
            //         break;

            //     case 'pg_va_bsi':
            //         $payment_payload = [
            //             "sof_code" => $payment_method->code,
            //             'bill_id' => app('env') != 'production' ? random_int(1000, 9999) : $data->order_id,
            //             'bill_name' => 'GetPay',
            //             'amount' => (string) $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             "exp_date" => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
            //             "va_type" => "close",
            //             'phone' => $request->customer_phone,
            //             'email' => $request->customer_email,
            //             'customer_name' => $request->customer_name,
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //         ];

            //         $res = PgJmto::vaCreate(
            //             $payment_method->code,
            //             app('env') != 'production' ? random_int(100000, 999999) : $data->order_id,
            //             'GetPay',
            //             $data->total,
            //             $data->tenant->name ?? 'Travoy',
            //             $request->customer_phone,
            //             $request->customer_email,
            //             $request->customer_name,
            //             $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id
            //         );

            //         if ($res['status'] == 'success') {
            //             $pay = null;
            //             if ($data->payment === null) {
            //                 $pay = new TransPayment();
            //                 $pay->data = $res['responseData'];
            //                 $data->payment()->save($pay);
            //             } else {
            //                 $pay = $data->payment;
            //                 $pay->data = $res['responseData'];
            //                 $pay->save();
            //             }
            //             $data->service_fee = $pay->data['fee'];
            //             $data->total = $data->total + $data->service_fee;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //         } else {
            //             return response()->json([$res], 500);
            //         }
            //         break;


            //     case 'pg_va_bri':
            //         $payment_payload = [
            //             "sof_code" => $payment_method->code,
            //             'bill_id' => $data->order_id,
            //             'bill_name' => 'GetPay',
            //             'amount' => (string) $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             "exp_date" => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
            //             "va_type" => "close",
            //             'phone' => $request->customer_phone,
            //             'email' => $request->customer_email,
            //             'customer_name' => $request->customer_name,
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //         ];
            //         $res = PgJmto::vaCreate(
            //             $payment_method->code,
            //             $data->order_id,
            //             'GetPay',
            //             $data->total,
            //             $data->tenant->name ?? 'Travoy',
            //             $request->customer_phone,
            //             $request->customer_email,
            //             $request->customer_name,
            //             $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id
            //         );

            //         if ($res['status'] == 'success') {
            //             $pay = null;
            //             if ($data->payment === null) {
            //                 $pay = new TransPayment();
            //                 $pay->data = $res['responseData'];
            //                 $data->payment()->save($pay);
            //             } else {
            //                 $pay = $data->payment;
            //                 $pay->data = $res['responseData'];
            //                 $pay->save();
            //             }
            //             $data->service_fee = $pay->data['fee'];
            //             $data->total = $data->total + $data->service_fee;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;

            //             $data->save();
            //         } else {
            //             return response()->json([$res], 500);
            //         }
            //         break;
            //     case 'pg_va_bni':
            //         $payment_payload = [
            //             "sof_code" => $payment_method->code,
            //             'bill_id' => $data->order_id,
            //             'bill_name' => 'GetPay',
            //             'amount' => (string) $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             "exp_date" => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
            //             "va_type" => "close",
            //             'phone' => $request->customer_phone,
            //             'email' => $request->customer_email,
            //             'customer_name' => $request->customer_name,
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //         ];

            //         $res = PgJmto::vaCreate(
            //             $payment_method->code,
            //             $data->order_id,
            //             'GetPay',
            //             $data->total,
            //             $data->tenant->name ?? 'Travoy',
            //             $request->customer_phone,
            //             $request->customer_email,
            //             $request->customer_name,
            //             $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id
            //         );
            //         if ($res['status'] == 'success') {
            //             $pay = null;
            //             if ($data->payment === null) {
            //                 $pay = new TransPayment();
            //                 $pay->data = $res['responseData'];
            //                 $data->payment()->save($pay);
            //             } else {
            //                 $pay = $data->payment;
            //                 $pay->data = $res['responseData'];
            //                 $pay->save();
            //             }
            //             $data->service_fee = $pay->data['fee'];
            //             $data->total = $data->total + $data->service_fee;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //         } else {
            //             return response()->json([$res], 500);
            //         }
            //         break;
            //     case 'tav_qr':


            //         $voucher = Voucher::where('hash', request()->voucher)
            //             ->where('is_active', 1)
            //             ->where('rest_area_id', $data->tenant->rest_area_id)
            //             ->first();

            //         if (!$voucher) {
            //             return response()->json(['error' => 'Voucher tidak ditemukan'], 500);
            //         }

            //         if ($voucher->balance < $data->total) {
            //             return response()->json(['error' => 'Ballance tidak cukup'], 500);
            //         }

            //         $balance_now = $voucher->balance;
            //         $voucher->balance -= $data->total;
            //         $ballaceHistory = [
            //             "trx_id" => $data->id,
            //             "trx_order_id" => $data->order_id,
            //             "trx_type" => 'Belanja',
            //             "trx_area" => $data->tenant ? ($data->tenant->rest_area ? $data->tenant->rest_area->name : '') : '',
            //             "trx_name" => $data->tenant ? $data->tenant->name : '',
            //             "trx_amount" => $data->total,
            //             "current_balance" => $voucher->balance,
            //             "last_balance" => $balance_now,
            //             "datetime" => Carbon::now()->toDateTimeString(),
            //         ];
            //         $dataHistori = $voucher->balance_history;
            //         $dataHistori['data'] = array_merge([$ballaceHistory], $voucher->balance_history['data']);
            //         $dataHistori['current_balance'] = $voucher->balance;
            //         $voucher->balance_history = $dataHistori;
            //         $voucher->qr_code_use = $voucher->qr_code_use + 1;
            //         $voucher->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            //         $voucher->save();

            //         $payment_payload = [
            //             'order_id' => $data->order_id,
            //             'order_name' => 'GetPay',
            //             'amount' => $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             'phone' => $request->customer_phone,
            //             'email' => $request->customer_email,
            //             'customer_name' => $request->customer_name,
            //             'voucher' => $voucher->id
            //         ];
            //         $payment = new TransPayment();
            //         $payment->trans_order_id = $data->id;
            //         $payment->data = $payment_payload;
            //         $data->payment()->save($payment);
            //         $data->total = $data->total + $data->service_fee + $data->addon_total;
            //         $data->status = TransOrder::PAYMENT_SUCCESS;
            //         $data->save();
            //         foreach ($data->detil as $key => $value) {
            //             $this->stock_service->updateStockProduct($value);
            //         }
            //         $this->trans_sharing_service->calculateSharing($data);
            //         $res = $data;

            //         break;

            //     case 'pg_dd_bri':
            //         $bind = Bind::where('id', $request->card_id)->first();
            //         $bind_before = TransPayment::where('trans_order_id', $data->id)->first();

            //         if (!$bind && $request->card_id) {
            //             return response()->json(['message' => 'Card Not Found'], 404);
            //         }
            //         if ($bind) {
            //             if (!$bind->is_valid) {
            //                 return response()->json(['message' => 'Card Not Valid'], 404);
            //             }
            //         }

            //         // dd($bind_before);
            //         // $payment_payload = [
            //         //     "sof_code" => $bind->sof_code ?? $bind_before->data['sof_code'],
            //         //     "bind_id" => $bind->bind_id ?? $bind_before->data['bind_id'],
            //         //     "refnum" => $bind->refnum ?? $bind_before->data['refnum'],
            //         //     "card_no" => $bind->card_no ?? $bind_before->data['card_no'],
            //         //     "amount" => (string) $data->sub_total,
            //         //     "trxid" => $data->order_id,
            //         //     "remarks" => $data->tenant->name ?? 'Travoy',
            //         //     "phone" => $bind->phone ?? $bind_before->data['phone'],
            //         //     "email" => $bind->email ?? $bind_before->data['email'],
            //         //     "customer_name" => $bind->customer_name ?? $bind_before->data['customer_name'],
            //         //     "bill" => (string) $data->sub_total,
            //         //     "fee" => (string) $data->fee,
            //         //     "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id

            //         // ];
            //         $payment_payload = [
            //             "sof_code" => $bind->sof_code ?? $bind_before->data['sof_code'],
            //             "bind_id" => $bind->bind_id ?? $bind_before->data['bind_id'],
            //             "card_no" => $bind->card_no ?? $bind_before->data['card_no'],
            //             "amount" => (string) $data->sub_total,
            //             "trxid" => $data->order_id,
            //             "remarks" => $data->tenant->name ?? 'Travoy',
            //             "phone" => $bind->phone ?? $bind_before->data['phone'],
            //             "email" => $bind->email ?? $bind_before->data['email'],
            //             "fee" => (string) $data->fee,
            //             "customer_name" => $bind->customer_name ?? $bind_before->data['customer_name'],
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id

            //         ];
            //         // log::info('Request DD inquiry => '.$payment_payload);

            //         $respon = PgJmto::inquiryDD($payment_payload);
            //         // log::info($respon);
            //         // log::info('Response DD inquiry => ' . $respon);

            //         if ($respon->successful()) {
            //             $res = $respon->json();
            //             if ($res['status'] == 'ERROR') {
            //                 return response()->json($res, 400);
            //             }
            //             $res['responseData']['bind_id'] = $bind->bind_id;
            //             $res['responseData']['card_id'] = $request->card_id;
            //             $respon = $res['responseData'];
            //             if ($data->payment === null) {
            //                 $payment = new TransPayment();
            //                 $payment->data = $respon;
            //                 $payment->trans_order_id = $data->id;
            //                 $payment->save();
            //             } else {
            //                 $tans_payment = TransPayment::where('trans_order_id', $data->id)->first();
            //                 $tans_payment->data = $respon;
            //                 $tans_payment->save();
            //             }
            //             $data->service_fee = $respon['fee'];
            //             $data->total = $data->sub_total + $data->service_fee + $data->addon_total;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //             DB::commit();
            //             return response()->json($res);
            //         }
            //         return response()->json($respon->json(), 400);
            //         break;


            //     case 'pg_dd_mandiri':
            //         $bind = Bind::where('id', $request->card_id)->first();
            //         $bind_before = TransPayment::where('trans_order_id', $data->id)->first();

            //         if (!$bind && $request->card_id) {
            //             return response()->json(['message' => 'Card Not Found'], 404);
            //         }
            //         if ($bind) {
            //             if (!$bind->is_valid) {
            //                 return response()->json(['message' => 'Card Not Valid'], 404);
            //             }
            //         }


            //         // $payment_payload = [
            //         //     "sof_code" => $bind->sof_code ?? $bind_before->data['sof_code'],
            //         //     "bind_id" => (string) ($bind?->bind_id ?? $bind_before->data['bind_id']),
            //         //     "refnum" => $bind->refnum ?? $bind_before->data['refnum'],
            //         //     "card_no" => $bind->card_no ?? $bind_before->data['card_no'],
            //         //     "amount" => (string) $data->sub_total,
            //         //     "trxid" => $data->order_id,
            //         //     "remarks" => $data->tenant->name ?? 'Travoy',
            //         //     "phone" => $bind->phone ?? $bind_before->data['phone'],
            //         //     "email" => $bind->email ?? $bind_before->data['email'],
            //         //     "customer_name" => $bind->customer_name ?? $bind_before->data['customer_name'],
            //         //     "bill" => (string) $data->sub_total,
            //         //     "fee" => (string) $data->fee,
            //         //     "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id
            //         // ];
            //         $payment_payload = [
            //             "sof_code" => $bind->sof_code ?? $bind_before->data['sof_code'],
            //             "bind_id" => $bind->bind_id ?? $bind_before->data['bind_id'],
            //             "card_no" => $bind->card_no ?? $bind_before->data['card_no'],
            //             "amount" => (string) $data->sub_total,
            //             "trxid" => $data->order_id,
            //             "remarks" => $data->tenant->name ?? 'Travoy',
            //             "phone" => $bind->phone ?? $bind_before->data['phone'],
            //             "email" => $bind->email ?? $bind_before->data['email'],
            //             "customer_name" => $bind->customer_name ?? $bind_before->data['customer_name'],
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id

            //         ];
            //         $respon = PgJmto::inquiryDD($payment_payload);
            //         // log::info($respon);
            //         if ($respon->successful()) {
            //             $res = $respon->json();
            //             if ($res['status'] == 'ERROR') {
            //                 return response()->json($res, 400);
            //             }
            //             $res['responseData']['bind_id'] = $bind->bind_id;
            //             $res['responseData']['card_id'] = $request->card_id;
            //             $respon = $res['responseData'];
            //             if ($data->payment === null) {
            //                 $payment = new TransPayment();
            //                 $payment->data = $respon;
            //                 $payment->trans_order_id = $data->id;
            //                 $payment->save();
            //             } else {
            //                 $tans_payment = TransPayment::where('trans_order_id', $data->id)->first();
            //                 $tans_payment->data = $respon;
            //                 $tans_payment->save();
            //             }
            //             $data->service_fee = $respon['fee'];
            //             $data->total = $data->sub_total + $data->service_fee + $data->addon_total;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //             DB::commit();
            //             return response()->json($res);
            //         }
            //         return response()->json($respon->json(), 400);
            //         break;

            //     case 'pg_link_aja':
            //         $payment_payload = [
            //             "sof_code" => $payment_method->code,
            //             'bill_id' => $data->order_id,
            //             'bill_name' => 'GetPay',
            //             'amount' => (string) $data->total,
            //             'desc' => $data->tenant->name ?? 'Travoy',
            //             "exp_date" => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
            //             "va_type" => "close",
            //             'phone' => $data->tenant->phone,
            //             'email' => $data->tenant->email,
            //             'customer_name' => $data->nomor_name,
            //             "submerchant_id" => $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //         ];

            //         $data_la = TenantLa::where('tenant_id', $data->Tenant->id)->firstOrFail();

            //         $res = LaJmto::qrCreate(
            //             $payment_method->code,
            //             $data->order_id,
            //             'GetPay',
            //             $data->total,
            //             $data->tenant->name ?? 'Travoy',
            //             $data->tenant->phone,
            //             $data->tenant->email,
            //             $data->nomor_name,
            //             $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id,
            //             $data_la
            //         );

            //         if (isset($res['status']) && $res['status'] == 'success') {
            //             $pay = null;
            //             if ($data->payment === null) {
            //                 $pay = new TransPayment();
            //                 $pay->data = $res['responseData'];
            //                 $pay->inquiry = $res;
            //                 $pay->tenant_kriteria = $data_la['merchant_criteria'];
            //                 $data->payment()->save($pay);
            //             } else {
            //                 $pay = $data->payment;
            //                 $pay->data = $res['responseData'];
            //                 $pay->save();
            //             }
            //             $data->service_fee = $pay->data['fee'];
            //             $data->total = $data->total + $data->service_fee;
            //             $data->sub_merchant_id = $data->tenant?->sub_merchant_id ?? $data->sub_merchant_id;
            //             $data->save();
            //         } else {
            //             return response()->json([$res], 500);
            //         }

            //         break;
            //     default:
            //         return response()->json(['error' => $payment_method->name . ' Coming Soon'], 500);

            //         break;
            // }
            // DB::commit();
            // return response()->json($res);
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => $th->getMessage(), $payment_payload], 500);
        }
    }

    public function paymentByOrderId($id)
    {
        $data = TransOrder::findOrfail($id);
        if (!$data->payment) {
            return response()->json(['error' => 'Payment Not Found'], 404);
        }
        return response()->json(new TsPaymentresource($data->payment));
    }

    function post($url, $header, $params = false)
    {
        $curl = curl_init();

        if ($params === false)
            $query = '';
        else
            $query = json_encode($params);

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 800,
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $query,
                CURLOPT_HTTPHEADER => array(
                    $header,
                    'content-type:application/json'
                ),
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0
            )
        );
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            return $err;
        } else {
            return $response;
        }
    }

    public function payKios($data, $id)
    {
        $kios = [];

        if ($data->description == 'single') {
            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
            Log::info(['bayar depan => ', $kios]);
        }
        if ($data->description == 'dual') {
            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
            if ($data->productKiosbank()->integrator == 'JATELINDO') {
                //1. Purchase
                $res_jatelindo = JatelindoService::purchase($data->log_kiosbank->data ?? [], $data);
                $result_jatelindo = $res_jatelindo->json();

                $rc = $result_jatelindo['bit39'] ?? '';

                //2. Cek req timout code 18
                if ($rc == '18') {
                    //3. Advice 1 kali
                    $res_jatelindo = JatelindoService::advice($data->log_kiosbank->data ?? [], $data);
                    $result_jatelindo = $res_jatelindo->json();
                    $rc = $result_jatelindo['bit39'] ?? '';
                    //4. Cek advice timout
                    if ($rc == '18') {
                        //5. Advice Repeate max 3x percobaan
                        $try = 1;
                        do {
                            $res_jatelindo = JatelindoService::repeat($data->log_kiosbank->data ?? [], $data);
                            $result_jatelindo = $res_jatelindo->json();
                            $rc = $result_jatelindo['bit39'] ?? '';
                            $try++;
                            Log::info('RC ' . $try . ' : ' . $rc);
                        } while ($try <= 3 && $rc == '18');
                    }
                }

                if ($rc == '00') {
                    //return token listrik
                    $data->status = TransOrder::DONE;
                    $log_kios = $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                        'data' => $result_jatelindo,
                        'payment' => $result_jatelindo

                    ]);
                    $data->save();
                    DB::commit();
                    $info = JatelindoService::infoPelanggan($log_kios, $data->status);
                    $map = [
                        'status' => $data->status,
                        'kiosbank' => [
                            'data' => $info
                        ]
                    ];
                    return response()->json($map);
                } else {
                    return response()->json(['status' => 422, 'data' => JatelindoService::responseTranslation($result_jatelindo)], 422);
                }
                DB::commit();
                return response()->json(['status' => 422, 'data' => JatelindoService::responseTranslation($result_jatelindo)], 422);
            }
            $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
            $admin = $datalog['data']['data']['adminBank'] ?? $datalog['data']['data']['AB'] ?? '000000000000';
            $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
            $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
            Log::info(['bayar depan => ', $kios]);

        }

        $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
        $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
        // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
        // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
        $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
        $kios['data']['harga_kios'] = $data->harga_kios;
        $kios['data']['harga'] = $data->sub_total ?? '0';

        if ($kios['rc'] == '00') {
            if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                    'data' => $kios,
                    'payment' => $kios,

                ]);
                $data->status = TransOrder::DONE;
                $data->save();
                DB::commit();
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            }
            if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                    'data' => $kios,
                    'payment' => $kios,

                ]);
                $data->status = TransOrder::DONE;
                $data->save();
                DB::commit();
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            } else {
                $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                    'data' => $kios,
                    'payment' => $kios,

                ]);
                $data->status = TransOrder::PAYMENT_SUCCESS;
                $data->save();
                DB::commit();
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            }
            // $data->status = TransOrder::DONE;
            // $data->save();
            // DB::commit();
            // return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
        } else {
            $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                'data' => $kios,
                'payment' => $kios,

            ]);
            $data->status = TransOrder::PAYMENT_SUCCESS;
            $data->save();
            DB::commit();
            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
        }
    }

    public function statusPayment(Request $request, $id)
    {
        $data = TransOrder::with('payment_method')->findOrfail($id);
        try {
            if ($data->tenant_id != env('LET_IT_FLO_TID')) {
                $limit = app(CustomRateLimitMiddleware::class)->handle(
                    $request,
                    function ($request) {
                        // Closure kosong sebagai pemroses berikutnya
                    },
                    'statusPayment:' . $id, // Kunci unik untuk rate limit
                    1,                      // Maksimal 1 request
                    10                      // Dalam waktu 5 menit
                );
                if ($limit[0]) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many requests. Please try again later.',
                        'retry' => $limit[1]
                    ], 429);
                }

            }
            DB::beginTransaction();
            if ($data->status == TransOrder::PAYMENT_SUCCESS || $data->status == TransOrder::DONE || $data->status == TransOrder::READY) {
                $kios = [];
                if ($data->order_type == TransOrder::ORDER_TRAVOY && $data->status != TransOrder::DONE) {

                    if ($data->productKiosbank()->integrator == 'JATELINDO') {
                        $is_success = $data->log_kiosbank->data['is_success'] ?? false;
                        if (!$is_success) {
                            return $this->servicePayment->payKios($data);
                        }
                        return response()->json(['token' => $data->log_kiosbank->data['bit62'] ?? '']);
                    }

                    $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                    $adminBank = $datalog['data']['data']['adminBank'] ?? '000000000000';
                    $refid = $datalog['data']['referenceID'];

                    $kios = $this->kiosBankService->cekStatus($data->sub_total, $data->order_id, $adminBank, $data->harga_kios);
                    $kios['data']['harga_kios'] = $data->harga_kios;
                    $kios['data']['harga'] = $data->sub_total ?? '0';
                    $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '-';
                    if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                            $data->status = TransOrder::DONE;
                        }
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                            $data->status = TransOrder::DONE;
                        } else {
                            // log::info($kios['description'].' casenya masuk sini');
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                        }
                    }
                    // $rc_coll = array('2', '10', '12', '15', '17', '18', '27', '34', '37', '40', '41', '42', '46', '60', '62', '64', '65', '68', '69', '70', '72', '73', '74', '75', '78', '79', '80', '83', '85', '86');
                    $rc_coll = array('x');

                    if (in_array($kios['rc'], $rc_coll)) {

                        //inquiry ulang
                        $ref = explode('-', $data->order_id);
                        $random_id = rand(100000000000, 999999999999);
                        $data->order_id = $ref[0] . '-' . $ref[1] . '-' . $random_id . '-' . Carbon::now()->timestamp;
                        Log::info('REPROCESS --> BEFORE => ' . $ref[2] . 'AFTER => ' . $random_id);

                        $productId = $ref[0];
                        $customerID = $ref[1];
                        $referenceID = (string) $random_id;
                        $data->save();
                        DB::commit();


                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar susulan => ', $kios]);
                        }

                        if ($data->description == 'dual') {
                            $res_json = $this->kiosBankService->reinquiry($productId, $customerID, $referenceID);
                            $res_json = $res_json->json();
                        }

                        if ($data->description == 'dual' && $res_json['rc'] == '00') {
                            if ($res_json['productID'] == '520021' || $res_json['productID'] == '520011') {
                                $data->harga_kios = $res_json['data']['total'];

                                //harga jual

                                $harga_jual_kios = ProductKiosBank::where('kode', $res_json['productID'])->first() ?? $res_json['data']['total'];
                                $data->sub_total = ($harga_jual_kios?->harga ?? 0) + $res_json['data']['total'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';

                                $data->save();
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json,
                                    'payment' => $res_json

                                ]);
                            } else {
                                $data->harga_kios = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'] ?? $data->harga_kios;
                                //harga jual
                                $harga_jual_kios = ProductKiosBank::where('kode', $ref[0])->first();
                                // $order->sub_total = $harga_jual_kios?->harga ?? $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $data->harga_kios;
                                // $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';
                                $res_json['data']['adminBank'] = $res_json['data']['adminBank'] ?? $res_json['data']['AB'] ?? '000000000000';

                                $data->save();
                                // Log::info($data);
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json
                                ]);
                            }

                            //pay ulang
                            // if ($data->description == 'single') {
                            //     $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            //     Log::info(['bayar susulan => ', $kios]);

                            // }
                            if ($data->description == 'dual') {
                                $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                                $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                                $admin = $datalog['data']['data']['adminBank'] ?? '000000000000';
                                $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                                $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                                Log::info(['bayar susulan => ', $kios]);

                            }
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                            // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
                            // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
                            $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                            $kios['data']['harga_kios'] = $data->harga_kios;
                            $kios['data']['harga'] = $data->sub_total ?? '0';

                            if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                } else {
                                    $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::READY;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                            } else {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::PAYMENT_SUCCESS;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            //end pay ulang
                        }
                        // end inquiry ulang
                    }

                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                        'data' => $kios,
                        'payment' => $kios
                    ]);
                    $data->save();
                    DB::commit();
                }
                if ($data->order_type == TransOrder::ORDER_DEREK_ONLINE) {
                    $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                }
                if ($data->order_type == TransOrder::ORDER_HU) {
                    $travoy = $this->travoyService->detailHU($id);
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                }
                if ($data->order_type == TransOrder::ORDER_PARKIR) {
                    // $travoy = $this->travoyService->detailHU($id);
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '']);
                }
                $product_kios_bank = $data->productKiosbank();
                if ($product_kios_bank?->integrator == 'JATELINDO') {
                    $infoPelanggan = JatelindoService::infoPelanggan($data->log_kiosbank, $data);
                    return response()->json([
                        'status' => $data->status,
                        'kiosbank' => [
                            'data' => $infoPelanggan
                        ]
                    ]);
                }
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            }

            if ($data->status != TransOrder::WAITING_PAYMENT) {
                return response()->json(['status' => $data->status, 'responseData' => $data->payment ?? '']);
            }

            if (!$data->payment) {
                return response()->json(['status' => $data->status, 'responseData' => null]);
            }

            $payment_result = $this->servicePayment->statusOrder($data, $request->all());
            if ($payment_result->status != true) {
                return response()->json([
                    ...$payment_result->data,
                    'status' => $data->status
                ], 422);
            }
            if ($data->tenant_id == env('LET_IT_FLO_TID')) {

                $x = $this?->callbackFloServices($data->id);
                Log::info($x);
            }
            $data->save();
            DB::commit();

            if ($data->order_type == TransOrder::ORDER_HU) {
                $travoy = $this->travoyService->detailHU($id);
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
            }
            return response()->json(['status' => $data->status, ...$payment_result->data]);
            //END REFACTOR
            //CODE DIBWAH INI TIDAK DI EKSEUSI, SEMENTARA UNTUK KOMPARE DATA DENGAN REFACTOR
            if ($data->payment_method->code_name == 'pg_dd_bri') {
                $payload = $data_payment;
                $payload['submerchant_id'] = $data->sub_merchant_id;
                $payload['payrefnum'] = $data_payment['refnum'];
                $res = PgJmto::statusDD($payload);
                // $res = PgJmto::paymentDD($payload);

                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }
                    $is_dd_pg_success = $res['responseData']['pay_refnum'] ?? null;
                    if ($is_dd_pg_success == null) {
                        $res['rcm'] = "PENDING";
                        return response()->json([
                            "message" => "PENDING",
                            "errors" => [
                                $res
                            ]
                        ], 201);
                    }

                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }


                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type === TransOrder::ORDER_TRAVOY) {
                        return $this->payKios($data, $id);
                    }
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }

                    $data->save();
                    //Cek Payment kios
                    if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
                    }

                    if ($data->order_type == TransOrder::ORDER_HU) {
                        $data->save();
                        DB::commit();
                        $travoy = $this->travoyService->detailHU($id);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                    }

                    //End payment kios
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return response()->json($data);
                }

                return response()->json($res->json(), 400);
            }

            if ($data->payment_method->code_name == 'pg_dd_mandiri') {
                $payload = $data_payment;
                $payload['submerchant_id'] = $data->sub_merchant_id;
                $payload['payrefnum'] = $data_payment['refnum'];
                $res = PgJmto::statusDD($payload);
                // $res = PgJmto::paymentDD($payload);

                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }

                    $is_dd_pg_success = $res['responseData']['pay_refnum'] ?? null;
                    if ($is_dd_pg_success == null) {
                        return response()->json([
                            "message" => "PENDING",
                            "errors" => [
                                $res
                            ]
                        ], 201);
                    }

                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }


                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type === TransOrder::ORDER_TRAVOY) {
                        return $this->payKios($data, $id);
                    }
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }

                    $data->save();
                    //Cek Payment kios
                    if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
                    }

                    if ($data->order_type === TransOrder::ORDER_HU) {
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailHU($id);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
                    }

                    //End payment kios
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return response()->json($data);
                }

                return response()->json($res->json(), 400);
            }

            if ($data->payment_method->code_name == 'pg_link_aja') {
                $data_la = TenantLa::where('tenant_id', $data->Tenant->id)->firstOrFail();

                $res = LAJmto::qrStatus(
                    $data_payment['bill_id'],
                    $data_la
                );

                if (isset($res['status']) && $res['status'] == 'success') {
                    $res_data = $res['responseData'];
                    $res_data['fee'] = $data_payment['fee'];
                    $kios = [];
                    if ($res_data['pay_status'] === '1') {
                        if ($data->status === TransOrder::WAITING_PAYMENT) {
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            if ($data->order_type === TransOrder::ORDER_TRAVOY) {
                                return $this->payKios($data, $id);
                            }
                        }
                        if ($data->order_type === TransOrder::ORDER_SELF_ORDER || TransOrder::ORDER_TAKE_N_GO) {
                            $fcm_token = User::where([['id', $data->casheer_id]])->get();
                            $ids = array();
                            foreach ($fcm_token as $val) {
                                if ($val['fcm_token'] != null && $val['fcm_token'] != '')
                                    array_push($ids, $val['fcm_token']);
                            }
                            if ($ids != '') {
                                $payload = array(
                                    'id' => $data->id,
                                    'type' => 'click',
                                    'action' => 'payment_success'
                                );
                                $result = sendNotif($ids, '💰Pesanan Telah Dibayar', 'Yuk segera siapkan pesanan atas transaksi ' . $data->order_id, $payload);
                            }
                        }

                        if ($data->order_type === TransOrder::POS) {
                            $data->status = TransOrder::DONE;
                        }
                        $data->save();
                        if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            DB::commit();

                            $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                        }
                        if ($data->order_type === TransOrder::ORDER_HU) {
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            DB::commit();

                            $travoy = $this->travoyService->detailHU($id);
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                        }
                        foreach ($data->detil as $key => $value) {
                            $this->stock_service->updateStockProduct($value);
                        }
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $res_data;
                        $pay->payment = $res_data;
                        $pay->orderid_sof = $res['responseData']?->merchantTrxID;
                        $pay->save();
                    } else {
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                    }
                    $data->payment()->update(['data' => $res_data]);
                } else {
                    return response()->json($res, 500);
                }
                DB::commit();
                return response()->json($res);
            }
            $res = PgJmto::vaStatus(
                $data_payment['sof_code'],
                $data_payment['bill_id'],
                $data_payment['va_number'],
                $data_payment['refnum'],
                $data_payment['phone'],
                $data_payment['email'],
                $data_payment['customer_name'],
                // $data_payment['submerchant_id']
                $data->sub_merchant_id

            );

            if ($res['status'] == 'success') {
                $res_data = $res['responseData'];
                $res_data['fee'] = $data_payment['fee'];
                $res_data['bill'] = $data_payment['bill'];
                $kios = [];
                if ($res_data['pay_status'] === '1') {
                    if ($data->status === TransOrder::WAITING_PAYMENT) {
                        $data->status = TransOrder::PAYMENT_SUCCESS;
                        $data->save();
                        if ($data->order_type === TransOrder::ORDER_TRAVOY) {
                            return $this->payKios($data, $id);
                        }
                        if ($data->order_type === TransOrder::ORDER_SELF_ORDER || TransOrder::ORDER_TAKE_N_GO) {
                            $fcm_token = User::where([['id', $data->casheer_id]])->get();
                            $ids = array();
                            foreach ($fcm_token as $val) {
                                if ($val['fcm_token'] != null && $val['fcm_token'] != '')
                                    array_push($ids, $val['fcm_token']);
                            }
                            if ($ids != '') {
                                $payload = array(
                                    'id' => $data->id,
                                    'type' => 'click',
                                    'action' => 'payment_success'
                                );
                                $result = sendNotif($ids, '💰Pesanan Telah Dibayar', 'Yuk segera siapkan pesanan atas transaksi ' . $data->order_id, $payload);
                            }
                        }
                    }
                    if ($data->order_type === TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                        $data->status = TransOrder::PAYMENT_SUCCESS;
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                    }


                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                } else {
                    // if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                    //     $data->status = TransOrder::PAYMENT_SUCCESS;
                    //     $data->save();
                    //     DB::commit();

                    //     $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                    //     return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);

                    // }
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                }
                $data->payment()->update(['data' => $res_data]);
            }
            DB::commit();
            return response()->json($res);
        } catch (\Throwable $th) {
            // DB::rollBack();
            return response()->json(['error' => (string) $th], 500);

            // return response()->json(['error' => 'Coba Kembali'], 500);
        }
    }

    public function statusPaymentDD(Request $request, $id)
    {
        $data = TransOrder::with('payment_method')->findOrfail($id);
        try {
            DB::beginTransaction();
            if ($data->status == TransOrder::PAYMENT_SUCCESS || $data->status == TransOrder::DONE || $data->status == TransOrder::READY) {
                $kios = [];
                if ($data->order_type == TransOrder::ORDER_TRAVOY) {

                    $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                    $adminBank = $datalog['data']['data']['adminBank'] ?? '000000000000';
                    $refid = $datalog['data']['referenceID'];

                    $kios = $this->kiosBankService->cekStatus($data->sub_total, $data->order_id, $adminBank, $data->harga_kios);
                    $kios['data']['harga_kios'] = $data->harga_kios;
                    $kios['data']['harga'] = $data->sub_total ?? '0';
                    $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '-';
                    if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                            $data->status = TransOrder::DONE;
                        }
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                            $data->status = TransOrder::DONE;
                        } else {
                            $data->status = TransOrder::DONE;
                        }
                    }

                    // if(!$kios['rc'] || $kios['rc'] == '01' || $kios['rc'] == '03' || $kios['rc'] == '04' || $kios['rc'] == '05' || $kios['rc'] == '14' || $kios['rc'] == '19' || $kios['rc'] == '38' || $kios['rc'] == '39' || $kios['rc'] == '67' | $kios['rc'] == '71') {
                    //     // if(str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL'))
                    //     // {
                    //     //     $data->status = TransOrder::DONE;
                    //     // }
                    //     // if(str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES'))
                    //     // {
                    //     //     $data->status = TransOrder::DONE;
                    //     // }
                    //     // else 
                    //     // {
                    //         $data->status = TransOrder::READY;
                    //     // }
                    // }

                    // $rc_coll = array('2', '10', '12', '15', '17', '18', '27', '34', '37', '40', '41', '42', '46', '60', '61', '62', '64', '65', '68', '69', '70', '72', '73', '74', '75', '78', '79', '80', '83', '85', '86');
                    // $rc_coll = array('2', '10', '12', '15', '17', '18', '27', '34', '37', '40', '41', '42', '46', '60', '62', '64', '65', '68', '69', '70', '72', '73', '74', '75', '78', '79', '80', '83', '85', '86');


                    $rc_coll = array('9999');

                    if (in_array($kios['rc'], $rc_coll)) {

                        //inquiry ulang
                        $ref = explode('-', $data->order_id);
                        $random_id = rand(100000000000, 999999999999);
                        $data->order_id = $ref[0] . '-' . $ref[1] . '-' . $random_id . '-' . Carbon::now()->timestamp;
                        Log::info('REPROCESS --> BEFORE => ' . $ref[2] . 'AFTER => ' . $random_id);

                        $productId = $ref[0];
                        $customerID = $ref[1];
                        $referenceID = (string) $random_id;
                        $data->save();
                        DB::commit();


                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar susulan => ', $kios]);
                        }

                        if ($data->description == 'dual') {
                            $res_json = $this->kiosBankService->reinquiry($productId, $customerID, $referenceID);
                            $res_json = $res_json->json();
                        }

                        if ($data->description == 'dual' && $res_json['rc'] == '00') {
                            if ($res_json['productID'] == '520021' || $res_json['productID'] == '520011') {
                                $data->harga_kios = $res_json['data']['total'];

                                //harga jual

                                $harga_jual_kios = ProductKiosBank::where('kode', $res_json['productID'])->first() ?? $res_json['data']['total'];
                                $data->sub_total = ($harga_jual_kios?->harga ?? 0) + $res_json['data']['total'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';

                                $data->save();
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json,
                                    'payment' => $res_json
                                ]);
                            } else {
                                $data->harga_kios = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'] ?? $data->harga_kios;
                                //harga jual
                                $harga_jual_kios = ProductKiosBank::where('kode', $ref[0])->first();
                                // $order->sub_total = $harga_jual_kios?->harga ?? $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $data->harga_kios;
                                // $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';
                                $res_json['data']['adminBank'] = $res_json['data']['adminBank'] ?? $res_json['data']['AB'] ?? '000000000000';

                                $data->save();
                                Log::info($data);
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json,
                                    'payment' => $res_json

                                ]);
                            }

                            //pay ulang
                            // if ($data->description == 'single') {
                            //     $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            //     Log::info(['bayar susulan => ', $kios]);

                            // }
                            if ($data->description == 'dual') {
                                $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                                $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                                $admin = $datalog['data']['data']['adminBank'] ?? '000000000000';
                                $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                                $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                                Log::info(['bayar susulan => ', $kios]);

                            }
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                            // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
                            // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
                            $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                            $kios['data']['harga_kios'] = $data->harga_kios;
                            $kios['data']['harga'] = $data->sub_total ?? '0';

                            if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                } else {
                                    $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::READY;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                            } else {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::PAYMENT_SUCCESS;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            //end pay ulang
                        }
                        // end inquiry ulang
                    }

                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                        'data' => $kios,
                        'payment' => $kios
                    ]);
                    $data->save();
                    DB::commit();
                }
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            }

            if ($data->status != TransOrder::WAITING_PAYMENT) {
                return response()->json(['status' => $data->status, 'responseData' => $data->payment ?? '']);
            }

            if (!$data->payment) {
                return response()->json(['status' => $data->status, 'responseData' => null]);
            }

            $data_payment = $data->payment->data;
            if ($data->payment_method->code_name == 'pg_dd_bri') {
                $payload = $data_payment;
                $payload['submerchant_id'] = $data->sub_merchant_id;
                $payload['payrefnum'] = $data_payment['refnum'];
                $res = PgJmto::statusDD($payload);
                // $res = PgJmto::paymentDD($payload);

                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }
                    $is_dd_pg_success = $res['responseData']['pay_refnum'] ?? null;
                    $res['rcm'] = "PENDING";
                    if ($is_dd_pg_success == null) {
                        return response()->json([
                            "message" => "PENDING",
                            "errors" => [
                                $res
                            ]
                        ], 201);
                    }

                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }


                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    //Cek Payment kios
                    if ($data->order_type == TransOrder::ORDER_TRAVOY && $data->status != TransOrder::DONE) {
                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar depan => ', $kios]);
                        }
                        if ($data->description == 'dual') {
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                            $admin = $datalog['data']['data']['adminBank'] ?? $datalog['data']['data']['AB'] ?? '000000000000';
                            $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                            $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                            Log::info(['bayar depan => ', $kios]);

                        }
                        $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                        $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                        $kios['data']['harga_kios'] = $data->harga_kios;
                        $kios['data']['harga'] = $data->sub_total ?? '0';

                        if ($kios['rc'] == '00') {
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            } else {
                                $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::READY;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            // $data->status = TransOrder::DONE;
                            // $data->save();
                            // DB::commit();
                            // return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        } else {
                            $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                'data' => $kios,
                                'payment' => $kios,

                            ]);
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            DB::commit();
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        }
                    }
                    //End payment kios
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return response()->json($data);
                }

                return response()->json($res->json(), 400);
            }

            if ($data->payment_method->code_name == 'pg_dd_mandiri') {
                if (!$request->otp) {
                    return response()->json([
                        "message" => "The given data was invalid.",
                        "errors" => [
                            "otp" => [
                                "The otp field is required."
                            ]
                        ]
                    ], 422);
                }
                $payload = $data_payment;
                $payload['otp'] = $request->otp;
                // $payload['submerchant_id'] = $data->sub_merchant_id;
                $res = PgJmto::paymentDD($payload);
                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }
                    $is_dd_pg_success = $res['responseData']['pay_refnum'] ?? null;
                    if ($is_dd_pg_success == null || $is_dd_pg_success == "") {
                        $res['rcm'] = "PENDING";
                        return response()->json([
                            "message" => "PENDING",
                            "errors" => [
                                $res
                            ]
                        ], 201);
                    }

                    $res['responseData']['card_id'] = $payload['card_id'] ?? '';
                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }

                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    if ($data->order_type === TransOrder::ORDER_DEREK_ONLINE) {
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailDerek($id, $request->id_user, $request->token);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
                    }
                    if ($data->order_type === TransOrder::ORDER_HU) {
                        $data->save();
                        DB::commit();

                        $travoy = $this->travoyService->detailHU($id);
                        return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'travoy' => $travoy ?? '']);
                    }
                    $data->save();
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return $data;
                }
                return response()->json($res->json(), 400);
            }

            $res = PgJmto::vaStatus(
                $data_payment['sof_code'],
                $data_payment['bill_id'],
                $data_payment['va_number'],
                $data_payment['refnum'],
                $data_payment['phone'],
                $data_payment['email'],
                $data_payment['customer_name'],
                // $data_payment['submerchant_id']
                $data->sub_merchant_id

            );
            if ($res['status'] == 'success') {
                $res_data = $res['responseData'];
                $res_data['fee'] = $data_payment['fee'];
                $res_data['bill'] = $data_payment['bill'];
                $kios = [];

                if ($res_data['pay_status'] === '1') {
                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type === TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    if ($data->order_type === TransOrder::ORDER_TRAVOY && $data->status !== TransOrder::DONE) {
                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar depan => ', $kios]);
                        }
                        if ($data->description == 'dual') {
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                            $admin = $datalog['data']['data']['adminBank'] ?? $datalog['data']['data']['AB'] ?? '000000000000';
                            $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                            $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                            Log::info(['bayar depan => ', $kios]);

                        }
                        $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                        $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                        // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
                        // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
                        $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                        $kios['data']['harga_kios'] = $data->harga_kios;
                        $kios['data']['harga'] = $data->sub_total ?? '0';

                        if ($kios['rc'] == '00') {
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            } else {
                                $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::READY;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            // $data->status = TransOrder::DONE;
                            // $data->save();
                            // DB::commit();
                            // return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        } else {
                            $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                'data' => $kios,
                                'payment' => $kios,

                            ]);
                            $data->status = TransOrder::READY;
                            $data->save();
                            DB::commit();
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        }
                    }
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                } else {
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                }
                $data->payment()->update(['data' => $res_data]);
            }
            DB::commit();
            return response()->json($res);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['error' => (string) $th], 500);
        }
    }
    public function statusPaymentManual(Request $request, $id)
    {
        $data = TransOrder::with('payment_method')->findOrfail($id);
        try {
            DB::beginTransaction();
            if ($data->status == TransOrder::PAYMENT_SUCCESS || $data->status == TransOrder::DONE || $data->status == TransOrder::READY) {
                $kios = [];
                if ($data->order_type == TransOrder::ORDER_TRAVOY) {

                    $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                    $adminBank = $datalog['data']['data']['adminBank'] ?? '000000000000';
                    $refid = $datalog['data']['referenceID'];

                    $kios = $this->kiosBankService->cekStatus($data->sub_total, $data->order_id, $adminBank, $data->harga_kios);
                    $kios['data']['harga_kios'] = $data->harga_kios;
                    $kios['data']['harga'] = $data->sub_total ?? '0';
                    $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '-';
                    if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                            $data->status = TransOrder::DONE;
                        }
                        if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                            $data->status = TransOrder::DONE;
                        } else {
                            $data->status = TransOrder::DONE;
                        }
                    }

                    // if(!$kios['rc'] || $kios['rc'] == '01' || $kios['rc'] == '03' || $kios['rc'] == '04' || $kios['rc'] == '05' || $kios['rc'] == '14' || $kios['rc'] == '19' || $kios['rc'] == '38' || $kios['rc'] == '39' || $kios['rc'] == '67' | $kios['rc'] == '71') {
                    //     // if(str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL'))
                    //     // {
                    //     //     $data->status = TransOrder::DONE;
                    //     // }
                    //     // if(str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES'))
                    //     // {
                    //     //     $data->status = TransOrder::DONE;
                    //     // }
                    //     // else 
                    //     // {
                    //         $data->status = TransOrder::READY;
                    //     // }
                    // }

                    // $rc_coll = array('2', '10', '12', '15', '17', '18', '27', '34', '37', '40', '41', '42', '46', '60', '61', '62', '64', '65', '68', '69', '70', '72', '73', '74', '75', '78', '79', '80', '83', '85', '86', '19');
                    $rc_coll = array('2', '10', '12', '15', '17', '18', '27', '34', '37', '40', '41', '42', '46', '60', '62', '64', '65', '68', '69', '70', '72', '73', '74', '75', '78', '79', '80', '83', '85', '86', '71');


                    // $rc_coll = array('9999');

                    if (in_array($kios['rc'], $rc_coll)) {

                        //inquiry ulang
                        $ref = explode('-', $data->order_id);
                        $random_id = rand(100000000000, 999999999999);
                        $data->order_id = $ref[0] . '-' . $ref[1] . '-' . $random_id . '-' . Carbon::now()->timestamp;
                        Log::info('REPROCESS --> BEFORE => ' . $ref[2] . 'AFTER => ' . $random_id);

                        $productId = $ref[0];
                        $customerID = $ref[1];
                        $referenceID = (string) $random_id;
                        $data->save();
                        DB::commit();


                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar susulan => ', $kios]);
                        }

                        if ($data->description == 'dual') {
                            $res_json = $this->kiosBankService->reinquiry($productId, $customerID, $referenceID);
                            $res_json = $res_json->json();
                        }

                        if ($data->description == 'dual' && $res_json['rc'] == '00') {
                            if ($res_json['productID'] == '520021' || $res_json['productID'] == '520011') {
                                $data->harga_kios = $res_json['data']['total'];

                                //harga jual

                                $harga_jual_kios = ProductKiosBank::where('kode', $res_json['productID'])->first() ?? $res_json['data']['total'];
                                $data->sub_total = ($harga_jual_kios?->harga ?? 0) + $res_json['data']['total'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';

                                $data->save();
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json,
                                    'payment' => $res_json
                                ]);
                            } else {
                                $data->harga_kios = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'] ?? $data->harga_kios;
                                //harga jual
                                $harga_jual_kios = ProductKiosBank::where('kode', $ref[0])->first();
                                // $order->sub_total = $harga_jual_kios?->harga ?? $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $data->total = $data->sub_total + $data->fee;
                                $res_json['data']['harga_kios'] = $data->harga_kios;
                                // $res_json['data']['harga_kios'] = $res_json['data']['harga'] ?? $res_json['data']['total'] ?? $res_json['data']['totalBayar'] ?? $res_json['data']['tagihan'];
                                $res_json['data']['harga'] = $data->sub_total;
                                $res_json['description'] = 'INQUIRY';
                                $res_json['data']['adminBank'] = $res_json['data']['adminBank'] ?? $res_json['data']['AB'] ?? '000000000000';

                                $data->save();
                                Log::info($data);
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $res_json,
                                    'payment' => $res_json
                                ]);
                            }

                            //pay ulang
                            // if ($data->description == 'single') {
                            //     $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            //     Log::info(['bayar susulan => ', $kios]);

                            // }
                            if ($data->description == 'dual') {
                                $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                                $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                                $admin = $datalog['data']['data']['adminBank'] ?? '000000000000';
                                $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                                $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                                Log::info(['bayar susulan => ', $kios]);

                            }
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                            // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
                            // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
                            $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                            $kios['data']['harga_kios'] = $data->harga_kios;
                            $kios['data']['harga'] = $data->sub_total ?? '0';

                            if ($kios['rc'] == '00' || $kios['rc'] == "00" || $kios['rc'] == 00) {
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                                if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::DONE;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                } else {
                                    $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                        'data' => $kios,
                                        'payment' => $kios,

                                    ]);
                                    $data->status = TransOrder::READY;
                                    $data->save();
                                    DB::commit();
                                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                                }
                            } else {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::PAYMENT_SUCCESS;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            //end pay ulang
                        }
                        // end inquiry ulang
                    }

                    $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                        'data' => $kios,
                        'payment' => $kios

                    ]);
                    $data->save();
                    DB::commit();
                }
                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
            }

            if ($data->status != TransOrder::WAITING_PAYMENT) {
                return response()->json(['status' => $data->status, 'responseData' => $data->payment ?? '']);
            }

            if (!$data->payment) {
                return response()->json(['status' => $data->status, 'responseData' => null]);
            }

            $data_payment = $data->payment->data;
            if ($data->payment_method->code_name == 'pg_dd_bri') {
                if (!$request->otp) {
                    return response()->json([
                        "message" => "The given data was invalid.",
                        "errors" => [
                            "otp" => [
                                "The otp field is required."
                            ]
                        ]
                    ], 422);
                }
                $payload = $data_payment;
                $payload['otp'] = $request->otp;
                $payload['submerchant_id'] = $data->sub_merchant_id;
                $res = PgJmto::paymentDD($payload);
                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }
                    $res['responseData']['card_id'] = $payload['card_id'] ?? '';
                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }

                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return $data;
                }

                return response()->json($res->json(), 400);
            }

            if ($data->payment_method->code_name == 'pg_dd_mandiri') {
                if (!$request->otp) {
                    return response()->json([
                        "message" => "The given data was invalid.",
                        "errors" => [
                            "otp" => [
                                "The otp field is required."
                            ]
                        ]
                    ], 422);
                }
                $payload = $data_payment;
                $payload['otp'] = $request->otp;
                $payload['submerchant_id'] = $data->sub_merchant_id;
                $res = PgJmto::paymentDD($payload);
                if ($res->successful()) {
                    $res = $res->json();

                    if ($res['status'] == 'ERROR') {
                        return response()->json([
                            "message" => "ERROR!",
                            "errors" => [
                                $res
                            ]
                        ], 422);
                    }
                    $res['responseData']['card_id'] = $payload['card_id'] ?? '';
                    $respon = $res['responseData'];
                    if ($data->payment === null) {
                        $payment = new TransPayment();
                        $payment->data = $respon;
                        $data->payment()->save($payment);
                    } else {
                        $pay = TransPayment::where('trans_order_id', $data->id)->first();
                        $pay->data = $respon;
                        $pay->save();
                    }

                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                    $this->trans_sharing_service->calculateSharing($data);
                    DB::commit();
                    return $data;
                }
                return response()->json($res->json(), 400);
            }

            $res = PgJmto::vaStatus(
                $data_payment['sof_code'],
                $data_payment['bill_id'],
                $data_payment['va_number'],
                $data_payment['refnum'],
                $data_payment['phone'],
                $data_payment['email'],
                $data_payment['customer_name'],
                // $data_payment['submerchant_id']
                $data->sub_merchant_id

            );
            if ($res['status'] == 'success') {
                $res_data = $res['responseData'];
                $res_data['fee'] = $data_payment['fee'];
                $res_data['bill'] = $data_payment['bill'];
                $kios = [];

                if ($res_data['pay_status'] == '1') {
                    $data->status = TransOrder::PAYMENT_SUCCESS;
                    if ($data->order_type == TransOrder::POS) {
                        $data->status = TransOrder::DONE;
                    }
                    $data->save();
                    if ($data->order_type == TransOrder::ORDER_TRAVOY && $data->status != TransOrder::DONE) {
                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar depan => ', $kios]);
                        }
                        if ($data->description == 'dual') {
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                            $admin = $datalog['data']['data']['adminBank'] ?? $datalog['data']['data']['AB'] ?? '000000000000';
                            $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                            $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                            Log::info(['bayar depan => ', $kios]);

                        }
                        $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                        $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                        // $kios['data']['nama'] = $kios['data']['nama'] ?? $datalog['data']['data']['nama'] ?? '-';
                        // $kios['data']['nominalProduk'] = $kios['data']['nominalProduk'] ?? $datalog['data']['data']['nominalProduk'] ?? '0';
                        $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                        $kios['data']['harga_kios'] = $data->harga_kios;
                        $kios['data']['harga'] = $data->sub_total ?? '0';

                        if ($kios['rc'] == '00') {
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            } else {
                                $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::READY;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            // $data->status = TransOrder::DONE;
                            // $data->save();
                            // DB::commit();
                            // return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        } else {
                            $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                'data' => $kios,
                                'payment' => $kios,

                            ]);
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            DB::commit();
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        }
                    }
                    foreach ($data->detil as $key => $value) {
                        $this->stock_service->updateStockProduct($value);
                    }
                } else {
                    return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                }
                $data->payment()->update(['data' => $res_data]);
            }
            DB::commit();
            return response()->json($res);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['error' => (string) $th], 500);
        }
    }

    public function convernum($phone)
    {

        $phone = request()->phone;
        $Phone = str_replace("+", "", $phone);

        if (substr($Phone, 0, 1) == 0) {
            return $Phone;
        }

        if (substr($Phone, 0, 2) == 62) {
            return substr_replace($Phone, '0', 0, 2);
        }
    }
    public function saldo()
    {
        $phone = $this->convernum(request()->phone);

        $data = Voucher::when($rest_area_id = request()->rest_area_id, function ($q) use ($rest_area_id) {
            return $q->where('rest_area_id', $rest_area_id);
        })
            ->when($username = request()->username, function ($q) use ($username) {
                return $q->where('username', $username);
            })->when($customer_id = request()->customer_id, function ($q) use ($customer_id) {
                return $q->where('customer_id', $customer_id);
            })->when($phone = $this->convernum(request()->phone), function ($q) use ($phone) {
                return $q->where('phone', $phone);
            })->get();
        return response()->json(SaldoResource::collection($data));
    }

    public function verifikasiOrder($id, VerifikasiOrderReqeust $request)
    {
        $data = TransOrder::findOrFail($id);
        if ($data->code_verif == $request->code) {
            $data->status = TransOrder::DONE;
            $data->pickup_date = Carbon::now();
        } else {
            return response()->json([
                "message" => "The given data was invalid.",
                "errors" => [
                    "code" => [
                        "The code is invalid."
                    ]
                ]
            ], 422);
        }
        $data->save();

        return response()->json($data);
    }

    public function absen(Request $request)
    {
        $voucher = Voucher::where('hash', request()->voucher)
            ->where('rest_area_id', request()->rest_area_id)
            ->first();

        // dd(request());
        if ($voucher == null) {
            return response()->json(['message' => 'Scan QR dibatalkan'], 500);
        }
        $dataHistori = $voucher->balance_history;
        $dataHistori['current_balance'] = $voucher->balance;
        $voucher->balance_history = $dataHistori;
        $voucher->qr_code_use = $voucher->qr_code_use + 1;

        if ($voucher->is_active == 1) {
            return response(['message' => 'Mohon Maaf karyawan atas nama ' . $voucher->nama_lengkap . ' Sudah pernah melakukan absen'], 422);
        } else {
            $voucher->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $voucher->is_active = 1;
            $voucher->save();
            return response(['message' => 'Selamat karyawan atas nama ' . $voucher->nama_lengkap . ' Berhasil melakukan Absen'], 200);
        }


    }

    public function callbackDerek(Request $request)
    {
        $data = TransOrder::where('order_type', TransOrder::ORDER_DEREK_ONLINE)->findOrFail($request->id);
        if ($data) {
            $all_sharing_amount = [];
            $all_sharing_proportion = [];
            try {
                foreach (json_decode($data->sharing_code) as $item) {
                    if ($item == 'JMTO') {
                        array_push($all_sharing_amount, $data->sub_total);
                        array_push($all_sharing_proportion, 100);
                    } else {
                        array_push($all_sharing_amount, 0);
                        array_push($all_sharing_proportion, 0);
                    }
                }
                $data->sharing_amount = $all_sharing_amount;
                $data->sharing_proportion = $all_sharing_proportion;
                $data->save();
                return response()->json(['status' => 'success', 'data' => $data,], 200);
            } catch (\Throwable $th) {
                return response()->json(['status' => 'error', 'data' => 'Data Tidak di temukan',], 422);
            }
        }
        return response()->json(['status' => 'error', 'data' => 'Data Tidak di temukan',], 422);
    }

    public function callbackpg(Request $request)
    {
        log::info('callback DD PG', [$request->all()]);
        DB::beginTransaction();
        $trans_payment = TransPayment::where('inquiry', 'like', '%' . $request->originalPartnerRewferenceNo . '%')->first();
        try {
            if ($trans_payment) {
                $data = TransOrder::where('id', $trans_payment->trans_order_id)->firstOrFail();
                if ($request->latestTransactionStatus == '00' && $request->additionalInfo['description'] == 'Transaction completed' && $request->additionalInfo['responseMessage'] == 'SUCCESSFUL' && $request->additionalInfo['responseCode'] == '2005600' && $request->transactionStatusDesc == 'Success') {
                    if ($data->status == TransOrder::PAYMENT_SUCCESS || $data->status == TransOrder::READY || $data->status == TransOrder::DONE) {
                    }
                    $data->status = TransOrder::PAYMENT_SUCCESS;

                    if ($data->order_type == TransOrder::ORDER_TRAVOY) {
                        if ($data->description == 'single') {
                            $kios = $this->kiosBankService->singlePayment($data->sub_total, $data->order_id, $data->harga_kios);
                            Log::info(['bayar depan => ', $kios]);
                        }
                        if ($data->description == 'dual') {
                            $datalog = $data->log_kiosbank()->where('trans_order_id', $id)->first();
                            $tagihan = $datalog['data']['data']['tagihan'] ?? $datalog['data']['data']['harga_kios'];
                            $admin = $datalog['data']['data']['adminBank'] ?? $datalog['data']['data']['AB'] ?? '000000000000';
                            $total = $datalog['data']['data']['total'] ?? $datalog['data']['data']['harga_kios'] ?? $tagihan;
                            $kios = $this->kiosBankService->dualPayment($data->sub_total, $data->order_id, $tagihan, $admin, $total);
                            Log::info(['bayar depan => ', $kios]);

                        }
                        $kios['data']['harga'] = $kios['data']['harga'] ?? ($data->sub_total ?? '0');
                        $kios['description'] = $kios['description'] ?? $kios['data']['status'] ?? $kios['data']['description'] ?? '';
                        $kios['data']['harga_kios'] = $data->harga_kios;
                        $kios['data']['harga'] = $data->sub_total ?? '0';

                        if ($kios['rc'] == '00') {
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'BERHASIL')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            if (str_contains($kios['description'] ?? $kios['data']['status'], 'SUKSES')) {
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::DONE;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            } else {
                                $kios['description'] = $kios['description'] ?? $kios['data']['description'];
                                $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                    'data' => $kios,
                                    'payment' => $kios,

                                ]);
                                $data->status = TransOrder::READY;
                                $data->save();
                                DB::commit();
                                return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                            }
                            // $data->status = TransOrder::DONE;
                            // $data->save();
                            // DB::commit();
                            // return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        } else {
                            $data->log_kiosbank()->updateOrCreate(['trans_order_id' => $data->id], [
                                'data' => $kios,
                                'payment' => $kios,

                            ]);
                            $data->status = TransOrder::PAYMENT_SUCCESS;
                            $data->save();
                            DB::commit();
                            return response()->json(['status' => $data->status, 'responseData' => $data->payment->data ?? '', 'kiosbank' => $kios]);
                        }
                    }
                    // $data->save();
                }
                $trans_payment->update(['data' => $request->all(), 'payment' => $request->all()]);
            }
            DB::commit();
            return response()->json(['status' => 'success', 'data' => 'Data di Terima'], 200);
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['error' => $th->getMessage()], 500);
        }

    }

    public function infoPln(Request $request)
    {
        return $this->response(JatelindoService::infoPln($request->meter_id));
    }

    public function repeateManual($id)
    {
        return $this->response($this->servicePayment->repeatManual($id));
    }

    public function callbackFlo(Request $request)
    {
        $fake = $request?->fake;
        if ($fake) {
            return response()->json(['rc' => '00', 'message' => 'Request Berhasil'], 200);
        } else {
            try {
                $data = TransOrder::findOrfail($request->trans_order_id);
                $callBack = new TsOrderResourceFlo($data);
                $payload = $callBack->toArray($request);
                $response = Http::withHeaders([
                ])
                    // ->withBody(json_encode($payload), 'Application/json')
                    ->timeout(10)
                    ->retry(1, 100)
                    ->withoutVerifying()
                    ->post(env('URL_FLO') . '/handle-payment-callback', $payload);
                clock()->event("flo/handle-payment-callback")->end();
                Log::info(['Payload FLO =>', $payload, 'Response => ', $response ?? 'ERROR']);

                if ($response->status() === 200) {
                    return $response->json();
                } else {
                    return response()->json(['rc' => '500', 'message' => 'Request Gagal Hubungi Customer Care', 'response' => $response->json()], 501);

                }

            } catch (\Exception $e) {
                Log::info(['Payload FLO =>', $payload, 'E => ', $e ?? 'ERROR']);
                return response()->json(['rc' => '500', 'message' => 'Request Gagal Hubungi Customer Care', 'e' => $e], 500);

            }
        }


    }

    public function callbackFloServices($id)
    {

        try {
            $data = TransOrder::findOrfail($id);
            $callBack = new TsOrderResourceFlo($data);
            $payload = $callBack->toArray($id);
            $response = Http::withHeaders([
            ])
                // ->withBody(json_encode($payload), 'Application/json')
                ->timeout(10)
                ->retry(1, 100)
                ->withoutVerifying()
                ->post(env('URL_FLO') . '/handle-payment-callback', $payload);
            clock()->event("flo/handle-payment-callback")->end();
            Log::info(['Payload FLO =>', $payload, 'Response => ', $response ?? 'ERROR']);

            // dd($response->json());
            if ($response->status() === 200) {
                Log::info(['callback' => 'FLO', 'response' => $response->json()]);
                return true;
            } else {
                return false;
                // return response()->json(['rc' => '500','message' => 'Request Gagal Hubungi Customer Care','response'=>$response->json()], 501);

            }

        } catch (\Exception $e) {
            Log::info(['Payload FLO =>', $payload, 'E => ', $e ?? 'ERROR']);
            // return response()->json(['rc' => '500','message' => 'Request Gagal Hubungi Customer Care','e'=>$e], 500);
            return false;

        }



    }
}