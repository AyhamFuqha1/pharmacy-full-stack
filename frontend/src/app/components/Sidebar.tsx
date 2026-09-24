import { useNavigate, useLocation } from "react-router";
import {
  LayoutDashboard,
  Package,
  Layers,
  ShoppingCart,
  AlertTriangle,
  BarChart3,
  FileText,
  Settings,
  ChevronLeft,
  ChevronRight,
  User,
  CreditCard,
  Truck,
  Shield,
  Boxes,
  Landmark,
  Users,
} from "lucide-react";
import { useAuth } from "../../auth/useAuth";
import { useTranslation } from "react-i18next";

const menuGroups = [
  {
    label: "Operations",
    icon: ShoppingCart,
    items: [
      {
        icon: LayoutDashboard,
        label: "Dashboard",
        path: "/",
        permission: "dashboard.view",
      },
      {
        icon: ShoppingCart,
        label: "Sales",
        path: "/sales",
        permission: "sales.create",
      },
      {
        icon: ShoppingCart,
        label: "Create Sale",
        path: "/create-order",
        permission: "sales.create",
      },
      {
        icon: FileText,
        label: "Purchases",
        path: "/purchases",
        permissions: ["purchases.manage", "accounting.vouchers"],
      },
      {
        icon: FileText,
        label: "Sales Returns",
        path: "/returns/sales",
        permission: "sales.create",
      },
      {
        icon: FileText,
        label: "Purchase Returns",
        path: "/returns/purchases",
        permissions: ["purchases.manage", "accounting.vouchers"],
      },
    ],
  },
  {
    label: "Inventory",
    icon: Boxes,
    items: [
      {
        icon: Package,
        label: "Products",
        path: "/products",
        permissions: ["products.view", "products.manage"],
      },
      {
        icon: Layers,
        label: "Batches",
        path: "/batches",
        permissions: ["batches.view", "batches.manage"],
      },
      {
        icon: AlertTriangle,
        label: "Expiry & Batch Risk",
        path: "/expiry",
        permissions: ["batches.view", "batches.manage"],
      },
      {
        icon: BarChart3,
        label: "Inventory Reports",
        path: "/reports/universal?report=stock_movements",
        permission: "reports.view_operational",
      },
      {
        icon: FileText,
        label: "Opening Balances",
        path: "/inventory/opening-balances",
        permission: "reports.view_financial",
      },
      {
        icon: FileText,
        label: "Stock Counts",
        path: "/inventory/stock-counts",
        permission: "inventory.count",
      },
      {
        icon: FileText,
        label: "Adjustments",
        path: "/inventory/adjustments",
        permission: "inventory.adjust",
      },
      {
        icon: Truck,
        label: "Transfers",
        path: "/inventory/transfers",
        permission: "inventory.transfer",
      },
      {
        icon: BarChart3,
        label: "Reorder",
        path: "/inventory/reorder",
        permission: "reports.view_operational",
      },
    ],
  },
  {
    label: "Parties",
    icon: Users,
    items: [
      {
        icon: User,
        label: "Customers",
        path: "/customers",
        permission: "accounting.vouchers",
      },
      {
        icon: Truck,
        label: "Suppliers",
        path: "/suppliers",
        permissions: ["suppliers.view", "accounting.vouchers"],
      },
      {
        icon: User,
        label: "Patients",
        path: "/patients",
        permission: "patients.manage",
      },
      {
        icon: FileText,
        label: "Prescriptions",
        path: "/prescriptions",
        permission: "prescriptions.manage",
      },
    ],
  },
  {
    label: "Finance",
    icon: Landmark,
    items: [
      {
        icon: CreditCard,
        label: "Customer Receipts",
        path: "/payments",
        permission: "accounting.vouchers",
      },
      {
        icon: CreditCard,
        label: "Supplier Payments",
        path: "/payment-vouchers",
        permission: "accounting.vouchers",
      },
      {
        icon: FileText,
        label: "Cheques",
        path: "/cheques",
        permission: "accounting.vouchers",
      },
      {
        icon: BarChart3,
        label: "Financial Reports",
        path: "/reports",
        permission: "accounting.reports",
      },
      {
        icon: CreditCard,
        label: "Cashier Shifts",
        path: "/cashier-shifts",
        permission: "cashier.shift",
      },
      {
        icon: FileText,
        label: "Expenses",
        path: "/expenses",
        permission: "expenses.view",
      },
      {
        icon: FileText,
        label: "Other Income",
        path: "/other-income",
        permission: "other_income.view",
      },
      {
        icon: Landmark,
        label: "Bank Reconciliation",
        path: "/bank-reconciliation",
        permission: "reconciliation.manage",
      },
    ],
  },
  {
    label: "Reports",
    icon: BarChart3,
    items: [
      {
        icon: BarChart3,
        label: "Analytics",
        path: "/analytics",
        permissions: ["dashboard.view", "accounting.reports", "audits.view"],
      },
      {
        icon: FileText,
        label: "Universal Reports",
        path: "/reports/universal",
        permissions: ["reports.view_operational", "reports.view_financial"],
      },
      {
        icon: FileText,
        label: "Customer Aging",
        path: "/reports/universal?report=ar_aging",
        permission: "reports.view_financial",
      },
      {
        icon: FileText,
        label: "Supplier Aging",
        path: "/reports/universal?report=ap_aging",
        permission: "reports.view_financial",
      },
      {
        icon: Shield,
        label: "Controlled Ledger",
        path: "/controlled-medicine-ledger",
        permission: "controlled.view",
      },
    ],
  },
  {
    label: "Administration",
    icon: Settings,
    items: [
      {
        icon: Shield,
        label: "Audit",
        path: "/audit",
        permission: "audits.view",
      },
      {
        icon: AlertTriangle,
        label: "System Status",
        path: "/system-status",
        permission: "deployment.readiness",
      },
      { icon: Settings, label: "Settings", path: "/settings", permissions: ["users.manage", "branches.manage", "accounting.periods.manage", "print_identity.manage"] },
      {
        icon: FileText,
        label: "Imports",
        path: "/imports",
        permission: "imports.manage",
      },
      {
        icon: Shield,
        label: "Backup Status",
        path: "/system/backup",
        permission: "system.backup",
      },
    ],
  },
];

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

export function Sidebar({ collapsed, onToggle }: SidebarProps) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const location = useLocation();
  const { user, hasPermission } = useAuth();
  const visibleGroups = menuGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) =>
        item.permissions
          ? item.permissions.some((permission) => hasPermission(permission))
          : !item.permission || hasPermission(item.permission),
      ),
    }))
    .filter((group) => group.items.length > 0);
  const initials =
    user?.name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join("") || "U";

  return (
    <aside
      className={`${
        collapsed ? "w-20" : "w-64"
      } bg-white border-e border-gray-200 flex flex-col transition-all duration-300`}
    >
      {/* Logo Section */}
      <div className="h-16 flex items-center justify-between px-6 border-b border-gray-200">
        {!collapsed && (
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 bg-gradient-to-br from-teal-600 to-emerald-500 rounded-lg flex items-center justify-center">
              <span className="text-white font-bold text-sm">Rx</span>
            </div>
            <span className="font-bold text-gray-900">PharmaSaaS</span>
          </div>
        )}
        <button
          onClick={onToggle}
          className="p-1.5 hover:bg-gray-100 rounded-lg transition-colors"
        >
          {(collapsed ? i18n.dir() === "rtl" : i18n.dir() !== "rtl") ? <ChevronRight className="w-5 h-5 text-gray-600" /> : <ChevronLeft className="w-5 h-5 text-gray-600" />}
        </button>
      </div>

      {/* Navigation Menu */}
      <nav className="flex-1 overflow-y-auto py-4 px-3">
        {visibleGroups.map((group) => (
          <div key={group.label} className="mb-4">
            {!collapsed && (
              <p className="px-3 mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                {t(group.label, { defaultValue: group.label })}
              </p>
            )}
            {group.items.map((item) => {
              const Icon = item.icon;
              const itemPath = item.path.split("?")[0];
              const isActive =
                location.pathname === itemPath &&
                (itemPath !== "/reports/universal" ||
                  !item.path.includes("?") ||
                  item.path.includes(
                    `report=${new URLSearchParams(location.search).get("report")}`,
                  ));

              return (
                <button
                  key={item.path}
                  onClick={() => navigate(item.path)}
                  className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 transition-all group ${
                    isActive
                      ? "bg-gradient-to-r from-teal-600 to-emerald-500 text-white shadow-sm"
                      : "text-gray-700 hover:bg-gray-100"
                  }`}
                >
                  <Icon
                    className={`w-5 h-5 flex-shrink-0 ${isActive ? "text-white" : "text-gray-600 group-hover:text-teal-600"}`}
                  />
                  {!collapsed && (
                    <>
                      <span className="flex-1 text-start font-medium">
                        {t(item.label, { defaultValue: item.label })}
                      </span>
                      {item.badge && (
                        <span className="w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                          {item.badge}
                        </span>
                      )}
                    </>
                  )}
                </button>
              );
            })}
          </div>
        ))}
      </nav>

      {/* User Section */}
      <div className="p-4 border-t border-gray-200">
        <div
          className={`flex items-center gap-3 ${collapsed ? "justify-center" : ""}`}
        >
          <div className="w-10 h-10 bg-gradient-to-br from-teal-400 to-emerald-500 rounded-full flex items-center justify-center flex-shrink-0">
            <span className="text-white font-semibold text-sm">{initials}</span>
          </div>
          {!collapsed && (
            <div className="flex-1 min-w-0">
              <p className="font-semibold text-gray-900 text-sm truncate">
                {user?.name}
              </p>
              <p className="text-xs text-gray-500 truncate">
                {user?.role?.name || t("User")}
              </p>
            </div>
          )}
        </div>
      </div>
    </aside>
  );
}
