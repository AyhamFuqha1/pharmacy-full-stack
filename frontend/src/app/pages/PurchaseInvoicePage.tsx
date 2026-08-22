import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import {
  ArrowLeft,
  Plus,
  Trash2,
  Search,
  Calendar,
  AlertCircle,
  CheckCircle,
  Lock,
  Save,
} from 'lucide-react';

interface InvoiceItem {
  id: string;
  product: string;
  batch: string;
  quantity: number;
  purchasePrice: number;
  expiryDate: string;
  total: number;
}

type InvoiceStatus = 'draft' | 'posted';

export function PurchaseInvoicePage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const isNewInvoice = id === 'new';

  const [status, setStatus] = useState<InvoiceStatus>('draft');
  const [showConfirmModal, setShowConfirmModal] = useState(false);

  const [formData, setFormData] = useState({
    supplier: '1',
    invoiceNumber: isNewInvoice ? `PI-2024-${Math.floor(Math.random() * 1000).toString().padStart(3, '0')}` : 'PI-2024-001',
    invoiceDate: new Date().toISOString().split('T')[0],
    dueDate: '',
    notes: '',
    discount: '0',
    tax: '0',
    paidAmount: '0',
  });

  const [items, setItems] = useState<InvoiceItem[]>([
    {
      id: '1',
      product: 'Paracetamol 500mg',
      batch: 'BT-2024-001',
      quantity: 100,
      purchasePrice: 3.99,
      expiryDate: '2026-12-15',
      total: 399.00,
    },
  ]);

  const subtotal = items.reduce((sum, item) => sum + item.total, 0);
  const discount = parseFloat(formData.discount) || 0;
  const tax = parseFloat(formData.tax) || 0;
  const grandTotal = subtotal - discount + tax;
  const paidAmount = parseFloat(formData.paidAmount) || 0;
  const remainingBalance = grandTotal - paidAmount;

  const handleAddItem = () => {
    const newItem: InvoiceItem = {
      id: Date.now().toString(),
      product: '',
      batch: '',
      quantity: 0,
      purchasePrice: 0,
      expiryDate: '',
      total: 0,
    };
    setItems([...items, newItem]);
  };

  const handleRemoveItem = (id: string) => {
    setItems(items.filter((item) => item.id !== id));
  };

  const handleItemChange = (id: string, field: keyof InvoiceItem, value: any) => {
    setItems(
      items.map((item) => {
        if (item.id === id) {
          const updated = { ...item, [field]: value };
          if (field === 'quantity' || field === 'purchasePrice') {
            updated.total = updated.quantity * updated.purchasePrice;
          }
          return updated;
        }
        return item;
      })
    );
  };

  const handleSaveDraft = () => {
    console.log('Saving draft...', { formData, items });
    alert('Invoice saved as draft!');
    navigate('/suppliers/1');
  };

  const handlePostInvoice = () => {
    setShowConfirmModal(true);
  };

  const confirmPost = () => {
    setStatus('posted');
    setShowConfirmModal(false);
    alert('Invoice posted successfully! Inventory and accounting records updated.');
  };

  const mockSuppliers = [
    { id: '1', name: 'MediCorp Pharmaceuticals' },
    { id: '2', name: 'Global Health Supplies' },
  ];

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
      <div className="flex items-start justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 mb-2">Purchase Invoice</h1>
          <p className="text-gray-500">Create and manage supplier purchase invoices</p>
        </div>
        <div>
          {status === 'draft' ? (
            <span className="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-xl border border-gray-300">
              <Save className="w-4 h-4" />
              <span className="font-semibold">Draft</span>
            </span>
          ) : (
            <span className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-100 text-emerald-700 rounded-xl border border-emerald-300">
              <CheckCircle className="w-4 h-4" />
              <span className="font-semibold">Posted</span>
            </span>
          )}
        </div>
      </div>

      {/* Posted Notice */}
      {status === 'posted' && (
        <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-6 flex items-center gap-3">
          <Lock className="w-6 h-6 text-emerald-600 flex-shrink-0" />
          <div>
            <h3 className="font-semibold text-emerald-900">Invoice Posted</h3>
            <p className="text-sm text-emerald-700">
              This invoice has been posted. Editing is disabled. Inventory and accounting records have been updated.
            </p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Header Section */}
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 className="text-lg font-bold text-gray-900 mb-4">Invoice Details</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Supplier <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.supplier}
                  onChange={(e) => setFormData({ ...formData, supplier: e.target.value })}
                  disabled={status === 'posted'}
                  className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all disabled:opacity-50"
                >
                  {mockSuppliers.map((supplier) => (
                    <option key={supplier.id} value={supplier.id}>
                      {supplier.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Invoice Number</label>
                <input
                  type="text"
                  value={formData.invoiceNumber}
                  onChange={(e) => setFormData({ ...formData, invoiceNumber: e.target.value })}
                  disabled={status === 'posted'}
                  className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all font-mono disabled:opacity-50"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Invoice Date</label>
                <div className="relative">
                  <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                  <input
                    type="date"
                    value={formData.invoiceDate}
                    onChange={(e) => setFormData({ ...formData, invoiceDate: e.target.value })}
                    disabled={status === 'posted'}
                    className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all disabled:opacity-50"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
                <div className="relative">
                  <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                  <input
                    type="date"
                    value={formData.dueDate}
                    onChange={(e) => setFormData({ ...formData, dueDate: e.target.value })}
                    disabled={status === 'posted'}
                    className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all disabled:opacity-50"
                  />
                </div>
              </div>
            </div>

            <div className="mt-4">
              <label className="block text-sm font-medium text-gray-700 mb-2">Notes</label>
              <textarea
                value={formData.notes}
                onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                disabled={status === 'posted'}
                rows={3}
                className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 transition-all resize-none disabled:opacity-50"
                placeholder="Add any notes..."
              />
            </div>
          </div>

          {/* Products Table */}
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-6 border-b border-gray-200 flex items-center justify-between">
              <h3 className="text-lg font-bold text-gray-900">Products</h3>
              {status === 'draft' && (
                <button
                  onClick={handleAddItem}
                  className="flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-xl hover:bg-teal-700 transition-colors text-sm"
                >
                  <Plus className="w-4 h-4" />
                  <span>Add Product</span>
                </button>
              )}
            </div>

            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Product</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Batch</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Qty</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Price</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Expiry</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Total</th>
                    {status === 'draft' && (
                      <th className="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Action</th>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {items.map((item) => (
                    <tr key={item.id}>
                      <td className="px-4 py-3">
                        <input
                          type="text"
                          value={item.product}
                          onChange={(e) => handleItemChange(item.id, 'product', e.target.value)}
                          disabled={status === 'posted'}
                          className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                          placeholder="Product name"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <input
                          type="text"
                          value={item.batch}
                          onChange={(e) => handleItemChange(item.id, 'batch', e.target.value)}
                          disabled={status === 'posted'}
                          className="w-32 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono text-sm disabled:opacity-50"
                          placeholder="Batch"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <input
                          type="number"
                          value={item.quantity}
                          onChange={(e) => handleItemChange(item.id, 'quantity', parseFloat(e.target.value) || 0)}
                          disabled={status === 'posted'}
                          className="w-24 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                          min="0"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <input
                          type="number"
                          value={item.purchasePrice}
                          onChange={(e) => handleItemChange(item.id, 'purchasePrice', parseFloat(e.target.value) || 0)}
                          disabled={status === 'posted'}
                          className="w-28 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                          step="0.01"
                          min="0"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <input
                          type="date"
                          value={item.expiryDate}
                          onChange={(e) => handleItemChange(item.id, 'expiryDate', e.target.value)}
                          disabled={status === 'posted'}
                          className="w-36 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 text-sm disabled:opacity-50"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <span className="font-bold text-gray-900">${item.total.toFixed(2)}</span>
                      </td>
                      {status === 'draft' && (
                        <td className="px-4 py-3 text-center">
                          <button
                            onClick={() => handleRemoveItem(item.id)}
                            className="p-2 hover:bg-red-50 text-red-600 rounded-lg transition-colors"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Summary Card - Right Sidebar */}
        <div>
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sticky top-8">
            <h3 className="text-lg font-bold text-gray-900 mb-4">Summary</h3>

            <div className="space-y-3 mb-4">
              <div className="flex items-center justify-between py-2 border-b border-gray-100">
                <span className="text-gray-600">Subtotal</span>
                <span className="font-semibold text-gray-900">${subtotal.toFixed(2)}</span>
              </div>

              <div>
                <label className="block text-sm text-gray-600 mb-1">Discount</label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                  <input
                    type="number"
                    value={formData.discount}
                    onChange={(e) => setFormData({ ...formData, discount: e.target.value })}
                    disabled={status === 'posted'}
                    className="w-full pl-7 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                    step="0.01"
                    min="0"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm text-gray-600 mb-1">Tax</label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                  <input
                    type="number"
                    value={formData.tax}
                    onChange={(e) => setFormData({ ...formData, tax: e.target.value })}
                    disabled={status === 'posted'}
                    className="w-full pl-7 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                    step="0.01"
                    min="0"
                  />
                </div>
              </div>

              <div className="flex items-center justify-between py-3 border-t-2 border-gray-300">
                <span className="font-semibold text-gray-900">Grand Total</span>
                <span className="text-2xl font-bold text-teal-600">${grandTotal.toFixed(2)}</span>
              </div>

              <div>
                <label className="block text-sm text-gray-600 mb-1">Paid Amount</label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                  <input
                    type="number"
                    value={formData.paidAmount}
                    onChange={(e) => setFormData({ ...formData, paidAmount: e.target.value })}
                    disabled={status === 'posted'}
                    className="w-full pl-7 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 disabled:opacity-50"
                    step="0.01"
                    min="0"
                  />
                </div>
              </div>

              <div className={`flex items-center justify-between py-3 px-4 rounded-xl ${remainingBalance > 0 ? 'bg-red-50 border border-red-200' : 'bg-emerald-50 border border-emerald-200'}`}>
                <span className={`font-semibold ${remainingBalance > 0 ? 'text-red-700' : 'text-emerald-700'}`}>
                  Remaining Balance
                </span>
                <span className={`text-xl font-bold ${remainingBalance > 0 ? 'text-red-700' : 'text-emerald-700'}`}>
                  ${remainingBalance.toFixed(2)}
                </span>
              </div>
            </div>

            {/* Action Buttons */}
            {status === 'draft' ? (
              <div className="space-y-3">
                <button
                  onClick={handlePostInvoice}
                  className="w-full flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm hover:shadow-md font-semibold"
                >
                  <CheckCircle className="w-5 h-5" />
                  <span>Post & Approve</span>
                </button>
                <button
                  onClick={handleSaveDraft}
                  className="w-full px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors font-semibold"
                >
                  Save as Draft
                </button>
                <button
                  onClick={() => navigate('/suppliers/1')}
                  className="w-full px-6 py-3 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors font-semibold"
                >
                  Cancel
                </button>
              </div>
            ) : (
              <div className="text-center py-4">
                <p className="text-sm text-gray-500">Invoice has been posted and locked</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Confirmation Modal */}
      {showConfirmModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
                <AlertCircle className="w-6 h-6 text-yellow-600" />
              </div>
              <div>
                <h3 className="text-xl font-bold text-gray-900">Confirm Post Invoice</h3>
              </div>
            </div>
            <p className="text-gray-600 mb-6">
              This action will update inventory and accounting records. Once posted, the invoice cannot be edited. Are you sure you want to continue?
            </p>
            <div className="flex gap-3">
              <button
                onClick={() => setShowConfirmModal(false)}
                className="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors font-semibold"
              >
                Cancel
              </button>
              <button
                onClick={confirmPost}
                className="flex-1 px-4 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all font-semibold"
              >
                Yes, Post Invoice
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
