import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Search, Plus, Filter, User } from 'lucide-react';
import { AddCustomerModal } from '../components/AddCustomerModal';

interface Customer {
  id: string;
  name: string;
  phone: string;
  email?: string;
  avatar?: string;
  totalOrders: number;
  totalPurchased: number;
  totalPaid: number;
  currentBalance: number;
  status: 'good' | 'small-debt' | 'high-debt';
}

const mockCustomers: Customer[] = [
  {
    id: '1',
    name: 'John Smith',
    phone: '+1 234-567-8900',
    email: 'john@example.com',
    totalOrders: 45,
    totalPurchased: 2850.50,
    totalPaid: 2850.50,
    currentBalance: 0,
    status: 'good',
  },
  {
    id: '2',
    name: 'Sarah Johnson',
    phone: '+1 234-567-8901',
    email: 'sarah@example.com',
    totalOrders: 32,
    totalPurchased: 1920.75,
    totalPaid: 1820.75,
    currentBalance: 100.00,
    status: 'small-debt',
  },
  {
    id: '3',
    name: 'Michael Brown',
    phone: '+1 234-567-8902',
    totalOrders: 28,
    totalPurchased: 3420.00,
    totalPaid: 2620.00,
    currentBalance: 800.00,
    status: 'high-debt',
  },
  {
    id: '4',
    name: 'Emily Davis',
    phone: '+1 234-567-8903',
    email: 'emily@example.com',
    totalOrders: 52,
    totalPurchased: 4150.25,
    totalPaid: 4250.25,
    currentBalance: -100.00,
    status: 'good',
  },
  {
    id: '5',
    name: 'Robert Wilson',
    phone: '+1 234-567-8904',
    totalOrders: 18,
    totalPurchased: 980.50,
    totalPaid: 980.50,
    currentBalance: 0,
    status: 'good',
  },
];

export function CustomersPage() {
  const navigate = useNavigate();
  const [searchQuery, setSearchQuery] = useState('');
  const [filterStatus, setFilterStatus] = useState('all');
  const [showAddModal, setShowAddModal] = useState(false);

  const getStatusBadge = (status: string) => {
    const styles = {
      good: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      'small-debt': 'bg-yellow-100 text-yellow-700 border-yellow-200',
      'high-debt': 'bg-red-100 text-red-700 border-red-200',
    };
    const labels = {
      good: 'Good',
      'small-debt': 'Small Debt',
      'high-debt': 'High Debt',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  const getBalanceColor = (balance: number) => {
    if (balance > 0) return 'text-red-600'; // Customer owes
    if (balance < 0) return 'text-blue-600'; // Credit balance
    return 'text-emerald-600'; // No balance
  };

  const filteredCustomers = mockCustomers.filter((customer) => {
    const matchesSearch =
      customer.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      customer.phone.includes(searchQuery);

    const matchesFilter =
      filterStatus === 'all' ||
      (filterStatus === 'debt' && customer.currentBalance > 0) ||
      (filterStatus === 'no-debt' && customer.currentBalance <= 0);

    return matchesSearch && matchesFilter;
  });

  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Customers</h1>
        <p className="text-gray-500">Manage your pharmacy customers and track balances</p>
      </div>

      {/* Controls Bar */}
      <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 mb-6">
        <div className="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
          {/* Left Side Controls */}
          <div className="flex flex-1 gap-3 w-full lg:w-auto">
            {/* Search */}
            <div className="relative flex-1 lg:w-96">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search by name or phone..."
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
              />
            </div>

            {/* Filter */}
            <div className="relative">
              <Filter className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="pl-9 pr-8 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 appearance-none cursor-pointer"
              >
                <option value="all">All Customers</option>
                <option value="debt">Has Debt</option>
                <option value="no-debt">No Debt</option>
              </select>
            </div>
          </div>

          {/* Add Customer Button */}
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm hover:shadow-md"
          >
            <Plus className="w-4 h-4" />
            <span className="font-medium">Add Customer</span>
          </button>
        </div>
      </div>

      {/* Customers Table */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Customer Name</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Phone</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Total Orders</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Total Purchased</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Total Paid</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Current Balance</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {filteredCustomers.map((customer) => {
                const statusBadge = getStatusBadge(customer.status);
                return (
                  <tr
                    key={customer.id}
                    onClick={() => navigate(`/customers/${customer.id}`)}
                    className="hover:bg-gray-50 transition-colors cursor-pointer"
                  >
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center flex-shrink-0">
                          <User className="w-5 h-5 text-white" />
                        </div>
                        <div>
                          <p className="font-semibold text-gray-900">{customer.name}</p>
                          {customer.email && <p className="text-xs text-gray-500">{customer.email}</p>}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-gray-900">{customer.phone}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">{customer.totalOrders}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">${customer.totalPurchased.toFixed(2)}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">${customer.totalPaid.toFixed(2)}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`font-bold text-lg ${getBalanceColor(customer.currentBalance)}`}>
                        ${Math.abs(customer.currentBalance).toFixed(2)}
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

      {/* Add Customer Modal */}
      {showAddModal && <AddCustomerModal onClose={() => setShowAddModal(false)} />}
    </div>
  );
}
