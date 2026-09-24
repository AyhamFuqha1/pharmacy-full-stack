import { useEffect, useState, type ReactNode } from "react";
import { useTranslation, type TFunction } from "react-i18next";
import { ApiClientError } from "../../api/client";
import { lookupsApi } from "../../api/lookups";
import { reportsApi } from "../../api/reports";
import type {
  BalanceSheetDto,
  IncomeStatementDto,
  InventoryValuationDto,
  ProductProfitabilityDto,
  LedgerReportDto,
  TrialBalanceDto,
} from "../../types/reports";
import { formatDateOnly, formatMoney } from "../../utils/batchFormatters";
import i18n from "../../i18n";
import {
  financialAccountName,
  financialDescription,
} from "../../utils/financialPresentation";
import {
  EntitySelect,
  Field,
  PageHeader,
  RemoteEntitySelect,
} from "../components/UxPrimitives";
import { productEntityLoader } from "../entityLoaders";
import { useEntityOptions } from "../hooks/useEntityOptions";

type Report = "ledger" | "trial" | "income" | "product_profitability" | "balance" | "inventory";
type Result =
  | LedgerReportDto
  | TrialBalanceDto
  | IncomeStatementDto
  | ProductProfitabilityDto
  | BalanceSheetDto
  | InventoryValuationDto;

const labels: Record<Report, string> = {
  ledger: "General Ledger",
  trial: "Trial Balance",
  income: "Income Statement / Profit & Loss",
  product_profitability: "Product Profitability",
  balance: "Balance Sheet",
  inventory: "Inventory Valuation",
};
const descriptions: Record<Report, string> = {
  ledger: "Detailed debit and credit entries for a selected account.",
  trial:
    "Summary of debit and credit balances used to verify accounting integrity.",
  income:
    "Revenue and expenses used to calculate profit for the selected period.",
  product_profitability: "Historical realized product revenue, COGS, gross profit and margin.",
  balance: "Assets, liabilities and equity as of the selected date.",
  inventory: "Accounting value of pharmacy inventory as of the selected date.",
};

export function ReportsPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions();
  const [report, setReport] = useState<Report>("ledger");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [accountCode, setAccountCode] = useState("");
  const [profitSort, setProfitSort] = useState("gross_profit");
  const [profitDirection, setProfitDirection] = useState<"asc"|"desc">("desc");
  const [profitProduct, setProfitProduct] = useState("");
  const [profitBranch, setProfitBranch] = useState("");
  const [appliedFilters, setAppliedFilters] = useState<{from?: string; to?: string; account_code?: string;sort?:string;direction?:"asc"|"desc";product_id?:number;branch_id?:number}>({});
  const [result, setResult] = useState<Result | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [reload, setReload] = useState(0);
  const [ledgerPage, setLedgerPage] = useState(1);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const filters = { from: appliedFilters.from, to: appliedFilters.to, sort: appliedFilters.sort, direction: appliedFilters.direction, product_id: appliedFilters.product_id, branch_id: appliedFilters.branch_id };
    const request =
      report === "ledger"
        ? reportsApi.generalLedger(
            { ...filters, account_code: appliedFilters.account_code, page: ledgerPage, per_page: 50 },
            controller.signal,
          )
        : report === "trial"
          ? reportsApi.trialBalance(filters, controller.signal)
          : report === "income"
            ? reportsApi.incomeStatement(filters, controller.signal)
            : report === "product_profitability"
              ? reportsApi.productProfitability({ ...filters, page: ledgerPage, per_page: 25 }, controller.signal)
            : report === "balance"
              ? reportsApi.balanceSheet(
                  { to: to || undefined },
                  controller.signal,
                )
              : reportsApi.inventoryValuation(
                  { to: to || undefined },
                  controller.signal,
                );
    request
      .then(setResult)
      .catch((value) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(
                  0,
                  t(
                    "The accounting report could not be loaded. Check your access or connection.",
                  ),
                ),
          );
      })
      .finally(() => !controller.signal.aborted && setLoading(false));
    return () => controller.abort();
  }, [report, reload, ledgerPage]);

  const accountLoader = async (
    query: string,
    page: number,
    signal: AbortSignal,
  ) => {
    const response = await lookupsApi.accounts(
      { q: query, page, per_page: 25 },
      signal,
    );
    return {
      options: response.data.map((account) => ({
        id: account.id,
        label: `${account.code} — ${financialAccountName(account.code, account.name, t)}`,
      })),
      hasMore: page < (response.meta.last_page || 1),
    };
  };
  return (
    <div className="p-4 md:p-8 space-y-6">
      <PageHeader
        title={t("Accounting Reports")}
        description={t(
          "Review authoritative financial information created from posted accounting entries.",
        )}
      />
      <div className="flex flex-wrap gap-2">
        {(Object.keys(labels) as Report[]).map((key) => (
          <button
            key={key}
            onClick={() => {
              setReport(key);
              setLedgerPage(1);
              setResult(null);
            }}
            className={`report-action ${report === key ? "primary" : ""}`}
          >
            {t(labels[key])}
          </button>
        ))}
      </div>
      <p className="text-sm text-gray-600">{t(descriptions[report])}</p>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (from && to && from > to) {
            setError(
              new ApiClientError(422, t("From Date cannot be after To Date.")),
            );
            return;
          }
          setAppliedFilters({ from: from || undefined, to: to || undefined, account_code: accountCode || undefined, sort: report === "product_profitability" ? profitSort : undefined, direction: report === "product_profitability" ? profitDirection : undefined, product_id: report === "product_profitability" && profitProduct ? Number(profitProduct) : undefined, branch_id: report === "product_profitability" && profitBranch ? Number(profitBranch) : undefined });
          if (ledgerPage === 1) setReload((value) => value + 1);
          else setLedgerPage(1);
        }}
        className="bg-white border rounded-2xl p-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end"
      >
        {report !== "balance" && report !== "inventory" && (
          <Field label={t("From Date")}>
            <input
              className="input"
              type="date"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
            />
          </Field>
        )}
        <Field
          label={
            report === "balance" || report === "inventory"
              ? t("As of Date")
              : t("To Date")
          }
        >
          <input
            className="input"
            type="date"
            value={to}
            onChange={(event) => setTo(event.target.value)}
          />
        </Field>
        {report === "ledger" && (
          <RemoteEntitySelect
            label={t("Account")}
            help={t(
              "Choose the account whose posted debit and credit entries you want to review.",
            )}
            value={accountCode}
            onChange={setAccountCode}
            onSelect={(option) => setAccountCode(option.label.split(" — ")[0])}
            load={accountLoader}
            placeholder={t("Search account code or name")}
          />
        )}
        {report === "product_profitability" && <>
          <RemoteEntitySelect label={t("Product")} value={profitProduct} onChange={setProfitProduct} load={productEntityLoader} placeholder={t("All Products")} />
          <EntitySelect label={t("Branch")} value={profitBranch} onChange={setProfitBranch} options={branches} allLabel={t("All Branches")} />
          <Field label={t("Sort By")}><select className="input" value={profitSort} onChange={(event)=>setProfitSort(event.target.value)}><option value="net_sales_revenue">{t("Net Sales Revenue")}</option><option value="gross_profit">{t("Gross Profit")}</option><option value="gross_margin_percent">{t("Gross Margin %")}</option></select></Field>
          <Field label={t("Direction")}><select className="input" value={profitDirection} onChange={(event)=>setProfitDirection(event.target.value as "asc"|"desc")}><option value="desc">{t("Descending")}</option><option value="asc">{t("Ascending")}</option></select></Field>
        </>}
        <button className="report-action primary">
          {t("runAccountingReport", { report: t(labels[report]) })}
        </button>
      </form>
      {error && (
        <div className="p-5 bg-red-50 border text-red-700 rounded-xl">
          <strong>
            {error.status === 403
              ? t("Report access denied")
              : error.status === 422
                ? t("Check the selected filters")
                : t("Report request failed")}
          </strong>
          <p>
            {error.status === 403
              ? t("You do not have permission to view this accounting report.")
              : error.message}
          </p>
        </div>
      )}
      {loading ? (
        <div
          className="h-64 bg-gray-100 animate-pulse rounded-2xl"
          aria-label={t("Loading accounting report…")}
        />
      ) : (
        result && <ReportResult report={report} result={result} t={t} ledgerPage={ledgerPage} setLedgerPage={setLedgerPage} />
      )}
    </div>
  );
}

function ReportResult({
  report,
  result,
  t,
  ledgerPage,
  setLedgerPage,
}: {
  report: Report;
  result: Result;
  t: TFunction;
  ledgerPage: number;
  setLedgerPage: (page: number) => void;
}) {
  if (report === "ledger") {
    const ledger = result as LedgerReportDto;
    const rows = ledger.data;
    return (
      <div className="space-y-4">
      <Card title={t("General Ledger")}>
        <div className="grid sm:grid-cols-2 gap-3 mb-4">
          <div className="rounded-xl bg-gray-50 p-3"><span>{t("Total Debit")}</span><strong className="block"><bdi dir="ltr">{formatMoney(ledger.totals.total_debit)}</bdi></strong></div>
          <div className="rounded-xl bg-gray-50 p-3"><span>{t("Total Credit")}</span><strong className="block"><bdi dir="ltr">{formatMoney(ledger.totals.total_credit)}</bdi></strong></div>
        </div>
        <Data
          empty={t("No ledger entries found.")}
          heads={[
            t("Date"),
            t("Entry Number"),
            t("Account"),
            t("Description"),
            t("Reference"),
            t("Debit"),
            t("Credit"),
          ]}
          rows={rows.map((row) => [
            <bdi dir="ltr">{formatDateOnly(row.entry_date)}</bdi>,
            <bdi dir="ltr">{row.entry_number}</bdi>,
            <span>
              <bdi dir="ltr">{row.code}</bdi> — {financialAccountName(row.code, row.account_name, t)}
            </span>,
            financialDescription(
              row.source_type,
              row.description ?? row.entry_description,
              t,
            ) ?? t("Not provided"),
            <bdi dir="ltr">
              {t("sourceReference", {
                type: t(friendly(row.source_type)),
                id: row.source_id,
              })}
            </bdi>,
            <bdi dir="ltr">
              {row.side === "debit" ? formatMoney(row.amount) : "—"}
            </bdi>,
            <bdi dir="ltr">
              {row.side === "credit" ? formatMoney(row.amount) : "—"}
            </bdi>,
          ])}
        />
      </Card>
      {ledger.meta.last_page > 1 && <div className="flex items-center justify-between gap-3" dir={i18n.dir()}>
        <p className="text-sm text-gray-500">{t("reportPageSummary", { page: ledger.meta.current_page, lastPage: ledger.meta.last_page, count: ledger.meta.total })}</p>
        <div className="flex gap-2">
          <button className="report-action" disabled={ledgerPage <= 1} onClick={() => setLedgerPage(ledgerPage - 1)}>{t("Previous")}</button>
          <button className="report-action" disabled={ledgerPage >= ledger.meta.last_page} onClick={() => setLedgerPage(ledgerPage + 1)}>{t("Next")}</button>
        </div>
      </div>}
      </div>
    );
  }
  if (report === "trial") {
    const data = result as TrialBalanceDto;
    return (
      <Card title={t("Trial Balance")}>
        <Data
          empty={t("No accounts found.")}
          heads={[
            t("Account Code"),
            t("Account Name"),
            t("Debit"),
            t("Credit"),
            t("Balance"),
          ]}
          rows={data.accounts.map((account) => [
            <bdi dir="ltr">{account.code}</bdi>,
            financialAccountName(account.code, account.account_name, t),
            <bdi dir="ltr">{formatMoney(account.debit)}</bdi>,
            <bdi dir="ltr">{formatMoney(account.credit)}</bdi>,
            <bdi dir="ltr">{formatMoney(account.balance)}</bdi>,
          ])}
        />
        <Totals
          values={[
            [t("Total Debit"), data.total_debit],
            [t("Total Credit"), data.total_credit],
          ]}
        />
      </Card>
    );
  }
  if (report === "income") {
    const data = result as IncomeStatementDto;
    return (
      <Card title={t("Income Statement / Profit & Loss")}>
        <Data
          empty={t("No accounts found.")}
          heads={[
            t("Account Code"),
            t("Account Name"),
            t("Account Type"),
            t("Debit"),
            t("Credit"),
            t("Balance"),
          ]}
          rows={data.accounts.map((account) => [
            <bdi dir="ltr">{account.code}</bdi>,
            financialAccountName(account.code, account.account_name, t),
            t(friendly(account.account_type)),
            <bdi dir="ltr">{formatMoney(account.debit)}</bdi>,
            <bdi dir="ltr">{formatMoney(account.credit)}</bdi>,
            <bdi dir="ltr">{formatMoney(account.balance)}</bdi>,
          ])}
        />
        <Totals
          values={[
            [t("Net Sales Revenue"), data.net_sales_revenue],
            [t("Cost of Goods Sold"), data.cogs],
            [t("Gross Profit"), data.gross_profit],
            [t("Gross Margin %"), data.gross_margin_percent],
            [t("Other Income"), data.other_income],
            [t("Operating Expenses"), data.operating_expenses],
            [t("Inventory Adjustments"), data.inventory_adjustments],
            [t("Net Profit"), data.net_income],
          ]}
        />
        {!data.gl_reconciliation.reconciled && <div className="mt-4 rounded-xl bg-red-50 p-4 text-red-700">{t("Income statement does not reconcile to the General Ledger.")} <bdi dir="ltr">{formatMoney(data.gl_reconciliation.difference)}</bdi></div>}
      </Card>
    );
  }
  if (report === "product_profitability") {
    const data = result as ProductProfitabilityDto;
    return <div className="space-y-4"><Card title={t("Product Profitability")}><Data empty={t("No products found.")} heads={[t("Product"),t("Barcode"),t("Sold Quantity"),t("Sales Return Quantity"),t("Net Sold Quantity"),t("Net Sales Revenue"),t("Cost of Goods Sold"),t("Gross Profit"),t("Gross Margin %")]} rows={data.data.map((row) => [row.product_name,<bdi dir="ltr">{row.barcode || "—"}</bdi>,row.sold_quantity,row.sales_return_quantity,row.net_sold_quantity,<bdi dir="ltr">{formatMoney(row.net_sales_revenue)}</bdi>,<bdi dir="ltr">{formatMoney(row.cogs)}</bdi>,<bdi dir="ltr">{formatMoney(row.gross_profit)}</bdi>,<bdi dir="ltr">{row.gross_margin_percent}%</bdi>])} /></Card>{data.last_page>1&&<div className="flex justify-end gap-2"><button className="report-action" disabled={ledgerPage<=1} onClick={()=>setLedgerPage(ledgerPage-1)}>{t("Previous")}</button><span>{data.current_page} / {data.last_page}</span><button className="report-action" disabled={ledgerPage>=data.last_page} onClick={()=>setLedgerPage(ledgerPage+1)}>{t("Next")}</button></div>}</div>;
  }
  if (report === "balance") {
    const data = result as BalanceSheetDto;
    return (
      <Card title={t("Balance Sheet")}>
        <Data
          empty={t("No accounts found.")}
          heads={[
            t("Account Code"),
            t("Account Name"),
            t("Section"),
            t("Balance"),
          ]}
          rows={data.accounts.map((account) => [
            <bdi dir="ltr">{account.code}</bdi>,
            financialAccountName(account.code, account.account_name, t),
            t(friendly(account.account_type)),
            <bdi dir="ltr">{formatMoney(account.balance)}</bdi>,
          ])}
        />
        <Totals
          values={[
            [t("Assets"), data.assets],
            [t("Liabilities"), data.liabilities],
            [t("Total Equity"), data.total_equity ?? data.equity],
          ]}
        />
      </Card>
    );
  }
  const data = result as InventoryValuationDto;
  return (
    <Card title={t("Inventory Valuation")}>
      <div className="p-8 grid md:grid-cols-3 gap-4">
        <Metric
          label={
            <span>
              <bdi dir="ltr">{data.account_code}</bdi> — {financialAccountName(data.account_code, data.account_name, t)}
            </span>
          }
          value={data.value}
        />
        <Metric label={t("Posted Debits")} value={data.debit} />
        <Metric label={t("Posted Credits")} value={data.credit} />
      </div>
    </Card>
  );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="bg-white border rounded-2xl overflow-hidden">
      <h2 className="text-xl font-bold p-5 border-b">{title}</h2>
      {children}
    </div>
  );
}
function Data({
  heads,
  rows,
  empty = "No posted accounting entries match these filters.",
}: {
  heads: string[];
  rows: ReactNode[][];
  empty?: string;
}) {
  return rows.length ? (
    <div className="overflow-x-auto">
      <table className="w-full">
        <thead>
          <tr>
            {heads.map((heading) => (
              <th key={heading}>{heading}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, rowIndex) => (
            <tr key={rowIndex}>
              {row.map((value, columnIndex) => (
                <td key={columnIndex}>{value}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  ) : (
    <div className="p-12 text-center text-gray-500">{empty}</div>
  );
}
function Totals({ values }: { values: Array<[string, number]> }) {
  return (
    <div className="p-6 ms-auto max-w-md">
      {values.map(([label, value]) => (
        <div className="flex justify-between border-b py-2" key={label}>
          <span>{label}</span>
          <strong>
            <bdi dir="ltr">{formatMoney(value)}</bdi>
          </strong>
        </div>
      ))}
    </div>
  );
}
function Metric({ label, value }: { label: ReactNode; value: number }) {
  return (
    <div className="bg-gray-50 rounded-xl p-5">
      <p>{label}</p>
      <strong className="text-2xl">
        <bdi dir="ltr">{formatMoney(value)}</bdi>
      </strong>
    </div>
  );
}
const friendly = (value: string) =>
  String(value || "")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (character) => character.toUpperCase());
