<?php

namespace App\Services\External;

use App\Models\KiosBank\ProductKiosBank;
use App\Models\TransOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;


class TravoyService
{
    protected $baseUrl;

    public const detilDerek = '/status_payment';

    public const detilHU = '/subsciption/payment-status';
    public const detilHUPlan = '/subsciption/Callback';

    public const detilPaymentHU = '/subsciption/updatestatusva';



    function __construct()
    {
        $this->baseUrl = env('TRAVOY_URL');
    }
    function http($method, $path, $payload = [])
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
        )
        );

        $response = curl_exec($curl);
        curl_close($curl);

        $res = json_decode($response);

        unset($res->data);
        return $res;

    }
    public function detailDerek($id, $id_user, $token)
    {

        $payLoad = [
            'trans_id' => $id,
            'idUser' => $id_user,
            'token' => $token
        ];

        $res_json = $this->http('POST', self::detilDerek, $payLoad);


        return $res_json;

    }
    
    public function detailHU($id)
    {

        $payLoad = [
            'trans_id' => $id
            
        ];
        $res_json = $this->http('POST', self::detilHU, $payLoad);
        log::info('callbackHU',$payLoad);
        return $res_json;

    }

    public function detailHUPlan($id)
    {

        $payLoad = [
            'trans_id' => $id
            
        ];
        $res_json = $this->http('POST', self::detilHUPlan, $payLoad);
        log::info('callbackHUDetail',$payLoad);
        return $res_json;

    }
    public function PaymentRequesthu($id)
    {

        $payLoad = [
            'trans_id' => $id
            
        ];

        try {
            $res_json = $this->http('POST', self::detilPaymentHU, $payLoad);
        } catch (\Throwable $th) {
            $res_json = [];
        }
        log::info('callbackHUDetail', [$payLoad, $res_json]);
        return $res_json;

    }
}