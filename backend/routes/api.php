<?php

use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExpressController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\ProductBatchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\ReceiptVouchersController;
use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {return $request->user();})->middleware('auth:sanctum');
Route::post('/login', [AuthController::class, 'login']);
Route::middleware(['auth:sanctum'])->post('/register', [AuthController::class, 'register']);
Route::middleware(['auth:sanctum'])->post('/logout', [AuthController::class, 'logout']);
Route::middleware(['auth:sanctum'])->post('/change-password', [AuthController::class, 'changePassword']);
//*
Route::middleware(['auth:sanctum', 'password.must-change'])->group(function () {
    Route::get('/product', [ProductController::class,'index']);
    Route::get('/product/{product}', [ProductController::class,'show']);
    Route::post('/product', [ProductController::class,'store']);
    Route::put('/product/{product}', [ProductController::class,'update']);
    Route::delete('/product/{product}', [ProductController::class,'destroy']);
    //*
    Route::get('/batch', [ProductBatchController::class,'index']);
    Route::get('/batch/{id}', [ProductBatchController::class,'show']);
    Route::post('/batch', [ProductBatchController::class,'store']);
    Route::put('/batch/{id}', [ProductBatchController::class,'update']);
    Route::delete('/batch/{id}', [ProductBatchController::class,'destroy']);
    //*
    Route::post('/sales-invoices',[SalesInvoiceController::class,'store']);
    Route::delete('/sales-invoices/{id}/cancel',[SalesInvoiceController::class,'cancel']);
    Route::get('/sales-invoices',[SalesInvoiceController::class,'index']);
    Route::get('/sales-invoices/{id}',[SalesInvoiceController::class,'show']);
    //*
    Route::post('/invoice_returns',[App\Http\Controllers\Api\InvoiceReturnController::class,'store']);
    //*
    Route::get('/cheque',[ChequeController::class,'index']);
    Route::get('/cheque/{id}',[ChequeController::class,'show']);
    Route::post('/cheque',[ChequeController::class,'store']);
    Route::post('/cheque/{id}/clear',[ChequeController::class,'clear']);
    Route::post('/cheque/{id}/bounce',[ChequeController::class,'bounce']);
    //*
    Route::get('/customer',[CustomerController::class,'index']);
    Route::get('/customer/{id}',[CustomerController::class,'show']);
    Route::post('/customer',[CustomerController::class,'store']);
    Route::put('/customer/{id}',[CustomerController::class,'update']);
    Route::delete('/customer/{id}',[CustomerController::class,'destroy']);
    Route::post('/customer/{id}',[CustomerController::class,'incrementDept']);
    Route::post('/customer/{id}',[CustomerController::class,'decrementDept']);
    //* 
    Route::get('/providers',[ProviderController::class,'index']);
    Route::get('/providers/{id}',[ProviderController::class,'show']);
    Route::post('/providers',[ProviderController::class,'store']);
    Route::put('/providers/{id}',[ProviderController::class,'update']);
    Route::delete('/providers/{id}',[ProviderController::class,'destroy']);
    Route::post('/providers/{id}/increase-debt',[ProviderController::class,'incrementDept']);
    Route::post('/providers/{id}/decrease-debt',[ProviderController::class,'decrementDept']);
    //*
    Route::post('/purch-invoices',[PurchaseInvoiceController::class,'store']);
    Route::post('/purch-invoices/{id}',[PurchaseInvoiceController::class,'post']);
    Route::get('/purch-invoices',[PurchaseInvoiceController::class,'index']);
    Route::get('/purch-invoices/{id}',[PurchaseInvoiceController::class,'show']);
    Route::put('/purch-invoices/{id}',[PurchaseInvoiceController::class,'update']);
    Route::delete('/purch-invoices/{id}',[PurchaseInvoiceController::class,'cancel']);
    //*
    Route::post('/invoice_returns/{id}',[App\Http\Controllers\Api\InvoiceReturnController::class,'purchase']);
    //*
    Route::get('/payment-vouchers',[PaymentVoucherController::class,'index']);
    Route::get('/payment-vouchers/{id}',[PaymentVoucherController::class,'show']);
    Route::post('/payment-vouchers',[PaymentVoucherController::class,'store']);
    Route::delete('/payment-vouchers',[PaymentVoucherController::class,'cancel']);
    //*
    Route::get('/receipt-vouchers',[ReceiptVouchersController::class,'index']);
    Route::get('/receipt-vouchers/{id}',[ReceiptVouchersController::class,'show']);
    Route::post('/receipt-vouchers',[ReceiptVouchersController::class,'store']);
    Route::delete('/receipt-vouchers',[ReceiptVouchersController::class,'cancel']);
    //*
    Route::get('/express',[ExpressController::class,'index']);
    Route::get('/express/{id}',[ExpressController::class,'show']);
    Route::post('/express',[ExpressController::class,'store']);
    Route::delete('/express',[ExpressController::class,'destroy']);
});
//*
