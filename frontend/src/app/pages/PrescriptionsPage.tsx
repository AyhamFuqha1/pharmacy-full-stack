import { useEffect, useState } from "react";
import { Plus } from "lucide-react";
import { useNavigate } from "react-router";
import { ApiClientError } from "../../api/client";
import { prescriptionsApi } from "../../api/clinical";
import type { PrescriptionDto } from "../../types/clinical";
import { ErrorBox, Pager, State } from "./PatientsPage";
import { useTranslation } from "react-i18next";

export function PrescriptionsPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [rows, setRows] = useState<PrescriptionDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [status, setStatus] = useState(""),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    prescriptionsApi.list({ page, per_page: 15, status }, controller.signal)
      .then((response) => {
        setRows(response.data);
        setLastPage(response.meta.last_page ?? 1);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(
            value instanceof ApiClientError
              ? value
              : new ApiClientError(
                  500,
                  t("An unexpected server error occurred. Please try again."),
                ),
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, status, reload]);
  return (
    <div className="p-8">
      <div className="flex justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold">{t("Prescriptions")}</h1>
          <p className="text-gray-500">
            {t("Controlled-medicine authorization records")}
          </p>
        </div>
        <button
          onClick={() => navigate("/prescriptions/new")}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> {t("New Prescription")}
        </button>
      </div>
      {error && (
        <ErrorBox
          error={error}
          retry={() => setReload((v) => v + 1)}
          context="Prescription"
        />
      )}
      <select
        className="input max-w-xs mb-5"
        value={status}
        onChange={(e) => {
          setPage(1);
          setStatus(e.target.value);
        }}
      >
        <option value="">{t("All statuses")}</option>
        <option value="active">{t("Active")}</option>
        <option value="fulfilled">{t("Fulfilled")}</option>
        <option value="expired">{t("Expired")}</option>
        <option value="void">{t("Void")}</option>
      </select>
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <State text={t("Loading prescriptions…")} />
        ) : rows.length === 0 ? (
          <State text={t("No prescriptions found.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("Prescription")}</th>
                <th>{t("Patient")}</th>
                <th>{t("Prescriber")}</th>
                <th>{t("Prescribed")}</th>
                <th>{t("Expires")}</th>
                <th>{t("Status")}</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((row) => (
                <tr
                  key={row.id}
                  onClick={() => navigate(`/prescriptions/${row.id}`)}
                  className="cursor-pointer"
                >
                  <td className="p-4 font-bold"><bdi dir="ltr">#{row.id}</bdi></td>
                  <td>
                    {row.patient?.full_name ?? t("patientReference", { id: row.patient_id })}
                  </td>
                  <td>{row.prescriber_name}</td>
                  <td><bdi dir="ltr">{localizedDate(row.prescribed_at, i18n.resolvedLanguage)}</bdi></td>
                  <td>
                    {row.expires_at ? <bdi dir="ltr">{localizedDate(row.expires_at, i18n.resolvedLanguage)}</bdi> : "—"}
                  </td>
                  <td className="capitalize">{t(row.status)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pager page={page} lastPage={lastPage} setPage={setPage} />
    </div>
  );
}
function localizedDate(value: string, language?: string) {
  return new Intl.DateTimeFormat(language === "ar" ? "ar" : "en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
}
