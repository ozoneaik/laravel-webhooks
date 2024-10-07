<?php

use App\Models\customers;
use App\Models\Rates;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/{custId}/{rateId}' ,function($custId,$rateId){
    $custName = customers::select('custName')->where('custId',$custId)->first();
    if (!$custName){
        return response()->json([
            'message' => 'เกิดข้อผิดพลาดบางอย่าง'
        ],404);
    }
    $custName = $custName->custName;
    $rateStatus = Rates::where('id',$rateId)->first();
    if (!$rateStatus){
        return response()->json([
            'message' => 'เกิดข้อผิดพลาดบางอย่าง'
        ],404);
    }
    $status = $rateStatus['status'];
    $star = $rateStatus['rate'];
    return view('star',compact('custName','status','star','rateId','custId'));
});

Route::get('/rate/{star}/{rateId}',function($star,$rateId){
    $rates = Rates::where('id',$rateId)->first();
    $rates['rate'] = $star;
    $rates->save();
    return response()->json(['message' => 'success']);
});
