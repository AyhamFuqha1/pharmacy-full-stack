import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  ArrowLeft,
  User,
  Phone,
  Mail,
  MapPin,
  Edit,
  Plus,
  DollarSign,
  ShoppingCart,
  CreditCard,
  TrendingUp,
  AlertCircle,
  Calendar,
  FileText,
} from 'lucide-react';

type TabType = 'overview' | 'orders' | 'payments' | 'ledger';

interface Order {
  id: string;
  orderNumber: string;
  date: string;
  total: number;
  paid: number;
  remaining: number;
  status: 'paid' | 'partial' | 'unpaid';
}

interface Payment {
  id: string;
  date: string;
  amount: number;
  method: string;
  notes: string;
}

interface LedgerEntry {
  id: string;
  date: string;
  description: string;
  debit: number;
  credit: number;
  balance: number;
}

const mockOrders: Order[] = [
  { id: '1', orderNumber: 'ORD-2024-1245', date: '2024-03-01', total: 125.50, paid: 125.50, remaining: 0, status: 'paid' },
  { id: '2', orderNumber: 'ORD-2024-1198', date: '2024-02-28', total: 89.75, paid: 50.00, remaining: 39.75, status: 'partial' },
  { id: '3', orderNumber: 'ORD-2024-1156', date: '2024-02-25', total: 210.00, paid: 0, remaining: 210.00, status: 'unpaid' },
];

const mockPayments: Payment[] = [
  { id: '1', date: '2024-03-01', amount: 125.50, method: 'Cash', notes: 'Payment for ORD-2024-1245' },
  { id: '2', date: '2024-02-28', amount: 50.00, method: 'Card', notes: 'Partial payment for ORD-2024-1198' },
];

const mockLedger: LedgerEntry[] = [
  { id: '1', date: '2024-02-20', description: 'Opening Balance', debit: 0, credit: 0, balance: 0 },
  { id: '2', date: '2024-02-25', description: 'Order ORD-2024-1156', debit: 210.00, credit: 0, balance: 210.00 },
  { id: '3', date: '2024-02-28', description: 'Order ORD-2024-1198', debit: 89.75, credit: 0, balance: 299.75 },
  { id: '4', date: '2024-02-28', description: 'Payment Received', debit: 0, credit: 50.00, balance: 249.75 },
  { id: '5', date: '2024-03-01', description: 'Order ORD-2024-1245', debit: 125.50, credit: 0, balance: 375.25 },
  { id: '6', date: '2024-03-01', description: 'Payment Received', debit: 0, credit: 125.50, balance: 249.75 },
];

export function CustomerDetailsPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<TabType>('overview');

  // Mock customer data
  const customer = {
    id: id || '1',
    name: 'Sarah Johnson',
    phone: '+1 234-567-8901',
    email: 'sarah@example.com',
    address: '123 Main Street, Springfield, IL 62701',
    totalOrders: 32,
    totalPurchased: 1920.75,
    totalPaid: 1820.75,
    currentBalance: 100.00,
    creditLimit: 500.00,
    lastOrderDate: '2024-03-01',
    avgOrderValue: 60.02,
    largestOrder: 210.00,
    paymentBehavior: 'on-time',
  };

  const getBalanceColor = (balance: number) => {
    if (balance > 0) return 'text-red-600'; // Customer owes
    if (balance < 0) return 'text-blue-600'; // Credit balance
    return 'text-emerald-600'; // No balance
  };

  const getOrderStatusBadge = (status: string) => {
    const styles = {
      paid: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      partial: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      unpaid: 'bg-red-100 text-red-700 border-red-200',
    };
    const labels = {
      paid: 'Paid',
      partial: 'Partially Paid',
      unpaid: 'Unpaid',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  const exceedsCreditLimit = customer.currentBalance > customer.creditLimit;

  return (
    <div className="p-8">
      {/* Back Button */}
      <button
        onClick={() => navigate('/customers')}
        className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6 transition-colors"
      >
        <ArrowLeft className="w-5 h-5" />
        <span className="font-medium">Back to Customers</span>
      </button>

      {/* Credit Limit Warning */}
      {exceedsCreditLimit && (
        <div className="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6 flex items-center gap-3">
          <AlertCircle className="w-6 h-6 text-red-600 flex-shrink-0" />
          <div>
            <h3 className="font-semibold text-red-900">Credit Limit Exceeded</h3>
            <p className="text-sm text-red-700">
              Customer balance (${customer.currentBalance.toFixed(2)}) exceeds credit limit (${customer.creditLimit.toFixed(2)})
            </p>
          </div>
        </div>
      )}

      {/* Customer Summary Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 mb-6">
        <div className="flex flex-col lg:flex-row gap-8">
          {/* Left: Customer Info */}
          <div className="flex-1">
            <div className="flex items-start gap-4 mb-6">
              <div className="w-20 h-20 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center flex-shrink-0">
                <User className="w-10 h-10 text-white" />
              </div>
              <div className="flex-1">
                <h1 className="text-3xl font-bold text-gray-900 mb-2">{customer.name}</h1>
                <div className="space-y-2">
                  <div className="flex items-center gap-2 text-gray-600">
                    <Phone className="w-4 h-4" />
                    <span>{customer.phone}</span>
                  </div>
                  {customer.email && (
                    <div className="flex items-center gap-2 text-gray-600">
                      <Mail className="w-4 h-4" />
                      <span>{customer.email}</span>
                    </div>
                  )}
                  {customer.address && (
                    <div className="flex items-center gap-2 text-gray-600">
                      <MapPin className="w-4 h-4" />
                      <span>{customer.address}</span>
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="flex gap-3">
              <button className="flex items-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors">
                <Edit className="w-4 h-4" />
                <span className="font-medium">Edit Customer</span>
              </button>
              <button className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm">
                <Plus className="w-4 h-4" />
                <span className="font-medium">Add Payment</span>
              </button>
            </div>
          </div>

          {/* Right: Financial Summary */}
          <div className="lg:w-96">
            <div className="grid grid-cols-2 gap-4">
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Total Orders</p>
                <p className="text-2xl font-bold text-gray-900">{customer.totalOrders}</p>
              </div>
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Total Purchased</p>
                <p className="text-2xl font-bold text-gray-900">${customer.totalPurchased.toFixed(2)}</p>
              </div>
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Total Paid</p>
                <p className="text-2xl font-bold text-gray-900">${customer.totalPaid.toFixed(2)}</p>
              </div>
              <div className="p-4 bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-200 rounded-xl">
                <p className="text-sm text-teal-600 mb-1 font-semibold">Current Balance</p>
                <p className={`text-3xl font-bold ${getBalanceColor(customer.currentBalance)}`}>
                  ${customer.currentBalance.toFixed(2)}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs Section */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        {/* Tab Headers */}
        <div className="border-b border-gray-200">
          <div className="flex gap-1 p-2">
            {(['overview', 'orders', 'payments', 'ledger'] as TabType[]).map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`flex-1 px-6 py-3 rounded-xl font-medium transition-all ${
                  activeTab === tab
                    ? 'bg-gradient-to-r from-teal-600 to-emerald-500 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-50'
                }`}
              >
                {tab.charAt(0).toUpperCase() + tab.slice(1)}
              </button>
            ))}
          </div>
        </div>

        {/* Tab Content */}
        <div className="p-6">
          {activeTab === 'overview' && (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
              <div className="bg-gray-50 rounded-xl p-6">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <Calendar className="w-5 h-5 text-blue-600" />
                  </div>
                  <p className="text-sm font-medium text-gray-600">Last Order Date</p>
                </div>
                <p className="text-xl font-bold text-gray-900">{customer.lastOrderDate}</p>
              </div>

              <div className="bg-gray-50 rounded-xl p-6">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <DollarSign className="w-5 h-5 text-purple-600" />
                  </div>
                  <p className="text-sm font-medium text-gray-600">Avg Order Value</p>
                </div>
                <p className="text-xl font-bold text-gray-900">${customer.avgOrderValue.toFixed(2)}</p>
              </div>

              <div className="bg-gray-50 rounded-xl p-6">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                    <TrendingUp className="w-5 h-5 text-orange-600" />
                  </div>
                  <p className="text-sm font-medium text-gray-600">Largest Order</p>
                </div>
                <p className="text-xl font-bold text-gray-900">${customer.largestOrder.toFixed(2)}</p>
              </div>

              <div className="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-xl p-6">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <CreditCard className="w-5 h-5 text-emerald-600" />
                  </div>
                  <p className="text-sm font-medium text-emerald-700">Payment Behavior</p>
                </div>
                <p className="text-xl font-bold text-emerald-700">On Time</p>
              </div>
            </div>
          )}

          {activeTab === 'orders' && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Order Number</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Total</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Paid</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Remaining</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {mockOrders.map((order) => {
                    const statusBadge = getOrderStatusBadge(order.status);
                    return (
                      <tr key={order.id} className="hover:bg-gray-50 transition-colors cursor-pointer">
                        <td className="px-6 py-4">
                          <span className="font-mono font-semibold text-gray-900">{order.orderNumber}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="text-gray-900">{order.date}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-gray-900">${order.total.toFixed(2)}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-emerald-600">${order.paid.toFixed(2)}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-red-600">${order.remaining.toFixed(2)}</span>
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
          )}

          {activeTab === 'payments' && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Payment Date</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Payment Method</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Notes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {mockPayments.map((payment) => (
                    <tr key={payment.id} className="hover:bg-gray-50 transition-colors">
                      <td className="px-6 py-4">
                        <span className="text-gray-900">{payment.date}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-bold text-emerald-600 text-lg">${payment.amount.toFixed(2)}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-flex px-3 py-1 bg-teal-50 text-teal-700 text-xs font-medium rounded-lg">
                          {payment.method}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-gray-600">{payment.notes}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'ledger' && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Debit</th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Credit</th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Running Balance</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {mockLedger.map((entry) => (
                    <tr key={entry.id} className="hover:bg-gray-50 transition-colors">
                      <td className="px-6 py-4">
                        <span className="text-gray-900">{entry.date}</span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <FileText className="w-4 h-4 text-gray-400" />
                          <span className="text-gray-900">{entry.description}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right">
                        {entry.debit > 0 ? (
                          <span className="font-semibold text-red-600">${entry.debit.toFixed(2)}</span>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {entry.credit > 0 ? (
                          <span className="font-semibold text-emerald-600">${entry.credit.toFixed(2)}</span>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <span className="font-bold text-gray-900 text-lg">${entry.balance.toFixed(2)}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
