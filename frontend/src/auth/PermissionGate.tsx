import type { ReactNode } from 'react';
import { useAuth } from './useAuth';

interface PermissionGateProps {
  permission?: string;
  permissions?: string[];
  children: ReactNode;
  fallback?: ReactNode;
}

export function PermissionGate({ permission, permissions, children, fallback = null }: PermissionGateProps) {
  const { hasPermission } = useAuth();
  const allowed = permissions?.some(hasPermission) ?? (permission ? hasPermission(permission) : false);
  return allowed ? children : fallback;
}
