import { Fragment, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Download,
  Filter,
  Printer,
  Save,
  Trash2,
  X,
} from "lucide-react";
import { Link, useNavigate, useSearchParams } from "react-router";
import { useTranslation, type TFunction } from "react-i18next";
import { ApiClientError } from "../../api/client";
import { universalReportsApi } from "../../api/universalReports";
import { lookupsApi } from "../../api/lookups";
import { productsApi } from "../../api/products";
import { batchesApi } from "../../api/batches";
import { customersApi } from "../../api/customers";
import { providersApi } from "../../api/providers";
import { useAuth } from "../../auth/useAuth";
import type {
  PrintReportData,
  ReportConfiguration,
  ReportDefinition,
  ReportQueryData,
  ReportValue,
  SavedReport,
} from "../../types/universalReports";
import { PrintableReport, reportCell } from "../components/PrintableReport";
import {
  EntitySelect,
  Field,
  RemoteEntitySelect,
  movementOptions,
  type SelectOption,
} from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import {
  batchEntityLoader,
  partyEntityLoader,
  productEntityLoader,
  userEntityLoader,
} from "../entityLoaders";
import i18n from "../../i18n";

const agingSummaryLabels: Record<string, string> = {
  current_not_due: "Current / Not Due",
  "0_30": "0–30 Days",
  "31_60": "31–60 Days",
  "61_90": "61–90 Days",
  over_90: "More than 90 Days",
};
const label = (key: string) =>
  i18n.t(
    agingSummaryLabels[key] ||
      key.replace(/_/g, " ").replace(/\b\w/g, (value) => value.toUpperCase()),
  );
const reportName = (item: Pick<ReportDefinition, "key" | "label">) =>
  i18n.t(`reportMode.${item.key}`, { defaultValue: i18n.t(item.label) });
const primaryKeys = new Set([
  "from",
  "to",
  "as_of",
  "search",
  "product_id",
  "batch_id",
  "customer_id",
  "provider_id",
  "party_id",
  "branch_id",
]);
const dedicatedReportRoutes: Record<string, string> = {
  stock_counts: "/inventory/stock-counts",
  stock_adjustments: "/inventory/adjustments",
  stock_transfers: "/inventory/transfers",
  products: "/products",
  batches: "/batches",
  customer_statement: "/customers",
  supplier_statement: "/suppliers",
  other_income: "/other-income",
  bank_reconciliation: "/bank-reconciliation",
  cashier_shifts: "/cashier-shifts",
  expenses: "/expenses",
  sales: "/sales",
  purchases: "/purchases",
  sales_returns: "/returns/sales",
  purchase_returns: "/returns/purchases",
  cheques: "/cheques",
  prescriptions: "/prescriptions",
  controlled_medicine_ledger: "/controlled-medicine-ledger",
};

export function UniversalReportsPage() {
  const { t } = useTranslation();
  const entityOptions = useEntityOptions({
    loadBatches: false,
    loadProducts: false,
    loadCustomers: false,
    loadSuppliers: false,
    loadUsers: false,
  });
  const { hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();
  const [catalog, setCatalog] = useState<ReportDefinition[]>([]);
  const [reportKey, setReportKey] = useState(params.get("report") || "");
  const [draftFilters, setDraftFilters] = useState<Record<string, ReportValue>>(
    {},
  );
  const [filters, setFilters] = useState<Record<string, ReportValue>>({});
  const [columns, setColumns] = useState<string[]>([]);
  const [sort, setSort] = useState("");
  const [direction, setDirection] = useState<"asc" | "desc">("asc");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<ReportQueryData | null>(null);
  const [saved, setSaved] = useState<SavedReport[]>([]);
  const [advanced, setAdvanced] = useState(false);
  const [loading, setLoading] = useState(true);
  const [action, setAction] = useState("");
  const [error, setError] = useState<ApiClientError | null>(null);
  const [printData, setPrintData] = useState<PrintReportData | null>(null);
  const [productOptions, setProductOptions] = useState<
    Record<string, SelectOption>
  >({});
  const [batchOptions, setBatchOptions] = useState<
    Record<string, SelectOption>
  >({});
  const [partyOptions, setPartyOptions] = useState<
    Record<string, SelectOption>
  >({});
  const reportRequest = useRef<{ controller: AbortController; id: number } | null>(null);
  const reportRequestId = useRef(0);
  const definition = useMemo(
    () => catalog.find((item) => item.key === reportKey),
    [catalog, reportKey],
  );

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    Promise.all([
      universalReportsApi.catalog(controller.signal),
      universalReportsApi.saved(controller.signal),
    ])
      .then(([catalogResponse, savedResponse]) => {
        setCatalog(catalogResponse.data);
        setSaved(savedResponse.data);
        const initial = reportKey
          ? catalogResponse.data.find((item) => item.key === reportKey)
          : catalogResponse.data[0];
        if (initial) selectReport(initial, reportKey ? params : undefined);
      })
      .catch(handleError)
      .finally(() => setLoading(false));
    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (
      !definition ||
      !sort ||
      columns.length === 0 ||
      definition.required_filters?.some((key) => !filters[key])
    )
      return;
    void runQuery();
  }, [page, sort, direction, reportKey]);
  useEffect(
    () => () => reportRequest.current?.controller.abort(),
    [],
  );
  useEffect(() => {
    const requested = params.get("report");
    if (!requested || requested === reportKey || catalog.length === 0) return;
    const item = catalog.find((entry) => entry.key === requested);
    if (item) selectReport(item, params);
  }, [params, catalog, reportKey]);

  useEffect(() => {
    const productIds = [draftFilters.product_id, filters.product_id]
      .map((value) => Number(value || 0))
      .filter(
        (id, index, values) =>
          id > 0 && values.indexOf(id) === index && !productOptions[String(id)],
      );
    if (!productIds.length) return;

    const controller = new AbortController();
    productIds.forEach((productId) => {
      productsApi
        .detail(productId, controller.signal, "all")
        .then(({ data: product }) =>
          setProductOptions((current) => ({
            ...current,
            [String(product.id)]: {
              id: product.id,
              label: `${product.name}${product.barcode ? ` — ${product.barcode}` : ""}`,
            },
          })),
        )
        .catch((value) => {
          if (value instanceof DOMException && value.name === "AbortError")
            return;
        });
    });
    return () => controller.abort();
  }, [draftFilters.product_id, filters.product_id, productOptions]);

  function rememberProduct(option: SelectOption | null) {
    if (!option) return;
    setProductOptions((current) => ({
      ...current,
      [String(option.id)]: option,
    }));
  }

  useEffect(() => {
    const ids = [draftFilters.batch_id, filters.batch_id]
      .map((value) => Number(value || 0))
      .filter(
        (id, index, values) =>
          id > 0 && values.indexOf(id) === index && !batchOptions[String(id)],
      );
    if (!ids.length) return;
    const controller = new AbortController();
    ids.forEach((id) =>
      batchesApi
        .detail(id, controller.signal)
        .then(({ data: batch }) =>
          setBatchOptions((current) => ({
            ...current,
            [String(batch.id)]: {
              id: batch.id,
              label: `${batch.batch_number}${batch.expiry_date ? ` — ${batch.expiry_date}` : ""}`,
            },
          })),
        )
        .catch(() => undefined),
    );
    return () => controller.abort();
  }, [draftFilters.batch_id, filters.batch_id, batchOptions]);

  useEffect(() => {
    const ids = [draftFilters.party_id, filters.party_id]
      .map((value) => Number(value || 0))
      .filter(
        (id, index, values) =>
          id > 0 && values.indexOf(id) === index && !partyOptions[String(id)],
      );
    if (!ids.length || !["ar_aging", "ap_aging"].includes(reportKey)) return;
    const controller = new AbortController();
    ids.forEach((id) => {
      const request =
        reportKey === "ar_aging"
          ? customersApi.detail(id, controller.signal, "all")
          : providersApi.detail(id, controller.signal, "all");
      request
        .then(({ data: party }) =>
          setPartyOptions((current) => ({
            ...current,
            [String(party.id)]: { id: party.id, label: party.name },
          })),
        )
        .catch(() => undefined);
    });
    return () => controller.abort();
  }, [draftFilters.party_id, filters.party_id, partyOptions, reportKey]);

  function rememberOption(
    setter: React.Dispatch<React.SetStateAction<Record<string, SelectOption>>>,
    option: SelectOption | null,
  ) {
    if (!option) return;
    setter((current) => ({ ...current, [String(option.id)]: option }));
  }

  function selectReport(item: ReportDefinition, source?: URLSearchParams) {
    const restored: Record<string, ReportValue> = {};
    item.filters.forEach((filter) => {
      const value = source?.get(filter.key);
      if (value !== null && value !== undefined) restored[filter.key] = value;
    });
    reportRequest.current?.controller.abort();
    reportRequest.current = null;
    setReportKey(item.key);
    setDraftFilters(restored);
    setFilters(restored);
    setColumns(item.default_columns || item.allowed_columns);
    setSort(item.sortable_columns[0] || "id");
    setDirection(item.default_direction || "asc");
    setPage(1);
    setResult(null);
    setError(null);
    setLoading(true);
    if (!source) setParams({ report: item.key });
  }
  function configuration(): ReportConfiguration {
    return {
      report_key: reportKey,
      filters,
      columns,
      sort,
      direction,
      per_page: 25,
    };
  }
  async function runQuery(nextFilters = filters, nextPage = page) {
    if (!definition) return;
    if (definition.required_filters?.some((key) => !nextFilters[key])) {
      setResult(null);
      return;
    }
    reportRequest.current?.controller.abort();
    const controller = new AbortController();
    const requestId = ++reportRequestId.current;
    reportRequest.current = { controller, id: requestId };
    setResult(null);
    setLoading(true);
    setError(null);
    try {
      const response = await universalReportsApi.query({
        ...configuration(),
        filters: nextFilters,
        page: nextPage,
      }, controller.signal);
      if (reportRequest.current?.id === requestId) setResult(response.data);
    } catch (value) {
      if (reportRequest.current?.id === requestId) {
        setResult(null);
        handleError(value);
      }
    } finally {
      if (reportRequest.current?.id === requestId) {
        reportRequest.current = null;
        setLoading(false);
      }
    }
  }
  function applyFilters(event: React.FormEvent) {
    event.preventDefault();
    if (
      String(draftFilters.from || "") > String(draftFilters.to || "") &&
      draftFilters.to
    ) {
      setError(
        new ApiClientError(422, t("From date cannot be after the to date.")),
      );
      return;
    }
    setFilters(draftFilters);
    setPage(1);
    const query = new URLSearchParams({ report: reportKey });
    Object.entries(draftFilters).forEach(([key, value]) => {
      if (value !== "" && value !== null) query.set(key, String(value));
    });
    setParams(query);
    void runQuery(draftFilters);
  }
  function clearFilters() {
    setDraftFilters({});
    setFilters({});
    setPage(1);
    setParams({ report: reportKey });
    void runQuery({});
  }
  function removeFilter(key: string) {
    const nextDraft = { ...draftFilters };
    const nextFilters = { ...filters };
    delete nextDraft[key];
    delete nextFilters[key];
    setDraftFilters(nextDraft);
    setFilters(nextFilters);
    setPage(1);
    const query = new URLSearchParams({ report: reportKey });
    Object.entries(nextFilters).forEach(([filterKey, value]) => {
      if (value !== "" && value !== null) query.set(filterKey, String(value));
    });
    setParams(query);
    void runQuery(nextFilters, 1);
  }
  async function exportCsv() {
    setAction("export");
    setError(null);
    try {
      const file = await universalReportsApi.exportCsv(configuration());
      const url = URL.createObjectURL(file.blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = file.filename;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (value) {
      handleError(value);
    } finally {
      setAction("");
    }
  }
  async function print() {
    setAction("print");
    setError(null);
    try {
      const response = await universalReportsApi.printData(configuration());
      if (response.data.print_too_large) {
        setError(
          new ApiClientError(
            409,
            t("reportTooLarge", {
              total: response.data.total_rows,
              maximum: response.data.max_rows,
            }),
          ),
        );
        return;
      }
      setPrintData(response.data);
      setTimeout(() => window.print(), 100);
    } catch (value) {
      handleError(value);
    } finally {
      setAction("");
    }
  }
  async function saveReport() {
    const name = window.prompt(t("Name this saved report"));
    if (!name?.trim()) return;
    setAction("save");
    try {
      const response = await universalReportsApi.save({
        name: name.trim(),
        report_key: reportKey,
        filters,
        sort,
        direction,
        visible_columns: columns,
      });
      setSaved((items) => [response.data, ...items]);
    } catch (value) {
      handleError(value);
    } finally {
      setAction("");
    }
  }
  async function openSaved(item: SavedReport) {
    const itemFilters =
      typeof item.filters === "string"
        ? JSON.parse(item.filters || "{}")
        : item.filters;
    const itemColumns =
      typeof item.visible_columns === "string"
        ? JSON.parse(item.visible_columns || "[]")
        : item.visible_columns;
    const itemDefinition = catalog.find(
      (entry) => entry.key === item.report_key,
    );
    if (!itemDefinition) {
      setReportKey(item.report_key);
      setParams({ report: item.report_key });
      return;
    }
    setReportKey(item.report_key);
    setDraftFilters(itemFilters);
    setFilters(itemFilters);
    setColumns(
      itemColumns.length
        ? itemColumns.filter((column: string) =>
            itemDefinition.allowed_columns.includes(column),
          )
        : itemDefinition.default_columns || itemDefinition.allowed_columns,
    );
    setSort(item.sort || itemDefinition.sortable_columns[0]);
    setDirection(item.direction || "asc");
    setPage(1);
    try {
      setLoading(true);
      const response = await universalReportsApi.executeSaved(item.id);
      setResult(response.data);
    } catch (value) {
      handleError(value);
    } finally {
      setLoading(false);
    }
  }
  async function removeSaved(id: number) {
    if (!window.confirm(t("Delete this saved report?"))) return;
    try {
      await universalReportsApi.deleteSaved(id);
      setSaved((items) => items.filter((item) => item.id !== id));
    } catch (value) {
      handleError(value);
    }
  }
  function handleError(value: unknown) {
    if (value instanceof DOMException && value.name === "AbortError") return;
    setError(
      value instanceof ApiClientError
        ? value
        : new ApiClientError(
            0,
            t("The report request could not be completed."),
          ),
    );
  }

  if (loading && catalog.length === 0)
    return (
      <div className="p-4 md:p-8">
        <div className="h-80 rounded-2xl bg-gray-100 animate-pulse" />
      </div>
    );
  if (!definition && reportKey)
    return (
      <UnsupportedReport
        reportKey={reportKey}
        destination={dedicatedReportRoutes[reportKey]}
      />
    );
  if (!definition)
    return (
      <div className="p-8">
        <ErrorBox
          error={
            error ||
            new ApiClientError(
              403,
              t("No reports are available for your permissions."),
            )
          }
        />
      </div>
    );
  const activeFilters = Object.entries(filters).filter(
    ([, value]) => value !== "" && value !== null,
  );
  return (
    <div className="min-w-0 max-w-full space-y-6 p-4 md:p-8">
      <div className="flex flex-wrap items-start justify-between gap-4 no-print">
        <div className="min-w-0">
          <h1 className="text-3xl font-bold">{t("Universal Reports")}</h1>
          <p className="text-gray-500">
            {t("Catalog-driven operational and financial reporting")}
          </p>
        </div>
        <div className="flex w-full flex-wrap gap-2 sm:w-auto sm:justify-end">
          <button
            className="report-action"
            onClick={saveReport}
            disabled={!!action}
          >
            <Save size={17} /> {t("Save")}
          </button>
          {definition.printable && hasPermission("reports.print") && (
            <button
              className="report-action"
              onClick={print}
              disabled={!!action}
            >
              <Printer size={17} />{" "}
              {action === "print" ? t("Preparing…") : t("Print all")}
            </button>
          )}
          {definition.exportable && hasPermission("reports.export") && (
            <button
              className="report-action primary"
              onClick={exportCsv}
              disabled={!!action}
            >
              <Download size={17} />{" "}
              {action === "export" ? t("Exporting…") : t("Export CSV")}
            </button>
          )}
        </div>
      </div>
      <div className="grid min-w-0 max-w-full gap-6 2xl:grid-cols-[minmax(0,1fr)_minmax(220px,260px)] no-print">
        <div className="min-w-0 space-y-6">
          <section className="min-w-0 max-w-full rounded-2xl border bg-white p-5">
            <div className="grid md:grid-cols-[minmax(220px,1fr)_auto] gap-4 items-end">
              <label className="min-w-0">
                <span className="block text-sm mb-2">{t("Report type")}</span>
                <select
                  className="input"
                  value={reportKey}
                  onChange={(event) => {
                    const item = catalog.find(
                      (value) => value.key === event.target.value,
                    );
                    if (item) selectReport(item);
                  }}
                >
                  {catalog.map((item) => (
                    <option key={item.key} value={item.key}>
                      {reportName(item)}
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                className="report-action h-12"
                onClick={() => setAdvanced((value) => !value)}
              >
                <Filter size={17} /> {t("Filters")}{" "}
                <ChevronDown
                  size={16}
                  className={advanced ? "rotate-180" : ""}
                />
              </button>
            </div>
            <form onSubmit={applyFilters}>
              <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                {definition.filters
                  .filter((item) => primaryKeys.has(item.key))
                  .map((item) => (
                    <div className="min-w-0" key={item.key}>
                      <FilterField
                        options={entityOptions}
                        item={item}
                        reportKey={reportKey}
                        required={definition.required_filters?.includes(
                          item.key,
                        )}
                        value={draftFilters[item.key]}
                        selectedProduct={
                          productOptions[
                            String(draftFilters.product_id || "")
                          ] || null
                        }
                        onProductSelect={rememberProduct}
                        selectedBatch={
                          batchOptions[String(draftFilters.batch_id || "")] ||
                          null
                        }
                        onBatchSelect={(option) =>
                          rememberOption(setBatchOptions, option)
                        }
                        selectedParty={
                          partyOptions[String(draftFilters.party_id || "")] ||
                          null
                        }
                        onPartySelect={(option) =>
                          rememberOption(setPartyOptions, option)
                        }
                        filterValues={draftFilters}
                        partyType={String(draftFilters.party_type || "")}
                        onPartyType={(value) => {
                          if (
                            definition.filters.some(
                              (filter) => filter.key === "party_type",
                            )
                          )
                            setDraftFilters((current) => ({
                              ...current,
                              party_type: value,
                            }));
                        }}
                        onChange={(value) =>
                          setDraftFilters((current) => ({
                            ...current,
                            [item.key]: value,
                            ...(["product_id", "branch_id"].includes(item.key)
                              ? { batch_id: "" }
                              : {}),
                          }))
                        }
                      />
                    </div>
                  ))}
              </div>
              {advanced && (
                <div className="mt-4 grid grid-cols-1 gap-4 border-t pt-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                  {definition.filters
                    .filter((item) => !primaryKeys.has(item.key))
                    .map((item) => (
                      <div className="min-w-0" key={item.key}>
                        <FilterField
                          options={entityOptions}
                          item={item}
                          reportKey={reportKey}
                          required={definition.required_filters?.includes(
                            item.key,
                          )}
                          value={draftFilters[item.key]}
                          selectedProduct={
                            productOptions[
                              String(draftFilters.product_id || "")
                            ] || null
                          }
                          onProductSelect={rememberProduct}
                          selectedBatch={
                            batchOptions[String(draftFilters.batch_id || "")] ||
                            null
                          }
                          onBatchSelect={(option) =>
                            rememberOption(setBatchOptions, option)
                          }
                          selectedParty={
                            partyOptions[String(draftFilters.party_id || "")] ||
                            null
                          }
                          onPartySelect={(option) =>
                            rememberOption(setPartyOptions, option)
                          }
                          filterValues={draftFilters}
                          partyType={String(draftFilters.party_type || "")}
                          onPartyType={(value) => {
                            if (
                              definition.filters.some(
                                (filter) => filter.key === "party_type",
                              )
                            )
                              setDraftFilters((current) => ({
                                ...current,
                                party_type: value,
                              }));
                          }}
                          onChange={(value) =>
                            setDraftFilters((current) => ({
                              ...current,
                              [item.key]: value,
                              ...(["product_id", "branch_id"].includes(item.key)
                                ? { batch_id: "" }
                                : {}),
                            }))
                          }
                        />
                      </div>
                    ))}
                  <ColumnPicker
                    definition={definition}
                    columns={columns}
                    setColumns={setColumns}
                  />
                </div>
              )}
              <div className="flex flex-wrap gap-2 mt-5">
                <button className="report-action primary">
                  {t("Apply Filters")}
                </button>
                <button
                  type="button"
                  className="report-action"
                  onClick={clearFilters}
                >
                  {t("Clear all")}
                </button>
              </div>
            </form>
            {activeFilters.length > 0 && (
              <div className="flex flex-wrap gap-2 mt-4">
                {activeFilters.map(([key, value]) => (
                  <span className="filter-chip" key={key}>
                    {key === "product_id" ? t("Product") : label(key)}:{" "}
                    {key === "product_id"
                      ? productOptions[String(value)]?.label ||
                        t("Selected product")
                      : key === "batch_id"
                        ? batchOptions[String(value)]?.label ||
                          t("Selected batch")
                        : key === "party_id"
                          ? partyOptions[String(value)]?.label ||
                            t(
                              reportKey === "ar_aging"
                                ? "Selected customer"
                                : "Selected supplier",
                            )
                          : key === "branch_id"
                            ? entityOptions.branches.find(
                                (option) => String(option.id) === String(value),
                              )?.label || t("Selected branch")
                            : renderReportValue(key, value, t)}
                    <button type="button" onClick={() => removeFilter(key)}>
                      <X size={13} />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </section>
          {error && <ErrorBox error={error} />}
          {loading ? (
            <div className="h-64 rounded-2xl bg-gray-100 animate-pulse" />
          ) : error ? null : result ? (
            <Results
              result={result}
              sort={sort}
              direction={direction}
              onSort={(column) => {
                if (!definition.sortable_columns.includes(column)) return;
                if (sort === column)
                  setDirection((value) => (value === "asc" ? "desc" : "asc"));
                else {
                  setSort(column);
                  setDirection("asc");
                }
              }}
            />
          ) : (
            <div className="bg-white border rounded-2xl p-12 text-center text-gray-500">
              {t("Apply filters to generate this report.")}
            </div>
          )}
          {!error && result && result.meta.last_page && result.meta.last_page > 1 && (
            <div className="flex justify-between items-center">
              <p className="text-sm text-gray-500">
                {t("reportPageSummary", {
                  page: result.meta.current_page,
                  lastPage: result.meta.last_page,
                  count: result.meta.total,
                })}
              </p>
              <div className="flex gap-2">
                <button
                  className="report-action"
                  disabled={page <= 1}
                  onClick={() => setPage((value) => value - 1)}
                >
                  <ChevronLeft size={17} />
                </button>
                <button
                  className="report-action"
                  disabled={page >= (result.meta.last_page || 1)}
                  onClick={() => setPage((value) => value + 1)}
                >
                  <ChevronRight size={17} />
                </button>
              </div>
            </div>
          )}
        </div>
        <aside className="min-w-0 rounded-2xl border bg-white p-4 h-fit">
          <h2 className="font-bold mb-3">{t("Saved reports")}</h2>
          {saved.length ? (
            <div className="space-y-2">
              {saved.map((item) => (
                <div key={item.id} className="group flex gap-2 items-center">
                  <button
                    onClick={() => openSaved(item)}
                    className="min-w-0 flex-1 rounded-xl p-3 text-start hover:bg-gray-50"
                  >
                    <strong className="block truncate text-sm">
                      {item.name}
                    </strong>
                    <span className="text-xs text-gray-500">
                      {(() => {
                        const entry = catalog.find(
                          (candidate) => candidate.key === item.report_key,
                        );
                        return entry ? reportName(entry) : item.report_key;
                      })()}
                    </span>
                  </button>
                  <button
                    aria-label={t("Delete saved report")}
                    onClick={() => removeSaved(item.id)}
                    className="p-2 text-gray-400 hover:text-red-600"
                  >
                    <Trash2 size={16} />
                  </button>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-500">
              {t("No saved reports yet.")}
            </p>
          )}
        </aside>
      </div>
      {printData && <PrintableReport data={printData} />}
    </div>
  );
}

function UnsupportedReport({
  reportKey,
  destination,
}: {
  reportKey: string;
  destination?: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="p-4 md:p-8">
      <section className="mx-auto max-w-2xl rounded-2xl border bg-white p-8 text-center">
        <h1 className="text-2xl font-bold">{t("Report unavailable here")}</h1>
        <p className="mt-3 text-gray-600">
          {destination
            ? t("This report is available in its dedicated module.")
            : t("This report type is not supported by Universal Reports.")}
        </p>
        <p className="mt-2 text-sm text-gray-500">{label(reportKey)}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-2">
          {destination && (
            <Link className="report-action primary" to={destination}>
              {t("Open dedicated module")}
            </Link>
          )}
          <Link
            className="report-action"
            to="/reports/universal?report=stock_movements"
          >
            {t("Back to reports")}
          </Link>
        </div>
      </section>
    </div>
  );
}

function FilterField({
  item,
  reportKey,
  required,
  value,
  filterValues,
  onChange,
  options,
  partyType,
  onPartyType,
  selectedProduct,
  onProductSelect,
  selectedBatch,
  onBatchSelect,
  selectedParty,
  onPartySelect,
}: {
  item: { key: string; type: string };
  reportKey: string;
  required?: boolean;
  value: ReportValue | undefined;
  filterValues: Record<string, ReportValue>;
  onChange: (value: ReportValue) => void;
  options: Record<string, SelectOption[]>;
  partyType: string;
  onPartyType: (value: string) => void;
  selectedProduct: SelectOption | null;
  onProductSelect: (option: SelectOption | null) => void;
  selectedBatch: SelectOption | null;
  onBatchSelect: (option: SelectOption | null) => void;
  selectedParty: SelectOption | null;
  onPartySelect: (option: SelectOption | null) => void;
}) {
  const { t } = useTranslation();
  const type =
    item.type === "date"
      ? "date"
      : ["integer", "decimal", "number"].includes(item.type)
        ? "number"
        : "text";
  const map: Record<string, { label: string; rows: SelectOption[] }> = {
    branch_id: { label: t("Branch"), rows: options.branches },
  };
  if (map[item.key])
    return (
      <EntitySelect
        label={map[item.key].label}
        value={String(value ?? "")}
        onChange={onChange}
        options={map[item.key].rows}
        allLabel={t("All")}
      />
    );
  if (item.key === "product_id")
    return (
      <RemoteEntitySelect
        label={t("Product")}
        value={String(value ?? "")}
        onChange={onChange}
        onSelect={onProductSelect}
        selectedOption={selectedProduct}
        load={productEntityLoader}
        required={required}
        placeholder={t("Search product name or barcode")}
      />
    );
  if (item.key === "customer_id" || item.key === "provider_id")
    return (
      <RemoteEntitySelect
        label={t(item.key === "customer_id" ? "Customer" : "Supplier")}
        value={String(value ?? "")}
        onChange={onChange}
        load={partyEntityLoader(
          item.key === "customer_id" ? "customer" : "supplier",
        )}
        placeholder={t(
          item.key === "customer_id"
            ? "Search customer name or phone"
            : "Search supplier name or phone",
        )}
      />
    );
  if (item.key === "batch_id")
    return (
      <RemoteEntitySelect
        label={t("Batch / Lot")}
        value={String(value ?? "")}
        onChange={onChange}
        onSelect={onBatchSelect}
        selectedOption={selectedBatch}
        reloadKey={`${filterValues.product_id || ""}:${filterValues.branch_id || "all"}`}
        load={batchEntityLoader(
          Number(filterValues.product_id || 0),
          Number(filterValues.branch_id || 0) || undefined,
        )}
        disabled={!filterValues.product_id}
        required={required}
        placeholder={t("Search batch number")}
      />
    );
  if (item.key === "user_id")
    return (
      <RemoteEntitySelect
        label={t("User")}
        value={String(value ?? "")}
        onChange={onChange}
        load={userEntityLoader}
        placeholder={t("Search team member name or email")}
      />
    );
  if (item.key === "party_id") {
    const agingPartyType =
      reportKey === "ar_aging"
        ? "customer"
        : reportKey === "ap_aging"
          ? "supplier"
          : undefined;
    const loader = async (q: string, page: number, signal: AbortSignal) => {
      const type =
          agingPartyType ||
          (partyType === "customer" || partyType === "supplier"
            ? partyType
            : undefined),
        r = await lookupsApi.parties(
          { q, party_type: type, page, per_page: 25 },
          signal,
        );
      return {
        options: r.data.map((x) => ({
          id: x.id,
          label: `${x.party_type === "customer" ? t("Customer") : t("Supplier")} — ${x.display_name}${x.phone_reference ? ` — ${x.phone_reference}` : ""}`,
        })),
        hasMore: page < (r.meta.last_page || 1),
      };
    };
    return (
      <RemoteEntitySelect
        label={t(
          agingPartyType === "customer"
            ? "Customer"
            : agingPartyType === "supplier"
              ? "Supplier"
              : "Customer or Supplier",
        )}
        value={String(value ?? "")}
        onChange={onChange}
        selectedOption={selectedParty}
        onSelect={(option) => {
          onPartySelect(option);
          onPartyType(
            agingPartyType ||
              (option.label.startsWith(t("Customer"))
                ? "customer"
                : "supplier"),
          );
        }}
        load={loader}
        placeholder={t("Search customer or supplier name or phone")}
      />
    );
  }
  if (item.key === "account_id") {
    const loader = async (q: string, page: number, signal: AbortSignal) => {
      const response = await lookupsApi.accounts(
        { q, page, per_page: 25 },
        signal,
      );
      return {
        options: response.data.map((account) => ({
          id: account.id,
          label: `${account.code} — ${account.name}`,
        })),
        hasMore: page < (response.meta.last_page || 1),
      };
    };
    return (
      <RemoteEntitySelect
        label={t("Account")}
        value={String(value ?? "")}
        onChange={onChange}
        load={loader}
        placeholder={t("Search by code or name")}
      />
    );
  }
  if (item.key === "party_type")
    return (
      <Field label={t("Party Type")}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{t("Customers and suppliers")}</option>
          <option value="customer">{t("Customers")}</option>
          <option value="supplier">{t("Suppliers")}</option>
        </select>
      </Field>
    );
  if (item.type === "boolean")
    return (
      <Field label={label(item.key)}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(event) =>
            onChange(
              event.target.value === "" ? "" : event.target.value === "true",
            )
          }
        >
          <option value="">{t("Any")}</option>
          <option value="true">{t("Yes")}</option>
          <option value="false">{t("No")}</option>
        </select>
      </Field>
    );
  if (item.key === "movement_type")
    return (
      <Field label={t("Movement Type")}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          {movementOptions.map(([v, l]) => (
            <option key={v} value={v}>
              {t(l)}
            </option>
          ))}
        </select>
      </Field>
    );
  if (item.key === "type") {
    const stockMovementTypes = [
      ["", "All movements"],
      ["OPENING_STOCK_IN", "Opening Stock"],
      ["PURCHASE_IN", "Purchase"],
      ["PURCHASE_RETURN_OUT", "Purchase Return"],
      ["SALE_OUT", "Sale"],
      ["SALES_RETURN_IN", "Sales Return"],
      ["PRESCRIPTION_DISPENSE_OUT", "Prescription Dispense"],
      ["ADJUSTMENT_IN", "Stock Adjustment In"],
      ["ADJUSTMENT_OUT", "Stock Adjustment Out"],
      ["TRANSFER_IN", "Transfer In"],
      ["TRANSFER_OUT", "Transfer Out"],
    ];
    return (
      <Field label={t("Movement Type")}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          {stockMovementTypes.map(([v, l]) => (
            <option key={v} value={v}>
              {t(l)}
            </option>
          ))}
        </select>
      </Field>
    );
  }
  if (item.key === "reference_type") {
    const referenceTypes = [
      ["", "All reference types"],
      ["purchase", "Purchase"],
      ["sale", "Sale"],
      ["sales_return", "Sales Return"],
      ["sales_cancel", "Sale Cancellation"],
      ["purchase_return", "Purchase Return"],
      ["stock_adjustment", "Stock Adjustment"],
      ["stock_count", "Stock Count"],
      ["stock_transfer", "Stock Transfer"],
      ["opening_balance", "Opening Balance"],
      ["prescription_dispense", "Prescription Dispense"],
    ];
    return (
      <Field label={t("Reference Type")}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          {referenceTypes.map(([v, l]) => (
            <option key={v} value={v}>
              {t(l)}
            </option>
          ))}
        </select>
      </Field>
    );
  }
  if (/status$/.test(item.key))
    return (
      <Field label={label(item.key)}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{t("All statuses")}</option>
          <option value="open">{t("Open")}</option>
          <option value="closed">{t("Closed")}</option>
          <option value="pending">{t("Pending")}</option>
          <option value="posted">{t("Posted")}</option>
          <option value="cancelled">{t("Cancelled")}</option>
          <option value="cleared">{t("Cleared")}</option>
          <option value="bounced">{t("Bounced")}</option>
        </select>
      </Field>
    );
  if (item.key === "direction")
    return (
      <Field label={t("Direction")}>
        <select
          className="input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{t("All directions")}</option>
          <option value="incoming">{t("Incoming")}</option>
          <option value="outgoing">{t("Outgoing")}</option>
        </select>
      </Field>
    );
  return (
    <Field label={label(item.key)}>
      <input
        className="input"
        type={type}
        step={type === "number" ? "any" : undefined}
        value={String(value ?? "")}
        onChange={(event) => onChange(event.target.value)}
      />
    </Field>
  );
}
function ColumnPicker({
  definition,
  columns,
  setColumns,
}: {
  definition: ReportDefinition;
  columns: string[];
  setColumns: (columns: string[]) => void;
}) {
  const { t } = useTranslation();
  return (
    <fieldset className="min-w-0 sm:col-span-2 xl:col-span-3 2xl:col-span-4">
      <legend className="text-sm font-medium mb-2">
        {t("Visible columns")}
      </legend>
      <div className="flex flex-wrap gap-2">
        {definition.allowed_columns.map((column) => (
          <label
            key={column}
            className={`px-3 py-2 rounded-lg border cursor-pointer text-sm ${columns.includes(column) ? "bg-teal-50 border-teal-300" : ""}`}
          >
            <input
              className="mr-2"
              type="checkbox"
              checked={columns.includes(column)}
              onChange={(event) =>
                setColumns(
                  event.target.checked
                    ? [...columns, column]
                    : columns.filter((item) => item !== column),
                )
              }
            />
            {label(column)}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
function Results({
  result,
  sort,
  direction,
  onSort,
}: {
  result: ReportQueryData;
  sort: string;
  direction: string;
  onSort: (column: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <section className="min-w-0 max-w-full space-y-4">
      {result.summary && Object.keys(result.summary).length > 0 && (
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {Object.entries(result.summary).map(([key, value]) => (
            <div key={key} className="bg-white border rounded-xl p-4">
              <p className="text-xs uppercase text-gray-500">{label(key)}</p>
              <strong className="text-xl">
                <bdi dir="ltr">{reportCell(key, value)}</bdi>
              </strong>
            </div>
          ))}
        </div>
      )}
      <div className="min-w-0 max-w-full overflow-hidden rounded-2xl border bg-white">
        <div className="p-5 border-b">
          <h2 className="font-bold text-lg">
            {t(`reportMode.${result.report.key}`, {
              defaultValue: t(result.report.label),
            })}
          </h2>
        </div>
        {result.rows.length ? result.report.key === "stock_movements" ? (
          <StockMovementResults rows={result.rows} />
        ) : (
          <div className="w-full max-w-full overflow-x-auto overscroll-x-contain">
            <table className="w-full min-w-max">
              <thead className="bg-gray-50">
                <tr>
                  {result.columns.map((column) => (
                    <th
                      key={column}
                      className="p-4 text-left text-xs uppercase whitespace-nowrap"
                    >
                      <button
                        className="flex gap-1 items-center"
                        onClick={() => onSort(column)}
                      >
                        {label(column)}{" "}
                        {sort === column && (
                          <span>{direction === "asc" ? "↑" : "↓"}</span>
                        )}
                      </button>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y">
                {result.rows.map((row, index) => (
                  <tr key={index} className="hover:bg-gray-50">
                    {result.columns.map((column) => (
                      <td
                        key={column}
                        className="p-4 text-sm whitespace-nowrap"
                      >
                        <span className={/(^|_)(date|expiry)|_at$/.test(column) ? "inline-block tabular-nums" : undefined}>
                          {renderReportValue(column, row[column], t)}
                        </span>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="p-12 text-center text-gray-500">
            {t("No results match the selected filters.")}
          </div>
        )}
      </div>
    </section>
  );
}

function StockMovementResults({ rows }: { rows: ReportQueryData["rows"] }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [expanded, setExpanded] = useState<number | null>(null);
  return (
    <div className="w-full max-w-full overflow-x-auto overscroll-x-contain">
      <table className="w-full min-w-[940px]">
        <thead className="bg-gray-50">
          <tr>
            <th>{label("created_at")}</th>
            <th>{t("Product")}</th>
            <th>{t("Batch / Lot")}</th>
            <th>{t("Movement Type")}</th>
            <th>{t("Signed Quantity")}</th>
            <th className="hidden lg:table-cell">{t("Branch")}</th>
            <th className="hidden xl:table-cell">{t("Unit Cost")}</th>
            <th>{t("Reference")}</th>
            <th className="hidden xl:table-cell">{t("Team Member")}</th>
            <th>{t("Details")}</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((row, index) => {
            const movementId = Number(row.movement_id || row.id || index);
            const signed = stockMovementSign(row.type) * Number(row.quantity || 0);
            const path = stockSourcePath(String(row.reference_type || ""), Number(row.reference_id));
            const isExpanded = expanded === movementId;
            return (
              <Fragment key={movementId}>
                <tr
                  className={path ? "cursor-pointer hover:bg-teal-50/50" : "hover:bg-gray-50/60"}
                  tabIndex={path ? 0 : undefined}
                  onClick={() => path && navigate(path)}
                  onKeyDown={(event) => {
                    if (path && (event.key === "Enter" || event.key === " ")) navigate(path);
                  }}
                >
                  <td><bdi dir="ltr" className="whitespace-nowrap text-sm tabular-nums">{formatMovementDate(row.created_at, i18n.language)}</bdi></td>
                  <td className="max-w-56"><bdi className="block truncate font-medium">{String(row.product_name || "—")}</bdi><bdi dir="ltr" className="block text-xs text-gray-500">{String(row.product_barcode || "—")}</bdi></td>
                  <td><bdi dir="ltr" className="block font-medium">{String(row.batch_number || "—")}</bdi><bdi dir="ltr" className="block text-xs text-gray-500">{row.batch_expiry ? String(row.batch_expiry).slice(0, 10).replaceAll("-", "/") : t("No expiry date")}</bdi></td>
                  <td><span className="inline-flex whitespace-nowrap rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{renderReportValue("type", row.type, t)}</span></td>
                  <td><bdi dir="ltr" className={`font-bold tabular-nums ${signed > 0 ? "text-emerald-700" : signed < 0 ? "text-rose-700" : "text-gray-700"}`}>{signed > 0 ? "+" : ""}{signed}</bdi></td>
                  <td className="hidden lg:table-cell"><span className="block max-w-44 truncate">{String(row.branch || t("Unknown branch"))}</span></td>
                  <td className="hidden xl:table-cell"><bdi dir="ltr">{row.unit_cost === null || row.unit_cost === undefined || row.unit_cost === "" ? "—" : reportCell("unit_cost", row.unit_cost)}</bdi></td>
                  <td className="max-w-56">
                    {path ? <Link to={path} onClick={(event) => event.stopPropagation()} className="block truncate font-semibold text-teal-700 hover:underline">{stockReferenceLabel(row, t)}</Link> : <span className="block truncate font-medium">{stockReferenceLabel(row, t)}</span>}
                    {row.reference && <bdi dir="ltr" className="block truncate text-xs text-gray-500">{String(row.reference)}</bdi>}
                  </td>
                  <td className="hidden xl:table-cell"><bdi>{String(row.user_name || t("Unknown user"))}</bdi></td>
                  <td><button type="button" className="text-sm font-semibold text-teal-700 hover:underline" aria-expanded={isExpanded} onClick={(event) => { event.stopPropagation(); setExpanded(isExpanded ? null : movementId); }}>{t(isExpanded ? "Hide details" : "View details")}</button></td>
                </tr>
                {isExpanded && (
                  <tr className="bg-gray-50/70">
                    <td colSpan={10} className="p-4">
                      <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <ReportDetail label={t("Movement ID")} value={row.movement_id || row.id} />
                        <ReportDetail label={t("Product ID")} value={row.product_id} />
                        <ReportDetail label={t("Batch ID")} value={row.batch_id} />
                        <ReportDetail label={t("Source ID")} value={row.reference_id} />
                        <ReportDetail label={t("Source Type")} value={renderReportValue("reference_type", row.reference_type, t)} />
                        <ReportDetail label={t("Branch ID")} value={row.branch_id} />
                        <ReportDetail label={t("Technical Reference")} value={row.reference || "—"} />
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
  );
}

function stockSourcePath(type: string, id: number): string | null {
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
    prescription_dispense: `/prescriptions/${id}`,
  };
  return paths[type.toLowerCase()] ?? null;
}

function stockMovementSign(type: ReportValue): number {
  const value = String(type || "").toUpperCase();
  return value.includes("OUT") || ["SALE", "PURCHASE_RETURN"].includes(value) ? -1 : 1;
}

function stockReferenceLabel(row: ReportQueryData["rows"][number], t: TFunction): string {
  if (row.reference) return String(row.reference);
  const type = renderReportValue("reference_type", row.reference_type, t);
  return t("Legacy source document", { type: typeof type === "string" ? type : t("Source") });
}

function formatMovementDate(value: ReportValue, locale: string): string {
  const date = new Date(String(value || ""));
  return Number.isNaN(date.getTime()) ? String(value || "—") : new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function ReportDetail({ label: heading, value }: { label: string; value: ReactNode }) {
  return <div><dt className="text-xs text-gray-500">{heading}</dt><dd className="mt-1 font-medium"><bdi dir="ltr">{value ?? "—"}</bdi></dd></div>;
}
function renderReportValue(
  key: string,
  value: ReportValue,
  t: TFunction,
): ReactNode {
  if (key === "batch_expiry" && (value === null || value === ""))
    return t("No expiry date");
  if (typeof value === "boolean") return t(value ? "Yes" : "No");
  if (key === "bucket") {
    const buckets: Record<string, string> = {
      current_not_due: "Current / Not Due",
      "0_30": "0–30 Days",
      "31_60": "31–60 Days",
      "61_90": "61–90 Days",
      over_90: "More than 90 Days",
    };
    return t(buckets[String(value)] || String(value));
  }
  if (key === "aging_basis")
    return t(
      String(value) === "invoice_allocation"
        ? "Invoice allocation"
        : "Pooled balance allocation",
    );
  if (key === "side")
    return t(String(value) === "customer" ? "Customer" : "Supplier");
  const stableValueLabels: Record<string, string> = {
    open: "Open",
    closed: "Closed",
    pending: "Pending",
    posted: "Posted",
    cancelled: "Cancelled",
    cleared: "Cleared",
    bounced: "Bounced",
    incoming: "Incoming",
    outgoing: "Outgoing",
    cash: "Cash",
    cheque: "Cheque",
    card: "Card",
    active: "Active",
    inactive: "Inactive",
    available: "Available",
    quarantined: "Quarantined",
    recalled: "Recalled",
    expired: "Expired",
    OPENING_IN: "Opening Stock",
    PURCHASE_IN: "Purchase",
    PURCHASE_RETURN_OUT: "Purchase Return",
    SALE_OUT: "Sale",
    SALES_RETURN_IN: "Sales Return",
    PRESCRIPTION_DISPENSE_OUT: "Prescription Dispense",
    ADJUSTMENT_IN: "Stock Adjustment In",
    ADJUSTMENT_OUT: "Stock Adjustment Out",
    STOCK_COUNT_IN: "Stock Count",
    OPENING_STOCK_IN: "Opening Stock",
    TRANSFER_IN: "Transfer In",
    TRANSFER_OUT: "Transfer Out",
  };
  const referenceLabels: Record<string, string> = {
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
    prescription: "Prescription Dispense",
  };
  if (
    ["reference_type", "source_type"].includes(key) &&
    referenceLabels[String(value)]
  )
    return t(referenceLabels[String(value)]);
  if (
    [
      "status",
      "direction",
      "movement_type",
      "type",
      "payment_method",
      "inventory_state",
      "disposition",
    ].includes(key) &&
    stableValueLabels[String(value)]
  )
    return t(stableValueLabels[String(value)]);
  const displayed = reportCell(key, value);
  if (["product_name", "branch", "user_name"].includes(key))
    return <bdi>{displayed}</bdi>;
  return /(^|_)(id|number|date|amount|balance|outstanding|settled|reference|barcode|phone|code)|age_days/.test(
    key,
  ) ? (
    <bdi dir="ltr">{displayed}</bdi>
  ) : (
    displayed
  );
}
function ErrorBox({ error }: { error: ApiClientError }) {
  const { t } = useTranslation();
  return (
    <div
      className={`p-4 border rounded-xl ${error.status === 409 ? "bg-amber-50 border-amber-200 text-amber-800" : "bg-red-50 border-red-200 text-red-700"}`}
    >
      <strong>
        {error.status === 403
          ? t("Permission denied")
          : error.status === 409
            ? t("Action required")
            : t("Report request failed")}
      </strong>
      <p>{error.message}</p>
      {Object.entries(error.errors).map(([field, messages]) => (
        <p className="text-sm" key={field}>
          {label(field)}: {messages.join(" ")}
        </p>
      ))}
    </div>
  );
}
