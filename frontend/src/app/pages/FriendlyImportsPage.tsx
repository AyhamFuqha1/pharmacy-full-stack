import { useMemo, useState } from "react";
import { erpApi, type JsonRecord } from "../../api/erpOperations";
import { ApiClientError } from "../../api/client";
import { EntitySelect, Field, FormSection, PageHeader } from "../components/UxPrimitives";
import { useEntityOptions } from "../hooks/useEntityOptions";
import { ErrorNotice, MetricGrid, RecordTable, normalizeError } from "../components/ErpPrimitives";
import { useTranslation } from "react-i18next";

type Step = "upload" | "mapping" | "preview" | "complete";

export function FriendlyImportsPage() {
  const { t } = useTranslation();
  const { branches } = useEntityOptions();
  const [domain, setDomain] = useState("products"), [branch, setBranch] = useState("");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10)), [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<JsonRecord | null>(null), [mapping, setMapping] = useState<Record<string, string>>({});
  const [autoMapping, setAutoMapping] = useState<Record<string, string>>({});
  const [step, setStep] = useState<Step>("upload"), [error, setError] = useState<ApiClientError | null>(null), [busy, setBusy] = useState(false);
  const job = result?.job;
  const usedDestinations = useMemo(() => new Set(Object.values(mapping).filter(Boolean)), [mapping]);

  async function upload(e?: React.FormEvent, worksheet?: string) {
    e?.preventDefault(); if (!file) return;
    const body = new FormData(); body.append("domain", domain); body.append("file", file); if (worksheet) body.append("worksheet", worksheet);
    setBusy(true); setError(null);
    try {
      const response = (await erpApi.importPreview(body)).data;
      const suggested = Object.fromEntries(Object.entries(response.job.mapping || {}).map(([key, value]) => [key, String(value || "")]));
      setResult(response); setMapping(suggested); setAutoMapping(suggested); setStep("mapping");
    } catch (value) { setError(normalizeError(value, t("The import file could not be read. Check its type, encoding, and headers."))); }
    finally { setBusy(false); }
  }

  async function validateMapping() {
    if (!job) return; setBusy(true); setError(null);
    try { const response = (await erpApi.importMapping(job.id, mapping)).data; setResult(response); setStep(response.job.status === "previewed" ? "preview" : "mapping"); }
    catch (value) { setError(normalizeError(value)); } finally { setBusy(false); }
  }

  async function confirmImport() {
    if (!job || !confirm(t("Confirm Import?\n\nOnly the validated rows in this preview will be imported. Review all reported errors before continuing."))) return;
    setBusy(true); setError(null);
    try {
      const extra = domain === "opening_stock" ? { effective_date: date, branch_id: Number(branch) } : {};
      setResult({ ...result, job: (await erpApi.importConfirm(job.id, extra)).data }); setStep("complete");
    } catch (value) { setError(normalizeError(value)); } finally { setBusy(false); }
  }

  async function downloadSource() {
    if (!job) return;
    try { const { blob, filename } = await erpApi.importSource(job.id); const url = URL.createObjectURL(blob), link = document.createElement("a"); link.href = url; link.download = filename; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url); }
    catch (value) { setError(normalizeError(value, t("The uploaded source file could not be downloaded."))); }
  }

  const reset = (nextDomain = domain) => { setDomain(nextDomain); setResult(null); setMapping({}); setAutoMapping({}); setStep("upload"); setError(null); };
  const steps: Array<[string, string, Step]> = [["choose", "1. Choose Data Type", "upload"], ["upload", "2. Upload File", "upload"], ["mapping", "3. Column Mapping", "mapping"], ["preview", "4. Preview & Errors", "preview"], ["complete", "5. Confirm Import", "complete"]];

  return <div className="p-4 md:p-8 space-y-5">
    <PageHeader title={t("Smart Data Import")} description={t("Upload CSV or XLSX data, map its columns, validate every row, then confirm the import.")} />
    <ol className="grid gap-2 sm:grid-cols-5 text-sm">{steps.map(([key, label, active]) => <li key={key} className={`rounded-xl border p-3 ${step === active ? "bg-blue-50 border-blue-400 font-bold" : "bg-white"}`}>{t(label)}</li>)}</ol>
    <ErrorNotice error={error} />
    {step === "upload" && <form className="space-y-5" onSubmit={upload}>
      <FormSection title={t("Import file")}>
        <Field required label={t("Data Type")}><select className="input" value={domain} onChange={(e) => reset(e.target.value)}><option value="products">{t("Products")}</option><option value="customers">{t("Customers")}</option><option value="suppliers">{t("Suppliers")}</option><option value="opening_stock">{t("Opening Stock")}</option></select></Field>
        <Field required label={t("CSV or XLSX File")} hint={t("The first non-empty row must contain column headers. Maximum 5 MB and 5,000 data rows.")}><input required className="input" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(e) => setFile(e.target.files?.[0] || null)} /></Field>
        {domain === "opening_stock" && <><EntitySelect required label={t("Branch")} value={branch} onChange={setBranch} options={branches} /><Field required label={t("Effective Date")}><input required className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field></>}
      </FormSection>
      {domain === "opening_stock" && <div className="p-4 bg-blue-50 text-blue-900 rounded-xl">{t("Opening Stock enters the formal opening-balance workflow. It creates a draft and does not create purchases or post stock automatically.")}</div>}
      <button className="report-action primary" disabled={busy || !file}>{t(busy ? "Reading File…" : "Upload and Read Headers")}</button>
    </form>}
    {step === "mapping" && job && <section className="space-y-4">
      {job.worksheets?.length > 1 && <Field label={t("Worksheet")} hint={t("The first worksheet is selected by default.")}><select className="input" value={job.worksheet} onChange={(e) => void upload(undefined, e.target.value)}>{job.worksheets.map((sheet: string) => <option key={sheet}>{sheet}</option>)}</select></Field>}
      {job.duplicate_headers?.length > 0 && <div className="p-4 rounded-xl bg-amber-50 border border-amber-300">{t("Duplicate headers were detected. Map only the intended column and ignore the duplicate.")}: {job.duplicate_headers.join(", ")}</div>}
      <div className="bg-white border rounded-2xl overflow-x-auto"><table className="w-full text-sm"><thead><tr><th className="p-3 text-start">{t("Source Column")}</th><th className="p-3 text-start">{t("Sample Values")}</th><th className="p-3 text-start">{t("System Field")}</th><th className="p-3 text-start">{t("Status")}</th></tr></thead><tbody>
        {job.columns?.map((column: JsonRecord) => { const current = mapping[column.id] || ""; return <tr key={column.id} className="border-t"><td className="p-3 font-medium">{column.header}</td><td className="p-3">{column.samples?.join(" · ") || "—"}</td><td className="p-3"><select className="input min-w-52" value={current} onChange={(e) => setMapping({ ...mapping, [column.id]: e.target.value })}><option value="">{t("Ignore this column")}</option>{Object.entries(job.destination_fields || {}).map(([key, details]: [string, any]) => <option key={key} value={key} disabled={usedDestinations.has(key) && current !== key}>{t(details.label)}{details.required ? ` (${t("required")})` : ""}</option>)}</select></td><td className="p-3">{current ? <span className="text-green-700">{t(current === autoMapping[column.id] ? "Auto Matched" : "Manually Mapped")}</span> : <span className="text-gray-500">{t("Ignored")}</span>}</td></tr>; })}
      </tbody></table></div>
      {job.missing_fields?.length > 0 && <div className="p-4 rounded-xl bg-red-50 border border-red-300">{t("Required mappings are missing")}: {job.missing_fields.map((field: string) => t(field)).join(", ")}</div>}
      <div className="flex gap-2"><button className="report-action" onClick={() => setStep("upload")}>{t("Back")}</button><button className="report-action primary" disabled={busy} onClick={() => void validateMapping()}>{t(busy ? "Validating…" : "Continue to Preview")}</button></div>
    </section>}
    {(step === "preview" || step === "complete") && job && <>
      <MetricGrid data={job} keys={["status", "total_rows", "valid_rows", "failed_rows"]} />
      {result?.warnings?.length > 0 && <section className="bg-amber-50 border rounded-2xl overflow-hidden"><h2 className="p-4 font-bold">{t("Warnings")}</h2><RecordTable rows={result.warnings} /></section>}
      {result?.errors?.length > 0 && <section className="bg-red-50 border rounded-2xl overflow-hidden"><h2 className="p-4 font-bold">{t("Rows Requiring Attention")}</h2><RecordTable rows={result.errors} emptyText={t("No row errors were found.")} /></section>}
      <section className="bg-white border rounded-2xl overflow-hidden"><h2 className="p-4 font-bold">{t("Mapped Import Preview")}</h2><RecordTable rows={result?.preview || []} emptyText={t("The uploaded file did not contain previewable rows.")} /></section>
      {step === "complete" && <div className="p-4 rounded-xl bg-green-50 border border-green-300">{t("Import completed successfully.")}</div>}
      <div className="flex flex-wrap gap-2"><button type="button" className="report-action" onClick={() => void downloadSource()}>{t("Download Source")}</button>{step === "preview" && <><button type="button" className="report-action" onClick={() => setStep("mapping")}>{t("Back to Mapping")}</button><button type="button" className="report-action primary" disabled={busy || job.failed_rows > 0 || job.status !== "previewed"} onClick={() => void confirmImport()}>{t("Confirm Import")}</button></>}</div>
    </>}
  </div>;
}
