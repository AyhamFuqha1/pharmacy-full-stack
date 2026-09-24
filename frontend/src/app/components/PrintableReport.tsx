import { useEffect } from "react";
import { useTranslation } from "react-i18next";
import type { PrintReportData } from "../../types/universalReports";
import { formatMoney } from "../../utils/batchFormatters";
import i18n from "../../i18n";
import { displayValue } from "./ErpPrimitives";
import { PrintIdentityHeader } from "./PrintIdentityHeader";

const moneyKeys =
  /amount|price|cost|profit|revenue|debit|credit|balance|outstanding|cash|value/i;
const dateKeys = /date|expiry|_at$|timestamp/i;
const technicalKeys =
  /(^id$|_id$|number|code|reference|date|_at$|timestamp|amount|price|cost|debit|credit|balance|quantity|count|total)/i;
const title = (key: string) =>
  key.replace(/_/g, " ").replace(/\b\w/g, (value) => value.toUpperCase());
const reportLabel = (key: string) =>
  ({
    current_not_due: "Current / Not Due",
    "0_30": "0–30 Days",
    "31_60": "31–60 Days",
    "61_90": "61–90 Days",
    over_90: "More than 90 Days",
  })[key] || title(key);
const stableReportValues: Record<string, string> = {
  purchase: "Purchase",
  sale: "Sale",
  sales_return: "Sales Return",
  sales_cancel: "Sale Cancellation",
  purchase_return: "Purchase Return",
  stock_adjustment: "Stock Adjustment",
  stock_count: "Stock Count",
  stock_transfer: "Stock Transfer",
  opening_balance: "Opening Balance",
  prescription_dispense: "Prescription Dispense",
  PURCHASE_IN: "Purchase",
  prescription: "Prescription Dispense",
  non_commercial: "Non Commercial",
  SALE_OUT: "Sale",
  SALES_RETURN_IN: "Sales Return",
  PURCHASE_RETURN_OUT: "Purchase Return",
  ADJUSTMENT_IN: "Stock Adjustment In",
  ADJUSTMENT_OUT: "Stock Adjustment Out",
  TRANSFER_IN: "Transfer In",
  TRANSFER_OUT: "Transfer Out",
  PRESCRIPTION_DISPENSE_OUT: "Prescription Dispense",
  SALE_CANCELLATION_IN: "Sale Cancellation / Stock Restore",
  OPENING_STOCK_IN: "Opening Stock",
};

export function reportCell(key: string, value: unknown): string {
  if (key === "batch_expiry" && (value === null || value === ""))
    return i18n.t("No expiry date");
  if (value === null || value === "") return "—";
  if (typeof value === "boolean") return i18n.t(value ? "Yes" : "No");
  if (key === "bucket") {
    const buckets: Record<string, string> = {
      current_not_due: "Current / Not Due",
      "0_30": "0–30 Days",
      "31_60": "31–60 Days",
      "61_90": "61–90 Days",
      over_90: "More than 90 Days",
    };
    return i18n.t(buckets[String(value)] || String(value));
  }
  if (key === "side")
    return i18n.t(String(value) === "customer" ? "Customer aging side" : "Supplier aging side");
  if (
    [
      "type",
      "movement_type",
      "reference_type",
      "source_type",
      "prescription_commercial_disposition",
    ].includes(key) &&
    stableReportValues[String(value)]
  )
    return i18n.t(stableReportValues[String(value)]);
  if (moneyKeys.test(key) && Number.isFinite(Number(value)))
    return formatMoney(Number(value));
  if (dateKeys.test(key) && typeof value === "string") {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value.replaceAll("-", "/");
    const parsed = new Date(value);
    if (!Number.isNaN(parsed.getTime()))
      return new Intl.DateTimeFormat(
        i18n.resolvedLanguage === "ar" ? "ar" : "en",
        {
          dateStyle: "medium",
          timeStyle: value.includes("T") ? "short" : undefined,
        },
      ).format(parsed);
  }
  return displayValue(value).replace(/_/g, " ");
}

export function PrintableReport({ data }: { data: PrintReportData }) {
  const { t, i18n: printI18n } = useTranslation();
  const locale = printI18n.resolvedLanguage === "ar" ? "ar" : "en";
  useEffect(() => {
    const style = document.createElement("style");
    style.dataset.erpPrintOrientation = "true";
    style.textContent = `@media print { @page { size: A4 ${data.suggested_orientation || "portrait"}; margin: 12mm; } }`;
    document.head.appendChild(style);
    return () => style.remove();
  }, [data.suggested_orientation]);
  const columns =
    data.column_definitions ||
    Object.keys(data.rows[0] || {}).map((key) => ({ key, label: title(key) }));
  const filters = Object.entries(data.filters || {}).filter(
    ([, value]) => value !== null && value !== "",
  );
  const cell = (key: string, value: unknown) => (
    <bdi dir={technicalKeys.test(key) ? "ltr" : undefined}>
      {reportCell(key, value)}
    </bdi>
  );
  return (
    <section
      lang={locale}
      dir={locale === "ar" ? "rtl" : "ltr"}
      className={`print-report print-${data.suggested_orientation || "portrait"}`}
    >
      <PrintIdentityHeader identity={data.print_identity || {}} />
      <header className="print-header report-print-header">
        <div><p>
            {data.branch ? (
              <>
                {t("Branch")}: {displayValue(data.branch)}
              </>
            ) : (
              t("All authorized branches")
            )}
          </p>
        </div>
        <div className="print-title">
          <h2>{t(data.report_title || "Report")}</h2>
          <p>
            {data.from || data.to ? (
              <>
                <bdi dir="ltr">{data.from || t("Beginning")}</bdi> —{" "}
                <bdi dir="ltr">{data.to || t("Present")}</bdi>
              </>
            ) : (
              t("Complete filtered period")
            )}
          </p>
        </div>
        <div className="print-generated">
          <p>
            {t("Generated At")}{" "}
            <bdi dir="ltr">
              {data.generated_at
                ? new Date(data.generated_at).toLocaleString(locale)
                : ""}
            </bdi>
          </p>
          <p>
            {t("Generated By")}{" "}
            {displayValue(data.generated_by || t("Authorized user"))}
          </p>
        </div>
      </header>
      {filters.length > 0 && (
        <div className="print-filters">
          <strong>{t("Filters")}</strong>
          {filters.map(([key, value]) => (
            <span key={key}>
              <strong>{t(title(key))}:</strong> {cell(key, value)}
            </span>
          ))}
        </div>
      )}
      {data.opening_balance !== null && data.opening_balance !== undefined && (
        <p className="print-balance">
          <strong>{t("Opening Balance")}:</strong>{" "}
          {cell("opening_balance", data.opening_balance)}
        </p>
      )}
      <table>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key}>
                {t(title(column.key), { defaultValue: column.label })}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.rows.length ? (
            data.rows.map((row, index) => (
              <tr key={index}>
                {columns.map((column) => (
                  <td key={column.key}>{cell(column.key, row[column.key])}</td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan={columns.length || 1}>{t("No data")}</td>
            </tr>
          )}
        </tbody>
      </table>
      <footer className="print-summary">
        {Object.entries(data.summaries || {}).map(([key, value]) => (
          <div key={key}>
            <span>{t(reportLabel(key))}</span>
            <strong>{cell(key, value)}</strong>
          </div>
        ))}
        {data.closing_balance !== null &&
          data.closing_balance !== undefined && (
            <div>
              <span>{t("Closing Balance")}</span>
              <strong>{cell("closing_balance", data.closing_balance)}</strong>
            </div>
          )}
      </footer>
    </section>
  );
}
