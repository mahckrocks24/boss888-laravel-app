/**
 * LevelUpGrowth — Task System
 * List view, detail modal, cancel + retry actions
 */
'use strict';

const Tasks = {

    async render(container, focusTaskId = null) {
        container.innerHTML = `
        <div class="view-header">
          <div><h1 class="view-title">Tasks</h1><p class="view-sub">All AI agent tasks and their status</p></div>
          <div style="display:flex;gap:8px">
            <select class="form-input" id="tasks-filter" style="width:160px;padding:8px 12px;font-size:13px" onchange="Tasks.applyFilter()">
              <option value="">All statuses</option>
              <option value="in_progress">In Progress</option>
              <option value="queued">Queued</option>
              <option value="completed">Completed</option>
              <option value="failed">Failed</option>
              <option value="pending">Pending</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="Tasks.load()">↻</button>
          </div>
        </div>
        <div id="tasks-list-wrap"></div>`;

        await this.load();
        if (focusTaskId) {
            setTimeout(() => this.openDetail(focusTaskId), 200);
        }
    },

    _allTasks: [],

    async load() {
        const wrap = document.getElementById('tasks-list-wrap');
        if (!wrap) return;
        wrap.innerHTML = '<div class="loading-row">Loading tasks…</div>';
        try {
            const { data } = await API.TasksAPI.list('?limit=50');
            this._allTasks = data.tasks || [];
            this.applyFilter();
        } catch (e) {
            wrap.innerHTML = `<div class="empty-state">Could not load tasks: ${_esc(e.message)}</div>`;
        }
    },

    applyFilter() {
        const filter = document.getElementById('tasks-filter')?.value || '';
        const tasks  = filter ? this._allTasks.filter(t => t.status === filter) : this._allTasks;
        this.renderList(tasks);
    },

    renderList(tasks) {
        const wrap = document.getElementById('tasks-list-wrap');
        if (!wrap) return;

        if (!tasks.length) {
            wrap.innerHTML = '<div class="empty-state">No tasks match the current filter.</div>';
            return;
        }

        const STATUS_COLOR = { completed:'var(--green)', in_progress:'var(--amber)', queued:'var(--blue)', failed:'var(--red)', pending:'var(--faint)', retrying:'var(--violet)' };
        const AGENT_COLORS = { dmm:'#6C5CE7', sarah:'#6C5CE7', james:'#3B8BF5', priya:'#A78BFA', marcus:'#F59E0B', elena:'#F87171', alex:'#00E5A8' };
        const AGENT_ICONS  = { dmm:'🎯', sarah:'🎯', james:'🔍', priya:'✍️', marcus:'📱', elena:'🤝', alex:'⚙️' };

        wrap.innerHTML = `
          <div class="tasks-table">
            <div class="tasks-thead">
              <div class="tasks-th" style="flex:2">Task</div>
              <div class="tasks-th">Agent</div>
              <div class="tasks-th">Origin</div>
              <div class="tasks-th">Status</div>
              <div class="tasks-th">Duration</div>
              <div class="tasks-th">Retries</div>
              <div class="tasks-th">Actions</div>
            </div>
            ${tasks.map(t => {
                const col    = STATUS_COLOR[t.status] || 'var(--faint)';
                const agCol  = AGENT_COLORS[t.agent_id] || '#8892a4';
                const agIcon = AGENT_ICONS[t.agent_id]  || '🤖';
                const dur    = t.duration_ms ? (t.duration_ms / 1000).toFixed(1) + 's' : '—';
                return `
                <div class="tasks-row" onclick="Tasks.openDetail('${_esc(t.task_id)}')">
                  <div class="tasks-td" style="flex:2">
                    <div class="task-title-cell">${_esc((t.title || '').slice(0, 60))}</div>
                    <div class="task-time-cell">${_relTime(t.created_at)}</div>
                  </div>
                  <div class="tasks-td">
                    <div style="display:flex;align-items:center;gap:5px">
                      <span style="color:${agCol}">${agIcon}</span>
                      <span style="font-size:12px;color:${agCol};font-weight:600">${_esc(t.agent_id || '—')}</span>
                    </div>
                  </div>
                  <div class="tasks-td"><span style="font-size:11.5px;color:var(--muted)">${_esc(t.origin || t.created_from || 'user')}</span></div>
                  <div class="tasks-td"><span class="status-chip" style="background:${col}18;color:${col};border-color:${col}35">${t.status}</span></div>
                  <div class="tasks-td" style="font-size:12.5px;color:var(--faint)">${dur}</div>
                  <div class="tasks-td" style="font-size:12.5px;color:var(--faint)">${t.retry_count || 0}</div>
                  <div class="tasks-td" onclick="event.stopPropagation()">
                    ${['in_progress','queued','pending'].includes(t.status)
                      ? `<button class="tasks-action-btn" onclick="Tasks.cancel('${_esc(t.task_id)}',this)">Cancel</button>` : ''}
                    ${t.status === 'failed'
                      ? `<button class="tasks-action-btn tasks-action-retry" onclick="Tasks.retry('${_esc(t.task_id)}',this)">Retry</button>` : ''}
                  </div>
                </div>`;
            }).join('')}
          </div>`;
    },

    async openDetail(taskId) {
        let task = this._allTasks.find(t => t.task_id === taskId);
        if (!task) {
            try { const { data } = await API.TasksAPI.get(taskId); task = data; } catch (_) { return; }
        }

        const existing = document.getElementById('task-detail-bd');
        if (existing) existing.remove();

        const STATUS_COLOR = { completed:'var(--green)', in_progress:'var(--amber)', queued:'var(--blue)', failed:'var(--red)', pending:'var(--faint)' };
        const col = STATUS_COLOR[task.status] || 'var(--faint)';

        const bd = document.createElement('div');
        bd.id = 'task-detail-bd';
        bd.className = 'modal-backdrop';
        bd.onclick = e => { if (e.target === bd) bd.remove(); };
        bd.innerHTML = `
          <div class="modal" style="max-width:580px">
            <div class="modal-header">
              <h3 style="font-size:15px">Task Detail</h3>
              <button class="modal-close" onclick="document.getElementById('task-detail-bd').remove()">✕</button>
            </div>
            <div class="modal-body">
              <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px">
                <span class="status-chip" style="background:${col}18;color:${col};border-color:${col}35;flex-shrink:0">${task.status}</span>
                <div>
                  <div style="font-family:var(--ff-h);font-weight:700;font-size:15px;color:#D1D5DB">${_esc(task.title || '')}</div>
                  <div style="font-size:12px;color:var(--faint);margin-top:4px">ID: ${_esc(task.task_id)}</div>
                </div>
              </div>
              <div class="detail-grid">
                <div class="detail-row"><span class="detail-label">Agent</span><span class="detail-value">${_esc(task.agent_id || '—')}</span></div>
                <div class="detail-row"><span class="detail-label">Origin</span><span class="detail-value">${_esc(task.origin || task.created_from || '—')}</span></div>
                <div class="detail-row"><span class="detail-label">Created</span><span class="detail-value">${task.created_at ? new Date(task.created_at).toLocaleString() : '—'}</span></div>
                <div class="detail-row"><span class="detail-label">Duration</span><span class="detail-value">${task.duration_ms ? (task.duration_ms / 1000).toFixed(1) + 's' : '—'}</span></div>
                <div class="detail-row"><span class="detail-label">Retries</span><span class="detail-value">${task.retry_count || 0}</span></div>
                <div class="detail-row"><span class="detail-label">Tokens</span><span class="detail-value">${task.tokens_used || '—'}</span></div>
              </div>
              ${task.error_message ? `<div class="detail-error">${_esc(task.error_message)}</div>` : ''}
              ${task.output_content ? `<div class="detail-output"><div style="font-size:11px;color:var(--faint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.09em">Output</div><div class="detail-output-text">${_esc(task.output_content).slice(0, 500)}${task.output_content.length > 500 ? '…' : ''}</div></div>` : ''}
            </div>
            <div class="modal-footer">
              ${['in_progress','queued','pending'].includes(task.status) ? `<button class="btn btn-ghost btn-sm" onclick="Tasks.cancel('${_esc(task.task_id)}',this)">Cancel Task</button>` : ''}
              ${task.status === 'failed' ? `<button class="btn btn-primary btn-sm" onclick="Tasks.retry('${_esc(task.task_id)}',this)">Retry Task</button>` : ''}
              <button class="btn btn-ghost btn-sm" onclick="document.getElementById('task-detail-bd').remove()" style="margin-left:auto">Close</button>
            </div>
          </div>`;
        document.body.appendChild(bd);
    },

    async cancel(taskId, btn) {
        if (!confirm('Cancel this task?')) return;
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        try {
            await API.TasksAPI.cancel(taskId);
            _showToast('Task cancelled.', 'success');
            await this.load();
            document.getElementById('task-detail-bd')?.remove();
        } catch (e) {
            _showToast('Cancel failed: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Cancel'; }
        }
    },

    async retry(taskId, btn) {
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        try {
            await API.TasksAPI.retry(taskId);
            _showToast('Task queued for retry.', 'success');
            await this.load();
            document.getElementById('task-detail-bd')?.remove();
        } catch (e) {
            _showToast('Retry failed: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Retry'; }
        }
    },
};

window.Tasks = Tasks;
