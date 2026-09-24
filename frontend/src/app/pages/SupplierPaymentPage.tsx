import { useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { paymentVouchersApi } from "../../api/finance";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ErrorNotice, normalizeError } from "../components/ErpPrimitives";
import { RemoteEntitySelect } from "../components/UxPrimitives";
import { partyEntityLoader } from "../entityLoaders";
export function SupplierPaymentPage() {
  const { t } = useTranslation();
  const [f, setF] = useState<JsonRecord>({
      provider_id: "",
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
    [error, setError] = useState<any>(null);
  useEffect(() => {
    if (!f.provider_id) {
      setRows([]);
      return;
    }
    erpApi
      .supplier360(Number(f.provider_id))
      .then((r) => setRows(r.data.outstanding_purchases || []))
      .catch((v) => setError(normalizeError(v)));
  }, [f.provider_id]);
  const amount = Number(f.amount || 0),
    allocated = useMemo(
      () => Object.values(alloc).reduce((n, v) => n + Number(v || 0), 0),
      [alloc],
    );
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (allocated > amount) {
      setError(
        normalizeError(null, "Allocated total cannot exceed payment amount."),
      );
      return;
    }
    const allocations = rows
      .map((x) => ({
        invoice_id: Number(x.invoice_id || x.id),
        amount: Number(alloc[x.invoice_id || x.id] || 0),
      }))
      .filter((x) => x.amount > 0);
    try {
      const p: any = {
        provider_id: Number(f.provider_id),
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
      const r = await paymentVouchersApi.create(p);
      setSuccess(`Payment ${r.data.id} posted.`);
      setAlloc({});
      setF({ ...f, amount: "" });
    } catch (v) {
      setError(normalizeError(v, "Payment validation failed."));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <h1 className="text-3xl font-bold">Supplier Payment</h1>
      {success && (
        <div className="p-4 bg-emerald-50 text-emerald-800 rounded-xl">
          {success}
        </div>
      )}
      <ErrorNotice error={error} />
      <form className="card-form" onSubmit={submit}>
        <RemoteEntitySelect label="Supplier" value={String(f.provider_id)} onChange={(value) => { setF({ ...f, provider_id: value }); setAlloc({}); }} load={partyEntityLoader("supplier")} placeholder="Search supplier name or phone" required />
        <input
          className="input"
          type="number"
          min=".01"
          step=".01"
          placeholder="Payment amount"
          value={f.amount}
          onChange={(e) => setF({ ...f, amount: e.target.value })}
        />
        <input
          className="input"
          type="date"
          value={f.date}
          onChange={(e) => setF({ ...f, date: e.target.value })}
        />
        <select
          className="input"
          value={f.payment_method}
          onChange={(e) => setF({ ...f, payment_method: e.target.value })}
        >
          <option value="cash">Cash</option>
          <option value="cheque">Cheque</option>
        </select>
        {f.payment_method === "cheque" && (
          <>
            <input
              className="input"
              placeholder="Cheque number"
              value={f.cheque_number}
              onChange={(e) => setF({ ...f, cheque_number: e.target.value })}
            />
            <input
              className="input"
              placeholder="Bank"
              value={f.bank}
              onChange={(e) => setF({ ...f, bank: e.target.value })}
            />
            <input
              className="input"
              type="date"
              value={f.due_date}
              onChange={(e) => setF({ ...f, due_date: e.target.value })}
            />
          </>
        )}
        <button className="report-action primary">Post Payment</button>
      </form>
      {f.provider_id && amount > 0 && (
        <section className="bg-white border rounded-2xl overflow-hidden">
          <h2 className="p-4 font-bold">Allocate to purchases (optional)</h2>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[650px]">
              <thead>
                <tr>
                  <th>Purchase</th>
                  <th>Date</th>
                  <th>Outstanding</th>
                  <th>Allocation</th>
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
                        {r.invoice_number || r.document_number || t("Legacy purchase invoice")}
                      </td>
                      <td>{r.invoice_date || r.date}</td>
                      <td>{out}</td>
                      <td>
                        <input
                          className="cell-input"
                          type="number"
                          min="0"
                          max={Math.min(out, amount)}
                          value={alloc[id] || ""}
                          onChange={(e) =>
                            setAlloc({
                              ...alloc,
                              [id]: String(
                                Math.min(Number(e.target.value), out, amount),
                              ),
                            })
                          }
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="p-4 flex gap-6">
            <strong>Payment: {amount}</strong>
            <strong>Allocated: {allocated}</strong>
            <strong>Unallocated: {amount - allocated}</strong>
          </div>
        </section>
      )}
    </div>
  );
}
