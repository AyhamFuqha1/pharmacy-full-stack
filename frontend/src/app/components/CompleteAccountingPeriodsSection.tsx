import { useEffect, useState } from "react";
import { accountingPeriodsApi } from "../../api/admin";
import { ApiClientError } from "../../api/client";
import type { AccountingPeriodDto } from "../../types/admin";
import { useAuth } from "../../auth/useAuth";
import { ErrorNotice, StatusBadge, normalizeError } from "./ErpPrimitives";
import { useTranslation } from "react-i18next";

export function CompleteAccountingPeriodsSection() {
  const { t } = useTranslation();
  const { hasPermission } = useAuth(),
    canManage = hasPermission("accounting.periods.manage");
  const [rows, setRows] = useState<AccountingPeriodDto[]>([]),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState("");
  const [selected, setSelected] = useState<AccountingPeriodDto | null>(null),
    [reason, setReason] = useState(""),
    [saving, setSaving] = useState(false);
  const load = () =>
    accountingPeriodsApi
      .list(1, 100)
      .then((r) => setRows(r.data))
      .catch((v) => setError(normalizeError(v)));
  useEffect(() => {
    void load();
  }, []);
  async function reopen(e: React.FormEvent) {
    e.preventDefault();
    if (!selected || !reason.trim()) return;
    setSaving(true);
    setError(null);
    try {
      await accountingPeriodsApi.reopen(selected.id, reason.trim());
      setSelected(null);
      setReason("");
      setSuccess(t("accountingPeriodReopened", { name: selected.name }));
      await load();
    } catch (v) {
      setError(normalizeError(v));
    } finally {
      setSaving(false);
    }
  }
  return (
    <div className="space-y-4">
      {success && (
        <div className="p-4 bg-emerald-50 text-emerald-800 rounded-xl">
          {success}
        </div>
      )}
      <ErrorNotice error={error} />
      <div className="bg-white border rounded-2xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr>
              <th>{t("Name")}</th>
              <th>{t("Dates")}</th>
              <th>{t("Status")}</th>
              <th>{t("Action")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td>{row.name}</td>
                <td>
                  <bdi dir="ltr">{row.start_date} – {row.end_date}</bdi>
                </td>
                <td>
                  <StatusBadge value={row.status} />
                </td>
                <td>
                  {row.status === "closed" && canManage && (
                    <button
                      className="report-action"
                      onClick={() => {
                        setSelected(row);
                        setReason("");
                        setError(null);
                      }}
                    >
                      {t("Reopen")}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {selected && (
        <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
          <form
            onSubmit={reopen}
            className="bg-white rounded-2xl p-6 w-full max-w-lg space-y-4"
          >
            <div>
              <h2 className="text-xl font-bold">{t("reopenPeriodName", { name: selected.name })}</h2>
              <p className="text-gray-500">
                {t("Provide the required audit reason.")}
              </p>
            </div>
            <label>
              <span className="block mb-2 font-medium">{t("Reason")}</span>
              <textarea
                autoFocus
                required
                className="input min-h-28"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </label>
            <div className="flex justify-end gap-2">
              <button
                type="button"
                className="report-action"
                onClick={() => setSelected(null)}
              >
                {t("Cancel")}
              </button>
              <button
                disabled={saving || !reason.trim()}
                className="report-action primary disabled:opacity-50"
              >
                {t(saving ? "Reopening…" : "Reopen Period")}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
