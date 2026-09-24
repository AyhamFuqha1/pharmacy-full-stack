import { useEffect, useState } from "react";
import { ArrowLeft } from "lucide-react";
import { useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { patientsApi, prescriptionsApi } from "../../api/clinical";
import type { PatientDto, PrescriptionDto } from "../../types/clinical";
import { ErrorBox, State } from "./PatientsPage";
import { useAuth } from "../../auth/useAuth";
import { useTranslation } from "react-i18next";

export function PatientDetailsPage() {
  const { t, i18n } = useTranslation();
  const { id } = useParams();
  const patientId = Number(id);
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const canPrescriptions = hasPermission("prescriptions.manage");
  const [patient, setPatient] = useState<PatientDto | null>(null),
    [prescriptions, setPrescriptions] = useState<PrescriptionDto[]>([]),
    [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [reload, setReload] = useState(0);
  useEffect(() => {
    if (!Number.isInteger(patientId) || patientId <= 0) {
      setError(new ApiClientError(404, t("Patient not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    setLoading(true);
    Promise.all([
      patientsApi.detail(patientId, controller.signal),
      canPrescriptions
        ? prescriptionsApi.list(
            { patient_id: patientId, per_page: 100 },
            controller.signal,
          )
        : Promise.resolve(null),
    ])
      .then(([p, r]) => {
        setPatient(p);
        setPrescriptions(r?.data ?? []);
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
  }, [patientId, reload, canPrescriptions]);
  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading patient…")} />
      </div>
    );
  if (error || !patient)
    return (
      <div className="p-8">
        <ErrorBox
          error={error ?? new ApiClientError(404, t("Patient not found."))}
          retry={() => setReload((v) => v + 1)}
          context="Patient"
        />
      </div>
    );
  return (
    <div className="p-8">
      <button onClick={() => navigate("/patients")} className="flex gap-2 mb-6">
        <ArrowLeft /> {t("Back to Patients")}
      </button>
      <h1 className="text-3xl font-bold">{patient.full_name}</h1>
      <p className="text-gray-500 mb-8">{t("Persisted patient record")}</p>
      <div className="grid md:grid-cols-3 gap-4 bg-white border rounded-2xl p-6 mb-6">
        <Info label={t("Phone")} value={patient.phone_number ?? t("Not recorded")} isolate={Boolean(patient.phone_number)} />
        <Info
          label={t("Date of birth")}
          value={
            patient.date_of_birth
              ? localizedDate(patient.date_of_birth, i18n.resolvedLanguage)
              : t("Not recorded")
          }
        />
        <Info label={t("Gender")} value={patient.gender ? t(patient.gender) : t("Not recorded")} />
        <Info label={t("Allergies")} value={patient.allergies ?? t("None recorded")} />
        <Info label={t("Notes")} value={patient.notes ?? t("None recorded")} />
        <Info
          label={t("Status")}
          value={t(patient.is_active ? "Active" : "Inactive")}
        />
      </div>
      <div className="bg-white border rounded-2xl overflow-hidden">
        <div className="p-5 border-b flex justify-between">
          <h2 className="font-bold">{t("Prescriptions")}</h2>
          <button
            onClick={() =>
              navigate(`/prescriptions/new?patient_id=${patient.id}`)
            }
            className="text-teal-600"
          >
            {t("New Prescription")}
          </button>
        </div>
        {prescriptions.length === 0 ? (
          <State text={t("No prescriptions recorded for this patient.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("Prescription")}</th>
                <th>{t("Prescriber")}</th>
                <th>{t("Date")}</th>
                <th>{t("Status")}</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {prescriptions.map((row) => (
                <tr
                  key={row.id}
                  onClick={() => navigate(`/prescriptions/${row.id}`)}
                  className="cursor-pointer"
                >
                  <td className="p-4 font-bold"><bdi dir="ltr">#{row.id}</bdi></td>
                  <td>{row.prescriber_name}</td>
                  <td><bdi dir="ltr">{localizedDate(row.prescribed_at, i18n.resolvedLanguage)}</bdi></td>
                  <td className="capitalize">{t(row.status)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
function localizedDate(value: string, language?: string) {
  return new Intl.DateTimeFormat(language === "ar" ? "ar" : "en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
}
function Info({ label, value, isolate = false }: { label: string; value: string; isolate?: boolean }) {
  return (
    <div className="bg-gray-50 rounded-xl p-4">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="font-semibold mt-1 capitalize">{isolate ? <bdi dir="ltr">{value}</bdi> : value}</p>
    </div>
  );
}
