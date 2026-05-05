/**
 * LevelUpGrowth — Analytics
 * Sparkline charts, no external libraries.
 * Data from GET /lu/v1/analytics/overview
 */
'use strict';

const Analytics = {

    async render(container) {
        container.innerHTML = `
        <div class="view-header">
          <div><h1 class="view-title">Analytics</h1><p class="view-sub">Performance snapshots across all engines</p></div>
          <select class="form-input" id="an-period" style="width:130px;padding:8px 12px;font-size:13px" onchange="Analytics.load()">
            <option value="7">Last 7 days</option>
            <option value="30" selected>Last 30 days</option>
            <option value="90">Last 90 days</option>
          </select>
        </div>
        <div id="an-content"><div class="loading-row">Loading analytics…</div></div>`;
        await this.load();
    },

    async load() {
        const days = parseInt(document.getElementById('an-period')?.value || '30');
        const content = document.getElementById('an-content');
        if (!content) return;
        try {
            const { data } = await API.AnalyticsAPI.overview(days);
            this.renderCharts(data.engines || {}, days);
        } catch (e) {
            content.innerHTML = `<div class="empty-state">Could not load analytics: ${_esc(e.message)}<br><span style="font-size:12px;color:var(--faint)">Analytics snapshots are collected daily at 02:00 UTC. Data will appear after the first snapshot runs.</span></div>`;
        }
    },

    renderCharts(engines, days) {
        const content = document.getElementById('an-content');
        if (!content) return;

        const ENGINE_CONFIG = {
            tasks:    { label: 'Task Execution',  icon: '⚡', key: 'completed',        color: '#7C3AED', unit: 'tasks' },
            credits:  { label: 'Credit Usage',    icon: '💳', key: 'spent',            color: '#3B82F6', unit: 'credits' },
            write:    { label: 'Content Created', icon: '✍️', key: 'items_created',    color: '#A78BFA', unit: 'items' },
            marketing:{ label: 'Campaigns Sent',  icon: '📣', key: 'campaigns_sent',   color: '#F59E0B', unit: 'campaigns' },
            social:   { label: 'Posts Published', icon: '📱', key: 'posts_published',  color: '#00E5A8', unit: 'posts' },
        };

        if (!Object.keys(engines).length) {
            content.innerHTML = `<div class="empty-state">No analytics data yet.<br><span style="font-size:12.5px;color:var(--faint)">Snapshots are collected daily. Check back tomorrow for your first data points.</span></div>`;
            return;
        }

        content.innerHTML = `<div class="an-grid">${Object.entries(ENGINE_CONFIG).map(([key, cfg]) => {
            const rows = engines[key] || [];
            const values = rows.map(r => parseFloat(r.metrics?.[cfg.key] || 0));
            const total  = values.reduce((a, b) => a + b, 0);
            const latest = values[values.length - 1] || 0;
            const canvasId = 'an-chart-' + key;
            return `
              <div class="an-card">
                <div class="an-card-header">
                  <span class="an-icon" style="background:${cfg.color}18;color:${cfg.color}">${cfg.icon}</span>
                  <div>
                    <div class="an-engine-name">${cfg.label}</div>
                    <div class="an-engine-sub">${days}d window</div>
                  </div>
                </div>
                <div class="an-val" style="color:${cfg.color}">${total}</div>
                <div class="an-val-sub">${total} ${cfg.unit} total · ${latest} yesterday</div>
                <div class="an-chart-wrap">
                  <canvas id="${canvasId}" width="300" height="60"></canvas>
                </div>
              </div>`;
        }).join('')}</div>`;

        // Draw sparklines after DOM is ready
        requestAnimationFrame(() => {
            Object.entries(ENGINE_CONFIG).forEach(([key, cfg]) => {
                const rows = engines[key] || [];
                const values = rows.map(r => parseFloat(r.metrics?.[cfg.key] || 0));
                this.drawSparkline('an-chart-' + key, values, cfg.color);
            });
        });
    },

    drawSparkline(canvasId, values, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        if (!values.length || values.every(v => v === 0)) {
            ctx.fillStyle = 'rgba(255,255,255,0.04)';
            ctx.fillRect(0, h * 0.8, w, 1);
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.font = '11px Inter, sans-serif';
            ctx.fillText('No data yet', w / 2 - 28, h / 2 + 4);
            return;
        }

        const max = Math.max(...values, 1);
        const pad = 4;
        const step = values.length > 1 ? (w - pad * 2) / (values.length - 1) : w;
        const points = values.map((v, i) => ({
            x: pad + i * step,
            y: pad + ((1 - v / max) * (h - pad * 2)),
        }));

        // Fill area
        const grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, color + '3a');
        grad.addColorStop(1, color + '00');
        ctx.beginPath();
        ctx.moveTo(points[0].x, h);
        points.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(points[points.length - 1].x, h);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Line
        ctx.beginPath();
        ctx.moveTo(points[0].x, points[0].y);
        points.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // Last point dot
        const last = points[points.length - 1];
        ctx.beginPath();
        ctx.arc(last.x, last.y, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();
    },
};

window.Analytics = Analytics;
