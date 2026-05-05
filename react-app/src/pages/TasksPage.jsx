import React, { useEffect, useState } from 'react';
import * as api from '../services/api';

export default function TasksPage() {
  const [tasks, setTasks] = useState([]);
  useEffect(() => { api.tasks.list('?limit=50').then(d => setTasks(d.tasks || [])).catch(() => {}); }, []);

  const SC = { completed: 'bg-accent/10 text-accent', failed: 'bg-red/10 text-red', running: 'bg-amber/10 text-amber', queued: 'bg-blue/10 text-blue', pending: 'bg-gray-500/10 text-gray-400' };

  return (
    <div>
      <div className="mb-6"><h1 className="font-heading text-xl font-bold">Tasks</h1></div>
      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="text-[11px] text-gray-500 uppercase border-b border-border">
            <th className="text-left px-4 py-3">Action</th><th className="text-left px-4 py-3">Engine</th><th className="text-left px-4 py-3">Status</th><th className="text-left px-4 py-3">Created</th>
          </tr></thead>
          <tbody>{tasks.map(t => (
            <tr key={t.id || t.task_id} className="border-b border-s2 hover:bg-s2">
              <td className="px-4 py-3 font-medium">{t.title || t.action}</td>
              <td className="px-4 py-3 text-gray-400">{t.engine || '—'}</td>
              <td className="px-4 py-3"><span className={`text-[11px] px-2 py-0.5 rounded-full ${SC[t.status] || SC.pending}`}>{t.status}</span></td>
              <td className="px-4 py-3 text-gray-500">{t.created_at ? new Date(t.created_at).toLocaleDateString() : '—'}</td>
            </tr>
          ))}</tbody>
        </table>
        {!tasks.length && <div className="text-center text-gray-500 py-8">No tasks yet</div>}
      </div>
    </div>
  );
}
