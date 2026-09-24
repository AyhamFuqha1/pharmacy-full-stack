import { AlertTriangle, Clock, TrendingDown, Users } from "lucide-react";
import { useTranslation, type TFunction } from "react-i18next";
import type { NotificationDto } from "../../types/reports";

function notificationText(item: NotificationDto, t: TFunction) {
  if (item.type === "low_stock") {
    const match = /^(.*) batch (.*) is out of stock\.$/.exec(item.text);
    if (match) return t("batchOutOfStock", { product: match[1], batch: match[2] });
  }
  if (item.type === "expiring_batch") {
    const match = /^(.*) batch (.*) expires on (.*)\.$/.exec(item.text);
    if (match) return t("batchExpiresOn", { product: match[1], batch: match[2], date: match[3] });
  }
  if (item.type === "customer_due") {
    const match = /^Customer (.*) has a balance of (.*)\.$/.exec(item.text);
    if (match) return t("customerHasBalance", { customer: match[1], balance: match[2] });
  }
  if (item.type === "supplier_due") {
    const match = /^Supplier (.*) has a balance of (.*)\.$/.exec(item.text);
    if (match) return t("supplierHasBalance", { supplier: match[1], balance: match[2] });
  }
  return item.text;
}

export function NotificationPanel({ notifications, loading }: { notifications: NotificationDto[]; loading?: boolean }) {
  const { t } = useTranslation();
  return <div className="bg-white rounded-2xl p-6 shadow-sm border border-gray-100"><div className="mb-6"><h3 className="text-lg font-bold">{t("Current Actionable Alerts")}</h3><p className="text-sm text-gray-500">{t("Current backend-calculated conditions, not notification history")}</p></div>{loading ? <p className="py-8 text-center text-gray-500">{t("Loading alerts…")}</p> : notifications.length === 0 ? <p className="py-8 text-center text-gray-500">{t("No current actionable alerts.")}</p> : <div className="space-y-3 max-h-[430px] overflow-auto">{notifications.map((item) => { const Icon = item.type === "expiring_batch" ? Clock : item.type === "low_stock" ? TrendingDown : item.type.includes("due") ? Users : AlertTriangle; return <div key={item.id} className="flex gap-3 p-3 rounded-xl bg-gray-50"><Icon className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" /><div><p className="text-sm text-gray-700">{notificationText(item, t)}</p><p className="text-xs text-gray-400 mt-1">{t("Current")} · {t(item.type.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()))}</p></div></div>; })}</div>}</div>;
}
