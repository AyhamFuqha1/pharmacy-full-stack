import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { ArrowLeft, RotateCcw } from "lucide-react";
import { useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { returnsApi } from "../../api/finance";
import { purchasesApi } from "../../api/purchases";
import { salesApi } from "../../api/sales";
import type { PurchaseInvoiceDto } from "../../types/purchase";
import type { SalesInvoiceDto } from "../../types/sales";
import { formatMoney } from "../../utils/batchFormatters";

export function InvoiceReturnPage({ kind }: { kind: "sales" | "purchase" }) {
  const { t } = useTranslation();
  const { id } = useParams(),
    invoiceId = Number(id),
    navigate = useNavigate();
  const [sale, setSale] = useState<SalesInvoiceDto | null>(null),
    [purchase, setPurchase] = useState<PurchaseInvoiceDto | null>(null);
  const [quantities, setQuantities] = useState<Record<number, string>>({}),
    [reasons, setReasons] = useState<Record<number, string>>({});
  const [credits, setCredits] = useState<Record<number, string>>({}),
    [restock, setRestock] = useState<Record<number, boolean>>({});
  const [loading, setLoading] = useState(true),
    [submitting, setSubmitting] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [reload, setReload] = useState(0);
  const isSales = kind === "sales";

  useEffect(() => {
    if (!Number.isInteger(invoiceId) || invoiceId <= 0) {
      setError(new ApiClientError(404, t("Invoice not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    const request = isSales
      ? salesApi
          .detail(invoiceId, controller.signal)
          .then((r) => setSale(r.data))
      : purchasesApi
          .detail(invoiceId, controller.signal)
          .then((r) => setPurchase(r.data));
    request
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(0, t("Unable to load invoice.")),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [invoiceId, kind, reload]);

  const lines = isSales ? (sale?.items ?? []) : (purchase?.items ?? []);
  async function submit() {
    const selected = lines.filter((line) => Number(quantities[line.id]) > 0);
    if (!selected.length) {
      setError(
        new ApiClientError(
          422,
          t("Select at least one line and enter a return quantity."),
        ),
      );
      return;
    }
    if (
      selected.some(
        (line) =>
          !Number.isInteger(Number(quantities[line.id])) ||
          Number(quantities[line.id]) >
            (isSales
              ? ((line as NonNullable<SalesInvoiceDto["items"]>[number])
                  .available_return_quantity ?? 0)
              : ((line as NonNullable<PurchaseInvoiceDto["items"]>[number])
                  .available_return_transaction_quantity ??
                (line as NonNullable<PurchaseInvoiceDto["items"]>[number])
                  .available_return_quantity ??
                line.amount)),
      )
    ) {
      setError(
        new ApiClientError(
          422,
          t(
            "Return quantities must be whole numbers within the available return quantities.",
          ),
        ),
      );
      return;
    }
    if (
      !window.confirm(
        t("postReturnConfirmation", {
          returnType: t(isSales ? "Sales Return" : "Purchase Return"),
        }),
      )
    )
      return;
    setSubmitting(true);
    setError(null);
    try {
      if (isSales)
        await returnsApi.createSales(
          selected.map((line) => {
            const item = line as NonNullable<SalesInvoiceDto["items"]>[number];
            const original = Number(item.final_total) / item.amount;
            return {
              invoice_id: invoiceId,
              product_id: item.product?.id ?? 0,
              batch_id: item.batch_id,
              quantity: Number(quantities[item.id]),
              price: original,
              customer_credit_unit_amount: Number(
                credits[item.id] ?? original.toFixed(2),
              ),
              return_to_inventory: restock[item.id] ?? true,
              reason: reasons[item.id] || null,
            };
          }),
        );
      else
        await returnsApi.createPurchase(
          invoiceId,
          selected.map((line) => {
            const item = line as NonNullable<
              PurchaseInvoiceDto["items"]
            >[number];
            const factor = Number(item.conversion_factor ?? 1);
            const original = Number(item.purchase_price) * factor;
            const credit = Number(credits[item.id] ?? original.toFixed(2));
            return {
              invoice_item_id: item.id,
              batch_id: item.batch_id,
              ...(item.transaction_quantity !== null &&
              item.transaction_quantity !== undefined
                ? { transaction_quantity: Number(quantities[item.id]) }
                : { quantity: Number(quantities[item.id]) }),
              supplier_credit_unit_amount: Number((credit / factor).toFixed(2)),
              reasone: reasons[item.id] || null,
            };
          }),
        );
      setSuccess(
        t("Return posted. Inventory and accounting were updated by Laravel."),
      );
      setQuantities({});
      setCredits({});
      setRestock({});
      setReload((value) => value + 1);
    } catch (value) {
      setError(
        value instanceof ApiClientError
          ? value
          : new ApiClientError(0, t("Unable to post return.")),
      );
    } finally {
      setSubmitting(false);
    }
  }

  const back = isSales ? `/sales/${invoiceId}` : `/purchases/${invoiceId}`;
  if (loading) return <div className="p-8">{t("Loading invoice lines…")}</div>;
  return (
    <div className="p-8">
      <button onClick={() => navigate(back)} className="flex gap-2 mb-6">
        <ArrowLeft /> {t("Back to Invoice")}
      </button>
      <h1 className="text-3xl font-bold">
        {t(isSales ? "Create Sales Return" : "Create Purchase Return")}
      </h1>
      <p className="text-gray-500 mb-8">
        {t("Return against")} <bdi dir="ltr">{(isSales ? sale?.document_number : purchase?.document_number) || t(isSales ? "Legacy sales invoice" : "Legacy purchase invoice")}</bdi>
      </p>
      {success && (
        <div className="p-4 mb-5 bg-emerald-50 text-emerald-700 rounded-xl">
          {success}
        </div>
      )}
      {error && (
        <div className="p-4 mb-5 bg-red-50 text-red-700 rounded-xl">
          <strong>
            {t(
              error.status === 409
                ? "Return conflict"
                : error.status === 403
                  ? "Return access denied"
                  : error.status === 404
                    ? "Invoice not found"
                    : "Return failed",
            )}
          </strong>
          <p>{error.message}</p>
          {Object.entries(error.errors).map(([field, messages]) => (
            <p key={field} className="text-sm mt-1">
              {t(field)}: {messages.join(" ")}
            </p>
          ))}
        </div>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        <table className="w-full min-w-[1450px]">
          <thead className="bg-gray-50">
            <tr>
              <th className="p-4">{t("Product")}</th>
              <th>{t("Batch")}</th>
              <th>{t(isSales ? "Original Quantity" : "Purchased Quantity")}</th>
              <th>{t("Previously returned")}</th>
              <th>{t("Available to return")}</th>
              <th>{t("Return Quantity")}</th>
              <th>
                {t(
                  isSales
                    ? "Original returnable unit value"
                    : "Historical inventory cost per unit",
                )}
              </th>
              <th>
                {t(
                  isSales
                    ? "Customer credit per unit"
                    : "Supplier credit per unit",
                )}
              </th>
              <th>
                {t(isSales ? "Return deduction" : "Inventory adjustment")}
              </th>
              {isSales && <th>{t("Return to inventory")}</th>}
              <th>{t("Reason")}</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {lines.map((line) => {
              const salesLine = isSales
                ? (line as NonNullable<SalesInvoiceDto["items"]>[number])
                : null;
              const purchaseLine = !isSales
                ? (line as NonNullable<PurchaseInvoiceDto["items"]>[number])
                : null;
              const available =
                salesLine?.available_return_quantity ??
                purchaseLine?.available_return_transaction_quantity ??
                purchaseLine?.available_return_quantity ??
                line.amount;
              const originalQty =
                salesLine?.original_quantity ??
                purchaseLine?.original_transaction_quantity ??
                purchaseLine?.original_quantity ??
                line.amount;
              const returnedQty =
                salesLine?.returned_quantity ??
                purchaseLine?.returned_transaction_quantity ??
                purchaseLine?.returned_quantity ??
                0;
              const original = salesLine
                ? Number(salesLine.final_total) / salesLine.amount
                : Number(purchaseLine?.purchase_price ?? 0) *
                  Number(purchaseLine?.conversion_factor ?? 1);
              const credit = Number(credits[line.id] ?? original.toFixed(2));
              const qty = Number(quantities[line.id] ?? 0);
              return (
                <tr key={line.id}>
                  <td className="p-4 font-semibold">
                    {salesLine?.product?.name ??
                      (purchaseLine
                        ? t("productReference", { id: purchaseLine.product_id })
                        : t("Product"))}
                  </td>
                  <td>
                    <bdi dir="ltr">
                      {salesLine?.batch?.batch_number ??
                        purchaseLine?.batch_number ??
                        `#${line.batch_id}`}
                    </bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{originalQty}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{returnedQty}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{available}</bdi>
                  </td>
                  <td>
                    <input
                      aria-label={t("Return Quantity")}
                      className="cell-input w-24"
                      type="number"
                      min="0"
                      max={available}
                      step="1"
                      disabled={available <= 0}
                      value={quantities[line.id] ?? ""}
                      onChange={(e) =>
                        setQuantities({
                          ...quantities,
                          [line.id]: e.target.value,
                        })
                      }
                    />
                  </td>
                  <td>
                    <bdi dir="ltr">{formatMoney(original)}</bdi>
                  </td>
                  <td>
                    <input
                      aria-label={t(
                        isSales
                          ? "Customer credit per unit"
                          : "Supplier credit per unit",
                      )}
                      className="cell-input w-28"
                      type="number"
                      min="0"
                      max={original}
                      step="0.01"
                      value={credits[line.id] ?? original.toFixed(2)}
                      onChange={(e) =>
                        setCredits({ ...credits, [line.id]: e.target.value })
                      }
                    />
                  </td>
                  <td>
                    <bdi dir="ltr">
                      {formatMoney(Math.max(0, (original - credit) * qty))}
                    </bdi>
                  </td>
                  {isSales && (
                    <td>
                      <input
                        aria-label={t("Return to inventory")}
                        type="checkbox"
                        checked={restock[line.id] ?? true}
                        onChange={(e) =>
                          setRestock({
                            ...restock,
                            [line.id]: e.target.checked,
                          })
                        }
                      />
                    </td>
                  )}
                  <td>
                    <input
                      aria-label={t("Reason")}
                      className="cell-input"
                      value={reasons[line.id] ?? ""}
                      onChange={(e) =>
                        setReasons({ ...reasons, [line.id]: e.target.value })
                      }
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {lines.length === 0 && (
          <div className="p-12 text-center text-gray-500">
            {t("No persisted invoice lines are available to return.")}
          </div>
        )}
      </div>
      <button
        disabled={submitting || !lines.length}
        onClick={() => void submit()}
        className="mt-6 flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl disabled:opacity-50"
      >
        <RotateCcw /> {t(submitting ? "Posting Return…" : "Post Return")}
      </button>
    </div>
  );
}
