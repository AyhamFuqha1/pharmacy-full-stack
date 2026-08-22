import { BarChart3, TrendingUp, PieChart, LineChart } from 'lucide-react';

export function AnalyticsPage() {
  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Analytics</h1>
        <p className="text-gray-600">Business intelligence and insights</p>
      </div>

      {/* Placeholder Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
        <div className="inline-flex items-center justify-center w-16 h-16 bg-teal-100 rounded-full mb-4">
          <BarChart3 className="w-8 h-8 text-teal-600" />
        </div>
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Advanced Analytics</h2>
        <p className="text-gray-600 mb-6 max-w-md mx-auto">
          This feature is coming soon. You'll have access to comprehensive analytics, custom reports, and business intelligence dashboards.
        </p>
        <div className="flex items-center justify-center gap-4 text-sm text-gray-500">
          <div className="flex items-center gap-2">
            <TrendingUp className="w-4 h-4" />
            <span>Sales Trends</span>
          </div>
          <div className="flex items-center gap-2">
            <PieChart className="w-4 h-4" />
            <span>Market Share</span>
          </div>
          <div className="flex items-center gap-2">
            <LineChart className="w-4 h-4" />
            <span>Growth Metrics</span>
          </div>
          <div className="flex items-center gap-2">
            <BarChart3 className="w-4 h-4" />
            <span>Custom Reports</span>
          </div>
        </div>
      </div>
    </div>
  );
}
