/**
 * LevelUpGrowth — Notifications
 * Bell + 30s polling + modal + mark read
 */
'use strict';

const Notifications = {
    _timer: null,
    _count: 0,

    init() {
        this.poll();
        if (this._timer) clearInterval(this._timer);
        this._timer = setInterval(() => this.poll(), 30_000);
    },

    stop() {
        if (this._timer) { clearInterval(this._timer); this._timer = null; }
    },

    async poll() {
        try {
            const { data } = await API.NotifAPI.list(true);
            const count = data.total_unread || 0;
            this._count = count;
            this._updateBell(count);
        } catch (_) {}
    },

    _updateBell(count) {
        const bell = document.getElementById('app-notif-bell');
        if (!bell) return;
        if (count > 0) {
            bell.innerHTML = `🔔<span class="notif-badge">${count}</span>`;
            bell.title = `${count} unread notification${count > 1 ? 's' : ''}`;
        } else {
            bell.innerHTML = '🔔';
            bell.title = 'Notifications';
        }
    },

    async openModal() {
        try {
            const { data } = await API.NotifAPI.list(false);
            const items = data.notifications || [];
            this._renderModal(items);
        } catch (_) {
            this._renderModal([]);
        }
    },

    _renderModal(items) {
        const existing = document.getElementById('notif-modal-bd');
        if (existing) existing.remove();

        const bd = document.createElement('div');
        bd.id = 'notif-modal-bd';
        bd.className = 'modal-backdrop';
        bd.onclick = e => { if (e.target === bd) bd.remove(); };
        bd.innerHTML = `
          <div class="modal" style="max-width:420px">
            <div class="modal-header">
              <h3 style="font-size:15px">Notifications</h3>
              <button class="modal-close" onclick="document.getElementById('notif-modal-bd').remove()">✕</button>
            </div>
            <div style="max-height:440px;overflow-y:auto">
              ${items.length === 0
                ? '<div style="padding:40px;text-align:center;color:var(--faint);font-size:13.5px">No notifications yet.</div>'
                : items.map(n => `
                  <div class="notif-item${n.read_at ? '' : ' notif-unread'}" id="notif-${n.id}">
                    <div class="notif-dot${n.read_at ? '' : ' notif-dot-on'}"></div>
                    <div style="flex:1;min-width:0">
                      <div class="notif-title">${_esc(n.title)}</div>
                      <div class="notif-time">${_relTime(n.created_at)}</div>
                    </div>
                    ${n.read_at ? '' : `<button class="notif-read-btn" onclick="Notifications.markRead(${n.id})">Mark read</button>`}
                  </div>`).join('')}
            </div>
          </div>`;

        document.body.appendChild(bd);
        this.poll(); // refresh count after opening
    },

    async markRead(id) {
        try {
            await API.NotifAPI.markRead(id);
            const row = document.getElementById('notif-' + id);
            if (row) {
                row.classList.remove('notif-unread');
                row.querySelector('.notif-dot')?.classList.remove('notif-dot-on');
                row.querySelector('.notif-read-btn')?.remove();
            }
            this.poll();
        } catch (_) {}
    },
};

function _relTime(ts) {
    if (!ts) return '';
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000);
    if (diff < 60)  return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return new Date(ts).toLocaleDateString();
}

window.Notifications = Notifications;
window._relTime = _relTime;
