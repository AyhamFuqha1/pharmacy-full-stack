import { Languages } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { setLocale, type AppLocale } from '.';

export function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
  const { i18n } = useTranslation();
  const locale: AppLocale = i18n.resolvedLanguage === 'ar' ? 'ar' : 'en';
  return <label className="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
    <Languages size={18} aria-hidden />
    {!compact && <span>{locale === 'ar' ? 'اللغة' : 'Language'}</span>}
    <select aria-label={locale === 'ar' ? 'اللغة' : 'Language'} className="rounded-lg border bg-white px-2 py-1.5" value={locale} onChange={event => void setLocale(event.target.value as AppLocale)}>
      <option value="en">English</option><option value="ar">العربية</option>
    </select>
  </label>;
}
