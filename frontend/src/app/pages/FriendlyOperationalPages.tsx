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
import { partyEntityLoader, productEntityLoader } from "../entityLoaders";
import {
  ErrorNotice,
  MetricGrid,
  PrintDocumentButton,
  RecordTable,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";
function useUnits() {
  const [rows, setRows] = useState<{ id: number; label: string }[]>([]);
  useEffect(() => {
    erpApi.units().then((r) =>
      setRows(
        r.data.map((x) => ({
          id: Number(x.id),
          label: `${x.name}${x.symbol ? ` (${x.symbol})` : ""}`,
        })),
      ),
    );
  }, []);
  return rows;
}
export function FriendlyOpeningBalancesPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    units = useUnits(),
    [f, setF] = useState<JsonRecord>({
      effective_date: new Date().toISOString().slice(0, 10),
      branch_id: "",
      type: "inventory",
      product_id: "",
      party_id: "",
      batch_number: "",
      expiry_date: "",
      quantity: "",
      unit_id: "",
      unit_cost: "",
      sale_price: "",
      amount: "",
    }),
    [session, setSession] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null);
  const inventory = f.type === "inventory";
  async function create(e: React.FormEvent) {
    e.preventDefault();
    const line = {
      type: f.type,
      product_id: inventory ? Number(f.product_id) : null,
      customer_id: f.type === "customer_ar" ? Number(f.party_id) : null,
      provider_id: f.type === "supplier_ap" ? Number(f.party_id) : null,
      batch_number: inventory ? f.batch_number : null,
      expiry_date: inventory ? f.expiry_date || null : null,
      quantity: inventory ? Number(f.quantity) : null,
      unit_id: inventory && f.unit_id ? Number(f.unit_id) : null,
      unit_cost: inventory ? Number(f.unit_cost) : null,
      sale_price: inventory ? Number(f.sale_price) : null,
      amount: !inventory ? Number(f.amount) : null,
    };
    try {
      setSession(
        (
          await erpApi.openingCreate({
            branch_id: f.branch_id ? Number(f.branch_id) : null,
            effective_date: f.effective_date,
            lines: [line],
          })
        ).data,
      );
    } catch (v) {
      setError(
        normalizeError(
          v,
          t(
            "The opening balance draft could not be created. Review the required setup values.",
          ),
        ),
      );
    }
  }
  async function action(kind: "validate" | "post") {
    if (!session) return;
    if (kind === "post" && !confirm(t("postOpeningBalanceQuestion"))) return;
    try {
      setSession(
        (
          await (kind === "validate"
            ? erpApi.openingValidate(session.id)
            : erpApi.openingPost(session.id))
        ).data,
      );
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Opening Balances"
        description="Use Opening Balances only when bringing existing stock or financial balances into the system during setup or migration."
      />
      <div className="rounded-xl bg-blue-50 p-4 text-sm text-blue-900">
        <strong>{t("Workflow:")}</strong> {t("openingBalanceWorkflow")}
      </div>
      <ErrorNotice error={error} />
      {session ? (
        <section className="space-y-4 rounded-2xl border bg-white p-5">
          <div className="flex justify-between">
            <h2 className="font-bold">{t("Opening Balance Review")}</h2>
            <StatusBadge value={session.status} />
          </div>
          <MetricGrid
            data={session}
            keys={["status", "effective_date", "branch_name", "total_lines"]}
          />
          <RecordTable
            rows={session.lines || []}
            emptyText="No opening balance lines were returned for review."
          />
          <div className="flex flex-wrap gap-2">
            {session.status === "draft" && (
              <button
                className="report-action primary"
                onClick={() => void action("validate")}
              >
                {t("Validate Draft")}
              </button>
            )}
            {session.status === "validated" && (
              <button
                className="report-action primary"
                onClick={() => void action("post")}
              >
                {t("Post Opening Balance")}
              </button>
            )}
            <PrintDocumentButton
              type="opening_balance"
              id={Number(session.id)}
            />
          </div>
        </section>
      ) : (
        <form className="space-y-5" onSubmit={create}>
          <FormSection title="Opening balance setup">
            <Field
              required
              label="Effective Date"
              help="The date these starting balances become effective in the ERP."
            >
              <input
                required
                className="input"
                type="date"
                value={String(f.effective_date)}
                onChange={(e) => setF({ ...f, effective_date: e.target.value })}
              />
            </Field>
            <EntitySelect
              label="Branch"
              value={String(f.branch_id)}
              onChange={(v) => setF({ ...f, branch_id: v })}
              options={branches}
              allLabel="Company-wide / no branch"
            />
            <Field required label="Balance Type">
              <select
                className="input"
                value={String(f.type)}
                onChange={(e) =>
                  setF({ ...f, type: e.target.value, party_id: "" })
                }
              >
                <option value="inventory">{t("Existing Stock")}</option>
                <option value="customer_ar">{t("Customer Amount Owed")}</option>
                <option value="supplier_ap">{t("Supplier Amount Owed")}</option>
                <option value="cash">{t("Cash Balance")}</option>
                <option value="bank">{t("Bank Balance")}</option>
                <option value="incoming_cheque">{t("Incoming Cheque")}</option>
                <option value="outgoing_cheque">{t("Outgoing Cheque")}</option>
              </select>
            </Field>
          </FormSection>
          {inventory ? (
            <FormSection title="Existing stock">
              <RemoteEntitySelect
                required
                label="Product"
                value={String(f.product_id)}
                onChange={(v) => setF({ ...f, product_id: v })}
                load={productEntityLoader}
                placeholder={t("Search for a product...")}
              />
              <Field required label="Batch / Lot">
                <input
                  required
                  className="input"
                  value={String(f.batch_number)}
                  onChange={(e) => setF({ ...f, batch_number: e.target.value })}
                />
              </Field>
              <Field label="Expiry Date">
                <input
                  className="input"
                  type="date"
                  value={String(f.expiry_date)}
                  onChange={(e) => setF({ ...f, expiry_date: e.target.value })}
                />
              </Field>
              <Field required label="Quantity">
                <input
                  required
                  className="input"
                  type="number"
                  step="any"
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
              <Field required label="Unit Cost">
                <input
                  required
                  className="input"
                  type="number"
                  step=".01"
                  value={String(f.unit_cost)}
                  onChange={(e) => setF({ ...f, unit_cost: e.target.value })}
                />
              </Field>
              <Field required label="Sale Price">
                <input
                  required
                  className="input"
                  type="number"
                  step=".01"
                  value={String(f.sale_price)}
                  onChange={(e) => setF({ ...f, sale_price: e.target.value })}
                />
              </Field>
            </FormSection>
          ) : (
            <FormSection title="Financial balance">
              {f.type === "customer_ar" ? (
                <RemoteEntitySelect
                  required
                  label="Customer"
                  value={String(f.party_id)}
                  onChange={(v) => setF({ ...f, party_id: v })}
                  load={partyEntityLoader("customer")}
                  placeholder={t("Search for a customer...")}
                />
              ) : f.type === "supplier_ap" ? (
                <RemoteEntitySelect
                  required
                  label="Supplier"
                  value={String(f.party_id)}
                  onChange={(v) => setF({ ...f, party_id: v })}
                  load={partyEntityLoader("supplier")}
                  placeholder={t("Search for a supplier...")}
                />
              ) : null}
              <Field required label="Opening Amount">
                <input
                  required
                  className="input"
                  type="number"
                  step=".01"
                  value={String(f.amount)}
                  onChange={(e) => setF({ ...f, amount: e.target.value })}
                />
              </Field>
            </FormSection>
          )}
          <button className="report-action primary">
            {t("Create Opening Balance Draft")}
          </button>
        </form>
      )}
    </div>
  );
}
export function FriendlyCashierShiftsPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions({ loadBatches: false, loadProducts: false, loadCustomers: false, loadSuppliers: false, loadUsers: false }),
    [branch, setBranch] = useState(""),
    [opening, setOpening] = useState(""),
    [counted, setCounted] = useState(""),
    [notes, setNotes] = useState(""),
    [current, setCurrent] = useState<JsonRecord | null>(null),
    [history, setHistory] = useState<JsonRecord[]>([]),
    [error, setError] = useState<ApiClientError | null>(null);
  const load = () =>
    Promise.all([erpApi.currentShift(branch), erpApi.shifts({ per_page: 25 })])
      .then(([a, b]) => {
        setCurrent(a.data);
        setHistory(b.data);
      })
      .catch((v) => setError(normalizeError(v)));
  useEffect(() => {
    void load();
  }, []);
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title="Cashier Shifts"
        description="Open a cash drawer, monitor cash movements, and compare expected cash with the amount counted at closing."
      />
      <ErrorNotice error={error} />
      {current ? (
        <section className="rounded-2xl border bg-white p-5 space-y-4">
          <div className="flex justify-between">
            <h2 className="font-bold">{t("Current Shift")}</h2>
            <StatusBadge value={current.status} />
          </div>
          <MetricGrid
            data={current}
            keys={[
              "opening_cash",
              "cash_in",
              "cash_out",
              "expected_cash",
              "counted_cash",
              "difference",
            ]}
          />
          <p className="text-sm text-gray-600">
            <strong>{t("Expected Cash")}</strong> {t("expectedCashExplanation")}
          </p>
          <RecordTable
            rows={current.movements || []}
            emptyText="No cash movements have been recorded during this shift."
          />
          {current.status !== "closed" && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field required label="Counted Cash">
                <input
                  required
                  className="input"
                  type="number"
                  step=".01"
                  value={counted}
                  onChange={(e) => setCounted(e.target.value)}
                />
              </Field>
              <Field label="Closing Notes">
                <textarea
                  className="input"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </Field>
              <button
                className="report-action primary"
                onClick={async () => {
                  if (
                    !confirm(
                      t(
                        "Close Cashier Shift?\n\nThe system will compare counted cash with expected cash and record any difference.",
                      ),
                    )
                  )
                    return;
                  try {
                    setCurrent(
                      (
                        await erpApi.closeShift(current.id, {
                          counted_cash: Number(counted),
                          notes,
                        })
                      ).data,
                    );
                  } catch (v) {
                    setError(normalizeError(v));
                  }
                }}
              >
                {t("Close Shift")}
              </button>
            </div>
          )}{" "}
          {current.status === "closed" && (
            <PrintDocumentButton type="cashier_shift" id={Number(current.id)} />
          )}
        </section>
      ) : (
        <form
          className="space-y-5"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              setCurrent(
                (
                  await erpApi.openShift({
                    branch_id: Number(branch),
                    opening_cash: Number(opening),
                  })
                ).data,
              );
            } catch (v) {
              setError(normalizeError(v));
            }
          }}
        >
          <FormSection title="Open cash drawer">
            <EntitySelect
              required
              label="Branch"
              value={branch}
              onChange={setBranch}
              options={branches}
            />
            <Field
              required
              label="Opening Cash"
              help="The physical cash present in the drawer before the cashier starts work."
            >
              <input
                required
                className="input"
                type="number"
                step=".01"
                value={opening}
                onChange={(e) => setOpening(e.target.value)}
              />
            </Field>
          </FormSection>
          <button className="report-action primary">{t("Open Shift")}</button>
        </form>
      )}
      <section className="rounded-2xl border bg-white overflow-hidden">
        <h2 className="p-4 font-bold border-b">{t("Shift History")}</h2>
        <RecordTable
          rows={history}
          columns={["cashier_name", "branch_id", "status", "opening_cash", "expected_cash", "counted_cash", "difference", "opened_at", "closed_at", "created_at", "updated_at"]}
          emptyText="No cashier shifts have been recorded yet."
        />
      </section>
    </div>
  );
}
