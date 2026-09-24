import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { printIdentityApi } from "../../api/printIdentity";
import type { JsonRecord } from "../../api/erpOperations";

const labels: Record<string, string> = {
  address: "Address", phone: "Phone", secondary_phone: "Secondary Phone", mobile: "Mobile",
  email: "Email", website: "Website", tax_number: "Tax Number",
  registration_number: "Registration Number", license_number: "License Number",
};

export function PrintIdentityHeader({ identity, logoPreviewUrl }: { identity: JsonRecord; logoPreviewUrl?: string | null }) {
  const { t, i18n } = useTranslation();
  const [logoUrl, setLogoUrl] = useState<string | null>(logoPreviewUrl || null);
  useEffect(() => {
    if (logoPreviewUrl !== undefined) { setLogoUrl(logoPreviewUrl); return; }
    if (!identity.logo_url) { setLogoUrl(null); return; }
    let active = true, objectUrl = "";
    printIdentityApi.logo(String(identity.logo_url)).then(({ blob }) => {
      if (!active) return;
      objectUrl = URL.createObjectURL(blob); setLogoUrl(objectUrl);
    }).catch(() => { if (active) setLogoUrl(null); });
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [identity.logo_url, logoPreviewUrl]);
  const rows = Array.isArray(identity.fields) ? identity.fields.filter((field: JsonRecord) => !["business_name", "business_name_secondary"].includes(String(field.key))) : [];
  return <header lang={i18n.resolvedLanguage === "ar" ? "ar" : "en"} dir={i18n.resolvedLanguage === "ar" ? "rtl" : "ltr"} className="print-identity-header flex items-start gap-4 border-b-2 border-teal-700/30 pb-4 print:break-inside-avoid">
    {logoUrl && <img src={logoUrl} alt={t("Business Logo")} className="h-20 w-24 shrink-0 object-contain" />}
    <div className="min-w-0 flex-1">
      {identity.business_name ? <h1 className="print-identity-name text-2xl font-bold text-gray-950">{String(identity.business_name)}</h1> : null}
      {identity.business_name_secondary && <p className="print-identity-subtitle text-sm text-gray-600">{identity.business_name_secondary}</p>}
      <div className="print-identity-details mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700">
        {rows.map((field: JsonRecord, index: number) => <span className="print-identity-detail" key={`${field.key}-${index}`}><strong>{field.label || t(labels[String(field.key)] || String(field.key))}:</strong> <bdi>{field.value}</bdi></span>)}
      </div>
    </div>
  </header>;
}
