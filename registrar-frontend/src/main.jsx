import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from './context/AuthProvider';
import { ThemeProvider } from './context/ThemeProvider';
import { AccessibilityProvider } from './context/AccessibilityProvider';
import AccessibilityModal from './components/AccessibilityModal';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from './context/QueryClientContext';
import App from './App';
import ErrorBoundary from './components/ErrorBoundary';
import './index.css';

// BUG FIX (QA #6) — see ErrorBoundary.jsx docblock. This outer boundary
// sits above every context provider, so if AuthProvider/ThemeProvider/
// QueryClientProvider itself throws during setup (not just a page inside
// <App />), the user still gets a real error screen instead of a blank
// document. No resetKey here — there's no "navigate away" concept above
// the router — so recovery is via the Reload/Back-to-Login buttons only.
ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ThemeProvider>
            <AccessibilityProvider>
              <AuthProvider>
                <App />
                <AccessibilityModal />
              </AuthProvider>
            </AccessibilityProvider>
          </ThemeProvider>
        </QueryClientProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </React.StrictMode>
);