import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { AppLayout } from './components/layout/AppLayout'
import { LoginPage, RegisterPage } from './pages/Auth'
import { DashboardPage } from './pages/Dashboard'
import { CRMPage } from './pages/engines/CRM'
import {
  SEOPage, WritePage, CreativePage, MarketingPage, SocialPage,
  BuilderPage, CalendarPage, StrategyPage, ApprovalsPage,
  SettingsPage, CampaignsPage, HistoryPage,
} from './pages/engines/Engines'
import { Loading } from './components/ui/index.jsx'

// ── Auth Guard ────────────────────────────────────────────────────────────────
function ProtectedRoute({ children }) {
  const { isAuthenticated, loading } = useAuth()
  if (loading) return <Loading text="Loading LevelUp…" />
  if (!isAuthenticated) return <Navigate to="/login" replace />
  return <AppLayout>{children}</AppLayout>
}

function GuestRoute({ children }) {
  const { isAuthenticated, loading } = useAuth()
  if (loading) return <Loading text="Loading…" />
  if (isAuthenticated) return <Navigate to="/dashboard" replace />
  return children
}

// ── App ───────────────────────────────────────────────────────────────────────
function AppRoutes() {
  return (
    <Routes>
      {/* Public */}
      <Route path="/login"    element={<GuestRoute><LoginPage /></GuestRoute>} />
      <Route path="/register" element={<GuestRoute><RegisterPage /></GuestRoute>} />

      {/* Protected — Workspace */}
      <Route path="/dashboard" element={<ProtectedRoute><DashboardPage /></ProtectedRoute>} />
      <Route path="/strategy"  element={<ProtectedRoute><StrategyPage /></ProtectedRoute>} />
      <Route path="/campaigns" element={<ProtectedRoute><CampaignsPage /></ProtectedRoute>} />
      <Route path="/history"   element={<ProtectedRoute><HistoryPage /></ProtectedRoute>} />
      <Route path="/approvals" element={<ProtectedRoute><ApprovalsPage /></ProtectedRoute>} />
      <Route path="/settings"  element={<ProtectedRoute><SettingsPage /></ProtectedRoute>} />

      {/* Protected — Engines */}
      <Route path="/crm"       element={<ProtectedRoute><CRMPage /></ProtectedRoute>} />
      <Route path="/seo"       element={<ProtectedRoute><SEOPage /></ProtectedRoute>} />
      <Route path="/write"     element={<ProtectedRoute><WritePage /></ProtectedRoute>} />
      <Route path="/creative"  element={<ProtectedRoute><CreativePage /></ProtectedRoute>} />
      <Route path="/marketing" element={<ProtectedRoute><MarketingPage /></ProtectedRoute>} />
      <Route path="/social"    element={<ProtectedRoute><SocialPage /></ProtectedRoute>} />
      <Route path="/builder"   element={<ProtectedRoute><BuilderPage /></ProtectedRoute>} />
      <Route path="/calendar"  element={<ProtectedRoute><CalendarPage /></ProtectedRoute>} />

      {/* Default redirect */}
      <Route path="/"   element={<Navigate to="/dashboard" replace />} />
      <Route path="*"   element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter basename="/app">
        <AppRoutes />
      </BrowserRouter>
    </AuthProvider>
  )
}
