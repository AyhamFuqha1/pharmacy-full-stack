import { createBrowserRouter } from "react-router";
import { createElement, type ReactNode } from "react";
import { Navigate } from "react-router";
import { Layout } from "./components/Layout";
import { DashboardPage } from "./pages/DashboardPage";
import { ChatPage } from "./pages/ChatPage";
import { CreateOrderPage } from "./pages/CreateOrderPage";
import { ProductsPage } from "./pages/ProductsPage";
import { ProductDetailsPage } from "./pages/ProductDetailsPage";
import { AddProductPage } from "./pages/AddProductPage";
import { CustomersPage } from "./pages/CustomersPage";
import { CustomerDetailsPage } from "./pages/CustomerDetailsPage";
import { PaymentsPage } from "./pages/PaymentsPage";
import { ReceiptPage } from "./pages/ReceiptPage";
import { SuppliersPage } from "./pages/SuppliersPage";
import { SupplierDetailsPage } from "./pages/SupplierDetailsPage";
import { PurchaseInvoicePage } from "./pages/PurchaseInvoicePage";
import { PurchaseInvoicesPage } from "./pages/PurchaseInvoicesPage";
import { SupplierPaymentPage } from "./pages/SupplierPaymentPage";
import { BatchesPage } from "./pages/BatchesPage";
import { SalesPage } from "./pages/SalesPage";
import { SalesDetailsPage } from "./pages/SalesDetailsPage";
import { ExpiryPage } from "./pages/ExpiryPage";
import { AnalyticsPage } from "./pages/AnalyticsPage";
import { ReportsPage } from "./pages/ReportsPage";
import { SettingsPage } from "./pages/SettingsPage";
import { LoginPage } from "./pages/LoginPage";
import { ChangePasswordPage } from "./pages/ChangePasswordPage";
import { NotFoundPage } from "./pages/NotFoundPage";
import { ChangePasswordRoute, LoginRoute, PermissionRoute, ProtectedRoute } from "../auth/RouteGuards";
import { InvoiceReturnPage } from "./pages/InvoiceReturnPage";
import { ChequesPage } from "./pages/ChequesPage";
import { PatientsPage } from "./pages/PatientsPage";
import { PatientDetailsPage } from "./pages/PatientDetailsPage";
import { PrescriptionsPage } from "./pages/PrescriptionsPage";
import { CreatePrescriptionPage } from "./pages/CreatePrescriptionPage";
import { PrescriptionDetailsPage } from "./pages/PrescriptionDetailsPage";
import { AuditPage } from "./pages/AuditPage";
import { SystemStatusPage } from "./pages/SystemStatusPage";
import { ReturnHistoryDetailsPage, ReturnHistoryPage } from "./pages/ReturnHistoryPage";
import { UniversalReportsPage } from "./pages/UniversalReportsPage";
import { Batch360Page, Product360Page, RecallPage } from "./pages/Entity360Pages";
import { AdjustmentsPage, OpeningBalancesPage, ReorderPage, TransfersPage } from "./pages/InventoryWorkspaces";
import { BankReconciliationPage, CashierShiftsPage, ExpensesPage, OtherIncomePage } from "./pages/FinanceWorkspaces";
import { BackupPage, ImportsPage, PrintableDocumentPage } from "./pages/AdminOperationalPages";
import { ControlledLedgerPage } from "./pages/ControlledLedgerPage";
import { ClosureSalesPage } from "./pages/ClosureSalesPage";
import { ChequeDetailsPage } from "./pages/ChequeDetailsPage";
import { Product360WorkspacePage } from "./pages/Product360WorkspacePage";
import { FriendlyAdjustmentPage, FriendlyReorderPage, FriendlyTransferPage } from "./pages/FriendlyInventoryPages";
import { CompleteStockCountsPage } from "./pages/CompleteStockCountsPage";
import { FriendlyCashierShiftsPage, FriendlyOpeningBalancesPage } from "./pages/FriendlyOperationalPages";
import { FriendlyImportsPage } from "./pages/FriendlyImportsPage";
import { FriendlyReceiptPage, FriendlySupplierPaymentPage } from "./pages/FriendlyVoucherPages";
import { FriendlyBankReconciliationPage, FriendlyExpensesPage, FriendlyOtherIncomePage } from "./pages/FriendlyFinancePages";
import { ActionableAlerts } from "./pages/AdminOperationalPages";
import { DocumentEnhancers } from "./components/DocumentEnhancers";
import { CreatedPaymentPrint } from "./components/CreatedPaymentPrint";
import { useAuth } from "../auth/useAuth";

const PurchaseWithOperations = () => createElement('div', null, createElement(PurchaseInvoicePage), createElement(DocumentEnhancers, { type: 'purchase_invoice', attachments: true }));
const PrescriptionWithOperations = () => createElement('div', null, createElement(PrescriptionDetailsPage), createElement(DocumentEnhancers, { type: 'prescription', attachments: true }));
const SaleWithPrint = () => createElement('div', null, createElement(SalesDetailsPage), createElement(DocumentEnhancers, { type: 'sales_invoice' }));
const ReceiptWithPrint = () => createElement('div', null, createElement(ReceiptPage), createElement(DocumentEnhancers, { type: 'receipt' }));
const SalesReturnWithPrint = () => createElement('div', null, createElement(ReturnHistoryDetailsPage, { kind: 'sales' }), createElement(DocumentEnhancers, { type: 'sales_return' }));
const PurchaseReturnWithPrint = () => createElement('div', null, createElement(ReturnHistoryDetailsPage, { kind: 'purchase' }), createElement(DocumentEnhancers, { type: 'purchase_return' }));
const DashboardWithAlerts = () => createElement('div', null, createElement(DashboardPage), createElement('div', { className: 'px-4 sm:px-8 pb-8' }, createElement(ActionableAlerts)));
const SupplierPaymentWithPrint = () => createElement('div', null, createElement(SupplierPaymentPage), createElement(CreatedPaymentPrint));
const FriendlySupplierPaymentWithPrint = () => createElement('div', null, createElement(FriendlySupplierPaymentPage), createElement(CreatedPaymentPrint));
const Denied = () => createElement('div', { className: 'p-8' }, createElement('h1', { className: 'text-2xl font-bold' }, 'Access denied'), createElement('p', { className: 'text-gray-600 mt-2' }, 'You do not have permission to view this information.'));
const ExpenseLookupPage = () => { const {hasPermission}=useAuth(); return hasPermission('expenses.view')||hasPermission('expenses.create')?createElement(FriendlyExpensesPage):createElement(Denied); };
const IncomeLookupPage = () => { const {hasPermission}=useAuth(); return hasPermission('other_income.view')||hasPermission('other_income.create')?createElement(FriendlyOtherIncomePage):createElement(Denied); };
const BankLookupPage = () => { const {hasPermission}=useAuth(); return hasPermission('reconciliation.manage')?createElement(FriendlyBankReconciliationPage):createElement(Denied); };
const AccountingReportsLookupPage = () => { const {hasPermission}=useAuth(); return hasPermission('accounting.reports')?createElement(ReportsPage):createElement(Denied); };
const permitted = (element: ReactNode, permissions: string[]) => createElement(PermissionRoute, { permissions }, element);

export const router = createBrowserRouter([
  {
    path: "/login",
    element: createElement(LoginRoute, null, createElement(LoginPage)),
  },
  {
    path: "/change-password",
    element: createElement(ChangePasswordRoute, null, createElement(ChangePasswordPage)),
  },
  {
    path: "/",
    element: createElement(ProtectedRoute, null, createElement(Layout)),
    children: [
      {
        index: true,
        Component: DashboardWithAlerts,
      },
      {
        path: "chat",
        Component: ChatPage,
      },
      {
        path: "create-order",
        Component: ClosureSalesPage,
      },
      {
        path: "products",
        Component: ProductsPage,
      },
      {
        path: "products/:id",
        Component: Product360WorkspacePage,
      },
      {
        path: "products/add",
        Component: AddProductPage,
      },
      {
        path: "products/:id/edit",
        Component: AddProductPage,
      },
      {
        path: "customers",
        Component: CustomersPage,
      },
      {
        path: "customers/:id",
        Component: CustomerDetailsPage,
      },
      {
        path: "payments",
        Component: FriendlyReceiptPage,
      },
      {
        path: "receipt/:id",
        Component: ReceiptWithPrint,
      },
      {
        path: "suppliers",
        element: permitted(createElement(SuppliersPage), ["suppliers.view", "accounting.vouchers"]),
      },
      {
        path: "suppliers/:id",
        element: permitted(createElement(SupplierDetailsPage), ["suppliers.view", "accounting.vouchers"]),
      },
      {
        path: "suppliers/purchase-invoice/:id",
        element: permitted(createElement(PurchaseWithOperations), ["purchases.manage", "accounting.vouchers"]),
      },
      {
        path: "purchases",
        element: permitted(createElement(PurchaseInvoicesPage), ["purchases.manage", "accounting.vouchers"]),
      },
      {
        path: "purchases/new",
        element: permitted(createElement(PurchaseInvoicePage), ["purchases.manage", "accounting.vouchers"]),
      },
      {
        path: "purchases/:id",
        element: permitted(createElement(PurchaseWithOperations), ["purchases.manage", "accounting.vouchers"]),
      },
      {
        path: "purchases/:id/edit",
        element: permitted(createElement(PurchaseInvoicePage), ["purchases.manage", "accounting.vouchers"]),
      },
      {
        path: "suppliers/payment/new",
        element: permitted(createElement(FriendlySupplierPaymentWithPrint), ["accounting.vouchers"]),
      },
      {
        path: "payment-vouchers",
        element: permitted(createElement(FriendlySupplierPaymentWithPrint), ["accounting.vouchers"]),
      },
      {
        path: "cheques",
        Component: ChequesPage,
      },
      { path: "cheques/:id", Component: ChequeDetailsPage },
      {
        path: "batches",
        Component: BatchesPage,
      },
      { path: "batches/:id", Component: Batch360Page },
      { path: "inventory/opening-balances", Component: FriendlyOpeningBalancesPage },
      { path: "inventory/stock-counts", Component: CompleteStockCountsPage },
      { path: "inventory/adjustments", Component: FriendlyAdjustmentPage },
      { path: "inventory/transfers", Component: FriendlyTransferPage },
      { path: "inventory/reorder", Component: FriendlyReorderPage },
      { path: "inventory/recalls/:id", Component: RecallPage },
      { path: "cashier-shifts", Component: FriendlyCashierShiftsPage },
      { path: "expenses", Component: ExpenseLookupPage },
      { path: "expenses/:id", Component: ExpenseLookupPage },
      { path: "other-income", Component: IncomeLookupPage },
      { path: "other-income/:id", Component: IncomeLookupPage },
      { path: "bank-reconciliation", Component: BankLookupPage },
      { path: "controlled-medicine-ledger", Component: ControlledLedgerPage },
      { path: "imports", Component: FriendlyImportsPage },
      { path: "system/backup", Component: BackupPage },
      {
        path: "sales",
        Component: SalesPage,
      },
      {
        path: "sales/:id",
        Component: SaleWithPrint,
      },
      {
        path: "sales/:id/return",
        element: createElement(InvoiceReturnPage, { kind: "sales" }),
      },
      {
        path: "purchases/:id/return",
        element: permitted(createElement(InvoiceReturnPage, { kind: "purchase" }), ["purchases.manage", "accounting.vouchers"]),
      },
      { path: "returns/sales", element: createElement(ReturnHistoryPage, { kind: "sales" }) },
      { path: "returns/sales/:id", Component: SalesReturnWithPrint },
      { path: "returns/purchases", element: permitted(createElement(ReturnHistoryPage, { kind: "purchase" }), ["purchases.manage", "accounting.vouchers"]) },
      { path: "returns/purchases/:id", element: permitted(createElement(PurchaseReturnWithPrint), ["purchases.manage", "accounting.vouchers"]) },
      {
        path: "expiry",
        Component: ExpiryPage,
      },
      {
        path: "patients",
        Component: PatientsPage,
      },
      {
        path: "patients/:id",
        Component: PatientDetailsPage,
      },
      {
        path: "prescriptions",
        Component: PrescriptionsPage,
      },
      {
        path: "prescriptions/new",
        Component: CreatePrescriptionPage,
      },
      {
        path: "prescriptions/:id",
        Component: PrescriptionWithOperations,
      },
      {
        path: "analytics",
        Component: AnalyticsPage,
      },
      {
        path: "reports",
        Component: AccountingReportsLookupPage,
      },
      {
        path: "reports/universal",
        Component: UniversalReportsPage,
      },
      {
        path: "settings",
        Component: SettingsPage,
      },
      {
        path: "audit",
        Component: AuditPage,
      },
      {
        path: "system-status",
        Component: SystemStatusPage,
      },
    ],
  },
  { path: "/print-document", element: createElement(ProtectedRoute, null, createElement(PrintableDocumentPage)) },
  {
    path: "*",
    Component: NotFoundPage,
  },
]);
