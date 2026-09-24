import { Fragment, useEffect, useState, type ReactNode } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import { PermissionGate } from "../../auth/PermissionGate";
import { useAuth } from "../../auth/useAuth";
import { CompleteUnitsManager } from "../components/CompleteUnitsManager";
import {
  EntitySelect,
  Field,
  PageHeader,
  movementOptions,
} from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { formatMoney, formatNumericDate } from "../../utils/batchFormatters";
import {
  ErrorNotice,
  human,
  MetricGrid,
  PrintReportButton,
  RecordTable,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";

const tabs = [
  "Overview",
  "Stock Statement",
  "Batches",
  "Purchases",
  "Sales",
  "Prescription Usage",
  "Adjustments",
  "Stock Counts",
  "Transfers",
  "Recall / Quarantine",
  "Profitability",
  "Units / Packaging",
];
const cleanFilters = {
  from: "",
  to: "",
  branch_id: "",
  batch_id: "",
  movement_type: "",
  page: 1,
  per_page: 25,
};
const currentMonthFilters = { ...cleanFilters, from: `${new Date().toISOString().slice(0, 7)}-01`, to: new Date().toISOString().slice(0, 10) };
type PeriodPreset = "today" | "week" | "month" | "last_month" | "year" | "custom";

function localIso(date: Date) {
  const offset = date.getTimezoneOffset();
  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10);
}

function periodRange(preset: PeriodPreset) {
  const now = new Date(), today = localIso(now);
  if (preset === "today") return { from: today, to: today };
  if (preset === "week") {
    const start = new Date(now); start.setDate(now.getDate() - ((now.getDay() + 6) % 7));
    return { from: localIso(start), to: today };
  }
  if (preset === "month") return { from: localIso(new Date(now.getFullYear(), now.getMonth(), 1)), to: today };
  if (preset === "last_month") return { from: localIso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: localIso(new Date(now.getFullYear(), now.getMonth(), 0)) };
  if (preset === "year") return { from: localIso(new Date(now.getFullYear(), 0, 1)), to: today };
  return null;
}

export function Product360WorkspacePage() {
  const { t } = useTranslation();
  const { hasPermission } = useAuth();
  const location = useLocation();
  const id = Number(useParams().id),
    { branches, batches } = useEntityOptions();
  const [data, setData] = useState<JsonRecord | null>(null),
    [statement, setStatement] = useState<JsonRecord | null>(null),
    [profitability, setProfitability] = useState<JsonRecord | null>(null),
    [tab, setTab] = useState("Overview");
  const [draft, setDraft] = useState(cleanFilters),
    [filters, setFilters] = useState(cleanFilters),
    [error, setError] = useState<ApiClientError | null>(null),
    [filterError, setFilterError] = useState(""),
    [periodPreset, setPeriodPreset] = useState<PeriodPreset>("month");
  useEffect(() => {
    erpApi
      .product360(id, filters)
      .then((r) => setData(r.data))
      .catch((v) => setError(normalizeError(v)));
  }, [id, filters]);
  useEffect(() => {
    if (tab === "Stock Statement")
      erpApi
        .stockStatement(id, filters)
        .then((r) => setStatement(r.data))
        .catch((v) => setError(normalizeError(v)));
  }, [tab, filters, id]);
  useEffect(() => {
    if (tab === "Profitability" && hasPermission("reports.view_profit"))
      erpApi.productProfitability(id, filters).then((r) => setProfitability(r.data)).catch((v) => setError(normalizeError(v)));
  }, [tab, filters, id, hasPermission]);
  const product = data?.profile || {};
  return (
    <div className="p-4 md:p-8 space-y-5">
      {((location.state as { successKey?: string; success?: string } | null)
        ?.successKey ||
        (location.state as { successKey?: string; success?: string } | null)
          ?.success) && (
        <div className="p-4 rounded-xl border bg-emerald-50 text-emerald-700">
          {(location.state as { successKey?: string; success?: string })
            .successKey
            ? t((location.state as { successKey: string }).successKey)
            : (location.state as { success?: string }).success}
        </div>
      )}
      <PageHeader
        title={
          product.name
            ? `${product.name} — ${t("Product 360")}`
            : t("Product 360")
        }
        description="One operational workspace for product identity, inventory, batches, movements and performance."
        action={
          <div className="flex flex-wrap gap-2">
            <PermissionGate permission="products.manage">
              <Link className="report-action" to={`/products/${id}/edit`}>
                {t("Edit Product")}
              </Link>
            </PermissionGate>
            <PrintReportButton
              type="product_statement"
              filters={{ ...filters, product_id: id }}
              label={t("Print Product Statement")}
            />
          </div>
        }
      />
      <ErrorNotice error={error} />
      {data && (
        <Identity product={product} controlled={data.controlled_status} />
      )}
      <div className="flex gap-2 overflow-x-auto pb-1" role="tablist">
        {tabs.filter((x) => x !== "Profitability" || hasPermission("reports.view_profit")).map((x) => (
          <button
            type="button"
            role="tab"
            aria-selected={tab === x}
            className={`px-4 py-2.5 rounded-xl whitespace-nowrap ${tab === x ? "bg-teal-600 text-white" : "bg-white border"}`}
            onClick={() => { setTab(x); if (x === "Profitability" && !filters.from && !filters.to) { setDraft(currentMonthFilters); setFilters(currentMonthFilters); } }}
            key={x}
          >
            {t(x)}
          </button>
        ))}
      </div>
      {tab === "Stock Statement" && (
        <form
          className="rounded-2xl border bg-white p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
          onSubmit={(e) => {
            e.preventDefault();
            setFilters({ ...draft, page: 1 });
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
          <EntitySelect
            label="Branch"
            value={draft.branch_id}
            onChange={(v) => setDraft({ ...draft, branch_id: v })}
            options={branches}
            allLabel="All branches"
          />
          <EntitySelect
            label="Batch / Lot"
            value={draft.batch_id}
            onChange={(v) => setDraft({ ...draft, batch_id: v })}
            options={batches}
            allLabel="All batches"
            help="The manufacturer's batch number used for expiry and recall tracking."
          />
          <Field label="Movement Type">
            <select
              className="input"
              value={draft.movement_type}
              onChange={(e) =>
                setDraft({ ...draft, movement_type: e.target.value })
              }
            >
              {movementOptions.map(([v, l]) => (
                <option key={v} value={v}>
                  {t(l)}
                </option>
              ))}
            </select>
          </Field>
          <div className="flex gap-2 lg:col-span-5">
            <button className="report-action primary">
              {t("Apply Filters")}
            </button>
            <button
              type="button"
              className="report-action"
              onClick={() => {
                setDraft(cleanFilters);
                setFilters(cleanFilters);
              }}
            >
              {t("Clear All")}
            </button>
          </div>
        </form>
      )}
      {tab === "Profitability" && (
        <form
          className="rounded-2xl border bg-white p-4 space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            if (!draft.from || !draft.to || draft.from > draft.to) {
              setFilterError(t("The start date must be on or before the end date."));
              return;
            }
            setFilterError("");
            setFilters({ ...cleanFilters, from: draft.from, to: draft.to, branch_id: draft.branch_id });
          }}
        >
          <div className="flex flex-wrap gap-2" aria-label={t("Quick period")}>
            {(["today", "week", "month", "last_month", "year", "custom"] as PeriodPreset[]).map((preset) => (
              <button key={preset} type="button" className={`rounded-lg border px-3 py-2 text-sm font-medium ${periodPreset === preset ? "border-teal-600 bg-teal-50 text-teal-800" : "bg-white"}`} onClick={() => {
                setPeriodPreset(preset);
                const range = periodRange(preset);
                if (range) setDraft((current) => ({ ...current, ...range }));
              }}>{t(({today:"Today",week:"This Week",month:"This Month",last_month:"Last Month",year:"This Year",custom:"Custom Period"})[preset])}</button>
            ))}
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label={t("From Date")}><input className="input" type="date" value={draft.from} onChange={(e) => { setPeriodPreset("custom"); setDraft({ ...draft, from: e.target.value }); }} /></Field>
            <Field label={t("To Date")}><input className="input" type="date" value={draft.to} onChange={(e) => { setPeriodPreset("custom"); setDraft({ ...draft, to: e.target.value }); }} /></Field>
            <EntitySelect label={t("Branch")} value={draft.branch_id} onChange={(v) => setDraft({ ...draft, branch_id: v })} options={branches} allLabel={t("All Branches")} />
          </div>
          {filterError && <p role="alert" className="text-sm font-medium text-rose-700">{filterError}</p>}
          <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
            <p className="text-sm text-gray-600">{t("Applied Period")}: <bdi dir="ltr" className="font-semibold tabular-nums">{formatNumericDate(filters.from || null)} – {formatNumericDate(filters.to || null)}</bdi></p>
            <div className="flex gap-2"><button className="report-action primary">{t("Apply Filters")}</button><button type="button" className="report-action" onClick={() => { setPeriodPreset("month"); setFilterError(""); setDraft(currentMonthFilters); setFilters(currentMonthFilters); }}>{t("Clear All")}</button></div>
          </div>
        </form>
      )}
      {!data ? (
        <div className="h-40 animate-pulse rounded-2xl bg-gray-100" />
      ) : (
        <Tab
          name={tab}
          data={data}
          statement={statement}
          profitability={profitability}
          profitabilityFilters={filters}
          productId={id}
          branches={branches}
          onStatementPage={(page) => setFilters((current) => ({ ...current, page }))}
        />
      )}
    </div>
  );
}

function Identity({
  product,
  controlled,
}: {
  product: JsonRecord;
  controlled: JsonRecord;
}) {
  const { t } = useTranslation();
  const generic = product.generic_name || product.active_ingredient;
  return (
    <section className="bg-white border rounded-2xl p-5">
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <h2 className="text-lg font-bold">{t("Product identity")}</h2>
        <StatusBadge
          value={
            controlled?.is_controlled
              ? t("controlledStatus", {
                  schedule: controlled.schedule
                    ? ` — ${controlled.schedule}`
                    : "",
                })
              : t("Regular")
          }
        />
      </div>
      <dl className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
        <Info label={t("Name")} value={product.name} />
        {generic && (
          <Info label={t("Generic / ingredient")} value={generic} />
        )}{" "}
        {product.strength && (
          <Info label={t("Strength")} value={product.strength} />
        )}
        <Info
          label={t("Barcode")}
          value={product.barcode || t("Not recorded")}
          technical
        />
        <Info
          label={t("Reorder level")}
          value={product.reorder_level ?? product.minimum_stock ?? t("Not set")}
        />
      </dl>
    </section>
  );
}

function Tab({
  name,
  data,
  statement,
  profitability,
  profitabilityFilters,
  productId,
  branches,
  onStatementPage,
}: {
  name: string;
  data: JsonRecord;
  statement: JsonRecord | null;
  profitability: JsonRecord | null;
  profitabilityFilters: typeof cleanFilters;
  productId: number;
  branches: { value: string; label: string }[];
  onStatementPage: (page: number) => void;
}) {
  const { t } = useTranslation();
  const batchRows = (data.batches || []) as JsonRecord[],
    today = new Date().toISOString().slice(0, 10);
  const active = batchRows.filter(
    (x) =>
      Number(x.quantity || 0) > 0 &&
      !["recalled", "quarantined"].includes(
        String(x.inventory_state || "").toLowerCase(),
      ),
  );
  const sellable = active.filter(
    (x) => !x.expiry_date || String(x.expiry_date) >= today,
  );
  const nearest = sellable
    .map((x) => String(x.expiry_date || ""))
    .filter(Boolean)
    .sort()[0];
  const branchRows = ((data.stock_by_branch || []) as JsonRecord[]).map(
    (x) => ({
      branch:
        branches.find((b) => b.value === String(x.branch_id))?.label ||
        t("branchReference", { id: x.branch_id }),
      quantity: x.quantity,
    }),
  );
  if (name === "Overview")
    return (
      <div className="space-y-5">
        <MetricGrid
          data={{
            current_stock: branchRows.reduce(
              (n, x) => n + Number(x.quantity || 0),
              0,
            ),
            sellable_stock: sellable.reduce(
              (n, x) => n + Number(x.quantity || 0),
              0,
            ),
            active_batches: active.length,
            nearest_expiry: nearest || t("None"),
            sale_price: sellable[0]?.sale_price,
            cost: sellable[0]?.purchase_price,
            reorder_level:
              data.reorder_state?.reorder_level ?? data.profile?.reorder_level,
          }}
          keys={[
            "current_stock",
            "sellable_stock",
            "active_batches",
            "nearest_expiry",
            "sale_price",
            "cost",
            "reorder_level",
          ]}
        />
        {(Number(data.expiry_state?.expired || 0) > 0 ||
          Number(data.expiry_state?.expiring || 0) > 0) && (
          <div className="p-4 rounded-xl bg-amber-50 text-amber-900">
            {t("expiryAttention", {
              expired: data.expiry_state?.expired || 0,
              expiring: data.expiry_state?.expiring || 0,
            })}
          </div>
        )}
        {(data.recall_quarantine || []).length > 0 && (
          <div className="p-4 rounded-xl bg-red-50 text-red-900">
            {t(
              "Recall or quarantine activity exists for this product. Review the dedicated tab before dispensing.",
            )}
          </div>
        )}
        <section className="bg-white border rounded-2xl overflow-hidden">
          <h2 className="p-4 font-bold">{t("Inventory by branch")}</h2>
          <RecordTable
            rows={branchRows}
            emptyText={t("No stock is currently recorded for this product.")}
          />
        </section>
        <section className="bg-white border rounded-2xl overflow-hidden">
          <h2 className="p-4 font-bold">{t("Active batch summary")}</h2>
          <RecordTable
            rows={active.slice(0, 8)}
            emptyText={t("No active batches are currently available.")}
          />
        </section>
      </div>
    );
  if (name === "Stock Statement")
    return (
      <div className="space-y-3">
        <StockMovementTable
          rows={statement?.movements?.data || []}
          emptyText={t("No stock movements match these filters.")}
        />
        {Number(statement?.movements?.last_page || 1) > 1 && (
          <div className="flex items-center justify-end gap-2">
            <button type="button" className="report-action" disabled={Number(statement?.movements?.current_page || 1) <= 1} onClick={() => onStatementPage(Number(statement?.movements?.current_page) - 1)}>
              {t("Previous")}
            </button>
            <span>{Number(statement?.movements?.current_page || 1)} / {Number(statement?.movements?.last_page || 1)}</span>
            <button type="button" className="report-action" disabled={Number(statement?.movements?.current_page || 1) >= Number(statement?.movements?.last_page || 1)} onClick={() => onStatementPage(Number(statement?.movements?.current_page) + 1)}>
              {t("Next")}
            </button>
          </div>
        )}
      </div>
    );
  if (name === "Profitability")
    return <ProfitabilityView data={profitability} filters={profitabilityFilters} />;
  if (name === "Batches")
    return (
      <RecordTable
        rows={batchRows}
        emptyText={t("No batches are currently available for this product.")}
      />
    );
  const metricTabs: Record<string, string[]> = {
    Purchases: ["purchase_quantity", "purchase_returns", "purchase_value"],
    Sales: ["sold_quantity", "sales_returns"],
    "Prescription Usage": ["prescription_dispensing"],
    Adjustments: ["stock_adjustments"],
    "Stock Counts": ["stock_count_differences"],
    Transfers: ["transfers"],
  };
  if (metricTabs[name])
    return <MetricGrid data={data} keys={metricTabs[name]} />;
  if (name === "Recall / Quarantine")
    return (
      <RecordTable
        rows={data.recall_quarantine || []}
        emptyText={t("This product has no recall or quarantine activity.")}
      />
    );
  return <CompleteUnitsManager id={productId} />;
}

function ProfitabilityView({ data, filters }: { data: JsonRecord | null; filters: typeof cleanFilters }) {
  const { t } = useTranslation();
  if (!data) return <div className="h-40 animate-pulse rounded-2xl bg-gray-100" />;
  const quantity = (value: unknown) => <bdi dir="ltr" className="tabular-nums">{Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 })}</bdi>;
  const money = (value: unknown) => <bdi dir="ltr" className="tabular-nums">{formatMoney(Number(value || 0))}</bdi>;
  const margin = <bdi dir="ltr" className="tabular-nums">{Number(data.gross_margin_percent || 0).toFixed(2)}%</bdi>;
  const noSales = Number(data.sold_quantity || 0) === 0;
  return <div className="space-y-5">
    {noSales && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center text-amber-900"><strong>{t("No sales exist for this product in the selected period.")}</strong></div>}
    <ProfitSection title={t("Sales Movement")}>
      <ProfitMetric label={t("Sold Quantity")} value={quantity(data.sold_quantity)} />
      <ProfitMetric label={t("Sales Return Quantity")} value={quantity(data.sales_return_quantity)} />
      <ProfitMetric label={t("Net Sold Quantity")} value={quantity(data.net_sold_quantity)} />
      <ProfitMetric label={t("Gross Sales Revenue")} value={money(data.gross_sales_revenue)} />
      <ProfitMetric featured label={t("Net Sales Revenue")} value={money(data.net_sales_revenue)} help={t("Sales after subtracting the value of returns.")} />
    </ProfitSection>
    <ProfitSection title={t("Profitability")}>
      <ProfitMetric label={t("COGS")} value={money(data.cogs)} help={t("The actual historical cost of items sold during the period.")} />
      <ProfitMetric featured positive={Number(data.gross_profit || 0) >= 0} label={t("Gross Profit")} value={money(data.gross_profit)} help={t("Net sales minus cost of goods sold.")} />
      <ProfitMetric featured positive={Number(data.gross_profit || 0) >= 0} label={t("Gross Margin %")} value={margin} help={t("Gross profit as a percentage of net sales.")} />
      <ProfitMetric label={t("Average Actual Sale Price")} value={money(data.average_actual_sale_price)} />
      <ProfitMetric label={t("Average Historical Cost")} value={money(data.average_historical_cost)} />
      <div className="sm:col-span-2 lg:col-span-3 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600">
        <bdi dir="ltr">{formatMoney(Number(data.net_sales_revenue || 0))}</bdi> {t("Net Sales Revenue")} − <bdi dir="ltr">{formatMoney(Number(data.cogs || 0))}</bdi> {t("COGS")} = <strong><bdi dir="ltr">{formatMoney(Number(data.gross_profit || 0))}</bdi> {t("Gross Profit")}</strong>
      </div>
    </ProfitSection>
    <ProfitSection title={t("Other Factors Affecting Profit")} subtitle={t("These values are accounted for separately and are not included in gross profit.")}>
      <ProfitMetric negative label={t("Non-Restocked Return Loss")} value={money(data.non_restocked_return_loss)} help={t("Returns deducted from sales where the goods were not returned to inventory.")} />
      <ProfitMetric label={t("Return Deductions / Other Income")} value={money(data.return_deductions_other_income)} />
      <ProfitMetric negative label={t("Inventory Adjustment Losses")} value={money(data.inventory_adjustment_losses)} />
    </ProfitSection>
    <p className="text-xs text-gray-500">{t("Applied Period")}: <bdi dir="ltr">{formatNumericDate(filters.from || null)} – {formatNumericDate(filters.to || null)}</bdi></p>
  </div>;
}

function ProfitSection({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return <section className="rounded-2xl border bg-white p-4 sm:p-5"><div className="mb-4"><h2 className="text-lg font-bold text-gray-900">{title}</h2>{subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}</div><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{children}</div></section>;
}

function ProfitMetric({ label, value, help, featured = false, positive = false, negative = false }: { label: string; value: ReactNode; help?: string; featured?: boolean; positive?: boolean; negative?: boolean }) {
  return <div className={`rounded-xl border p-4 ${featured ? "border-teal-200 bg-teal-50/60" : "bg-gray-50/60"}`}><div className="flex items-start gap-2"><p className="text-xs font-semibold uppercase tracking-wide text-gray-600">{label}</p>{help && <span title={help} aria-label={help} tabIndex={0} className="inline-flex h-5 w-5 shrink-0 cursor-help items-center justify-center rounded-full border text-xs text-gray-500">i</span>}</div><div className={`mt-2 font-bold ${featured ? "text-2xl" : "text-xl"} ${positive ? "text-emerald-700" : negative ? "text-rose-700" : "text-gray-900"}`}>{value}</div>{help && <p className="sr-only">{help}</p>}</div>;
}

function StockMovementTable({ rows, emptyText }: { rows: JsonRecord[]; emptyText: string }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [expanded, setExpanded] = useState<number | null>(null);
  if (!rows.length) {
    return (
      <div className="rounded-2xl border bg-white p-10 text-center">
        <strong className="text-gray-700">{t("Nothing to show")}</strong>
        <p className="mt-1 text-sm text-gray-500">{emptyText}</p>
      </div>
    );
  }
  return (
    <div className="rounded-2xl border bg-white overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px]">
          <thead className="bg-gray-50">
            <tr>
              <th>{t("Date / Time")}</th>
              <th>{t("Movement Type")}</th>
              <th>{t("Signed Quantity")}</th>
              <th className="hidden md:table-cell">{t("Direction")}</th>
              <th>{t("Reference")}</th>
              <th className="hidden lg:table-cell">{t("Batch / Lot")}</th>
              <th className="hidden xl:table-cell">{t("Branch")}</th>
              <th>{t("Details")}</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {rows.map((row) => {
              const id = Number(row.id);
              const signed = Number(row.signed_quantity ?? movementSign(row.movement_type) * Number(row.quantity || 0));
              const path = movementSourcePath(String(row.source_type || ""), Number(row.source_id));
              const isExpanded = expanded === id;
              return (
                <Fragment key={id}>
                  <tr
                    className={path ? "cursor-pointer hover:bg-teal-50/50" : "hover:bg-gray-50/60"}
                    onClick={() => path && navigate(path)}
                    onKeyDown={(event) => {
                      if (path && (event.key === "Enter" || event.key === " ")) navigate(path);
                    }}
                    tabIndex={path ? 0 : undefined}
                  >
                    <td><bdi dir="ltr" className="whitespace-nowrap text-sm">{new Intl.DateTimeFormat(i18n.language, { dateStyle: "medium", timeStyle: "short" }).format(new Date(String(row.created_at)))}</bdi></td>
                    <td><span className="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 whitespace-nowrap">{t(human(String(row.movement_type)))}</span></td>
                    <td><bdi dir="ltr" className={`font-bold tabular-nums ${signed > 0 ? "text-emerald-700" : signed < 0 ? "text-rose-700" : "text-gray-700"}`}>{signed > 0 ? "+" : ""}{signed}</bdi></td>
                    <td className="hidden md:table-cell"><span className={`text-sm font-medium ${signed >= 0 ? "text-emerald-700" : "text-rose-700"}`}>{t(signed >= 0 ? "Incoming" : "Outgoing")}</span></td>
                    <td className="max-w-52 truncate">
                      {path ? <Link to={path} onClick={(event) => event.stopPropagation()} className="font-semibold text-teal-700 hover:underline"><bdi>{movementReference(row, t)}</bdi></Link> : <bdi className="text-gray-700">{movementReference(row, t)}</bdi>}
                    </td>
                    <td className="hidden lg:table-cell"><bdi dir="ltr">{String(row.batch_number || "—")}</bdi></td>
                    <td className="hidden xl:table-cell"><span className="block max-w-44 truncate">{branchLabel(row)}</span></td>
                    <td>
                      <button type="button" className="text-sm font-semibold text-teal-700 hover:underline" aria-expanded={isExpanded} onClick={(event) => { event.stopPropagation(); setExpanded(isExpanded ? null : id); }}>
                        {t(isExpanded ? "Hide details" : "View details")}
                      </button>
                    </td>
                  </tr>
                  {isExpanded && (
                    <tr className="bg-gray-50/70">
                      <td colSpan={8} className="p-4">
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                          <MovementDetail label={t("Team Member")} value={row.user_name || t("System")} />
                          <MovementDetail label={t("Batch / Lot")} value={row.batch_number || "—"} ltr />
                          <MovementDetail label={t("Expiry")} value={row.batch_expiry || "—"} ltr />
                          <MovementDetail label={t("Branch")} value={branchLabel(row)} />
                          <MovementDetail label={t("Movement ID")} value={row.id} ltr />
                          <MovementDetail label={t("Source ID")} value={row.source_id || "—"} ltr />
                          {row.cost_basis !== undefined && <MovementDetail label={t("Unit Cost")} value={row.cost_basis} ltr />}
                        </dl>
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function movementSourcePath(type: string, id: number): string | null {
  if (!id) return null;
  const paths: Record<string, string> = {
    purchase: `/purchases/${id}`,
    purchase_invoice: `/purchases/${id}`,
    sale: `/sales/${id}`,
    sales_invoice: `/sales/${id}`,
    sales_cancel: `/sales/${id}`,
    sales_return: `/returns/sales/${id}`,
    purchase_return: `/returns/purchases/${id}`,
    prescription: `/prescriptions/${id}`,
  };
  return paths[type.toLowerCase()] ?? null;
}

function movementReference(row: JsonRecord, t: (key: string, options?: any) => string): string {
  return t("sourceReference", { type: t(human(String(row.source_type || "Source"))), id: row.source_id || "—" });
}

function movementSign(type: unknown): number {
  const value = String(type || "").toUpperCase();
  return value.includes("OUT") || ["SALE", "PURCHASE_RETURN"].includes(value) ? -1 : 1;
}

function branchLabel(row: JsonRecord): string {
  return [row.branch_code, row.branch_name].filter(Boolean).join(" — ") || "—";
}

function MovementDetail({ label, value, ltr = false }: { label: string; value: unknown; ltr?: boolean }) {
  return <div><dt className="text-xs text-gray-500">{label}</dt><dd className="mt-1 font-medium"><bdi dir={ltr ? "ltr" : undefined}>{String(value ?? "—")}</bdi></dd></div>;
}

function Info({
  label,
  value,
  technical = false,
}: {
  label: string;
  value: unknown;
  technical?: boolean;
}) {
  return (
    <div>
      <dt className="text-gray-500">{label}</dt>
      <dd className="font-semibold mt-1">
        <bdi dir={technical ? "ltr" : undefined}>{String(value ?? "—")}</bdi>
      </dd>
    </div>
  );
}
