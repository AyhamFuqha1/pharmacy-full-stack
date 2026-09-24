import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';

export function NotFoundPage() {
  const { t } = useTranslation();
  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="text-center">
        <p className="text-sm font-semibold text-teal-600 mb-2">404</p>
        <h1 className="text-3xl font-bold text-gray-900 mb-3">{t('Page not found')}</h1>
        <p className="text-gray-500 mb-6">{t('The page you requested does not exist.')}</p>
        <Link to="/" className="inline-flex px-5 py-3 bg-teal-600 text-white rounded-xl hover:bg-teal-700">
          {t('Return to dashboard')}
        </Link>
      </div>
    </div>
  );
}
