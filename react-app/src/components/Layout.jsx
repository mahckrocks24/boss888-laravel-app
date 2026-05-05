import React, { useState } from 'react';
import { Outlet, NavLink, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { LayoutDashboard, Zap, Target, Settings, LogOut, BarChart3, Bot, Building2, Search, PenTool, Palette, Globe, Mail, Phone, Calendar, Shield, ChevronLeft, ChevronRight, Bell } from 'lucide-react';

const NAV = [
  { section: 'Workspace' },
  { to: '/', icon: LayoutDashboard, label: 'Command Center' },
  { to: '/tasks', icon: Zap, label: 'Tasks' },
  { to: '/strategy', icon: Target, label: 'Strategy Room' },
  { section: 'Engines' },
  { to: '/engines', icon: Bot, label: 'Engine Hub' },
  { to: '/builder', icon: Building2, label: 'Website Builder' },
  { to: '/seo', icon: Search, label: 'SEO Engine' },
  { to: '/write', icon: PenTool, label: 'Write Engine' },
  { to: '/creative', icon: Palette, label: 'Creative Engine' },
  { to: '/crm', icon: BarChart3, label: 'CRM' },
  { to: '/marketing', icon: Mail, label: 'Marketing' },
  { to: '/social', icon: Phone, label: 'Social Media' },
  { to: '/calendar', icon: Calendar, label: 'Calendar' },
];

export default function Layout() {
  const [collapsed, setCollapsed] = useState(false);
  const logout = useAuthStore(s => s.logout);
  const workspace = useAuthStore(s => s.workspace);
  const navigate = useNavigate();

  return (
    <div className="flex h-screen bg-bg text-gray-200 font-body">
      {/* Sidebar */}
      <aside className={`${collapsed ? 'w-16' : 'w-56'} bg-s1 border-r border-border flex flex-col transition-all duration-200 flex-shrink-0`}>
        <div className="p-4 flex items-center gap-2">
          <span className="text-2xl">⚡</span>
          {!collapsed && <span className="font-heading font-bold text-sm text-primary">LevelUpGrowth</span>}
        </div>

        <nav className="flex-1 overflow-y-auto px-2 space-y-0.5">
          {NAV.map((item, i) => item.section ? (
            !collapsed && <div key={i} className="text-[10px] font-bold text-gray-500 uppercase tracking-wider px-3 pt-4 pb-1">{item.section}</div>
          ) : (
            <NavLink key={item.to} to={item.to} end={item.to === '/'}
              className={({ isActive }) => `flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] transition-colors ${isActive ? 'bg-primary/10 text-primary font-semibold' : 'text-gray-400 hover:bg-s2 hover:text-gray-200'}`}>
              <item.icon size={16} />
              {!collapsed && item.label}
            </NavLink>
          ))}
        </nav>

        <div className="p-3 border-t border-border space-y-1">
          <button onClick={() => navigate('/settings')} className="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] text-gray-400 hover:bg-s2 hover:text-gray-200 w-full">
            <Settings size={16} />{!collapsed && 'Settings'}
          </button>
          <button onClick={logout} className="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] text-gray-400 hover:bg-s2 hover:text-red-400 w-full">
            <LogOut size={16} />{!collapsed && 'Sign Out'}
          </button>
        </div>

        <button onClick={() => setCollapsed(!collapsed)} className="p-2 border-t border-border text-gray-500 hover:text-gray-300">
          {collapsed ? <ChevronRight size={16} /> : <ChevronLeft size={16} />}
        </button>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col overflow-hidden">
        <header className="h-12 bg-s1 border-b border-border flex items-center justify-between px-4 flex-shrink-0">
          <div className="text-sm text-gray-400">{workspace?.business_name || 'LevelUp Growth'}</div>
          <div className="flex items-center gap-3">
            <button className="text-gray-400 hover:text-gray-200"><Bell size={18} /></button>
            <a href="/admin/" className="text-[11px] text-gray-500 hover:text-gray-300 border border-border rounded px-2 py-1">Admin</a>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
