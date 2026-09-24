<?php

use App\Http\Controllers\Api\AccountingPeriodController;
use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\AdvancedAuditReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashBoardController;
use App\Http\Controllers\Api\DeploymentReadinessController;
use App\Http\Controllers\Api\ErpCompletionController;
use App\Http\Controllers\Api\ExpressController;
use App\Http\Controllers\Api\FinanceExpansionController;
use App\Http\Controllers\Api\InventoryExpansionController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\PrintIdentityController;
use App\Http\Controllers\Api\ProductBatchController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\ReceiptVouchersController;
use App\Http\Controllers\Api\ReportExplorerController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    $user = $request->user()->load('roleRelation.permissions');

    return response()->json([
        'success' => true,
        'message' => 'Authenticated user retrieved.',
        'data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roleRelation ? [
                'id' => $user->roleRelation->id,
                'name' => $user->roleRelation->name,
            ] : null,
            'permissions' => $user->roleRelation?->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all() ?? [],
            'requires_password_change' => (bool) $user->force_change_password,
        ],
    ]);
})->middleware('auth:sanctum');
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
Route::middleware(['auth:sanctum', 'permission:users.manage'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::get('/roles', [RoleController::class, 'index']);
});
// *
Route::middleware(['auth:sanctum', 'password.must-change'])->group(function () {
    Route::get('/lookups/accounts', [LookupController::class, 'accounts'])
        ->middleware('permission:accounting.reports,expenses.view,expenses.create,other_income.view,other_income.create');
    Route::get('/lookups/bank-accounts', [LookupController::class, 'bankAccounts'])
        ->middleware('permission:reconciliation.manage');
    Route::get('/lookups/parties', [LookupController::class, 'parties'])
        ->middleware('permission:reports.view_financial,accounting.reports');
    Route::get('/cashier-shifts', [FinanceExpansionController::class, 'shifts'])->middleware('permission:cashier.shift');
    Route::get('/cashier-shifts/current', [FinanceExpansionController::class, 'current'])->middleware('permission:cashier.shift');
    Route::post('/cashier-shifts/open', [FinanceExpansionController::class, 'shiftOpen'])->middleware('permission:cashier.shift');
    Route::get('/cashier-shifts/{id}', [FinanceExpansionController::class, 'shiftShow'])->middleware('permission:cashier.shift');
    Route::post('/cashier-shifts/{id}/close', [FinanceExpansionController::class, 'shiftClose'])->middleware('permission:cashier.shift.close');
    Route::get('/other-incomes', [FinanceExpansionController::class, 'otherIncomes'])->middleware('permission:other_income.view');
    Route::post('/other-incomes', [FinanceExpansionController::class, 'otherIncomeStore'])->middleware(['permission:other_income.create', 'permission:other_income.post']);
    Route::get('/other-incomes/{id}', [FinanceExpansionController::class, 'otherIncomeShow'])->middleware('permission:other_income.view');
    Route::post('/other-incomes/{id}/cancel', [FinanceExpansionController::class, 'otherIncomeCancel'])->middleware('permission:other_income.post');
    Route::get('/bank-reconciliation/movements', [ErpCompletionController::class, 'bankMovements'])->middleware('permission:reconciliation.manage');
    Route::post('/bank-reconciliation/movements/{line}/reconcile', [ErpCompletionController::class, 'reconcile'])->middleware('permission:reconciliation.manage');
    Route::post('/bank-reconciliation/movements/{line}/unreconcile', [ErpCompletionController::class, 'unreconcile'])->middleware('permission:reconciliation.manage');
    Route::get('/controlled-medicine-ledger', [ErpCompletionController::class, 'controlled'])->middleware('permission:controlled.view,controlled.audit');
    Route::get('/attachments/{type}/{id}', [ErpCompletionController::class, 'attachments'])->middleware('permission:attachments.manage');
    Route::post('/attachments/{type}/{id}', [ErpCompletionController::class, 'attachmentUpload'])->middleware('permission:attachments.manage');
    Route::get('/attachment-files/{id}', [ErpCompletionController::class, 'attachmentDownload'])->middleware('permission:attachments.manage');
    Route::delete('/attachment-files/{id}', [ErpCompletionController::class, 'attachmentDelete'])->middleware('permission:attachments.manage');
    Route::post('/imports/preview', [ErpCompletionController::class, 'importPreview'])->middleware('permission:imports.manage');
    Route::post('/imports/{id}/mapping', [ErpCompletionController::class, 'importMapping'])->middleware('permission:imports.manage');
    Route::get('/imports/{id}', [ErpCompletionController::class, 'importShow'])->middleware('permission:imports.manage');
    Route::get('/imports/{id}/source', [ErpCompletionController::class, 'importDownload'])->middleware('permission:imports.manage');
    Route::post('/imports/{id}/confirm', [ErpCompletionController::class, 'importConfirm'])->middleware('permission:imports.manage');
    Route::get('/system/backups/status', [ErpCompletionController::class, 'backupStatus'])->middleware('permission:system.backup');
    Route::get('/system/backups', [ErpCompletionController::class, 'backups'])->middleware('permission:system.backup');
    Route::post('/system/backups', [ErpCompletionController::class, 'backup'])->middleware('permission:system.backup');
    Route::get('/system/backups/{id}/download', [ErpCompletionController::class, 'backupDownload'])->middleware('permission:system.backup');
    Route::post('/system/backups/{id}/validate', [ErpCompletionController::class, 'backupValidate'])->middleware('permission:system.restore');
    Route::post('/system/backups/{id}/restore', [ErpCompletionController::class, 'restore'])->middleware('permission:system.restore');
    Route::get('/customers/{id}/360', [ErpCompletionController::class, 'customer360'])->middleware('permission:reports.view_financial');
    Route::get('/customers/{id}/360/activity', [ErpCompletionController::class, 'customerActivity'])->middleware('permission:reports.view_financial');
    Route::get('/providers/{id}/360', [ErpCompletionController::class, 'supplier360'])->middleware('permission:reports.view_financial');
    Route::get('/providers/{id}/360/activity', [ErpCompletionController::class, 'supplierActivity'])->middleware('permission:reports.view_financial');
    Route::get('/products/{id}/360', [ErpCompletionController::class, 'product360'])->middleware('permission:reports.view_operational');
    Route::get('/products/{id}/profitability', [ErpCompletionController::class, 'productProfitability'])->middleware('permission:reports.view_profit');
    Route::get('/reports/product-profitability', [AccountingReportController::class, 'productProfitability'])->middleware('permission:reports.view_profit');
    Route::get('/batches/{id}/360', [ErpCompletionController::class, 'batch360'])->middleware('permission:reports.view_operational');
    Route::get('/batches/{id}/cost-detail', [ErpCompletionController::class, 'batch360'])->middleware('permission:inventory.view_cost');
    Route::get('/products/{product}/stock-statement', [ErpCompletionController::class, 'stockStatement'])->middleware('permission:reports.view_operational');
    Route::get('/recalls/{id}/traceability', [ErpCompletionController::class, 'recall'])->middleware('permission:reports.view_operational');
    Route::get('/alerts/actionable', [ErpCompletionController::class, 'alerts'])->middleware('permission:reports.view_operational');
    Route::post('/reports/print-data', [ReportExplorerController::class, 'printData'])->middleware('permission:reports.print');
    Route::get('/print-documents/{type}/{id}', [ErpCompletionController::class, 'printDocument'])->middleware('permission:reports.print,reports.print_operational');
    Route::post('/print-documents/{type}', [ErpCompletionController::class, 'printReportDocument'])->middleware('permission:reports.print,reports.print_operational');
    Route::get('/accounting/reports/ar-aging', [FinanceExpansionController::class, 'arAging'])->middleware('permission:reports.view_financial');
    Route::get('/accounting/reports/ap-aging', [FinanceExpansionController::class, 'apAging'])->middleware('permission:reports.view_financial');
    Route::get('/units', [ProductUnitController::class, 'units'])->middleware('permission:products.view,inventory.units.manage');
    Route::get('/products/{product}/units', [ProductUnitController::class, 'index'])->middleware('permission:products.view,inventory.units.manage');
    Route::post('/products/{product}/units', [ProductUnitController::class, 'store'])->middleware('permission:inventory.units.manage');
    Route::put('/products/{product}/units/{id}', [ProductUnitController::class, 'update'])->middleware('permission:inventory.units.manage');
    Route::delete('/products/{product}/units/{id}', [ProductUnitController::class, 'disable'])->middleware('permission:inventory.units.manage');
    Route::post('/opening-balances', [InventoryExpansionController::class, 'openingStore'])->middleware('permission:reports.view_financial');
    Route::post('/opening-balances/{id}/validate', [InventoryExpansionController::class, 'openingValidate'])->middleware('permission:reports.view_financial');
    Route::post('/opening-balances/{id}/post', [InventoryExpansionController::class, 'openingPost'])->middleware('permission:reports.view_financial');
    Route::post('/stock-counts', [InventoryExpansionController::class, 'countStore'])->middleware('permission:inventory.count');
    Route::post('/stock-counts/{id}/post', [InventoryExpansionController::class, 'countPost'])->middleware('permission:inventory.count');
    Route::post('/stock-adjustments', [InventoryExpansionController::class, 'adjustmentStore'])->middleware('permission:inventory.adjust');
    Route::get('/inventory/reorder', [InventoryExpansionController::class, 'reorder'])->middleware('permission:reports.view_operational');
    Route::post('/batches/{id}/state', [InventoryExpansionController::class, 'batchState'])->middleware('permission:inventory.adjust');
    Route::post('/stock-transfers', [InventoryExpansionController::class, 'transferStore'])->middleware('permission:inventory.transfer');
    Route::post('/stock-transfers/{id}/send', [InventoryExpansionController::class, 'transferSend'])->middleware('permission:inventory.transfer');
    Route::post('/stock-transfers/{id}/receive', [InventoryExpansionController::class, 'transferReceive'])->middleware('permission:inventory.transfer');
    Route::post('/stock-transfers/{id}/cancel', [InventoryExpansionController::class, 'transferCancel'])->middleware('permission:inventory.transfer');
    Route::get('/reports/catalog', [ReportExplorerController::class, 'catalog'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::post('/reports/query', [ReportExplorerController::class, 'query'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::post('/reports/export/csv', [ReportExplorerController::class, 'export'])->middleware('permission:reports.export');
    Route::get('/reports/saved', [ReportExplorerController::class, 'savedIndex'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::post('/reports/saved', [ReportExplorerController::class, 'savedStore'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::post('/reports/saved/{id}/execute', [ReportExplorerController::class, 'savedExecute'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::delete('/reports/saved/{id}', [ReportExplorerController::class, 'savedDestroy'])->middleware('permission:reports.view_operational,reports.view_financial');
    Route::middleware('permission:accounting.periods.manage')->group(function () {
        Route::get('/accounting-periods', [AccountingPeriodController::class, 'index']);
        Route::get('/accounting-periods/{id}', [AccountingPeriodController::class, 'show']);
        Route::post('/accounting-periods', [AccountingPeriodController::class, 'store']);
        Route::post('/accounting-periods/{id}/close', [AccountingPeriodController::class, 'close']);
        Route::post('/accounting-periods/{id}/reopen', [AccountingPeriodController::class, 'reopen']);
    });
    Route::get('/dashboard', [DashBoardController::class, 'index'])->middleware('permission:dashboard.view');
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:dashboard.view');
    Route::prefix('/accounting/reports')->middleware('permission:accounting.reports')->group(function () {
        Route::get('/general-ledger', [AccountingReportController::class, 'generalLedger']);
        Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('/income-statement', [AccountingReportController::class, 'incomeStatement'])->middleware('permission:reports.view_profit');
        Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('/customers/{customerId}/statement', [AccountingReportController::class, 'customerStatement']);
        Route::get('/suppliers/{supplierId}/statement', [AccountingReportController::class, 'supplierStatement']);
        Route::get('/inventory-valuation', [AccountingReportController::class, 'inventoryValuation']);
    });
    Route::get('/product/barcode/{barcode}', [ProductController::class, 'scanBarcode'])->middleware('permission:products.view,products.manage');
    Route::get('/product', [ProductController::class, 'index'])->middleware('permission:products.view,products.manage');
    Route::get('/product/{product}', [ProductController::class, 'show'])->middleware('permission:products.view,products.manage');
    Route::post('/product', [ProductController::class, 'store'])->middleware('permission:products.manage');
    Route::put('/product/{product}', [ProductController::class, 'update'])->middleware('permission:products.manage');
    Route::delete('/product/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.manage');
    Route::post('/product/{product}/restore', [ProductController::class, 'restore'])->middleware('permission:products.manage');
    Route::get('/products/{id}/stock-ledger', [ProductController::class, 'stockLedger'])->middleware('permission:products.view,products.manage');
    Route::get('/products/{id}/report', [ProductController::class, 'report'])->middleware('permission:accounting.reports');
    // *
    Route::get('/branches', [BranchController::class, 'index'])->middleware('permission:branches.manage');
    Route::post('/branches', [BranchController::class, 'store'])->middleware('permission:branches.manage');
    Route::get('/branches/{id}', [BranchController::class, 'show'])->middleware('permission:branches.manage');
    Route::put('/branches/{id}', [BranchController::class, 'update'])->middleware('permission:branches.manage');
    // *
    Route::get('/patients', [PatientController::class, 'index'])->middleware('permission:patients.view,patients.manage');
    Route::post('/patients', [PatientController::class, 'store'])->middleware('permission:patients.manage');
    Route::get('/patients/{id}', [PatientController::class, 'show'])->middleware('permission:patients.view,patients.manage');
    Route::put('/patients/{id}', [PatientController::class, 'update'])->middleware('permission:patients.manage');
    // *
    Route::get('/prescriptions', [PrescriptionController::class, 'index'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions', [PrescriptionController::class, 'store'])->middleware('permission:prescriptions.manage');
    Route::get('/prescriptions/{id}', [PrescriptionController::class, 'show'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions/{id}/items', [PrescriptionController::class, 'addItem'])->middleware('permission:prescriptions.manage');
    Route::post('/prescriptions/{id}/dispense', [PrescriptionController::class, 'dispense'])->middleware('permission:prescriptions.manage,controlled.dispense');
    // *
    Route::get('/audit/reports/advanced', [AdvancedAuditReportController::class, 'index'])->middleware('permission:audits.view');
    Route::get('/print-identity', [PrintIdentityController::class, 'show'])->middleware('permission:print_identity.manage');
    Route::post('/print-identity', [PrintIdentityController::class, 'update'])->middleware('permission:print_identity.manage');
    Route::get('/print-identity/logo/{asset}', [PrintIdentityController::class, 'logo']);
    Route::get('/deployment/readiness', [DeploymentReadinessController::class, 'show'])->middleware('permission:deployment.readiness');
    // *
    Route::get('/batch', [ProductBatchController::class, 'index'])->middleware('permission:batches.view,batches.manage');
    Route::get('/batch/{id}', [ProductBatchController::class, 'show'])->middleware('permission:batches.view,batches.manage');
    Route::put('/batch/{id}', [ProductBatchController::class, 'update'])->middleware('permission:batches.manage');
    Route::delete('/batch/{id}', [ProductBatchController::class, 'destroy'])->middleware('permission:batches.manage');
    Route::post('/batch/{id}/restore', [ProductBatchController::class, 'restore'])->middleware('permission:batches.manage');
    // *
    Route::post('/sales-invoices', [SalesInvoiceController::class, 'store'])->middleware('permission:sales.create');
    Route::delete('/sales-invoices/{id}/cancel', [SalesInvoiceController::class, 'cancel'])->middleware('permission:sales.create');
    Route::get('/sales-invoices', [SalesInvoiceController::class, 'index'])->middleware('permission:sales.create');
    Route::get('/sales-invoices/{id}', [SalesInvoiceController::class, 'show'])->middleware('permission:sales.create');
    Route::get('/customers/{customer}/products/{product}/price-history', [SalesInvoiceController::class, 'customerProductPriceHistory'])->middleware('permission:sales.create');
    // *
    Route::post('/sales-returns', [App\Http\Controllers\Api\InvoiceReturnController::class, 'store'])->middleware('permission:sales.create');
    Route::get('/sales-returns', [App\Http\Controllers\Api\InvoiceReturnController::class, 'salesIndex'])->middleware('permission:sales.create');
    Route::get('/sales-returns/{id}', [App\Http\Controllers\Api\InvoiceReturnController::class, 'salesShow'])->middleware('permission:sales.create');
    // *
    Route::get('/cheques', [ChequeController::class, 'index'])->middleware('permission:accounting.vouchers');
    Route::get('/cheques/{id}', [ChequeController::class, 'show'])->middleware('permission:accounting.vouchers');
    Route::post('/cheques/{id}/clear', [ChequeController::class, 'clear'])->middleware('permission:accounting.vouchers');
    Route::post('/cheques/{id}/bounce', [ChequeController::class, 'bounce'])->middleware('permission:accounting.vouchers');
    // *
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view,accounting.vouchers');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->middleware('permission:customers.view,accounting.vouchers');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:accounting.vouchers');
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->middleware('permission:accounting.vouchers');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->middleware('permission:accounting.vouchers');
    Route::post('/customers/{id}/restore', [CustomerController::class, 'restore'])->middleware('permission:accounting.vouchers');
    // *
    Route::get('/providers', [ProviderController::class, 'index'])->middleware('permission:suppliers.view,accounting.vouchers');
    Route::get('/providers/{id}', [ProviderController::class, 'show'])->middleware('permission:suppliers.view,accounting.vouchers');
    Route::post('/providers', [ProviderController::class, 'store'])->middleware('permission:accounting.vouchers');
    Route::put('/providers/{id}', [ProviderController::class, 'update'])->middleware('permission:accounting.vouchers');
    Route::delete('/providers/{id}', [ProviderController::class, 'destroy'])->middleware('permission:accounting.vouchers');
    Route::post('/providers/{id}/restore', [ProviderController::class, 'restore'])->middleware('permission:accounting.vouchers');
    // *
    Route::post('/purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::post('/purchase-invoices/{id}/post', [PurchaseInvoiceController::class, 'post'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::get('/purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::get('/purchase-invoices/{id}', [PurchaseInvoiceController::class, 'show'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::put('/purchase-invoices/{id}', [PurchaseInvoiceController::class, 'update'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::delete('/purchase-invoices/{id}', [PurchaseInvoiceController::class, 'cancel'])->middleware('permission:purchases.manage,accounting.vouchers');
    // *
    Route::post('/purchase-invoices/{id}/returns', [App\Http\Controllers\Api\InvoiceReturnController::class, 'purchase'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::get('/purchase-returns', [App\Http\Controllers\Api\InvoiceReturnController::class, 'purchaseIndex'])->middleware('permission:purchases.manage,accounting.vouchers');
    Route::get('/purchase-returns/{id}', [App\Http\Controllers\Api\InvoiceReturnController::class, 'purchaseShow'])->middleware('permission:purchases.manage,accounting.vouchers');
    // *
    Route::get('/payment-vouchers', [PaymentVoucherController::class, 'index'])->middleware('permission:accounting.vouchers');
    Route::get('/payment-vouchers/{id}', [PaymentVoucherController::class, 'show'])->middleware('permission:accounting.vouchers');
    Route::post('/payment-vouchers', [PaymentVoucherController::class, 'store'])->middleware('permission:accounting.vouchers');
    Route::delete('/payment-vouchers/{id}', [PaymentVoucherController::class, 'cancel'])->middleware('permission:accounting.vouchers');
    // *
    Route::get('/receipt-vouchers', [ReceiptVouchersController::class, 'index'])->middleware('permission:accounting.vouchers');
    Route::get('/receipt-vouchers/{id}', [ReceiptVouchersController::class, 'show'])->middleware('permission:accounting.vouchers');
    Route::post('/receipt-vouchers', [ReceiptVouchersController::class, 'store'])->middleware('permission:accounting.vouchers');
    Route::delete('/receipt-vouchers/{id}', [ReceiptVouchersController::class, 'cancel'])->middleware('permission:accounting.vouchers');
    // *
    Route::get('/expenses', [ExpressController::class, 'index'])->middleware('permission:expenses.view');
    Route::get('/expenses/{id}', [ExpressController::class, 'show'])->middleware('permission:expenses.view');
    Route::post('/expenses', [ExpressController::class, 'store'])->middleware(['permission:expenses.create', 'permission:expenses.post']);
    Route::delete('/expenses/{id}',[ExpressController::class, 'destroy'])->middleware('permission:expenses.post');
});
// *
