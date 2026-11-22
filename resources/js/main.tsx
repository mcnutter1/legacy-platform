import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, Navigate, RouterProvider, useRouteError } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError } from './api/client';
import { WorkspaceLayout } from './app/Layout';
import { ErrorState, Loading } from './components/ui';
import Root from './pages/Root';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (n, e) => !(e instanceof ApiError && e.status < 500) && n < 2,
      refetchOnWindowFocus: true,
      staleTime: 15_000,
      gcTime: 5 * 60_000,
    },
  },
});

const page = (load: () => Promise<{ default: React.ComponentType }>) => {
  const C = lazy(load);
  return (
    <Suspense fallback={<Loading rows={4} />}>
      <C />
    </Suspense>
  );
};

function RouteError() {
  const e = useRouteError();
  return <div className="p-8"><ErrorState error={e} /></div>;
}

const router = createBrowserRouter([
  { path: '/', element: <Root />, errorElement: <RouteError /> },
  { path: '/integrations/oauth/callback', element: page(() => import('./pages/IntegrationCallback')), errorElement: <RouteError /> },
  {
    path: '/w/:workspace',
    element: <WorkspaceLayout />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: <Navigate to="home" replace /> },
      { path: 'home', element: page(() => import('./pages/Home')) },
      { path: 'review', element: page(() => import('./pages/Review')) },
      { path: 'net-worth', element: page(() => import('./pages/NetWorth')) },
      { path: 'accounts', element: page(() => import('./pages/Accounts')) },
      { path: 'accounts/:id', element: page(() => import('./pages/AccountDetail')) },
      { path: 'portfolio', element: page(() => import('./pages/Portfolio')) },
      { path: 'real-estate', element: page(() => import('./pages/RealEstate')) },
      { path: 'real-estate/:id', element: page(() => import('./pages/PropertyDetail')) },
      { path: 'assets/:id', element: page(() => import('./pages/AssetDetail')) },
      { path: 'assets', element: page(() => import('./pages/Assets')) },
      { path: 'debts', element: page(() => import('./pages/Debts')) },
      { path: 'imports', element: page(() => import('./pages/Imports')) },
      { path: 'integrations', element: page(() => import('./pages/Integrations')) },
      { path: 'imports/:batch', element: page(() => import('./pages/ImportBatch')) },
      { path: 'year-close', element: page(() => import('./pages/YearClose')) },
      { path: 'payroll', element: page(() => import('./pages/Payroll')) },
      { path: 'equity', element: page(() => import('./pages/Equity')) },
      { path: 'equity/:id', element: page(() => import('./pages/EquityGrant')) },
      { path: 'tax', element: page(() => import('./pages/Tax')) },
      { path: 'tax/units/:unit', element: page(() => import('./pages/TaxUnit')) },
      { path: 'tax/runs/:run', element: page(() => import('./pages/TaxRun')) },
      { path: 'tax/packs', element: page(() => import('./pages/TaxPacks')) },
      { path: 'planning', element: page(() => import('./pages/Planning')) },
      { path: 'planning/scenarios/:scenario', element: page(() => import('./pages/Scenario')) },
      { path: 'planning/runs/:run', element: page(() => import('./pages/PlanRun')) },
      { path: 'estate', element: page(() => import('./pages/Estate')) },
      { path: 'estate/runs/:run', element: page(() => import('./pages/EstateRun')) },
      { path: 'insurance', element: page(() => import('./pages/Insurance')) },
      { path: 'family', element: page(() => import('./pages/Family')) },
      { path: 'governance', element: page(() => import('./pages/Governance')) },
      { path: 'vault', element: page(() => import('./pages/Vault')) },
      { path: 'vault/:id', element: page(() => import('./pages/DocumentDetail')) },
      { path: 'reports', element: page(() => import('./pages/Reports')) },
      { path: 'access', element: page(() => import('./pages/Access')) },
      { path: 'settings', element: page(() => import('./pages/Settings')) },
      { path: 'activity', element: page(() => import('./pages/Activity')) },
      { path: 'notifications', element: page(() => import('./pages/Notifications')) },
      { path: 'search', element: page(() => import('./pages/Search')) },
      { path: 'records', element: page(() => import('./pages/Records')) },
      { path: 'records/:type', element: page(() => import('./pages/Records')) },
      { path: '*', element: <div className="p-8"><ErrorState error={new ApiError(404, 'not_found', 'Not found')} /></div> },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
]);

createRoot(document.getElementById('root') as HTMLElement).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
);
