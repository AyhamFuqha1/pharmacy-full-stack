import { useEffect, useState } from "react";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { useAuth } from "../../auth/useAuth";
import { formatMoney } from "../../utils/batchFormatters";
import { useTranslation } from "react-i18next";
import {
  ErrorNotice,
  MetricGrid,
  PrintReportButton,
  RecordTable,
  human,
  normalizeError,
} from "./ErpPrimitives";

export function Party360Workspace({
  kind,
  id,
}: {
  kind: "customer" | "supplier";
  id: number;
}) {
  const { t } = useTranslation();
  const { hasPermission } = useAuth(),
    allowed = hasPermission("reports.view_financial");
  const [data, setData] = useState<JsonRecord | null>(null),
    [activity, setActivity] = useState<JsonRecord | null>(null),
    [tab, setTab] = useState("overview"),
    [draft, setDraft] = useState({ from: "", to: "", activity_type: "" }),
    [filters, setFilters] = useState({ from: "", to: "", activity_type: "" }),
    [page, setPage] = useState(1),
    [loading, setLoading] = useState(false),
    [error, setError] = useState<any>(null);
  useEffect(() => {
    if (!allowed) return;
    const c = new AbortController();
    (kind === "customer"
      ? erpApi.customer360(id, filters, c.signal)
      : erpApi.supplier360(id, filters, c.signal)
    )
      .then((r) => setData(r.data))
      .catch((v) => setError(normalizeError(v)));
    return () => c.abort();
  }, [kind, id, allowed, filters.from, filters.to]);
  useEffect(() => {
    if (tab !== "activity" || !allowed) return;
    const c = new AbortController();
    setLoading(true);
    (kind === "customer"
      ? erpApi.customerActivity(id, { ...filters, page }, c.signal)
      : erpApi.supplierActivity(id, { ...filters, page }, c.signal)
    )
      .then((r) => setActivity(r.data))
      .catch((v) => setError(normalizeError(v)))
      .finally(() => setLoading(false));
    return () => c.abort();
  }, [tab, page, filters, id, kind, allowed]);
  if (!allowed) return null;
  if (!data)
    return (
      <div className="my-6">
        <ErrorNotice error={error} />
        <div className="h-32 animate-pulse bg-gray-100 rounded-2xl" />
      </div>
    );
  const tabs =
    kind === "customer"
      ? [
          "overview",
          "accounting",
          "sales",
          "payments",
          "activity",
          ...(data.profitability ? ["profitability"] : []),
        ]
      : [
          "overview",
          "accounting",
          "purchases",
          "returns",
          "payments",
          "cheques",
          "products supplied",
          "activity",
        ];
  return (
    <section className="my-6 space-y-4">
      <div className="flex gap-2 overflow-x-auto">
        {tabs.map((tabName) => (
          <button
            className={`px-4 py-2 rounded-xl whitespace-nowrap ${tab === tabName ? "bg-teal-600 text-white" : "bg-white border"}`}
            onClick={() => setTab(tabName)}
            key={tabName}
          >
            {t(human(tabName))}
          </button>
        ))}
      </div>
      <div className="flex flex-wrap gap-2 bg-white border rounded-xl p-3">
        <label className="sm:w-40 text-sm">
          <span className="block mb-1">{t("From Date")}</span>
          <input
            className="input"
            type="date"
            value={draft.from}
            onChange={(e) => setDraft((x) => ({ ...x, from: e.target.value }))}
          />
        </label>
        <label className="sm:w-40 text-sm">
          <span className="block mb-1">{t("To Date")}</span>
          <input
            className="input"
            type="date"
            value={draft.to}
            onChange={(e) => setDraft((x) => ({ ...x, to: e.target.value }))}
          />
        </label>
        <PrintReportButton
          type={
            kind === "customer" ? "customer_statement" : "supplier_statement"
          }
          filters={{
            [kind === "customer" ? "customer_id" : "provider_id"]: id,
            from: draft.from || null,
            to: draft.to || null,
          }}
          label={t("Print Statement")}
        />
      </div>
      <ErrorNotice error={error} />
      {tab === "overview" && (
        <>
          {kind === "customer" ? (
            <CustomerCreditOverview data={data} />
          ) : (
            <MetricGrid data={data} keys={["current_ap"]} />
          )}
        </>
      )}
      {tab === "accounting" && (
        <RecordTable
          rows={data.outstanding_invoices || data.outstanding_purchases || []}
        />
      )}
      {tab === "sales" && (
        <MetricGrid data={data} keys={["sales_total", "sales_return_total"]} />
      )}
      {tab === "purchases" && (
        <MetricGrid data={data.purchases || {}} keys={["count", "total"]} />
      )}
      {tab === "returns" && (
        <MetricGrid
          data={data.purchase_returns || {}}
          keys={["count", "total"]}
        />
      )}
      {tab === "payments" && (
        <MetricGrid
          data={
            kind === "customer"
              ? {
                  receipts_total: data.receipts_total,
                  ...data.incoming_cheque_summary,
                }
              : data.payments || {}
          }
          keys={
            kind === "customer"
              ? ["receipts_total", "count", "amount", "pending_amount"]
              : ["count", "total"]
          }
        />
      )}
      {tab === "cheques" && (
        <MetricGrid
          data={data.outgoing_cheques || {}}
          keys={["count", "total", "pending"]}
        />
      )}
      {tab === "products supplied" && (
        <RecordTable rows={data.products_supplied || []} />
      )}
      {tab === "profitability" && (
        <MetricGrid
          data={data.profitability}
          keys={Object.keys(data.profitability)}
        />
      )}
      {tab === "activity" && (
        <div className="bg-white border rounded-2xl overflow-hidden">
          <form
            className="p-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              setPage(1);
              setFilters(draft);
            }}
          >
            <label className="text-sm">
              <span className="block mb-1">{t("From Date")}</span>
              <input
                className="input"
                type="date"
                value={draft.from}
                onChange={(e) =>
                  setDraft((x) => ({ ...x, from: e.target.value }))
                }
              />
            </label>
            <label className="text-sm">
              <span className="block mb-1">{t("To Date")}</span>
              <input
                className="input"
                type="date"
                value={draft.to}
                onChange={(e) =>
                  setDraft((x) => ({ ...x, to: e.target.value }))
                }
              />
            </label>
            <input
              className="input"
              placeholder={t("Activity type")}
              value={draft.activity_type}
              onChange={(e) =>
                setDraft((x) => ({ ...x, activity_type: e.target.value }))
              }
            />
            <div className="flex gap-2">
              <button className="report-action primary">{t("Apply")}</button>
              <button
                type="button"
                className="report-action"
                onClick={() => {
                  const x = { from: "", to: "", activity_type: "" };
                  setDraft(x);
                  setFilters(x);
                  setPage(1);
                }}
              >
                {t("Clear")}
              </button>
            </div>
          </form>
          {loading ? (
            <div className="p-10 text-center">{t("Loading activity…")}</div>
          ) : (
            <RecordTable rows={activity?.rows || []} />
          )}
          <div className="p-3 flex justify-between">
            <button
              className="report-action"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
            >
              {t("Previous")}
            </button>
            <span>
              {t("pageOf", {
                page: activity?.meta?.current_page || 1,
                lastPage: activity?.meta?.last_page || 1,
              })}
            </span>
            <button
              className="report-action"
              disabled={page >= (activity?.meta?.last_page || 1)}
              onClick={() => setPage((p) => p + 1)}
            >
              {t("Next")}
            </button>
          </div>
        </div>
      )}
    </section>
  );
}

function CustomerCreditOverview({ data }: { data: JsonRecord }) {
  const { t } = useTranslation();
  const state = data.credit_state || {},
    limit = state.limit,
    current = Number(data.current_balance || 0),
    available = state.available_credit;
  const limitText =
    limit === null || limit === undefined
      ? t("Not configured")
      : Number(limit) === 0
        ? `${formatMoney(0)} — ${t("No credit allowed")}`
        : formatMoney(Number(limit));
  return (
    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <CreditCard label={t("Current Balance")} value={formatMoney(current)} />
      <CreditCard label={t("Credit Limit")} value={limitText} />
      <CreditCard
        label={t("Available Credit")}
        value={
          limit === null || limit === undefined
            ? t("Not configured")
            : formatMoney(Number(available || 0))
        }
      />
    </div>
  );
}
function CreditCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="p-4 bg-white border rounded-xl">
      <p className="text-xs uppercase text-gray-500">{label}</p>
      <strong className="text-xl">{value}</strong>
    </div>
  );
}
