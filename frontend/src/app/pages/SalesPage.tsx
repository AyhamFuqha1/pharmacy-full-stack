import { ShoppingCart, TrendingUp, DollarSign, Users } from 'lucide-react';

export function SalesPage() {
  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Sales Management</h1>
        <p className="text-gray-600">Track sales orders, invoices, and revenue</p>
      </div>

      {/* Placeholder Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
        <div className="inline-flex items-center justify-center w-16 h-16 bg-teal-100 rounded-full mb-4">
          <ShoppingCart className="w-8 h-8 text-teal-600" />
        </div>
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Sales Management</h2>
        <p className="text-gray-600 mb-6 max-w-md mx-auto">
          This feature is coming soon. You'll be able to manage sales orders, generate invoices, and track revenue performance.
        </p>
        <div className="flex items-center justify-center gap-4 text-sm text-gray-500">
          <div className="flex items-center gap-2">
            <ShoppingCart className="w-4 h-4" />
            <span>Sales Orders</span>
          </div>
          <div className="flex items-center gap-2">
            <DollarSign className="w-4 h-4" />
            <span>Invoicing</span>
          </div>
          <div className="flex items-center gap-2">
            <TrendingUp className="w-4 h-4" />
            <span>Revenue Analytics</span>
          </div>
          <div className="flex items-center gap-2">
            <Users className="w-4 h-4" />
            <span>Customer Orders</span>
          </div>
        </div>
      </div>
    </div>
  );
}
