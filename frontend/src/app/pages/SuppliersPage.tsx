import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Search, Plus, TrendingUp, AlertTriangle, DollarSign, Users, Building2 } from 'lucide-react';

interface Supplier {
  id: string;
  name: string;
  phone: string;
  email?: string;
  totalPurchases: number;
  paid: number;
  remaining: number;
  status: 'active' | 'overdue' | 'blocked';
}

const mockSuppliers: Supplier[] = [
  {
    id: '1',
    name: 'MediCorp Pharmaceuticals',
    phone: '+1 555-123-4567',
    email: 'orders@medicorp.com',
    totalPurchases: 45280.50,
    paid: 40280.50,
    remaining: 5000.00,
    status: 'active',
  },
  {
    id: '2',
    name: 'Global Health Supplies',
    phone: '+1 555-234-5678',
    email: 'sales@globalhealth.com',
    totalPurchases: 32150.75,
    paid: 28150.75,
    remaining: 4000.00,
    status: 'overdue',
  },
  {
    id: '3',
    name: 'PharmaDistributors Inc',
    phone: '+1 555-345-6789',
    totalPurchases: 58920.00,
    paid: 58920.00,
    remaining: 0,
    status: 'active',
  },
  {
    id: '4',
    name: 'MedSupply Co',
    phone: '+1 555-456-7890',
    email: 'info@medsupply.com',
    totalPurchases: 12450.25,
    paid: 8450.25,
    remaining: 4000.00,
    status: 'blocked',
  },
];

export function SuppliersPage() {
  const navigate = useNavigate();
  const [searchQuery, setSearchQuery] = useState('');

  const getStatusBadge = (status: string) => {
    const styles = {
      active: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      overdue: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      blocked: 'bg-red-100 text-red-700 border-red-200',
    };
    const labels = {
      active: 'Active',
      overdue: 'Overdue',
      blocked: 'Blocked',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  const filteredSuppliers = mockSuppliers.filter((supplier) =>
    supplier.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    supplier.phone.includes(searchQuery)
  );

  // Calculate totals
  const totalSuppliers = mockSuppliers.length;
  const totalOutstanding = mockSuppliers.reduce((sum, s) => sum + s.remaining, 0);
  const thisMonthPurchases = 18450.75; // Mock data
  const overduePayments = mockSuppliers.filter(s => s.status === 'overdue').reduce((sum, s) => sum + s.remaining, 0);

  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Suppliers Management</h1>
        <p className="text-gray-500">Manage suppliers and track payables</p>
      </div>

      {/* Overview Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
              <Users className="w-6 h-6 text-blue-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">Total Suppliers</p>
              <p className="text-xs text-gray-400">Active & Inactive</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">{totalSuppliers}</p>
        </div>

        <div className="bg-gradient-to-br from-red-50 to-orange-50 border border-red-200 rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-gradient-to-br from-red-500 to-orange-500 rounded-xl flex items-center justify-center">
              <DollarSign className="w-6 h-6 text-white" />
            </div>
            <div>
              <p className="text-sm font-medium text-red-700">Outstanding Payables</p>
              <p className="text-xs text-red-600">Total owed</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-red-700">${totalOutstanding.toFixed(2)}</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center">
              <TrendingUp className="w-6 h-6 text-teal-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">This Month Purchases</p>
              <p className="text-xs text-gray-400">March 2024</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">${thisMonthPurchases.toFixed(2)}</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
              <AlertTriangle className="w-6 h-6 text-yellow-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">Overdue Payments</p>
              <p className="text-xs text-gray-400">Needs attention</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">${overduePayments.toFixed(2)}</p>
        </div>
      </div>

      {/* Suppliers Table */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-200">
          <div className="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
            {/* Search */}
            <div className="relative flex-1 w-full md:w-auto">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search suppliers..."
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
              />
            </div>

            {/* Add Supplier Button */}
            <button
              onClick={() => navigate('/suppliers/add')}
              className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm hover:shadow-md whitespace-nowrap"
            >
              <Plus className="w-4 h-4" />
              <span className="font-medium">Add Supplier</span>
            </button>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Supplier Name</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Phone</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Total Purchases</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Paid</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Remaining</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {filteredSuppliers.map((supplier) => {
                const statusBadge = getStatusBadge(supplier.status);
                return (
                  <tr
                    key={supplier.id}
                    onClick={() => navigate(`/suppliers/${supplier.id}`)}
                    className="hover:bg-gray-50 transition-colors cursor-pointer"
                  >
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center flex-shrink-0">
                          <Building2 className="w-5 h-5 text-white" />
                        </div>
                        <div>
                          <p className="font-semibold text-gray-900">{supplier.name}</p>
                          {supplier.email && <p className="text-xs text-gray-500">{supplier.email}</p>}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-gray-900">{supplier.phone}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">${supplier.totalPurchases.toFixed(2)}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-emerald-600">${supplier.paid.toFixed(2)}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`font-bold text-lg ${supplier.remaining > 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                        ${supplier.remaining.toFixed(2)}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${statusBadge.style}`}>
                        {statusBadge.label}
                      </span>
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
