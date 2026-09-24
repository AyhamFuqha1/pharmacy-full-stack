import { useEffect, useState } from "react";
import { ApiClientError } from "../../api/client";
import { attachmentsApi } from "../../api/attachments";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { useAuth } from "../../auth/useAuth";
import { formatMoney } from "../../utils/batchFormatters";
import i18n from "../../i18n";
import { useTranslation } from "react-i18next";
const readableValueKeys = [
  "display_name",
  "name",
  "label",
  "code",
  "document_number",
  "reference",
] as const;
export function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (
    typeof value === "string" ||
    typeof value === "number" ||
    typeof value === "bigint"
  )
    return String(value);
  if (Array.isArray(value)) {
    const items = value.map(displayValue).filter((item) => item !== "—");
    return items.length ? items.join(", ") : "—";
  }
  if (typeof value === "object") {
    const record = value as Record<string, unknown>;
    for (const key of readableValueKeys) {
      const candidate = record[key];
      if (
        candidate !== null &&
        candidate !== undefined &&
        candidate !== "" &&
        typeof candidate !== "object"
      )
        return displayValue(candidate);
    }
    return "—";
  }
  return "—";
}
const friendlyLabels: Record<string, string> = {
  branch_id: "Branch",
  batch_id: "Batch / Lot",
  product_id: "Product",
  customer_id: "Customer",
  provider_id: "Supplier",
  supplier_id: "Supplier",
  user_id: "Team Member",
  unit_id: "Unit / Packaging",
  document_id: "Document",
  entity_type: "Record Type",
  source_type: "Source",
  movement_type: "Movement Type",
  cogs: "Cost of Goods Sold",
  qty: "Quantity",
  system_qty: "System Quantity",
  current_ap: "Supplier Balance",
  sales_total: "Sales Total",
  sales_return_total: "Sales Return Total",
  receipts_total: "Receipts Total",
  pending_amount: "Pending Amount",
  activity_type: "Activity Type",
  source_id: "Reference",
  source_label: "Details",
  timestamp: "Date / Time",
  due_date: "Due Date",
  cleared_at: "Cleared At",
  bounced_at: "Bounced At",
  opening_stock_in: "Opening Stock",
  opening_in: "Opening Stock",
  purchase_in: "Purchase",
  purchase_return_out: "Purchase Return",
  sale_out: "Sale",
  sales_return_in: "Sales Return",
  prescription_dispense_out: "Prescription Dispense",
  adjustment_in: "Stock Adjustment In",
  adjustment_out: "Stock Adjustment Out",
  stock_count_in: "Stock Count",
  transfer_in: "Transfer In",
  transfer_out: "Transfer Out",
  sale_cancellation_in: "Sale Cancellation / Stock Restore",
};
export const human = (v: string) => {
  const english =
    friendlyLabels[v.toLowerCase()] ||
    v.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
  return i18n.t(english, { defaultValue: english });
};
export function valueText(k: string, v: any): string {
  if (v === null || v === undefined || v === "") return "—";
  if (typeof v === "boolean") return i18n.t(v ? "Yes" : "No");
  if (/(^id$|_id$)/i.test(k)) return String(v);
  if (/^(activity_type|source_type|movement_type|type)$/.test(k))
    return i18n.t(human(String(v)), { defaultValue: human(String(v)) });
  if (
    /amount|price|cost|profit|revenue|balance|cash|outstanding|credit|debit/i.test(
      k,
    ) &&
    !Number.isNaN(Number(v))
  )
    return formatMoney(v);
  if (typeof v === "object")
    return Array.isArray(v)
      ? i18n.t("itemCount", { count: v.length })
      : v.name || v.document_number || v.label || JSON.stringify(v);
  return String(v).replace(/_/g, " ");
}
export function ErrorNotice({ error }: { error: ApiClientError | null }) {
  const { t } = useTranslation();
  if (!error) return null;
  return (
    <div
      className={`p-4 rounded-xl border ${error.status === 409 ? "bg-amber-50 text-amber-800" : "bg-red-50 text-red-700"}`}
    >
      <strong>
        {error.status === 403
          ? t("Permission denied")
          : error.status === 409
            ? t("Business conflict")
            : error.status === 422
              ? t("Validation failed")
              : t("Request failed")}
      </strong>
      <p>{error.message}</p>
      {Object.entries(error.errors).map(([k, v]) => (
        <p className="text-sm" key={k}>
          {human(k)}: {v.join(" ")}
        </p>
      ))}
    </div>
  );
}
export const normalizeError = (v: unknown, msg = "Request failed.") =>
  v instanceof ApiClientError ? v : new ApiClientError(0, i18n.t(msg));
export function StatusBadge({ value }: { value: any }) {
  const { t } = useTranslation();
  const status = String(value ?? "unknown"),
    tone = /posted|received|open|available|completed|reconciled|active/i.test(
      status,
    )
      ? "bg-emerald-100 text-emerald-800"
      : /cancel|expired|recalled|failed|exceeded|disabled/i.test(status)
        ? "bg-red-100 text-red-800"
        : "bg-amber-100 text-amber-800";
  return (
    <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${tone}`}>
      {t(human(status), { defaultValue: human(status) })}
    </span>
  );
}
export function RecordTable({
  rows,
  columns,
  emptyText = "No information is available here yet.",
}: {
  rows: JsonRecord[];
  columns?: string[];
  emptyText?: string;
}) {
  const { t } = useTranslation();
  const cols =
    columns ||
    Object.keys(rows[0] || {}).filter(
      (k) => typeof rows[0][k] !== "object" && k !== "id",
    );
  return rows.length ? (
    <div className="overflow-x-auto">
      <table className="w-full min-w-max">
        <thead className="bg-gray-50">
          <tr>
            {cols.map((c) => (
              <th key={c}>{t(human(c), { defaultValue: human(c) })}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((r, i) => (
            <tr key={r.id ?? i}>
              {cols.map((c) => (
                <td key={c}>
                  {/status|state/.test(c) ? (
                    <StatusBadge value={r[c]} />
                  ) : (
                    <bdi
                      dir={
                        /phone|email|number|code|reference|_id$|date|_at$|amount|quantity|price|cost|total|debit|credit|balance/.test(c)
                          ? "ltr"
                          : undefined
                      }
                    >
                      {c === "source_label" &&
                      (r.activity_type || r.reference_type) &&
                      (r.source_id || r.reference_id)
                        ? t("sourceReference", {
                            type: t(
                              human(
                                String(r.activity_type || r.reference_type),
                              ),
                            ),
                            id: r.source_id || r.reference_id,
                          })
                        : valueText(c, r[c])}
                    </bdi>
                  )}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  ) : (
    <div className="p-10 text-center">
      <strong className="text-gray-700">{t("Nothing to show")}</strong>
      <p className="mt-1 text-sm text-gray-500">
        {t(emptyText, { defaultValue: emptyText })}
      </p>
    </div>
  );
}
export function MetricGrid({
  data,
  keys,
}: {
  data: JsonRecord;
  keys: string[];
}) {
  const { t } = useTranslation();
  return (
    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
      {keys
        .filter((k) => data[k] !== undefined)
        .map((k) => (
          <div className="p-4 bg-white border rounded-xl" key={k}>
            <p className="text-xs uppercase text-gray-500">
              {t(human(k), { defaultValue: human(k) })}
            </p>
            <strong className="text-xl">{valueText(k, data[k])}</strong>
          </div>
        ))}
    </div>
  );
}
export function AttachmentsSection({ type, id }: { type: string; id: number }) {
  const { t } = useTranslation();
  const { hasPermission } = useAuth(),
    [rows, setRows] = useState<JsonRecord[]>([]),
    [error, setError] = useState<ApiClientError | null>(null),
    [busy, setBusy] = useState(false);
  const load = () =>
    attachmentsApi
      .list(type, id)
      .then((r) => setRows(r.data))
      .catch((v) => setError(normalizeError(v)));
  useEffect(() => {
    if (hasPermission("attachments.manage")) void load();
  }, [type, id]);
  if (!hasPermission("attachments.manage")) return null;
  async function upload(f?: File) {
    if (!f) return;
    setBusy(true);
    try {
      await attachmentsApi.upload(type, id, f);
      await load();
    } catch (v) {
      setError(normalizeError(v));
    } finally {
      setBusy(false);
    }
  }
  async function download(r: JsonRecord) {
    try {
      const f = await attachmentsApi.download(r.id),
        u = URL.createObjectURL(f.blob),
        a = document.createElement("a");
      a.href = u;
      a.download = f.filename || r.original_name;
      a.click();
      URL.revokeObjectURL(u);
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  return (
    <section className="bg-white border rounded-2xl p-5 space-y-4">
      <div className="flex flex-wrap justify-between">
        <h2 className="font-bold text-lg">{t("Attachments")}</h2>
        <label className="report-action primary cursor-pointer">
          {t(busy ? "Uploading…" : "Upload")}
          <input
            hidden
            type="file"
            onChange={(e) => void upload(e.target.files?.[0])}
          />
        </label>
      </div>
      <ErrorNotice error={error} />
      {rows.map((r) => (
        <div
          className="flex flex-wrap gap-3 items-center border-t pt-3"
          key={r.id}
        >
          <div className="flex-1">
            <strong>{r.original_name}</strong>
            <p className="text-xs text-gray-500">
              <bdi dir="ltr">
                {r.mime_type} · {Math.ceil(r.size / 1024)} KB
              </bdi>{" "}
              · {t("Uploaded by")} <bdi>{r.uploaded_by}</bdi> ·{" "}
              {new Date(r.created_at).toLocaleString()}
            </p>
          </div>
          <button className="report-action" onClick={() => void download(r)}>
            {t("Download")}
          </button>
          <button
            className="report-action text-red-600"
            onClick={async () => {
              if (confirm(t("Delete this attachment?"))) {
                await attachmentsApi.remove(r.id);
                await load();
              }
            }}
          >
            {t("Delete")}
          </button>
        </div>
      ))}
    </section>
  );
}
const PRINT_STORAGE_KEY = "erp-print-document";
function beginPrint(): Window | null {
  sessionStorage.removeItem(PRINT_STORAGE_KEY);
  const popup = window.open("about:blank", "_blank");
  if (popup) {
    const locale = i18n.resolvedLanguage === "ar" ? "ar" : "en";
    popup.document.documentElement.lang = locale;
    popup.document.documentElement.dir = locale === "ar" ? "rtl" : "ltr";
    popup.document.title = i18n.t("Preparing print document");
    const main = popup.document.createElement("main");
    main.style.cssText = "font-family:system-ui;padding:32px";
    const heading = popup.document.createElement("h1");
    heading.textContent = i18n.t("Preparing document…");
    const message = popup.document.createElement("p");
    message.textContent = i18n.t(
      "Please wait while the printable document is loaded.",
    );
    main.append(heading, message);
    popup.document.body.replaceChildren(main);
  }
  return popup;
}
function finishPrint(popup: Window | null, data: JsonRecord) {
  const locale = i18n.resolvedLanguage === "ar" ? "ar" : "en";
  const serialized = JSON.stringify({ ...data, locale });
  sessionStorage.setItem(PRINT_STORAGE_KEY, serialized);
  if (popup && !popup.closed) {
    popup.sessionStorage.setItem(PRINT_STORAGE_KEY, serialized);
    popup.location.replace("/print-document");
  } else window.location.assign("/print-document");
}
function printError(value: unknown): ApiClientError {
  return value instanceof ApiClientError
    ? value
    : new ApiClientError(0, "Unable to prepare this document for printing.");
}
export function PrintDocumentButton({
  type,
  id,
  label = "Print",
}: {
  type: string;
  id: number;
  label?: string;
}) {
  const { t } = useTranslation();
  const { hasPermission } = useAuth(),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null);
  if (!hasPermission("reports.print")) return null;
  async function run() {
    const popup = beginPrint();
    setBusy(true);
    setError(null);
    try {
      const filters: Record<string, JsonRecord> = {
          batch_statement: { batch_id: id },
          recall: { recall_id: id },
        },
        response = filters[type]
          ? await erpApi.printReportDocument(
              type === "recall" ? "recall_statement" : type,
              filters[type],
            )
          : await erpApi.printDocument(
              type === "transfer" ? "stock_transfer" : type,
              id,
            );
      finishPrint(popup, response.data);
    } catch (value) {
      if (popup && !popup.closed) popup.close();
      setError(printError(value));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="inline-flex flex-col items-end gap-2">
      <button
        type="button"
        className="report-action"
        disabled={busy}
        onClick={() => void run()}
      >
        {busy ? t("Preparing…") : t(label)}
      </button>
      {error && (
        <div
          role="alert"
          className="max-w-sm rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {error.status === 403
            ? t("You do not have permission to print this document.")
            : error.message ||
              t("Unable to prepare this document for printing.")}{" "}
          <button
            type="button"
            className="underline"
            onClick={() => void run()}
          >
            {t("Retry")}
          </button>
        </div>
      )}
    </div>
  );
}
export function PrintReportButton({
  type,
  filters,
  label = "Print",
}: {
  type: string;
  filters: JsonRecord;
  label?: string;
}) {
  const { t } = useTranslation();
  const { hasPermission } = useAuth(),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null);
  if (!hasPermission("reports.print")) return null;
  async function run() {
    const popup = beginPrint();
    setBusy(true);
    setError(null);
    try {
      finishPrint(
        popup,
        (await erpApi.printReportDocument(type, filters)).data,
      );
    } catch (value) {
      if (popup && !popup.closed) popup.close();
      setError(printError(value));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="inline-flex flex-col items-end gap-2">
      <button
        type="button"
        className="report-action"
        disabled={busy}
        onClick={() => void run()}
      >
        {busy ? t("Preparing…") : t(label)}
      </button>
      {error && (
        <div
          role="alert"
          className="max-w-sm rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {error.status === 403
            ? t("You do not have permission to print this document.")
            : error.message ||
              t("Unable to prepare this document for printing.")}{" "}
          <button
            type="button"
            className="underline"
            onClick={() => void run()}
          >
            {t("Retry")}
          </button>
        </div>
      )}
    </div>
  );
}
