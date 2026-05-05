/**
 * LevelUp Growth — Onboarding System
 * Steps 0-4: business info + website generation
 * Step 5 (Growth/Pro/Agency only): AI team selection with orb grid
 */
'use strict';

const Onboarding = {
    state: {
        step:       parseInt(localStorage.getItem('lu_ob_step') || '0'),
        name:       localStorage.getItem('lu_ob_name')     || '',
        industry:   localStorage.getItem('lu_ob_industry') || '',
        services:   JSON.parse(localStorage.getItem('lu_ob_services') || '[]'),
        goal:       localStorage.getItem('lu_ob_goal')     || '',
        location:   localStorage.getItem('lu_ob_location') || '',
        website_id: localStorage.getItem('lu_ob_website_id') || null,
    },
    save(key, val) {
        this.state[key] = val;
        localStorage.setItem('lu_ob_' + key, typeof val === 'object' ? JSON.stringify(val) : String(val));
    },
    clear() {
        ['step','name','industry','services','goal','location','website_id'].forEach(k => localStorage.removeItem('lu_ob_' + k));
        localStorage.setItem('lu_onboarded', '1');
    },
    getPlan() {
        try { return (localStorage.getItem('lu_selected_plan') || '').toLowerCase().trim(); } catch(_) { return ''; }
    },
    needsAgentSelection() {
        const plan = this.getPlan();
        const rules = (typeof getPlanRules !== 'undefined') ? getPlanRules(plan) : null;
        return rules && rules.ai === true && !localStorage.getItem('lu_team_selected');
    },
    render(container) {
        if (this.needsAgentSelection()) { AIOnboarding.render(container); return; }
        const stepLabels = ['Business', 'Industry', 'Goal', 'Generate', 'Done'];
        const step = this.state.step;
        const pips = stepLabels.map((s, i) =>
            '<div class="ob-pip-item"><div class="ob-pip' + (i <= step ? ' ob-pip-done' : '') + '">' + (i+1) + '</div><div class="ob-pip-label">' + s + '</div></div>' +
            (i < 4 ? '<div class="ob-pip-line' + (i < step ? ' ob-pip-line-done' : '') + '"></div>' : '')
        ).join('');
        container.innerHTML = '<div class="ob-wrap"><div class="ob-stepper">' + pips + '</div><div class="ob-card" id="ob-card"></div></div>';
        this.renderStep(step);
    },
    renderStep(step) {
        this.save('step', step);
        const card = document.getElementById('ob-card');
        if (!card) return;
        const back = step > 0 && step < 3 ? '<button class="btn btn-ghost btn-sm" onclick="Onboarding.renderStep(' + (step-1) + ')">← Back</button>' : '';
        if (step === 0) {
            card.innerHTML = '<div class="ob-step-head"><h2>Tell us about your business</h2><p>Your AI team uses this to deliver relevant work from day one.</p></div><div class="form-group"><label class="form-label">Business name *</label><input class="form-input" id="ob-name" value="' + _esc(this.state.name) + '" placeholder="e.g. Bloom Boutique, Nexus Consulting"></div><div class="form-group" style="margin-bottom:26px"><label class="form-label">Location <span style="font-size:10.5px;color:var(--faint)">optional</span></label><input class="form-input" id="ob-loc" value="' + _esc(this.state.location) + '" placeholder="e.g. New York, London, Sydney"></div><button class="btn btn-primary" style="width:100%;justify-content:center" onclick="Onboarding.step0Next()">Continue →</button>';
        } else if (step === 1) {
            card.innerHTML = '<div class="ob-step-head"><h2>What industry are you in?</h2><p>This shapes your agent team's strategy and content approach.</p></div><div class="form-group"><label class="form-label">Industry *</label><input class="form-input" id="ob-ind" value="' + _esc(this.state.industry) + '" placeholder="e.g. Interior Design, SaaS, Restaurant"></div><div class="form-group" style="margin-bottom:26px"><label class="form-label">Core services <span style="font-size:10.5px;color:var(--faint)">comma separated</span></label><input class="form-input" id="ob-svc" value="' + _esc(Array.isArray(this.state.services) ? this.state.services.join(', ') : this.state.services) + '" placeholder="e.g. Web Design, SEO, Consulting"></div><div style="display:flex;gap:10px">' + back + '<button class="btn btn-primary" style="flex:1;justify-content:center" onclick="Onboarding.step1Next()">Continue →</button></div>';
        } else if (step === 2) {
            const goals = [['leads','📊','Generate more leads'],['brand','🏆','Build brand credibility'],['ecommerce','🛒','Sell products online'],['portfolio','🎨','Showcase my work']];
            card.innerHTML = '<div class="ob-step-head"><h2>What's your primary goal?</h2><p>We'll build your first website around this.</p></div><div class="ob-goal-grid">' + goals.map(([id,icon,label]) => '<div class="ob-goal-card' + (this.state.goal === id ? ' ob-goal-selected' : '') + '" onclick="Onboarding.selectGoal('' + id + '')"><div class="ob-goal-icon">' + icon + '</div><div class="ob-goal-label">' + label + '</div></div>').join('') + '</div><div style="display:flex;gap:10px;margin-top:4px">' + back + '<button class="btn btn-primary" id="ob-gen-btn" style="flex:1;justify-content:center;' + (!this.state.goal ? 'opacity:.45;cursor:not-allowed' : '') + '" onclick="Onboarding.step2Next()">Generate My Website ✨</button></div>';
        } else if (step === 3) {
            card.innerHTML = '<div style="text-align:center;padding:16px 0"><div style="font-size:44px;margin-bottom:16px">✨</div><h2 style="margin-bottom:8px">Building your website…</h2><p style="color:var(--muted);font-size:14.5px;margin-bottom:28px">Your AI team is generating pages for <strong style="color:#D1D5DB">' + _esc(this.state.name) + '</strong></p><div class="ob-gen-box"><div id="ob-gen-label" style="font-size:13.5px;color:var(--violet);font-weight:600;margin-bottom:12px">Initialising AI builder…</div><div class="ob-gen-track"><div id="ob-gen-fill" class="ob-gen-fill" style="width:5%"></div></div><div id="ob-gen-steps" style="margin-top:14px;display:flex;flex-direction:column;gap:7px"></div></div></div>';
            this.generate();
        } else if (step === 4) {
            const plan = this.getPlan();
            const trialMsg = (() => {
    if (['growth','pro','agency'].includes(plan)) return 'AI agents activated and ready to work';
    if (plan === 'ai lite') return 'AI trial activated — 50 credits, 3 days · Research + brainstorming unlocked';
    return 'Free plan active · Build more to unlock your 3-day AI trial (50 credits)';
})();
            card.innerHTML = '<div style="text-align:center;padding:20px 0"><div style="font-size:52px;margin-bottom:18px">🎉</div><h2 style="margin-bottom:8px">Your workspace is ready.</h2><p style="color:var(--muted);font-size:15px;margin-bottom:28px">Website generated for <strong style="color:#D1D5DB">' + _esc(this.state.name) + '</strong>.</p><div class="ob-done-list"><div class="ob-done-item"><span class="ob-done-check">✓</span> Website generated and ready to edit</div><div class="ob-done-item"><span class="ob-done-check">✓</span> ' + trialMsg + '</div><div class="ob-done-item"><span class="ob-done-check">✓</span> Dashboard and all engines ready</div></div><button class="btn btn-primary btn-lg" style="width:100%;justify-content:center" onclick="Onboarding.complete()">Enter Dashboard →</button></div>';
        }
    },
    step0Next() {
        const name = document.getElementById('ob-name')?.value?.trim();
        if (!name) { _showToast('Enter your business name.', 'error'); return; }
        this.save('name', name);
        this.save('location', document.getElementById('ob-loc')?.value?.trim() || '');
        this.renderStep(1);
    },
    step1Next() {
        const ind = document.getElementById('ob-ind')?.value?.trim();
        if (!ind) { _showToast('Enter your industry.', 'error'); return; }
        this.save('industry', ind);
        const svcStr = document.getElementById('ob-svc')?.value || '';
        this.save('services', svcStr.split(',').map(s => s.trim()).filter(Boolean));
        this.renderStep(2);
    },
    selectGoal(goal) { this.save('goal', goal); this.renderStep(2); },
    step2Next() {
        if (!this.state.goal) { _showToast('Select a goal.', 'error'); return; }
        this.renderStep(3);
    },
    async generate() {
        const pageMap = { leads:['home','services','about','contact'], brand:['home','about','services','contact'], ecommerce:['home','products','about','contact'], portfolio:['home','portfolio','about','contact'] };
        const setP = (pct, label, steps=[]) => {
            const f=document.getElementById('ob-gen-fill'); const l=document.getElementById('ob-gen-label'); const s=document.getElementById('ob-gen-steps');
            if(f)f.style.width=pct+'%'; if(l)l.textContent=label;
            if(s&&steps.length)s.innerHTML=steps.map(x=>'<div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted)"><span style="color:var(--green)">✓</span>'+x+'</div>').join('');
        };
        try {
            setP(10,'Saving workspace profile…');
            await API.WorkspaceAPI.settings({ business_name:this.state.name, industry:this.state.industry, services:Array.isArray(this.state.services)?this.state.services.join(', '):this.state.services, business_desc:this.state.goal });
            setP(20,'Creating website blueprint…',['Workspace profile saved']);
            const {data:ws} = await API.WebsiteAPI.create({ wizard_mode:true, business_name:this.state.name, industry:this.state.industry, goal:this.state.goal, location:this.state.location, services:this.state.services, pages:pageMap[this.state.goal]||['home','about','services','contact'] });
            if(!ws.website_id) throw new Error(ws.error||ws.message||'Website creation failed');
            this.save('website_id',ws.website_id); setP(30,'Generating pages…',['Workspace profile saved','Website blueprint created']);
            let att=0;
            while(att<30){ await _sleep(2000); const {data:st}=await API.WebsiteAPI.get(ws.website_id); const done=st.pages_generated||st.pages_done||0; const total=st.pages_total||4; const pct=Math.max(30,Math.round(30+(done/total)*60)); const dp=Array.isArray(st.pages)?st.pages.map(p=>p.title||p.slug||p):[]; setP(pct,'Generating pages… ('+done+'/'+total+')',['Workspace profile saved','Website blueprint created',...dp.map(p=>'Page "'+p+'" created')]); if(st.status==='complete'||st.status==='published'){setP(100,'Website complete! 🎉',['All pages generated','SEO optimised','Ready to edit']);await _sleep(800);this.renderStep(4);return;} att++; }
            setP(100,'Generation complete.'); await _sleep(600); this.renderStep(4);
        } catch(e) {
            const l=document.getElementById('ob-gen-label'); if(l)l.innerHTML='<span style="color:var(--red)">Error: '+_esc(e.message)+'</span> — <a href="#" onclick="Onboarding.renderStep(4)">Continue anyway →</a>';
        }
    },
    complete() { this.clear(); Router.go('dashboard'); },
};

// ════════ AI ONBOARDING — orb-based team selection ════════════
const AIOnboarding = {
    _selected:[], _maxSelect:2, _level:'specialist', _plan:'growth', _step:0,
    render(container) {
        const plan = Onboarding.getPlan();
        const rules = (typeof getPlanRules!=='undefined') ? getPlanRules(plan) : {count:2,level:'specialist'};
        this._selected = (typeof AgentTeam!=='undefined') ? AgentTeam.getSelectedIds().slice(0,rules.count) : [];
        this._maxSelect = rules.count||2; this._level = rules.level||'specialist'; this._plan = plan;
        const PLAN_COLORS = {growth:'#7C3AED',pro:'#00E5A8',agency:'#F59E0B'};
        const pc = PLAN_COLORS[plan]||'#7C3AED';
        const cap = plan.charAt(0).toUpperCase()+plan.slice(1);
        container.innerHTML = `<div class="aio-shell" id="aio-shell"><div class="aio-bg"></div><div class="aio-content">
<div class="aio-step" id="aio-step-0">
  <div class="aio-center-orb" id="aio-welcome-orb"></div>
  <h1 class="aio-title">Let's build your AI marketing team</h1>
  <p class="aio-sub">I'm Sarah, your Digital Marketing Manager. I'll coordinate your specialists and keep your marketing running 24/7.</p>
  <div style="margin-bottom:20px"><span style="background:${pc}14;border:1px solid ${pc}30;border-radius:100px;padding:6px 18px;font-size:13px;font-weight:700;color:${pc};font-family:var(--ff-h)">${cap} Plan · ${rules.count} Specialist${rules.count!==1?'s':''} · Full AI Access</span></div>
  <div class="aio-msg-bubble"><div id="aio-msg-orb-0"></div><div class="aio-bubble">Hi! You're on the <strong>${cap}</strong> plan. You get to choose <strong>${rules.count} specialist${rules.count!==1?'s':''}</strong> to join your team. I'll coordinate everything. Ready to pick?</div></div>
  <button class="btn btn-primary btn-lg aio-btn" onclick="AIOnboarding.goStep(1)">Choose My Specialists →</button>
</div>
<div class="aio-step" id="aio-step-1" style="display:none">
  <div class="aio-select-header"><div id="aio-select-orb"></div><div><div class="aio-select-title">Choose your AI specialists</div><div class="aio-select-sub" id="aio-counter">0 of ${rules.count} selected</div></div></div>
  <div class="aio-filter-row"><button class="aio-filter active" onclick="AIOnboarding.filter(this,'')">All</button><button class="aio-filter" onclick="AIOnboarding.filter(this,'seo')">SEO</button><button class="aio-filter" onclick="AIOnboarding.filter(this,'content')">Content</button><button class="aio-filter" onclick="AIOnboarding.filter(this,'social')">Social</button><button class="aio-filter" onclick="AIOnboarding.filter(this,'crm')">CRM</button></div>
  <div class="agent-select-grid" id="aio-grid"></div>
  <button class="btn btn-primary btn-lg aio-btn" id="aio-confirm-btn" onclick="AIOnboarding.confirm()" style="opacity:.45;cursor:not-allowed" disabled>Confirm My Team →</button>
</div>
<div class="aio-step" id="aio-step-2" style="display:none">
  <div class="aio-team-orbs" id="aio-team-orbs"></div>
  <h1 class="aio-title" style="font-size:26px">Your AI team is ready 🎉</h1>
  <p class="aio-sub" id="aio-done-msg"></p>
  <div class="aio-team-list" id="aio-team-list"></div>
  <button class="btn btn-primary btn-lg aio-btn" onclick="AIOnboarding.proceed()">Enter Dashboard →</button>
</div>
</div></div>`;
        if(typeof OrbAvatar!=='undefined'){
            const wo=document.getElementById('aio-welcome-orb'); if(wo){wo.innerHTML=OrbAvatar.buildAgentOrb('sarah','lg','executing'); setTimeout(()=>{const o=wo.querySelector('.orb');if(o){o.dataset.state='success';setTimeout(()=>{o.dataset.state='idle';},700);}},2000);}
            const mo=document.getElementById('aio-msg-orb-0'); if(mo)mo.innerHTML=OrbAvatar.buildAgentOrb('sarah','sm','idle');
            const so=document.getElementById('aio-select-orb'); if(so)so.innerHTML=OrbAvatar.buildAgentOrb('sarah','md','thinking');
        }
        this._renderGrid('');
    },
    goStep(n){
        [0,1,2].forEach(i=>{const el=document.getElementById('aio-step-'+i);if(el)el.style.display=i===n?'flex':'none';}); this._step=n;
        if(n===1)this._updateCounter();
    },
    filter(btn,cat){
        document.querySelectorAll('.aio-filter').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); this._renderGrid(cat);
    },
    _renderGrid(cat){
        const grid=document.getElementById('aio-grid'); if(!grid||typeof SPECIALISTS==='undefined')return;
        const list=cat?SPECIALISTS.filter(s=>s.category===cat):SPECIALISTS;
        const lc=typeof LEVEL_COLORS!=='undefined'?LEVEL_COLORS[this._level]||'#6B7280':'#6B7280';
        const ll=typeof LEVEL_LABELS!=='undefined'?LEVEL_LABELS[this._level]||'Specialist':'Specialist';
        grid.innerHTML=list.map(a=>{
            const sel=this._selected.includes(a.id);
            const agentLevel=(typeof OrbAvatar!=='undefined'&&OrbAvatar.AGENT_LEVELS[a.id])?OrbAvatar.AGENT_LEVELS[a.id]:this._level;
            const orb=(typeof OrbAvatar!=='undefined')?OrbAvatar.buildOrb(a.orbType,'md',sel?'executing':'idle','',agentLevel):'';
            return '<div class="agent-card-comp selectable'+(sel?' agent-card-selected':'')+'" data-id="'+a.id+'" style="border-color:'+(sel?a.color+'60':a.color+'18')+';'+(sel?'background:'+a.color+'08':'')+'" onclick="AIOnboarding.toggleAgent(''+a.id+'')"><div class="agent-card-orb-wrap">'+orb+'</div><div class="agent-card-body"><div class="agent-card-name" style="color:'+a.color+'">'+a.name+'</div><div class="agent-card-role">'+a.role+'</div><div class="agent-level-badge" style="background:'+lc+'12;color:'+lc+';border-color:'+lc+'28">'+ll+'</div><div class="agent-card-desc">'+a.desc+'</div></div></div>';
        }).join('');
    },
    toggleAgent(id){
        const idx=this._selected.indexOf(id);
        if(idx>=0){this._selected.splice(idx,1);}
        else{if(this._selected.length>=this._maxSelect){_showToast('Max '+this._maxSelect+' specialists on your plan.','info');return;}this._selected.push(id);}
        const af=document.querySelector('.aio-filter.active'); const cat=af?af.onclick?.toString().match(/'([^']*)'/)&&af.onclick.toString().match(/'([^']*)'/)[1]:''; this._renderGrid(cat||''); this._updateCounter();
    },
    _updateCounter(){
        const n=this._selected.length; const max=this._maxSelect;
        const el=document.getElementById('aio-counter'); if(el)el.textContent=n+' of '+max+' selected';
        const btn=document.getElementById('aio-confirm-btn'); if(btn){const r=n===max;btn.disabled=!r;btn.style.opacity=r?'1':'.45';btn.style.cursor=r?'pointer':'not-allowed';}
    },
    confirm(){
        if(this._selected.length<this._maxSelect){_showToast('Select '+this._maxSelect+' specialists.','error');return;}
        if(typeof AgentTeam!=='undefined')AgentTeam.setSpecialists(this._selected,this._plan);
        localStorage.setItem('lu_team_selected','1'); this.goStep(2); this._renderDoneStep();
    },
    _renderDoneStep(){
        const ow=document.getElementById('aio-team-orbs'); const tl=document.getElementById('aio-team-list'); const dm=document.getElementById('aio-done-msg');
        const all=[{id:'sarah',name:'Sarah',orbType:'dmm',color:'#F59E0B',role:'Digital Marketing Manager'}];
        this._selected.forEach(id=>{const sp=typeof SPECIALISTS!=='undefined'?SPECIALISTS.find(s=>s.id===id):null;if(sp)all.push({...sp,level:this._level});});
        if(ow&&typeof OrbAvatar!=='undefined'){ow.innerHTML=all.map(a=>'<div style="display:flex;flex-direction:column;align-items:center;gap:6px">'+(a.id==='sarah'?OrbAvatar.buildAgentOrb('sarah','md','success'):OrbAvatar.buildOrb(a.orbType,'md','success','',a.level||'specialist'))+'<div style="font-size:11px;font-weight:700;color:'+a.color+';font-family:var(--ff-h)">'+a.name+'</div></div>').join('');setTimeout(()=>{document.querySelectorAll('#aio-team-orbs .orb').forEach(o=>{o.dataset.state='idle';});},1200);}
        if(dm)dm.textContent='Sarah + '+this._selected.length+' specialist'+(this._selected.length!==1?'s':'')+' are ready to work on your business.';
        if(tl)tl.innerHTML=all.map(a=>'<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:rgba(255,255,255,.03);border-radius:9px"><div style="width:8px;height:8px;border-radius:50%;background:'+a.color+';flex-shrink:0"></div><div style="font-family:var(--ff-h);font-weight:700;font-size:13.5px;color:'+a.color+'">'+a.name+'</div><div style="font-size:12px;color:var(--faint);flex:1">'+a.role+'</div></div>').join('');
    },
    proceed(){ Onboarding.render(document.getElementById('auth-view')||document.getElementById('auth-shell')); },
};

window.Onboarding   = Onboarding;
window.AIOnboarding = AIOnboarding;
