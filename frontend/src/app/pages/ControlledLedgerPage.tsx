import { useEffect, useState } from "react";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import {
  ErrorNotice,
  MetricGrid,
  PrintReportButton,
  RecordTable,
  normalizeError,
} from "../components/ErpPrimitives";
import {
  EntitySelect,
  Field,
  PageHeader,
  RemoteEntitySelect,
  movementOptions,
} from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { batchEntityLoader, productEntityLoader } from "../entityLoaders";
import { useTranslation } from "react-i18next";
export function ControlledLedgerPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    empty = {
      from: "",
      to: "",
      product_id: "",
      batch_id: "",
      branch_id: "",
      movement_type: "",
    },
    [draft, setDraft] = useState<JsonRecord>(empty),
    [filters, setFilters] = useState<JsonRecord>({}),
    [data, setData] = useState<JsonRecord | null>(null),
    [page, setPage] = useState(1),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null);
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    erpApi
      .controlledLedger({ ...filters, page }, c.signal)
      .then((r) => setData(r.data))
      .catch((v) =>
        setError(
          normalizeError(
            v,
            t("The controlled medicine ledger could not be loaded. Check your filters and try again."),
          ),
        ),
      )
      .finally(() => setLoading(false));
    return () => c.abort();
  }, [filters, page]);
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Controlled Medicine Ledger"
        description="Review every controlled-medicine stock movement, its source and the responsible team member."
        action={
          <PrintReportButton
            type="controlled_medicine_ledger"
            filters={filters}
            label="Print Ledger"
          />
        }
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setFilters({ ...draft });
        }}
        className="rounded-2xl border bg-white p-5"
      >
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
            label="Product"
            value={String(draft.product_id)}
            onChange={(v) => setDraft({ ...draft, product_id: v, batch_id: "" })}
            load={productEntityLoader}
            placeholder="Search controlled product name or barcode"
          />
          <RemoteEntitySelect
            label="Batch / Lot"
            value={String(draft.batch_id)}
            onChange={(v) => setDraft({ ...draft, batch_id: v })}
            load={batchEntityLoader(Number(draft.product_id || 0), Number(draft.branch_id || 0) || undefined)}
            disabled={!draft.product_id}
            help="The manufacturer's batch number used for expiry and recall tracking."
          />
          <EntitySelect
            label="Branch"
            value={String(draft.branch_id)}
            onChange={(v) => setDraft({ ...draft, branch_id: v, batch_id: "" })}
            options={branches}
            allLabel="All branches"
          />
          <Field label="Movement Type">
            <select
              className="input"
              value={String(draft.movement_type)}
              onChange={(e) =>
                setDraft({ ...draft, movement_type: e.target.value })
              }
            >
              {movementOptions.map(([v, l]) => (
                <option value={v} key={v}>
                  {t(l)}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <button className="report-action primary">{t("Apply Filters")}</button>
          <button
            type="button"
            className="report-action"
            onClick={() => {
              setDraft(empty);
              setFilters({});
              setPage(1);
            }}
          >
            {t("Clear All")}
          </button>
        </div>
      </form>
      <ErrorNotice error={error} />
      {data && (
        <MetricGrid
          data={data.summary || {}}
          keys={[
            "opening",
            "received",
            "sold",
            "prescription_dispensed",
            "returns",
            "adjustments",
            "transfers",
            "closing",
          ]}
        />
      )}
      <section className="bg-white border rounded-2xl overflow-hidden">
        {loading ? (
          <div className="p-12 text-center animate-pulse">
            {t("Loading controlled medicine movements…")}
          </div>
        ) : (
          <RecordTable
            rows={data?.rows || []}
            columns={[
              "timestamp",
              "product",
              "batch_number",
              "in",
              "out",
              "type",
              "source_label",
              "actor",
              "disposition",
              ...(data?.patient_identity_included
                ? ["patient", "prescription", "prescriber"]
                : []),
            ]}
            emptyText={t("No controlled medicine movements match these filters.")}
          />
        )}
        <div className="p-3 flex justify-between">
          <button
            className="report-action"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            {t("Previous Page")}
          </button>
          <span>
            {t("pageOf", { page, lastPage: data?.meta?.last_page || 1 })}
          </span>
          <button
            className="report-action"
            disabled={page >= (data?.meta?.last_page || 1)}
            onClick={() => setPage((p) => p + 1)}
          >
            {t("Next Page")}
          </button>
        </div>
      </section>
    </div>
  );
}
