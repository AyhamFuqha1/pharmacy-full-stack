import { useState } from 'react';
import { useNavigate } from 'react-router';
import {
  DollarSign,
  TrendingUp,
  AlertCircle,
  CreditCard,
  Calendar,
  FileText,
  Eye,
  Printer,
  Search,
  Filter,
} from 'lucide-react';

type PaymentMethod = 'cash' | 'cheque' | 'bank-transfer' | 'card';

interface PaymentFormData {
  customer: string;
  paymentMethod: PaymentMethod;
  amount: string;
  date: string;
  notes: string;
  linkedInvoice: string;
  // Cheque fields
  chequeNumber: string;
  bankName: string;
  dueDate: string;
  accountHolder: string;
  // Bank transfer fields
  transactionReference: string;
  transferBankName: string;
}

interface PaymentHistoryItem {
  id: string;
  date: string;
  customer: string;
  method: PaymentMethod;
  amount: number;
  invoice: string;
  status: 'completed' | 'pending' | 'cleared';
}

const mockCustomers = [
  { id: '1', name: 'Sarah Johnson', totalDue: 249.75, previousPayments: 1820.75 },
  { id: '2', name: 'Michael Brown', totalDue: 800.00, previousPayments: 2620.00 },
  { id: '3', name: 'John Smith', totalDue: 0, previousPayments: 2850.50 },
];

const mockInvoices = [
  { id: 'INV-2024-001', amount: 125.50, customer: 'Sarah Johnson' },
  { id: 'INV-2024-002', amount: 89.75, customer: 'Sarah Johnson' },
  { id: 'INV-2024-003', amount: 210.00, customer: 'Michael Brown' },
];

const mockPaymentHistory: PaymentHistoryItem[] = [
  {
    id: '1',
    date: '2024-03-03',
    customer: 'Sarah Johnson',
    method: 'cash',
    amount: 125.50,
    invoice: 'INV-2024-001',
    status: 'completed',
  },
  {
    id: '2',
    date: '2024-03-02',
    customer: 'Michael Brown',
    method: 'cheque',
    amount: 500.00,
    invoice: 'INV-2024-003',
    status: 'pending',
  },
  {
    id: '3',
    date: '2024-03-01',
    customer: 'John Smith',
    method: 'card',
    amount: 89.75,
    invoice: 'INV-2024-002',
    status: 'completed',
  },
];

export function PaymentsPage() {
  const navigate = useNavigate();
  const [formData, setFormData] = useState<PaymentFormData>({
    customer: '',
    paymentMethod: 'cash',
    amount: '',
    date: new Date().toISOString().split('T')[0],
    notes: '',
    linkedInvoice: '',
    chequeNumber: '',
    bankName: '',
    dueDate: '',
    accountHolder: '',
    transactionReference: '',
    transferBankName: '',
  });

  const [searchHistory, setSearchHistory] = useState('');
  const [filterMethod, setFilterMethod] = useState('all');

  const selectedCustomer = mockCustomers.find((c) => c.id === formData.customer);
  const remainingBalance = selectedCustomer
    ? selectedCustomer.totalDue - (parseFloat(formData.amount) || 0)
    : 0;
  const isOverpayment = remainingBalance < 0;

  const handleInputChange = (field: keyof PaymentFormData, value: string) => {
    setFormData({ ...formData, [field]: value });
  };

  const handleSavePayment = () => {
    console.log('Payment saved:', formData);
    alert('Payment saved successfully!');
    // Reset form
    setFormData({
      customer: '',
      paymentMethod: 'cash',
      amount: '',
      date: new Date().toISOString().split('T')[0],
      notes: '',
      linkedInvoice: '',
      chequeNumber: '',
      bankName: '',
      dueDate: '',
      accountHolder: '',
      transactionReference: '',
      transferBankName: '',
    });
  };

  const handleSaveAndPrint = () => {
    if (!selectedCustomer || !formData.amount) return;

    // Generate receipt number
    const receiptNumber = `RCP-${new Date().getFullYear()}-${Math.floor(Math.random() * 1000).toString().padStart(3, '0')}`;
    
    // Get reference based on payment method
    let reference = '';
    if (formData.paymentMethod === 'cheque') {
      reference = formData.chequeNumber;
    } else if (formData.paymentMethod === 'bank-transfer') {
      reference = formData.transactionReference;
    }

    // Navigate to receipt page with data
    const params = new URLSearchParams({
      receiptNumber,
      date: formData.date,
      customerName: selectedCustomer.name,
      customerPhone: '+1 234-567-8901', // You can add phone to customer data
      accountBalanceBefore: selectedCustomer.totalDue.toString(),
      paymentMethod: formData.paymentMethod,
      amount: formData.amount,
      remainingBalance: remainingBalance.toString(),
    });

    if (reference) params.append('reference', reference);
    if (formData.linkedInvoice) params.append('linkedInvoice', formData.linkedInvoice);
    if (formData.notes) params.append('notes', formData.notes);

    navigate(`/receipt?${params.toString()}`);
  };

  const getMethodBadge = (method: PaymentMethod) => {
    const styles = {
      cash: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      cheque: 'bg-blue-100 text-blue-700 border-blue-200',
      'bank-transfer': 'bg-purple-100 text-purple-700 border-purple-200',
      card: 'bg-orange-100 text-orange-700 border-orange-200',
    };
    const labels = {
      cash: 'Cash',
      cheque: 'Cheque',
      'bank-transfer': 'Bank Transfer',
      card: 'Card',
    };
    return { style: styles[method], label: labels[method] };
  };

  const getStatusBadge = (status: string) => {
    const styles = {
      completed: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      pending: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      cleared: 'bg-blue-100 text-blue-700 border-blue-200',
    };
    const labels = {
      completed: 'Completed',
      pending: 'Pending',
      cleared: 'Cleared',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  const filteredHistory = mockPaymentHistory.filter((payment) => {
    const matchesSearch =
      payment.customer.toLowerCase().includes(searchHistory.toLowerCase()) ||
      payment.invoice.toLowerCase().includes(searchHistory.toLowerCase());
    const matchesFilter = filterMethod === 'all' || payment.method === filterMethod;
    return matchesSearch && matchesFilter;
  });

  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Payments & Accounting</h1>
        <p className="text-gray-500">Manage customer payments and track financial transactions</p>
      </div>

      {/* Dashboard Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div className="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-xl flex items-center justify-center">
              <DollarSign className="w-6 h-6 text-white" />
            </div>
            <div>
              <p className="text-sm font-medium text-emerald-700">Today Collected</p>
              <p className="text-xs text-emerald-600">As of now</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-emerald-700">$1,245.50</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
              <TrendingUp className="w-6 h-6 text-blue-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">This Week Collected</p>
              <p className="text-xs text-gray-400">Last 7 days</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">$8,430.25</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
              <FileText className="w-6 h-6 text-yellow-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">Total Pending</p>
              <p className="text-xs text-gray-400">Awaiting clearance</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">$2,150.00</p>
        </div>

        <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center">
              <AlertCircle className="w-6 h-6 text-red-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-600">Outstanding Balances</p>
              <p className="text-xs text-gray-400">Customer debts</p>
            </div>
          </div>
          <p className="text-3xl font-bold text-gray-900">$3,820.75</p>
        </div>
      </div>

      {/* Receive Payment Section */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 mb-8">
        <h2 className="text-2xl font-bold text-gray-900 mb-6">Receive Payment</h2>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Left: Payment Form */}
          <div className="lg:col-span-2 space-y-5">
            {/* Customer Selection */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Customer <span className="text-red-500">*</span>
              </label>
              <select
                value={formData.customer}
                onChange={(e) => handleInputChange('customer', e.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
              >
                <option value="">Select customer...</option>
                {mockCustomers.map((customer) => (
                  <option key={customer.id} value={customer.id}>
                    {customer.name} - Due: ${customer.totalDue.toFixed(2)}
                  </option>
                ))}
              </select>
            </div>

            {/* Payment Method and Amount */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Payment Method <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.paymentMethod}
                  onChange={(e) => handleInputChange('paymentMethod', e.target.value as PaymentMethod)}
                  className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                >
                  <option value="cash">Cash</option>
                  <option value="cheque">Cheque</option>
                  <option value="bank-transfer">Bank Transfer</option>
                  <option value="card">Card</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Amount <span className="text-red-500">*</span>
                </label>
                <div className="relative">
                  <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-semibold">$</span>
                  <input
                    type="number"
                    value={formData.amount}
                    onChange={(e) => handleInputChange('amount', e.target.value)}
                    placeholder="0.00"
                    step="0.01"
                    min="0"
                    className="w-full pl-8 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                  />
                </div>
              </div>
            </div>

            {/* Date and Linked Invoice */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Date</label>
                <div className="relative">
                  <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                  <input
                    type="date"
                    value={formData.date}
                    onChange={(e) => handleInputChange('date', e.target.value)}
                    className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Linked Invoice <span className="text-gray-400 text-xs">(optional)</span>
                </label>
                <select
                  value={formData.linkedInvoice}
                  onChange={(e) => handleInputChange('linkedInvoice', e.target.value)}
                  disabled={!formData.customer}
                  className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all disabled:opacity-50"
                >
                  <option value="">Select invoice...</option>
                  {mockInvoices
                    .filter((inv) => inv.customer === selectedCustomer?.name)
                    .map((invoice) => (
                      <option key={invoice.id} value={invoice.id}>
                        {invoice.id} - ${invoice.amount.toFixed(2)}
                      </option>
                    ))}
                </select>
              </div>
            </div>

            {/* Conditional Fields: Cheque */}
            {formData.paymentMethod === 'cheque' && (
              <div className="p-4 bg-blue-50 border border-blue-200 rounded-xl space-y-4">
                <h3 className="font-semibold text-blue-900 mb-3">Cheque Details</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Cheque Number</label>
                    <input
                      type="text"
                      value={formData.chequeNumber}
                      onChange={(e) => handleInputChange('chequeNumber', e.target.value)}
                      placeholder="Enter cheque number"
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Bank Name</label>
                    <input
                      type="text"
                      value={formData.bankName}
                      onChange={(e) => handleInputChange('bankName', e.target.value)}
                      placeholder="Enter bank name"
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
                    <input
                      type="date"
                      value={formData.dueDate}
                      onChange={(e) => handleInputChange('dueDate', e.target.value)}
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Account Holder</label>
                    <input
                      type="text"
                      value={formData.accountHolder}
                      onChange={(e) => handleInputChange('accountHolder', e.target.value)}
                      placeholder="Enter account holder name"
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                </div>
              </div>
            )}

            {/* Conditional Fields: Bank Transfer */}
            {formData.paymentMethod === 'bank-transfer' && (
              <div className="p-4 bg-purple-50 border border-purple-200 rounded-xl space-y-4">
                <h3 className="font-semibold text-purple-900 mb-3">Bank Transfer Details</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Transaction Reference</label>
                    <input
                      type="text"
                      value={formData.transactionReference}
                      onChange={(e) => handleInputChange('transactionReference', e.target.value)}
                      placeholder="Enter transaction reference"
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Bank Name</label>
                    <input
                      type="text"
                      value={formData.transferBankName}
                      onChange={(e) => handleInputChange('transferBankName', e.target.value)}
                      placeholder="Enter bank name"
                      className="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
                    />
                  </div>
                </div>
              </div>
            )}

            {/* Notes */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Notes <span className="text-gray-400 text-xs">(optional)</span>
              </label>
              <textarea
                value={formData.notes}
                onChange={(e) => handleInputChange('notes', e.target.value)}
                placeholder="Add any additional notes..."
                rows={3}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all resize-none"
              />
            </div>

            {/* Action Buttons */}
            <div className="flex gap-3 pt-4">
              <button
                onClick={handleSavePayment}
                disabled={!formData.customer || !formData.amount}
                className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md font-semibold"
              >
                <DollarSign className="w-5 h-5" />
                <span>Save Payment</span>
              </button>
              <button
                onClick={handleSaveAndPrint}
                disabled={!formData.customer || !formData.amount}
                className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all disabled:opacity-50 disabled:cursor-not-allowed font-semibold"
              >
                <Printer className="w-5 h-5" />
                <span>Save & Print Receipt</span>
              </button>
            </div>
          </div>

          {/* Right: Live Summary Card */}
          <div>
            <div className="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-xl p-6 sticky top-8">
              <h3 className="font-bold text-gray-900 mb-4">Payment Summary</h3>

              {selectedCustomer ? (
                <div className="space-y-4">
                  <div className="p-4 bg-white rounded-xl border border-gray-200">
                    <p className="text-sm text-gray-500 mb-1">Customer</p>
                    <p className="font-semibold text-gray-900">{selectedCustomer.name}</p>
                  </div>

                  <div className="p-4 bg-white rounded-xl border border-gray-200">
                    <p className="text-sm text-gray-500 mb-1">Total Due</p>
                    <p className="text-2xl font-bold text-red-600">${selectedCustomer.totalDue.toFixed(2)}</p>
                  </div>

                  <div className="p-4 bg-white rounded-xl border border-gray-200">
                    <p className="text-sm text-gray-500 mb-1">Previous Payments</p>
                    <p className="text-xl font-bold text-gray-900">${selectedCustomer.previousPayments.toFixed(2)}</p>
                  </div>

                  {formData.amount && (
                    <>
                      <div className="p-4 bg-teal-50 border border-teal-200 rounded-xl">
                        <p className="text-sm text-teal-700 mb-1">Current Payment</p>
                        <p className="text-2xl font-bold text-teal-700">${parseFloat(formData.amount).toFixed(2)}</p>
                      </div>

                      <div className={`p-4 rounded-xl border ${isOverpayment ? 'bg-yellow-50 border-yellow-200' : 'bg-emerald-50 border-emerald-200'}`}>
                        <p className={`text-sm mb-1 font-semibold ${isOverpayment ? 'text-yellow-700' : 'text-emerald-700'}`}>
                          Remaining Balance
                        </p>
                        <p className={`text-3xl font-bold ${isOverpayment ? 'text-yellow-700' : 'text-emerald-700'}`}>
                          ${Math.abs(remainingBalance).toFixed(2)}
                        </p>
                      </div>

                      {isOverpayment && (
                        <div className="flex items-start gap-2 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                          <AlertCircle className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
                          <div>
                            <p className="text-sm font-semibold text-yellow-900">Overpayment Warning</p>
                            <p className="text-xs text-yellow-700">Payment exceeds customer balance</p>
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </div>
              ) : (
                <div className="text-center py-12">
                  <CreditCard className="w-16 h-16 text-gray-300 mx-auto mb-3" />
                  <p className="text-gray-500">Select a customer to see summary</p>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Payment History Section */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-200">
          <h2 className="text-2xl font-bold text-gray-900 mb-4">Payment History</h2>

          {/* Filters */}
          <div className="flex flex-col md:flex-row gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
              <input
                type="text"
                value={searchHistory}
                onChange={(e) => setSearchHistory(e.target.value)}
                placeholder="Search by customer or invoice..."
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
              />
            </div>

            <div className="relative">
              <Filter className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <select
                value={filterMethod}
                onChange={(e) => setFilterMethod(e.target.value)}
                className="pl-9 pr-8 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 appearance-none cursor-pointer"
              >
                <option value="all">All Methods</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="bank-transfer">Bank Transfer</option>
                <option value="card">Card</option>
              </select>
            </div>
          </div>
        </div>

        {/* Payment History Table */}
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Method</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Invoice</th>
                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                <th className="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {filteredHistory.map((payment) => {
                const methodBadge = getMethodBadge(payment.method);
                const statusBadge = getStatusBadge(payment.status);
                return (
                  <tr key={payment.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-6 py-4">
                      <span className="text-gray-900">{payment.date}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-semibold text-gray-900">{payment.customer}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${methodBadge.style}`}>
                        {methodBadge.label}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-bold text-emerald-600 text-lg">${payment.amount.toFixed(2)}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-mono text-sm text-gray-600">{payment.invoice}</span>
                    </td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${statusBadge.style}`}>
                        {statusBadge.label}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center justify-end gap-2">
                        <button className="p-2 hover:bg-teal-50 text-teal-600 rounded-lg transition-colors">
                          <Eye className="w-4 h-4" />
                        </button>
                        <button className="p-2 hover:bg-gray-100 text-gray-600 rounded-lg transition-colors">
                          <Printer className="w-4 h-4" />
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