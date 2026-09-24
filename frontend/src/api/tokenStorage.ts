const AUTH_TOKEN_KEY = 'pharmacy_erp_auth_token';

export function getToken(): string | null {
  return window.localStorage.getItem(AUTH_TOKEN_KEY);
}

export function setToken(token: string): void {
  window.localStorage.setItem(AUTH_TOKEN_KEY, token);
}

export function removeToken(): void {
  window.localStorage.removeItem(AUTH_TOKEN_KEY);
}
