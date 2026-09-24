import { useEffect, useState } from "react";
import { AlertCircle, ArrowLeft, Ban, Lock } from "lucide-react";
import { useLocation, useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { customersApi } from "../../api/customers";
import { salesApi } from "../../api/sales";
import { PermissionGate } from "../../auth/PermissionGate";
import type { SalesInvoiceDto } from "../../types/sales";
import { formatMoney, formatNumericDate } from "../../utils/batchFormatters";
import { useTranslation } from "react-i18next";
import { useAuth } from "../../auth/useAuth";

export function SalesDetailsPage() {
  const { t, i18n } = useTranslation();
  const { hasPermission } = useAuth();
  const { id } = useParams();
  const invoiceId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const [invoice, setInvoice] = useState<SalesInvoiceDto | null>(null);
  const [customer, setCustomer] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [reload, setReload] = useState(0);
  const [confirming, setConfirming] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [success, setSuccess] = useState(
    (location.state as { success?: string } | null)?.success ?? "",
  );
  useEffect(() => {
    if (!Number.isInteger(invoiceId) || invoiceId <= 0) {
      setError(new ApiClientError(404, t("Sales invoice not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    salesApi
      .detail(invoiceId, controller.signal)
      .then(async (response) => {
        setInvoice(response.data);
        try {
          setCustomer(
            (
              await customersApi.detail(
                response.data.customer_id,
                controller.signal,
              )
            ).data.name,
          );
        } catch {
          setCustomer(t("customerReference", { id: response.data.customer_id }));
        }
      })
      .catch((value: unknown) => {
        if (value instanceof DOMException && value.name === "AbortError")
          return;
        setError(
          value instanceof ApiClientError
            ? value
            : new ApiClientError(0, t("Unable to load sales invoice.")),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [invoiceId, reload]);
  async function cancel() {
    setCancelling(true);
    setError(null);
    try {
      await salesApi.cancel(invoiceId);
      setConfirming(false);
      setSuccess(
        t("Sale cancelled. Laravel reversed inventory and accounting effects."),
      );
      setReload((key) => key + 1);
    } catch (value) {
      setConfirming(false);
      setError(
        value instanceof ApiClientError
          ? value
          : new ApiClientError(0, t("Unable to cancel sale.")),
      );
    } finally {
      setCancelling(false);
    }
  }
  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading sales invoice…")} />
      </div>
    );
  if (error && !invoice)
    return (
      <div className="p-8">
        <button onClick={() => navigate("/sales")} className="flex gap-2 mb-6">
          <ArrowLeft /> {t("Back to Sales")}
        </button>
        <ErrorState error={error} retry={() => setReload((key) => key + 1)} />
      </div>
    );
  if (!invoice) return null;
  const cancelled = Boolean(invoice.deleted_at);
  const summary = invoice.return_summary ?? {
    original_total: Number(invoice.total_price), return_total: 0, gross_original_return_total: 0, customer_credit_total: 0, return_deduction_total: 0,
    net_total: Number(invoice.total_price), paid_settled: Number(invoice.paid_amount),
    net_outstanding: Math.max(0, Number(invoice.total_price) - Number(invoice.paid_amount)), excess_settlement: 0,
  };
  return (
    <div className="p-8">
      <button
        onClick={() => navigate("/sales")}
        className="flex gap-2 text-gray-600 mb-6"
      >
        <ArrowLeft /> {t("Back to Sales")}
      </button>
      <div className="flex justify-between items-start mb-8">
        <div>
          <h1 className="text-3xl font-bold">
            <bdi dir="ltr">{invoice.document_number || t("Legacy sales invoice")}</bdi>
          </h1>
          <p className="text-gray-500 mt-1">
            {t("Persisted FEFO allocations and totals")}
          </p>
        </div>
        <div className="flex flex-col gap-2 items-end">
          <span className={`px-4 py-2 rounded-full font-semibold ${cancelled ? "bg-red-100 text-red-700" : "bg-emerald-100 text-emerald-700"}`}>
            {t("Invoice status")}: {t(cancelled ? "Cancelled" : "Posted")}
          </span>
          <span className="px-4 py-2 rounded-full font-semibold bg-amber-50 text-amber-700">
            {t("Return status")}: {t(invoice.return_status ?? "no_returns")}
          </span>
        </div>
      </div>
      {success && (
        <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      {error && <ErrorState error={error} />}
      {cancelled && (
        <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex gap-3">
          <Lock className="text-red-600" />
          <div>
            <h2 className="font-bold text-red-900">
              {t("Cancelled and read-only")}
            </h2>
            <p className="text-sm text-red-700">
              {t(
                "Laravel has reversed stock and accounting. The current backend removes allocation lines during cancellation.",
              )}
            </p>
          </div>
        </div>
      )}
      <div className="bg-white rounded-2xl border p-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Info
          label={t("Customer")}
          value={customer || t("customerReference", { id: invoice.customer_id })}
        />
        <Info
          label={t("Date")}
          value={new Intl.DateTimeFormat(i18n.resolvedLanguage === "ar" ? "ar" : "en", { year: "numeric", month: "short", day: "numeric", timeZone: "UTC" }).format(new Date(`${invoice.date.slice(0, 10)}T00:00:00Z`))}
          isolate
        />
        <Info label={t("Payment method")} value={t(invoice.payment_method)} />
        <Info label={t("Invoice discount")} value={formatMoney(invoice.discount)} isolate />
        <Info label={t("Original invoice total")} value={formatMoney(summary.original_total)} isolate />
        <Info label={t("Gross historical return value")} value={formatMoney(summary.gross_original_return_total)} isolate />
        <Info label={t("Customer credit granted")} value={formatMoney(summary.customer_credit_total)} isolate />
        <Info label={t("Return deductions")} value={formatMoney(summary.return_deduction_total)} isolate />
        <Info label={t("Net customer liability after returns")} value={formatMoney(summary.net_total)} isolate />
        <Info label={t("Paid / settled")} value={formatMoney(summary.paid_settled)} isolate />
        <Info label={t("Net outstanding")} value={formatMoney(summary.net_outstanding)} isolate />
        {summary.excess_settlement > 0 && <Info label={t("Excess settlement / possible customer credit")} value={formatMoney(summary.excess_settlement)} isolate />}
        {hasPermission("reports.view_profit") && <Info label={t("Original persisted profit")} value={formatMoney(invoice.profit)} isolate />}
      </div>
      <div className="bg-white rounded-2xl border overflow-x-auto">
        <table className="w-full min-w-[1100px]">
          <thead className="bg-gray-50 text-xs uppercase">
            <tr>
              <th className="p-4">{t("Product")}</th>
              <th className="p-4">{t("Allocated batch")}</th>
              <th className="p-4">{t("Original Quantity")}</th>
              <th className="p-4">{t("Returned Quantity")}</th>
              <th className="p-4">{t("Net Quantity")}</th>
              <th className="p-4">{t("Sale price")}</th>
              <th className="p-4">{t("Line discount")}</th>
              <th className="p-4">{t("Line total")}</th>
              <th className="p-4">{t("Invoice discount share")}</th>
              <th className="p-4">{t("Final total")}</th>
              {hasPermission("reports.view_profit") && <th className="p-4">{t("Cost")}</th>}
              {hasPermission("reports.view_profit") && <th className="p-4">{t("Profit")}</th>}
            </tr>
          </thead>
          <tbody className="divide-y">
            {invoice.items?.map((line) => (
              <tr key={line.id}>
                <td className="p-4">
                  {line.product?.name || t("Product unavailable")}
                </td>
                <td className="p-4 font-mono">
                  {t("Batch")} <bdi dir="ltr">#{line.batch_id}</bdi>
                </td>
                <td className="p-4"><bdi dir="ltr">{line.original_quantity ?? line.amount}</bdi></td>
                <td className="p-4"><bdi dir="ltr">{line.returned_quantity ?? t("Unavailable")}</bdi></td>
                <td className="p-4"><bdi dir="ltr">{line.net_quantity ?? t("Unavailable")}</bdi></td>
                <td className="p-4"><bdi dir="ltr">{formatMoney(line.price)}</bdi></td>
                <td className="p-4"><bdi dir="ltr">{formatMoney(line.discount)}</bdi></td>
                <td className="p-4">
                  <bdi dir="ltr">{formatMoney(line.line_total)}</bdi>
                </td>
                <td className="p-4">
                  <bdi dir="ltr">{formatMoney(line.invoice_discount_share)}</bdi>
                </td>
                <td className="p-4 font-bold">
                  <bdi dir="ltr">{formatMoney(line.final_total)}</bdi>
                </td>
                {hasPermission("reports.view_profit") && <td className="p-4">
                  <bdi dir="ltr">{formatMoney(Number(line.cost_price) * line.amount)}</bdi>
                </td>}
                {hasPermission("reports.view_profit") && <td className="p-4"><bdi dir="ltr">{formatMoney(line.profit)}</bdi></td>}
              </tr>
            ))}
          </tbody>
        </table>
        {(!invoice.items || invoice.items.length === 0) && (
          <div className="p-10 text-center text-gray-500">
            {t(
              cancelled
                ? "Allocation lines are no longer returned after cancellation."
                : "No persisted allocation lines returned.",
            )}
          </div>
        )}
      </div>
      <section className="mt-6 bg-white rounded-2xl border p-6">
        <h2 className="text-xl font-bold mb-4">{t("Linked returns")}</h2>
        {invoice.linked_returns?.length ? <div className="divide-y">{invoice.linked_returns.map((row) => (
          <button key={row.id} onClick={() => navigate(`/returns/sales/${row.id}`)} className="w-full py-4 flex flex-wrap justify-between gap-3 text-start hover:text-teal-700">
            <span className="font-semibold">
              <bdi dir="ltr">{row.document_number || t("Sales Return")}</bdi>
            </span>
            <span><bdi dir="ltr">{formatNumericDate(row.created_at)}</bdi></span>
            {row.item_count && row.item_count > 1 && <span>{t("Item Count")}: <bdi dir="ltr">{row.item_count}</bdi></span>}
            <span>{t("Original return value")}: <bdi dir="ltr">{formatMoney(row.gross_original_value)}</bdi></span>
            <span>{t("Customer credit")}: <bdi dir="ltr">{formatMoney(row.customer_credit_value)}</bdi></span>
            <span>{t("Return deduction")}: <bdi dir="ltr">{formatMoney(row.deduction_value)}</bdi></span>
            {row.return_to_inventory !== null && row.return_to_inventory !== undefined && <span>{t("Restocked")}: {t(row.return_to_inventory ? "Yes" : "No")}</span>}
          </button>
        ))}</div> : <p className="text-gray-500">{t("No linked returns")}</p>}
      </section>
      <PermissionGate permission="sales.create">
        <div className="mt-6 flex flex-wrap gap-3">
          {!cancelled && (
            <>
              <button
                onClick={() => navigate(`/sales/${invoice.id}/return`)}
                className="flex gap-2 px-5 py-3 bg-teal-50 text-teal-700 rounded-xl"
              >
                {t("Return Items")}
              </button>
              <button
                onClick={() => setConfirming(true)}
                className="flex gap-2 px-5 py-3 bg-red-50 text-red-600 rounded-xl"
              >
                <Ban className="w-5 h-5" /> {t("Cancel Sale")}
              </button>
            </>
          )}
          <button
            onClick={() => navigate("/returns/sales")}
            className="px-5 py-3 bg-gray-100 rounded-xl"
          >
            {t("Sales Return History")}
          </button>
        </div>
      </PermissionGate>
      {confirming && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl max-w-md p-6">
            <AlertCircle className="w-10 h-10 text-amber-500 mb-3" />
            <h2 className="text-xl font-bold">{t("Cancel sales invoice?")}</h2>
            <p className="text-gray-600 my-4">
              {t(
                "Laravel will restore allocated inventory and reverse the accounting event. The document becomes read-only.",
              )}
            </p>
            <div className="flex gap-3">
              <button
                disabled={cancelling}
                onClick={() => setConfirming(false)}
                className="flex-1 py-2.5 bg-gray-100 rounded-xl"
              >
                {t("Keep Sale")}
              </button>
              <button
                disabled={cancelling}
                onClick={() => void cancel()}
                className="flex-1 py-2.5 bg-red-600 text-white rounded-xl disabled:opacity-50"
              >
                {t(cancelling ? "Cancelling…" : "Cancel Sale")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
function Info({ label, value, isolate = false }: { label: string; value: string; isolate?: boolean }) {
  return (
    <div className="p-4 bg-gray-50 rounded-xl">
      <p className="text-xs text-gray-500">{label}</p>
      <p className="font-bold mt-1 capitalize">
        {isolate ? <bdi dir="ltr">{value}</bdi> : value}
      </p>
    </div>
  );
}
function State({ text }: { text: string }) {
  return (
    <div className="bg-white rounded-2xl border p-12 text-center text-gray-500">
      {text}
    </div>
  );
}
function ErrorState({
  error,
  retry,
}: {
  error: ApiClientError;
  retry?: () => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
      <h2 className="font-bold text-red-900">
        {error.status === 403
          ? t("Sales access denied")
          : error.status === 404
            ? t("Sales invoice not found")
            : error.status === 409
              ? t("Cancellation conflict")
              : t("Sales request failed")}
      </h2>
      <p className="text-sm text-red-700 mt-1">{error.message}</p>
      {retry && error.status !== 403 && error.status !== 404 && (
        <button
          onClick={retry}
          className="mt-3 px-4 py-2 bg-red-600 text-white rounded-xl"
        >
          {t("Retry")}
        </button>
      )}
    </div>
  );
}
