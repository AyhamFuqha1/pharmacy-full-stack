# Localization route matrix

Generated from the current `src/app/routes.ts` closure audit. `PASS` means the primary interface is source-localized and does not depend on `LegacyLocalizationBridge`. `FAIL` means the named component still contains genuine user-facing source strings requiring migration.

| Route | Component | Status | Remaining source-level issue |
|---|---|---:|---|
| `/login` | `LoginPage` | PASS | — |
| `/change-password` | `ChangePasswordPage` | PASS | — |
| `/` | `DashboardWithAlerts` / `DashboardPage` | PASS | — |
| `/chat` | `ChatPage` | PASS | — |
| `/create-order` | `ClosureSalesPage` | PASS | — |
| `/products` | `ProductsPage` | PASS | — |
| `/products/:id` | `Product360WorkspacePage` | PASS | — |
| `/products/add` | `AddProductPage` | PASS | — |
| `/products/:id/edit` | `AddProductPage` | PASS | — |
| `/customers` | `CustomersPage` / `PartyListPage` | PASS | — |
| `/customers/:id` | `CustomerDetailsPage` / party components | PASS | — |
| `/payments` | `FriendlyReceiptPage` | PASS | — |
| `/receipt/:id` | `ReceiptPage` | PASS | — |
| `/suppliers` | `SuppliersPage` / `PartyListPage` | PASS | — |
| `/suppliers/:id` | `SupplierDetailsPage` / party components | PASS | — |
| `/suppliers/purchase-invoice/:id` | `PurchaseWithOperations` | PASS | — |
| `/purchases` | `PurchaseInvoicesPage` | PASS | — |
| `/purchases/new` | `PurchaseInvoicePage` | PASS | — |
| `/purchases/:id` | `PurchaseWithOperations` | PASS | — |
| `/purchases/:id/edit` | `PurchaseInvoicePage` | PASS | — |
| `/suppliers/payment/new` | `FriendlySupplierPaymentWithPrint` | PASS | — |
| `/payment-vouchers` | `FriendlySupplierPaymentWithPrint` | PASS | — |
| `/cheques` | `ChequesPage` | PASS | — |
| `/cheques/:id` | `ChequeDetailsPage` | PASS | — |
| `/batches` | `BatchesPage` | PASS | — |
| `/batches/:id` | `Batch360Page` | PASS | — |
| `/inventory/opening-balances` | `FriendlyOpeningBalancesPage` | PASS | — |
| `/inventory/stock-counts` | `CompleteStockCountsPage` | PASS | — |
| `/inventory/adjustments` | `FriendlyAdjustmentPage` | PASS | — |
| `/inventory/transfers` | `FriendlyTransferPage` | PASS | — |
| `/inventory/reorder` | `FriendlyReorderPage` | PASS | — |
| `/inventory/recalls/:id` | `RecallPage` | PASS | — |
| `/cashier-shifts` | `FriendlyCashierShiftsPage` | PASS | — |
| `/expenses` | `ExpenseLookupPage` | PASS | — |
| `/expenses/:id` | `ExpenseLookupPage` | PASS | — |
| `/other-income` | `IncomeLookupPage` | PASS | — |
| `/other-income/:id` | `IncomeLookupPage` | PASS | — |
| `/bank-reconciliation` | `BankLookupPage` | PASS | — |
| `/controlled-medicine-ledger` | `ControlledLedgerPage` | PASS | — |
| `/imports` | `FriendlyImportsPage` | PASS | — |
| `/system/backup` | `BackupPage` | PASS | — |
| `/sales` | `SalesPage` | PASS | — |
| `/sales/:id` | `SaleWithPrint` | PASS | — |
| `/sales/:id/return` | `InvoiceReturnPage` | PASS | — |
| `/purchases/:id/return` | `InvoiceReturnPage` | PASS | — |
| `/returns/sales` | `ReturnHistoryPage` | PASS | — |
| `/returns/sales/:id` | `SalesReturnWithPrint` | PASS | — |
| `/returns/purchases` | `ReturnHistoryPage` | PASS | — |
| `/returns/purchases/:id` | `PurchaseReturnWithPrint` | PASS | — |
| `/expiry` | `ExpiryPage` | PASS | — |
| `/patients` | `PatientsPage` | PASS | — |
| `/patients/:id` | `PatientDetailsPage` | PASS | — |
| `/prescriptions` | `PrescriptionsPage` | PASS | — |
| `/prescriptions/new` | `CreatePrescriptionPage` | PASS | — |
| `/prescriptions/:id` | `PrescriptionWithOperations` | PASS | — |
| `/analytics` | `AnalyticsPage` | PASS | — |
| `/reports` | `AccountingReportsLookupPage` / `ReportsPage` | PASS | — |
| `/reports/universal` | `UniversalReportsPage` | PASS | — |
| `/settings` | `SettingsPage` | PASS | — |
| `/audit` | `AuditPage` | PASS | — |
| `/system-status` | `SystemStatusPage` | PASS | — |
| `/print-document` | `PrintableDocumentPage` | PASS | — |
| `*` | `NotFoundPage` | PASS | — |

Summary: **63 user-facing route entries audited — 63 PASS, 0 FAIL.**
