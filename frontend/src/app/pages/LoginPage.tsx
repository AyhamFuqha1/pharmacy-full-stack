import { LockKeyhole, Mail } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { useNavigate } from "react-router";
import { ApiClientError } from "../../api/client";
import { useAuth } from "../../auth/useAuth";
import type { LoginPayload } from "../../types/auth";
import { LanguageSwitcher } from "../../i18n/LanguageSwitcher";
import { useTranslation } from "react-i18next";

export function LoginPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { login } = useAuth();
  const [serverError, setServerError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginPayload>({ defaultValues: { email: "", password: "" } });

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      const user = await login(values);
      navigate(user.requires_password_change ? "/change-password" : "/", {
        replace: true,
      });
    } catch (error) {
      if (error instanceof ApiClientError) {
        Object.entries(error.errors).forEach(([field, messages]) => {
          if (field === "email" || field === "password") {
            setError(field, { message: messages[0] });
          }
        });
        setServerError(error.message);
        return;
      }
      setServerError(
        error instanceof Error
          ? error.message
          : t("Unable to sign in. Please try again."),
      );
    }
  });

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="w-full max-w-md">
        <div className="mb-4 flex justify-end">
          <LanguageSwitcher />
        </div>
        <div className="flex items-center justify-center gap-3 mb-8">
          <div className="w-12 h-12 bg-gradient-to-br from-teal-600 to-emerald-500 rounded-xl flex items-center justify-center shadow-sm">
            <span className="text-white font-bold">Rx</span>
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">PharmaSaaS</h1>
            <p className="text-sm text-gray-500">{t("Pharmacy ERP")}</p>
          </div>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
          <div className="mb-6">
            <h2 className="text-2xl font-bold text-gray-900 mb-2">
              {t("Welcome back")}
            </h2>
            <p className="text-gray-500">{t("Sign in to manage your pharmacy")}</p>
          </div>

          {serverError && (
            <div
              role="alert"
              className="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700"
            >
              {serverError}
            </div>
          )}

          <form onSubmit={onSubmit} className="space-y-5" noValidate>
            <div>
              <label
                htmlFor="email"
                className="block text-sm font-medium text-gray-700 mb-2"
              >
                {t("Email")}
              </label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
                  aria-invalid={Boolean(errors.email)}
                  {...register("email", {
                    required: t("Email is required."),
                    pattern: {
                      value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                      message: t("Enter a valid email address."),
                    },
                  })}
                />
              </div>
              {errors.email && (
                <p className="text-sm text-red-600 mt-1">
                  {errors.email.message}
                </p>
              )}
            </div>

            <div>
              <label
                htmlFor="password"
                className="block text-sm font-medium text-gray-700 mb-2"
              >
                {t("Password")}
              </label>
              <div className="relative">
                <LockKeyhole className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                <input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  className="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
                  aria-invalid={Boolean(errors.password)}
                  {...register("password", {
                    required: t("Password is required."),
                  })}
                />
              </div>
              {errors.password && (
                <p className="text-sm text-red-600 mt-1">
                  {errors.password.message}
                </p>
              )}
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 disabled:opacity-60 disabled:cursor-not-allowed font-semibold transition-all"
            >
              {isSubmitting ? t("Signing in...") : t("Sign in")}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
