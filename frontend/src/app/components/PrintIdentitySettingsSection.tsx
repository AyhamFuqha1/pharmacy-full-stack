import { useEffect, useMemo, useState } from "react";
import { Plus, Save, Trash2 } from "lucide-react";
import { printIdentityApi, type PrintIdentityConfiguration } from "../../api/printIdentity";
import { ApiClientError } from "../../api/client";
import { useTranslation } from "react-i18next";
import { ErrorNotice, normalizeError } from "./ErpPrimitives";
import { PrintIdentityHeader } from "./PrintIdentityHeader";

const fieldLabels: Record<string, string> = {
  business_name: "Business Name", business_name_secondary: "Subtitle / Trade Name", address: "Address",
  phone: "Phone", secondary_phone: "Secondary Phone", mobile: "Mobile", email: "Email", website: "Website",
  tax_number: "Tax Number", registration_number: "Registration Number", license_number: "License Number",
};

export function PrintIdentitySettingsSection() {
  const { t } = useTranslation();
  const [form, setForm] = useState<PrintIdentityConfiguration | null>(null);
  const [logo, setLogo] = useState<File | null>(null), [error, setError] = useState<ApiClientError | null>(null);
  const [busy, setBusy] = useState(false), [saved, setSaved] = useState(false);
  const localLogo = useMemo(() => logo ? URL.createObjectURL(logo) : undefined, [logo]);
  useEffect(() => () => { if (localLogo) URL.revokeObjectURL(localLogo); }, [localLogo]);
  useEffect(() => { printIdentityApi.get().then((response) => setForm(response.data)).catch((value) => setError(normalizeError(value))); }, []);
  if (!form) return <section className="bg-white border rounded-2xl p-6"><ErrorNotice error={error} /><p>{t("Loading print identity…")}</p></section>;
  const patchField = (key: string, patch: Partial<PrintIdentityConfiguration["fields"][string]>) => setForm({ ...form, fields: { ...form.fields, [key]: { ...form.fields[key], ...patch } } });
  const resolved = {
    business_name: form.fields.business_name.value,
    business_name_secondary: form.fields.business_name_secondary.visible ? form.fields.business_name_secondary.value : null,
    logo_url: form.logo.visible ? form.logo.url : null,
    fields: [
      ...Object.entries(form.fields).filter(([, field]) => field.visible && field.value).map(([key, field]) => ({ key, value: field.value, order: field.order })),
      ...form.custom_fields.filter((field) => field.visible && field.label && field.value).map((field, index) => ({ key: `custom_${index}`, label: field.label, value: field.value, order: field.order })),
    ].sort((a, b) => a.order - b.order),
  };
  async function save() {
    setBusy(true); setError(null); setSaved(false);
    try {
      const body = new FormData();
      body.append("payload", JSON.stringify({ fields: form.fields, logo_visible: form.logo.visible, logo_order: form.logo.order, remove_logo: false, custom_fields: form.custom_fields }));
      if (logo) body.append("logo", logo);
      const response = await printIdentityApi.update(body); setForm(response.data); setLogo(null); setSaved(true);
    } catch (value) { setError(normalizeError(value)); } finally { setBusy(false); }
  }
  return <div className="space-y-5">
    <ErrorNotice error={error} />
    {saved && <p className="rounded-xl border border-green-300 bg-green-50 p-3 text-green-800">{t("Print identity saved.")}</p>}
    <section className="bg-white border rounded-2xl p-5 space-y-4">
      <h2 className="text-xl font-bold">{t("Print Identity")}</h2>
      <p className="text-sm text-gray-500">{t("Configure the business information used by future printable document headers.")}</p>
      <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr><th className="text-start">{t("Field")}</th><th className="text-start">{t("Value")}</th><th>{t("Show in Print")}</th><th>{t("Display Order")}</th></tr></thead><tbody>
        {Object.entries(form.fields).map(([key, field]) => <tr className="border-t" key={key}><td className="p-2 font-medium">{t(fieldLabels[key])}</td><td className="p-2"><input className="input" type={key === "email" ? "email" : key === "website" ? "url" : "text"} maxLength={key === "address" ? 500 : 255} value={field.value} onChange={(event) => patchField(key, { value: event.target.value })} required={key === "business_name"} /></td><td className="p-2 text-center"><input type="checkbox" checked={field.visible} onChange={(event) => patchField(key, { visible: event.target.checked })} /></td><td className="p-2"><input className="input w-24" type="number" min="0" max="999" value={field.order} onChange={(event) => patchField(key, { order: Number(event.target.value) })} /></td></tr>)}
      </tbody></table></div>
      <div className="grid gap-3 md:grid-cols-[1fr_auto_auto] items-end"><label><span className="block text-sm font-medium mb-1">{t("Business Logo")}</span><input className="input" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" onChange={(event) => setLogo(event.target.files?.[0] || null)} /></label><label className="flex items-center gap-2 pb-3"><input type="checkbox" checked={form.logo.visible} onChange={(event) => setForm({ ...form, logo: { ...form.logo, visible: event.target.checked } })} />{t("Show in Print")}</label><label><span className="block text-sm mb-1">{t("Display Order")}</span><input className="input w-24" type="number" min="0" max="999" value={form.logo.order} onChange={(event) => setForm({ ...form, logo: { ...form.logo, order: Number(event.target.value) } })} /></label></div>
    </section>
    <section className="bg-white border rounded-2xl p-5 space-y-3"><div className="flex justify-between gap-3"><div><h2 className="font-bold">{t("Custom Header Fields")}</h2><p className="text-sm text-gray-500">{t("Add up to 20 additional business-information rows.")}</p></div><button className="report-action" disabled={form.custom_fields.length >= 20} onClick={() => setForm({ ...form, custom_fields: [...form.custom_fields, { label: "", value: "", visible: true, order: 100 + form.custom_fields.length }] })}><Plus size={16} />{t("Add Field")}</button></div>
      {form.custom_fields.map((field, index) => <div className="grid gap-2 rounded-xl border p-3 md:grid-cols-[1fr_2fr_auto_100px_auto]" key={index}><input className="input" maxLength={100} placeholder={t("Label")} value={field.label} onChange={(event) => { const next = [...form.custom_fields]; next[index] = { ...field, label: event.target.value }; setForm({ ...form, custom_fields: next }); }} /><input className="input" maxLength={500} placeholder={t("Value")} value={field.value} onChange={(event) => { const next = [...form.custom_fields]; next[index] = { ...field, value: event.target.value }; setForm({ ...form, custom_fields: next }); }} /><label className="flex items-center gap-2"><input type="checkbox" checked={field.visible} onChange={(event) => { const next = [...form.custom_fields]; next[index] = { ...field, visible: event.target.checked }; setForm({ ...form, custom_fields: next }); }} />{t("Visible")}</label><input className="input" type="number" min="0" max="999" value={field.order} onChange={(event) => { const next = [...form.custom_fields]; next[index] = { ...field, order: Number(event.target.value) }; setForm({ ...form, custom_fields: next }); }} /><button aria-label={t("Remove Field")} className="p-2 text-red-600" onClick={() => setForm({ ...form, custom_fields: form.custom_fields.filter((_, item) => item !== index) })}><Trash2 size={17} /></button></div>)}
    </section>
    <section className="bg-gray-50 border rounded-2xl p-5"><h2 className="font-bold mb-4">{t("Header Preview")}</h2><div className="bg-white rounded-xl border p-5"><PrintIdentityHeader identity={resolved} logoPreviewUrl={form.logo.visible ? localLogo : null} /></div></section>
    <button className="report-action primary" disabled={busy} onClick={() => void save()}><Save size={17} />{t(busy ? "Saving…" : "Save Print Identity")}</button>
  </div>;
}
