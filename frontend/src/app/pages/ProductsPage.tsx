import { useState } from 'react';
import { useNavigate } from 'react-router';
import {
  Search,
  Plus,
  Grid3x3,
  List,
  Filter,
  ArrowUpDown,
  Eye,
  Edit,
  Trash2,
  AlertCircle,
  Package,
  TrendingUp,
} from 'lucide-react';

interface Product {
  id: string;
  name: string;
  barcode: string;
  category: string;
  image: string;
  stock: number;
  minStock: number;
  price: number;
  soldThisMonth: number;
  expiryWarning?: boolean;
  stockStatus: 'low' | 'medium' | 'high' | 'out';
}

const mockProducts: Product[] = [
  {
    id: '1',
    name: 'Paracetamol 500mg',
    barcode: '1234567890123',
    category: 'Pain Relief',
    image: 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=400',
    stock: 45,
    minStock: 100,
    price: 5.99,
    soldThisMonth: 450,
    expiryWarning: true,
    stockStatus: 'low',
  },
  {
    id: '2',
    name: 'Ibuprofen 400mg',
    barcode: '1234567890124',
    category: 'Anti-Inflammatory',
    image: 'https://images.unsplash.com/photo-1471864190281-a93a3070b6de?w=400',
    stock: 320,
    minStock: 150,
    price: 7.49,
    soldThisMonth: 280,
    stockStatus: 'high',
  },
  {
    id: '3',
    name: 'Amoxicillin 250mg',
    barcode: '1234567890125',
    category: 'Antibiotics',
    image: 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?w=400',
    stock: 180,
    minStock: 100,
    price: 12.99,
    soldThisMonth: 156,
    stockStatus: 'high',
  },
  {
    id: '4',
    name: 'Aspirin 100mg',
    barcode: '1234567890126',
    category: 'Cardiovascular',
    image: 'https://images.unsplash.com/photo-1550572017-4585dc20e50d?w=400',
    stock: 0,
    minStock: 80,
    price: 4.99,
    soldThisMonth: 210,
    expiryWarning: true,
    stockStatus: 'out',
  },
  {
    id: '5',
    name: 'Omeprazole 20mg',
    barcode: '1234567890127',
    category: 'Digestive',
    image: 'https://images.unsplash.com/photo-1631549916768-4119b2e5f926?w=400',
    stock: 92,
    minStock: 80,
    price: 9.99,
    soldThisMonth: 189,
    stockStatus: 'medium',
  },
  {
    id: '6',
    name: 'Metformin 500mg',
    barcode: '1234567890128',
    category: 'Diabetes',
    image: 'https://images.unsplash.com/photo-1607619056574-7b8d3ee536b2?w=400',
    stock: 520,
    minStock: 200,
    price: 6.49,
    soldThisMonth: 380,
    stockStatus: 'high',
  },
];

export function ProductsPage() {
  const navigate = useNavigate();
  const [viewMode, setViewMode] = useState<'grid' | 'table'>('grid');
  const [searchQuery, setSearchQuery] = useState('');
  const [filterStatus, setFilterStatus] = useState('all');
  const [sortBy, setSortBy] = useState('name');

  const getStockBadge = (status: string) => {
    const styles = {
      low: 'bg-orange-100 text-orange-700 border-orange-200',
      medium: 'bg-yellow-100 text-yellow-700 border-yellow-200',
      high: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      out: 'bg-red-100 text-red-700 border-red-200',
    };
    const labels = {
      low: 'Low Stock',
      medium: 'Medium',
      high: 'In Stock',
      out: 'Out of Stock',
    };
    return { style: styles[status as keyof typeof styles], label: labels[status as keyof typeof labels] };
  };

  const filteredProducts = mockProducts.filter((product) => {
    const matchesSearch =
      product.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      product.barcode.includes(searchQuery) ||
      product.category.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesFilter =
      filterStatus === 'all' ||
      (filterStatus === 'low' && product.stockStatus === 'low') ||
      (filterStatus === 'expiring' && product.expiryWarning) ||
      (filterStatus === 'out' && product.stockStatus === 'out');

    return matchesSearch && matchesFilter;
  });

  return (
    <div className="p-8">
      {/* Page Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Products Management</h1>
        <p className="text-gray-500">Manage your pharmacy inventory with smart insights</p>
      </div>

      {/* Smart Controls Bar */}
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
                placeholder="Search by name, barcode, or category..."
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
                <option value="all">All Products</option>
                <option value="low">Low Stock</option>
                <option value="expiring">Expiring Soon</option>
                <option value="out">Out of Stock</option>
              </select>
            </div>

            {/* Sort */}
            <div className="relative">
              <ArrowUpDown className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                className="pl-9 pr-8 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 appearance-none cursor-pointer"
              >
                <option value="name">Name</option>
                <option value="stock">Stock</option>
                <option value="expiry">Expiry</option>
                <option value="selling">Best Selling</option>
              </select>
            </div>
          </div>

          {/* Right Side Controls */}
          <div className="flex gap-3">
            {/* Add Product Button */}
            <button
              onClick={() => navigate('/products/add')}
              className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all shadow-sm hover:shadow-md"
            >
              <Plus className="w-4 h-4" />
              <span className="font-medium">Add Product</span>
            </button>

            {/* View Toggle */}
            <div className="flex bg-gray-100 rounded-xl p-1">
              <button
                onClick={() => setViewMode('grid')}
                className={`p-2 rounded-lg transition-all ${
                  viewMode === 'grid'
                    ? 'bg-white shadow-sm text-teal-600'
                    : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                <Grid3x3 className="w-5 h-5" />
              </button>
              <button
                onClick={() => setViewMode('table')}
                className={`p-2 rounded-lg transition-all ${
                  viewMode === 'table'
                    ? 'bg-white shadow-sm text-teal-600'
                    : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                <List className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Products Display */}
      {filteredProducts.length === 0 ? (
        <div className="bg-white rounded-2xl p-12 shadow-sm border border-gray-100 text-center">
          <Package className="w-16 h-16 text-gray-300 mx-auto mb-4" />
          <h3 className="text-xl font-bold text-gray-900 mb-2">No products found</h3>
          <p className="text-gray-500 mb-6">Try adjusting your search or filter criteria</p>
          <button
            onClick={() => navigate('/products/add')}
            className="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all"
          >
            <Plus className="w-5 h-5" />
            <span className="font-medium">Add Your First Product</span>
          </button>
        </div>
      ) : viewMode === 'grid' ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {filteredProducts.map((product) => {
            const stockBadge = getStockBadge(product.stockStatus);
            return (
              <div
                key={product.id}
                className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-lg transition-all group"
              >
                {/* Product Image */}
                <div className="relative h-48 bg-gray-100 overflow-hidden">
                  <img
                    src={product.image}
                    alt={product.name}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                  />
                  {product.expiryWarning && (
                    <div className="absolute top-3 right-3 px-2 py-1 bg-red-500 text-white text-xs font-semibold rounded-lg flex items-center gap-1 animate-pulse">
                      <AlertCircle className="w-3 h-3" />
                      <span>Expiring</span>
                    </div>
                  )}
                  <div className={`absolute top-3 left-3 px-3 py-1 rounded-lg text-xs font-semibold border ${stockBadge.style}`}>
                    {stockBadge.label}
                  </div>
                </div>

                {/* Product Info */}
                <div className="p-5">
                  <div className="mb-3">
                    <h3 className="text-lg font-bold text-gray-900 mb-1">{product.name}</h3>
                    <p className="text-xs text-gray-500 font-mono">{product.barcode}</p>
                  </div>

                  <div className="inline-flex px-2 py-1 bg-teal-50 text-teal-700 text-xs font-medium rounded-lg mb-4">
                    {product.category}
                  </div>

                  <div className="flex items-center justify-between mb-4">
                    <span className="text-2xl font-bold text-teal-600">${product.price}</span>
                  </div>

                  {/* Analytics Row */}
                  <div className="grid grid-cols-2 gap-3 mb-4 p-3 bg-gray-50 rounded-xl">
                    <div>
                      <p className="text-xs text-gray-500 mb-1">Sold This Month</p>
                      <p className="font-bold text-gray-900">{product.soldThisMonth}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500 mb-1">Current Stock</p>
                      <p className={`font-bold ${product.stock <= product.minStock ? 'text-red-600' : 'text-gray-900'}`}>
                        {product.stock}
                      </p>
                    </div>
                  </div>

                  {/* Action Buttons */}
                  <div className="flex gap-2">
                    <button
                      onClick={() => navigate(`/products/${product.id}`)}
                      className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-teal-50 text-teal-700 rounded-xl hover:bg-teal-100 transition-colors"
                    >
                      <Eye className="w-4 h-4" />
                      <span className="text-sm font-medium">View</span>
                    </button>
                    <button
                      onClick={() => navigate(`/products/${product.id}/edit`)}
                      className="flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors"
                    >
                      <Edit className="w-4 h-4" />
                    </button>
                    <button className="flex items-center justify-center px-3 py-2 bg-red-50 text-red-600 rounded-xl hover:bg-red-100 transition-colors">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Product</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Barcode</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Category</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Stock</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Price</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Sold/Month</th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                  <th className="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredProducts.map((product) => {
                  const stockBadge = getStockBadge(product.stockStatus);
                  return (
                    <tr key={product.id} className="hover:bg-gray-50 transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <img
                            src={product.image}
                            alt={product.name}
                            className="w-12 h-12 rounded-lg object-cover"
                          />
                          <div>
                            <p className="font-semibold text-gray-900">{product.name}</p>
                            {product.expiryWarning && (
                              <span className="inline-flex items-center gap-1 text-xs text-red-600 font-medium">
                                <AlertCircle className="w-3 h-3" />
                                Expiring
                              </span>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-mono text-sm text-gray-600">{product.barcode}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-flex px-2 py-1 bg-teal-50 text-teal-700 text-xs font-medium rounded-lg">
                          {product.category}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`font-bold ${product.stock <= product.minStock ? 'text-red-600' : 'text-gray-900'}`}>
                          {product.stock}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-bold text-teal-600">${product.price}</span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <TrendingUp className="w-4 h-4 text-emerald-500" />
                          <span className="font-semibold text-gray-900">{product.soldThisMonth}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${stockBadge.style}`}>
                          {stockBadge.label}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => navigate(`/products/${product.id}`)}
                            className="p-2 hover:bg-teal-50 text-teal-600 rounded-lg transition-colors"
                          >
                            <Eye className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => navigate(`/products/${product.id}/edit`)}
                            className="p-2 hover:bg-gray-100 text-gray-600 rounded-lg transition-colors"
                          >
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
      )}
    </div>
  );
}
