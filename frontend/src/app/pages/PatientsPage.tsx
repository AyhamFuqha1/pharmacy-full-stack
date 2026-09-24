import { useEffect, useState } from "react";
import { Eye, Pencil, Plus } from "lucide-react";
import { useNavigate } from "react-router";
import { ApiClientError } from "../../api/client";
import { patientsApi } from "../../api/clinical";
import type { PatientDto, PatientPayload } from "../../types/clinical";
import { useTranslation } from "react-i18next";

const empty: PatientPayload = {
  branch_id: null,
  full_name: "",
  phone_number: null,
  date_of_birth: null,
  gender: null,
  allergies: null,
  notes: null,
  is_active: true,
};
export function PatientsPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [rows, setRows] = useState<PatientDto[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [search, setSearch] = useState(""),
    [applied, setApplied] = useState(""),
    [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(""),
    [saving, setSaving] = useState(false),
    [editing, setEditing] = useState<PatientDto | "new" | null>(null),
    [form, setForm] = useState<PatientPayload>(empty),
    [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    patientsApi
      .list({ page, per_page: 15, search: applied }, controller.signal)
      .then((response) => {
        setRows(response.data);
        setLastPage(response.last_page);
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
  }, [page, applied, reload]);
  function open(patient?: PatientDto) {
    setEditing(patient ?? "new");
    setForm(
      patient
        ? {
            branch_id: patient.branch_id,
            full_name: patient.full_name,
            phone_number: patient.phone_number,
            date_of_birth: patient.date_of_birth,
            gender: patient.gender,
            allergies: patient.allergies,
            notes: patient.notes,
            is_active: patient.is_active,
          }
        : empty,
    );
    setFieldErrors({});
    setError(null);
  }
  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form.full_name.trim()) {
      setFieldErrors({ full_name: t("Full name is required.") });
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const result =
        editing === "new"
          ? await patientsApi.create(form)
          : editing
            ? await patientsApi.update(editing.id, form)
            : null;
      if (result)
        setSuccess(t(editing === "new" ? "patientCreated" : "patientUpdated", { name: result.full_name }));
      setEditing(null);
      setReload((v) => v + 1);
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
  return (
    <div className="p-8">
      <div className="flex justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold">{t("Patients")}</h1>
          <p className="text-gray-500">{t("Persisted patient records")}</p>
        </div>
        <button
          onClick={() => open()}
          className="flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
        >
          <Plus /> {t("Add Patient")}
        </button>
      </div>
      {success && <Notice text={success} />}
      {error && (
        <ErrorBox
          error={error}
          retry={() => setReload((v) => v + 1)}
          context="Patient"
        />
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setApplied(search.trim());
        }}
        className="flex gap-2 mb-5"
      >
        <input
          className="input max-w-md"
          placeholder={t("Search patient name")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="px-4 bg-gray-900 text-white rounded-xl">
          {t("Search")}
        </button>
      </form>
      {editing && (
        <form
          onSubmit={(e) => void save(e)}
          className="bg-white border rounded-2xl p-6 mb-6"
        >
          <h2 className="text-xl font-bold mb-5">
            {t(editing === "new" ? "New patient" : "Edit patient")}
          </h2>
          <div className="grid md:grid-cols-2 gap-4">
            <Field label={t("Full name")} error={fieldErrors.full_name}>
              <input
                className="input"
                value={form.full_name}
                onChange={(e) =>
                  setForm({ ...form, full_name: e.target.value })
                }
              />
            </Field>
            <Field label={t("Phone")} error={fieldErrors.phone_number}>
              <input
                className="input"
                value={form.phone_number ?? ""}
                onChange={(e) =>
                  setForm({ ...form, phone_number: e.target.value || null })
                }
              />
            </Field>
            <Field label={t("Date of birth")} error={fieldErrors.date_of_birth}>
              <input
                type="date"
                className="input"
                value={form.date_of_birth ?? ""}
                onChange={(e) =>
                  setForm({ ...form, date_of_birth: e.target.value || null })
                }
              />
            </Field>
            <Field label={t("Gender")} error={fieldErrors.gender}>
              <select
                className="input"
                value={form.gender ?? ""}
                onChange={(e) =>
                  setForm({
                    ...form,
                    gender: (e.target.value || null) as PatientDto["gender"],
                  })
                }
              >
                <option value="">{t("Not specified")}</option>
                <option value="male">{t("Male")}</option>
                <option value="female">{t("Female")}</option>
                <option value="other">{t("Other")}</option>
              </select>
            </Field>
            <Field label={t("Allergies")} error={fieldErrors.allergies}>
              <textarea
                className="input"
                value={form.allergies ?? ""}
                onChange={(e) =>
                  setForm({ ...form, allergies: e.target.value || null })
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
            <label className="flex gap-2 items-center">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) =>
                  setForm({ ...form, is_active: e.target.checked })
                }
              />{" "}
              {t("Active")}
            </label>
          </div>
          <div className="flex gap-3 mt-5">
            <button
              type="button"
              onClick={() => setEditing(null)}
              className="px-5 py-2.5 bg-gray-100 rounded-xl"
            >
              {t("Cancel")}
            </button>
            <button
              disabled={saving}
              className="px-5 py-2.5 bg-teal-600 text-white rounded-xl"
            >
              {t(saving ? "Saving…" : "Save Patient")}
            </button>
          </div>
        </form>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {loading ? (
          <State text={t("Loading patients…")} />
        ) : rows.length === 0 ? (
          <State text={t("No patients found.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("Patient")}</th>
                <th>{t("Phone")}</th>
                <th>{t("Gender")}</th>
                <th>{t("Status")}</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="p-4 font-semibold">{row.full_name}</td>
                  <td>{row.phone_number ?? "—"}</td>
                  <td className="capitalize">{row.gender ? t(row.gender) : "—"}</td>
                  <td>{t(row.is_active ? "Active" : "Inactive")}</td>
                  <td className="space-x-2">
                    <button
                      onClick={() => navigate(`/patients/${row.id}`)}
                      className="p-2 text-teal-600"
                    >
                      <Eye />
                    </button>
                    <button
                      onClick={() => open(row)}
                      className="p-2 text-gray-600"
                    >
                      <Pencil />
                    </button>
                  </td>
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
export function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label>
      <span className="block text-sm font-medium mb-2">{label}</span>
      {children}
      {error && (
        <span className="block text-xs text-red-600 mt-1">{error}</span>
      )}
    </label>
  );
}
export function ErrorBox({
  error,
  retry,
  context,
}: {
  error: ApiClientError;
  retry: () => void;
  context: string;
}) {
  const { t } = useTranslation();
  const contextLabel = t(context);
  return (
    <div className="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl">
      <strong>
        {error.status === 403
          ? t("contextAccessDenied", { context: contextLabel })
          : error.status === 404
            ? t("contextNotFound", { context: contextLabel })
            : error.status === 409
              ? t("contextConflict", { context: contextLabel })
              : error.status === 422
                ? t("contextValidationFailed", { context: contextLabel })
                : error.status === 0
                  ? t("Connection failure")
                  : t("Unexpected technical failure")}
      </strong>
      <p>{error.message}</p>
      {error.status === 422 &&
        Object.entries(error.errors).map(([field, messages]) => (
          <p key={field} className="text-sm">
            {field}: {messages.join(" ")}
          </p>
        ))}
      {error.status !== 403 && (
        <button onClick={retry} className="mt-2 underline">
          {t("Retry")}
        </button>
      )}
    </div>
  );
}
export function Pager({
  page,
  lastPage,
  setPage,
}: {
  page: number;
  lastPage: number;
  setPage: (value: number) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="flex justify-end gap-3 mt-4">
      <button
        disabled={page <= 1}
        onClick={() => setPage(page - 1)}
        className="px-4 py-2 border rounded-xl disabled:opacity-40"
      >
        {t("Previous")}
      </button>
      <span className="py-2">
        <bdi dir="ltr">{page} / {lastPage}</bdi>
      </span>
      <button
        disabled={page >= lastPage}
        onClick={() => setPage(page + 1)}
        className="px-4 py-2 border rounded-xl disabled:opacity-40"
      >
        {t("Next")}
      </button>
    </div>
  );
}
export function State({ text }: { text: string }) {
  return <div className="p-12 text-center text-gray-500">{text}</div>;
}
export function Notice({ text }: { text: string }) {
  return (
    <div className="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
      {text}
    </div>
  );
}
