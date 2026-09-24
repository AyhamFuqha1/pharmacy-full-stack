import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useParams } from "react-router";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { lookupsApi } from "../../api/lookups";
import { ApiClientError } from "../../api/client";
import { EntitySelect, Field, FormSection, PageHeader, RemoteEntitySelect } from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { AttachmentsSection, ErrorNotice, MetricGrid, PrintDocumentButton, RecordTable, StatusBadge, normalizeError } from "../components/ErpPrimitives";

const accountLoader = (type: "expense" | "revenue") => async (q: string, page: number, signal: AbortSignal) => {
  const response = await lookupsApi.accounts({ q, type, page, per_page: 25 }, signal);
  return { options: response.data.map((account) => ({ id: account.id, label: `${account.code} — ${account.name}` })), hasMore: page < (response.meta.last_page || 1) };
};

export function FriendlyExpensesPage() { return <Money kind="expense" />; }
export function FriendlyOtherIncomePage() { return <Money kind="income" />; }

function Money({ kind }: { kind: "expense" | "income" }) {
  const { t } = useTranslation(), id = Number(useParams().id || 0), { branches } = useEntityOptions();
  const [rows, setRows] = useState<JsonRecord[]>([]), [detail, setDetail] = useState<JsonRecord | null>(null);
  const [form, setForm] = useState<JsonRecord>({ date: new Date().toISOString().slice(0, 10), branch_id: "", account_id: "", amount: "", payment_method: "cash", description: "", reference: "" });
  const [error, setError] = useState<ApiClientError | null>(null), isExpense = kind === "expense";
  const load = () => id
    ? (isExpense ? erpApi.expense(id) : erpApi.income(id)).then((r) => setDetail(r.data)).catch((v) => setError(normalizeError(v)))
    : (isExpense ? erpApi.expenses({ per_page: 25 }) : erpApi.incomes({ per_page: 25 })).then((r) => setRows(r.data)).catch((v) => setError(normalizeError(v)));
  useEffect(() => { void load(); }, [id]);

  if (detail) {
    const documentType = isExpense ? t("Expense") : t("Other Income");
    return <div className="p-4 md:p-8 space-y-5">
      <PageHeader title={`${documentType} ${detail.document_number || ""}`} description={t("Review the posted accounting document and its operational reference.")} />
      <ErrorNotice error={error} />
      <MetricGrid data={detail} keys={Object.keys(detail).filter((key) => typeof detail[key] !== "object")} />
      <div className="flex gap-2">
        <button className="report-action text-red-600" disabled={detail.status === "cancelled"} onClick={async () => {
          if (!confirm(t("cancelFinanceDocument", { documentType }))) return;
          try {
            if (isExpense) await erpApi.cancelExpense(detail.id);
            else { const reason = prompt(t("Enter the cancellation reason")); if (!reason) return; await erpApi.cancelIncome(detail.id, reason); }
            await load();
          } catch (value) { setError(normalizeError(value)); }
        }}>{t("cancelFinanceAction", { documentType })}</button>
        <PrintDocumentButton type={isExpense ? "expense" : "other_income"} id={Number(detail.id)} />
      </div>
      {isExpense && <AttachmentsSection type="expense" id={Number(detail.id)} />}
    </div>;
  }

  async function create(event: React.FormEvent) {
    event.preventDefault();
    try {
      const payload = isExpense
        ? { expense_date: form.date, branch_id: form.branch_id ? Number(form.branch_id) : null, expense_account_id: Number(form.account_id), amount: Number(form.amount), payment_type: form.payment_method, description: form.description, external_reference: form.reference }
        : { date: form.date, branch_id: form.branch_id ? Number(form.branch_id) : null, account_id: Number(form.account_id), amount: Number(form.amount), payment_method: form.payment_method, description: form.description, reference: form.reference };
      await (isExpense ? erpApi.createExpense(payload) : erpApi.createIncome(payload));
      setForm({ ...form, amount: "", description: "", reference: "" }); await load();
    } catch (value) {
      setError(normalizeError(value, t(isExpense ? "The expense could not be recorded. Review the fields and try again." : "The income could not be recorded. Review the fields and try again.")));
    }
  }

  return <div className="p-4 md:p-8 space-y-5">
    <PageHeader title={t(isExpense ? "Expenses" : "Other Income")} description={t(isExpense ? "Record pharmacy operating costs and their accounting effect." : "Record income that did not come from a normal product sale.")} />
    <ErrorNotice error={error} />
    <form className="space-y-5" onSubmit={create}>
      <FormSection title={t(isExpense ? "Expense details" : "Income details")}>
        <Field required label={t("Date")}><input required className="input" type="date" value={String(form.date)} onChange={(e) => setForm({ ...form, date: e.target.value })} /></Field>
        <EntitySelect label={t("Branch")} value={String(form.branch_id)} onChange={(v) => setForm({ ...form, branch_id: v })} options={branches} allLabel={t("Company-wide / no branch")} />
        <RemoteEntitySelect required label={t(isExpense ? "Expense Category / Account" : "Income Category / Account")} help={t(isExpense ? "Choose the accounting category that best describes this expense." : "Choose the revenue category that best describes this income.")} value={String(form.account_id)} onChange={(v) => setForm({ ...form, account_id: v })} load={accountLoader(isExpense ? "expense" : "revenue")} placeholder={t("Search account code or name")} />
        <Field required label={t("Amount")}><input required className="input" type="number" min=".01" step=".01" value={String(form.amount)} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
        <Field label={t("Payment Method")}><select className="input" value={String(form.payment_method)} onChange={(e) => setForm({ ...form, payment_method: e.target.value })}><option value="cash">{t("Cash")}</option><option value="bank">{t("Bank")}</option></select></Field>
        <Field required label={t("Description")}><textarea required className="input" value={String(form.description)} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
        <Field label={t("External Reference")}><input className="input" value={String(form.reference)} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></Field>
      </FormSection>
      <button className="report-action primary">{t(isExpense ? "Record Expense" : "Record Other Income")}</button>
    </form>
    <RecordTable rows={rows} emptyText={t(isExpense ? "No expenses have been recorded yet." : "No other income has been recorded yet.")} />
  </div>;
}

export function FriendlyBankReconciliationPage() {
  const { t } = useTranslation();
  const [filters, setFilters] = useState<JsonRecord>({ account_id: "", from: "", to: "", reconciled: "" }), [rows, setRows] = useState<JsonRecord[]>([]), [error, setError] = useState<ApiClientError | null>(null);
  const load = () => erpApi.bankMovements(filters).then((r) => setRows(r.data)).catch((v) => setError(normalizeError(v, t("Bank movements could not be loaded. Check your access or connection."))));
  const bankLoader = async (q: string, page: number, signal: AbortSignal) => { const r = await lookupsApi.bankAccounts({ q, page, per_page: 25 }, signal); return { options: r.data.map((x) => ({ id: x.id, label: `${x.code} — ${x.display_name}` })), hasMore: page < (r.meta.last_page || 1) }; };
  async function toggle(row: JsonRecord) {
    try {
      if (row.reconciled) { const reason = prompt(t("Why should this movement be marked unreconciled?")); if (!reason) return; await erpApi.unreconcile(row.journal_entry_line_id, reason); }
      else { const reference = prompt(t("External bank reference (optional)")) || "", note = prompt(t("Reconciliation note (optional)")) || ""; await erpApi.reconcile(row.journal_entry_line_id, { external_reference: reference, note }); }
      await load();
    } catch (value) { setError(normalizeError(value)); }
  }
  return <div className="p-4 md:p-8 space-y-5">
    <PageHeader title={t("Bank Reconciliation")} description={t("Match ERP bank movements with the external bank statement without changing accounting amounts.")} />
    <div className="p-4 rounded-xl bg-blue-50 text-blue-900 text-sm"><strong>{t("Reconciled")}</strong>{" "}{t("means the ERP movement has been matched to the bank statement.")}</div>
    <form className="rounded-2xl border bg-white p-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void load(); }}>
      <RemoteEntitySelect label={t("Bank Account")} help={t("Choose the ERP bank account you want to reconcile with the external bank statement.")} value={String(filters.account_id)} onChange={(v) => setFilters({ ...filters, account_id: v })} load={bankLoader} placeholder={t("Search bank account code or name")} />
      <Field label={t("From Date")}><input className="input" type="date" value={String(filters.from)} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>
      <Field label={t("To Date")}><input className="input" type="date" value={String(filters.to)} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>
      <Field label={t("Reconciliation Status")}><select className="input" value={String(filters.reconciled)} onChange={(e) => setFilters({ ...filters, reconciled: e.target.value })}><option value="">{t("All movements")}</option><option value="1">{t("Reconciled")}</option><option value="0">{t("Unreconciled")}</option></select></Field>
      <button className="report-action primary">{t("Apply Filters")}</button>
    </form>
    <ErrorNotice error={error} />
    <div className="bg-white border rounded-2xl overflow-x-auto">{rows.length ? <table className="w-full min-w-[800px]"><thead><tr><th>{t("Date")}</th><th>{t("Entry")}</th><th>{t("Source")}</th><th>{t("Amount")}</th><th>{t("Status")}</th><th>{t("External Reference")}</th><th>{t("Action")}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.journal_entry_line_id}><td><bdi dir="ltr">{row.entry_date}</bdi></td><td><bdi dir="ltr">{row.entry_number}</bdi></td><td>{row.source_label}</td><td><bdi dir="ltr">{row.amount}</bdi></td><td><StatusBadge value={row.reconciled ? "reconciled" : "unreconciled"} /></td><td><bdi dir="ltr">{row.external_reference || t("Not provided")}</bdi></td><td><button className="report-action" onClick={() => void toggle(row)}>{t(row.reconciled ? "Mark Unreconciled" : "Mark Reconciled")}</button></td></tr>)}</tbody></table> : <div className="p-10 text-center text-gray-500">{t("Choose a bank account and apply filters to review its movements.")}</div>}</div>
  </div>;
}
