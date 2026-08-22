import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  ArrowLeft,
  Building2,
  Phone,
  Mail,
  MapPin,
  Edit,
  Plus,
  FileText,
  DollarSign,
  Calendar,
  Printer,
} from 'lucide-react';

type TabType = 'purchases' | 'payments';

interface Purchase {
  id: string;
  invoiceNo: string;
  date: string;
  total: number;
  paid: number;
  remaining: number;
  status: 'draft' | 'posted' | 'partial' | 'paid';
}

interface Payment {
  id: string;
  date: string;
  amount: number;
  method: string;
  reference: string;
}

const mockPurchases: Purchase[] = [
  { id: '1', invoiceNo: 'PI-2024-001', date: '2024-03-01', total: 5280.50, paid: 5280.50, remaining: 0, status: 'paid' },
  { id: '2', invoiceNo: 'PI-2024-002', date: '2024-02-28', total: 3200.00, paid: 1200.00, remaining: 2000.00, status: 'partial' },
  { id: '3', invoiceNo: 'PI-2024-003', date: '2024-02-25', total: 4800.00, paid: 0, remaining: 4800.00, status: 'posted' },
  { id: '4', invoiceNo: 'PI-2024-004', date: '2024-02-20', total: 2150.00, paid: 0, remaining: 2150.00, status: 'draft' },
];

const mockPayments: Payment[] = [
  { id: '1', date: '2024-03-01', amount: 5280.50, method: 'Bank Transfer', reference: 'TRX-20240301-001' },
  { id: '2', date: '2024-02-28', amount: 1200.00, method: 'Cheque', reference: 'CHQ-456789' },
];

export function SupplierDetailsPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<TabType>('purchases');

  const supplier = {
    id: id || '1',
    name: 'MediCorp Pharmaceuticals',
    phone: '+1 555-123-4567',
    email: 'orders@medicorp.com',
    address: '123 Industrial Ave, Medical District, State 12345',
    currentPayable: 9950.00,
    lastPurchaseDate: '2024-03-01',
    totalPurchases: 45280.50,
    totalPaid: 35330.50,
  };

  const getStatusBadge = (status: string) => {
    const styles = {
      draft: 'bg-gray-100 text-gray-700 border-gray-200',
      posted: 'bg-blue-100 text-blue-700 border-blue-200',
      partial: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      paid: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    };
    const labels = {
      draft: 'Draft',
      posted: 'Posted',
      partial: 'Partially Paid',
      paid: 'Paid',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  return (
    <div className="p-8">
      {/* Back Button */}
      <button
        onClick={() => navigate('/suppliers')}
        className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6 transition-colors"
      >
        <ArrowLeft className="w-5 h-5" />
        <span className="font-medium">Back to Suppliers</span>
      </button>

      {/* Supplier Overview Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 mb-6">
        <div className="flex flex-col lg:flex-row gap-8">
          {/* Left: Supplier Info */}
          <div className="flex-1">
            <div className="flex items-start gap-4 mb-6">
              <div className="w-20 h-20 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center flex-shrink-0">
                <Building2 className="w-10 h-10 text-white" />
              </div>
              <div className="flex-1">
                <h1 className="text-3xl font-bold text-gray-900 mb-2">{supplier.name}</h1>
                <div className="space-y-2">
                  <div className="flex items-center gap-2 text-gray-600">
                    <Phone className="w-4 h-4" />
                    <span>{supplier.phone}</span>
                  </div>
                  {supplier.email && (
                    <div className="flex items-center gap-2 text-gray-600">
                      <Mail className="w-4 h-4" />
                      <span>{supplier.email}</span>
                    </div>
                  )}
                  {supplier.address && (
                    <div className="flex items-center gap-2 text-gray-600">
                      <MapPin className="w-4 h-4" />
                      <span>{supplier.address}</span>
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="flex gap-3">
              <button className="flex items-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors">
                <Edit className="w-4 h-4" />
                <span className="font-medium">Edit Supplier</span>
              </button>
              <button
                onClick={() => navigate('/suppliers/purchase-invoice/new')}
                className="flex items-center gap-2 px-4 py-2.5 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all shadow-sm"
              >
                <Plus className="w-4 h-4" />
                <span className="font-medium">New Purchase</span>
              </button>
              <button
                onClick={() => navigate('/suppliers/payment/new')}
                className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm"
              >
                <DollarSign className="w-4 h-4" />
                <span className="font-medium">Make Payment</span>
              </button>
            </div>
          </div>

          {/* Right: Financial Summary */}
          <div className="lg:w-96">
            <div className="grid grid-cols-2 gap-4">
              <div className="p-4 bg-gradient-to-br from-red-50 to-orange-50 border border-red-200 rounded-xl">
                <p className="text-sm text-red-600 mb-1 font-semibold">Current Payable</p>
                <p className="text-3xl font-bold text-red-700">${supplier.currentPayable.toFixed(2)}</p>
              </div>
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Last Purchase</p>
                <p className="text-lg font-bold text-gray-900">{supplier.lastPurchaseDate}</p>
              </div>
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Total Purchases</p>
                <p className="text-xl font-bold text-gray-900">${supplier.totalPurchases.toFixed(2)}</p>
              </div>
              <div className="p-4 bg-gray-50 rounded-xl">
                <p className="text-sm text-gray-500 mb-1">Total Paid</p>
                <p className="text-xl font-bold text-emerald-600">${supplier.totalPaid.toFixed(2)}</p>
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
            {(['purchases', 'payments'] as TabType[]).map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`flex-1 px-6 py-3 rounded-xl font-medium transition-all ${
                  activeTab === tab
                    ? 'bg-gradient-to-r from-teal-600 to-emerald-500 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-50'
                }`}
              >
                {tab === 'purchases' ? 'Purchase History' : 'Payment History'}
              </button>
            ))}
          </div>
        </div>

        {/* Tab Content */}
        <div className="p-6">
          {activeTab === 'purchases' && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Invoice No</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Total</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Paid</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Remaining</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {mockPurchases.map((purchase) => {
                    const statusBadge = getStatusBadge(purchase.status);
                    return (
                      <tr
                        key={purchase.id}
                        onClick={() => navigate(`/suppliers/purchase-invoice/${purchase.id}`)}
                        className="hover:bg-gray-50 transition-colors cursor-pointer"
                      >
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2">
                            <FileText className="w-4 h-4 text-gray-400" />
                            <span className="font-mono font-semibold text-gray-900">{purchase.invoiceNo}</span>
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2">
                            <Calendar className="w-4 h-4 text-gray-400" />
                            <span className="text-gray-900">{purchase.date}</span>
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-gray-900">${purchase.total.toFixed(2)}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-emerald-600">${purchase.paid.toFixed(2)}</span>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-semibold text-red-600">${purchase.remaining.toFixed(2)}</span>
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
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Method</th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
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
                        <span className="font-mono text-sm text-gray-600">{payment.reference}</span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end">
                          <button className="p-2 hover:bg-gray-100 text-gray-600 rounded-lg transition-colors">
                            <Printer className="w-4 h-4" />
                          </button>
                        </div>
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
