import { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";
import { batchesApi } from "../../api/batches";
import { ApiClientError } from "../../api/client";
import type { BatchDto } from "../../types/batch";
import {
  classifyExpiry,
  daysUntilExpiry,
  formatDateOnly,
} from "../../utils/batchFormatters";
import { StatusBadge } from "./BatchesPage";
import { useTranslation } from "react-i18next";

type Filter = "all" | "expired" | "expiring-soon";
const PER_PAGE = 15;

export function ExpiryPage() {
  const { t } = useTranslation();
  const [batches, setBatches] = useState<BatchDto[]>([]);
  const [filter, setFilter] = useState<Filter>("all");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    batchesApi
      .listAll({ sort: "expiry_date", direction: "asc" }, controller.signal)
      .then(setBatches)
      .catch((requestError: unknown) => {
        if (
          requestError instanceof DOMException &&
          requestError.name === "AbortError"
        )
          return;
        setError(
          requestError instanceof ApiClientError
            ? requestError
            : new ApiClientError(0, t("Unable to load expiry alerts.")),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [reloadKey, t]);

  const alerts = useMemo(
    () =>
      batches.filter((batch) => classifyExpiry(batch.expiry_date) !== "future"),
    [batches],
  );
  const filtered = useMemo(
    () =>
      alerts.filter(
        (batch) =>
          filter === "all" || classifyExpiry(batch.expiry_date) === filter,
      ),
    [alerts, filter],
  );
  const lastPage = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
  const rows = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  const expiredCount = alerts.filter(
    (batch) => classifyExpiry(batch.expiry_date) === "expired",
  ).length;

  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">
          {t("Expiry Alerts")}
        </h1>
        <p className="text-gray-600">
          {t("Persisted batches expired or due within the next 30 days")}
        </p>
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <Summary label={t("All alerts")} value={alerts.length} tone="amber" />
        <Summary label={t("Expired")} value={expiredCount} tone="red" />
        <Summary
          label={t("Expiring soon")}
          value={alerts.length - expiredCount}
          tone="teal"
        />
      </div>
      <div className="flex gap-2 mb-6">
        {(["all", "expired", "expiring-soon"] as Filter[]).map((value) => (
          <button
            key={value}
            onClick={() => {
              setFilter(value);
              setPage(1);
            }}
            className={`px-4 py-2 rounded-xl capitalize ${filter === value ? "bg-teal-600 text-white" : "bg-white border text-gray-600"}`}
          >
            {t(value.replace("-", " "))}
          </button>
        ))}
      </div>
      {error && (
        <div className="bg-red-50 border border-red-200 rounded-2xl p-10 text-center">
          <AlertCircle className="w-10 h-10 text-red-500 mx-auto mb-3" />
          <h2 className="font-bold text-red-900">
            {error.status === 403
              ? t("Expiry access denied")
              : t("Unable to load expiry alerts")}
          </h2>
          <p className="text-red-700 mt-1 mb-4">{error.message}</p>
          {error.status !== 403 && (
            <button
              onClick={() => setReloadKey((key) => key + 1)}
              className="px-4 py-2 bg-red-600 text-white rounded-xl"
            >
              {t("Retry")}
            </button>
          )}
        </div>
      )}
      {!error && loading && (
        <div className="bg-white rounded-2xl border p-12 text-center text-gray-500">
          {t("Loading expiry alerts…")}
        </div>
      )}
      {!error && !loading && rows.length === 0 && (
        <div className="bg-white rounded-2xl border p-12 text-center">
          <AlertTriangle className="w-12 h-12 text-emerald-400 mx-auto mb-3" />
          <h2 className="text-xl font-bold">
            {t("noExpiryResults", {
              type: t(
                filter === "all" ? "expiry alerts" : filter.replace("-", " "),
              ),
            })}
          </h2>
          <p className="text-gray-500 mt-1">
            {t("This view uses the fixed 30-day frontend display window.")}
          </p>
        </div>
      )}
      {!error && !loading && rows.length > 0 && (
        <>
          <div className="bg-white rounded-2xl border overflow-x-auto">
            <table className="w-full min-w-[760px]">
              <thead className="bg-gray-50 text-start text-xs uppercase text-gray-500">
                <tr>
                  <th className="p-4">{t("Product")}</th>
                  <th className="p-4">{t("Batch")}</th>
                  <th className="p-4">{t("Expiry date")}</th>
                  <th className="p-4">{t("Timing")}</th>
                  <th className="p-4 text-end">{t("Quantity")}</th>
                  <th className="p-4">{t("Status")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((batch) => {
                  const days = daysUntilExpiry(batch.expiry_date);
                  const status = classifyExpiry(batch.expiry_date);
                  return (
                    <tr key={batch.id}>
                      <td className="p-4 font-semibold">
                        <bdi>
                          {batch.product?.name ??
                            t("productReference", { id: batch.product_id })}
                        </bdi>
                      </td>
                      <td className="p-4 font-mono text-sm">
                        <bdi dir="ltr">{batch.batch_number}</bdi>
                      </td>
                      <td className="p-4">
                        {formatDateOnly(batch.expiry_date)}
                      </td>
                      <td className="p-4 text-sm text-gray-600">
                        {days === null
                          ? t("Unavailable")
                          : days < 0
                            ? t("daysOverdue", { count: Math.abs(days) })
                            : days === 0
                              ? t("Expires today")
                              : t("daysRemaining", { count: days })}
                      </td>
                      <td className="p-4 text-end font-semibold">
                        {batch.quantity}
                      </td>
                      <td className="p-4">
                        <StatusBadge status={status} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="flex justify-between items-center mt-5">
            <p className="text-sm text-gray-500">
              {t("pageOf", { page, lastPage })}
            </p>
            <div className="flex gap-2">
              <button
                disabled={page <= 1}
                onClick={() => setPage((value) => value - 1)}
                className="p-2 border rounded-lg disabled:opacity-40"
              >
                <ChevronLeft className="w-5 h-5 rtl:rotate-180" />
              </button>
              <button
                disabled={page >= lastPage}
                onClick={() => setPage((value) => value + 1)}
                className="p-2 border rounded-lg disabled:opacity-40"
              >
                <ChevronRight className="w-5 h-5 rtl:rotate-180" />
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function Summary({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: "amber" | "red" | "teal";
}) {
  const styles = {
    amber: "bg-amber-50 text-amber-700",
    red: "bg-red-50 text-red-700",
    teal: "bg-teal-50 text-teal-700",
  };
  return (
    <div className="bg-white rounded-2xl border p-5">
      <div
        className={`w-10 h-10 rounded-xl flex items-center justify-center mb-3 ${styles[tone]}`}
      >
        <AlertTriangle className="w-5 h-5" />
      </div>
      <p className="text-2xl font-bold">{value}</p>
      <p className="text-sm text-gray-500">{label}</p>
    </div>
  );
}
