import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import { receiptVouchersApi } from "../../api/finance";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ErrorNotice, normalizeError } from "../components/ErpPrimitives";
import { RemoteEntitySelect } from "../components/UxPrimitives";
import { partyEntityLoader } from "../entityLoaders";
export function PaymentsPage() {
  const nav = useNavigate(),
    [f, setF] = useState<JsonRecord>({
      customer_id: "",
      amount: "",
      date: new Date().toISOString().slice(0, 10),
      payment_method: "cash",
      cheque_number: "",
      bank: "",
      due_date: "",
    }),
    [invoices, setInvoices] = useState<JsonRecord[]>([]),
    [alloc, setAlloc] = useState<Record<number, string>>({}),
    [error, setError] = useState<any>(null);
  useEffect(() => {
    if (!f.customer_id) {
      setInvoices([]);
      return;
    }
    erpApi
      .customer360(Number(f.customer_id))
      .then((r) => setInvoices(r.data.outstanding_invoices || []))
      .catch((v) => setError(normalizeError(v)));
  }, [f.customer_id]);
  const allocated = useMemo(
      () => Object.values(alloc).reduce((n, v) => n + Number(v || 0), 0),
      [alloc],
    ),
    amount = Number(f.amount || 0);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (allocated > amount) {
      setError(
        normalizeError(null, "Allocated total cannot exceed receipt amount."),
      );
      return;
    }
    const allocations = invoices
      .map((x) => ({
        invoice_id: Number(x.invoice_id || x.id),
        amount: Number(alloc[x.invoice_id || x.id] || 0),
      }))
      .filter((x) => x.amount > 0);
    try {
      const payload: any = {
        customer_id: Number(f.customer_id),
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
      const r = await receiptVouchersApi.create(payload);
      nav(`/receipt/${r.data.id}`);
    } catch (v) {
      setError(normalizeError(v, "Receipt validation failed."));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <h1 className="text-3xl font-bold">Customer Receipt</h1>
      <ErrorNotice error={error} />
      <form className="card-form" onSubmit={submit}>
        <RemoteEntitySelect label="Customer" value={String(f.customer_id)} onChange={(value) => { setF({ ...f, customer_id: value }); setAlloc({}); }} load={partyEntityLoader("customer")} placeholder="Search customer name or phone" required />
        <input
          className="input"
          type="number"
          min=".01"
          step=".01"
          placeholder="Receipt amount"
          value={f.amount}
          onChange={(e) => setF({ ...f, amount: e.target.value })}
          required
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
        <button className="report-action primary">Post Receipt</button>
      </form>
      {f.customer_id && amount > 0 && (
        <Allocation
          title="Allocate to invoices"
          rows={invoices}
          alloc={alloc}
          setAlloc={setAlloc}
          amount={amount}
          allocated={allocated}
        />
      )}
    </div>
  );
}
function Allocation({
  title,
  rows,
  alloc,
  setAlloc,
  amount,
  allocated,
}: {
  title: string;
  rows: JsonRecord[];
  alloc: Record<number, string>;
  setAlloc: (x: Record<number, string>) => void;
  amount: number;
  allocated: number;
}) {
  const { t } = useTranslation();
  return (
    <section className="bg-white border rounded-2xl overflow-hidden">
      <h2 className="p-4 font-bold">{title} (optional)</h2>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[650px]">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Date</th>
              <th>Original / Outstanding</th>
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
                  <td>{r.invoice_number || r.document_number || t("Legacy sales invoice")}</td>
                  <td>{r.invoice_date || r.date}</td>
                  <td>{out}</td>
                  <td>
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
      <div className="p-4 flex flex-wrap gap-6">
        <strong>Receipt: {amount}</strong>
        <strong>Allocated: {allocated}</strong>
        <strong>Unallocated: {amount - allocated}</strong>
      </div>
    </section>
  );
}
