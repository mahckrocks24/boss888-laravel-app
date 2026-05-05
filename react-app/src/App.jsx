import React, { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './store/authStore';
import Layout from './components/Layout';
import Login from './pages/Login';
import Dashboard from './pages/dashboard/Dashboard';
import EngineHub from './pages/EngineHub';
import CRMPage from './pages/crm/CRMPage';
import SEOPage from './pages/seo/SEOPage';
import WritePage from './pages/write/WritePage';
import CreativePage from './pages/creative/CreativePage';
import BuilderPage from './pages/builder/BuilderPage';
import MarketingPage from './pages/marketing/MarketingPage';
import SocialPage from './pages/social/SocialPage';
import CalendarPage from './pages/calendar/CalendarPage';
import TasksPage from './pages/TasksPage';
import StrategyRoom from './pages/StrategyRoom';
import CanvasEditor from './editors/canvas/CanvasEditor';
import ContentEditor from './editors/content/ContentEditor';
import BuilderEditor from './editors/builder/BuilderEditor';

function ProtectedRoute({ children }) {
  const status = useAuthStore(s => s.status);
  if (status === 'loading') return <div className="flex items-center justify-center h-screen bg-bg"><div className="text-primary text-4xl">⚡</div></div>;
  if (status !== 'authenticated') return <Navigate to="/login" />;
  return children;
}

export default function App() {
  const bootstrap = useAuthStore(s => s.bootstrap);
  useEffect(() => { bootstrap(); }, []);

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={<ProtectedRoute><Layout /></ProtectedRoute>}>
          <Route index element={<Dashboard />} />
          <Route path="engines" element={<EngineHub />} />
          <Route path="crm/*" element={<CRMPage />} />
          <Route path="seo" element={<SEOPage />} />
          <Route path="write" element={<WritePage />} />
          <Route path="creative" element={<CreativePage />} />
          <Route path="builder" element={<BuilderPage />} />
          <Route path="marketing" element={<MarketingPage />} />
          <Route path="social" element={<SocialPage />} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="strategy" element={<StrategyRoom />} />
          <Route path="tasks" element={<TasksPage />} />
        </Route>
        {/* Full-screen editors (outside Layout) */}
        <Route path="/editor/canvas/:id" element={<ProtectedRoute><CanvasEditor /></ProtectedRoute>} />
        <Route path="/editor/content/:id" element={<ProtectedRoute><ContentEditor /></ProtectedRoute>} />
        <Route path="/editor/builder/:id" element={<ProtectedRoute><BuilderEditor /></ProtectedRoute>} />
      </Routes>
    </BrowserRouter>
  );
}
