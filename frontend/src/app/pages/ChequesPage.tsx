import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import { chequesApi } from "../../api/finance";
import type { ChequeDto } from "../../types/finance";
import { formatMoney } from "../../utils/batchFormatters";
import {
  ErrorNotice,
  StatusBadge,
  normalizeError,
} from "../components/ErpPrimitives";
import { Field, PageHeader } from "../components/UxPrimitives";
export function ChequesPage() {
  const { t } = useTranslation(),
    [rows, setRows] = useState<ChequeDto[]>([]),
    [status, setStatus] = useState(""),
    [direction, setDirection] = useState(""),
    [error, setError] = useState<any>(null),
    [loading, setLoading] = useState(true);
  const load = () => {
    setLoading(true);
    setError(null);
    chequesApi
      .list({
        page: 1,
        per_page: 100,
        status: status || undefined,
        direction: (direction as any) || undefined,
      })
      .then((r) => setRows(r.data))
      .catch((v) => setError(normalizeError(v)))
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    void load();
  }, [status, direction]);
  async function action(row: ChequeDto, kind: "clear" | "bounce") {
    const number = row.cheque_number || row.check_number;
    if (
      !confirm(
        t(kind === "clear" ? "clearChequeQuestion" : "bounceChequeQuestion", {
          number,
        }),
      )
    )
      return;
    try {
      await chequesApi[kind](row.id);
      await load();
    } catch (v) {
      setError(normalizeError(v));
    }
  }
  return (
    <div className="p-4 md:p-8 space-y-5">
      <PageHeader
        title={t("Cheque Register")}
        description={t(
          "Track incoming and outgoing cheques until they are cleared by the bank or returned unpaid.",
        )}
      />
      <div className="p-4 rounded-xl bg-blue-50 text-sm text-blue-900">
        {t("chequeStatusHelp")}
      </div>
      <ErrorNotice error={error} />
      <div className="grid gap-4 sm:grid-cols-2 max-w-xl">
        <Field label={t("Status")}>
          <select
            className="input"
            value={status}
            onChange={(e) => setStatus(e.target.value)}
          >
            <option value="">{t("All statuses")}</option>
            <option value="pending">{t("Pending")}</option>
            <option value="cleared">{t("Cleared")}</option>
            <option value="bounced">{t("Bounced")}</option>
          </select>
        </Field>
        <Field
          label={t("Direction")}
          help={t(
            "Incoming cheques are received from customers. Outgoing cheques are issued to suppliers or others.",
          )}
        >
          <select
            className="input"
            value={direction}
            onChange={(e) => setDirection(e.target.value)}
          >
            <option value="">{t("All directions")}</option>
            <option value="incoming">{t("Incoming")}</option>
            <option value="outgoing">{t("Outgoing")}</option>
          </select>
        </Field>
      </div>
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <div className="p-10 text-center animate-pulse">
            {t("Loading cheques…")}
          </div>
        ) : rows.length ? (
          <table className="w-full min-w-[760px]">
            <thead>
              <tr>
                <th>{t("Cheque Number")}</th>
                <th>{t("Bank")}</th>
                <th>{t("Direction")}</th>
                <th>{t("Amount")}</th>
                <th>{t("Due Date")}</th>
                <th>{t("Status")}</th>
                <th>{t("Actions")}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td>
                    <Link
                      className="text-teal-700 font-semibold hover:underline"
                      to={`/cheques/${row.id}`}
                    >
                      <bdi dir="ltr">
                        {row.cheque_number ||
                          row.check_number ||
                          t("chequeReference", { id: row.id })}
                      </bdi>
                    </Link>
                  </td>
                  <td>{row.bank || row.bank_name}</td>
                  <td>
                    {t(row.direction === "incoming" ? "Incoming" : "Outgoing")}
                  </td>
                  <td>{formatMoney(row.amount)}</td>
                  <td>
                    <bdi dir="ltr">{row.due_date}</bdi>
                  </td>
                  <td>
                    <StatusBadge value={row.status} />
                  </td>
                  <td>
                    {row.status === "pending" && (
                      <div className="flex flex-wrap gap-2">
                        <button
                          className="report-action"
                          onClick={() => void action(row, "clear")}
                        >
                          {t("Clear Cheque")}
                        </button>
                        <button
                          className="report-action text-red-600"
                          onClick={() => void action(row, "bounce")}
                        >
                          {t("Bounce Cheque")}
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="p-10 text-center">
            <strong>{t("No cheques match these filters.")}</strong>
            <p className="text-sm text-gray-500">
              {t(
                "Choose all statuses and directions to view the full cheque register.",
              )}
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
