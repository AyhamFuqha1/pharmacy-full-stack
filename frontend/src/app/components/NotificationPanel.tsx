import { AlertTriangle, TrendingDown, Clock } from 'lucide-react';

interface Notification {
  id: string;
  type: 'expiry' | 'stock' | 'alert';
  title: string;
  message: string;
  time: string;
  icon: any;
  color: string;
}

const notifications: Notification[] = [
  {
    id: '1',
    type: 'expiry',
    title: 'Expiry Warning',
    message: 'Paracetamol batch BT-2024-001 expires in 8 days',
    time: '5 min ago',
    icon: Clock,
    color: 'text-red-500',
  },
  {
    id: '2',
    type: 'stock',
    title: 'Low Stock Alert',
    message: 'Aspirin 100mg stock below minimum threshold',
    time: '15 min ago',
    icon: TrendingDown,
    color: 'text-orange-500',
  },
  {
    id: '3',
    type: 'alert',
    title: 'High Risk Batch',
    message: 'Batch BT-2024-004 has low sell ratio (42%)',
    time: '1 hour ago',
    icon: AlertTriangle,
    color: 'text-yellow-500',
  },
  {
    id: '4',
    type: 'expiry',
    title: 'Expiry Warning',
    message: 'Amoxicillin batch expires in 15 days',
    time: '2 hours ago',
    icon: Clock,
    color: 'text-red-500',
  },
];

export function NotificationPanel() {
  return (
    <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h3 className="text-lg font-bold text-gray-900">Recent Notifications</h3>
          <p className="text-sm text-gray-500">Alerts and warnings</p>
        </div>
        <button className="text-sm font-medium text-teal-600 hover:text-teal-700">View All</button>
      </div>
      <div className="space-y-4">
        {notifications.map((notification) => {
          const Icon = notification.icon;
          return (
            <div
              key={notification.id}
              className="flex items-start gap-3 p-3 rounded-xl hover:bg-gray-50 transition-colors cursor-pointer"
            >
              <div className={`${notification.color} mt-1`}>
                <Icon className="w-5 h-5" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-gray-900 text-sm mb-1">{notification.title}</h4>
                <p className="text-sm text-gray-600 leading-relaxed">{notification.message}</p>
                <span className="text-xs text-gray-400 mt-1 block">{notification.time}</span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}