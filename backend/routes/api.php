<?php

use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\AdvancedAuditReportController;
use App\Http\Controllers\Api\DeploymentReadinessController;
use App\Http\Controllers\Api\ExpressController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\ProductBatchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\DashBoardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\ReceiptVouchersController;
use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {return $request->user();})->middleware('auth:sanctum');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::select('select 1');
        return response()->json(['status' => 'ok', 'database' => 'ok']);
    } catch (\Throwable $exception) {
        report($exception);
        return response()->json(['status' => 'degraded', 'database' => 'unavailable'], 503);
    }
});
Route::middleware(['auth:sanctum'])->post('/logout', [AuthController::class, 'logout']);
Route::middleware(['auth:sanctum'])->post('/change-password', [AuthController::class, 'changePassword']);
Route::middleware(['auth:sanctum', 'permission:users.manage'])->post('/register', [AuthController::class, 'register']);
//*
Route::middleware(['auth:sanctum', 'password.must-change'])->group(function () {
    Route::get('/dashboard', [DashBoardController::class, 'index'])->middleware('permission:dashboard.view');
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:dashboard.view');
    Route::prefix('/accounting/reports')->middleware('permission:accounting.reports')->group(function () {
        Route::get('/general-ledger', [AccountingReportController::class, 'generalLedger']);
        Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('/income-statement', [AccountingReportController::class, 'incomeStatement']);
        Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('/customers/{customerId}/statement', [AccountingReportController::class, 'customerStatement']);
        Route::get('/suppliers/{supplierId}/statement', [AccountingReportController::class, 'supplierStatement']);
        Route::get('/inventory-valuation', [AccountingReportController::class, 'inventoryValuation']);
    });
    Route::get('/product', [ProductController::class,'index'])->middleware('permission:products.manage');
    Route::get('/product/{product}', [ProductController::class,'show']);
    Route::get('/product/barcode/{barcode}', [ProductController::class,'scanBarcode'])->middleware('permission:products.manage');
    Route::post('/product', [ProductController::class,'store'])->middleware('permission:products.manage');
    Route::put('/product/{product}', [ProductController::class,'update'])->middleware('permission:products.manage');
    Route::delete('/product/{product}', [ProductController::class,'destroy'])->middleware('permission:products.manage');
    //*
    Route::get('/branches', [BranchController::class, 'index'])->middleware('permission:branches.manage');
    Route::post('/branches', [BranchController::class, 'store'])->middleware('permission:branches.manage');
    Route::get('/branches/{id}', [BranchController::class, 'show'])->middleware('permission:branches.manage');
    Route::put('/branches/{id}', [BranchController::class, 'update'])->middleware('permission:branches.manage');
    //*
    Route::get('/patients', [PatientController::class, 'index'])->middleware('permission:patients.manage');
    Route::post('/patients', [PatientController::class, 'store'])->middleware('permission:patients.manage');
    Route::get('/patients/{id}', [PatientController::class, 'show'])->middleware('permission:patients.manage');
    Route::put('/patients/{id}', [PatientController::class, 'update'])->middleware('permission:patients.manage');
    //*
    Route::get('/prescriptions', [PrescriptionController::class, 'index'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions', [PrescriptionController::class, 'store'])->middleware('permission:prescriptions.manage');
    Route::get('/prescriptions/{id}', [PrescriptionController::class, 'show'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions/{id}/items', [PrescriptionController::class, 'addItem'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions/{id}/dispense', [PrescriptionController::class, 'dispense'])->middleware('permission:prescriptions.manage');
    //*
    Route::get('/audit/reports/advanced', [AdvancedAuditReportController::class, 'index'])->middleware('permission:audits.view');
    Route::get('/deployment/readiness', [DeploymentReadinessController::class, 'show'])->middleware('permission:deployment.readiness');
    //*
    Route::get('/batch', [ProductBatchController::class,'index'])->middleware('permission:batches.manage');
    Route::get('/batch/{id}', [ProductBatchController::class,'show']);
    Route::post('/batch', [ProductBatchController::class,'store'])->middleware('permission:batches.manage');
    Route::put('/batch/{id}', [ProductBatchController::class,'update'])->middleware('permission:batches.manage');
    Route::delete('/batch/{id}', [ProductBatchController::class,'destroy'])->middleware('permission:batches.manage');
    //*
    Route::post('/sales-invoices',[SalesInvoiceController::class,'store'])->middleware('permission:sales.create');
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
    Route::get('/payment-vouchers',[PaymentVoucherController::class,'index'])->middleware('permission:accounting.vouchers');
    Route::get('/payment-vouchers/{id}',[PaymentVoucherController::class,'show'])->middleware('permission:accounting.vouchers');
    Route::post('/payment-vouchers',[PaymentVoucherController::class,'store'])->middleware('permission:accounting.vouchers');
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
