import { useState } from "react";
import { Search, Bell, ChevronDown, LogOut } from "lucide-react";
import { useAuth } from "../../auth/useAuth";
import { LanguageSwitcher } from "../../i18n/LanguageSwitcher";
import { useTranslation } from "react-i18next";

export function Navbar() {
  const { t } = useTranslation();
  const { user, logout } = useAuth();
  const [profileOpen, setProfileOpen] = useState(false);
  const initials =
    user?.name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join("") || "U";

  return (
    <div className="bg-white border-b border-gray-200 px-3 sm:px-5 lg:px-8 py-3 sm:py-4">
      <div className="flex items-center justify-between">
        {/* Search Bar */}
        <div className="hidden sm:block flex-1 max-w-xl">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
            <input
              type="text"
              placeholder={t("Search products, batches, orders...")}
              className="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
            />
          </div>
        </div>

        {/* Right Section */}
        <div className="flex items-center gap-1 sm:gap-4 sm:ml-8 ml-auto">
          <LanguageSwitcher compact />
          {/* Notifications */}
          <button className="relative p-2 hover:bg-gray-100 rounded-xl transition-colors">
            <Bell className="w-6 h-6 text-gray-600" />
          </button>

          {/* User Profile */}
          <div className="relative">
            <button
              onClick={() => setProfileOpen((open) => !open)}
              className="flex items-center gap-3 pl-3 pr-2 py-2 hover:bg-gray-100 rounded-xl transition-colors"
              aria-expanded={profileOpen}
            >
              <div className="hidden md:block text-right">
                <p className="text-sm font-medium text-gray-900">
                  {user?.name}
                </p>
                <p className="text-xs text-gray-500">
                  {user?.role?.name || t("User")}
                </p>
              </div>
              <div className="w-10 h-10 rounded-full bg-gradient-to-br from-teal-500 to-emerald-500 flex items-center justify-center">
                <span className="text-white font-semibold text-sm">
                  {initials}
                </span>
              </div>
              <ChevronDown
                className={`w-4 h-4 text-gray-400 transition-transform ${profileOpen ? "rotate-180" : ""}`}
              />
            </button>
            {profileOpen && (
              <div className="absolute end-0 mt-2 w-52 bg-white border border-gray-200 rounded-xl shadow-lg p-2 z-50">
                <div className="px-3 py-2 border-b border-gray-100 mb-1">
                  <p className="text-xs text-gray-500 truncate">
                    {user?.email}
                  </p>
                </div>
                <button
                  onClick={() => void logout()}
                  className="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                >
                  <LogOut className="w-4 h-4" />
                  {t("Log out")}
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
