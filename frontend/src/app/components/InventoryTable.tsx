interface InventoryItem {
  id: string;
  productName: string;
  batchNumber: string;
  soldQuantity: number;
  daysRemaining: number;
  sellRatio: number;
  riskStatus: 'high' | 'medium' | 'low';
}

const inventoryData: InventoryItem[] = [
  {
    id: '1',
    productName: 'Paracetamol 500mg',
    batchNumber: 'BT-2024-001',
    soldQuantity: 450,
    daysRemaining: 8,
    sellRatio: 75,
    riskStatus: 'high',
  },
  {
    id: '2',
    productName: 'Amoxicillin 250mg',
    batchNumber: 'BT-2024-002',
    soldQuantity: 320,
    daysRemaining: 15,
    sellRatio: 64,
    riskStatus: 'medium',
  },
  {
    id: '3',
    productName: 'Ibuprofen 400mg',
    batchNumber: 'BT-2024-003',
    soldQuantity: 580,
    daysRemaining: 45,
    sellRatio: 92,
    riskStatus: 'low',
  },
  {
    id: '4',
    productName: 'Aspirin 100mg',
    batchNumber: 'BT-2024-004',
    soldQuantity: 210,
    daysRemaining: 12,
    sellRatio: 42,
    riskStatus: 'high',
  },
  {
    id: '5',
    productName: 'Omeprazole 20mg',
    batchNumber: 'BT-2024-005',
    soldQuantity: 380,
    daysRemaining: 22,
    sellRatio: 76,
    riskStatus: 'medium',
  },
  {
    id: '6',
    productName: 'Metformin 500mg',
    batchNumber: 'BT-2024-006',
    soldQuantity: 520,
    daysRemaining: 60,
    sellRatio: 87,
    riskStatus: 'low',
  },
];

export function InventoryTable() {
  const getRiskBadge = (status: string) => {
    const styles = {
      high: 'bg-red-100 text-red-700 border-red-200',
      medium: 'bg-orange-100 text-orange-700 border-orange-200',
      low: 'bg-green-100 text-green-700 border-green-200',
    };
    return styles[status as keyof typeof styles];
  };

  return (
    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div className="p-6 border-b border-gray-200">
        <h3 className="text-lg font-bold text-gray-900">Smart Inventory Analysis</h3>
        <p className="text-sm text-gray-500">Real-time batch performance and risk assessment</p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Product Name
              </th>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Batch Number
              </th>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Sold Qty
              </th>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Days Remaining
              </th>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Sell Ratio
              </th>
              <th className="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                Risk Status
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {inventoryData.map((item) => (
              <tr key={item.id} className="hover:bg-gray-50 transition-colors">
                <td className="px-6 py-4">
                  <span className="font-medium text-gray-900">{item.productName}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-gray-600 font-mono text-sm">{item.batchNumber}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-gray-900 font-semibold">{item.soldQuantity}</span>
                </td>
                <td className="px-6 py-4">
                  <span
                    className={`font-semibold ${
                      item.daysRemaining <= 10 ? 'text-red-600' : 'text-gray-900'
                    }`}
                  >
                    {item.daysRemaining} days
                  </span>
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-2">
                    <div className="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                      <div
                        className={`h-full rounded-full ${
                          item.sellRatio >= 80
                            ? 'bg-green-500'
                            : item.sellRatio >= 50
                            ? 'bg-orange-500'
                            : 'bg-red-500'
                        }`}
                        style={{ width: `${item.sellRatio}%` }}
                      />
                    </div>
                    <span className="text-sm font-semibold text-gray-700">{item.sellRatio}%</span>
                  </div>
                </td>
                <td className="px-6 py-4">
                  <span
                    className={`inline-flex px-3 py-1 rounded-lg text-xs font-semibold border ${getRiskBadge(
                      item.riskStatus
                    )}`}
                  >
                    {item.riskStatus.charAt(0).toUpperCase() + item.riskStatus.slice(1)} Risk
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
