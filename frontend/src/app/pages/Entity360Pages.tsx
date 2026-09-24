import { useEffect, useState } from "react";
import { useParams } from "react-router";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import {
  AttachmentsSection,
  ErrorNotice,
  MetricGrid,
  PrintDocumentButton,
  RecordTable,
  human,
  normalizeError,
  valueText,
} from "../components/ErpPrimitives";
import { ProductDetailsPage } from "./ProductDetailsPage";
import { useAuth } from "../../auth/useAuth";
import { ProductClosurePanel } from "../components/ProductClosurePanel";
import { CompleteUnitsManager } from "../components/CompleteUnitsManager";

export function Product360Page() {
  const id = Number(useParams().id);
  return (
    <>
      <ProductDetailsPage />
      <Product360Panel />
      <ProductClosurePanel id={id} />
      <div className="px-4 md:px-8 pb-8">
        <CompleteUnitsManager id={id} />
      </div>
    </>
  );
}
function Product360Panel() {
  const id = Number(useParams().id);
  const [data, setData] = useState<JsonRecord | null>(null),
    [statement, setStatement] = useState<JsonRecord | null>(null),
    [filters, setFilters] = useState({ from: "", to: "", branch_id: "" }),
    [tab, setTab] = useState("inventory"),
    [error, setError] = useState<ApiClientError | null>(null);
  const load = () =>
    erpApi
      .product360(id, filters)
      .then((r) => setData(r.data))
      .catch((v) => setError(normalizeError(v)));
  useEffect(() => {
    void load();
  }, [id]);
  useEffect(() => {
    if (tab === "stock statement")
      erpApi
        .stockStatement(id, filters)
        .then((r) => setStatement(r.data))
        .catch((v) => setError(normalizeError(v)));
  }, [tab]);
  const tabs = [
    "inventory",
    "stock statement",
    "batches",
    "purchasing",
    "sales",
    "prescription usage",
    "adjustments",
    "stock counts",
    "transfers",
    "recall / quarantine",
    "financial",
    "units",
  ];
  return (
    <div className="px-4 md:px-8 pb-8 space-y-4">
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void load();
        }}
        className="bg-white border p-4 rounded-2xl grid sm:grid-cols-4 gap-3"
      >
        <input
          className="input"
          type="date"
          value={filters.from}
          onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))}
        />
        <input
          className="input"
          type="date"
          value={filters.to}
          onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))}
        />
        <input
          className="input"
          placeholder="Branch ID"
          value={filters.branch_id}
          onChange={(e) =>
            setFilters((f) => ({ ...f, branch_id: e.target.value }))
          }
        />
        <button className="report-action primary">Apply</button>
      </form>
      <div className="flex gap-2 overflow-x-auto">
        {tabs.map((t) => (
          <button
            className={`px-4 py-2 rounded-xl whitespace-nowrap ${tab === t ? "bg-teal-600 text-white" : "bg-white border"}`}
            onClick={() => setTab(t)}
            key={t}
          >
            {human(t)}
          </button>
        ))}
      </div>
      <ErrorNotice error={error} />
      {!data ? (
        <div className="h-40 bg-gray-100 animate-pulse rounded-2xl" />
      ) : (
        <ProductTab
          tab={tab}
          data={data}
          statement={statement}
          productId={id}
        />
      )}
    </div>
  );
}
function ProductTab({
  tab,
  data,
  statement,
  productId,
}: {
  tab: string;
  data: JsonRecord;
  statement: JsonRecord | null;
  productId: number;
}) {
  if (tab === "inventory")
    return (
      <>
        <MetricGrid
          data={{
            total_stock: (data.stock_by_branch || []).reduce(
              (n: number, x: JsonRecord) => n + Number(x.quantity),
              0,
            ),
            ...data.expiry_state,
            ...data.reorder_state,
          }}
          keys={[
            "total_stock",
            "expired",
            "expiring",
            "minimum_stock",
            "reorder_level",
            "target_quantity",
          ]}
        />
        <RecordTable rows={data.stock_by_branch || []} />
      </>
    );
  if (tab === "stock statement")
    return (
      <section className="bg-white border rounded-2xl overflow-hidden">
        {statement && !statement.running_balance_available && (
          <div className="m-4 p-3 bg-amber-50 text-amber-800 rounded-xl">
            {statement.running_balance_reason}
          </div>
        )}
        {statement?.running_balance_available && (
          <MetricGrid
            data={statement}
            keys={["opening_quantity", "closing_quantity"]}
          />
        )}
        <RecordTable rows={statement?.movements?.data || []} />
      </section>
    );
  if (tab === "batches") return <RecordTable rows={data.batches || []} />;
  if (tab === "purchasing")
    return (
      <MetricGrid
        data={data}
        keys={["purchase_quantity", "purchase_returns", "purchase_value"]}
      />
    );
  if (tab === "sales")
    return <MetricGrid data={data} keys={["sold_quantity", "sales_returns"]} />;
  if (tab === "prescription usage")
    return <MetricGrid data={data} keys={["prescription_dispensing"]} />;
  if (tab === "adjustments")
    return <MetricGrid data={data} keys={["stock_adjustments"]} />;
  if (tab === "stock counts")
    return <MetricGrid data={data} keys={["stock_count_differences"]} />;
  if (tab === "transfers")
    return <MetricGrid data={data} keys={["transfers"]} />;
  if (tab === "recall / quarantine")
    return <RecordTable rows={data.recall_quarantine || []} />;
  if (tab === "financial")
    return (
      <MetricGrid
        data={data}
        keys={["revenue", "cogs", "gross_profit", "purchase_value"]}
      />
    );
  return <UnitsManager productId={productId} initial={data.units || []} />;
}
function UnitsManager({
  productId,
  initial,
}: {
  productId: number;
  initial: JsonRecord[];
}) {
  const { hasPermission } = useAuth();
  const [rows, setRows] = useState(initial),
    [all, setAll] = useState<JsonRecord[]>([]),
    [unit_id, setUnit] = useState(""),
    [base_quantity, setQty] = useState(""),
    [error, setError] = useState<ApiClientError | null>(null);
  useEffect(() => {
    erpApi.units().then((r) => setAll(r.data));
  }, []);
  const reload = () =>
    erpApi.productUnits(productId).then((r) => setRows(r.data));
  return (
    <section className="bg-white border rounded-2xl p-5">
      <ErrorNotice error={error} />
      {hasPermission("inventory.units.manage") && (
        <form
          className="flex flex-wrap gap-3 mb-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await erpApi.addUnit(productId, {
                unit_id: Number(unit_id),
                base_quantity: Number(base_quantity),
                is_purchase_unit: true,
                is_sale_unit: true,
              });
              await reload();
              setQty("");
            } catch (v) {
              setError(
                normalizeError(
                  v,
                  "Unit conversion could not be changed. It may be historically referenced.",
                ),
              );
            }
          }}
        >
          <select
            className="input sm:w-48"
            value={unit_id}
            onChange={(e) => setUnit(e.target.value)}
            required
          >
            <option value="">Select unit</option>
            {all.map((u) => (
              <option value={u.id} key={u.id}>
                {u.name} ({u.symbol})
              </option>
            ))}
          </select>
          <input
            className="input sm:w-48"
            type="number"
            step="any"
            min="0.001"
            placeholder="Base quantity"
            value={base_quantity}
            onChange={(e) => setQty(e.target.value)}
            required
          />
          <button className="report-action primary">Add packaging</button>
        </form>
      )}
      <RecordTable
        rows={rows}
        columns={[
          "id",
          "unit_name",
          "name",
          "symbol",
          "base_quantity",
          "is_purchase_unit",
          "is_sale_unit",
          "is_active",
        ]}
      />
      {hasPermission("inventory.units.manage") && (
        <div className="mt-3 text-sm text-gray-500">
          Referenced conversions are protected by the backend. Disable instead
          of deleting historical packaging.
        </div>
      )}
    </section>
  );
}

export function Batch360Page() {
  const { t } = useTranslation();
  const id = Number(useParams().id),
    [data, setData] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null);
  useEffect(() => {
    erpApi
      .batch360(id)
      .then((r) => setData(r.data))
      .catch((v) => setError(normalizeError(v)));
  }, [id]);
  if (!data)
    return (
      <div className="p-8">
        <ErrorNotice error={error} />
      </div>
    );
  const sections = [
    "originating_purchases",
    "sales",
    "sales_returns",
    "purchase_returns",
    "prescription_dispensing",
    "adjustments",
    "stock_counts",
    "transfers",
  ];
  return (
    <div className="p-4 md:p-8 space-y-5">
      <div className="flex flex-wrap justify-between">
        <div>
          <h1 className="text-3xl font-bold">
            {t("batchReference", { batch: data.batch_number })}
          </h1>
          <p>
            <bdi>{data.product?.name}</bdi> ·{" "}
            <bdi>
              {data.branch?.name ||
                t("branchReference", { id: data.branch?.id || "—" })}
            </bdi>
          </p>
        </div>
        <div className="flex gap-2">
          <PrintDocumentButton type="batch_statement" id={id} />
        </div>
      </div>
      <MetricGrid
        data={data}
        keys={["expiry", "current_state", "current_quantity", "unit_cost"]}
      />
      {!data.linkage_complete && (
        <div className="p-4 bg-amber-50 text-amber-800 rounded-xl">
          {t(
            "Some older movements do not contain full party or source linkage.",
          )}
        </div>
      )}
      {sections.map((s) => (
        <section
          className="bg-white border rounded-2xl overflow-hidden"
          key={s}
        >
          <h2 className="p-4 font-bold border-b">{t(human(s))}</h2>
          <RecordTable rows={data[s] || []} />
        </section>
      ))}
    </div>
  );
}

export function RecallPage() {
  const { t } = useTranslation();
  const id = Number(useParams().id),
    [data, setData] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null);
  useEffect(() => {
    erpApi
      .recall(id)
      .then((r) => setData(r.data))
      .catch((v) => setError(normalizeError(v)));
  }, [id]);
  if (!data)
    return (
      <div className="p-8">
        <ErrorNotice error={error} />
      </div>
    );
  const keys = [
    "source_purchases",
    "suppliers",
    "sales_documents",
    "customers",
    "sales_returns",
    "prescription_dispensing",
    "transfers",
    "adjustments",
    "stock_count_changes",
  ];
  return (
    <div className="p-4 md:p-8 space-y-5">
      <div className="flex justify-between">
        <div>
          <h1 className="text-3xl font-bold">{t("Recall Investigation")}</h1>
          <p>
            <bdi>{data.product?.name}</bdi> ·{" "}
            {t("batchReference", { batch: data.batch?.batch_number })}
          </p>
        </div>
        <PrintDocumentButton type="recall" id={id} />
      </div>
      <MetricGrid
        data={data}
        keys={[
          "recall_reason",
          "recall_status",
          "issued_at",
          "purchased_quantity",
          "current_quantity",
          "sales_quantity",
        ]}
      />
      {!data.linkage_complete && (
        <div className="p-4 bg-amber-50 rounded-xl text-amber-800">
          {t(
            "Some historical records predate full linkage. Missing names have not been fabricated.",
          )}
        </div>
      )}
      {keys.map((k) => (
        <section
          className="bg-white border rounded-2xl overflow-hidden"
          key={k}
        >
          <h2 className="p-4 font-bold border-b">{t(human(k))}</h2>
          <RecordTable rows={data[k] || []} />
        </section>
      ))}
      <AttachmentsSection type="batch_recall" id={id} />
    </div>
  );
}
