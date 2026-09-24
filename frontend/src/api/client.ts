import type { ApiError } from '../types/api';
import { getToken, removeToken } from './tokenStorage';
import { getStoredLocale } from '../i18n';

export const AUTH_UNAUTHORIZED_EVENT = 'pharmacy-auth-unauthorized';

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
type RequestBody = object | FormData | undefined;

interface RequestOptions {
  method?: HttpMethod;
  body?: RequestBody;
  signal?: AbortSignal;
}

const configuredBaseUrl = import.meta.env.VITE_API_BASE_URL as string | undefined;
const API_BASE_URL = (configuredBaseUrl || '/api').replace(/\/+$/, '');

export class ApiClientError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiClientError';
    this.status = status;
    this.errors = errors;
  }
}

function endpointUrl(path: string): string {
  return `${API_BASE_URL}/${path.replace(/^\/+/, '')}`;
}

async function parseResponse(response: Response): Promise<unknown> {
  if (response.status === 204) return null;

  const contentType = response.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    return null;
  }

  return response.json();
}

function normalizeErrors(value: unknown): Record<string, string[]> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};

  return Object.fromEntries(
    Object.entries(value).map(([field, messages]) => [
      field,
      Array.isArray(messages) ? messages.map(String) : [String(messages)],
    ]),
  );
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers = new Headers({ Accept: 'application/json' });
  headers.set('Accept-Language', getStoredLocale());
  const token = getToken();
  const isFormData = options.body instanceof FormData;

  if (token) headers.set('Authorization', `Bearer ${token}`);
  if (options.body && !isFormData) headers.set('Content-Type', 'application/json');

  let response: Response;
  try {
    response = await fetch(endpointUrl(path), {
      method: options.method || 'GET',
      headers,
      body: options.body ? (isFormData ? options.body : JSON.stringify(options.body)) : undefined,
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiClientError(0, 'Unable to connect to the server. Please try again.');
  }

  const payload = await parseResponse(response);

  if (!response.ok) {
    const apiError = (payload || {}) as Partial<ApiError>;
    if (response.status === 401) {
      removeToken();
      window.dispatchEvent(new Event(AUTH_UNAUTHORIZED_EVENT));
    }

    const safeMessage = response.status >= 500
      ? 'An unexpected server error occurred. Please try again.'
      : apiError.message || defaultErrorMessage(response.status);

    throw new ApiClientError(response.status, safeMessage, normalizeErrors(apiError.errors));
  }

  return payload as T;
}

async function download(path: string, body?: object): Promise<{ blob: Blob; filename: string }> {
  const headers = new Headers({ Accept: 'text/csv, application/octet-stream' });
  headers.set('Accept-Language', getStoredLocale());
  const token = getToken();
  if (token) headers.set('Authorization', `Bearer ${token}`);
  headers.set('Content-Type', 'application/json');
  let response: Response;
  try {
    response = await fetch(endpointUrl(path), { method: 'POST', headers, body: JSON.stringify(body || {}) });
  } catch {
    throw new ApiClientError(0, 'Unable to connect to the server. Please try again.');
  }
  if (!response.ok) {
    let payload: Partial<ApiError> = {};
    try { payload = await response.json() as Partial<ApiError>; } catch { /* non-JSON error */ }
    if (response.status === 401) {
      removeToken();
      window.dispatchEvent(new Event(AUTH_UNAUTHORIZED_EVENT));
    }
    throw new ApiClientError(response.status, response.status >= 500 ? 'An unexpected server error occurred. Please try again.' : payload.message || defaultErrorMessage(response.status), normalizeErrors(payload.errors));
  }
  const disposition = response.headers.get('content-disposition') || '';
  const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1];
  const plain = /filename="?([^";]+)"?/i.exec(disposition)?.[1];
  return { blob: await response.blob(), filename: encoded ? decodeURIComponent(encoded) : plain || 'report.csv' };
}
async function downloadGet(path: string): Promise<{ blob: Blob; filename: string }> {
  const headers = new Headers({ Accept: 'application/octet-stream' }); headers.set('Accept-Language', getStoredLocale()); const token=getToken(); if(token)headers.set('Authorization',`Bearer ${token}`);
  const response=await fetch(endpointUrl(path),{headers}); if(!response.ok)throw new ApiClientError(response.status,defaultErrorMessage(response.status));
  const disposition=response.headers.get('content-disposition')||'';const name=/filename="?([^";]+)"?/i.exec(disposition)?.[1]||'download';return{blob:await response.blob(),filename:name};
}

function defaultErrorMessage(status: number): string {
  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have permission to perform this action.',
    404: 'The requested resource was not found.',
    409: 'The request conflicts with the current server state.',
    422: 'Please correct the highlighted fields.',
    500: 'An unexpected server error occurred. Please try again.',
  };
  return messages[status] || 'The request could not be completed.';
}

export const apiClient = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>(path, { signal }),
  post: <T>(path: string, body?: RequestBody, signal?: AbortSignal) => request<T>(path, { method: 'POST', body, signal }),
  put: <T>(path: string, body?: RequestBody) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: RequestBody) => request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string, body?: RequestBody) => request<T>(path, { method: 'DELETE', body }),
  download,
  downloadGet,
};
