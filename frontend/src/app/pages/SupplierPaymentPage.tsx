import { useState } from 'react';
import { useNavigate } from 'react-router';
import { ArrowLeft, DollarSign, Calendar, AlertCircle, Building2, Printer } from 'lucide-react';

type PaymentMethod = 'cash' | 'cheque' | 'bank-transfer';

interface PaymentFormData {
  supplier: string;
  amount: string;
  paymentMethod: PaymentMethod;
  date: string;
  notes: string;
  linkedInvoice: string;
  // Cheque fields
  chequeNumber: string;
  bankName: string;
  dueDate: string;
  // Bank transfer fields
  transactionReference: string;
  transferBankName: string;
}

const mockSuppliers = [
  { id: '1', name: 'MediCorp Pharmaceuticals', totalPayable: 9950.00, previousPayments: 35330.50 },
  { id: '2', name: 'Global Health Supplies', totalPayable: 4000.00, previousPayments: 28150.75 },
];

const mockInvoices = [
  { id: 'PI-2024-001', amount: 5280.50, supplier: 'MediCorp Pharmaceuticals', remaining: 0 },
  { id: 'PI-2024-002', amount: 3200.00, supplier: 'MediCorp Pharmaceuticals', remaining: 2000.00 },
  { id: 'PI-2024-003', amount: 4800.00, supplier: 'MediCorp Pharmaceuticals', remaining: 4800.00 },
];

export function SupplierPaymentPage() {
  const navigate = useNavigate();

  const [formData, setFormData] = useState<PaymentFormData>({
    supplier: '',
    amount: '',
    paymentMethod: 'cash',
    date: new Date().toISOString().split('T')[0],
    notes: '',
    linkedInvoice: '',
    chequeNumber: '',
    bankName: '',
    dueDate: '',
    transactionReference: '',
    transferBankName: '',
  });

  const selectedSupplier = mockSuppliers.find((s) => s.id === formData.supplier);
  const remainingAfterPayment = selectedSupplier
    ? selectedSupplier.totalPayable - (parseFloat(formData.amount) || 0)
    : 0;
  const isOverpayment = remainingAfterPayment < 0;

  const handleInputChange = (field: keyof PaymentFormData, value: string) => {
    setFormData({ ...formData, [field]: value });
  };

  const handleSavePayment = () => {
    console.log('Payment saved:', formData);
    alert('Payment saved successfully!');
    navigate('/suppliers/1');
  };

  const handleSaveAndPrint = () => {
    handleSavePayment();
    // Navigate to printable receipt
    console.log('Print payment voucher');
  };

  return (
    <div className="p-8">
      {/* Back Button */}
      <button
        onClick={() => navigate('/suppliers/1')}
        className="flex items-center gap-2 text-gray-600 hover:text-gray-900 mb-6 transition-colors"
      >
        <ArrowLeft className="w-5 h-5" />
        <span className="font-medium">Back to Supplier</span>
      </button>

      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Supplier Payment</h1>
        <p className="text-gray-500">Record payment to supplier</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Left: Payment Form */}
        <div className="lg:col-span-2">
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 space-y-5">
            {/* Supplier Selection */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Supplier <span className="text-red-500">*</span>
              </label>
              <select
                value={formData.supplier}
                onChange={(e) => handleInputChange('supplier', e.target.value)}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all"
              >
                <option value="">Select supplier...</option>
                {mockSuppliers.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>
                    {supplier.name} - Payable: ${supplier.totalPayable.toFixed(2)}
                  </option>
                ))}
              </select>
            </div>

            {/* Amount */}
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

            {/* Payment Method */}
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
              </select>
            </div>

            {/* Date */}
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

            {/* Linked Invoice */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Link to Purchase Invoice <span className="text-gray-400 text-xs">(optional)</span>
              </label>
              <select
                value={formData.linkedInvoice}
                onChange={(e) => handleInputChange('linkedInvoice', e.target.value)}
                disabled={!formData.supplier}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all disabled:opacity-50"
              >
                <option value="">Select invoice...</option>
                {mockInvoices
                  .filter((inv) => inv.supplier === selectedSupplier?.name && inv.remaining > 0)
                  .map((invoice) => (
                    <option key={invoice.id} value={invoice.id}>
                      {invoice.id} - Remaining: ${invoice.remaining.toFixed(2)}
                    </option>
                  ))}
              </select>
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
                disabled={!formData.supplier || !formData.amount}
                className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md font-semibold"
              >
                <DollarSign className="w-5 h-5" />
                <span>Save Payment</span>
              </button>
              <button
                onClick={handleSaveAndPrint}
                disabled={!formData.supplier || !formData.amount}
                className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all disabled:opacity-50 disabled:cursor-not-allowed font-semibold"
              >
                <Printer className="w-5 h-5" />
                <span>Save & Print</span>
              </button>
            </div>
          </div>
        </div>

        {/* Right: Live Summary Card */}
        <div>
          <div className="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-xl p-6 sticky top-8">
            <h3 className="font-bold text-gray-900 mb-4">Payment Summary</h3>

            {selectedSupplier ? (
              <div className="space-y-4">
                <div className="p-4 bg-white rounded-xl border border-gray-200">
                  <div className="flex items-center gap-3 mb-2">
                    <div className="w-10 h-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center">
                      <Building2 className="w-5 h-5 text-white" />
                    </div>
                    <div>
                      <p className="text-sm text-gray-500">Supplier</p>
                      <p className="font-semibold text-gray-900">{selectedSupplier.name}</p>
                    </div>
                  </div>
                </div>

                <div className="p-4 bg-white rounded-xl border border-gray-200">
                  <p className="text-sm text-gray-500 mb-1">Total Payable</p>
                  <p className="text-2xl font-bold text-red-600">${selectedSupplier.totalPayable.toFixed(2)}</p>
                </div>

                <div className="p-4 bg-white rounded-xl border border-gray-200">
                  <p className="text-sm text-gray-500 mb-1">Previous Payments</p>
                  <p className="text-xl font-bold text-gray-900">${selectedSupplier.previousPayments.toFixed(2)}</p>
                </div>

                {formData.amount && (
                  <>
                    <div className="p-4 bg-teal-50 border border-teal-200 rounded-xl">
                      <p className="text-sm text-teal-700 mb-1">Current Payment</p>
                      <p className="text-2xl font-bold text-teal-700">${parseFloat(formData.amount).toFixed(2)}</p>
                    </div>

                    <div className={`p-4 rounded-xl border ${isOverpayment ? 'bg-yellow-50 border-yellow-200' : 'bg-emerald-50 border-emerald-200'}`}>
                      <p className={`text-sm mb-1 font-semibold ${isOverpayment ? 'text-yellow-700' : 'text-emerald-700'}`}>
                        Remaining After Payment
                      </p>
                      <p className={`text-3xl font-bold ${isOverpayment ? 'text-yellow-700' : 'text-emerald-700'}`}>
                        ${Math.abs(remainingAfterPayment).toFixed(2)}
                      </p>
                    </div>

                    {isOverpayment && (
                      <div className="flex items-start gap-2 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <AlertCircle className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
                        <div>
                          <p className="text-sm font-semibold text-yellow-900">Overpayment Warning</p>
                          <p className="text-xs text-yellow-700">Payment exceeds supplier payable</p>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </div>
            ) : (
              <div className="text-center py-12">
                <Building2 className="w-16 h-16 text-gray-300 mx-auto mb-3" />
                <p className="text-gray-500">Select a supplier to see summary</p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
