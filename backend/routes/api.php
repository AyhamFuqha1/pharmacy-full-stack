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
//*
Route::middleware(['auth:sanctum'])->get('/product', [ProductController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/product/{product}', [ProductController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/product', [ProductController::class,'store']);
Route::middleware(['auth:sanctum'])->put('/product/{product}', [ProductController::class,'update']);
Route::middleware(['auth:sanctum'])->delete('/product/{product}', [ProductController::class,'destroy']);
//*
Route::middleware(['auth:sanctum'])->get('/batch', [ProductBatchController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/batch/{id}', [ProductBatchController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/batch', [ProductBatchController::class,'store']);
Route::middleware(['auth:sanctum'])->put('/batch/{id}', [ProductBatchController::class,'update']);
Route::middleware(['auth:sanctum'])->delete('/batch/{id}', [ProductBatchController::class,'destroy']);
//*
Route::middleware(['auth:sanctum'])->post('/sales-invoices',[SalesInvoiceController::class,'store']);
Route::middleware(['auth:sanctum'])->delete('/sales-invoices/{id}/cancel',[SalesInvoiceController::class,'cancel']);
Route::middleware(['auth:sanctum'])->get('/sales-invoices',[SalesInvoiceController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/sales-invoices/{id}',[SalesInvoiceController::class,'show']);
//*
Route::middleware(['auth:sanctum'])->post('/invoice_returns',[App\Http\Controllers\Api\InvoiceReturnController::class,'store']);
//*
Route::middleware(['auth:sanctum'])->get('/cheque',[ChequeController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/cheque/{id}',[ChequeController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/cheque',[ChequeController::class,'store']);
Route::middleware(['auth:sanctum'])->post('/cheque/{id}/clear',[ChequeController::class,'clear']);
Route::middleware(['auth:sanctum'])->post('/cheque/{id}/bounce',[ChequeController::class,'bounce']);
//*
Route::middleware(['auth:sanctum'])->get('/customer',[CustomerController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/customer/{id}',[CustomerController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/customer',[CustomerController::class,'store']);
Route::middleware(['auth:sanctum'])->put('/customer/{id}',[CustomerController::class,'update']);
Route::middleware(['auth:sanctum'])->delete('/customer/{id}',[CustomerController::class,'destroy']);
Route::middleware(['auth:sanctum'])->post('/customer/{id}',[CustomerController::class,'incrementDept']);
Route::middleware(['auth:sanctum'])->post('/customer/{id}',[CustomerController::class,'decrementDept']);
//* 
Route::middleware(['auth:sanctum'])->get('/providers',[ProviderController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/providers/{id}',[ProviderController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/providers',[ProviderController::class,'store']);
Route::middleware(['auth:sanctum'])->put('/providers/{id}',[ProviderController::class,'update']);
Route::middleware(['auth:sanctum'])->delete('/providers/{id}',[ProviderController::class,'destroy']);
Route::middleware(['auth:sanctum'])->post('/providers/{id}/increase-debt',[ProviderController::class,'incrementDept']);
Route::middleware(['auth:sanctum'])->post('/providers/{id}/decrease-debt',[ProviderController::class,'decrementDept']);
//*
Route::middleware(['auth:sanctum'])->post('/purch-invoices',[PurchaseInvoiceController::class,'store']);
Route::middleware(['auth:sanctum'])->post('/purch-invoices/{id}',[PurchaseInvoiceController::class,'post']);
Route::middleware(['auth:sanctum'])->get('/purch-invoices',[PurchaseInvoiceController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/purch-invoices/{id}',[PurchaseInvoiceController::class,'show']);
Route::middleware(['auth:sanctum'])->put('/purch-invoices/{id}',[PurchaseInvoiceController::class,'update']);
Route::middleware(['auth:sanctum'])->delete('/purch-invoices/{id}',[PurchaseInvoiceController::class,'cancel']);
//*
Route::middleware(['auth:sanctum'])->post('/invoice_returns/{id}',[App\Http\Controllers\Api\InvoiceReturnController::class,'purchase']);
//*
Route::middleware(['auth:sanctum'])->get('/payment-vouchers',[PaymentVoucherController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/payment-vouchers/{id}',[PaymentVoucherController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/payment-vouchers',[PaymentVoucherController::class,'store']);
Route::middleware(['auth:sanctum'])->delete('/payment-vouchers',[PaymentVoucherController::class,'cancel']);
//*
Route::middleware(['auth:sanctum'])->get('/receipt-vouchers',[ReceiptVouchersController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/receipt-vouchers/{id}',[ReceiptVouchersController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/receipt-vouchers',[ReceiptVouchersController::class,'store']);
Route::middleware(['auth:sanctum'])->delete('/receipt-vouchers',[ReceiptVouchersController::class,'cancel']);
//*
Route::middleware(['auth:sanctum'])->get('/express',[ExpressController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/express/{id}',[ExpressController::class,'show']);
Route::middleware(['auth:sanctum'])->post('/express',[ExpressController::class,'store']);
Route::middleware(['auth:sanctum'])->delete('/express',[ExpressController::class,'destroy']);
//*
