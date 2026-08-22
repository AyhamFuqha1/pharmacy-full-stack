import { useState } from 'react';
import { Search, X, Plus, Scan, ShoppingCart, Trash2 } from 'lucide-react';

interface OrderItem {
  id: string;
  productName: string;
  batchNumber: string;
  amount: number;
  price: number;
  discount: number;
  total: number;
}

interface Customer {
  id: string;
  name: string;
  phone: string;
}

const mockCustomers: Customer[] = [
  { id: '1', name: 'John Smith', phone: '+1 234-567-8900' },
  { id: '2', name: 'Sarah Johnson', phone: '+1 234-567-8901' },
  { id: '3', name: 'Michael Brown', phone: '+1 234-567-8902' },
  { id: '4', name: 'Emily Davis', phone: '+1 234-567-8903' },
];

const mockProducts = [
  { name: 'Paracetamol 500mg', batch: 'BT-2024-001', price: 5.99, barcode: '1234567890123' },
  { name: 'Ibuprofen 400mg', batch: 'BT-2024-002', price: 7.49, barcode: '1234567890124' },
  { name: 'Amoxicillin 250mg', batch: 'BT-2024-003', price: 12.99, barcode: '1234567890125' },
  { name: 'Aspirin 100mg', batch: 'BT-2024-004', price: 4.99, barcode: '1234567890126' },
  { name: 'Omeprazole 20mg', batch: 'BT-2024-005', price: 9.99, barcode: '1234567890127' },
];

export function CreateOrderPage() {
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [showCustomerDropdown, setShowCustomerDropdown] = useState(false);
  const [customerSearch, setCustomerSearch] = useState('');
  const [orderDate, setOrderDate] = useState(new Date().toISOString().split('T')[0]);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [orderDiscount, setOrderDiscount] = useState(0);
  const [paidAmount, setPaidAmount] = useState('');
  const [barcodeInput, setBarcodeInput] = useState('');
  const [items, setItems] = useState<OrderItem[]>([]);

  const filteredCustomers = mockCustomers.filter(
    (customer) =>
      customer.name.toLowerCase().includes(customerSearch.toLowerCase()) ||
      customer.phone.includes(customerSearch)
  );

  const handleBarcodeInput = (barcode: string) => {
    const product = mockProducts.find((p) => p.barcode === barcode);
    if (product) {
      addItem(product.name, product.batch, product.price);
      setBarcodeInput('');
    }
  };

  const addItem = (productName = '', batchNumber = '', price = 0) => {
    const newItem: OrderItem = {
      id: Date.now().toString(),
      productName,
      batchNumber,
      amount: 1,
      price,
      discount: 0,
      total: price,
    };
    setItems([...items, newItem]);
  };

  const removeItem = (id: string) => {
    setItems(items.filter((item) => item.id !== id));
  };

  const updateItem = (id: string, field: keyof OrderItem, value: any) => {
    setItems(
      items.map((item) => {
        if (item.id === id) {
          const updated = { ...item, [field]: value };
          // Recalculate total
          const subtotal = updated.amount * updated.price;
          updated.total = subtotal - (subtotal * updated.discount) / 100;
          return updated;
        }
        return item;
      })
    );
  };

  const subtotal = items.reduce((sum, item) => sum + item.total, 0);
  const discountAmount = (subtotal * orderDiscount) / 100;
  const totalAmount = subtotal - discountAmount;

  const handleCreateOrder = () => {
    // Mock order creation
    console.log('Order created:', {
      customer: selectedCustomer,
      date: orderDate,
      paymentMethod,
      discount: orderDiscount,
      paidAmount: parseFloat(paidAmount),
      items,
      total: totalAmount,
      createdBy: 'Admin User', // This would come from JWT in production
    });
    alert('Order created successfully!');
  };

  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
          <span>Dashboard</span>
          <span>/</span>
          <span className="text-teal-600 font-medium">Create Order</span>
        </div>
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Create New Order</h1>
        <p className="text-gray-500">Enter order details and add products to create a new order</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Order Form */}
        <div className="lg:col-span-2 space-y-6">
          {/* Customer Selection */}
          <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 className="text-lg font-bold text-gray-900 mb-4">Customer Information</h3>
            <div className="relative">
              <label className="block text-sm font-medium text-gray-700 mb-2">Select Customer *</label>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                <input
                  type="text"
                  value={selectedCustomer ? selectedCustomer.name : customerSearch}
                  onChange={(e) => {
                    setCustomerSearch(e.target.value);
                    setShowCustomerDropdown(true);
                    setSelectedCustomer(null);
                  }}
                  onFocus={() => setShowCustomerDropdown(true)}
                  placeholder="Search customer by name or phone..."
                  className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
                />
              </div>

              {/* Customer Dropdown */}
              {showCustomerDropdown && !selectedCustomer && (
                <div className="absolute z-10 w-full mt-2 bg-white border border-gray-200 rounded-xl shadow-lg max-h-60 overflow-y-auto">
                  {filteredCustomers.length > 0 ? (
                    filteredCustomers.map((customer) => (
                      <button
                        key={customer.id}
                        onClick={() => {
                          setSelectedCustomer(customer);
                          setShowCustomerDropdown(false);
                          setCustomerSearch('');
                        }}
                        className="w-full px-4 py-3 text-left hover:bg-gray-50 transition-colors border-b border-gray-100 last:border-0"
                      >
                        <p className="font-medium text-gray-900">{customer.name}</p>
                        <p className="text-sm text-gray-500">{customer.phone}</p>
                      </button>
                    ))
                  ) : (
                    <div className="px-4 py-3 text-center text-gray-500">No customers found</div>
                  )}
                </div>
              )}

              {selectedCustomer && (
                <div className="mt-3 p-3 bg-teal-50 border border-teal-200 rounded-xl flex items-center justify-between">
                  <div>
                    <p className="font-medium text-gray-900">{selectedCustomer.name}</p>
                    <p className="text-sm text-gray-600">{selectedCustomer.phone}</p>
                  </div>
                  <button
                    onClick={() => setSelectedCustomer(null)}
                    className="p-1 hover:bg-teal-100 rounded-lg transition-colors"
                  >
                    <X className="w-5 h-5 text-teal-600" />
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* Barcode Scanner */}
          <div className="bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-200 rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-3">
              <div className="w-10 h-10 bg-gradient-to-br from-teal-500 to-emerald-500 rounded-xl flex items-center justify-center">
                <Scan className="w-5 h-5 text-white" />
              </div>
              <div>
                <h3 className="font-bold text-gray-900">Quick Add Product</h3>
                <p className="text-sm text-gray-600">Scan or type barcode to add product</p>
              </div>
            </div>
            <input
              type="text"
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              onKeyPress={(e) => {
                if (e.key === 'Enter' && barcodeInput) {
                  handleBarcodeInput(barcodeInput);
                }
              }}
              placeholder="Scan or enter barcode..."
              className="w-full px-4 py-3 bg-white border border-teal-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
            />
          </div>

          {/* Items Table */}
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-6 border-b border-gray-200 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-bold text-gray-900">Order Items</h3>
                <p className="text-sm text-gray-500">{items.length} item(s) added</p>
              </div>
              <button
                onClick={() => addItem()}
                className="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm"
              >
                <Plus className="w-4 h-4" />
                <span className="font-medium">Add Item</span>
              </button>
            </div>

            {items.length === 0 ? (
              <div className="p-12 text-center">
                <ShoppingCart className="w-12 h-12 text-gray-300 mx-auto mb-3" />
                <p className="text-gray-500">No items added yet</p>
                <p className="text-sm text-gray-400 mt-1">Use barcode scanner or click "Add Item" to start</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 border-b border-gray-200">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Product</th>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Batch</th>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Qty</th>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Price</th>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Disc %</th>
                      <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Total</th>
                      <th className="px-4 py-3"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {items.map((item) => (
                      <tr key={item.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3">
                          <input
                            type="text"
                            value={item.productName}
                            onChange={(e) => updateItem(item.id, 'productName', e.target.value)}
                            placeholder="Product name"
                            className="w-full px-2 py-1 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="text"
                            value={item.batchNumber}
                            onChange={(e) => updateItem(item.id, 'batchNumber', e.target.value)}
                            placeholder="Batch"
                            className="w-full px-2 py-1 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono text-sm"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="number"
                            value={item.amount}
                            onChange={(e) => updateItem(item.id, 'amount', parseFloat(e.target.value) || 0)}
                            min="1"
                            className="w-20 px-2 py-1 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="number"
                            value={item.price}
                            onChange={(e) => updateItem(item.id, 'price', parseFloat(e.target.value) || 0)}
                            min="0"
                            step="0.01"
                            className="w-24 px-2 py-1 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="number"
                            value={item.discount}
                            onChange={(e) => updateItem(item.id, 'discount', parseFloat(e.target.value) || 0)}
                            min="0"
                            max="100"
                            className="w-20 px-2 py-1 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <span className="font-semibold text-gray-900">${item.total.toFixed(2)}</span>
                        </td>
                        <td className="px-4 py-3">
                          <button
                            onClick={() => removeItem(item.id)}
                            className="p-2 hover:bg-red-50 rounded-lg transition-colors"
                          >
                            <Trash2 className="w-4 h-4 text-red-500" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        {/* Order Details & Summary */}
        <div className="space-y-6">
          {/* Order Details */}
          <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 className="text-lg font-bold text-gray-900 mb-4">Order Details</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Order Date</label>
                <input
                  type="date"
                  value={orderDate}
                  onChange={(e) => setOrderDate(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
                <select
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white"
                >
                  <option value="cash">Cash</option>
                  <option value="card">Credit/Debit Card</option>
                  <option value="mobile">Mobile Payment</option>
                  <option value="insurance">Insurance</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Order Discount (%)</label>
                <input
                  type="number"
                  value={orderDiscount}
                  onChange={(e) => setOrderDiscount(parseFloat(e.target.value) || 0)}
                  min="0"
                  max="100"
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Created By</label>
                <input
                  type="text"
                  value="Admin User"
                  disabled
                  className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-500 cursor-not-allowed"
                />
              </div>
            </div>
          </div>

          {/* Order Summary */}
          <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 className="text-lg font-bold text-gray-900 mb-4">Order Summary</h3>
            <div className="space-y-3">
              <div className="flex justify-between text-gray-600">
                <span>Subtotal</span>
                <span className="font-semibold">${subtotal.toFixed(2)}</span>
              </div>
              <div className="flex justify-between text-gray-600">
                <span>Discount ({orderDiscount}%)</span>
                <span className="font-semibold text-red-500">-${discountAmount.toFixed(2)}</span>
              </div>
              <div className="border-t border-gray-200 pt-3">
                <div className="flex justify-between">
                  <span className="text-lg font-bold text-gray-900">Total</span>
                  <span className="text-2xl font-bold text-teal-600">${totalAmount.toFixed(2)}</span>
                </div>
              </div>

              <div className="pt-3">
                <label className="block text-sm font-medium text-gray-700 mb-2">Paid Amount</label>
                <input
                  type="number"
                  value={paidAmount}
                  onChange={(e) => setPaidAmount(e.target.value)}
                  placeholder="0.00"
                  step="0.01"
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              {paidAmount && parseFloat(paidAmount) >= totalAmount && (
                <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl">
                  <p className="text-sm font-medium text-emerald-700">
                    Change: ${(parseFloat(paidAmount) - totalAmount).toFixed(2)}
                  </p>
                </div>
              )}
            </div>

            <button
              onClick={handleCreateOrder}
              disabled={!selectedCustomer || items.length === 0}
              className="w-full mt-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md font-semibold"
            >
              Create Order
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
