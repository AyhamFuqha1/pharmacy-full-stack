import { RouterProvider } from 'react-router';
import { AuthProvider } from '../auth/AuthProvider';
import { router } from './routes';
import { LegacyLocalizationBridge } from '../i18n/LegacyLocalizationBridge';

export default function App() {
  return (
    <AuthProvider>
      <LegacyLocalizationBridge />
      <RouterProvider router={router} />
    </AuthProvider>
  );
}
