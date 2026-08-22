import { FileText, Download, Calendar, Filter } from 'lucide-react';

export function ReportsPage() {
  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Reports</h1>
        <p className="text-gray-600">Generate and export business reports</p>
      </div>

      {/* Placeholder Card */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
        <div className="inline-flex items-center justify-center w-16 h-16 bg-teal-100 rounded-full mb-4">
          <FileText className="w-8 h-8 text-teal-600" />
        </div>
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Reports Center</h2>
        <p className="text-gray-600 mb-6 max-w-md mx-auto">
          This feature is coming soon. You'll be able to generate, schedule, and export various business reports including sales, inventory, and financial reports.
        </p>
        <div className="flex items-center justify-center gap-4 text-sm text-gray-500">
          <div className="flex items-center gap-2">
            <FileText className="w-4 h-4" />
            <span>Custom Reports</span>
          </div>
          <div className="flex items-center gap-2">
            <Download className="w-4 h-4" />
            <span>Export Data</span>
          </div>
          <div className="flex items-center gap-2">
            <Calendar className="w-4 h-4" />
            <span>Scheduled Reports</span>
          </div>
          <div className="flex items-center gap-2">
            <Filter className="w-4 h-4" />
            <span>Advanced Filters</span>
          </div>
        </div>
      </div>
    </div>
  );
}
