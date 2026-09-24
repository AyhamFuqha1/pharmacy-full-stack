import { useEffect, useRef, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import {
  ErrorNotice,
  MetricGrid,
  RecordTable,
  StatusBadge,
  displayValue,
  human,
  normalizeError,
  valueText,
} from "../components/ErpPrimitives";
import i18n, { applyDocumentLocale, type AppLocale } from "../../i18n";
import { PrintIdentityHeader } from "../components/PrintIdentityHeader";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";

export function ImportsPage() {
  const [domain, setDomain] = useState("products"),
    [file, setFile] = useState<File | null>(null),
    [result, setResult] = useState<JsonRecord | null>(null),
    [error, setError] = useState<ApiClientError | null>(null),
    [busy, setBusy] = useState(false);
  async function preview(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    const form = new FormData();
    form.append("domain", domain);
    form.append("file", file);
    setBusy(true);
    try {
      setResult((await erpApi.importPreview(form)).data);
    } catch (v) {
      setError(normalizeError(v));
    } finally {
      setBusy(false);
    }
  }
  const job = result?.job;
  return (
    <div className="p-4 md:p-8 space-y-5">
      <div>
        <h1 className="text-3xl font-bold">Data Import</h1>
        <p className="text-gray-500">Validated CSV preview and confirmation</p>
      </div>
      <ErrorNotice error={error} />
      <form className="card-form" onSubmit={preview}>
        <select
          className="input"
          value={domain}
          onChange={(e) => setDomain(e.target.value)}
        >
          <option value="products">Products</option>
          <option value="customers">Customers</option>
          <option value="suppliers">Suppliers</option>
          <option value="opening_stock">Opening Stock</option>
        </select>
        <input
          className="input"
          type="file"
          accept=".csv,text/csv"
          onChange={(e) => setFile(e.target.files?.[0] || null)}
          required
        />
        <button className="report-action primary" disabled={busy}>
          {busy ? "Parsing…" : "Preview CSV"}
        </button>
      </form>
      {domain === "opening_stock" && (
        <div className="p-4 bg-blue-50 text-blue-800 rounded-xl">
          Opening Stock imports create formal Opening Balance data; they do not
          create purchases.
        </div>
      )}
      {job && (
        <>
          <MetricGrid
            data={job}
            keys={[
              "status",
              "total_rows",
              "valid_rows",
              "failed_rows",
              "error_summary",
            ]}
          />
          {result.errors?.length > 0 && (
            <section className="bg-red-50 border rounded-2xl overflow-hidden">
              <h2 className="p-4 font-bold">Row Errors</h2>
              <RecordTable rows={result.errors} />
            </section>
          )}
          <section className="bg-white border rounded-2xl overflow-hidden">
            <h2 className="p-4 font-bold">Preview</h2>
            <RecordTable rows={result.preview || []} />
          </section>
          <div className="flex gap-2">
            <a
              className="report-action"
              href={`${(import.meta.env.VITE_API_BASE_URL || "/api").replace(/\/$/, "")}/imports/${job.id}/source`}
            >
              Download Source
            </a>
            <button
              className="report-action primary"
              disabled={job.failed_rows > 0 || job.status !== "previewed"}
              onClick={async () => {
                if (!confirm("Confirm and execute this import?")) return;
                try {
                  const extra =
                    domain === "opening_stock"
                      ? {
                          effective_date: new Date().toISOString().slice(0, 10),
                          branch_id: Number(prompt("Branch ID")),
                        }
                      : {};
                  const r = await erpApi.importConfirm(job.id, extra);
                  setResult({ ...result, job: r.data });
                } catch (v) {
                  setError(normalizeError(v));
                }
              }}
            >
              Confirm Import
            </button>
          </div>
        </>
      )}
    </div>
  );
}

export function BackupPage() {
  const requestInFlight = useRef(false);
  const { t } = useTranslation(),
    [status, setStatus] = useState<JsonRecord | null>(null),
    [rows, setRows] = useState<JsonRecord[]>([]),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null);
  const load = () => Promise.all([erpApi.backupStatus(), erpApi.backups()]).then(([a, b]) => { setStatus(a.data); setRows(b.data); }).catch((v) => setError(normalizeError(v)));
  useEffect(() => { void load(); }, []);
  const run = async (action: () => Promise<unknown>) => {
    if (requestInFlight.current) return;
    requestInFlight.current = true;
    setBusy(true);
    setError(null);
    try { await action(); await load(); }
    catch (value) { setError(normalizeError(value)); }
    finally { requestInFlight.current = false; setBusy(false); }
  };
  const download = async (id: number) => { try { const { blob, filename } = await erpApi.downloadBackup(id); const url = URL.createObjectURL(blob); const link = document.createElement("a"); link.href = url; link.download = filename; link.click(); URL.revokeObjectURL(url); } catch (value) { setError(normalizeError(value)); } };
  const restore = async (id: number) => { if (!window.confirm(t("Restore validation does not overwrite active data. Continue?"))) return; const confirmation = window.prompt(t("Type RESTORE to prepare the recovery workflow.")); if (confirmation !== "RESTORE") return; await run(() => erpApi.prepareRestore(id, confirmation)); };
  return (
    <div className="p-4 md:p-8 space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-3xl font-bold">{t("Backup and Restore")}</h1><p className="text-gray-600">{t("Private database and file recovery packages")}</p></div><button className="report-action primary" disabled={busy || status?.available === false} onClick={() => void run(() => erpApi.createBackup())}>{t("Create Backup")}</button></div>
      <ErrorNotice error={error} />
      {status && <section className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">{[["Backup Status", status.available ? "ready" : "unavailable"], ["Destination", status.destination], ["Retention", status.retention_count], ["Schedule", status.schedule]].map(([label, value]) => <div className="bg-white border rounded-2xl p-4" key={String(label)}><p className="text-sm text-gray-500">{t(String(label))}</p><p className="font-bold mt-1"><bdi>{displayValue(value)}</bdi></p></div>)}</section>}
      <section className="bg-white border rounded-2xl overflow-x-auto"><table className="w-full"><thead><tr><th>{t("Creation Date")}</th><th>{t("Status")}</th><th>{t("Size")}</th><th>{t("Included Components")}</th><th>{t("Integrity")}</th><th>{t("Actions")}</th></tr></thead><tbody>{rows.length ? rows.map((row) => <tr key={row.id}><td><bdi dir="ltr">{displayValue(row.created_at)}</bdi></td><td><StatusBadge value={row.status} /></td><td><bdi dir="ltr">{row.size ? `${(Number(row.size) / 1048576).toFixed(2)} MB` : "—"}</bdi></td><td>{Array.isArray(row.included_components) ? row.included_components.map(human).join(", ") : "—"}</td><td>{row.checksum ? t("Checksum recorded") : "—"}</td><td><div className="flex flex-wrap gap-2">{row.status === "completed" && <><button className="report-action" onClick={() => void download(Number(row.id))}>{t("Download")}</button><button className="report-action" disabled={busy} onClick={() => void run(() => erpApi.validateBackup(Number(row.id)))}>{t("Validate Integrity")}</button><button className="report-action text-red-700 border-red-300" disabled={busy} onClick={() => void restore(Number(row.id))}>{t("Restore")}</button></>}</div></td></tr>) : <tr><td colSpan={6}>{t("No backups available")}</td></tr>}</tbody></table></section>
    </div>
  );
}

function rowsForPrint(value: unknown): JsonRecord[] {
  return (Array.isArray(value) ? value : [value])
    .filter((item) => item !== null && item !== undefined)
    .map((item) => {
      if (typeof item !== "object" || Array.isArray(item))
        return { value: displayValue(item) };
      return Object.fromEntries(
        Object.entries(item as JsonRecord).map(([key, cell]) => [
          key,
          typeof cell === "object" && cell !== null ? displayValue(cell) : cell,
        ]),
      );
    });
}

export function PrintableDocumentPage() {
  const { t } = useTranslation();
  const [data] = useState<JsonRecord | null>(() => {
    try {
      return JSON.parse(sessionStorage.getItem("erp-print-document") || "null");
    } catch {
      return null;
    }
  });
  const locale: AppLocale = data?.locale === "ar" ? "ar" : "en";
  useEffect(() => {
    if (!data) return;
    let timer = 0;
    void i18n.changeLanguage(locale).then(() => {
      applyDocumentLocale(locale);
      timer = window.setTimeout(() => window.print(), 300);
    });
    return () => window.clearTimeout(timer);
  }, [data, locale]);
  if (!data)
    return (
      <div className="p-8">{t("No printable document was prepared.")}</div>
    );
  const meta = data.meta && typeof data.meta === "object" ? data.meta : {},
    content =
      data.content && typeof data.content === "object" ? data.content : {},
    sections = Array.isArray(content)
      ? content.map((value, i) => ({
          title: `Section ${i + 1}`,
          rows: rowsForPrint(value),
        }))
      : Object.entries(content).map(([title, value]) => ({
          title,
          rows: rowsForPrint(value),
        }));
  const orientation = displayValue(
    meta.suggested_orientation || data.suggested_orientation || "portrait",
  );
  if (meta.title === "Sales Invoice")
    return (
      <SaleInvoicePrint
        meta={meta as JsonRecord}
        content={content as JsonRecord}
      />
    );
  if (meta.title === "Purchase Invoice")
    return (
      <PurchaseInvoicePrint
        meta={meta as JsonRecord}
        content={content as JsonRecord}
      />
    );
  if (meta.title === "Sales Return")
    return (
      <SalesReturnPrint
        meta={meta as JsonRecord}
        content={content as JsonRecord}
      />
    );
  if (meta.title === "Customer Statement")
    return <CustomerStatementPrint meta={meta as JsonRecord} content={content as JsonRecord} />;
  if (meta.title === "Supplier Statement")
    return <SupplierStatementPrint meta={meta as JsonRecord} content={content as JsonRecord} />;
  const transactionRenderers: Record<string, (props: { meta: JsonRecord; content: JsonRecord }) => ReactNode> = {
    "Purchase Return": PurchaseReturnPrint,
    "Customer Receipt": CustomerReceiptPrint,
    "Supplier Payment": SupplierPaymentPrint,
    Cheque: ChequePrint,
    Expense: ExpensePrint,
    "Other Income": OtherIncomePrint,
    "Stock Transfer": StockTransferPrint,
    "Stock Adjustment": StockAdjustmentPrint,
    "Stock Count": StockCountPrint,
    "Opening Balance": OpeningBalancePrint,
    "Cashier Shift Close": CashierShiftPrint,
  };
  const Renderer = transactionRenderers[String(meta.title)];
  if (Renderer) return <>{Renderer({ meta: meta as JsonRecord, content: content as JsonRecord })}</>;
  return <SafeReportDocumentPrint meta={meta as JsonRecord} content={content as JsonRecord} orientation={orientation} />;
}

function CustomerStatementPrint({ meta, content }: { meta: JsonRecord; content: JsonRecord }) {
  const { t, i18n: printI18n } = useTranslation();
  const identity = (meta.print_identity || {}) as JsonRecord,
    customer = (content.party || {}) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    creator = (meta.generated_by || {}) as JsonRecord,
    filters = (content.filters_used || {}) as JsonRecord,
    totals = (content.totals_summary || {}) as JsonRecord,
    rows = Array.isArray(content.rows_lines) ? content.rows_lines as JsonRecord[] : [];
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  const date = (value: unknown) => String(value || "").slice(0, 10) || "—";
  const dateTime = (value: unknown) => {
    const parsed = new Date(String(value || ""));
    if (Number.isNaN(parsed.getTime())) return "—";
    const pad = (number: number) => String(number).padStart(2, "0");
    return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`;
  };
  const money = (value: unknown) => <bdi dir="ltr" className="tabular-nums whitespace-nowrap">{formatMoney(Number(value || 0))}</bdi>;
  const filterEntries = [
    filters.from || filters.to ? [t("Period"), <bdi dir="ltr">{date(filters.from)} – {date(filters.to)}</bdi>] : null,
    branch.name || branch.code ? [t("Branch"), displayValue(branch.name || branch.code)] : null,
    filters.transaction_type ? [t("Transaction Type"), t(human(String(filters.transaction_type)))] : null,
  ].filter(Boolean) as Array<[string, ReactNode]>;
  return <main lang={locale} dir={locale === "ar" ? "rtl" : "ltr"} className="print-report print-portrait customer-statement-print" style={{ display: "block" }}>
    <div className="print:hidden mb-4 flex justify-end"><button type="button" className="report-action primary" onClick={() => window.print()}>{t("Print")}</button></div>
    <PrintIdentityHeader identity={identity} />
    <header className="customer-statement-meta">
      <div><h2>{t("Customer Statement")}</h2>{filterEntries.length > 0 && <div className="customer-statement-filters">{filterEntries.map(([label, value]) => <span key={label}><strong>{label}:</strong> {value}</span>)}</div>}</div>
      <dl>
        <div><dt>{t("Customer Name")}</dt><dd>{displayValue(customer.name)}</dd></div>
        {customer.phone_num && <div><dt>{t("Phone")}</dt><dd><bdi dir="ltr">{displayValue(customer.phone_num)}</bdi></dd></div>}
        <div><dt>{t("Print Date")}</dt><dd><bdi dir="ltr">{dateTime(meta.generated_at)}</bdi></dd></div>
        {creator.name && <div><dt>{t("Created By")}</dt><dd>{displayValue(creator.name)}</dd></div>}
      </dl>
    </header>
    <p className="customer-statement-opening"><strong>{t("Opening Balance")}:</strong> {money(content.opening_balance_stock)}</p>
    <section className="customer-statement-ledger">
      <table>
        <thead><tr><th>{t("Date")}</th><th>{t("Reference")}</th><th>{t("Description")}</th><th>{t("Debit")}</th><th>{t("Credit")}</th><th>{t("Running Balance")}</th></tr></thead>
        <tbody>{rows.length ? rows.map((row, index) => <tr key={index}><td><bdi dir="ltr">{date(row.entry_date)}</bdi></td><td><bdi dir="ltr">{displayValue(row.source_label)}</bdi></td><td>{displayValue(row.description)}</td><td>{money(row.debit)}</td><td>{money(row.credit)}</td><td className="font-semibold">{money(row.running_balance)}</td></tr>) : <tr><td colSpan={6}>{t("No data")}</td></tr>}</tbody>
      </table>
    </section>
    <footer className="customer-statement-summary">
      <div><span>{t("Opening Balance")}</span><strong>{money(totals.opening_balance ?? content.opening_balance_stock)}</strong></div>
      <div><span>{t("Total Debit")}</span><strong>{money(totals.total_debit)}</strong></div>
      <div><span>{t("Total Credit")}</span><strong>{money(totals.total_credit)}</strong></div>
      <div className="customer-statement-closing"><span>{t("Closing Balance")}</span><strong>{money(totals.closing_balance ?? content.closing_balance_stock)}</strong></div>
    </footer>
  </main>;
}

function SupplierStatementPrint({ meta, content }: { meta: JsonRecord; content: JsonRecord }) {
  const { t, i18n: printI18n } = useTranslation();
  const identity = (meta.print_identity || {}) as JsonRecord,
    supplier = (content.party || {}) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    creator = (meta.generated_by || {}) as JsonRecord,
    filters = (content.filters_used || {}) as JsonRecord,
    totals = (content.totals_summary || {}) as JsonRecord,
    rows = Array.isArray(content.rows_lines) ? content.rows_lines as JsonRecord[] : [];
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  const date = (value: unknown) => String(value || "").slice(0, 10) || "—";
  const dateTime = (value: unknown) => {
    const parsed = new Date(String(value || ""));
    if (Number.isNaN(parsed.getTime())) return "—";
    const pad = (number: number) => String(number).padStart(2, "0");
    return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`;
  };
  const money = (value: unknown) => <bdi dir="ltr" className="tabular-nums whitespace-nowrap">{formatMoney(Number(value || 0))}</bdi>;
  const description = (row: JsonRecord) => {
    const labels: Record<string, string> = {
      purchase_invoice: "Purchase invoice",
      payment: "Supplier payment",
      purchase_return: "Purchase return",
      cheque_clear: "Supplier cheque",
      cheque_bounce: "Cheque reversal / cancellation",
    };
    return t(labels[String(row.source_type)] || "Supplier balance adjustment");
  };
  const filterEntries = [
    filters.from || filters.to ? [t("Period"), <bdi dir="ltr">{date(filters.from)} – {date(filters.to)}</bdi>] : null,
    branch.name || branch.code ? [t("Branch"), displayValue(branch.name || branch.code)] : null,
    filters.transaction_type ? [t("Transaction Type"), t(human(String(filters.transaction_type)))] : null,
  ].filter(Boolean) as Array<[string, ReactNode]>;
  return <main lang={locale} dir={locale === "ar" ? "rtl" : "ltr"} className="print-report print-portrait supplier-statement-print" style={{ display: "block" }}>
    <div className="print:hidden mb-4 flex justify-end"><button type="button" className="report-action primary" onClick={() => window.print()}>{t("Print")}</button></div>
    <PrintIdentityHeader identity={identity} />
    <header className="supplier-statement-meta">
      <div><h2>{t("Supplier Statement")}</h2>{locale === "ar" && <p className="supplier-statement-subtitle">Supplier Statement</p>}{filterEntries.length > 0 && <div className="supplier-statement-filters">{filterEntries.map(([label, value]) => <span key={label}><strong>{label}:</strong> {value}</span>)}</div>}</div>
      <dl>
        {supplier.name && <div><dt>{t("Supplier Name")}</dt><dd>{displayValue(supplier.name)}</dd></div>}
        {supplier.phone_num && <div><dt>{t("Phone")}</dt><dd><bdi dir="ltr">{displayValue(supplier.phone_num)}</bdi></dd></div>}
        <div><dt>{t("Print Date")}</dt><dd><bdi dir="ltr">{dateTime(meta.generated_at)}</bdi></dd></div>
        {creator.name && <div><dt>{t("Created By")}</dt><dd>{displayValue(creator.name)}</dd></div>}
      </dl>
    </header>
    <p className="supplier-statement-opening"><strong>{t("Opening Balance")}:</strong> {money(content.opening_balance_stock)}</p>
    <section className="supplier-statement-ledger">
      <table>
        <thead><tr><th>{t("Date")}</th><th>{t("Reference")}</th><th>{t("Description")}</th><th>{t("Increases Supplier Balance")}</th><th>{t("Reduces Supplier Balance")}</th><th>{t("Balance After Transaction")}</th></tr></thead>
        <tbody>{rows.length ? rows.map((row, index) => <tr key={index}><td><bdi dir="ltr">{date(row.entry_date)}</bdi></td><td><bdi dir="ltr">{displayValue(row.source_label)}</bdi></td><td>{description(row)}</td><td>{money(row.credit)}</td><td>{money(row.debit)}</td><td className="font-semibold">{money(row.running_balance)}</td></tr>) : <tr><td colSpan={6}>{t("No data")}</td></tr>}</tbody>
      </table>
    </section>
    <footer className="supplier-statement-summary">
      <div><span>{t("Opening Balance")}</span><strong>{money(totals.opening_balance ?? content.opening_balance_stock)}</strong></div>
      <div><span>{t("Total Amounts Added to Supplier Balance")}</span><strong>{money(totals.total_credit)}</strong></div>
      <div><span>{t("Total Payments, Returns and Reductions")}</span><strong>{money(totals.total_debit)}</strong></div>
      <div className="supplier-statement-closing"><span>{t("Current Amount Due to Supplier")}</span><strong>{money(totals.closing_balance ?? content.closing_balance_stock)}</strong></div>
    </footer>
  </main>;
}

type PrintField = [string, unknown, "money"?];
type PrintColumn = [string, string, "money"?];

function BusinessTransactionPrint({ meta, content, kind }: { meta: JsonRecord; content: JsonRecord; kind: string }) {
  const { t, i18n: printI18n } = useTranslation();
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en",
    identity = (meta.print_identity || {}) as JsonRecord,
    entity = (content.entity || {}) as JsonRecord,
    partyContainer = (content.party || {}) as JsonRecord,
    party = (partyContainer.data || partyContainer) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    cheque = (entity.cheque || {}) as JsonRecord,
    suppliedRows = Array.isArray(content.rows_lines) ? content.rows_lines as JsonRecord[] : [];
  const money = (value: unknown) => <bdi dir="ltr" className="tabular-nums whitespace-nowrap">{formatMoney(Number(value || 0))}</bdi>;
  const plain = (value: unknown) => <bdi>{displayValue(value)}</bdi>;
  const date = (value: unknown) => <bdi dir="ltr">{String(value || "").slice(0, 10) || "—"}</bdi>;
  const dateTime = (value: unknown) => <bdi dir="ltr">{String(value || "").replace("T", " ").slice(0, 16) || "—"}</bdi>;
  let fields: PrintField[] = [], columns: PrintColumn[] = [], rows = suppliedRows, summary: PrintField[] = [];
  const common: PrintField[] = [
    ["Branch", branch.name || branch.code],
    ["Status", entity.status],
    ["Created By", entity.created_by_name || (meta.generated_by as JsonRecord)?.name],
  ];
  switch (kind) {
    case "purchase_return":
      fields = [["Original Purchase", entity.original_purchase_reference], ["Supplier", party.name], ["Return Date", entity.return_date || entity.created_at], ...common];
      rows = [entity]; columns = [["Product", "product_name"], ["Batch", "batch_number"], ["Returned Quantity", "quantity"], ["Historical Unit Cost", "cost_price", "money"], ["Supplier Credit Unit Amount", "return_price", "money"], ["Supplier Credit", "supplier_credit_total", "money"]];
      summary = [["Historical Inventory Value Removed", entity.historical_inventory_value_removed || entity.line_total_return, "money"], ["Supplier Credit", entity.supplier_credit_total || entity.line_total_return, "money"], ["Adjustment / Difference", entity.adjustment_amount, "money"]]; break;
    case "receipt": case "payment":
      fields = [[kind === "receipt" ? "Customer" : "Supplier", party.name], ["Date", entity.date], ["Amount", entity.amount, "money"], ["Payment Method", entity.payment_method], ...common, ["Notes", entity.notes], ["Cheque Number", cheque.check_number], ["Bank", cheque.bank_name], ["Cheque Due Date", cheque.due_date]]; break;
    case "cheque":
      fields = [["Cheque Number", entity.check_number], ["Direction", entity.direction], [entity.direction === "in" ? "Customer" : "Supplier", party.name], ["Amount", entity.amount, "money"], ["Bank", entity.bank_name], ["Issue Date", entity.created_at], ["Due Date", entity.due_date], ["Status", entity.status], ["Related Document", entity.related_reference]]; break;
    case "expense": case "other_income":
      fields = [["Date", entity.date], [kind === "expense" ? "Expense Account" : "Income Account", entity.account_name], ["Amount", entity.amount, "money"], ["Payment Method", entity.payment_method], ...common, ["Description", entity.description], ["Reference", entity.external_reference || entity.reference], ["Notes", entity.notes]]; break;
    case "stock_transfer":
      fields = [["From Branch", entity.source_branch_name], ["To Branch", entity.destination_branch_name], ["Date", entity.sent_at || entity.created_at], ["Status", entity.status], ["Created By", entity.created_by_name], ["Notes", entity.notes]];
      columns = [["Product", "product_name"], ["Batch", "batch_number"], ["Quantity", "transaction_quantity"], ["Unit", "unit_name"]]; break;
    case "stock_adjustment":
      fields = [["Branch", branch.name || branch.code], ["Date", entity.posted_at], ["Product", entity.product_name], ["Batch", entity.batch_number], ["Adjustment Quantity", entity.entered_quantity || entity.quantity], ["Unit", entity.entered_unit], ["Reason", entity.reason], ["Notes", entity.note], ["Created By", entity.created_by_name]]; break;
    case "stock_count":
      fields = [["Branch", branch.name || branch.code], ["Count Date", entity.snapshot_at], ["Status", entity.status], ["Created By", entity.created_by_name], ["Notes", entity.notes]];
      columns = [["Product", "product_name"], ["Batch", "batch_number"], ["System Quantity", "system_quantity"], ["Counted Quantity", "counted_quantity"], ["Variance", "difference"]]; break;
    case "opening_balance":
      fields = [["Branch", branch.name || branch.code], ["Effective Date", entity.effective_date], ["Status", entity.status], ["Created By", entity.created_by_name], ["Notes", entity.notes]];
      columns = [["Type", "type"], ["Product", "product_name"], ["Party", "customer_name"], ["Supplier", "supplier_name"], ["Batch", "batch_number"], ["Quantity", "transaction_quantity"], ["Unit", "unit_name"], ["Amount", "amount", "money"]]; break;
    case "cashier_shift":
      fields = [["Cashier", entity.created_by_name], ["Branch", branch.name || branch.code], ["Opened At", entity.opened_at], ["Closed At", entity.closed_at], ["Opening Cash", entity.opening_cash, "money"], ["Expected Cash", entity.expected_cash, "money"], ["Counted Cash", entity.counted_cash, "money"], ["Difference", entity.difference, "money"], ["Notes", entity.notes]]; break;
  }
  fields = fields.filter(([, value]) => value !== null && value !== undefined && value !== "");
  summary = summary.filter(([, value]) => value !== null && value !== undefined && value !== "");
  return <main lang={locale} dir={locale === "ar" ? "rtl" : "ltr"} className="print-report print-portrait business-transaction-print" style={{ display: "block" }}>
    <div className="print:hidden mb-4 flex justify-end"><button type="button" className="report-action primary" onClick={() => window.print()}>{t("Print")}</button></div>
    <PrintIdentityHeader identity={identity} />
    <header className="business-print-header"><div><h2>{t(displayValue(meta.title))}</h2>{meta.document_number && <strong><bdi dir="ltr">{displayValue(meta.document_number)}</bdi></strong>}</div><dl>{fields.map(([label, value, format]) => <div key={label}><dt>{t(label)}</dt><dd>{format === "money" ? money(value) : / at$/i.test(label) ? dateTime(value) : /date/i.test(label) ? date(value) : plain(value)}</dd></div>)}</dl></header>
    {columns.length > 0 && <section className="business-print-table"><table><thead><tr>{columns.map(([label]) => <th key={label}>{t(label)}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, index) => <tr key={index}>{columns.map(([label, key, format]) => <td key={label}>{format === "money" ? money(row[key]) : plain(row[key])}</td>)}</tr>) : <tr><td colSpan={columns.length}>{t("No data")}</td></tr>}</tbody></table></section>}
    {summary.length > 0 && <footer className="business-print-summary">{summary.map(([label, value, format]) => <div key={label}><span>{t(label)}</span><strong>{format === "money" ? money(value) : plain(value)}</strong></div>)}</footer>}
  </main>;
}

const PurchaseReturnPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="purchase_return" />;
const CustomerReceiptPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="receipt" />;
const SupplierPaymentPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="payment" />;
const ChequePrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="cheque" />;
const ExpensePrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="expense" />;
const OtherIncomePrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="other_income" />;
const StockTransferPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="stock_transfer" />;
const StockAdjustmentPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="stock_adjustment" />;
const StockCountPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="stock_count" />;
const OpeningBalancePrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="opening_balance" />;
const CashierShiftPrint = (props: { meta: JsonRecord; content: JsonRecord }) => <BusinessTransactionPrint {...props} kind="cashier_shift" />;

function SafeReportDocumentPrint({ meta, content, orientation }: { meta: JsonRecord; content: JsonRecord; orientation: string }) {
  const { t, i18n: printI18n } = useTranslation();
  const identity = (meta.print_identity || {}) as JsonRecord;
  const sections = Object.entries(content).filter(([key, value]) => !["entity", "party", "source_references"].includes(key) && value !== null && value !== undefined && (!Array.isArray(value) || value.length > 0)).map(([title, value]) => ({ title, rows: rowsForPrint(value) }));
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  return <main lang={locale} dir={locale === "ar" ? "rtl" : "ltr"} className={`print-report print-${orientation} safe-report-print`} style={{ display: "block" }}><PrintIdentityHeader identity={identity} /><header className="business-print-header"><div><h2>{t(displayValue(meta.title || "Report"))}</h2></div></header>{sections.map((section) => <section className="business-print-table" key={section.title}><h3>{t(human(section.title))}</h3><RecordTable rows={section.rows} /></section>)}</main>;
}

function SaleInvoicePrint({
  meta,
  content,
}: {
  meta: JsonRecord;
  content: JsonRecord;
}) {
  const { t, i18n: printI18n } = useTranslation();
  const printIdentity = (meta.print_identity || {}) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    customer = (meta.customer || {}) as JsonRecord,
    cashier = (meta.generated_by || {}) as JsonRecord,
    totals = (content.totals || {}) as JsonRecord,
    items = Array.isArray(content.items) ? (content.items as JsonRecord[]) : [];
  const money = (value: unknown) => formatMoney(Number(value || 0));
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  return (
    <main
      lang={locale}
      dir={locale === "ar" ? "rtl" : "ltr"}
      className="print-report print-portrait sale-invoice-print"
      style={{ display: "block" }}
    >
      <div className="print:hidden mb-4 flex justify-end">
        <button
          type="button"
          className="report-action primary"
          onClick={() => window.print()}
        >
          {t("Print")}
        </button>
      </div>
      <PrintIdentityHeader identity={printIdentity} />
      <header className="print-header sale-invoice-meta">
        <div className="sale-invoice-meta-group">
          <strong>{t("Branch")}</strong>
          <p>{displayValue(branch.name || branch.code || "")}</p>
          <strong>{t("Customer")}</strong>
          <p>{displayValue(customer.name || t("Walk-in customer"))}</p>
        </div>
        <div className="print-title">
          <h2>{t("Sales Invoice")}</h2>
          <bdi dir="ltr">{displayValue(meta.document_number)}</bdi>
        </div>
        <div className="print-generated sale-invoice-meta-group">
          <strong>{t("Date")}</strong>
          <p><bdi dir="ltr">{displayValue(meta.date)}</bdi></p>
          <strong>{t("Cashier")}</strong>
          <p>{displayValue(cashier.name || "—")}</p>
        </div>
      </header>
      <section className="sale-invoice-items">
        <h2>{t("Items")}</h2>
        <div className="sale-invoice-table-wrap overflow-x-auto">
          <table className="sale-invoice-items-table w-full">
            <thead>
              <tr>
                {[
                  "Product",
                  "Quantity",
                  "Unit / Packaging",
                  "Batch",
                  "Unit Price",
                  "Discount",
                  "Line Total",
                ].map((label) => (
                  <th key={label}>{t(label)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => (
                <tr key={index}>
                  <td className="item-product">{displayValue(item.product)}</td>
                  <td className="item-number">
                    <bdi dir="ltr">{displayValue(item.quantity)}</bdi>
                  </td>
                  <td className="item-unit">
                    {displayValue(item.unit)}
                    {Number(item.packaging_quantity) !== Number(item.quantity)
                      ? ` (${displayValue(item.packaging_quantity)} ${t("base")})`
                      : ""}
                  </td>
                  <td className="item-batch">
                    <bdi dir="ltr">{displayValue(item.batch || "—")}</bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">{money(item.unit_price)}</bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">{money(item.discount)}</bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">{money(item.line_total)}</bdi>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <section className="print-summary">
        <h2>{t("Summary")}</h2>
        <table className="w-full">
          <tbody>
            {[
              ["Subtotal", totals.subtotal],
              ["Line Discounts", totals.line_discounts],
              ["Invoice Discount", totals.invoice_discount],
              ["Total", totals.total],
              ["Paid", totals.paid],
              ["Balance Due", totals.balance_due],
            ].map(([label, value]) => (
              <tr
                key={String(label)}
                className={
                  label === "Total" || label === "Balance Due"
                    ? "summary-emphasis"
                    : undefined
                }
              >
                <th>{t(String(label))}</th>
                <td>
                  <bdi dir="ltr">{money(value)}</bdi>
                </td>
              </tr>
            ))}
            <tr>
              <th>{t("Payment Method")}</th>
              <td>
                {t(human(displayValue(totals.payment_method || "—")), {
                  defaultValue: displayValue(totals.payment_method || "—"),
                })}
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </main>
  );
}

function PurchaseInvoicePrint({
  meta,
  content,
}: {
  meta: JsonRecord;
  content: JsonRecord;
}) {
  const { t, i18n: printI18n } = useTranslation();
  const printIdentity = (meta.print_identity || {}) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    entity = (content.entity || {}) as JsonRecord,
    partyContainer = (content.party || {}) as JsonRecord,
    supplier = (partyContainer.data || partyContainer) as JsonRecord,
    generatedBy = (meta.generated_by || {}) as JsonRecord,
    totals = (content.totals_summary || {}) as JsonRecord,
    items = Array.isArray(content.rows_lines)
      ? (content.rows_lines as JsonRecord[])
      : [];
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  const date = (value: unknown) =>
    value ? formatDateOnly(String(value).slice(0, 10)) : "—";
  const lineTotal = (item: JsonRecord) =>
    Number(item.transaction_quantity ?? item.amount ?? 0) *
      Number(item.transaction_unit_cost ?? item.purchase_price ?? 0) -
    Number(item.discount ?? 0);

  return (
    <main
      lang={locale}
      dir={locale === "ar" ? "rtl" : "ltr"}
      className="print-report print-portrait purchase-invoice-print"
      style={{ display: "block" }}
    >
      <div className="print:hidden mb-4 flex justify-end">
        <button
          type="button"
          className="report-action primary"
          onClick={() => window.print()}
        >
          {t("Print")}
        </button>
      </div>
      <PrintIdentityHeader identity={printIdentity} />
      <header className="print-header purchase-invoice-meta">
        <div className="purchase-invoice-meta-group">
          <strong>{t("Supplier")}</strong>
          <p>{displayValue(supplier.name || "—")}</p>
          <strong>{t("Branch")}</strong>
          <p>{displayValue(branch.name || branch.code || "—")}</p>
        </div>
        <div className="print-title">
          <h2>{t("Purchase Invoice")}</h2>
          <bdi dir="ltr">{displayValue(meta.document_number)}</bdi>
        </div>
        <div className="print-generated purchase-invoice-meta-group">
          <strong>{t("Invoice date")}</strong>
          <p><bdi dir="ltr">{date(entity.date || meta.date)}</bdi></p>
          {entity.due_date ? (
            <>
              <strong>{t("Due date")}</strong>
              <p><bdi dir="ltr">{date(entity.due_date)}</bdi></p>
            </>
          ) : null}
          <strong>{t("Generated By")}</strong>
          <p>{displayValue(generatedBy.name || "—")}</p>
        </div>
      </header>

      <section className="purchase-invoice-items">
        <h2>{t("Items")}</h2>
        <div className="purchase-invoice-table-wrap">
          <table className="purchase-invoice-items-table">
            <thead>
              <tr>
                {[
                  "Product",
                  "Batch",
                  "Unit / Packaging",
                  "Quantity",
                  "Unit Cost",
                  "Discount",
                  "Line Total",
                  "Expiry",
                ].map((label) => (
                  <th key={label}>{t(label)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => (
                <tr key={String(item.id ?? index)}>
                  <td className="item-product">
                    {displayValue(item.product_name || "—")}
                  </td>
                  <td className="item-batch">
                    <bdi dir="ltr">{displayValue(item.batch_number || "—")}</bdi>
                  </td>
                  <td className="item-unit">
                    {displayValue(
                      item.transaction_unit_name ||
                        item.unit_name ||
                        item.unit_symbol ||
                        t("Base unit"),
                    )}
                  </td>
                  <td className="item-number">
                    <bdi dir="ltr">
                      {displayValue(item.transaction_quantity ?? item.amount)}
                    </bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">
                      {formatMoney(
                        Number(
                          item.transaction_unit_cost ?? item.purchase_price ?? 0,
                        ),
                      )}
                    </bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">{formatMoney(Number(item.discount || 0))}</bdi>
                  </td>
                  <td className="item-money">
                    <bdi dir="ltr">{formatMoney(lineTotal(item))}</bdi>
                  </td>
                  <td className="item-date">
                    <bdi dir="ltr">{date(item.expire_date)}</bdi>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="print-summary purchase-invoice-summary">
        <h2>{t("Summary")}</h2>
        <table>
          <tbody>
            {[
              ["Subtotal", totals.subtotal],
              ["Invoice discount", totals.discount],
              ["Final total", totals.total],
            ].map(([label, value]) => (
              <tr
                key={String(label)}
                className={label === "Final total" ? "summary-emphasis" : undefined}
              >
                <th>{t(String(label))}</th>
                <td><bdi dir="ltr">{formatMoney(Number(value || 0))}</bdi></td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </main>
  );
}

function SalesReturnPrint({
  meta,
  content,
}: {
  meta: JsonRecord;
  content: JsonRecord;
}) {
  const { t, i18n: printI18n } = useTranslation();
  const printIdentity = (meta.print_identity || {}) as JsonRecord,
    branch = (meta.branch || {}) as JsonRecord,
    customer = (meta.customer || {}) as JsonRecord,
    generatedBy = (meta.generated_by || {}) as JsonRecord,
    originalSale = (content.original_sale || {}) as JsonRecord,
    totals = (content.totals || {}) as JsonRecord,
    items = Array.isArray(content.items) ? (content.items as JsonRecord[]) : [];
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  const returnDate = meta.date
    ? formatDateOnly(String(meta.date).slice(0, 10))
    : "—";

  return (
    <main
      lang={locale}
      dir={locale === "ar" ? "rtl" : "ltr"}
      className="print-report print-portrait sales-return-print"
      style={{ display: "block" }}
    >
      <div className="print:hidden mb-4 flex justify-end">
        <button
          type="button"
          className="report-action primary"
          onClick={() => window.print()}
        >
          {t("Print")}
        </button>
      </div>
      <PrintIdentityHeader identity={printIdentity} />
      <header className="print-header sales-return-meta">
        <div className="sales-return-meta-group">
          <strong>{t("Customer")}</strong>
          <p>{displayValue(customer.name || t("Walk-in customer"))}</p>
          <strong>{t("Branch")}</strong>
          <p>{displayValue(branch.name || branch.code || "—")}</p>
        </div>
        <div className="print-title">
          <h2>{t("Sales Return")}</h2>
          <bdi dir="ltr">{displayValue(meta.document_number)}</bdi>
        </div>
        <div className="print-generated sales-return-meta-group">
          <strong>{t("Return Date")}</strong>
          <p><bdi dir="ltr">{returnDate}</bdi></p>
          <strong>{t("Generated By")}</strong>
          <p>{displayValue(generatedBy.name || "—")}</p>
        </div>
      </header>

      <div className="sales-return-reference">
        <strong>{t("Original Sales Invoice")}</strong>
        <bdi dir="ltr">{displayValue(originalSale.document_number || "—")}</bdi>
      </div>

      <section className="sales-return-items">
        <h2>{t("Items")}</h2>
        <div className="sales-return-table-wrap">
          <table className="sales-return-items-table">
            <thead>
              <tr>
                {[
                  "Product",
                  "Batch",
                  "Unit / Packaging",
                  "Returned Quantity",
                  "Original Sale Price",
                  "Original Return Value",
                  "Customer Credit",
                  "Return Deduction",
                  "Returned to Inventory",
                ].map((label) => (
                  <th key={label}>{t(label)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => (
                <tr key={index}>
                  <td className="item-product">{displayValue(item.product)}</td>
                  <td className="item-batch"><bdi dir="ltr">{displayValue(item.batch || "—")}</bdi></td>
                  <td className="item-unit">{displayValue(item.unit || t("Base unit"))}</td>
                  <td className="item-number"><bdi dir="ltr">{displayValue(item.quantity)}</bdi></td>
                  <td className="item-money"><bdi dir="ltr">{formatMoney(Number(item.original_unit_amount || 0))}</bdi></td>
                  <td className="item-money"><bdi dir="ltr">{formatMoney(Number(item.gross_original_value || 0))}</bdi></td>
                  <td className="item-money"><bdi dir="ltr">{formatMoney(Number(item.customer_credit_value || 0))}</bdi></td>
                  <td className="item-money"><bdi dir="ltr">{formatMoney(Number(item.deduction || 0))}</bdi></td>
                  <td className="item-boolean">{t(item.return_to_inventory ? "Yes" : "No")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="print-summary sales-return-summary">
        <h2>{t("Summary")}</h2>
        <table>
          <tbody>
            {[
              ["Original Return Value", totals.gross_original_return_total],
              ["Customer Credit", totals.customer_credit_total],
              ["Return Deduction", totals.return_deduction_total],
            ].map(([label, value]) => (
              <tr key={String(label)}>
                <th>{t(String(label))}</th>
                <td><bdi dir="ltr">{formatMoney(Number(value || 0))}</bdi></td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </main>
  );
}

export function ActionableAlerts() {
  const { t } = useTranslation(),
    [rows, setRows] = useState<JsonRecord[]>([]);
  useEffect(() => {
    const c = new AbortController();
    erpApi.alerts(c.signal).then((r) => setRows(r.data));
    return () => c.abort();
  }, []);
  return (
    <section className="bg-white border rounded-2xl overflow-hidden">
      <div className="p-5 border-b">
        <h2 className="font-bold text-xl">{t("Actionable Alerts")}</h2>
        <p className="text-sm text-gray-500">
          {t(
            "Current conditions recalculated by the server—not historical notifications.",
          )}
        </p>
      </div>
      {rows.length ? (
        <div className="divide-y">
          {rows.map((r, i) => (
            <div
              className="p-4 flex gap-3 items-center"
              key={displayValue(r.source_identity) || i}
            >
              <StatusBadge value={r.severity} />
              <div className="flex-1">
                <strong>{t(human(displayValue(r.alert_type)))}</strong>
                <p className="text-sm text-gray-600">
                  {t(`alert.${displayValue(r.alert_type)}`, {
                    name: displayValue(r.message),
                    value: displayValue(r.current_value),
                    threshold: displayValue(r.threshold_or_date),
                    defaultValue: displayValue(r.message),
                  })}
                </p>
              </div>
              <bdi dir="ltr">{valueText("current_value", r.current_value)}</bdi>
            </div>
          ))}
        </div>
      ) : (
        <p className="p-8 text-center text-gray-500">
          {t("No current actionable alerts.")}
        </p>
      )}
    </section>
  );
}
