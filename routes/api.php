<?php

use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\ProductBatchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SalesInvoiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {return $request->user();})->middleware('auth:sanctum');
Route::post('/login', [AuthController::class, 'login']);
Route::middleware(['auth:sanctum'])->post('/register', [AuthController::class, 'register']);
Route::middleware(['auth:sanctum'])->post('/logout', [AuthController::class, 'logout']);
//*
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
Route::middleware(['auth:sanctum'])->post('/sales-invoices',[App\Http\Controllers\Api\SalesInvoiceController::class,'store']);
Route::middleware(['auth:sanctum'])->delete('/sales-invoices/{id}/cancel',[App\Http\Controllers\Api\SalesInvoiceController::class,'cancel']);
Route::middleware(['auth:sanctum'])->get('/sales-invoices',[App\Http\Controllers\Api\SalesInvoiceController::class,'index']);
Route::middleware(['auth:sanctum'])->get('/sales-invoices/{id}',[App\Http\Controllers\Api\SalesInvoiceController::class,'show']);
//*
Route::middleware(['auth:sanctum'])->post('/invoice_returns',[App\Http\Controllers\Api\InvoiceReturnController::class,'store']);
//*
Route::middleware(['auth:sanctum'])->post('/cheque',[ChequeController::class,'store']);



