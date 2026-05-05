import React, { useEffect, useState } from 'react';
import * as api from '../../services/api';

export default function CalendarPage() {
  const [events, setEvents] = useState([]);
  useEffect(() => {
    const now = new Date(); const from = new Date(now.getFullYear(), now.getMonth(), 1).toISOString();
    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString();
    api.calendar.events(from, to).then(d => setEvents(d || []));
  }, []);
  return (
    <div>
      <div className="mb-6"><h1 className="font-heading text-xl font-bold">📅 Calendar</h1><p className="text-gray-500 text-sm">{new Date().toLocaleString('default', { month: 'long', year: 'numeric' })}</p></div>
      <div className="space-y-2">{events.map((e, i) => <div key={e.id || i} className="bg-s1 border border-border rounded-lg p-3 flex items-center gap-3" style={{ borderLeftWidth: 3, borderLeftColor: e.color || '#3B82F6' }}>
        <div><div className="text-sm font-medium">{e.title}</div><div className="text-[11px] text-gray-500">{new Date(e.starts_at).toLocaleDateString()} · {e.category}</div></div>
      </div>)}</div>
      {!events.length && <div className="text-center text-gray-500 py-8">No events this month</div>}
    </div>
  );
}
