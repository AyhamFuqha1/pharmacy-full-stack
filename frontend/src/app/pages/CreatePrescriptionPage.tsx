import { useEffect, useState } from "react";
import { ArrowLeft } from "lucide-react";
import { useNavigate, useSearchParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { patientsApi, prescriptionsApi } from "../../api/clinical";
import type { PrescriptionPayload } from "../../types/clinical";
import { ErrorBox, Field, Notice, State } from "./PatientsPage";
import { useTranslation } from "react-i18next";
import { RemoteEntitySelect, type SelectOption } from "../components/UxPrimitives";
import { patientEntityLoader } from "../entityLoaders";

export function CreatePrescriptionPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [selectedPatient, setSelectedPatient] = useState<SelectOption | null>(null),
    [loading, setLoading] = useState(false),
    [saving, setSaving] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null),
    [success] = useState(""),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [form, setForm] = useState<PrescriptionPayload>({
    branch_id: null,
    patient_id: Number(params.get("patient_id") || 0),
    prescriber_name: "",
    prescriber_license: "",
    prescribed_at: new Date().toISOString().slice(0, 10),
    expires_at: null,
    notes: null,
  });
  useEffect(() => {
    if (!form.patient_id) return;
    const controller = new AbortController();
    setLoading(true);
    patientsApi.detail(form.patient_id, controller.signal)
      .then((patient) => setSelectedPatient({ id: patient.id, label: patient.full_name }))
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
  }, []);
  async function save(e: React.FormEvent) {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (!form.patient_id) errors.patient_id = t("Patient is required.");
    if (!form.prescriber_name.trim())
      errors.prescriber_name = t("Prescriber name is required.");
    if (!form.prescriber_license.trim())
      errors.prescriber_license = t("License is required.");
    if (!form.prescribed_at)
      errors.prescribed_at = t("Prescribed date is required.");
    setFieldErrors(errors);
    if (Object.keys(errors).length) return;
    setSaving(true);
    setError(null);
    try {
      const response = await prescriptionsApi.create(form);
      navigate(`/prescriptions/${response.id}`, {
        replace: true,
        state: {
          success: t("Prescription created. Add controlled medicine items below."),
        },
      });
    } catch (value) {
      const requestError =
        value instanceof ApiClientError
          ? value
          : new ApiClientError(
              500,
              t("An unexpected server error occurred. Please try again."),
            );
      setError(requestError);
      setFieldErrors(
        Object.fromEntries(
          Object.entries(requestError.errors).map(([key, messages]) => [
            key,
            messages[0],
          ]),
        ),
      );
    } finally {
      setSaving(false);
    }
  }
  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading patients…")} />
      </div>
    );
  return (
    <div className="p-8">
      <button
        onClick={() => navigate("/prescriptions")}
        className="flex gap-2 mb-6"
      >
        <ArrowLeft /> {t("Back to Prescriptions")}
      </button>
      <h1 className="text-3xl font-bold">{t("New Prescription")}</h1>
      <p className="text-gray-500 mb-8">
        {t("Create persisted prescription metadata")}
      </p>
      {success && <Notice text={success} />}
      {error && (
        <ErrorBox
          error={error}
          retry={() => undefined}
          context="Prescription"
        />
      )}
      <form
        onSubmit={(e) => void save(e)}
        className="bg-white border rounded-2xl p-6 max-w-4xl"
      >
        <div className="grid md:grid-cols-2 gap-4">
          <RemoteEntitySelect label={t("Patient")} error={fieldErrors.patient_id} value={String(form.patient_id || "")} onChange={(value) => setForm({ ...form, patient_id: Number(value) })} onSelect={setSelectedPatient} selectedOption={selectedPatient} load={patientEntityLoader} placeholder={t("Search patient name")} required />
          <Field label={t("Prescriber name")} error={fieldErrors.prescriber_name}>
            <input
              className="input"
              value={form.prescriber_name}
              onChange={(e) =>
                setForm({ ...form, prescriber_name: e.target.value })
              }
            />
          </Field>
          <Field
            label={t("Prescriber license")}
            error={fieldErrors.prescriber_license}
          >
            <input
              className="input"
              value={form.prescriber_license}
              onChange={(e) =>
                setForm({ ...form, prescriber_license: e.target.value })
              }
            />
          </Field>
          <Field label={t("Prescribed at")} error={fieldErrors.prescribed_at}>
            <input
              type="date"
              className="input"
              value={form.prescribed_at}
              onChange={(e) =>
                setForm({ ...form, prescribed_at: e.target.value })
              }
            />
          </Field>
          <Field label={t("Expires at")} error={fieldErrors.expires_at}>
            <input
              type="date"
              className="input"
              value={form.expires_at ?? ""}
              onChange={(e) =>
                setForm({ ...form, expires_at: e.target.value || null })
              }
            />
          </Field>
          <Field label={t("Notes")} error={fieldErrors.notes}>
            <textarea
              className="input"
              value={form.notes ?? ""}
              onChange={(e) =>
                setForm({ ...form, notes: e.target.value || null })
              }
            />
          </Field>
        </div>
        <button
          disabled={saving}
          className="mt-6 px-5 py-3 bg-teal-600 text-white rounded-xl disabled:opacity-50"
        >
          {t(saving ? "Creating…" : "Create Prescription")}
        </button>
      </form>
    </div>
  );
}
