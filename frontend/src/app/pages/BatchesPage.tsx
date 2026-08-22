import { Package, Calendar, AlertCircle } from 'lucide-react';

export function BatchesPage() {
  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Batch Management</h1>
        <p className="text-gray-600">Track and manage product batches</p>
      </div>

      {/* Placeholder Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
        <div className="inline-flex items-center justify-center w-16 h-16 bg-teal-100 rounded-full mb-4">
          <Package className="w-8 h-8 text-teal-600" />
        </div>
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Batch Management</h2>
        <p className="text-gray-600 mb-6 max-w-md mx-auto">
          This feature is coming soon. You'll be able to track batch numbers, expiry dates, and stock levels for each product batch.
        </p>
        <div className="flex items-center justify-center gap-4 text-sm text-gray-500">
          <div className="flex items-center gap-2">
            <Calendar className="w-4 h-4" />
            <span>Expiry Tracking</span>
          </div>
          <div className="flex items-center gap-2">
            <Package className="w-4 h-4" />
            <span>Batch Numbers</span>
          </div>
          <div className="flex items-center gap-2">
            <AlertCircle className="w-4 h-4" />
            <span>Stock Alerts</span>
          </div>
        </div>
      </div>
    </div>
  );
}
