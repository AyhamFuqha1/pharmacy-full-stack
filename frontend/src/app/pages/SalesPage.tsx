import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, Plus, Search } from "lucide-react";
import { useNavigate } from "react-router";
import { ApiClientError } from "../../api/client";
import { salesApi } from "../../api/sales";
import type {
  SalesInvoiceDto,
  SalesListParams,
  SalesPaymentMethod,
} from "../../types/sales";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import { useTranslation } from "react-i18next";

const emptyFilters: SalesListParams = { status: "all" };
export function SalesPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [rows, setRows] = useState<SalesInvoiceDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0);
  const [form, setForm] = useState<SalesListParams>(emptyFilters),
    [filters, setFilters] = useState<SalesListParams>(emptyFilters);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    salesApi
      .list({ ...filters, page, per_page: 15 }, controller.signal)
      .then((r) => {
        setRows(r.data);
        setLastPage(r.meta.last_page ?? 1);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load sales invoices.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [filters, page, reload]);
  function apply(e: React.FormEvent) {
    e.preventDefault();
    setPage(1);
    setFilters(form);
  }
  function clear() {
    setForm(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  }
  return (
    <div className="p-4 sm:p-8">
      <div className="flex flex-wrap justify-between gap-4 mb-8">
        <div>
          <h1 className="text-3xl font-bold">{t("Sales Management")}</h1>
          <p className="text-gray-500">
            {t("Server-filtered persisted sales invoices")}
          </p>
        </div>
        <button
          onClick={() => navigate("/create-order")}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> {t("Create Order")}
        </button>
      </div>
      <form
        onSubmit={apply}
        className="bg-white border rounded-2xl p-5 mb-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end"
      >
        <Field label={t("Invoice Number")}>
          <input
            className="input"
            type="search"
            placeholder={t("Search invoice number, e.g. SAL-2026-000020")}
            value={form.document_number ?? ""}
            onChange={(e) =>
              setForm({
                ...form,
                document_number: e.target.value || undefined,
              })
            }
          />
        </Field>
        <Field label="Customer">
          <input
            className="input"
            placeholder={t("Customer name")}
            value={form.search ?? ""}
            onChange={(e) => setForm({ ...form, search: e.target.value })}
          />
        </Field>
        <Field label="Product / barcode">
          <input
            className="input"
            placeholder={t("Contained product")}
            value={form.product_search ?? ""}
            onChange={(e) =>
              setForm({ ...form, product_search: e.target.value })
            }
          />
        </Field>
        <Field label="Payment method">
          <select
            className="input"
            value={form.payment_method ?? ""}
            onChange={(e) =>
              setForm({
                ...form,
                payment_method: (e.target.value || undefined) as
                  SalesPaymentMethod | undefined,
              })
            }
          >
            <option value="">{t("All methods")}</option>
            <option value="cash">{t("Cash")}</option>
            <option value="cheque">{t("Cheque")}</option>
            <option value="visa">{t("Visa")}</option>
          </select>
        </Field>
        <Field label="From">
          <input
            className="input"
            type="date"
            value={form.from ?? ""}
            onChange={(e) =>
              setForm({ ...form, from: e.target.value || undefined })
            }
          />
        </Field>
        <Field label="To">
          <input
            className="input"
            type="date"
            value={form.to ?? ""}
            onChange={(e) =>
              setForm({ ...form, to: e.target.value || undefined })
            }
          />
        </Field>
        <Field label="Status">
          <select
            className="input"
            value={form.status ?? "all"}
            onChange={(e) =>
              setForm({
                ...form,
                status: e.target.value as "active" | "cancelled" | "all",
              })
            }
          >
            <option value="active">{t("Active")}</option>
            <option value="cancelled">{t("Cancelled")}</option>
            <option value="all">{t("All")}</option>
          </select>
        </Field>
        <div className="flex gap-2">
          <button className="flex-1 h-11 bg-teal-600 text-white rounded-xl">
            <Search className="inline w-4 h-4 me-1" /> {t("Apply Filters")}
          </button>
          <button
            type="button"
            onClick={clear}
            className="px-4 h-11 border rounded-xl"
          >
            {t("Clear")}
          </button>
        </div>
      </form>
      {error && (
        <div className="p-5 mb-5 bg-red-50 border border-red-200 text-red-700 rounded-xl">
          <strong>
            {error.status === 403
              ? t("Sales access denied")
              : t("Unable to load sales")}
          </strong>
          <p>{error.message}</p>
          {error.status !== 403 && (
            <button
              className="underline mt-2"
              onClick={() => setReload((v) => v + 1)}
            >
              {t("Retry")}
            </button>
          )}
        </div>
      )}
      {loading ? (
        <State text="Loading sales…" />
      ) : !error && rows.length === 0 ? (
        <State text="No sales invoices match these filters." />
      ) : (
        !error && (
          <div className="bg-white rounded-2xl border overflow-x-auto">
            <table className="w-full min-w-[900px] table-fixed">
              <colgroup>
                <col className="w-[12%]" />
                <col className="w-[23%]" />
                <col className="w-[15%]" />
                <col className="w-[14%]" />
                <col className="w-[12%]" />
                <col className="w-[8%]" />
                <col className="w-[8%]" />
                <col className="w-[8%]" />
              </colgroup>
              <thead className="bg-gray-50 text-center">
                <tr>
                  <th className="p-4 text-center">{t("Invoice")}</th>
                  <th className="p-4 text-center">{t("Customer")}</th>
                  <th className="p-4 text-center">{t("Date")}</th>
                  <th className="p-4 text-center">{t("Status")}</th>
                  <th className="p-4 text-center">{t("Payment")}</th>
                  <th className="p-4 text-center">{t("Total")}</th>
                  <th className="p-4 text-center">{t("Paid")}</th>
                  <th className="p-4 text-center">{t("Outstanding")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((invoice) => {
                  const cancelled =
                    invoice.cancelled ||
                    Boolean(invoice.deleted_at) ||
                    invoice.status === "cancelled";
                  return (
                    <tr
                      key={invoice.id}
                      onClick={() => navigate(`/sales/${invoice.id}`)}
                      className="hover:bg-gray-50 cursor-pointer align-middle text-center"
                    >
                      <td className="p-4 text-center font-bold text-teal-700">
                        <bdi dir="ltr">{invoice.document_number || t("Legacy sales invoice")}</bdi>
                      </td>
                      <td className="p-4 text-center break-words">
                        {invoice.customer?.name ??
                          t("customerReference", { id: invoice.customer_id, defaultValue: "Customer #{{id}}" })}
                      </td>
                      <td className="p-4 text-center"><bdi dir="ltr">{formatDateOnly(invoice.date)}</bdi></td>
                      <td className="p-4 text-center">
                        <span
                          className={`px-2.5 py-1 rounded-full text-xs font-semibold ${cancelled ? "bg-red-50 text-red-700" : "bg-emerald-50 text-emerald-700"}`}
                        >
                          {cancelled ? t("Cancelled") : t("Posted / Active")}
                        </span>
                      </td>
                      <td className="p-4 text-center">{t(invoice.payment_method, { defaultValue: invoice.payment_method })}</td>
                      <td className="p-4 text-center font-bold">
                        <bdi dir="ltr">{formatMoney(invoice.total_price)}</bdi>
                      </td>
                      <td className="p-4 text-center">
                        <bdi dir="ltr">{formatMoney(invoice.paid_amount)}</bdi>
                      </td>
                      <td className="p-4 text-center">
                        <bdi dir="ltr">{formatMoney(
                          Math.max(
                            0,
                            Number(invoice.total_price) -
                              Number(invoice.paid_amount),
                          ),
                        )}</bdi>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )
      )}
      <div className="flex justify-end items-center gap-3 mt-5">
        <button
          disabled={page <= 1}
          onClick={() => setPage(page - 1)}
          className="p-2 border rounded-lg disabled:opacity-40"
        >
          {i18n.dir() === "rtl" ? <ChevronRight /> : <ChevronLeft />}
        </button>
        <span>
          {page} / {lastPage}
        </span>
        <button
          disabled={page >= lastPage}
          onClick={() => setPage(page + 1)}
          className="p-2 border rounded-lg disabled:opacity-40"
        >
          {i18n.dir() === "rtl" ? <ChevronLeft /> : <ChevronRight />}
        </button>
      </div>
    </div>
  );
}
function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  const { t } = useTranslation();
  return (
    <label>
      <span className="block text-sm mb-1">{t(label, { defaultValue: label })}</span>
      {children}
    </label>
  );
}
function State({ text }: { text: string }) {
  const { t } = useTranslation();
  return (
    <div className="p-12 bg-white border rounded-2xl text-center text-gray-500">
      {t(text, { defaultValue: text })}
    </div>
  );
}
