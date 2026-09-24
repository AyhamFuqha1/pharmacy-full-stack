import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";
import { receiptVouchersApi, paymentVouchersApi } from "../../api/finance";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { formatMoney } from "../../utils/batchFormatters";
import {
  RemoteEntitySelect,
  Field,
  FormSection,
  PageHeader,
} from "../components/UxPrimitives";
import { partyEntityLoader } from "../entityLoaders";
import { ErrorNotice, normalizeError } from "../components/ErpPrimitives";
export function FriendlyReceiptPage() {
  return <Voucher kind="receipt" />;
}
export function FriendlySupplierPaymentPage() {
  return <Voucher kind="payment" />;
}
function Voucher({ kind }: { kind: "receipt" | "payment" }) {
  const { t } = useTranslation(),
    nav = useNavigate(),
    partyKey = kind === "receipt" ? "customer_id" : "provider_id",
    [f, setF] = useState<JsonRecord>({
      [partyKey]: "",
      amount: "",
      date: new Date().toISOString().slice(0, 10),
      payment_method: "cash",
      cheque_number: "",
      bank: "",
      due_date: "",
    }),
    [rows, setRows] = useState<JsonRecord[]>([]),
    [alloc, setAlloc] = useState<Record<number, string>>({}),
    [success, setSuccess] = useState(""),
    [error, setError] = useState<any>(null),
    [refreshWarning, setRefreshWarning] = useState(""),
    [refreshKey, setRefreshKey] = useState(0),
    [submitting, setSubmitting] = useState(false),
    submitLock = useRef(false),
    submitAttempt = useRef(0),
    submitController = useRef<AbortController | null>(null);
  useEffect(() => {
    const id = Number(f[partyKey] || 0);
    if (!id) {
      setRows([]);
      return;
    }
    const controller = new AbortController();
    (kind === "receipt"
      ? erpApi.customer360(id, {}, controller.signal)
      : erpApi.supplier360(id, {}, controller.signal))
      .then((r) =>
        {
          setRows(
            kind === "receipt"
              ? r.data.outstanding_invoices || []
              : r.data.outstanding_purchases || [],
          );
          setRefreshWarning("");
        },
      )
      .catch((v) => {
        if (v instanceof DOMException && v.name === "AbortError") return;
        if (refreshKey > 0)
          setRefreshWarning(t("paymentRefreshWarning"));
        else setError(normalizeError(v));
      });
    return () => controller.abort();
  }, [f[partyKey], kind, refreshKey]);
  useEffect(() => () => submitController.current?.abort(), []);
  const amount = Number(f.amount || 0),
    allocated = useMemo(
      () => Object.values(alloc).reduce((n, v) => n + Number(v || 0), 0),
      [alloc],
    );
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (submitLock.current) return;
    submitLock.current = true;
    setSubmitting(true);
    const attempt = ++submitAttempt.current;
    const controller = new AbortController();
    submitController.current = controller;
    setSuccess("");
    setError(null);
    setRefreshWarning("");
    if (allocated > amount) {
      setError(
        normalizeError(
          null,
          "The allocated amount cannot be greater than the payment amount.",
        ),
      );
      submitLock.current = false;
      setSubmitting(false);
      return;
    }
    const allocations = rows
        .map((x) => ({
          invoice_id: Number(x.invoice_id || x.id),
          amount: Number(alloc[x.invoice_id || x.id] || 0),
        }))
        .filter((x) => x.amount > 0),
      common = {
        amount,
        date: f.date,
        payment_method: f.payment_method,
        ...(allocations.length ? { allocations } : {}),
        ...(f.payment_method === "cheque"
          ? {
              cheque_number: f.cheque_number,
              bank: f.bank,
              due_date: f.due_date,
            }
          : {}),
      };
    try {
      if (kind === "receipt") {
        const r = await receiptVouchersApi.create({
          ...common,
          customer_id: Number(f.customer_id),
        } as any, controller.signal);
        nav(`/receipt/${r.data.id}`);
      } else {
        const r = await paymentVouchersApi.create({
          ...common,
          provider_id: Number(f.provider_id),
        } as any, controller.signal);
        if (submitAttempt.current !== attempt) return;
        setError(null);
        setSuccess(t("supplierPaymentRecorded", { id: r.data.id }));
        setAlloc({});
        setF((current) => ({ ...current, amount: "" }));
        setRefreshKey((value) => value + 1);
      }
    } catch (v) {
      if (v instanceof DOMException && v.name === "AbortError") return;
      if (submitAttempt.current !== attempt) return;
      setSuccess("");
      setError(
        normalizeError(
          v,
          kind === "receipt"
            ? "The customer payment could not be recorded."
            : "The supplier payment could not be recorded.",
        ),
      );
    } finally {
      if (submitAttempt.current === attempt) {
        submitController.current = null;
        submitLock.current = false;
        setSubmitting(false);
      }
    }
  }
  const receipt = kind === "receipt";
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title={t(receipt ? "Customer Payment" : "Supplier Payment")}
        description={t(
          receipt
            ? "Record money received from a customer and optionally settle specific outstanding invoices."
            : "Record money paid to a supplier and optionally settle specific outstanding purchases.",
        )}
      />
      {success && (
        <div className="p-4 rounded-xl bg-emerald-50 text-emerald-800">
          {success}
        </div>
      )}
      {refreshWarning && (
        <div className="p-4 rounded-xl bg-amber-50 text-amber-800">
          {refreshWarning}
        </div>
      )}
      <ErrorNotice error={error} />
      <form className="space-y-5" onSubmit={submit}>
        <FormSection
          title={t(receipt ? "Customer and payment" : "Supplier and payment")}
        >
          <RemoteEntitySelect
            required
            label={t(receipt ? "Customer" : "Supplier")}
            value={String(f[partyKey])}
            onChange={(v) => {
              setF({ ...f, [partyKey]: v });
              setAlloc({});
            }}
            load={partyEntityLoader(receipt ? "customer" : "supplier")}
            placeholder={t(receipt ? "Search for a customer..." : "Search for a supplier...")}
          />
          <Field required label={t("Payment Amount")}>
            <input
              required
              className="input"
              type="number"
              min=".01"
              step=".01"
              value={String(f.amount)}
              onChange={(e) => setF({ ...f, amount: e.target.value })}
            />
          </Field>
          <Field required label={t("Payment Date")}>
            <input
              required
              className="input"
              type="date"
              value={String(f.date)}
              onChange={(e) => setF({ ...f, date: e.target.value })}
            />
          </Field>
          <Field label={t("Payment Method")}>
            <select
              className="input"
              value={String(f.payment_method)}
              onChange={(e) => setF({ ...f, payment_method: e.target.value })}
            >
              <option value="cash">{t("Cash")}</option>
              <option value="cheque">{t("Cheque")}</option>
            </select>
          </Field>
          {f.payment_method === "cheque" && (
            <>
              <Field required label={t("Cheque Number")}>
                <input
                  required
                  dir="ltr"
                  className="input"
                  value={String(f.cheque_number)}
                  onChange={(e) =>
                    setF({ ...f, cheque_number: e.target.value })
                  }
                />
              </Field>
              <Field required label={t("Bank")}>
                <input
                  required
                  className="input"
                  value={String(f.bank)}
                  onChange={(e) => setF({ ...f, bank: e.target.value })}
                />
              </Field>
              <Field required label={t("Cheque Due Date")}>
                <input
                  required
                  className="input"
                  type="date"
                  value={String(f.due_date)}
                  onChange={(e) => setF({ ...f, due_date: e.target.value })}
                />
              </Field>
            </>
          )}
        </FormSection>
        <button className="report-action primary" disabled={submitting}>
          {t(submitting ? "Saving…" : receipt ? "Save Customer Payment" : "Save Supplier Payment")}
        </button>
      </form>
      {f[partyKey] && amount > 0 && (
        <section className="bg-white border rounded-2xl overflow-hidden">
          <div className="p-4 border-b">
            <h2 className="font-bold">{t("Optional Payment Allocation")}</h2>
            <p className="text-sm text-gray-600">
              {t(
                receipt
                  ? "Choose specific outstanding invoices, or leave this blank to apply the payment to the oldest outstanding invoices first."
                  : "Choose specific outstanding purchases, or leave this blank to apply the payment to the oldest outstanding purchases first.",
              )}
            </p>
          </div>
          {rows.length ? (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[650px]">
                <thead>
                  <tr>
                    <th>{t("Document")}</th>
                    <th>{t("Date")}</th>
                    <th>{t("Outstanding Amount")}</th>
                    <th>{t("Allocation Amount")}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => {
                    const id = Number(r.invoice_id || r.id),
                      out = Number(
                        r.outstanding_amount ||
                          r.outstanding ||
                          r.original_amount ||
                          0,
                      );
                    return (
                      <tr key={id}>
                        <td>
                          <bdi dir="ltr">
                            {r.invoice_number ||
                              r.document_number ||
                              t(kind === "receipt" ? "Legacy sales invoice" : "Legacy purchase invoice")}
                          </bdi>
                        </td>
                        <td>
                          <bdi dir="ltr">{r.invoice_date || r.date}</bdi>
                        </td>
                        <td>{formatMoney(out)}</td>
                        <td>
                          <label>
                            <span className="sr-only">
                              {t("allocationAmountForDocument", { id })}
                            </span>
                            <input
                              className="cell-input"
                              type="number"
                              min="0"
                              max={Math.min(out, amount)}
                              step=".01"
                              value={alloc[id] || ""}
                              onChange={(e) =>
                                setAlloc({
                                  ...alloc,
                                  [id]: String(
                                    Math.min(
                                      Number(e.target.value),
                                      out,
                                      amount,
                                    ),
                                  ),
                                })
                              }
                            />
                          </label>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="p-8 text-center text-gray-500">
              {t(
                receipt
                  ? "No outstanding customer invoices are available for allocation."
                  : "No outstanding supplier purchases are available for allocation.",
              )}
            </div>
          )}
          <div className="p-4 flex flex-wrap gap-6">
            <strong>
              {t("Payment Amount")}: {formatMoney(amount)}
            </strong>
            <strong>
              {t("Allocated Amount")}: {formatMoney(allocated)}
            </strong>
            <strong>
              {t("Unallocated Amount")}: {formatMoney(amount - allocated)}
            </strong>
          </div>
        </section>
      )}
    </div>
  );
}
