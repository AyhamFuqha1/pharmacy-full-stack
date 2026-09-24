import { useEffect, useState } from "react";
import { Link } from "react-router";
import { ApiClientError } from "../../api/client";
import { systemApi } from "../../api/system";
import type {
  AdvancedAuditDto,
  AuditFilters,
  AuditLogDto,
} from "../../types/system";
import { Field, PageHeader, RemoteEntitySelect } from "../components/UxPrimitives";
import { userEntityLoader } from "../entityLoaders";
import { useTranslation } from "react-i18next";
const routeMap: Record<string, (id: number) => string> = {
  customer: (i) => `/customers/${i}`,
  customers: (i) => `/customers/${i}`,
  supplier: (i) => `/suppliers/${i}`,
  suppliers: (i) => `/suppliers/${i}`,
  provider: (i) => `/suppliers/${i}`,
  providers: (i) => `/suppliers/${i}`,
  product: (i) => `/products/${i}`,
  products: (i) => `/products/${i}`,
  batch: (i) => `/batches/${i}`,
  batches: (i) => `/batches/${i}`,
  product_batches: (i) => `/batches/${i}`,
  sale: (i) => `/sales/${i}`,
  sales: (i) => `/sales/${i}`,
  sales_invoice: (i) => `/sales/${i}`,
  sales_invoices: (i) => `/sales/${i}`,
  purchase: (i) => `/purchases/${i}`,
  purchases: (i) => `/purchases/${i}`,
  purchase_invoice: (i) => `/purchases/${i}`,
  purchase_invoices: (i) => `/purchases/${i}`,
  sales_return: (i) => `/returns/sales/${i}`,
  sales_returns: (i) => `/returns/sales/${i}`,
  invoice_returns: (i) => `/returns/sales/${i}`,
  purchase_return: (i) => `/returns/purchases/${i}`,
  purchase_returns: (i) => `/returns/purchases/${i}`,
  purch_returns: (i) => `/returns/purchases/${i}`,
  prescription: (i) => `/prescriptions/${i}`,
  prescriptions: (i) => `/prescriptions/${i}`,
  cheque: (i) => `/cheques/${i}`,
  cheques: (i) => `/cheques/${i}`,
  checks: (i) => `/cheques/${i}`,
  expense: (i) => `/expenses/${i}`,
  expenses: (i) => `/expenses/${i}`,
  other_income: (i) => `/other-income/${i}`,
  other_incomes: (i) => `/other-income/${i}`,
  recall: (i) => `/inventory/recalls/${i}`,
  batch_recalls: (i) => `/inventory/recalls/${i}`,
  import: () => "/imports",
  imports: () => "/imports",
  import_jobs: () => "/imports",
};
export function AuditPage() {
  const { t, i18n } = useTranslation();
  const [data, setData] = useState<AdvancedAuditDto | null>(null),
    [page, setPage] = useState(1),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [draft, setDraft] = useState({
      from: "",
      to: "",
      user_id: "",
      table_name: "",
      action_type: "",
    }),
    [filters, setFilters] = useState<AuditFilters>({});
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    systemApi
      .audit({ ...filters, page, per_page: 25 }, c.signal)
      .then(setData)
      .catch((v) => {
        if (!(v instanceof DOMException && v.name === "AbortError"))
          setError(
            v instanceof ApiClientError
              ? v
              : new ApiClientError(500, t("Audit history could not be loaded.")),
          );
      })
      .finally(() => !c.signal.aborted && setLoading(false));
    return () => c.abort();
  }, [filters, page]);
  const logs = data?.audit_logs.data || [],
    entities =
      (data?.summary.by_table
        .map((x) => x.table_name)
        .filter(Boolean) as string[]) || [],
    actions =
      (data?.summary.by_action
        .map((x) => x.action_type)
        .filter(Boolean) as string[]) || [];
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Audit History"
        description="See who changed what, when it changed, and which business record was affected."
      />
      {error && (
        <div className="p-4 bg-red-50 text-red-700 rounded-xl">
          {error.message} {t("Check your filters or try again.")}
        </div>
      )}
      <form
        className="rounded-2xl border bg-white p-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setFilters({
            from: draft.from || undefined,
            to: draft.to || undefined,
            user_id: draft.user_id ? Number(draft.user_id) : undefined,
            table_name: draft.table_name || undefined,
            action_type: draft.action_type || undefined,
          });
        }}
      >
        <Field label="From Date">
          <input
            className="input"
            type="date"
            value={draft.from}
            onChange={(e) => setDraft({ ...draft, from: e.target.value })}
          />
        </Field>
        <Field label="To Date">
          <input
            className="input"
            type="date"
            value={draft.to}
            onChange={(e) => setDraft({ ...draft, to: e.target.value })}
          />
        </Field>
        <RemoteEntitySelect
          label="Team Member"
          value={draft.user_id}
          onChange={(v) => setDraft({ ...draft, user_id: v })}
          load={userEntityLoader}
          placeholder="Search team member name or email"
        />
        <Field label="Business Record Type">
          <select
            className="input"
            value={draft.table_name}
            onChange={(e) => setDraft({ ...draft, table_name: e.target.value })}
          >
            <option value="">{t("All record types")}</option>
            {entities.map((x) => (
              <option value={x} key={x}>
                {t(friendly(x))}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Action">
          <select
            className="input"
            value={draft.action_type}
            onChange={(e) =>
              setDraft({ ...draft, action_type: e.target.value })
            }
          >
            <option value="">{t("All actions")}</option>
            {actions.map((x) => (
              <option value={x} key={x}>
                {t(friendly(x))}
              </option>
            ))}
          </select>
        </Field>
        <div className="lg:col-span-5 flex gap-2">
          <button className="report-action primary">{t("Apply Filters")}</button>
          <button
            type="button"
            className="report-action"
            onClick={() => {
              const clean = {
                from: "",
                to: "",
                user_id: "",
                table_name: "",
                action_type: "",
              };
              setDraft(clean);
              setFilters({});
              setPage(1);
            }}
          >
            {t("Clear All")}
          </button>
        </div>
      </form>
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <div className="p-10 text-center animate-pulse">
            {t("Loading audit history…")}
          </div>
        ) : logs.length ? (
          <table className="w-full min-w-[900px]">
            <thead>
              <tr>
                <th>{t("Date / Time")}</th>
                <th>{t("Team Member")}</th>
                <th>{t("Action")}</th>
                <th>{t("Record Type")}</th>
                <th>{t("Business Record")}</th>
                <th>{t("Changes")}</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td>
                    <bdi dir="ltr">{new Date(log.timestamp || log.created_at).toLocaleString(i18n.resolvedLanguage === "ar" ? "ar" : "en")}</bdi>
                  </td>
                  <td>{log.actor?.name || t("System process")}</td>
                  <td>{t(friendly(log.action_type || log.action))}</td>
                  <td>{log.entity_label || friendly(log.table_name)}</td>
                  <td>
                    <Reference log={log} />
                  </td>
                  <td>
                    <Changes log={log} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="p-10 text-center">
            <strong>{t("No audit activity matches these filters.")}</strong>
            <p className="text-sm text-gray-500">
              {t("Clear filters to review all recorded changes.")}
            </p>
          </div>
        )}
      </div>
      {data && (
        <div className="flex justify-end gap-3">
          <button
            className="report-action"
            disabled={page <= 1}
            onClick={() => setPage(page - 1)}
          >
            {t("Previous Page")}
          </button>
          <span className="py-2">
            {t("pageOf", { page, lastPage: data.audit_logs.last_page })}
          </span>
          <button
            className="report-action"
            disabled={page >= data.audit_logs.last_page}
            onClick={() => setPage(page + 1)}
          >
            {t("Next Page")}
          </button>
        </div>
      )}
    </div>
  );
}
function Reference({ log }: { log: AuditLogDto }) {
  const { t } = useTranslation();
  const raw = String(
      log.entity_type || log.model_name || log.table_name || "record",
    )
      .split("\\")
      .pop()!,
    key = raw.replace(/([a-z])([A-Z])/g, "$1_$2").toLowerCase(),
    route = routeMap[key] || routeMap[log.table_name?.toLowerCase()],
    label = t("auditRecordReference", { type: t(friendly(key)), id: log.record_id });
  return route && log.record_id ? (
    <Link className="text-teal-700 hover:underline" to={route(log.record_id)}>
      {label}
    </Link>
  ) : (
    <span className="text-gray-600">{label}</span>
  );
}
function Changes({ log }: { log: AuditLogDto }) {
  const { t } = useTranslation();
  const keys = [
    ...new Set([
      ...Object.keys(log.old_values || {}),
      ...Object.keys(log.new_values || {}),
    ]),
  ];
  return keys.length ? (
    <details>
      <summary className="cursor-pointer text-teal-700">{t("Review Changes")}</summary>
      {keys.map((k) => (
        <div className="text-xs" key={k}>
          {t(friendly(k))}: <bdi>{safe(log.old_values?.[k])}</bdi> →{" "}
          <bdi>{safe(log.new_values?.[k])}</bdi>
        </div>
      ))}
    </details>
  ) : (
    <span>{t("No field values recorded")}</span>
  );
}
const safe = (v: unknown) =>
  v == null
    ? "Not set"
    : typeof v === "object"
      ? "Structured information"
      : String(v);
const friendly = (v: string) =>
  String(v || "record")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
