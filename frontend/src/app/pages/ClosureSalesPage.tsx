import { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { ChevronDown, Info, Plus, ScanLine, Trash2 } from "lucide-react";
import { useNavigate } from "react-router";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { productsApi } from "../../api/products";
import { salesApi } from "../../api/sales";
import { useAuth } from "../../auth/useAuth";
import type { SalesPaymentMethod } from "../../types/sales";
import type { SalesPayload } from "../../types/sales";
import { formatMoney } from "../../utils/batchFormatters";
import { ErrorNotice, normalizeError } from "../components/ErpPrimitives";
import { Field, PageHeader, RemoteEntitySelect } from "../components/UxPrimitives";
import { partyEntityLoader, productEntityLoader } from "../entityLoaders";
import { useTranslation } from "react-i18next";
type SaleLine = {
  key: number;
  product_id: string;
  barcode?: string;
  product_label?: string;
  quantity: string;
  unit_id: string;
  configured_price: string;
  price: string;
  priceConfigured: boolean;
  discount_type: "fixed" | "percentage";
  discount_value: string;
  discount_reason: string;
  override_reason: string;
  advanced: boolean;
  units: JsonRecord[];
  unitsLoading: boolean;
};
type InvoiceDiscountType = "none" | "fixed" | "percentage";
const newLine = (key = Date.now()): SaleLine => ({
  key,
  product_id: "",
  quantity: "1",
  unit_id: "",
  configured_price: "",
  price: "",
  priceConfigured: false,
  discount_type: "fixed",
  discount_value: "0",
  discount_reason: "",
  override_reason: "",
  advanced: false,
  units: [],
  unitsLoading: false,
});
export function ClosureSalesPage() {
  const { t } = useTranslation();
  const navigate = useNavigate(),
    { hasPermission } = useAuth();
  const [lines, setLines] = useState<SaleLine[]>([newLine(1)]);
  const [customerId, setCustomerId] = useState(""),
    [date, setDate] = useState(new Date().toISOString().slice(0, 10)),
    [paymentMethod, setPaymentMethod] = useState<SalesPaymentMethod>("cash"),
    [paid, setPaid] = useState("0");
  const [cheque, setCheque] = useState({ number: "", bank: "", dueDate: "" });
  const [invoiceDiscountType, setInvoiceDiscountType] =
      useState<InvoiceDiscountType>("none"),
    [invoiceDiscountValue, setInvoiceDiscountValue] = useState(""),
    [invoiceDiscountReason, setInvoiceDiscountReason] = useState("");
  const [, setCreditAdvanced] = useState(false);
  const [credit, setCredit] = useState<JsonRecord | null>(null),
    [creditOverride, setCreditOverride] = useState(false),
    [creditReason, setCreditReason] = useState("");
  const [barcode, setBarcode] = useState(""),
    [loadingBarcode, setLoadingBarcode] = useState(false),
    [submitting, setSubmitting] = useState(false),
    [error, setError] = useState<any>(null),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const unitRequests = useRef(new Map<number, number>());
  const priceHistoryCache = useRef(new Map<string, Promise<JsonRecord>>());
  const paymentMethodReady = useRef(false);
  useEffect(() => {
    if (!customerId) {
      setCredit(null);
      return;
    }
    erpApi
      .customer360(Number(customerId))
      .then((r) => setCredit(r.data))
      .catch((v) =>
        setError(
          normalizeError(v, t("Customer credit information could not be loaded.")),
        ),
      );
  }, [customerId]);
  useEffect(() => {
    if (!paymentMethodReady.current) {
      paymentMethodReady.current = true;
      return;
    }
    setCheque({ number: "", bank: "", dueDate: "" });
    setFieldErrors((current) =>
      Object.fromEntries(
        Object.entries(current).filter(([key]) => !key.startsWith("cheque_")),
      ),
    );
  }, [paymentMethod]);
  const calculation = useMemo(
    () =>
      lines.reduce(
        (a, line) => {
          const gross = Number(line.quantity || 0) * Number(line.price || 0),
            discount =
              line.discount_type === "percentage"
                ? (gross * Math.min(100, Number(line.discount_value || 0))) /
                  100
                : Number(line.discount_value || 0);
          a.subtotal += gross;
          a.discount += Math.min(gross, discount);
          return a;
        },
        {
          subtotal: 0,
          discount: 0,
        },
      ),
    [lines],
  );
  const afterLineDiscounts = Math.max(
      0,
      calculation.subtotal - calculation.discount,
    ),
    requestedInvoiceDiscount =
      invoiceDiscountType === "percentage"
        ? Math.round(afterLineDiscounts * Number(invoiceDiscountValue || 0)) /
          100
        : invoiceDiscountType === "fixed"
          ? Number(invoiceDiscountValue || 0)
          : 0,
    invoiceDiscount = Math.min(
      afterLineDiscounts,
      Math.max(0, requestedInvoiceDiscount),
    ),
    total = Math.max(0, afterLineDiscounts - invoiceDiscount),
    balance = Math.max(0, total - Number(paid || 0));
  const creditState = credit?.credit_state || {},
    configuredLimit =
      creditState.limit === null || creditState.limit === undefined
        ? null
        : Number(creditState.limit),
    currentReceivable = Math.max(0, Number(credit?.current_balance || 0)),
    projectedReceivable = currentReceivable + balance,
    creditExceeded =
      configuredLimit !== null &&
      balance > 0 &&
      projectedReceivable > configuredLimit;
  useEffect(() => {
    if (!creditExceeded) {
      setCreditOverride(false);
      setCreditReason("");
    }
  }, [creditExceeded]);
  function patchLine(key: number, patch: Partial<SaleLine>) {
    setLines((current) =>
      current.map((line) =>
        line.key === key
          ? {
              ...line,
              ...patch,
            }
          : line,
      ),
    );
  }
  function applyUnit(
    key: number,
    unit: JsonRecord | undefined,
    units?: JsonRecord[],
  ) {
    const raw = unit?.sale_price,
      price =
        raw === null || raw === undefined || raw === "" ? NaN : Number(raw);
    if (!unit || !Number.isFinite(price) || price < 0) {
      patchLine(key, {
        ...(units
          ? {
              units,
            }
          : {}),
        unit_id: String(unit?.unit_id || unit?.id || ""),
        configured_price: "",
        price: "",
        priceConfigured: false,
        unitsLoading: false,
      });
      setError(
        normalizeError(
          new Error(t("No sale price is configured for this unit.")),
          t("No sale price is configured for this unit."),
        ),
      );
      return;
    }
    const value = String(price);
    patchLine(key, {
      ...(units
        ? {
            units,
          }
        : {}),
      unit_id: String(unit.unit_id || unit.id || ""),
      configured_price: value,
      price: value,
      priceConfigured: true,
      unitsLoading: false,
    });
  }
  async function selectProduct(key: number, productId: string) {
    const request = (unitRequests.current.get(key) || 0) + 1;
    unitRequests.current.set(key, request);
    patchLine(key, {
      product_id: productId,
      unit_id: "",
      configured_price: "",
      price: "",
      priceConfigured: false,
      units: [],
      unitsLoading: Boolean(productId),
      barcode: undefined,
    });
    if (!productId) return;
    try {
      const response = await erpApi.productUnits(Number(productId));
      if (unitRequests.current.get(key) !== request) return;
      const units = response.data.filter((x) => x.is_sale_unit && x.is_active),
        base =
          units.find((x) => Boolean(x.is_base)) ||
          units.find((x) => Number(x.base_quantity) === 1) ||
          units[0];
      applyUnit(key, base, units);
    } catch (v) {
      if (unitRequests.current.get(key) !== request) return;
      patchLine(key, {
        unitsLoading: false,
      });
      setError(
        normalizeError(
          v,
          t("Packaging choices could not be loaded for this product."),
        ),
      );
    }
  }
  function selectUnit(key: number, value: string) {
    const line = lines.find((x) => x.key === key),
      unit = line?.units.find((x) => String(x.unit_id || x.id) === value);
    applyUnit(key, unit);
  }
  async function scan() {
    if (!barcode.trim()) return;
    setLoadingBarcode(true);
    try {
      const r = await productsApi.lookupBarcode(barcode.trim()),
        line = newLine();
      line.product_id = String(r.data.product.id);
      line.barcode = r.data.product.barcode;
      line.product_label = `${r.data.product.name}${r.data.product.barcode ? ` — ${r.data.product.barcode}` : ""}`;
      setLines((current) => [...current.filter((x) => x.product_id), line]);
      setBarcode("");
      await selectProduct(line.key, line.product_id);
    } catch (v) {
      setError(
        normalizeError(v, t("No sellable product was found for this barcode.")),
      );
    } finally {
      setLoadingBarcode(false);
    }
  }
  function validate() {
    const e: Record<string, string> = {};
    if (!customerId) e.customer_id = t("Choose a customer.");
    if (!date) e.date = t("Sale date is required.");
    if (!lines.length) e.items = t("Add at least one product.");
    if (invoiceDiscountType !== "none") {
      const value = Number(invoiceDiscountValue);
      if (!Number.isFinite(value) || value < 0)
        e.invoice_discount_value = t("Enter a non-negative discount value.");
      else if (invoiceDiscountType === "percentage" && value > 100)
        e.invoice_discount_value = t("Percentage cannot exceed 100.");
      else if (
        requestedInvoiceDiscount >= afterLineDiscounts &&
        requestedInvoiceDiscount > 0
      )
        e.invoice_discount_value = t("Invoice discount must leave a positive invoice total.");
      if (requestedInvoiceDiscount > 0 && !invoiceDiscountReason.trim())
        e.invoice_discount_reason =
          t("Explain why this invoice discount is being given.");
    }
    lines.forEach((line, i) => {
      if (!line.product_id) e[`items.${i}.product_id`] = t("Choose a product.");
      if (!Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1)
        e[`items.${i}.quantity`] = t("Enter a positive whole quantity.");
      if (!line.priceConfigured)
        e[`items.${i}.price`] = t("No sale price is configured for this unit.");
      else if (Number(line.price) < 0)
        e[`items.${i}.price`] = t("Price cannot be negative.");
      if (Number(line.discount_value) < 0)
        e[`items.${i}.discount_value`] = t("Discount cannot be negative.");
      if (
        Number(line.discount_value) > 0 &&
        hasPermission("sales.discount") &&
        !line.discount_reason.trim()
      )
        e[`items.${i}.discount_reason`] = t(
          "Explain why this discount is being given.",
        );
    });
    if (Number(paid) < 0 || Number(paid) > total)
      e.paid_amount = t("Amount paid must be between zero and the invoice total.");
    if (paymentMethod === "cheque" && Number(paid) <= 0)
      e.paid_amount = t("Enter the cheque amount.");
    if (paymentMethod === "cheque" && Number(paid) > 0) {
      if (!cheque.number.trim())
        e.cheque_number = t("Cheque number is required.");
      else if (cheque.number.trim().length > 100)
        e.cheque_number = t("Cheque number cannot exceed 100 characters.");
      if (!cheque.bank.trim()) e.cheque_bank = t("Bank is required.");
      else if (cheque.bank.trim().length > 150)
        e.cheque_bank = t("Bank cannot exceed 150 characters.");
      if (!cheque.dueDate) e.cheque_due_date = t("Due date is required.");
    }
    if (creditOverride && !creditReason.trim())
      e.credit_override_reason = t("A credit override reason is required.");
    if (creditExceeded && !creditOverride)
      e.credit_limit = hasPermission("customer.credit_override")
        ? t(
            t("Allow this sale despite the credit limit or make the sale fully paid."),
          )
        : t("This sale would exceed the customer's credit limit.");
    return e;
  }
  async function submit() {
    const errors = validate();
    setFieldErrors(errors);
    if (Object.keys(errors).length) return;
    setSubmitting(true);
    setError(null);
    try {
      const items = lines.map((line) => {
        const gross = Number(line.quantity) * Number(line.price),
          discount =
            line.discount_type === "percentage"
              ? (gross * Math.min(100, Number(line.discount_value || 0))) / 100
              : Number(line.discount_value || 0);
        const item: any = {
          product_id: Number(line.product_id),
          amount: Number(line.quantity),
          unit_id: line.unit_id ? Number(line.unit_id) : null,
          price: Number(line.price),
          discount: Math.min(gross, discount),
        };
        if (hasPermission("sales.discount"))
          Object.assign(item, {
            discount_type: line.discount_type,
            discount_value: Number(line.discount_value || 0),
            discount_reason: line.discount_reason || null,
          });
        if (
          hasPermission("sales.override_price") &&
          line.override_reason.trim()
        )
          item.override_reason = line.override_reason.trim();
        return item;
      });
      const hasChequePayment = paymentMethod === "cheque" && Number(paid) > 0;
      const payload: SalesPayload = {
        customer_id: Number(customerId),
        date,
        discount: 0,
        paid_amount: Number(paid || 0),
        payment_method:
          paymentMethod === "cheque" && !hasChequePayment
            ? "cash"
            : paymentMethod,
        items,
        invoice_discount_type:
          invoiceDiscountType === "none" ? "fixed" : invoiceDiscountType,
        invoice_discount_value:
          invoiceDiscountType === "none"
            ? 0
            : Number(invoiceDiscountValue || 0),
        invoice_discount_reason:
          invoiceDiscountType === "none"
            ? undefined
            : invoiceDiscountReason.trim() || undefined,
        credit_override_reason: creditOverride
          ? creditReason.trim()
          : undefined,
        ...(hasChequePayment
          ? {
              cheque_number: cheque.number.trim(),
              cheque_bank: cheque.bank.trim(),
              cheque_due_date: cheque.dueDate,
            }
          : {}),
      };
      const r = await salesApi.create(payload);
      navigate(`/sales/${r.data.id}`, {
        replace: true,
        state: {
          success: t("Sale completed. Stock was allocated automatically by expiry."),
        },
      });
    } catch (v) {
      setError(
        normalizeError(
          v,
          t("The sale could not be completed. Review stock, pricing, discounts, payment, and customer credit."),
        ),
      );
    } finally {
      setSubmitting(false);
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title={t("Create Sale")}
        description={t("Create a customer sale. Stock is allocated automatically by expiry.")}
      />
      <ErrorNotice error={error} />
      <section className="bg-white border rounded-2xl p-4 grid gap-4 md:grid-cols-2">
        <RemoteEntitySelect required label={t("Customer")} value={customerId} onChange={setCustomerId} load={partyEntityLoader("customer")} placeholder={t("Search customer name or phone")} error={fieldErrors.customer_id} />
        <Field required label={t("Sale Date")}>
          <input
            className="input"
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
        </Field>
      </section>
      {credit && (
        <section
          className={`rounded-xl border p-3 ${creditExceeded ? "bg-amber-50 border-amber-300" : "bg-white"}`}
        >
          <div className="grid grid-cols-3 gap-3 text-sm">
            <Metric
              label={t("Current Balance")}
              value={formatMoney(currentReceivable)}
            />
            <Metric
              label={t("Credit Limit")}
              value={
                configuredLimit === null
                  ? t("Not configured")
                  : configuredLimit === 0
                    ? `${formatMoney(0)} — ${t("No credit allowed")}`
                    : formatMoney(configuredLimit)
              }
            />
            <Metric
              label={t("Available Credit")}
              value={
                configuredLimit === null
                  ? t("Not configured")
                  : formatMoney(
                      Math.max(0, configuredLimit - currentReceivable),
                    )
              }
            />
          </div>
          {creditExceeded && (
            <div className="mt-3">
              <p className="text-sm font-semibold text-amber-900">
                {t("This sale would exceed the customer's credit limit.")}
              </p>
              <p className="text-xs text-amber-800 mt-1">
                {t("Projected receivable:")} {formatMoney(projectedReceivable)}
              </p>
              {hasPermission("customer.credit_override") && (
                <label className="mt-3 flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={creditOverride}
                    onChange={(e) => {
                      setCreditOverride(e.target.checked);
                      setCreditAdvanced(e.target.checked);
                      if (!e.target.checked) setCreditReason("");
                    }}
                  />{" "}
                  {t("Allow this sale despite the credit limit")}
                </label>
              )}
              {creditOverride && hasPermission("customer.credit_override") && (
                <div className="mt-3 max-w-xl">
                  <Field required label={t("Override Reason")}>
                    <input
                      className="input"
                      value={creditReason}
                      onChange={(e) => setCreditReason(e.target.value)}
                    />
                    {fieldErrors.credit_override_reason && (
                      <small className="text-red-600">
                        {fieldErrors.credit_override_reason}
                      </small>
                    )}
                  </Field>
                </div>
              )}
              {fieldErrors.credit_limit && (
                <p className="mt-2 text-sm text-red-700">
                  {fieldErrors.credit_limit}
                </p>
              )}
            </div>
          )}
        </section>
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void scan();
        }}
        className="bg-teal-50 border rounded-xl p-3 flex flex-wrap gap-2"
      >
        <label className="flex-1 min-w-56">
          <span className="sr-only">{t("Scan or enter barcode")}</span>
          <input
            autoFocus
            data-bidi="ltr"
            className="input bg-white"
            placeholder={t("Scan or enter barcode")}
            value={barcode}
            onChange={(e) => setBarcode(e.target.value)}
          />
        </label>
        <button className="report-action primary" disabled={loadingBarcode}>
          <ScanLine size={17} />
          {loadingBarcode ? t("Looking Up…") : t("Add Scanned Product")}
        </button>
      </form>
      <section className="bg-white border rounded-2xl overflow-hidden">
        <div className="p-4 border-b flex flex-wrap justify-between gap-3">
          <div>
            <h2 className="font-bold text-lg">{t("Products")}</h2>
            <p className="text-sm text-gray-500">
              {t("Each row is an independent invoice line. Batch selection remains automatic.")}
            </p>
          </div>
          <button
            className="report-action primary"
            onClick={() => setLines((x) => [...x, newLine()])}
          >
            <Plus size={17} />
            {t("Add Product")}
          </button>
        </div>
        {fieldErrors.items && (
          <p className="p-3 text-red-600">{fieldErrors.items}</p>
        )}
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1050px] table-fixed">
            <colgroup>
              <col className="w-[30%]" />
              <col className="w-[9%]" />
              <col className="w-[18%]" />
              <col className="w-[12%]" />
              <col className="w-[13%]" />
              <col className="w-[13%]" />
              <col className="w-[5%]" />
            </colgroup>
            <thead>
              <tr>
                <th className="text-start">{t("Product")}</th>
                <th className="text-center">{t("Quantity")}</th>
                <th className="text-center">{t("Unit / Packaging")}</th>
                <th className="text-center">{t("Unit Price")}</th>
                <th className="text-center">{t("Discount")}</th>
                <th className="text-center">{t("Line Total")}</th>
                <th className="text-center">{t("Remove")}</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line, index) => (
                <SaleRow
                  key={line.key}
                  line={line}
                  index={index}
                  permissions={{
                    discount: hasPermission("sales.discount"),
                    override: hasPermission("sales.override_price"),
                  }}
                  errors={fieldErrors}
                  customerId={customerId}
                  historyCache={priceHistoryCache.current}
                  patch={(p) =>
                    "unit_id" in p
                      ? selectUnit(line.key, String(p.unit_id ?? ""))
                      : patchLine(line.key, p)
                  }
                  selectProduct={(v) => void selectProduct(line.key, v)}
                  remove={() =>
                    setLines((x) => x.filter((y) => y.key !== line.key))
                  }
                />
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <div className="grid lg:grid-cols-[1fr_360px] gap-5">
        <section className="bg-white border rounded-2xl p-4">
          <h2 className="font-bold mb-4">{t("Payment")}</h2>
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={t("Payment Method")}>
              <select
                className="input"
                value={paymentMethod}
                onChange={(e) =>
                  setPaymentMethod(e.target.value as SalesPaymentMethod)
                }
              >
                <option value="cash">{t("Cash")}</option>
                <option value="cheque">{t("Cheque")}</option>
                <option value="visa">{t("Card")}</option>
              </select>
            </Field>
            <Field label={t("Amount Paid")}>
              <input
                className="input"
                type="number"
                min="0"
                step=".01"
                value={paid}
                onChange={(e) => setPaid(e.target.value)}
              />
              {fieldErrors.paid_amount && (
                <small className="text-red-600">
                  {fieldErrors.paid_amount}
                </small>
              )}
            </Field>
          </div>
          {paymentMethod === "cheque" && (
            <div className="mt-5 border-t pt-4">
              <h3 className="font-bold text-lg">{t("Cheque Details")}</h3>
              <p className="mb-4 text-sm text-gray-600">
                {t("The incoming cheque amount equals the Amount Paid above.")}
              </p>
              <div className="grid gap-4 sm:grid-cols-3">
                <Field required label={t("Cheque Number")}>
                  <input
                    data-bidi="ltr"
                    className="input"
                    maxLength={100}
                    value={cheque.number}
                    onChange={(e) =>
                      setCheque((current) => ({
                        ...current,
                        number: e.target.value,
                      }))
                    }
                  />
                  {fieldErrors.cheque_number && (
                    <small className="text-red-600">
                      {fieldErrors.cheque_number}
                    </small>
                  )}
                </Field>
                <Field required label={t("Bank")}>
                  <input
                    className="input"
                    maxLength={150}
                    value={cheque.bank}
                    onChange={(e) =>
                      setCheque((current) => ({
                        ...current,
                        bank: e.target.value,
                      }))
                    }
                  />
                  {fieldErrors.cheque_bank && (
                    <small className="text-red-600">
                      {fieldErrors.cheque_bank}
                    </small>
                  )}
                </Field>
                <Field required label={t("Due Date")}>
                  <input
                    className="input"
                    type="date"
                    value={cheque.dueDate}
                    onChange={(e) =>
                      setCheque((current) => ({
                        ...current,
                        dueDate: e.target.value,
                      }))
                    }
                  />
                  {fieldErrors.cheque_due_date && (
                    <small className="text-red-600">
                      {fieldErrors.cheque_due_date}
                    </small>
                  )}
                </Field>
              </div>
            </div>
          )}
        </section>
        <section className="bg-white border rounded-2xl p-4 space-y-3">
          <h2 className="font-bold">{t("Invoice Summary")}</h2>
          {hasPermission("sales.discount") && (
            <div className="rounded-xl border bg-gray-50 p-3 space-y-3">
              <h3 className="font-semibold">{t("Invoice Discount")}</h3>
              <Field label={t("Discount Type")}>
                <select
                  className="input"
                  value={invoiceDiscountType}
                  onChange={(e) => {
                    setInvoiceDiscountType(
                      e.target.value as InvoiceDiscountType,
                    );
                    setInvoiceDiscountValue("");
                    setInvoiceDiscountReason("");
                  }}
                >
                  <option value="none">{t("None")}</option>
                  <option value="fixed">{t("Fixed Amount")}</option>
                  <option value="percentage">{t("Percentage")}</option>
                </select>
              </Field>
              {invoiceDiscountType !== "none" && (
                <>
                  <Field label={t("Discount Value")}>
                    <input
                      className="input"
                      type="number"
                      min="0"
                      max={
                        invoiceDiscountType === "percentage" ? 100 : undefined
                      }
                      step=".01"
                      value={invoiceDiscountValue}
                      onChange={(e) => setInvoiceDiscountValue(e.target.value)}
                    />
                    {fieldErrors.invoice_discount_value && (
                      <small className="text-red-600">
                        {fieldErrors.invoice_discount_value}
                      </small>
                    )}
                  </Field>
                  <Field required label={t("Discount Reason")}>
                    <input
                      className="input"
                      maxLength={255}
                      value={invoiceDiscountReason}
                      onChange={(e) => setInvoiceDiscountReason(e.target.value)}
                    />
                    {fieldErrors.invoice_discount_reason && (
                      <small className="text-red-600">
                        {fieldErrors.invoice_discount_reason}
                      </small>
                    )}
                  </Field>
                </>
              )}
            </div>
          )}
          <Summary label={t("Subtotal")} value={calculation.subtotal} />
          <Summary label={t("Line Discounts")} value={-calculation.discount} />
          <Summary label={t("Invoice Discount")} value={-invoiceDiscount} />
          <Summary label={t("Total")} value={total} strong />
          <Summary label={t("Amount Paid")} value={Number(paid || 0)} />
          <Summary label={t("Balance Due")} value={balance} strong />
          <button
            className="report-action primary w-full mt-3"
            disabled={submitting}
            onClick={() => void submit()}
          >
            {submitting ? t("Completing Sale…") : t("Complete Sale")}
          </button>
        </section>
      </div>
    </div>
  );
}
function SaleRow({
  line,
  index,
  permissions,
  errors,
  customerId,
  historyCache,
  patch,
  selectProduct,
  remove,
}: {
  line: SaleLine;
  index: number;
  permissions: {
    discount: boolean;
    override: boolean;
  };
  errors: Record<string, string>;
  customerId: string;
  historyCache: Map<string, Promise<JsonRecord>>;
  patch: (p: Partial<SaleLine>) => void;
  selectProduct: (v: string) => void;
  remove: () => void;
}) {
  const { t } = useTranslation();
  const [priceHistory, setPriceHistory] = useState<JsonRecord | null>(null);
  const historyRequest = useRef(0);
  const unit = line.units.find(
      (x) => String(x.unit_id || x.id) === line.unit_id,
    ),
    gross = Number(line.quantity || 0) * Number(line.price || 0),
    discount =
      line.discount_type === "percentage"
        ? (gross * Math.min(100, Number(line.discount_value || 0))) / 100
        : Number(line.discount_value || 0);
  useEffect(() => {
    const generation = historyRequest.current + 1;
    historyRequest.current = generation;
    setPriceHistory(null);
    if (!customerId || !line.product_id || !line.unit_id) return;
    const key = `${customerId}:${line.product_id}:${line.unit_id}`;
    let request = historyCache.get(key);
    if (!request) {
      request = salesApi.priceHistory(Number(customerId), Number(line.product_id), Number(line.unit_id)).then((response) => response.data);
      historyCache.set(key, request);
      request.catch(() => historyCache.delete(key));
    }
    request.then((history) => {
      if (historyRequest.current === generation) setPriceHistory(history);
    }).catch(() => undefined);
  }, [customerId, line.product_id, line.unit_id, historyCache]);
  return (
    <>
      <tr className="align-top">
        <Cell error={errors[`items.${index}.product_id`]}>
          <RemoteEntitySelect label="" inputClassName="cell-input h-11" value={line.product_id} onChange={selectProduct} onSelect={(option) => patch({ product_label: option.label })} selectedOption={line.product_label ? { id: line.product_id, label: line.product_label } : null} load={productEntityLoader} placeholder={t("Search product name or barcode")} />
        </Cell>
        <Cell error={errors[`items.${index}.quantity`]}>
          <input
            aria-label={t("Quantity")}
            className="cell-input h-11 w-full text-center"
            type="number"
            min="1"
            step="1"
            value={line.quantity}
            onChange={(e) =>
              patch({
                quantity: e.target.value,
              })
            }
          />
        </Cell>
        <Cell>
          <select
            aria-label={t("Unit or packaging")}
            className="cell-input h-11 w-full"
            disabled={line.unitsLoading}
            value={line.unit_id}
            onChange={(e) =>
              patch({
                unit_id: e.target.value,
              })
            }
          >
            <option value="">
              {line.unitsLoading ? t("Loading…") : t("Base unit")}
            </option>
            {line.units.map((x) => (
              <option data-i18n-skip key={x.id} value={x.unit_id || x.id}>
                {x.unit_name || x.name || t("Configured package")}
              </option>
            ))}
          </select>
          {unit && (
            <small className="mt-1 block break-words text-xs leading-4 text-gray-500">
              {t("sales.unitsConversion", {
                quantity: line.quantity,
                unit: unit.unit_name || t("packages"),
                baseQuantity: Number(line.quantity) * Number(unit.base_quantity || 1),
                defaultValue: "{{quantity}} {{unit}} = {{baseQuantity}} base units",
              })}
            </small>
          )}
        </Cell>
        <Cell error={errors[`items.${index}.price`]}>
          <div className="flex items-center gap-1">
            <input
              aria-label={t("Unit price")}
              className="cell-input h-11 min-w-0 flex-1 text-center"
              type="number"
              min="0"
              step=".01"
              value={line.price}
              onChange={(e) => patch({ price: e.target.value })}
            />
            {customerId && line.product_id && line.unit_id && priceHistory && <PriceHistoryPopover history={priceHistory} />}
          </div>
        </Cell>
        <Cell>
          <button
            className="cell-input flex h-11 w-full items-center justify-center gap-1 text-sm text-teal-700"
            onClick={() =>
              patch({
                advanced: !line.advanced,
              })
            }
          >
            {Number(line.discount_value) > 0
              ? `${line.discount_type === "percentage" ? `${line.discount_value}%` : formatMoney(line.discount_value)}`
              : t("None")}
            <ChevronDown size={15} />
          </button>
        </Cell>
        <td className="align-top">
          <div className="flex h-11 items-center justify-center whitespace-nowrap font-bold text-gray-900">
            {formatMoney(Math.max(0, gross - discount))}
          </div>
        </td>
        <td className="align-top">
          <div className="flex h-11 items-center justify-center">
            <button
              aria-label={t("Remove product line")}
              title={t("Remove product")}
              className="rounded-lg p-2 text-red-600 hover:bg-red-50"
              onClick={remove}
            >
              <Trash2 size={17} />
            </button>
          </div>
        </td>
      </tr>
      {line.advanced && (
        <tr>
          <td colSpan={7} className="bg-gray-50">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {permissions.discount && (
                <>
                  <Field label={t("Discount Type")}>
                    <select
                      className="input"
                      value={line.discount_type}
                      onChange={(e) =>
                        patch({
                          discount_type: e.target
                            .value as SaleLine["discount_type"],
                        })
                      }
                    >
                      <option value="fixed">{t("Fixed amount")}</option>
                      <option value="percentage">{t("Percentage")}</option>
                    </select>
                  </Field>
                  <Field label={t("Discount Value")}>
                    <input
                      className="input"
                      type="number"
                      min="0"
                      value={line.discount_value}
                      onChange={(e) =>
                        patch({
                          discount_value: e.target.value,
                        })
                      }
                    />
                  </Field>
                  <Field label={t("Discount Reason")}>
                    <input
                      className="input"
                      value={line.discount_reason}
                      onChange={(e) =>
                        patch({
                          discount_reason: e.target.value,
                        })
                      }
                    />
                    {errors[`items.${index}.discount_reason`] && (
                      <small className="text-red-600">
                        {errors[`items.${index}.discount_reason`]}
                      </small>
                    )}
                  </Field>
                </>
              )}
              {permissions.override && (
                <Field
                  label={t("Price Override Reason")}
                  help={t("Required when using a price different from the product's normal sale price.")}
                >
                  <input
                    className="input"
                    value={line.override_reason}
                    onChange={(e) =>
                      patch({
                        override_reason: e.target.value,
                      })
                    }
                  />
                </Field>
              )}
              {!permissions.discount && !permissions.override && (
                <p className="text-sm text-gray-500">
                  {t("No advanced pricing controls are available for your role.")}
                </p>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  );
}
function PriceHistoryPopover({ history }: { history: JsonRecord }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const anchorRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const closeTimer = useRef<number | null>(null);
  const pinned = useRef(false);
  const panelId = useMemo(() => `price-history-${Math.random().toString(36).slice(2)}`, []);

  function cancelClose() {
    if (closeTimer.current !== null) window.clearTimeout(closeTimer.current);
    closeTimer.current = null;
  }
  function scheduleClose() {
    if (pinned.current) return;
    cancelClose();
    closeTimer.current = window.setTimeout(() => setOpen(false), 120);
  }
  function updatePosition() {
    const anchor = anchorRef.current?.getBoundingClientRect();
    if (!anchor) return;
    const margin = 8, gap = 6;
    const width = panelRef.current?.offsetWidth || 256;
    const height = panelRef.current?.offsetHeight || 150;
    const roomBelow = window.innerHeight - anchor.bottom;
    const top = roomBelow >= height + gap + margin
      ? anchor.bottom + gap
      : Math.max(margin, anchor.top - height - gap);
    const rtl = document.documentElement.dir === "rtl";
    const preferredLeft = rtl ? anchor.right - width : anchor.left;
    const left = Math.min(window.innerWidth - width - margin, Math.max(margin, preferredLeft));
    setPosition((current) => current.top === top && current.left === left ? current : { top, left });
  }

  useLayoutEffect(() => {
    if (!open) return;
    updatePosition();
    const reposition = () => updatePosition();
    window.addEventListener("resize", reposition);
    window.addEventListener("scroll", reposition, true);
    return () => {
      window.removeEventListener("resize", reposition);
      window.removeEventListener("scroll", reposition, true);
    };
  }, [open]);
  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {
      const target = event.target as Node;
      if (!anchorRef.current?.contains(target) && !panelRef.current?.contains(target)) { pinned.current = false; setOpen(false); }
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === "Escape") { pinned.current = false; setOpen(false); anchorRef.current?.focus(); }
    };
    document.addEventListener("pointerdown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);
  useEffect(() => () => cancelClose(), []);

  return <>
    <button
      ref={anchorRef}
      type="button"
      className="flex size-7 shrink-0 items-center justify-center rounded-full text-teal-700 hover:bg-teal-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600"
      aria-label={t("Customer price history")}
      aria-expanded={open}
      aria-controls={open ? panelId : undefined}
      title={t("Customer price history")}
      onMouseEnter={() => { cancelClose(); setOpen(true); }}
      onMouseLeave={scheduleClose}
      onFocus={() => { cancelClose(); setOpen(true); }}
      onBlur={scheduleClose}
      onClick={() => { pinned.current = !pinned.current; setOpen(pinned.current); }}
    ><Info size={16} /></button>
    {open && createPortal(
      <div
        ref={panelRef}
        id={panelId}
        role="tooltip"
        dir={document.documentElement.dir || "ltr"}
        className="fixed z-[1000] w-64 rounded-xl border border-white/60 bg-white/90 p-3 text-start text-xs leading-6 text-gray-700 shadow-md backdrop-blur-sm"
        style={{ top: position.top, left: position.left }}
        onMouseEnter={cancelClose}
        onMouseLeave={scheduleClose}
      >
        <strong className="block text-sm text-gray-900">{t("Customer price history")}</strong>
        {history.available ? <>
          <div>{t("Last price")}: <b>{formatMoney(history.last_price)}</b></div>
          <div>{t("Recent range")}: <b>{formatMoney(history.min_price)} – {formatMoney(history.max_price)}</b></div>
          <div>{t("Last 5 prices")}: <b>{history.recent_prices.map((item: JsonRecord) => formatMoney(item.price)).join(", ")}</b></div>
          <div>{t("Last sale")}: <b data-bidi="ltr" dir="ltr">{history.recent_prices[0]?.date}</b></div>
        </> : <p className="text-gray-600">{t("No previous price history for this customer")}</p>}
      </div>,
      document.body,
    )}
  </>;
}
function Cell({
  children,
  error,
}: {
  children: React.ReactNode;
  error?: string;
}) {
  return (
    <td className="align-top">
      {children}
      {error && <small className="block text-red-600 mt-1">{error}</small>}
    </td>
  );
}
function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-gray-500">{label}</p>
      <strong>{value}</strong>
    </div>
  );
}
function Summary({
  label,
  value,
  strong,
}: {
  label: string;
  value: number;
  strong?: boolean;
}) {
  return (
    <div
      className={`flex justify-between ${strong ? "border-t pt-2 text-lg font-bold" : ""}`}
    >
      <span>{label}</span>
      <span>{formatMoney(value)}</span>
    </div>
  );
}
