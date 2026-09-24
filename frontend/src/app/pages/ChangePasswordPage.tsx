import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useForm, type UseFormRegisterReturn } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { authApi } from '../../api/auth';
import { ApiClientError } from '../../api/client';
import { useAuth } from '../../auth/useAuth';
import type { ChangePasswordPayload } from '../../types/auth';

export function ChangePasswordPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { refreshUser } = useAuth();
  const [serverError, setServerError] = useState<string | null>(null);
  const { register, handleSubmit, watch, setError, formState: { errors, isSubmitting } } =
    useForm<ChangePasswordPayload>({
      defaultValues: { current_password: '', new_password: '', new_password_confirmation: '' },
    });

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      await authApi.changePassword(values);
      await refreshUser();
      navigate('/', { replace: true });
    } catch (error) {
      if (error instanceof ApiClientError) {
        Object.entries(error.errors).forEach(([field, messages]) => {
          if (field in values) setError(field as keyof ChangePasswordPayload, { message: messages[0] });
        });
        setServerError(error.message);
        return;
      }
      setServerError(t('Unable to change your password. Please try again.'));
    }
  });

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="w-full max-w-lg bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
        <div className="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mb-5">
          <KeyRound className="w-6 h-6 text-teal-700" />
        </div>
        <h1 className="text-2xl font-bold text-gray-900 mb-2">{t('Change your password')}</h1>
        <p className="text-gray-500 mb-6">{t('You must choose a new password before continuing.')}</p>

        {serverError && (
          <div role="alert" className="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
            {serverError}
          </div>
        )}

        <form onSubmit={onSubmit} className="space-y-5" noValidate>
          <PasswordField
            id="current_password"
            label={t('Current password')}
            error={errors.current_password?.message && t(errors.current_password.message)}
            registration={register('current_password', { required: t('Current password is required.') })}
            autoComplete="current-password"
          />
          <PasswordField
            id="new_password"
            label={t('New password')}
            error={errors.new_password?.message && t(errors.new_password.message)}
            registration={register('new_password', {
              required: t('New password is required.'),
              minLength: { value: 8, message: t('Password must contain at least 8 characters.') },
            })}
            autoComplete="new-password"
          />
          <PasswordField
            id="new_password_confirmation"
            label={t('Confirm new password')}
            error={errors.new_password_confirmation?.message && t(errors.new_password_confirmation.message)}
            registration={register('new_password_confirmation', {
              required: t('Please confirm your new password.'),
              validate: (value) => value === watch('new_password') || t('Passwords do not match.'),
            })}
            autoComplete="new-password"
          />

          <button
            type="submit"
            disabled={isSubmitting}
            className="w-full py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl disabled:opacity-60 disabled:cursor-not-allowed font-semibold"
          >
            {t(isSubmitting ? 'Updating password...' : 'Update password')}
          </button>
        </form>
      </div>
    </div>
  );
}

interface PasswordFieldProps {
  id: string;
  label: string;
  error?: string;
  registration: UseFormRegisterReturn;
  autoComplete: string;
}

function PasswordField({ id, label, error, registration, autoComplete }: PasswordFieldProps) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-2">{label}</label>
      <input
        id={id}
        type="password"
        autoComplete={autoComplete}
        aria-invalid={Boolean(error)}
        className="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500"
        {...registration}
      />
      {error && <p className="text-sm text-red-600 mt-1">{error}</p>}
    </div>
  );
}
