import { useEffect, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { ArrowLeft, ChevronLeft, ChevronRight, Search } from "lucide-react";
import { Link, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { returnHistoryApi } from "../../api/returns";
import type { ReturnHistoryDto, ReturnHistoryFilters } from "../../types/returns";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import { Field, RemoteEntitySelect } from "../components/UxPrimitives";
import { partyEntityLoader, productEntityLoader, userEntityLoader } from "../entityLoaders";

const emptyFilters: ReturnHistoryFilters = {};

export function ReturnHistoryPage({ kind }: { kind: "sales" | "purchase" }) {
  const { t, i18n } = useTranslation();
  const [rows, setRows] = useState<ReturnHistoryDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0);
  const [form, setForm] = useState<ReturnHistoryFilters>(emptyFilters),
    [filters, setFilters] = useState<ReturnHistoryFilters>(emptyFilters);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const request =
      kind === "sales"
        ? returnHistoryApi.salesList(page, 15, filters, controller.signal)
        : returnHistoryApi.purchaseList(page, 15, filters, controller.signal);
    request
      .then((r) => {
        setRows(r.data);
        setLastPage(r.meta.last_page ?? 1);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load return history.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [filters, kind, page, reload]);
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
  const locale = i18n.resolvedLanguage === "ar" ? "ar" : "en";
  const detailBasePath =
    kind === "sales" ? "/returns/sales" : "/returns/purchases";
  return (
    <div className="p-4 sm:p-8">
      <div className="flex flex-wrap items-end justify-between gap-3 mb-8">
        <div>
          <h1 className="text-3xl font-bold">
            {t(kind === "sales" ? "Sales Returns" : "Purchase Returns")}
          </h1>
          <p className="text-gray-500">
            {t("Persisted, immutable return history")}
          </p>
        </div>
        <div className="flex gap-2">
          <Link to="/returns/sales" className="px-4 py-2 border rounded-xl">
            {t("Sales Returns")}
          </Link>
          <Link to="/returns/purchases" className="px-4 py-2 border rounded-xl">
            {t("Purchase Returns")}
          </Link>
        </div>
      </div>
      <form onSubmit={apply} className="bg-white border rounded-2xl p-5 mb-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <Field label={t("Return Number")}><input className="input" type="search" placeholder={t(kind === "sales" ? "Search return number, e.g. SRET-2026-000020" : "Search return number, e.g. PRET-2026-000020")} value={form.return_number ?? ""} onChange={(event) => setForm({ ...form, return_number: event.target.value || undefined })} /></Field>
        <Field label={t(kind === "sales" ? "Original Sales Invoice" : "Original Purchase Invoice")}><input className="input" type="search" placeholder={t(kind === "sales" ? "Search invoice number, e.g. SAL-2026-000020" : "Search purchase number, e.g. PUR-2026-000020")} value={form.source_document_number ?? ""} onChange={(event) => setForm({ ...form, source_document_number: event.target.value || undefined })} /></Field>
        {kind === "sales" ? <RemoteEntitySelect label={t("Customer")} value={String(form.customer_id ?? "")} onChange={(value) => setForm({ ...form, customer_id: value ? Number(value) : undefined })} load={partyEntityLoader("customer")} placeholder={t("Search customer name or phone")} /> : <RemoteEntitySelect label={t("Supplier")} value={String(form.provider_id ?? "")} onChange={(value) => setForm({ ...form, provider_id: value ? Number(value) : undefined })} load={partyEntityLoader("supplier")} placeholder={t("Search supplier name or phone")} />}
        <RemoteEntitySelect label={t("Product / barcode")} value={String(form.product_id ?? "")} onChange={(value) => setForm({ ...form, product_id: value ? Number(value) : undefined })} load={productEntityLoader} placeholder={t("Search product name or barcode")} />
        <Field label={t("Batch")}><input className="input" value={form.batch_search ?? ""} onChange={(event) => setForm({ ...form, batch_search: event.target.value || undefined })} placeholder={t("Batch number")} /></Field>
        <Field label={t("From")}><input className="input" type="date" value={form.from ?? ""} onChange={(event) => setForm({ ...form, from: event.target.value || undefined })} /></Field>
        <Field label={t("To")}><input className="input" type="date" value={form.to ?? ""} onChange={(event) => setForm({ ...form, to: event.target.value || undefined })} /></Field>
        <RemoteEntitySelect label={t("Created By")} value={String(form.user_id ?? "")} onChange={(value) => setForm({ ...form, user_id: value ? Number(value) : undefined })} load={userEntityLoader} placeholder={t("Search team member name or email")} />
        <div className="flex gap-2"><button className="flex-1 h-11 bg-teal-600 text-white rounded-xl"><Search className="inline w-4 h-4 me-1" />{t("Apply Filters")}</button><button type="button" onClick={clear} className="px-4 h-11 border rounded-xl">{t("Clear")}</button></div>
      </form>
      {error && (
        <ErrorState error={error} retry={() => setReload((v) => v + 1)} />
      )}
      {loading ? (
        <State text={t("Loading returns…")} />
      ) : !error && rows.length === 0 ? (
        <State
          text={t(
            kind === "sales"
              ? "No sales returns found."
              : "No purchase returns found.",
          )}
        />
      ) : (
        !error && (
          <div className="bg-white border rounded-2xl overflow-x-auto">
            <table className="w-full min-w-[940px]">
              <thead className="bg-gray-50">
                <tr>
                  <th className="p-4">{t("Return Number")}</th>
                  <th>
                    {t(
                      kind === "sales"
                        ? "Original Sales Invoice"
                        : "Original Purchase Invoice",
                    )}
                  </th>
                  <th>{t("Date")}</th>
                  <th>{t("Created By")}</th>
                  {kind === "sales" ? (
                    <>
                      <th>{t("Customer")}</th>
                      <th>{t("Item Count")}</th>
                      <th>{t("Returned Quantity")}</th>
                      <th>{t("Original Return Value")}</th>
                      <th>{t("Customer Credit")}</th>
                      <th>{t("Return Deduction")}</th>
                    </>
                  ) : (
                    <>
                      <th>{t("Product")}</th><th>{t("Batch")}</th><th>{t("Returned Quantity")}</th>
                      <th>{t("Unit Cost")}</th><th>{t("Return Amount")}</th><th>{t("Reason")}</th>
                    </>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="p-4">
                      <Link
                        className="font-bold text-teal-700"
                        to={`${detailBasePath}/${row.id}`}
                      >
                        <bdi dir="ltr">{row.document_number ?? t(kind === "sales" ? "Legacy sales return" : "Legacy purchase return")}</bdi>
                      </Link>
                    </td>
                    <td>
                      <Link
                        className="text-teal-700"
                        to={
                          kind === "sales"
                            ? `/sales/${row.source_invoice_id}`
                            : `/purchases/${row.source_invoice_id}`
                        }
                      >
                        <bdi dir="ltr">
                          {row.source_document_number ?? t(kind === "sales" ? "Legacy sales invoice" : "Legacy purchase invoice")}
                        </bdi>
                      </Link>
                    </td>
                    <td>{formatLocalizedDate(row.created_at, locale)}</td>
                    <td>
                      {row.user_name ??
                        (row.user_id
                          ? t("userReference", { id: row.user_id })
                          : t("Unavailable"))}
                    </td>
                    {kind === "sales" ? (
                      <>
                        <td>{row.customer_name ?? t("Unavailable")}</td>
                        <td><bdi dir="ltr">{row.item_count ?? 1}</bdi></td>
                        <td><bdi dir="ltr">{row.quantity}</bdi></td>
                        <td><bdi dir="ltr">{formatMoney(row.gross_original_return_value ?? 0)}</bdi></td>
                        <td><bdi dir="ltr">{formatMoney(row.customer_credit_value ?? row.total_returned_value)}</bdi></td>
                        <td><bdi dir="ltr">{formatMoney(row.return_deduction ?? 0)}</bdi></td>
                      </>
                    ) : (
                      <>
                        <td>{row.product_name}<div className="text-xs text-gray-500"><bdi dir="ltr">{row.product_barcode}</bdi></div></td>
                        <td><bdi dir="ltr">{row.batch_number}</bdi></td>
                        <td><bdi dir="ltr">{row.quantity}</bdi></td>
                        <td><bdi dir="ltr">{formatMoney(row.return_price ?? row.cost_price ?? 0)}</bdi></td>
                        <td className="font-semibold"><bdi dir="ltr">{formatMoney(row.total_returned_value)}</bdi></td>
                        <td>{row.reason ?? "—"}</td>
                      </>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}

export function ReturnHistoryDetailsPage({
  kind,
}: {
  kind: "sales" | "purchase";
}) {
  const { t, i18n } = useTranslation();
  const id = Number(useParams().id);
  const [row, setRow] = useState<ReturnHistoryDto | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const request =
      kind === "sales"
        ? returnHistoryApi.salesDetail(id, controller.signal)
        : returnHistoryApi.purchaseDetail(id, controller.signal);
    request
      .then((r) => setRow(r.data))
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load return.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [id, kind, reload]);
  const locale = i18n.resolvedLanguage === "ar" ? "ar" : "en";
  const listPath = kind === "sales" ? "/returns/sales" : "/returns/purchases";
  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading return…")} />
      </div>
    );
  if (error || !row)
    return (
      <div className="p-8">
        <ErrorState
          error={error ?? new ApiClientError(404, t("Return not found."))}
          retry={() => setReload((v) => v + 1)}
        />
      </div>
    );
  const returnType = t(kind === "sales" ? "Sales Return" : "Purchase Return");
  if (kind === "sales" && row.lines?.length) {
    return <SalesReturnDocumentDetails row={row} listPath={listPath} locale={locale} />;
  }
  return (
    <div className="p-4 sm:p-8">
      <Link to={listPath} className="inline-flex gap-2 mb-6">
        <ArrowLeft /> {t("Back to return history")}
      </Link>
      <div className="flex flex-wrap justify-between gap-4 mb-8">
        <div>
          <h1 className="text-3xl font-bold">
            {returnType} <bdi dir="ltr">{row.document_number ?? t(kind === "sales" ? "Legacy sales return" : "Legacy purchase return")}</bdi>
          </h1>
          <p className="text-gray-500">{t("Immutable persisted return")}</p>
        </div>
        <Link
          className="px-4 py-2.5 bg-teal-600 text-white rounded-xl"
          to={
            kind === "sales"
              ? `/sales/${row.source_invoice_id}`
              : `/purchases/${row.source_invoice_id}`
          }
        >
          {t("Open")} <bdi dir="ltr">{row.source_document_number ?? t(kind === "sales" ? "Legacy sales invoice" : "Legacy purchase invoice")}</bdi>
        </Link>
      </div>
      <div className="bg-white border rounded-2xl p-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <Info
          label={t("Return Number")}
          value={<bdi dir="ltr">{row.document_number ?? t(kind === "sales" ? "Legacy sales return" : "Legacy purchase return")}</bdi>}
        />
        <Info
          label={t(kind === "sales" ? "Original Sales Invoice" : "Original Purchase Invoice")}
          value={<bdi dir="ltr">{row.source_document_number ?? t(kind === "sales" ? "Legacy sales invoice" : "Legacy purchase invoice")}</bdi>}
        />
        {kind === "purchase" && (
          <Info
            label={t("Supplier")}
            value={row.provider_name ?? t("Unavailable")}
          />
        )}
        <Info
          label={t("Product")}
          value={
            <>
              {row.product_name}
              {row.product_barcode && (
                <>
                  {" "}
                  (<bdi dir="ltr">{row.product_barcode}</bdi>)
                </>
              )}
            </>
          }
        />
        <Info
          label={t("Batch")}
          value={
            <bdi dir="ltr">
              {row.batch_number}
            </bdi>
          }
        />
        <Info
          label={t("Expiry Date")}
          value={
            row.expiry_date
              ? formatLocalizedDate(row.expiry_date, locale)
              : t("No expiry date")
          }
        />
        <Info
          label={t("Returned Quantity")}
          value={<bdi dir="ltr">{row.quantity}</bdi>}
        />
        <Info
          label={t("Original returnable unit value")}
          value={
            <bdi dir="ltr">
              {formatMoney(
                row.original_returnable_unit_amount ?? row.price ?? row.return_price ?? row.cost_price ?? 0,
              )}
            </bdi>
          }
        />
        <Info
          label={t(row.price !== undefined ? "Customer credit" : "Supplier credit")}
          value={<bdi dir="ltr">{formatMoney(row.customer_credit_value ?? row.supplier_credit_value ?? row.total_returned_value)}</bdi>}
        />
        {row.price !== undefined && <Info label={t("Return deduction")} value={<bdi dir="ltr">{formatMoney(row.return_deduction ?? 0)}</bdi>} />}
        {row.price !== undefined && <Info label={t("Restocked")} value={t(row.return_to_inventory === false ? "No" : "Yes")} />}
        {row.return_price !== undefined && <Info label={t("Historical inventory value")} value={<bdi dir="ltr">{formatMoney(row.original_inventory_value ?? 0)}</bdi>} />}
        {row.return_price !== undefined && <Info label={t("Inventory adjustment")} value={<bdi dir="ltr">{formatMoney(row.inventory_adjustment ?? 0)}</bdi>} />}
        <Info
          label={t("Recorded By")}
          value={
            row.user_name ??
            (row.user_id
              ? t("userReference", { id: row.user_id })
              : t("Unavailable"))
          }
        />
        <Info
          label={t("Recorded At")}
          value={formatLocalizedDate(row.created_at, locale)}
        />
        <div className="sm:col-span-2 lg:col-span-3">
          <Info
            label={t("Reason")}
            value={row.reason ?? t("No reason recorded")}
          />
        </div>
      </div>
    </div>
  );
}

function SalesReturnDocumentDetails({ row, listPath, locale }: { row: ReturnHistoryDto; listPath: string; locale: "ar" | "en" }) {
  const { t } = useTranslation();
  return <div className="p-4 sm:p-8">
    <Link to={listPath} className="inline-flex gap-2 mb-6"><ArrowLeft /> {t("Back to return history")}</Link>
    <div className="flex flex-wrap justify-between gap-4 mb-6"><div><h1 className="text-3xl font-bold"><bdi dir="ltr">{row.document_number}</bdi></h1><p className="text-gray-500">{t("Sales Return")}</p></div></div>
    <div className="bg-white border rounded-2xl p-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
      <Info label={t("Original Sales Invoice")} value={<bdi dir="ltr">{row.source_document_number}</bdi>} />
      <Info label={t("Customer")} value={row.customer?.name ?? t("Unavailable")} />
      <Info label={t("Branch")} value={row.branch?.name ?? t("Unavailable")} />
      <Info label={t("Return Date")} value={<bdi dir="ltr">{formatLocalizedDate(row.return_date ?? row.created_at, locale)}</bdi>} />
      <Info label={t("Created By")} value={row.user_name ?? t("Unavailable")} />
    </div>
    <div className="bg-white border rounded-2xl overflow-x-auto"><table className="w-full min-w-[1100px]"><thead><tr>{["Product","Batch","Unit / Packaging","Returned Quantity","Original Sale Price","Original Return Value","Customer Credit","Return Deduction","Returned to Inventory","Reason"].map(label=><th key={label}>{t(label)}</th>)}</tr></thead><tbody>{row.lines.map(line=><tr key={line.id}><td>{line.product_name}</td><td><bdi dir="ltr">{line.batch_number}</bdi></td><td>{(line as ReturnHistoryDto & {unit_name?:string}).unit_name ?? t("Base unit")}</td><td><bdi dir="ltr">{line.quantity}</bdi></td><td><bdi dir="ltr">{formatMoney(line.original_returnable_unit_amount ?? line.price ?? 0)}</bdi></td><td><bdi dir="ltr">{formatMoney(line.gross_original_return_value ?? 0)}</bdi></td><td><bdi dir="ltr">{formatMoney(line.customer_credit_value ?? 0)}</bdi></td><td><bdi dir="ltr">{formatMoney(line.return_deduction ?? 0)}</bdi></td><td>{t(line.return_to_inventory ? "Yes" : "No")}</td><td>{line.reason ?? "—"}</td></tr>)}</tbody></table></div>
    <div className="mt-5 ms-auto max-w-md bg-white border rounded-2xl p-5 space-y-2"><Info label={t("Original Return Value")} value={<bdi dir="ltr">{formatMoney(row.totals?.gross_original_return_total ?? 0)}</bdi>} /><Info label={t("Customer Credit")} value={<bdi dir="ltr">{formatMoney(row.totals?.customer_credit_total ?? 0)}</bdi>} /><Info label={t("Return Deduction")} value={<bdi dir="ltr">{formatMoney(row.totals?.return_deduction_total ?? 0)}</bdi>} /></div>
  </div>;
}
function formatLocalizedDate(value: string, locale: "ar" | "en"): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return value;
  const date = new Date(
    Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
  );
  return new Intl.DateTimeFormat(locale, {
    dateStyle: "medium",
    timeZone: "UTC",
  }).format(date);
}
function Info({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="bg-gray-50 rounded-xl p-4">
      <p className="text-xs uppercase text-gray-500">{label}</p>
      <p className="font-semibold mt-1">{value}</p>
    </div>
  );
}
function State({ text }: { text: string }) {
  return (
    <div className="p-12 bg-white border rounded-2xl text-center text-gray-500">
      {text}
    </div>
  );
}
function ErrorState({
  error,
  retry,
}: {
  error: ApiClientError;
  retry: () => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="p-6 bg-red-50 border border-red-200 rounded-2xl text-red-700">
      <strong>
        {t(
          error.status === 403
            ? "Return history access denied"
            : error.status === 404
              ? "Return not found"
              : "Unable to load return history",
        )}
      </strong>
      <p>{error.message}</p>
      {error.status !== 403 && (
        <button onClick={retry} className="mt-2 underline">
          {t("Retry")}
        </button>
      )}
    </div>
  );
}
function Pager({
  page,
  lastPage,
  setPage,
}: {
  page: number;
  lastPage: number;
  setPage: (value: number) => void;
}) {
  return (
    <div className="flex justify-end items-center gap-3 mt-5">
      <button
        disabled={page <= 1}
        onClick={() => setPage(page - 1)}
        className="p-2 border rounded-lg disabled:opacity-40"
      >
        <ChevronLeft />
      </button>
      <span>
        {page} / {lastPage}
      </span>
      <button
        disabled={page >= lastPage}
        onClick={() => setPage(page + 1)}
        className="p-2 border rounded-lg disabled:opacity-40"
      >
        <ChevronRight />
      </button>
    </div>
  );
}
