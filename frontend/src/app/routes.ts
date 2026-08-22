import { createBrowserRouter } from "react-router";
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
import { SupplierPaymentPage } from "./pages/SupplierPaymentPage";
import { BatchesPage } from "./pages/BatchesPage";
import { SalesPage } from "./pages/SalesPage";
import { ExpiryPage } from "./pages/ExpiryPage";
import { AnalyticsPage } from "./pages/AnalyticsPage";
import { ReportsPage } from "./pages/ReportsPage";
import { SettingsPage } from "./pages/SettingsPage";

export const router = createBrowserRouter([
  {
    path: "/",
    Component: Layout,
    children: [
      {
        index: true,
        Component: DashboardPage,
      },
      {
        path: "chat",
        Component: ChatPage,
      },
      {
        path: "create-order",
        Component: CreateOrderPage,
      },
      {
        path: "products",
        Component: ProductsPage,
      },
      {
        path: "products/:id",
        Component: ProductDetailsPage,
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
        Component: PaymentsPage,
      },
      {
        path: "receipt",
        Component: ReceiptPage,
      },
      {
        path: "suppliers",
        Component: SuppliersPage,
      },
      {
        path: "suppliers/:id",
        Component: SupplierDetailsPage,
      },
      {
        path: "suppliers/purchase-invoice/:id",
        Component: PurchaseInvoicePage,
      },
      {
        path: "suppliers/payment/new",
        Component: SupplierPaymentPage,
      },
      {
        path: "batches",
        Component: BatchesPage,
      },
      {
        path: "sales",
        Component: SalesPage,
      },
      {
        path: "expiry",
        Component: ExpiryPage,
      },
      {
        path: "analytics",
        Component: AnalyticsPage,
      },
      {
        path: "reports",
        Component: ReportsPage,
      },
      {
        path: "settings",
        Component: SettingsPage,
      },
    ],
  },
]);