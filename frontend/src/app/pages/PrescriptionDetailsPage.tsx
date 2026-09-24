import { useEffect, useState } from "react";
import { ArrowLeft, Plus } from "lucide-react";
import { useLocation, useNavigate, useParams } from "react-router";
import { ApiClientError } from "../../api/client";
import { prescriptionsApi } from "../../api/clinical";
import type { DispenseResultDto, PrescriptionDto } from "../../types/clinical";
import { ErrorBox, Field, Notice, State } from "./PatientsPage";
import { useTranslation } from "react-i18next";
import { RemoteEntitySelect } from "../components/UxPrimitives";
import { controlledProductEntityLoader } from "../entityLoaders";

export function PrescriptionDetailsPage() {
  const { t, i18n } = useTranslation();
  const { id } = useParams();
  const prescriptionId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const [prescription, setPrescription] = useState<PrescriptionDto | null>(
      null,
    ),
    [lastDispense, setLastDispense] = useState<DispenseResultDto | null>(null);
  const [loading, setLoading] = useState(true),
    [saving, setSaving] = useState(false),
    [error, setError] = useState<ApiClientError | null>(null),
    [success, setSuccess] = useState(
      (location.state as { success?: string } | null)?.success ?? "",
    ),
    [reload, setReload] = useState(0);
  const [productId, setProductId] = useState(""),
    [quantity, setQuantity] = useState("1"),
    [mode, setMode] = useState<"add" | "dispense" | null>(null);
  useEffect(() => {
    if (!Number.isInteger(prescriptionId) || prescriptionId <= 0) {
      setError(new ApiClientError(404, t("Prescription not found.")));
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    prescriptionsApi.detail(prescriptionId, controller.signal)
      .then((response) => {
        setPrescription(response.data);
      })
      .catch((value: unknown) => {
        if (!(value instanceof DOMException && value.name === "AbortError"))
          setError(value instanceof ApiClientError ? value : unexpected(t("An unexpected server error occurred. Please try again.")));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [prescriptionId, reload]);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (
      !productId ||
      !Number.isInteger(Number(quantity)) ||
      Number(quantity) < 1 ||
      !mode
    ) {
      setError(
        new ApiClientError(
          422,
          t("Select a controlled medicine and enter a positive whole quantity."),
        ),
      );
      return;
    }
    setSaving(true);
    setError(null);
    try {
      if (mode === "add") {
        await prescriptionsApi.addItem(prescriptionId, {
          product_id: Number(productId),
          allowed_quantity: Number(quantity),
        });
        setSuccess(t("Prescription item saved."));
      } else {
        const response = await prescriptionsApi.dispense(prescriptionId, {
          product_id: Number(productId),
          quantity: Number(quantity),
        });
        setLastDispense(response.data);
        setSuccess(t("Prescription dispensed using persisted FEFO allocations."));
      }
      setMode(null);
      setProductId("");
      setQuantity("1");
      setReload((v) => v + 1);
    } catch (value) {
      setError(value instanceof ApiClientError ? value : unexpected(t("An unexpected server error occurred. Please try again.")));
    } finally {
      setSaving(false);
    }
  }
  if (loading)
    return (
      <div className="p-8">
        <State text={t("Loading prescription…")} />
      </div>
    );
  if (error && !prescription)
    return (
      <div className="p-8">
        <ErrorBox
          error={error}
          retry={() => setReload((v) => v + 1)}
          context="Prescription"
        />
      </div>
    );
  if (!prescription) return null;
  const items = prescription.items ?? [],
    active = prescription.status === "active";
  return (
    <div className="p-8">
      <button
        onClick={() => navigate("/prescriptions")}
        className="flex gap-2 mb-6"
      >
        <ArrowLeft /> {t("Back to Prescriptions")}
      </button>
      <div className="flex justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold">
            {t("prescriptionReference", { id: prescription.id })}
          </h1>
          <p className="text-gray-500">{t("Controlled medicine authorization")}</p>
        </div>
        <span className="capitalize px-4 py-2 bg-gray-100 rounded-full">
          {t(prescription.status)}
        </span>
      </div>
      {success && <Notice text={success} />}
      {error && (
        <ErrorBox
          error={error}
          retry={() => setError(null)}
          context="Dispensing"
        />
      )}
      <div className="bg-white border rounded-2xl p-6 grid md:grid-cols-3 gap-4 mb-6">
        <Info
          label={t("Patient")}
          value={prescription.patient?.full_name ?? t("Patient unavailable")}
        />
        <Info label={t("Prescriber")} value={prescription.prescriber_name} />
        <Info label={t("License")} value={prescription.prescriber_license} isolate />
        <Info
          label={t("Prescribed")}
          value={localizedDate(prescription.prescribed_at, i18n.resolvedLanguage)}
        />
        <Info
          label={t("Expires")}
          value={
            prescription.expires_at
              ? localizedDate(prescription.expires_at, i18n.resolvedLanguage)
              : t("No expiry")
          }
        />
        <Info label={t("Notes")} value={prescription.notes ?? t("None")} />
      </div>
      {lastDispense && <DispenseResult result={lastDispense} />}
      {active && (
        <div className="flex gap-3 mb-5">
          <button
            onClick={() => setMode("add")}
            className="flex gap-2 px-4 py-2.5 bg-teal-600 text-white rounded-xl"
          >
            <Plus /> {t("Add Item")}
          </button>
          <button
            onClick={() => setMode("dispense")}
            className="px-4 py-2.5 bg-emerald-600 text-white rounded-xl"
          >
            {t("Dispense")}
          </button>
        </div>
      )}
      {mode && (
        <form
          onSubmit={(e) => void submit(e)}
          className="bg-white border rounded-2xl p-5 mb-6 grid md:grid-cols-3 gap-4 items-end"
        >
          <RemoteEntitySelect label={t("Controlled product")} value={productId} onChange={setProductId} load={controlledProductEntityLoader} placeholder={t("Search controlled product name or barcode")} required />
          <Field
            label={t(mode === "add" ? "Allowed quantity" : "Dispense quantity")}
          >
            <input
              className="input"
              type="number"
              min="1"
              step="1"
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
            />
          </Field>
          <button
            disabled={saving}
            className="h-11 bg-teal-600 text-white rounded-xl"
          >
            {t(saving ? "Saving…" : mode === "add" ? "Save Item" : "Confirm Dispense")}
          </button>
        </form>
      )}
      <div className="bg-white border rounded-2xl overflow-x-auto">
        {items.length === 0 ? (
          <State text={t("No prescription items added.")} />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="p-4">{t("Product")}</th>
                <th>{t("Allowed")}</th>
                <th>{t("Dispensed")}</th>
                <th>{t("Remaining")}</th>
                <th>{t("Status")}</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {items.map((item) => (
                <tr key={item.id}>
                  <td className="p-4 font-semibold">
                    {item.product?.name ?? t("productReference", { id: item.product_id ?? t("unknown") })}
                  </td>
                  <td>{item.allowed_quantity}</td>
                  <td>{item.dispensed_quantity}</td>
                  <td>
                    {item.remaining_quantity ??
                      item.allowed_quantity - item.dispensed_quantity}
                  </td>
                  <td>
                    {item.dispensed_quantity >= item.allowed_quantity
                      ? t("Fulfilled")
                      : t("Pending")}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
function DispenseResult({ result }: { result: DispenseResultDto }) {
  const { t, i18n } = useTranslation();
  const total = result.allocations.reduce(
    (sum, item) => sum + item.quantity,
    0,
  );
  return (
    <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 mb-6">
      <h2 className="text-lg font-bold text-emerald-900">
        {t("Latest Dispense Allocation")}
      </h2>
      <p className="mt-1 text-sm text-emerald-800">
        <strong>{t("Disposition")}:</strong> {t("Non-commercial dispense")}
      </p>
      <div className="grid sm:grid-cols-3 gap-3 my-4">
        <Info label={t("Total dispensed now")} value={String(total)} isolate />
        <Info
          label={t("Remaining allowance")}
          value={String(result.remaining_quantity)}
          isolate
        />
        <Info label={t("Prescription status")} value={t(result.status)} />
      </div>
      <div className="overflow-x-auto bg-white rounded-xl">
        <table className="w-full">
          <thead className="bg-emerald-100">
            <tr>
              <th className="p-3">{t("Batch")}</th>
              <th>{t("Expiry date")}</th>
              <th>{t("Quantity")}</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {result.allocations.map((item) => (
              <tr key={item.id}>
                <td className="p-3 font-mono">
                  <bdi dir="ltr">{item.batch_number} (#{item.batch_id})</bdi>
                </td>
                <td>{item.expiry_date ? <bdi dir="ltr">{localizedDate(item.expiry_date, i18n.resolvedLanguage)}</bdi> : t("No expiry date")}</td>
                <td><bdi dir="ltr">{item.quantity}</bdi></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
function localizedDate(value: string, language?: string) {
  return new Intl.DateTimeFormat(language === "ar" ? "ar" : "en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
}
function Info({ label, value, isolate = false }: { label: string; value: string; isolate?: boolean }) {
  return (
    <div className="bg-white/70 rounded-xl p-4">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="font-semibold mt-1 capitalize">{isolate ? <bdi dir="ltr">{value}</bdi> : value}</p>
    </div>
  );
}
function unexpected(message: string) {
  return new ApiClientError(
    500,
    message,
  );
}
