import type { BatchDto } from '../types/batch';
import i18n from '../i18n';

export type ExpiryClass = 'expired' | 'expiring-soon' | 'future';
export type BatchStatus = ExpiryClass | 'out-of-stock' | 'available';

function dateOnly(value: string | null): Date | null {
  if (!value) return null;
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return null;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return Number.isNaN(date.getTime()) ? null : date;
}

function today(): Date {
  const now = new Date();
  return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
}

export function daysUntilExpiry(value: string | null): number | null {
  const expiry = dateOnly(value);
  return expiry ? Math.round((expiry.getTime() - today().getTime()) / 86_400_000) : null;
}

export function classifyExpiry(value: string | null, warningDays = 30): ExpiryClass {
  const days = daysUntilExpiry(value);
  if (days !== null && days < 0) return 'expired';
  if (days !== null && days <= warningDays) return 'expiring-soon';
  return 'future';
}

export function batchStatus(batch: BatchDto): BatchStatus {
  const expiry = classifyExpiry(batch.expiry_date);
  if (expiry !== 'future') return expiry;
  if (batch.quantity <= 0) return 'out-of-stock';
  return 'available';
}

export function formatDateOnly(value: string | null): string {
  if (value === null) return i18n.t('No expiry date');
  const date = dateOnly(value);
  const locale = i18n.resolvedLanguage === 'ar' ? 'ar' : 'en';
  return date ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(date) : i18n.t('Unavailable');
}

export function formatNumericDate(value: string | null): string {
  if (!value) return i18n.t('Unavailable');
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  return match ? `${match[1]}/${match[2]}/${match[3]}` : i18n.t('Unavailable');
}

export function formatMoney(value: string | number): string {
  const amount = Number(value);
  const currency = import.meta.env.VITE_CURRENCY || 'ILS';
  return Number.isFinite(amount)
    ? new Intl.NumberFormat(undefined, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).format(amount)
    : 'Unavailable';
}
