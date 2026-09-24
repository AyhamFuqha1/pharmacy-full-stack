import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import {
  EntitySelect,
  Field,
  FormSection,
  PageHeader,
  RemoteEntitySelect,
} from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { batchEntityLoader, productEntityLoader } from "../entityLoaders";
import {
  ErrorNotice,
  MetricGrid,
  PrintDocumentButton,
  RecordTable,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";
function useUnits() {
  const [rows, setRows] = useState<{ id: number | string; label: string }[]>(
    [],
  );
  useEffect(() => {
    erpApi.units().then((r) =>
      setRows(
        r.data.map((x) => ({
          id: x.id,
          label: x.name,
        })),
      ),
    );
  }, []);
  return rows;
}
export function FriendlyAdjustmentPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    units = useUnits(),
    [f, setF] = useState<JsonRecord>({
      branch_id: "",
      product_id: "",
      batch_id: "",
      unit_id: "",
      quantity: "",
      reason: "damaged",
      note: "",
    }),
    [result, setResult] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      setResult(
        (
          await erpApi.adjustmentCreate({
            ...f,
            branch_id: Number(f.branch_id),
            product_id: Number(f.product_id),
            batch_id: Number(f.batch_id),
            unit_id: f.unit_id ? Number(f.unit_id) : null,
            quantity: Number(f.quantity),
          })
        ).data,
      );
    } catch (v) {
      setError(
        normalizeError(
          v,
          t(
            "The stock adjustment could not be recorded. Check the available batch quantity and try again.",
          ),
        ),
      );
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Stock Adjustment"
        description="Record damage, loss or a stock correction. Use purchases and sales for normal trading activity."
      />
      <ErrorNotice error={error} />
      {result ? (
        <>
          <MetricGrid
            data={result}
            keys={["document_number", "status", "quantity", "reason"]}
          />
          <PrintDocumentButton type="stock_adjustment" id={Number(result.id)} />
        </>
      ) : (
        <form className="space-y-5" onSubmit={submit}>
          <FormSection title="Stock to adjust">
            <EntitySelect
              required
              label="Branch"
              value={String(f.branch_id)}
              onChange={(v) => setF({ ...f, branch_id: v, batch_id: "" })}
              options={branches}
            />
            <RemoteEntitySelect
              required
              label="Product"
              value={String(f.product_id)}
              onChange={(v) => setF({ ...f, product_id: v, batch_id: "" })}
              load={productEntityLoader}
              placeholder="Search product name or barcode"
            />
            <RemoteEntitySelect
              required
              label="Batch / Lot"
              value={String(f.batch_id)}
              onChange={(v) => setF({ ...f, batch_id: v })}
              load={batchEntityLoader(Number(f.product_id || 0), Number(f.branch_id || 0))}
              disabled={!f.product_id || !f.branch_id}
              help="The manufacturer's batch number used for expiry and recall tracking."
            />
            <EntitySelect
              label="Unit / Packaging"
              value={String(f.unit_id)}
              onChange={(v) => setF({ ...f, unit_id: v })}
              options={units}
              allLabel="Product base unit"
            />
            <Field
              required
              label="Quantity Change"
              hint="Use a positive number to add stock or a negative number to reduce it."
            >
              <input
                required
                className="input"
                type="number"
                dir="ltr"
                step="any"
                value={String(f.quantity)}
                onChange={(e) => setF({ ...f, quantity: e.target.value })}
              />
            </Field>
            <Field required label="Reason">
              <select
                className="input"
                value={String(f.reason)}
                onChange={(e) => setF({ ...f, reason: e.target.value })}
              >
                <option value="damaged">{t("Damaged stock")}</option>
                <option value="other">{t("Lost stock")}</option>
                <option value="expired">{t("Expired stock")}</option>
                <option value="administrative_correction">{t("Inventory correction")}</option>
                <option value="other">{t("Other")}</option>
              </select>
            </Field>
            <Field
              label="Notes"
              hint="Optional details explaining why this adjustment is necessary."
            >
              <textarea
                className="input"
                value={String(f.note)}
                onChange={(e) => setF({ ...f, note: e.target.value })}
              />
            </Field>
          </FormSection>
          <button className="report-action primary">
            {t("Record Stock Adjustment")}
          </button>
        </form>
      )}
    </div>
  );
}
export function FriendlyTransferPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    units = useUnits(),
    [f, setF] = useState<JsonRecord>({
      source_branch_id: "",
      destination_branch_id: "",
      product_id: "",
      batch_id: "",
      quantity: "",
      unit_id: "",
      notes: "",
    }),
    [doc, setDoc] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null);
  const transfer = (doc?.transfer || doc) as JsonRecord | null;
  async function create(e: React.FormEvent) {
    e.preventDefault();
    try {
      setDoc(
        (
          await erpApi.transferCreate({
            source_branch_id: Number(f.source_branch_id),
            destination_branch_id: Number(f.destination_branch_id),
            notes: f.notes,
            lines: [
              {
                batch_id: Number(f.batch_id),
                quantity: Number(f.quantity),
                unit_id: f.unit_id ? Number(f.unit_id) : null,
              },
            ],
          })
        ).data,
      );
    } catch (v) {
      setError(
        normalizeError(
          v,
          t(
            "The transfer draft could not be created. Check the selected branches, batch and available quantity.",
          ),
        ),
      );
    }
  }
  async function act(action: "send" | "receive" | "cancel") {
    if (
      !transfer ||
      !confirm(
        t("transferActionQuestion", {
          action: t(
            action === "send"
              ? "Send Transfer"
              : action === "receive"
                ? "Receive Transfer"
                : "Cancel Transfer",
          ),
          consequence: t(
            action === "send"
              ? "Stock will leave the source branch and become in transit."
              : action === "receive"
                ? "Stock will be added to the destination branch."
                : "The draft transfer will not move stock.",
          ),
        }),
      )
    )
      return;
    try {
      setDoc({
        ...doc,
        transfer: (await erpApi.transferAction(Number(transfer.id), action))
          .data,
      });
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Stock Transfer"
        description="Move stock between pharmacy branches while preserving batch and expiry information."
      />
      <ErrorNotice error={error} />
      {transfer ? (
        <>
          <MetricGrid
            data={transfer}
            keys={[
              "document_number",
              "source_branch",
              "destination_branch",
              "status",
              "sent_at",
              "received_at",
            ]}
          />
          <RecordTable
            rows={doc?.lines || []}
            columns={["product_name", "product_barcode", "batch_number", "expiry_date", "quantity", "unit_cost"]}
            emptyText="No transfer lines were returned."
          />
          <div className="flex flex-wrap gap-2">
            {transfer.status === "draft" && (
              <>
                <button
                  className="report-action primary"
                  onClick={() => void act("send")}
                >
                  {t("Send Transfer")}
                </button>
                <button
                  className="report-action text-red-600"
                  onClick={() => void act("cancel")}
                >
                  {t("Cancel Transfer")}
                </button>
              </>
            )}
            {transfer.status === "in_transit" && (
              <button
                className="report-action primary"
                onClick={() => void act("receive")}
              >
                {t("Receive Transfer")}
              </button>
            )}
            <PrintDocumentButton type="transfer" id={Number(transfer.id)} />
          </div>
        </>
      ) : (
        <form className="space-y-5" onSubmit={create}>
          <FormSection title="Transfer route">
            <EntitySelect
              required
              label="From Branch"
              value={String(f.source_branch_id)}
              onChange={(v) => setF({ ...f, source_branch_id: v, product_id: "", batch_id: "" })}
              options={branches}
            />
            <EntitySelect
              required
              label="To Branch"
              value={String(f.destination_branch_id)}
              onChange={(v) => setF({ ...f, destination_branch_id: v })}
              options={branches}
            />
          </FormSection>
          <FormSection title="Stock to move">
            <RemoteEntitySelect
              required
              label="Product"
              value={String(f.product_id)}
              onChange={(v) => setF({ ...f, product_id: v, batch_id: "" })}
              load={productEntityLoader}
              placeholder="Search product name or barcode"
              disabled={!f.source_branch_id}
            />
            <RemoteEntitySelect
              required
              label="Batch / Lot"
              value={String(f.batch_id)}
              onChange={(v) => setF({ ...f, batch_id: v })}
              load={batchEntityLoader(Number(f.product_id || 0), Number(f.source_branch_id || 0))}
              disabled={!f.product_id || !f.source_branch_id}
            />
            <Field required label="Quantity">
              <input
                required
                className="input"
                min="0.001"
                step="any"
                type="number"
                value={String(f.quantity)}
                onChange={(e) => setF({ ...f, quantity: e.target.value })}
              />
            </Field>
            <EntitySelect
              label="Unit / Packaging"
              value={String(f.unit_id)}
              onChange={(v) => setF({ ...f, unit_id: v })}
              options={units}
              allLabel="Product base unit"
            />
            <Field label="Transfer Notes">
              <textarea
                className="input"
                value={String(f.notes)}
                onChange={(e) => setF({ ...f, notes: e.target.value })}
              />
            </Field>
          </FormSection>
          <button className="report-action primary">
            {t("Create Transfer Draft")}
          </button>
        </form>
      )}
    </div>
  );
}
export function FriendlyReorderPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    [branch, setBranch] = useState(""),
    [product, setProduct] = useState(""),
    [rows, setRows] = useState<JsonRecord[]>([]),
    [error, setError] = useState<ApiClientError | null>(null);
  const load = () =>
    erpApi
      .reorder(branch, product)
      .then((r) => setRows(r.data))
      .catch((v) => setError(normalizeError(v)));
  useEffect(() => {
    void load();
  }, []);
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Reorder Planning"
        description="Review products below their reorder level. This page never creates purchases automatically."
      />
      <form
        className="rounded-2xl border bg-white p-4 grid gap-4 sm:grid-cols-3"
        onSubmit={(e) => {
          e.preventDefault();
          void load();
        }}
      >
        <EntitySelect
          label="Branch"
          value={branch}
          onChange={setBranch}
          options={branches}
          allLabel="All branches"
        />
        <RemoteEntitySelect label="Product" value={product} onChange={setProduct} load={productEntityLoader} placeholder="Search product name or barcode" />
        <div className="self-end flex gap-2">
          <button className="report-action primary">
            {t("Apply Filters")}
          </button>
          <button
            type="button"
            className="report-action"
            onClick={() => {
              setBranch("");
              setProduct("");
            }}
          >
            {t("Clear All")}
          </button>
        </div>
      </form>
      <ErrorNotice error={error} />
      <RecordTable
        rows={rows.map((r) => ({
          ...r,
          product: r.product?.name,
          last_supplier: r.last_supplier?.name,
          branch: r.branch?.name,
        }))}
        emptyText="No products need reordering for the selected branch."
      />
    </div>
  );
}
