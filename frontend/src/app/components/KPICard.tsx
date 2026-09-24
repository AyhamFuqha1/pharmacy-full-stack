import { LucideIcon } from 'lucide-react';

interface KPICardProps {
  title: string;
  value: string;
  change?: string;
  isPositive?: boolean;
  icon: LucideIcon;
  iconColor: string;
}

export function KPICard({ title, value, change, isPositive, icon: Icon, iconColor }: KPICardProps) {
  return (
    <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
      <div className="flex items-start justify-between mb-4">
        <div className={`w-12 h-12 rounded-xl ${iconColor} bg-opacity-10 flex items-center justify-center`}>
          <Icon className={`w-6 h-6 ${iconColor.replace('bg-', 'text-')}`} />
        </div>
        {change && <span
          className={`text-xs font-semibold px-2 py-1 rounded-lg ${
            isPositive ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'
          }`}
        >
          <bdi dir="ltr">{change}</bdi>
        </span>}
      </div>
      <h3 className="text-gray-500 text-sm font-medium mb-1">{title}</h3>
      <p className="text-3xl font-bold text-gray-900"><bdi dir="ltr">{value}</bdi></p>
    </div>
  );
}
