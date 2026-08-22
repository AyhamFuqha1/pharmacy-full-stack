import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  ArrowLeft,
  Edit,
  Trash2,
  Plus,
  TrendingUp,
  DollarSign,
  Package,
  Sparkles,
  AlertCircle,
} from 'lucide-react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

interface Batch {
  id: string;
  batchNumber: string;
  expiryDate: string;
  quantity: number;
  purchasePrice: number;
  status: 'safe' | 'warning' | 'expired';
}

const mockBatches: Batch[] = [
  {
    id: '1',
    batchNumber: 'BT-2024-001',
    expiryDate: '2026-12-15',
    quantity: 150,
    purchasePrice: 3.99,
    status: 'safe',
  },
  {
    id: '2',
    batchNumber: 'BT-2024-002',
    expiryDate: '2026-03-20',
    quantity: 80,
    purchasePrice: 3.89,
    status: 'warning',
  },
  {
    id: '3',
    batchNumber: 'BT-2023-045',
    expiryDate: '2025-01-10',
    quantity: 25,
    purchasePrice: 4.10,
    status: 'expired',
  },
];

const salesData = [
  { month: 'Jan', sales: 320 },
  { month: 'Feb', sales: 380 },
  { month: 'Mar', sales: 420 },
  { month: 'Apr', sales: 450 },
  { month: 'May', sales: 480 },
  { month: 'Jun', sales: 520 },
];

export function ProductDetailsPage() {
  const { id } = useParams();
  const navigate = useNavigate();

  // Mock product data
  const product = {
    id: id || '1',
    name: 'Paracetamol 500mg',
    barcode: '1234567890123',
    description: 'Effective pain relief and fever reducer. Suitable for adults and children over 12 years.',
    category: 'Pain Relief',
    image: 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=800',
    purchasePrice: 3.99,
    sellingPrice: 5.99,
    profitMargin: 50.1,
    currentStock: 255,
    minStockAlert: 100,
    expiryAlertDays: 30,
    totalSold30Days: 450,
    totalProfit30Days: 900,
    avgMonthlySales: 430,
    suggestedReorder: 500,
  };

  const getBatchStatusBadge = (status: string) => {
    const styles = {
      safe: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      warning: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      expired: 'bg-red-100 text-red-700 border-red-200',
    };
    const labels = {
      safe: 'Safe',
      warning: 'Expiring Soon',
      expired: 'Expired',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  return (
    <div className="p-8">
      {/* Back Button */}
      <button
        onClick={() => navigate('/products')}
        className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6 transition-colors"
      >
        <ArrowLeft className="w-5 h-5" />
        <span className="font-medium">Back to Products</span>
      </button>

      {/* Product Overview Section */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 p-8">
          {/* Left: Image */}
          <div>
            <div className="aspect-square rounded-2xl overflow-hidden bg-gray-100">
              <img src={product.image} alt={product.name} className="w-full h-full object-cover" />
            </div>
          </div>

          {/* Right: Details */}
          <div>
            <div className="flex items-start justify-between mb-4">
              <div>
                <h1 className="text-3xl font-bold text-gray-900 mb-2">{product.name}</h1>
                <p className="text-sm text-gray-500 font-mono mb-3">{product.barcode}</p>
                <span className="inline-flex px-3 py-1 bg-teal-100 text-teal-700 text-sm font-medium rounded-lg">
                  {product.category}
                </span>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => navigate(`/products/${product.id}/edit`)}
                  className="p-2.5 bg-teal-50 text-teal-600 rounded-xl hover:bg-teal-100 transition-colors"
                >
                  <Edit className="w-5 h-5" />
                </button>
                <button className="p-2.5 bg-red-50 text-red-600 rounded-xl hover:bg-red-100 transition-colors">
                  <Trash2 className="w-5 h-5" />
                </button>
              </div>
            </div>

            <p className="text-gray-600 leading-relaxed mb-6">{product.description}</p>

            <div className="grid grid-cols-2 gap-4 mb-6">
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Purchase Price</p>
                <p className="text-xl font-bold text-gray-900">${product.purchasePrice}</p>
              </div>
              <div className="p-4 bg-teal-50 rounded-xl">
                <p className="text-sm text-teal-600 mb-1">Selling Price</p>
                <p className="text-xl font-bold text-teal-700">${product.sellingPrice}</p>
              </div>
              <div className="p-4 bg-emerald-50 rounded-xl">
                <p className="text-sm text-emerald-600 mb-1">Profit Margin</p>
                <p className="text-xl font-bold text-emerald-700">{product.profitMargin}%</p>
              </div>
              <div className="p-4 bg-blue-50 rounded-xl">
                <p className="text-sm text-blue-600 mb-1">Current Stock</p>
                <p className="text-xl font-bold text-blue-700">{product.currentStock} units</p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Min Stock Alert</label>
                <div className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 font-semibold">
                  {product.minStockAlert} units
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Expiry Alert Days</label>
                <div className="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 font-semibold">
                  {product.expiryAlertDays} days
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Smart Insights Section */}
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-6">
        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center">
              <TrendingUp className="w-5 h-5 text-purple-600" />
            </div>
            <div>
              <p className="text-sm text-gray-500">Total Sold</p>
              <p className="text-xs text-gray-400">Last 30 days</p>
            </div>
          </div>
          <p className="text-2xl font-bold text-gray-900">{product.totalSold30Days}</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
              <DollarSign className="w-5 h-5 text-emerald-600" />
            </div>
            <div>
              <p className="text-sm text-gray-500">Total Profit</p>
              <p className="text-xs text-gray-400">Last 30 days</p>
            </div>
          </div>
          <p className="text-2xl font-bold text-gray-900">${product.totalProfit30Days}</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
              <Package className="w-5 h-5 text-blue-600" />
            </div>
            <div>
              <p className="text-sm text-gray-500">Avg Monthly Sales</p>
              <p className="text-xs text-gray-400">6 months trend</p>
            </div>
          </div>
          <p className="text-2xl font-bold text-gray-900">{product.avgMonthlySales}</p>
        </div>

        <div className="bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-200 rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 bg-gradient-to-br from-teal-500 to-emerald-500 rounded-xl flex items-center justify-center">
              <Sparkles className="w-5 h-5 text-white" />
            </div>
            <div>
              <p className="text-sm text-teal-700 font-semibold">AI Suggestion</p>
              <p className="text-xs text-teal-600">Reorder Qty</p>
            </div>
          </div>
          <p className="text-2xl font-bold text-teal-700">{product.suggestedReorder} units</p>
        </div>
      </div>

      {/* Sales Trend Chart */}
      <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 mb-6">
        <h3 className="text-lg font-bold text-gray-900 mb-4">Sales Trend (6 Months)</h3>
        <ResponsiveContainer width="100%" height={240}>
          <LineChart data={salesData}>
            <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
            <XAxis dataKey="month" stroke="#94a3b8" style={{ fontSize: '12px' }} />
            <YAxis stroke="#94a3b8" style={{ fontSize: '12px' }} />
            <Tooltip
              contentStyle={{
                backgroundColor: '#fff',
                border: '1px solid #e5e7eb',
                borderRadius: '12px',
                boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)',
              }}
            />
            <Line
              type="monotone"
              dataKey="sales"
              stroke="#0D7D6D"
              strokeWidth={3}
              dot={{ fill: '#0D7D6D', r: 5 }}
              activeDot={{ r: 7 }}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>

      {/* Batch Management Section */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-bold text-gray-900">Batch Management</h3>
            <p className="text-sm text-gray-500">Track and manage product batches</p>
          </div>
          <button className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm">
            <Plus className="w-4 h-4" />
            <span className="font-medium">Add Batch</span>
          </button>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Batch Number</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Expiry Date</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Quantity</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Purchase Price</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                <th className="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {mockBatches.map((batch) => {
                const statusBadge = getBatchStatusBadge(batch.status);
                return (
                  <tr key={batch.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-6 py-4">
                      <span className="font-mono font-semibold text-gray-900">{batch.batchNumber}</span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2">
                        {batch.status === 'warning' && <AlertCircle className="w-4 h-4 text-yellow-600" />}
                        {batch.status === 'expired' && <AlertCircle className="w-4 h-4 text-red-600" />}
                        <span className="text-gray-900">{batch.expiryDate}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">{batch.quantity} units</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">${batch.purchasePrice}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${statusBadge.style}`}>
                        {statusBadge.label}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center justify-end gap-2">
                        <button className="p-2 hover:bg-gray-100 text-gray-600 rounded-lg transition-colors">
                          <Edit className="w-4 h-4" />
                        </button>
                        <button className="p-2 hover:bg-red-50 text-red-600 rounded-lg transition-colors">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
