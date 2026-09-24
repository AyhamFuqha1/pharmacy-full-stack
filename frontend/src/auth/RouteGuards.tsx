import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useAuth } from './useAuth';
import { useTranslation } from 'react-i18next';

function AuthLoadingScreen() {
  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center">
      <div className="flex flex-col items-center gap-4 text-gray-600">
        <div className="w-10 h-10 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin" />
        <p className="text-sm font-medium">Restoring your session...</p>
      </div>
    </div>
  );
}

export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading, user } = useAuth();
  const location = useLocation();

  if (isLoading) return <AuthLoadingScreen />;
  if (!isAuthenticated) return <Navigate to="/login" replace state={{ from: location }} />;
  if (user?.requires_password_change) return <Navigate to="/change-password" replace />;
  return children;
}

export function PermissionRoute({ children, permissions }: { children: ReactNode; permissions: string[] }) {
  const { hasPermission } = useAuth();
  const { t } = useTranslation();
  if (permissions.some((permission) => hasPermission(permission))) return children;
  return (
    <div className="p-8">
      <h1 className="text-2xl font-bold">{t("Access denied")}</h1>
      <p className="mt-2 text-gray-600">{t("You do not have permission to view this information.")}</p>
    </div>
  );
}

export function LoginRoute({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading, user } = useAuth();

  if (isLoading) return <AuthLoadingScreen />;
  if (isAuthenticated) {
    return <Navigate to={user?.requires_password_change ? '/change-password' : '/'} replace />;
  }
  return children;
}

export function ChangePasswordRoute({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading, user } = useAuth();

  if (isLoading) return <AuthLoadingScreen />;
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (!user?.requires_password_change) return <Navigate to="/" replace />;
  return children;
}
