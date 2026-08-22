import { KPICard } from '../components/KPICard';
import { SalesChart } from '../components/SalesChart';
import { InventoryRiskChart } from '../components/InventoryRiskChart';
import { RevenueChart } from '../components/RevenueChart';
import { InventoryTable } from '../components/InventoryTable';
import { NotificationPanel } from '../components/NotificationPanel';
import {
  DollarSign,
  TrendingUp,
  AlertTriangle,
  PackageX,
  TrendingDown,
} from 'lucide-react';

export function DashboardPage() {
  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Dashboard Overview</h1>
        <p className="text-gray-500">Welcome back! Here's what's happening with your pharmacy today.</p>
      </div>

      {/* KPI Cards Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
        <KPICard
          title="Total Sales Today"
          value="$12,458"
          change="+12.5%"
          isPositive={true}
          icon={DollarSign}
          iconColor="bg-teal-600"
        />
        <KPICard
          title="Total Revenue"
          value="$84,290"
          change="+8.2%"
          isPositive={true}
          icon={TrendingUp}
          iconColor="bg-emerald-500"
        />
        <KPICard
          title="Expiring Soon"
          value="23"
          change="+3"
          isPositive={false}
          icon={AlertTriangle}
          iconColor="bg-red-500"
        />
        <KPICard
          title="High Risk Inventory"
          value="12"
          change="-2"
          isPositive={true}
          icon={PackageX}
          iconColor="bg-orange-500"
        />
        <KPICard
          title="Low Stock Products"
          value="8"
          change="+1"
          isPositive={false}
          icon={TrendingDown}
          iconColor="bg-amber-500"
        />
      </div>

      {/* Charts Section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div className="lg:col-span-2">
          <SalesChart />
        </div>
        <div>
          <InventoryRiskChart />
        </div>
      </div>

      {/* Revenue Chart */}
      <div className="mb-8">
        <RevenueChart />
      </div>

      {/* Bottom Section: Inventory Table + Notifications */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          <InventoryTable />
        </div>
        <div>
          <NotificationPanel />
        </div>
      </div>
    </div>
  );
}
