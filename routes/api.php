<?php

use App\Http\Controllers\API\lineController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/line/webhook' ,[lineController::class,'lineWebHook']);

Route::get('/test/{custId}/{rateId}' ,function($custId){
    $custName = \App\Models\customers::select('custName')->where('custId',$custId)->first();
    $custName = $custName->custName;
    return view('star',compact('custName'));
});
