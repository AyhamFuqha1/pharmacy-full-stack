import { useState } from "react";
import { Building2, CalendarRange, KeyRound, Printer, Shield, User } from "lucide-react";
import { useNavigate } from "react-router";
import { useAuth } from "../../auth/useAuth";
import {
  BranchesSection,
  UsersSection,
} from "../components/AdminSettingsSections";
import { CompleteAccountingPeriodsSection as AccountingPeriodsSection } from "../components/CompleteAccountingPeriodsSection";
import { useTranslation } from "react-i18next";
import { PrintIdentitySettingsSection } from "../components/PrintIdentitySettingsSection";

type Section = "account" | "branches" | "users" | "periods" | "print";
export function SettingsPage() {
  const { t } = useTranslation();
  const { user, hasPermission } = useAuth();
  const navigate = useNavigate();
  const canUsers = hasPermission("users.manage"),
    canBranches = hasPermission("branches.manage"),
    canPeriods = hasPermission("accounting.periods.manage"),
    canPrintIdentity = hasPermission("print_identity.manage");
  const [section, setSection] = useState<Section>("account");
  const sections = [
    { key: "account" as const, label: "My Account", icon: User, visible: true },
    {
      key: "branches" as const,
      label: "Branches",
      icon: Building2,
      visible: canBranches,
    },
    { key: "users" as const, label: "Users", icon: Shield, visible: canUsers },
    {
      key: "periods" as const,
      label: "Accounting Periods",
      icon: CalendarRange,
      visible: canPeriods,
    },
    { key: "print" as const, label: "Print Identity", icon: Printer, visible: canPrintIdentity },
  ];
  return (
    <div className="p-8">
      <h1 className="text-3xl font-bold">{t("Settings")}</h1>
      <p className="text-gray-500 mb-8">
        {t("Account and supported administration tools")}
      </p>
      <div className="flex flex-wrap gap-2 mb-6">
        {sections
          .filter((item) => item.visible)
          .map((item) => {
            const Icon = item.icon;
            return (
              <button
                key={item.key}
                onClick={() => setSection(item.key)}
                className={`flex gap-2 px-4 py-2.5 rounded-xl ${section === item.key ? "bg-teal-600 text-white" : "bg-white border"}`}
              >
                <Icon className="w-5 h-5" />
                {t(item.label)}
              </button>
            );
          })}
      </div>
      {section === "account" && user && (
        <div className="bg-white border rounded-2xl p-8 max-w-3xl">
          <div className="flex items-center gap-4 mb-8">
            <div className="w-14 h-14 bg-teal-100 text-teal-700 rounded-full flex items-center justify-center">
              <User />
            </div>
            <div>
              <h2 className="text-2xl font-bold">{user.name}</h2>
              <p className="text-gray-500">{user.email}</p>
            </div>
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            <Info label={t("Role")} value={user.role?.name ?? t("No role assigned")} />
            <Info
              label={t("Password state")}
              value={
                user.requires_password_change
                  ? t("Password change required")
                  : t("Current")
              }
            />
          </div>
          <div className="mt-6">
            <p className="text-sm font-medium mb-2">{t("Resolved permissions")}</p>
            <div className="flex flex-wrap gap-2">
              {user.permissions.map((value) => (
                <span
                  key={value}
                  className="px-3 py-1.5 bg-gray-100 rounded-lg text-sm"
                >
                  {value}
                </span>
              ))}
            </div>
          </div>
          <button
            onClick={() => navigate("/change-password")}
            className="mt-8 flex gap-2 px-5 py-3 bg-teal-600 text-white rounded-xl"
          >
            <KeyRound /> {t("Change Password")}
          </button>
        </div>
      )}
      {section === "branches" && canBranches && <BranchesSection />}
      {section === "users" && canUsers && <UsersSection />}
      {section === "periods" && canPeriods && <AccountingPeriodsSection />}
      {section === "print" && canPrintIdentity && <PrintIdentitySettingsSection />}
    </div>
  );
}
function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="p-4 bg-gray-50 rounded-xl">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="font-bold mt-1">{value}</p>
    </div>
  );
}
