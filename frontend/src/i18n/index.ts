import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { ar, en } from './resources';

export const LOCALE_STORAGE_KEY = 'app.locale';
export type AppLocale = 'en' | 'ar';
export const supportedLocales = ['en', 'ar'] as const;

export function getStoredLocale(): AppLocale {
  return localStorage.getItem(LOCALE_STORAGE_KEY) === 'ar' ? 'ar' : 'en';
}

export function applyDocumentLocale(locale: AppLocale) {
  document.documentElement.lang = locale;
  document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
}

void i18n.use(initReactI18next).init({
  resources: { en: { common: en }, ar: { common: ar } },
  lng: getStoredLocale(), fallbackLng: 'en', defaultNS: 'common',
  keySeparator: false,
  interpolation: { escapeValue: false }, returnNull: false,
  saveMissing: import.meta.env.DEV,
  missingKeyHandler: (_languages, _namespace, key) => { if (import.meta.env.DEV) console.warn(`[i18n] Missing translation: ${key}`); },
});
applyDocumentLocale(getStoredLocale());

export function translateUi(value: string): string {
  if (i18n.resolvedLanguage !== 'ar') return value;
  const trimmed = value.trim();
  if (!trimmed) return value;
  const translated = i18n.t(trimmed, { defaultValue: trimmed });
  return value.replace(trimmed, translated);
}

export async function setLocale(locale: AppLocale) {
  localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  applyDocumentLocale(locale);
  await i18n.changeLanguage(locale);
  window.dispatchEvent(new CustomEvent('app-locale-change', { detail: locale }));
}

export default i18n;
