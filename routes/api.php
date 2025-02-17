<?php

use App\Http\Controllers\API\CategoryTenantController;
use App\Http\Controllers\API\KiosBank\KiosBankController;
use App\Http\Controllers\API\NumberSaveController;
use App\Models\PgJmto;
use App\Services\External\KiosBankService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->group(function () {
    Route::get('/profile', [App\Http\Controllers\API\AuthController::class, 'profile']);
    Route::post('/profile', [App\Http\Controllers\API\AuthController::class, 'updateProfile']);
    Route::post('/pin', [App\Http\Controllers\API\AuthController::class, 'pinStore']);
    Route::post('/reset-pin', [App\Http\Controllers\API\AuthController::class, 'resetPin']);
    Route::post('/open-cashier', [App\Http\Controllers\API\AuthController::class, 'openCashier']);
    Route::post('/cek-pin', [App\Http\Controllers\API\AuthController::class, 'cekPin']);

    Route::post('/check-open-cashier', [App\Http\Controllers\API\AuthController::class, 'checkOpenCashier']);
    Route::post('/open-tenant', [App\Http\Controllers\API\AuthController::class, 'bukaToko']);
    Route::post('/close-tenant', [App\Http\Controllers\API\AuthController::class, 'tutupToko']);
    Route::post('/close-cashier', [App\Http\Controllers\API\AuthController::class, 'closeCashier']);
    Route::get('/get-rating', [App\Http\Controllers\API\AuthController::class, 'getRating']);
    Route::post('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'index']);

    Route::post('/logout', [App\Http\Controllers\API\AuthController::class, 'logout']);
    Route::post('/rest-area/update-status', [App\Http\Controllers\API\RestAreaController::class, 'updateStatus']);
    Route::apiResource('rest-area', App\Http\Controllers\API\RestAreaController::class);
    Route::apiResource('business', App\Http\Controllers\API\BusinessController::class);
    Route::post('tenant/export', [App\Http\Controllers\API\TenantController::class, 'export']);
    Route::post('tenant/setting-resi', [App\Http\Controllers\API\TenantController::class, 'settingResi']);
    Route::apiResource('tenant', App\Http\Controllers\API\TenantController::class);
    Route::apiResource('supertenant', App\Http\Controllers\API\SupertenantController::class);

    Route::prefix('category-tenant')->controller(CategoryTenantController::class)->group(function(){
        Route::get('/','index');
        Route::get('/{id}','show');
        Route::post('/','store');
        Route::post('/{id}','update');
        Route::delete('/{id}','delete');
    });

    Route::post('/tenant/buka-tutup-toko', [App\Http\Controllers\API\TenantController::class, 'bukaTutupToko']);
    Route::get('/set-payment', [App\Http\Controllers\API\TenantController::class, 'setPayment']);
    Route::get('/set-feature', [App\Http\Controllers\API\TenantController::class, 'setFeature']);
    Route::get('/saw-feature', [App\Http\Controllers\API\TenantController::class, 'sawFeature']);


    Route::post('/product/update-status', [App\Http\Controllers\API\ProductController::class, 'updateStatus']);
    Route::apiResource('product', App\Http\Controllers\API\ProductController::class);

    Route::post('/payment-method/rule/', [App\Http\Controllers\API\PaymentMethodController::class,'storeRule']);
    Route::post('/payment-method/rule/{id}', [App\Http\Controllers\API\PaymentMethodController::class,'updateRule']);
    Route::delete('/payment-method/rule/{id}', [App\Http\Controllers\API\PaymentMethodController::class,'deleteRule']);
    Route::get('/payment-method', [App\Http\Controllers\API\PaymentMethodController::class,'index']);
    Route::get('/payment-sof', [App\Http\Controllers\API\PaymentMethodController::class,'indexSof']);
    Route::get('/payment-method/{id}', [App\Http\Controllers\API\PaymentMethodController::class,'show']);
    Route::post('/payment-method', [App\Http\Controllers\API\PaymentMethodController::class,'store']);
    Route::post('/payment-method/{id}', [App\Http\Controllers\API\PaymentMethodController::class,'update']);
    Route::delete('/payment-method/{id}', [App\Http\Controllers\API\PaymentMethodController::class,'destroy']);

    Route::apiResource('paystation', App\Http\Controllers\API\PaystationController::class);
    Route::apiResource('voucher', App\Http\Controllers\API\VoucherController::class);
    Route::apiResource('category', App\Http\Controllers\API\CategoryController::class);
    Route::post('/user/approve-reset-pin/{id}', [App\Http\Controllers\API\UserController::class, 'approveResetPin']);
    Route::post('/user/reject-reset-pin/{id}', [App\Http\Controllers\API\UserController::class, 'rejectResetPin']);
    Route::post('/user/activation/{id}', [App\Http\Controllers\API\UserController::class, 'activationUserCashier']);
    Route::post('/user/export', [App\Http\Controllers\API\UserController::class, 'export']);
    Route::post('/user/export-pin', [App\Http\Controllers\API\UserController::class, 'exportReqPin']);
    Route::apiResource('user', App\Http\Controllers\API\UserController::class);
    Route::apiResource('ruas', App\Http\Controllers\API\RuasController::class);
    Route::get('/rekap-pendapatan', [App\Http\Controllers\API\RekapPendapatanController::class, 'index']);
    Route::get('/laporan-rekap-transaksi', [App\Http\Controllers\API\LaporanRekapTransaksiController::class, 'index']);
    Route::get('/laporan-rekap-transaksi/rekap/{id}', [App\Http\Controllers\API\LaporanRekapTransaksiController::class, 'showRekap']);
    Route::post('/laporan-rekap-transaksi/transaksi/{id}', [App\Http\Controllers\API\LaporanRekapTransaksiController::class, 'showTransaksi']);
    Route::get('/laporan-rekap-transaksi/download/{id}', [App\Http\Controllers\API\LaporanRekapTransaksiController::class, 'download']);

    Route::post('/laporan/penjualan', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanPenjualan']);
    Route::post('/penjualan', [App\Http\Controllers\API\LaporanController::class, 'laporanPenjualan']);

    Route::post('/laporan/operational', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanOperational']);
    Route::post('/operational', [App\Http\Controllers\API\LaporanController::class, 'laporanOperational']);

    Route::post('/laporan/penjualan-kategori', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanPenjualanKategori']);
    Route::post('/penjualan-kategori', [App\Http\Controllers\API\LaporanController::class, 'laporanPenjualanKategori']);

    Route::post('/laporan/metode-pembayaran', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanMetodePembayaran']);
    Route::post('/metode-pembayaran', [App\Http\Controllers\API\LaporanController::class, 'laporanMetodePembayaran']);

    Route::post('/laporan/invoice', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanInvoice']);
    Route::post('/rekon/list', [App\Http\Controllers\API\LaporanController::class, 'listRekon']);
    Route::post('/rekon/upload', [App\Http\Controllers\API\LaporanController::class, 'UploadRekon']);



    Route::post('/laporan/transaksi', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanTransaksi']);
    Route::post('/transaksi', [App\Http\Controllers\API\LaporanController::class, 'laporanTransaksi']);

    Route::post('/laporan/product-favorit', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanProductFavorit']);
    Route::post('/laporan/jenis-transaksi', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanJenisTransaksi']);
    Route::post('/laporan/rest-area', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanRestArea']);
    Route::post('/laporan/tenant', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanTenant']);
    Route::post('/laporan/customer-travoy', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanCustomerTravoy']);
    Route::post('/laporan/customer-tavqr', [App\Http\Controllers\API\LaporanController::class, 'downloadLaporanCustomerTavqr']);

    Route::post('sharing/list', [App\Http\Controllers\API\SharingController::class, 'index']);
    Route::post('sharing', [App\Http\Controllers\API\SharingController::class, 'store']);
    Route::get('/sharing/{id}', [App\Http\Controllers\API\SharingController::class, 'show']);
    Route::post('/sharing/{id}', [App\Http\Controllers\API\SharingController::class, 'update']);
    Route::delete('/sharing/{id}', [App\Http\Controllers\API\SharingController::class, 'destroy']);
    Route::delete('/sharing/{id}', [App\Http\Controllers\API\SharingController::class, 'destroy']);

    Route::get('trans-sharing', [App\Http\Controllers\API\TransSharingController::class, 'index']);
    Route::get('trans-sharing/{id}', [App\Http\Controllers\API\TransSharingController::class, 'show']);

    Route::get('extra-price', [App\Http\Controllers\API\ExtraPriceController::class,'index']);
    Route::get('extra-price/{id}', [App\Http\Controllers\API\ExtraPriceController::class,'show']);
    Route::post('extra-price/{id}/status', [App\Http\Controllers\API\ExtraPriceController::class, 'changeStatus']);
    Route::post('extra-price', [App\Http\Controllers\API\ExtraPriceController::class,'store']);
    Route::post('extra-price/{id}', [App\Http\Controllers\API\ExtraPriceController::class,'update']);
    Route::delete('extra-price/{id}', [App\Http\Controllers\API\ExtraPriceController::class,'destroy']);

    Route::get('number-table', [App\Http\Controllers\API\NumberTableController::class,'index']);
    Route::get('number-table/{id}', [App\Http\Controllers\API\NumberTableController::class,'show']);
    Route::get('number-table/{id}/qr', [App\Http\Controllers\API\NumberTableController::class,'showQr']);
    Route::post('number-table', [App\Http\Controllers\API\NumberTableController::class,'store']);
    Route::post('number-table/{id}', [App\Http\Controllers\API\NumberTableController::class,'update']);
    Route::delete('number-table/{id}', [App\Http\Controllers\API\NumberTableController::class,'destroy']);

    Route::get('tenant-terpadu', [App\Http\Controllers\API\TenantTerpaduController::class,'index']);
    Route::get('tenant-terpadu/member', [App\Http\Controllers\API\TenantTerpaduController::class,'indexMember']);
    Route::get('tenant-terpadu/member-laporan', [App\Http\Controllers\API\TenantTerpaduController::class,'indexMemberLaporan']);

    Route::get('tenant-terpadu/{id}', [App\Http\Controllers\API\TenantTerpaduController::class,'show']);
    Route::post('tenant-terpadu/set-supertenant/{id}', [App\Http\Controllers\API\TenantTerpaduController::class,'setSuperTenant']);
    Route::post('tenant-terpadu/{id}', [App\Http\Controllers\API\TenantTerpaduController::class,'store']);
    Route::post('tenant-terpadu-unbind/{id}', [App\Http\Controllers\API\TenantTerpaduController::class,'unbind']);
    

    Route::prefix('tavsir')->group(function () {
        #Supertenant
        Route::get('/tenant-supertenant', [App\Http\Controllers\API\TavsirController::class, 'tenantSupertenantList']);
        Route::post('/tenant-supertenant-close', [App\Http\Controllers\API\TavsirController::class, 'closeTenantSupertenant']);
        Route::get('/tenant-supertenant-product', [App\Http\Controllers\API\TavsirController::class, 'productSupertenantList']);
        Route::get('/tenant-supertenant-order', [App\Http\Controllers\API\TavsirController::class, 'orderListSupertenant']);
        Route::post('/tenant-supertenant-order', [App\Http\Controllers\API\TavsirController::class, 'orderSuperTenant']);
        Route::get('/tenant-supertenant-order/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderByIdSupertenant']);
        Route::get('/tenant-supertenant-order-member', [App\Http\Controllers\API\TavsirController::class, 'orderListMemberSupertenant']);
        Route::get('/tenant-supertenant-order-member/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderByIdMemberSupertenant']);
        Route::post('/tenant-supertenant-order-confirm', [App\Http\Controllers\API\TavsirController::class, 'confirmOrderMemberSupertenant']);
        Route::post('/tenant-supertenant-order-done/{id}', [App\Http\Controllers\API\TavsirController::class, 'doneOrderMemberSupertenant']);
        Route::post('/tenant-supertenant-order-refund/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderRefund']);
        
        #EndSupertenant
        
        Route::post('/product/change-status', [App\Http\Controllers\API\TavsirController::class, 'updateStatusProduct']);
        Route::get('/product', [App\Http\Controllers\API\TavsirController::class, 'productList']);
        Route::post('/send-notif', [App\Http\Controllers\API\TavsirController::class, 'sendNotif']);
        Route::get('/product/{id}', [App\Http\Controllers\API\TavsirController::class, 'productShow']);
        Route::post('/product', [App\Http\Controllers\API\TavsirController::class, 'productStore']);
        Route::put('/product/{id}', [App\Http\Controllers\API\TavsirController::class, 'productUpdate']);
        Route::delete('/product/{id}', [App\Http\Controllers\API\TavsirController::class, 'productDestroy']);
        Route::get('/category', [App\Http\Controllers\API\TavsirController::class, 'categoryList']);
        Route::get('/category/{category}', [App\Http\Controllers\API\TavsirController::class, 'categoryShow']);
        Route::post('/category', [App\Http\Controllers\API\TavsirController::class, 'categoryStore']);
        Route::put('/category/{category}', [App\Http\Controllers\API\TavsirController::class, 'categoryUpdate']);
        Route::delete('/category/{category}', [App\Http\Controllers\API\TavsirController::class, 'categoryDestroy']);
        Route::get('/countnewtng', [App\Http\Controllers\API\TavsirController::class, 'countNewTNG']);
        Route::post('/cart-delete', [App\Http\Controllers\API\TavsirController::class, 'cartDelete']);
        Route::get('/count-cart-saved', [App\Http\Controllers\API\TavsirController::class, 'countCarSaved']);
        Route::get('/order/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderById']);
        Route::get('/order-list', [App\Http\Controllers\API\TavsirController::class, 'orderList']);
        Route::get('/order-list-derek', [App\Http\Controllers\API\TavsirController::class, 'orderListDerek']);

        Route::get('/order-history', [App\Http\Controllers\API\TavsirController::class, 'orderHistory']);
        Route::post('/order', [App\Http\Controllers\API\TavsirController::class, 'order']);
        Route::post('/order-confirmation/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderConfirm']);
        Route::get('/payment-method', [App\Http\Controllers\API\TavsirController::class, 'paymentMethod']);
        Route::get('/bank', [App\Http\Controllers\API\TavsirController::class, 'bank']);
        Route::post('/payment-order', [App\Http\Controllers\API\TavsirController::class, 'paymentOrder']);
        Route::post('/create-payment/{id}', [App\Http\Controllers\API\TavsirController::class, 'createPayment']);
        Route::get('/payment-status/{id}', [App\Http\Controllers\API\TavsirController::class, 'statusPayment']);
        // Route::post('/CallbackLinkAjaQRIS', [App\Http\Controllers\API\TavsirController::class, 'CallbackLinkAjaQRIS']);
        Route::post('/refund/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderRefund']);
        Route::post('/identify/{id}', [App\Http\Controllers\API\TavsirController::class, 'orderIdentifier']);

        Route::apiResource('customize', App\Http\Controllers\API\CustomizeController::class);
        Route::post('/order-change-status/{id}', [App\Http\Controllers\API\TavsirController::class, 'changeStatusOrder']);
        Route::get('/invoice', [App\Http\Controllers\API\InvoiceController::class, 'index']);
        Route::post('/invoice-list-derek', [App\Http\Controllers\API\InvoiceController::class, 'indexDerek']);

        Route::get('/invoice/{id}', [App\Http\Controllers\API\InvoiceController::class, 'show']);
        Route::post('/invoice', [App\Http\Controllers\API\InvoiceController::class, 'store']);
        Route::post('/invoice-derek', [App\Http\Controllers\API\InvoiceController::class, 'storeDerek']);
        Route::post('/invoice-paid/{id}', [App\Http\Controllers\API\InvoiceController::class, 'paid']);
        Route::post('/invoice-paid-derek/{id}', [App\Http\Controllers\API\InvoiceController::class, 'paidInvoiceDerek']);

        Route::post('/subscription/aktivasi/{id}', [App\Http\Controllers\API\SubscriptionController::class, 'aktivasi']);
        Route::post('/subscription/reject/{id}', [App\Http\Controllers\API\SubscriptionController::class, 'reject']);
        Route::post('/subscription/kuota-kasir', [App\Http\Controllers\API\SubscriptionController::class, 'kuotaKasirTenant']);
        Route::get('/subscription/tenant-owner', [App\Http\Controllers\API\SubscriptionController::class, 'showMemberTenantOwner']);
        Route::get('/subscription/tenant-cashier/{id?}', [App\Http\Controllers\API\SubscriptionController::class, 'showKasirTenant']);
        // Route::get('/subscription/tenant-cashier/{id?}',[App\Http\Controllers\API\SubscriptionController::class, 'showKasirTenant']);
        
        Route::post('/subscription/mapping-tenant', [App\Http\Controllers\API\SubscriptionController::class, 'maapingSubscriptionTenant']);
        Route::post('/subscription/mapping-kasir', [App\Http\Controllers\API\SubscriptionController::class, 'maapingSubscriptionKasir']);
        Route::get('/subscription', [App\Http\Controllers\API\SubscriptionController::class, 'index']);
        Route::get('/subscription/{id}', [App\Http\Controllers\API\SubscriptionController::class, 'show']);
        Route::post('/subscription', [App\Http\Controllers\API\SubscriptionController::class, 'store']);
        Route::post('/subscription/{id}/extend', [App\Http\Controllers\API\SubscriptionController::class, 'extend']);
        Route::get('/subscription/{id}/price', [App\Http\Controllers\API\SubscriptionController::class, 'price']);
        Route::post('/subscription/{id}/document', [App\Http\Controllers\API\SubscriptionController::class, 'document']);

        Route::prefix('stock')->group(function () {
            Route::get('/kartu-stock', [App\Http\Controllers\API\StockController::class, 'indexKartu']);
            Route::get('/kartu-stock/{id}', [App\Http\Controllers\API\StockController::class, 'kartuShow']);

            Route::get('/masuk', [App\Http\Controllers\API\StockController::class, 'indexMasuk']);
            Route::post('/masuk', [App\Http\Controllers\API\StockController::class, 'storeMasuk']);

            Route::get('/keluar', [App\Http\Controllers\API\StockController::class, 'indexKeluar']);
            Route::post('/keluar', [App\Http\Controllers\API\StockController::class, 'storeKeluar']);

            Route::post('/change-status/{id}', [App\Http\Controllers\API\StockController::class, 'changeStatus']);
            Route::post('/download-template', [App\Http\Controllers\API\StockController::class, 'downloadTemplateImport']);
            Route::post('/import', [App\Http\Controllers\API\StockController::class, 'importStock']);
        });
    });

    Route::prefix('tavsir/tng')->group(function () {
        Route::post('/tenant-order', [App\Http\Controllers\API\TavsirTnGController::class, 'tenantOrder']);
        Route::post('/tenant-order/{id}', [App\Http\Controllers\API\TavsirTnGController::class, 'tenantOrderDetail']);
        Route::post('/order-ready/{id}', [App\Http\Controllers\API\TavsirTnGController::class, 'orderReady']);
        Route::post('/order-verif/{id}', [App\Http\Controllers\API\TavsirTnGController::class, 'orderVerification']);
    });
});

Route::post('/tavsir/manual/{id}', [App\Http\Controllers\API\TavsirController::class, 'manualArsip']);
Route::post('/tavsir/log-order/{id}', [App\Http\Controllers\API\TavsirController::class, 'logArsip']);

Route::get('/tavsir/UpdateSharingMode', [App\Http\Controllers\API\TavsirController::class, 'UpdateSharingMode']);



Route::post('/login', [App\Http\Controllers\API\AuthController::class, 'login']);
// Route::post('/lupa-password', [App\Http\Controllers\API\UserController::class, 'forgetPass']);
// Route::post('/reset-password', [App\Http\Controllers\API\UserController::class, 'resetPass']);


Route::prefix('travshop')->group(function () {
    Route::get('/number-table', [App\Http\Controllers\API\TravShopController::class,'tableList']);
    Route::post('/rest-area', [App\Http\Controllers\API\TravShopController::class, 'restArea']);
    Route::get('/tenant/{id}', [App\Http\Controllers\API\TravShopController::class, 'tenantById']);
    Route::get('/tenant-category', [App\Http\Controllers\API\TravShopController::class, 'tenantByCategory']);
    Route::get('/product-category-tenant/{tenant_id}', [App\Http\Controllers\API\TravShopController::class, 'categoryProductTenant']);
    Route::post('/tenant', [App\Http\Controllers\API\TravShopController::class, 'tenant']);
    Route::post('/product', [App\Http\Controllers\API\TravShopController::class, 'product']);
    Route::get('/extra-price/{id}', [App\Http\Controllers\API\TravShopController::class, 'extraPrice']);
    Route::get('/product/{id}', [App\Http\Controllers\API\TravShopController::class, 'productById']);
    Route::post('/order', [App\Http\Controllers\API\TravShopController::class, 'order']);
    Route::post('/self-order', [App\Http\Controllers\API\TravShopController::class, 'selfOrder']);
    Route::post('/derek-order', [App\Http\Controllers\API\TravShopController::class, 'derekOrder']);
    Route::post('/flo-order', [App\Http\Controllers\API\TravShopController::class, 'floOrder']);
    Route::post('/parkir-order', [App\Http\Controllers\API\TravShopController::class, 'parkirOrder']);
    Route::post('/hu-order', [App\Http\Controllers\API\TravShopController::class, 'huOrder']);

    

    Route::get('/order/{id}', [App\Http\Controllers\API\TravShopController::class, 'orderById']);
    Route::get('/order-meja/{id}', [App\Http\Controllers\API\TravShopController::class, 'orderByMeja']);
    Route::get('/order-list', [App\Http\Controllers\API\TravShopController::class, 'orderList']);

    Route::get('/order-customer/{id}', [App\Http\Controllers\API\TravShopController::class, 'orderCustomer']);
    Route::get('/queue-payment/{id}', [App\Http\Controllers\API\TravShopController::class, 'paymentonCasheer']);
    Route::post('/order-confirmation/{id}', [App\Http\Controllers\API\TravShopController::class, 'orderConfirm']);
    Route::post('/order-cancel/{id}', [App\Http\Controllers\API\TravShopController::class, 'orderCancel']);
    Route::get('/payment-method', [App\Http\Controllers\API\TravShopController::class, 'paymentMethod']);
    // Route::post('/create-payment/{id}', [App\Http\Controllers\API\TravShopController::class, 'createPayment']);
    Route::get('/payment-order/{id}', [App\Http\Controllers\API\TravShopController::class, 'paymentByOrderId']);
    // Route::get('/payment-status/{id}', [App\Http\Controllers\API\TravShopController::class, 'statusPayment']);

    Route::get('/payment-status-manual/{id}', [App\Http\Controllers\API\TravShopController::class, 'statusPaymentManual']);
    Route::get('/payment-status-dd/{id}', [App\Http\Controllers\API\TravShopController::class, 'statusPaymentDD']);

    Route::post('/absen', [App\Http\Controllers\API\TravShopController::class, 'absen']);


    Route::post('/saldo', [App\Http\Controllers\API\TravShopController::class, 'saldo']);
    Route::post('/rating/{id}', [App\Http\Controllers\API\RatingController::class, 'store']);
    Route::post('/order-verification/{id}', [App\Http\Controllers\API\TravShopController::class, 'verifikasiOrder']);

    Route::controller(NumberSaveController::class)->prefix('number-save')->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show');
        Route::post('/{id}', 'update');
        Route::delete('/{id}', 'destroy');
    });

    Route::post('/info-pelanggan', [App\Http\Controllers\API\TravShopController::class, 'infoPln']);
    Route::post('/repeate-manual/{id}', [App\Http\Controllers\API\TravShopController::class, 'repeateManual']);
});

// ratelimit
// Route::middleware('customRateLimit:key,1,5')->group(function () {
//     Route::get('/travshop/payment-status/{id}', [App\Http\Controllers\API\TravShopController::class, 'statusPayment']);
// });

// ratelimit
Route::middleware('customRateLimit:key,1,5')->group(function () {
    Route::post('/travshop/create-payment/{id}', [App\Http\Controllers\API\TravShopController::class, 'createPayment']);
});


Route::get('/travshop/payment-status/{id}', [App\Http\Controllers\API\TravShopController::class, 'statusPayment']);


// Route::middleware('customRateLimit:key,10,60')->group(function () {
    Route::post('/reset-password', [App\Http\Controllers\API\UserController::class, 'resetPass']);
    Route::post('/lupa-password', [App\Http\Controllers\API\UserController::class, 'forgetPass']);
// });


Route::post('/handle-payment-callback', [App\Http\Controllers\API\TravShopController::class, 'callbackFlo']);

Route::get('/card', [App\Http\Controllers\API\CardController::class, 'index']);
Route::post('/card', [App\Http\Controllers\API\CardController::class, 'bind']);
Route::post('/card/rebind/{id}', [App\Http\Controllers\API\CardController::class, 'rebind']);
Route::put('/card/{id}', [App\Http\Controllers\API\CardController::class, 'bindValidate']);
Route::delete('/card/{id}', [App\Http\Controllers\API\CardController::class, 'unBind']);
Route::delete('/card-old/{id}', [App\Http\Controllers\API\CardController::class, 'unBindOld']);

Route::get('chat', [App\Http\Controllers\API\ChatController::class, 'index']);
Route::get('chat/{chat}', [App\Http\Controllers\API\ChatController::class, 'show']);
Route::post('chat', [App\Http\Controllers\API\ChatController::class, 'store']);
Route::post('chat/{chat}', [App\Http\Controllers\API\ChatController::class, 'read']);

Route::post('/send-email/{order}', [App\Http\Controllers\API\SendController::class, 'mail']);

Route::post('payment-gateway/sof/list', [App\Http\Controllers\API\PaymentGatewayController::class, 'sofList']);
Route::post('payment-gateway/va/create', [App\Http\Controllers\API\PaymentGatewayController::class, 'vaCreate']);
Route::post('payment-gateway/va/cekstatus', [App\Http\Controllers\API\PaymentGatewayController::class, 'vaStatus']);
Route::post('payment-gateway/va/delete', [App\Http\Controllers\API\PaymentGatewayController::class, 'vaDelete']);
Route::post('payment-gateway/dd/inquiry', [App\Http\Controllers\API\PaymentGatewayController::class, 'ddInquiry']);
Route::post('payment-gateway/dd/payment', [App\Http\Controllers\API\PaymentGatewayController::class, 'ddPayment']);

Route::post('/CallbackLinkAjaQRIS', [App\Http\Controllers\API\TavsirController::class, 'CallbackLinkAjaQRIS']);
Route::post('/callbackDerek', [App\Http\Controllers\API\TravShopController::class, 'callbackDerek']);
Route::post('/callbackPg', [App\Http\Controllers\API\TravShopController::class, 'callbackpg']);
Route::post('/payment-method-id', [App\Http\Controllers\API\PaymentMethodController::class,'indexPublic']);


Route::get('/tokenpg', function (Request $request) {
return PgJmto::generateToken();
});

Route::get('/pg-tarif', function () {
    $mandiri_va = PgJmto::feeMandiriVa();
    $bri_va = PgJmto::feeBriVa();
    $bni_va = PgJmto::feeBniVa();
    return [
        'bri_va' => $bri_va,
        'mandiri_va' => $mandiri_va,
        'bni_va' => $bni_va
    ];
});


Route::get('test-notif', function () {
    $token = request()->token;
    $title = request()->title ?? 'Test';
    $message = request()->message ?? 'Testing Notig';
    $result = sendNotif($token, $title, $message, []);
    return response()->json([
        'data' => [
            'token' => $token,
            'title' => $title,
            'message' => $message,
        ],
        'status' => json_decode($result)
    ]);
});


Route::get('cek', function(Request $request){

    $current_date_time = Carbon::now()->toDateTimeString();
    Log::info("REQ".$current_date_time);
    $kios = new KiosBankService();
    $data = $kios->cek();
    Log::info("RESP".$current_date_time);

    $current_date_times = Carbon::now()->toDateTimeString();
    Log::info('REQ =>'.$current_date_time.' | RESP=>'.$current_date_times);

    return response()->json($data);
});


//Kiosbank
Route::prefix('kios-bank')->group(function(){
    Route::controller(KiosBankController::class)->group(function(){
        Route::any('/callback','callback');
        Route::post('/cek-deposit','cekDeposit');
    });

    Route::controller(KiosBankController::class)->group(function(){
        Route::get('/product','index');
        Route::get('/product/{id}','show');
        Route::get('/product/{id}','show');
        Route::get('/product-sub-kategori','getSubKategoriProduct');
    });

    Route::prefix('pulsa')->controller(KiosBankController::class)->group(function(){
        Route::get('/operator','listOperatorPulsa');
        Route::get('/operator-product/{id}','listProductOperatorPulsa');
        Route::post('/order','orderPulsa');
    });

    Route::prefix('uang-elektronik')->controller(KiosBankController::class)->group(function(){
        Route::post('/order','orderUangElektronik');
    });
});
