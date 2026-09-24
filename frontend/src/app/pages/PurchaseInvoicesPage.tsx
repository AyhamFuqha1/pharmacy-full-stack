import { useEffect, useState } from "react";
import {
  AlertCircle,
  ChevronLeft,
  ChevronRight,
  FilePlus2,
  ReceiptText,
  Search,
} from "lucide-react";
import { useLocation, useNavigate } from "react-router";
import { ApiClientError } from "../../api/client";
import { providersApi } from "../../api/providers";
import { purchasesApi } from "../../api/purchases";
import { PermissionGate } from "../../auth/PermissionGate";
import type { ProviderDto } from "../../types/party";
import type { PurchaseInvoiceDto, PurchaseListParams } from "../../types/purchase";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import { useTranslation } from "react-i18next";
import { Field, RemoteEntitySelect } from "../components/UxPrimitives";
import { partyEntityLoader, productEntityLoader } from "../entityLoaders";

const emptyFilters: PurchaseListParams = {};

export function PurchaseInvoicesPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const location = useLocation();
  const [rows, setRows] = useState<PurchaseInvoiceDto[]>([]);
  const [providers, setProviders] = useState<Map<number, string>>(new Map());
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [reload, setReload] = useState(0);
  const [form, setForm] = useState<PurchaseListParams>(emptyFilters);
  const [filters, setFilters] = useState<PurchaseListParams>(emptyFilters);
  const success = (location.state as { success?: string } | null)?.success;

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    purchasesApi
      .list({ ...filters, page, per_page: 15 }, controller.signal)
      .then(async (response) => {
        setRows(response.data);
        setLastPage(response.meta.last_page ?? 1);
        const ids = [
          ...new Set(response.data.map((invoice) => invoice.provider_id)),
        ];
        const resolved = await Promise.all(
          ids.map(async (id) => {
            try {
              const value = await providersApi.detail(id, controller.signal);
              return [id, value.data.name] as const;
            } catch {
              return [id, t("supplierReference", { id, defaultValue: "Supplier #{{id}}" })] as const;
            }
          }),
        );
        setProviders(new Map(resolved));
      })
      .catch((value: unknown) => {
        if (value instanceof DOMException && value.name === "AbortError")
          return;
        setError(
          value instanceof ApiClientError
            ? value
            : new ApiClientError(0, t("Unable to load purchase invoices.")),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [filters, page, reload]);

  function apply(event: React.FormEvent) {
    event.preventDefault();
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
      <div className="flex flex-wrap justify-between items-start gap-4 mb-8">
        <div>
          <h1 className="text-3xl font-bold mb-2">{t("Purchase Invoices")}</h1>
          <p className="text-gray-500">
            {t("Draft and posted stock-receiving documents")}
          </p>
        </div>
        <PermissionGate permission="accounting.vouchers">
          <button
            onClick={() => navigate("/purchases/new")}
            className="flex gap-2 px-5 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl"
          >
            <FilePlus2 className="w-5 h-5" /> {t("New Purchase")}
          </button>
        </PermissionGate>
      </div>
      {success && (
        <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      <form onSubmit={apply} className="bg-white border rounded-2xl p-5 mb-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <Field label={t("Purchase Number")}><input className="input" type="search" placeholder={t("Search purchase number, e.g. PUR-2026-000020")} value={form.document_number ?? ""} onChange={(event) => setForm({ ...form, document_number: event.target.value || undefined })} /></Field>
        <RemoteEntitySelect label={t("Supplier")} value={String(form.provider_id ?? "")} onChange={(value) => setForm({ ...form, provider_id: value ? Number(value) : undefined })} load={partyEntityLoader("supplier")} placeholder={t("Search supplier name or phone")} />
        <RemoteEntitySelect label={t("Product / barcode")} value={String(form.product_id ?? "")} onChange={(value) => setForm({ ...form, product_id: value ? Number(value) : undefined })} load={productEntityLoader} placeholder={t("Search product name or barcode")} />
        <Field label={t("From")}><input className="input" type="date" value={form.from ?? ""} onChange={(event) => setForm({ ...form, from: event.target.value || undefined })} /></Field>
        <Field label={t("To")}><input className="input" type="date" value={form.to ?? ""} onChange={(event) => setForm({ ...form, to: event.target.value || undefined })} /></Field>
        <Field label={t("Status")}><select className="input" value={form.status ?? ""} onChange={(event) => setForm({ ...form, status: event.target.value || undefined })}><option value="">{t("All")}</option><option value="draft">{t("Draft")}</option><option value="posted">{t("Posted")}</option><option value="cancelled">{t("Cancelled")}</option></select></Field>
        <button className="h-11 w-full rounded-xl bg-teal-600 text-white"><Search className="me-1 inline h-4 w-4" />{t("Apply Filters")}</button>
        <button type="button" onClick={clear} className="h-11 w-full rounded-xl border">{t("Clear")}</button>
      </form>
      {error && (
        <div className="bg-red-50 border border-red-200 rounded-2xl p-10 text-center">
          <AlertCircle className="w-10 h-10 text-red-500 mx-auto mb-3" />
          <h2 className="font-bold">
            {error.status === 403
              ? t("Purchase access denied")
              : t("Unable to load purchases")}
          </h2>
          <p className="my-3 text-red-700">{error.message}</p>
          {error.status !== 403 && (
            <button
              onClick={() => setReload((key) => key + 1)}
              className="px-4 py-2 bg-red-600 text-white rounded-xl"
            >
              {t("Retry")}
            </button>
          )}
        </div>
      )}
      {!error && loading && <State text="Loading purchase invoices…" />}
      {!error && !loading && rows.length === 0 && (
        <State text="No purchase invoices found." />
      )}
      {!error && !loading && rows.length > 0 && (
        <>
          <div className="bg-white rounded-2xl border overflow-x-auto">
            <table className="w-full min-w-[960px] table-fixed">
              <colgroup>
                <col className="w-[11%]" />
                <col className="w-[23%]" />
                <col className="w-[13%]" />
                <col className="w-[15%]" />
                <col className="w-[10%]" />
                <col className="w-[10%]" />
                <col className="w-[8%]" />
                <col className="w-[10%]" />
              </colgroup>
              <thead className="bg-gray-50 text-center text-xs uppercase text-gray-600">
                <tr>
                  <th className="p-4 text-center">{t("Invoice")}</th>
                  <th className="p-4 text-center">{t("Supplier")}</th>
                  <th className="p-4 text-center">{t("Date")}</th>
                  <th className="p-4 text-center">{t("Due date")}</th>
                  <th className="p-4 text-center">{t("Status")}</th>
                  <th className="p-4 text-center">{t("Subtotal")}</th>
                  <th className="p-4 text-center">{t("Discount")}</th>
                  <th className="p-4 text-center">{t("Final total")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((invoice) => (
                  <tr
                    key={invoice.id}
                    onClick={() => navigate(`/purchases/${invoice.id}`)}
                    className="cursor-pointer align-middle text-center hover:bg-gray-50"
                  >
                    <td className="p-4 text-center">
                      <span className="flex items-center justify-center gap-1.5 font-mono font-semibold">
                        <ReceiptText className="h-4 w-4 shrink-0 text-gray-400" />
                        <bdi dir="ltr">{invoice.document_number || t("Legacy purchase invoice")}</bdi>
                      </span>
                    </td>
                    <td className="p-4 text-center font-semibold break-words">
                      {providers.get(invoice.provider_id) ??
                        t("supplierReference", { id: invoice.provider_id, defaultValue: "Supplier #{{id}}" })}
                    </td>
                    <td className="p-4 text-center"><bdi dir="ltr">{formatDateOnly(invoice.date)}</bdi></td>
                    <td className="p-4 text-center">
                      {invoice.due_date ? <bdi dir="ltr">{formatDateOnly(invoice.due_date)}</bdi>
                        : t("Not set")}
                    </td>
                    <td className="p-4 text-center">
                      <Status status={invoice.status} />
                    </td>
                    <td className="p-4 text-center whitespace-nowrap">
                      <bdi dir="ltr">{formatMoney(invoice.total_before_discount)}</bdi>
                    </td>
                    <td className="p-4 text-center whitespace-nowrap">
                      <bdi dir="ltr">{formatMoney(
                        invoice.invoice_discount ?? invoice.descount,
                      )}</bdi>
                    </td>
                    <td className="p-4 text-center font-bold whitespace-nowrap">
                      <bdi dir="ltr">{formatMoney(
                        invoice.total_after_discount ?? invoice.amount,
                      )}</bdi>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex justify-between mt-5">
            <p className="text-sm text-gray-500">
              {t("pageOf", { page, lastPage, defaultValue: "Page {{page}} of {{lastPage}}" })}
            </p>
            <div className="flex gap-2">
              <button
                disabled={page <= 1}
                onClick={() => setPage((value) => value - 1)}
                className="p-2 border rounded-lg disabled:opacity-40"
              >
                {i18n.dir() === "rtl" ? <ChevronRight /> : <ChevronLeft />}
              </button>
              <button
                disabled={page >= lastPage}
                onClick={() => setPage((value) => value + 1)}
                className="p-2 border rounded-lg disabled:opacity-40"
              >
                {i18n.dir() === "rtl" ? <ChevronLeft /> : <ChevronRight />}
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

export function Status({ status }: { status: string }) {
  const { t } = useTranslation();
  const style =
    status === "posted"
      ? "bg-emerald-100 text-emerald-700"
      : status === "cancelled"
        ? "bg-red-100 text-red-700"
        : "bg-gray-100 text-gray-700";
  return (
    <span
      className={`px-3 py-1 rounded-full text-xs font-semibold capitalize ${style}`}
    >
      {t(
        status === "posted"
          ? "Posted"
          : status === "cancelled"
            ? "Cancelled"
            : status === "draft"
              ? "Draft"
              : status,
        { defaultValue: status },
      )}
    </span>
  );
}
function State({ text }: { text: string }) {
  const { t } = useTranslation();
  return (
    <div className="bg-white rounded-2xl border p-12 text-center text-gray-500">
      {t(text, { defaultValue: text })}
    </div>
  );
}
