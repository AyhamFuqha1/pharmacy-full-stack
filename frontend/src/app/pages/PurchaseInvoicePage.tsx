import { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle,
  Edit,
  Lock,
  Plus,
  Save,
  Trash2,
} from "lucide-react";
import { useLocation, useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { productsApi } from "../../api/products";
import { providersApi } from "../../api/providers";
import { purchasesApi } from "../../api/purchases";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { PermissionGate } from "../../auth/PermissionGate";
import type { ProviderDto } from "../../types/party";
import type { ProductDto } from "../../types/product";
import type {
  PurchaseInvoiceDto,
  PurchaseLinePayload,
  PurchasePayload,
} from "../../types/purchase";
import { formatMoney } from "../../utils/batchFormatters";
import { Status } from "./PurchaseInvoicesPage";
import { useTranslation } from "react-i18next";
import { RemoteEntitySelect, type SelectOption } from "../components/UxPrimitives";
import { partyEntityLoader, productEntityLoader } from "../entityLoaders";

interface EditableLine {
  key: number;
  id?: number;
  product_id: string;
  batch_number: string;
  expire_date: string;
  amount: string;
  unit_id: string;
  purchase_price: string;
  item_discount: string;
}
const emptyLine = (key: number): EditableLine => ({
  key,
  product_id: "",
  batch_number: "",
  expire_date: "",
  amount: "1",
  unit_id: "",
  purchase_price: "0",
  item_discount: "0",
});

export function PurchaseInvoicePage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const isNew = id === undefined || id === "new";
  const invoiceId = Number(id);
  const isEditRoute = location.pathname.endsWith("/edit");
  const [invoice, setInvoice] = useState<PurchaseInvoiceDto | null>(null);
  const [providers, setProviders] = useState<ProviderDto[]>([]);
  const [products, setProducts] = useState<ProductDto[]>([]);
  const [providerId, setProviderId] = useState("");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState("");
  const [invoiceDiscount, setInvoiceDiscount] = useState("0");
  const [lines, setLines] = useState<EditableLine[]>([emptyLine(1)]);
  const [unitsByProduct, setUnitsByProduct] = useState<
    Record<string, JsonRecord[]>
  >({});
  const [loading, setLoading] = useState(!isNew);
  const [error, setError] = useState<ApiClientError | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [posting, setPosting] = useState(false);
  const [confirmPost, setConfirmPost] = useState(false);
  const [success, setSuccess] = useState("");
  const [reload, setReload] = useState(0);
  const editing = isNew || (isEditRoute && invoice?.status === "draft");

  useEffect(() => {
    if (isNew) return;
    if (!Number.isInteger(invoiceId) || invoiceId <= 0) {
      setError(new ApiClientError(404, t("Purchase invoice not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    purchasesApi
      .detail(invoiceId, controller.signal)
      .then((response) => {
        const value = response.data;
        setInvoice(value);
        setProviderId(String(value.provider_id));
        void providersApi.detail(value.provider_id, controller.signal).then((r) => setProviders([r.data]));
        void Promise.all((value.items ?? []).map((item) => productsApi.detail(item.product_id, controller.signal).then((r) => r.data))).then(setProducts);
        setDate(value.date);
        setDueDate(value.due_date ?? "");
        setInvoiceDiscount(
          String(value.invoice_discount ?? value.descount ?? 0),
        );
        setLines(
          (value.items ?? []).map((item) => ({
            key: item.id,
            id: item.id,
            product_id: String(item.product_id),
            batch_number: item.batch_number,
            expire_date: item.expire_date ?? "",
            amount: String(item.transaction_quantity ?? item.amount),
            unit_id: String(item.transaction_unit_id ?? ""),
            purchase_price: String(
              item.transaction_unit_cost ?? item.purchase_price,
            ),
            item_discount: String(item.discount ?? 0),
          })),
        );
        (value.items ?? []).forEach((item) => {
          void loadProductUnits(
            item.product_id,
            item.id,
            item.transaction_unit_id == null,
          );
        });
      })
      .catch((value: unknown) => {
        if (value instanceof DOMException && value.name === "AbortError")
          return;
        setError(
          value instanceof ApiClientError
            ? value
            : new ApiClientError(0, t("Unable to load purchase invoice.")),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [invoiceId, isNew, reload]);

  const previewSubtotal = useMemo(
    () =>
      lines.reduce(
        (sum, line) =>
          sum +
          Math.max(
            0,
            Number(line.amount) * Number(line.purchase_price) -
              Number(line.item_discount || 0),
          ),
        0,
      ),
    [lines],
  );
  const previewTotal = previewSubtotal - Number(invoiceDiscount || 0);

  function updateLine(key: number, field: keyof EditableLine, value: string) {
    setLines((current) =>
      current.map((line) =>
        line.key === key ? { ...line, [field]: value } : line,
      ),
    );
  }
  async function loadProductUnits(
    productId: number,
    lineKey: number,
    chooseBase = true,
  ) {
    const response = await erpApi.productUnits(productId);
    const available = response.data.filter(
      (unit) => unit.is_active && (unit.is_purchase_unit || unit.is_base),
    );
    setUnitsByProduct((current) => ({ ...current, [productId]: available }));
    if (chooseBase) {
      const base = available.find(
        (unit) => unit.is_base || Number(unit.base_quantity) === 1,
      );
      if (base)
        setLines((current) =>
          current.map((line) =>
            line.key === lineKey && Number(line.product_id) === productId
              ? { ...line, unit_id: String(base.unit_id) }
              : line,
          ),
        );
    }
  }
  function selectProduct(lineKey: number, productId: string) {
    setLines((current) =>
      current.map((line) =>
        line.key === lineKey
          ? { ...line, product_id: productId, unit_id: "" }
          : line,
      ),
    );
    if (productId) void loadProductUnits(Number(productId), lineKey);
  }
  function selectedUnit(line: EditableLine) {
    return (unitsByProduct[line.product_id] ?? []).find(
      (unit) => String(unit.unit_id) === line.unit_id,
    );
  }
  function validate(): Record<string, string> {
    const errors: Record<string, string> = {};
    if (!providerId) errors.provider_id = t("Supplier is required.");
    if (!date) errors.date = t("Invoice date is required.");
    if (dueDate && dueDate < date)
      errors.due_date = t("Due date cannot be before invoice date.");
    if (lines.length === 0) errors.items = t("At least one line is required.");
    lines.forEach((line, index) => {
      if (!line.product_id)
        errors[`items.${index}.product_id`] = t("Product is required.");
      if (!line.batch_number.trim())
        errors[`items.${index}.batch_number`] = t("Batch number is required.");
      if (Number(line.amount) < 1 || !Number.isInteger(Number(line.amount)))
        errors[`items.${index}.amount`] = t(
          "Quantity must be a positive whole number.",
        );
      if (Number(line.purchase_price) < 0)
        errors[`items.${index}.purchase_price`] = t(
          "Purchase price cannot be negative.",
        );
      if (line.product_id && !line.unit_id)
        errors[`items.${index}.unit_id`] = t("Select a purchase unit.");
    });
    return errors;
  }
  function payload(): PurchasePayload {
    return {
      provider_id: Number(providerId),
      date,
      due_date: dueDate || null,
      invoice_discount: Number(invoiceDiscount || 0),
      items: lines.map((line): PurchaseLinePayload => ({
        ...(line.id ? { id: line.id } : {}),
        product_id: Number(line.product_id),
        batch_number: line.batch_number.trim(),
        expire_date: line.expire_date || null,
        amount: Number(line.amount),
        unit_id: line.unit_id ? Number(line.unit_id) : null,
        purchase_price: Number(line.purchase_price),
        item_discount: Number(line.item_discount || 0),
      })),
    };
  }

  async function save() {
    const errors = validate();
    setFieldErrors(errors);
    if (Object.keys(errors).length) return;
    setSubmitting(true);
    setError(null);
    try {
      const response = isNew
        ? await purchasesApi.create(payload())
        : await purchasesApi.update(invoiceId, payload());
      navigate(`/purchases/${response.data.id}`, {
        replace: true,
        state: {
          success: t(
            isNew ? "Purchase draft created." : "Purchase draft updated.",
          ),
        },
      });
    } catch (value) {
      const requestError =
        value instanceof ApiClientError
          ? value
          : new ApiClientError(0, t("Unable to save purchase draft."));
      setError(requestError);
      setFieldErrors(
        Object.fromEntries(
          Object.entries(requestError.errors).map(([field, messages]) => [
            field,
            messages[0],
          ]),
        ),
      );
    } finally {
      setSubmitting(false);
    }
  }
  async function postInvoice() {
    if (!invoice) return;
    setPosting(true);
    setError(null);
    try {
      const response = await purchasesApi.post(invoice.id);
      setInvoice(response.data);
      setConfirmPost(false);
      setSuccess(
        t("Purchase posted. Inventory and accounting were updated by Laravel."),
      );
      setReload((key) => key + 1);
    } catch (value) {
      setError(
        value instanceof ApiClientError
          ? value
          : new ApiClientError(0, t("Unable to post purchase invoice.")),
      );
    } finally {
      setPosting(false);
    }
  }

  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading purchase invoice…")} />
      </div>
    );
  if (error && !isNew && !invoice)
    return (
      <div className="p-8">
        <button
          onClick={() => navigate("/purchases")}
          className="flex gap-2 mb-6"
        >
          <ArrowLeft /> {t("Back to Purchases")}
        </button>
        <ErrorState error={error} retry={() => setReload((key) => key + 1)} />
      </div>
    );

  const persistedSuccess = (location.state as { success?: string } | null)
    ?.success;
  return (
    <div className="p-8">
      <button
        onClick={() => navigate("/purchases")}
        className="flex gap-2 text-gray-600 mb-6"
      >
        <ArrowLeft /> {t("Back to Purchases")}
      </button>
      <div className="flex justify-between items-start mb-8">
        <div>
          <h1 className="text-3xl font-bold">
            {isNew
              ? t("New Purchase Invoice")
              : <bdi dir="ltr">{invoice?.document_number || t("Legacy purchase invoice")}</bdi>}
          </h1>
          <p className="text-gray-500 mt-1">
            {t(
              editing
                ? "Save a validated draft before posting stock"
                : "Persisted purchase document",
            )}
          </p>
        </div>
        {invoice && <Status status={invoice.status} />}
      </div>
      {(success || persistedSuccess) && (
        <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
          {success || persistedSuccess}
        </div>
      )}
      {error && <ErrorState error={error} />}
      {invoice?.status === "posted" && (
        <div className="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex gap-3">
          <Lock className="text-emerald-600" />
          <div>
            <h2 className="font-bold text-emerald-900">
              {t("Posted and immutable")}
            </h2>
            <p className="text-sm text-emerald-700">
              {t(
                "Inventory receipt and accounting posting are owned by Laravel. Use Batches to inspect resulting stock.",
              )}
            </p>
          </div>
        </div>
      )}
      {!editing && invoice ? (
        <ReadOnly invoice={invoice} providers={providers} products={products} />
      ) : (
        <div className="grid lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <div className="bg-white rounded-2xl border p-6">
              <h2 className="text-lg font-bold mb-4">{t("Invoice details")}</h2>
              <div className="grid md:grid-cols-3 gap-4">
                <RemoteEntitySelect label={t("Supplier")} error={fieldErrors.provider_id} value={providerId} onChange={setProviderId} onSelect={(option) => setProviders([{ id: Number(option.id), name: option.label } as ProviderDto])} selectedOption={selectedProvider(providers, providerId)} load={partyEntityLoader("supplier")} placeholder={t("Search supplier name or phone")} required />
                <Field label={t("Invoice date")} error={fieldErrors.date}>
                  <input
                    type="date"
                    value={date}
                    onChange={(event) => setDate(event.target.value)}
                    className="input"
                  />
                </Field>
                <Field label={t("Due date")} error={fieldErrors.due_date}>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(event) => setDueDate(event.target.value)}
                    className="input"
                  />
                </Field>
              </div>
            </div>
            <div className="bg-white rounded-2xl border overflow-hidden">
              <div className="p-5 border-b flex justify-between">
                <h2 className="text-lg font-bold">{t("Purchase lines")}</h2>
                <button
                  type="button"
                  onClick={() =>
                    setLines((value) => [...value, emptyLine(Date.now())])
                  }
                  className="flex gap-2 px-4 py-2 bg-teal-600 text-white rounded-xl"
                >
                  <Plus className="w-4 h-4" /> {t("Add line")}
                </button>
              </div>
              {fieldErrors.items && (
                <p className="p-4 text-red-600">{fieldErrors.items}</p>
              )}
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1100px]">
                  <thead className="bg-gray-50 text-xs uppercase">
                    <tr>
                      <th className="p-3">{t("Product")}</th>
                      <th className="p-3">{t("Purchase Unit / Packaging")}</th>
                      <th className="p-3">{t("Batch")}</th>
                      <th className="p-3">{t("Expiry")}</th>
                      <th className="p-3">{t("Qty")}</th>
                      <th className="p-3">{t("Purchase price")}</th>
                      <th className="p-3">{t("Line discount")}</th>
                      <th className="p-3">{t("Preview total")}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {lines.map((line, index) => (
                      <tr key={line.key}>
                        <Cell error={fieldErrors[`items.${index}.product_id`]}>
                          <RemoteEntitySelect label="" inputClassName="cell-input min-w-44" value={line.product_id} onChange={(value) => void selectProduct(line.key, value)} onSelect={(option) => setProducts((current) => [...current.filter((product) => product.id !== Number(option.id)), { id: Number(option.id), name: option.label } as ProductDto])} selectedOption={selectedProduct(products, line.product_id)} load={productEntityLoader} placeholder={t("Search product name or barcode")} />
                        </Cell>
                        <Cell error={fieldErrors[`items.${index}.unit_id`]}>
                          <select
                            value={line.unit_id}
                            disabled={!line.product_id}
                            onChange={(event) =>
                              updateLine(
                                line.key,
                                "unit_id",
                                event.target.value,
                              )
                            }
                            className="cell-input min-w-40"
                          >
                            <option value="">
                              {t("Select a purchase unit")}
                            </option>
                            {(unitsByProduct[line.product_id] ?? []).map(
                              (unit) => (
                                <option
                                  data-i18n-skip
                                  key={unit.id}
                                  value={unit.unit_id}
                                >
                                  {unit.name}
                                </option>
                              ),
                            )}
                          </select>
                          {selectedUnit(line) && (
                            <span
                              className="block mt-1 text-xs text-gray-500"
                              data-i18n-skip
                            >
                              {t("purchaseUnitConversion", {
                                unit: selectedUnit(line)?.name,
                                factor: Number(
                                  selectedUnit(line)?.base_quantity,
                                ),
                              })}
                            </span>
                          )}
                        </Cell>
                        <Cell
                          error={fieldErrors[`items.${index}.batch_number`]}
                        >
                          <input
                            value={line.batch_number}
                            onChange={(event) =>
                              updateLine(
                                line.key,
                                "batch_number",
                                event.target.value,
                              )
                            }
                            className="cell-input w-36"
                          />
                        </Cell>
                        <Cell error={fieldErrors[`items.${index}.expire_date`]}>
                          <input
                            type="date"
                            value={line.expire_date}
                            onChange={(event) =>
                              updateLine(
                                line.key,
                                "expire_date",
                                event.target.value,
                              )
                            }
                            className="cell-input w-40"
                          />
                        </Cell>
                        <Cell error={fieldErrors[`items.${index}.amount`]}>
                          <input
                            type="number"
                            min="1"
                            step="1"
                            value={line.amount}
                            onChange={(event) =>
                              updateLine(line.key, "amount", event.target.value)
                            }
                            className="cell-input w-24"
                          />
                        </Cell>
                        <Cell
                          error={fieldErrors[`items.${index}.purchase_price`]}
                        >
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={line.purchase_price}
                            onChange={(event) =>
                              updateLine(
                                line.key,
                                "purchase_price",
                                event.target.value,
                              )
                            }
                            className="cell-input w-28"
                          />
                          {selectedUnit(line) && (
                            <span className="block mt-1 text-xs text-gray-500">
                              {t("purchaseBasePreview", {
                                quantity:
                                  Number(line.amount) *
                                  Number(
                                    selectedUnit(line)?.base_quantity || 1,
                                  ),
                                cost: formatMoney(
                                  Number(line.purchase_price) /
                                    Number(
                                      selectedUnit(line)?.base_quantity || 1,
                                    ),
                                ),
                              })}
                            </span>
                          )}
                        </Cell>
                        <Cell
                          error={fieldErrors[`items.${index}.item_discount`]}
                        >
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={line.item_discount}
                            onChange={(event) =>
                              updateLine(
                                line.key,
                                "item_discount",
                                event.target.value,
                              )
                            }
                            className="cell-input w-28"
                          />
                        </Cell>
                        <td className="p-3 font-bold">
                          <bdi dir="ltr">
                            {formatMoney(
                              Math.max(
                                0,
                                Number(line.amount) *
                                  Number(line.purchase_price) -
                                  Number(line.item_discount || 0),
                              ),
                            )}
                          </bdi>
                        </td>
                        <td className="p-3">
                          <button
                            disabled={lines.length === 1}
                            onClick={() =>
                              setLines((value) =>
                                value.filter((item) => item.key !== line.key),
                              )
                            }
                            className="p-2 text-red-600 disabled:opacity-30"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div>
            <div className="bg-white rounded-2xl border p-6 sticky top-8">
              <h2 className="text-lg font-bold mb-4">{t("Draft preview")}</h2>
              <p className="flex justify-between py-2">
                <span>{t("Lines subtotal")}</span>
                <strong>
                  <bdi dir="ltr">{formatMoney(previewSubtotal)}</bdi>
                </strong>
              </p>
              <Field
                label={t("Invoice discount")}
                error={fieldErrors.invoice_discount}
              >
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={invoiceDiscount}
                  onChange={(event) => setInvoiceDiscount(event.target.value)}
                  className="input"
                />
              </Field>
              <p className="flex justify-between py-4 border-t mt-4 text-lg">
                <strong>{t("Preview total")}</strong>
                <strong className="text-teal-600">
                  <bdi dir="ltr">{formatMoney(previewTotal)}</bdi>
                </strong>
              </p>
              <button
                disabled={submitting}
                onClick={() => void save()}
                className="w-full flex justify-center gap-2 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl disabled:opacity-50"
              >
                <Save className="w-5 h-5" />{" "}
                {t(
                  submitting
                    ? "Saving…"
                    : isNew
                      ? "Create Draft"
                      : "Save Draft",
                )}
              </button>
            </div>
          </div>
        </div>
      )}
      {!editing && invoice?.status === "draft" && (
        <PermissionGate permissions={["purchases.manage", "accounting.vouchers"]}>
          <div className="flex gap-3 mt-6">
            <button
              onClick={() => navigate(`/purchases/${invoice.id}/edit`)}
              className="flex gap-2 px-5 py-3 bg-gray-100 rounded-xl"
            >
              <Edit className="w-5 h-5" /> {t("Edit Draft")}
            </button>
            <button
              onClick={() => setConfirmPost(true)}
              className="flex gap-2 px-5 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl"
            >
              <CheckCircle className="w-5 h-5" /> {t("Post Invoice")}
            </button>
          </div>
        </PermissionGate>
      )}
      {invoice?.status === "posted" && (
        <PermissionGate permissions={["purchases.manage", "accounting.vouchers"]}>
          <div className="mt-6 flex flex-wrap gap-3">
            <button
              onClick={() => navigate(`/purchases/${invoice.id}/return`)}
              className="px-5 py-3 bg-teal-600 text-white rounded-xl"
            >
              {t("Return Items")}
            </button>
            <button
              onClick={() => navigate("/returns/purchases")}
              className="px-5 py-3 bg-gray-100 rounded-xl"
            >
              {t("Purchase Return History")}
            </button>
            <button
              onClick={() => navigate("/batches")}
              className="px-5 py-3 bg-gray-100 rounded-xl"
            >
              {t("View Batch Inventory")}
            </button>
          </div>
        </PermissionGate>
      )}
      {confirmPost && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white max-w-md rounded-2xl p-6">
            <AlertCircle className="w-10 h-10 text-amber-500 mb-3" />
            <h2 className="text-xl font-bold">{t("Post purchase invoice?")}</h2>
            <p className="text-gray-600 my-4">
              {t(
                "Posting receives inventory, updates batch costing, creates stock movements, and posts accounting entries. The document will become immutable.",
              )}
            </p>
            {error && (
              <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl">
                <p className="font-semibold text-red-900">
                  {error.status === 409
                    ? t("Posting conflict")
                    : error.status === 422
                      ? t("Posting validation failed")
                      : error.status === 403
                        ? t("Posting denied")
                        : t("Posting failed")}
                </p>
                <p className="text-sm text-red-700 mt-1">{error.message}</p>
              </div>
            )}
            <div className="flex gap-3">
              <button
                disabled={posting}
                onClick={() => setConfirmPost(false)}
                className="flex-1 py-2.5 bg-gray-100 rounded-xl"
              >
                {t("Cancel")}
              </button>
              <button
                disabled={posting}
                onClick={() => void postInvoice()}
                className="flex-1 py-2.5 bg-teal-600 text-white rounded-xl disabled:opacity-50"
              >
                {t(posting ? "Posting…" : "Post Invoice")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function ReadOnly({
  invoice,
  providers,
  products,
}: {
  invoice: PurchaseInvoiceDto;
  providers: ProviderDto[];
  products: ProductDto[];
}) {
  const { t, i18n } = useTranslation();
  const displayDate = (value: string) =>
    new Intl.DateTimeFormat(i18n.resolvedLanguage === "ar" ? "ar" : "en", {
      year: "numeric",
      month: "short",
      day: "numeric",
      timeZone: "UTC",
    }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
  return (
    <div className="space-y-6">
      <div className="bg-white rounded-2xl border p-6 grid md:grid-cols-4 gap-4">
        <Info
          label={t("Supplier")}
          value={
            providers.find((item) => item.id === invoice.provider_id)?.name ??
            t("supplierReference", { id: invoice.provider_id })
          }
        />
        <Info
          label={t("Invoice date")}
          value={displayDate(invoice.date)}
          isolate
        />
        <Info
          label={t("Due date")}
          value={
            invoice.due_date ? displayDate(invoice.due_date) : t("Not set")
          }
          isolate={Boolean(invoice.due_date)}
        />
        <Info label={t("Status")} value={t(invoice.status)} />
      </div>
      <div className="bg-white rounded-2xl border overflow-x-auto">
        <table className="w-full min-w-[900px]">
          <thead className="bg-gray-50 text-xs uppercase">
            <tr>
              <th className="p-4">{t("Product")}</th>
              <th className="p-4">{t("Batch")}</th>
              <th className="p-4">{t("Expiry")}</th>
              <th className="p-4">{t("Quantity")}</th>
              <th className="p-4">{t("Base Quantity Received")}</th>
              <th className="p-4">{t("Purchase Cost per Unit")}</th>
              <th className="p-4">{t("Base Unit Cost")}</th>
              <th className="p-4">{t("Discount")}</th>
              <th className="p-4">{t("Line total")}</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {invoice.items?.map((line) => (
              <tr key={line.id}>
                <td className="p-4 font-semibold">
                  {products.find((product) => product.id === line.product_id)
                    ?.name ?? t("productReference", { id: line.product_id })}
                </td>
                <td className="p-4 font-mono">
                  <bdi dir="ltr">{line.batch_number}</bdi>
                </td>
                <td className="p-4">
                  {line.expire_date ? (
                    <bdi dir="ltr">{displayDate(line.expire_date)}</bdi>
                  ) : (
                    t("No expiry date")
                  )}
                </td>
                <td className="p-4">
                  <bdi dir="ltr">
                    {line.transaction_quantity ?? line.amount}
                  </bdi>{" "}
                  <span data-i18n-skip>
                    {line.transaction_unit_name ?? t("Base unit")}
                  </span>
                </td>
                <td className="p-4">
                  <bdi dir="ltr">{line.base_quantity ?? line.amount}</bdi>
                </td>
                <td className="p-4">
                  <bdi dir="ltr">
                    {formatMoney(
                      line.transaction_unit_cost ?? line.purchase_price,
                    )}
                  </bdi>
                  {line.transaction_unit_name && (
                    <span data-i18n-skip> / {line.transaction_unit_name}</span>
                  )}
                </td>
                <td className="p-4">
                  <bdi dir="ltr">{formatMoney(line.purchase_price)}</bdi>
                </td>
                <td className="p-4">
                  <bdi dir="ltr">{formatMoney(line.discount)}</bdi>
                </td>
                <td className="p-4 font-bold">
                  <bdi dir="ltr">
                    {formatMoney(
                      Number(line.transaction_quantity ?? line.amount) *
                        Number(
                          line.transaction_unit_cost ?? line.purchase_price,
                        ) -
                        Number(line.discount),
                    )}
                  </bdi>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="ml-auto max-w-sm bg-white rounded-2xl border p-6 space-y-3">
        <p className="flex justify-between">
          <span>{t("Subtotal")}</span>
          <strong>
            <bdi dir="ltr">{formatMoney(invoice.total_before_discount)}</bdi>
          </strong>
        </p>
        <p className="flex justify-between">
          <span>{t("Invoice discount")}</span>
          <strong>
            <bdi dir="ltr">
              {formatMoney(invoice.invoice_discount ?? invoice.descount)}
            </bdi>
          </strong>
        </p>
        <p className="flex justify-between border-t pt-3 text-lg">
          <strong>{t("Final total")}</strong>
          <strong className="text-teal-600">
            <bdi dir="ltr">
              {formatMoney(invoice.total_after_discount ?? invoice.amount)}
            </bdi>
          </strong>
        </p>
      </div>
    </div>
  );
}
function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="block text-sm font-medium mb-2">{label}</span>
      {children}
      {error && (
        <span className="block text-xs text-red-600 mt-1">{error}</span>
      )}
    </label>
  );
}
function Cell({
  error,
  children,
}: {
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <td className="p-3 align-top">
      {children}
      {error && (
        <span className="block text-xs text-red-600 mt-1 max-w-40">
          {error}
        </span>
      )}
    </td>
  );
}
function Info({
  label,
  value,
  isolate = false,
}: {
  label: string;
  value: string;
  isolate?: boolean;
}) {
  return (
    <div className="p-4 bg-gray-50 rounded-xl">
      <p className="text-xs text-gray-500">{label}</p>
      <p className="font-bold mt-1 capitalize">
        {isolate ? <bdi dir="ltr">{value}</bdi> : value}
      </p>
    </div>
  );
}
function State({ text }: { text: string }) {
  return (
    <div className="bg-white rounded-2xl border p-12 text-center text-gray-500">
      {text}
    </div>
  );
}
function ErrorState({
  error,
  retry,
}: {
  error: ApiClientError;
  retry?: () => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
      <h2 className="font-bold text-red-900">
        {error.status === 403
          ? t("Purchase access denied")
          : error.status === 404
            ? t("Purchase invoice not found")
            : error.status === 409
              ? t("Purchase lifecycle conflict")
              : error.status === 422
                ? t("Purchase validation failed")
                : t("Purchase request failed")}
      </h2>
      <p className="text-sm text-red-700 mt-1">{error.message}</p>
      {retry && error.status !== 403 && error.status !== 404 && (
        <button
          onClick={retry}
          className="mt-3 px-4 py-2 bg-red-600 text-white rounded-xl"
        >
          {t("Retry")}
        </button>
      )}
    </div>
  );
}

function selectedProvider(rows: ProviderDto[], value: string): SelectOption | null {
  const row = rows.find((item) => item.id === Number(value));
  return row ? { id: row.id, label: row.name } : null;
}
function selectedProduct(rows: ProductDto[], value: string): SelectOption | null {
  const row = rows.find((item) => item.id === Number(value));
  return row ? { id: row.id, label: `${row.name}${row.barcode ? ` — ${row.barcode}` : ""}` } : null;
}
