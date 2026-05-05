<x-marketing-layout title="AI Specialists — LevelUp" description="Meet your 21-strong AI marketing team. Led by Sarah, your Digital Marketing Manager.">

@push('styles')
<style>
  .specialists-hero{text-align:center;padding:80px 24px 56px;max-width:720px;margin:0 auto}
  .agent-card{background:var(--s1);border:1px solid var(--border);border-radius:16px;padding:24px;display:flex;flex-direction:column;gap:14px;transition:all .2s}
  .agent-card:hover{border-color:var(--p);transform:translateY(-2px)}
  .agent-header{display:flex;align-items:center;gap:14px}
  .agent-avatar{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
  .agent-name{font-family:'Syne',system-ui,sans-serif;font-size:17px;font-weight:700}
  .agent-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
  .agent-desc{font-size:13px;color:var(--muted);line-height:1.6}
  .agent-skills{display:flex;flex-wrap:wrap;gap:6px}
  .skill-tag{background:var(--s2);border:1px solid var(--border);border-radius:6px;padding:3px 10px;font-size:11px;color:var(--muted)}
  .domain-label{font-family:'Syne',system-ui,sans-serif;font-size:20px;font-weight:700;margin-bottom:20px;margin-top:40px;padding-bottom:12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
  .sarah-card{background:linear-gradient(135deg,rgba(245,158,11,.06),rgba(108,92,231,.06));border:2px solid rgba(245,158,11,.3);border-radius:20px;padding:32px;max-width:860px;margin:0 auto 48px;display:flex;gap:24px;align-items:flex-start}
  .sarah-big-avatar{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--p),var(--am));display:flex;align-items:center;justify-content:center;font-size:36px;flex-shrink:0;border:3px solid rgba(245,158,11,.4)}
</style>
@endpush

<div class="specialists-hero">
  <span class="badge badge-p" style="margin-bottom:16px">Your AI Team</span>
  <h1 style="font-size:clamp(32px,5vw,52px);font-weight:800;margin-bottom:16px">Meet your <span class="gradient-text">21 specialists</span></h1>
  <p style="font-size:16px;color:var(--muted)">Each agent has a domain, a personality, and a track record. They work together in strategy meetings. Sarah keeps them aligned.</p>
</div>

<div class="section" style="padding-top:0">

  <!-- Sarah -->
  <div class="sarah-card">
    <div class="sarah-big-avatar">👩‍💼</div>
    <div>
      <div style="font-family:'Syne',system-ui,sans-serif;font-size:22px;font-weight:800;margin-bottom:4px">Sarah <span class="badge badge-p" style="font-size:11px;vertical-align:middle">DMM</span></div>
      <div style="font-size:12px;color:var(--am);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">Digital Marketing Manager · Your lead agent</div>
      <p style="font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:14px">Sarah is your Digital Marketing Manager and the only agent who initiates contact. She orchestrates all other specialists, runs your strategy meetings, monitors execution, and keeps everything on track. She never executes directly — she delegates, supervises, and reports to you.</p>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <span class="skill-tag">Strategy orchestration</span><span class="skill-tag">Agent delegation</span><span class="skill-tag">Proactive monitoring</span><span class="skill-tag">Meeting facilitation</span><span class="skill-tag">30/60/90-day planning</span>
      </div>
      <div style="margin-top:16px;font-size:13px;color:var(--muted)">✓ Included in <strong style="color:var(--text)">Growth, Pro, Agency</strong> plans</div>
    </div>
  </div>

  <!-- SEO Domain -->
  <div class="domain-label"><span>🔍</span> SEO Specialists</div>
  <div class="grid-3">
    @foreach([
      ['James','james','Senior SEO Strategist','#3B82F6','🕵️','SERP analysis, deep technical audits, AI-powered SEO reports. James is the one who figures out why you rank or don't.', ['SERP analysis','Deep audits','AI reports','Keyword strategy']],
      ['Alex','alex','Technical SEO Lead','#06B6D4','🔧','Link analysis, outbound link management, anchor text optimization. Alex handles the technical plumbing of SEO.', ['Link analysis','Outbound links','Anchor text','Technical fixes']],
      ['Diana','diana','Local SEO Specialist','#3B82F6','📍','Google Business optimization, local citations, geo-targeted content. Diana owns your local search presence.', ['Google Business','Local citations','Geo-targeting','Local content']],
      ['Ryan','ryan','SEO Analytics Manager','#06B6D4','📊','Rank tracking, traffic attribution, conversion analysis. Ryan measures what matters.', ['Rank tracking','Traffic analysis','Attribution','Reporting']],
      ['Sofia','sofia','International SEO Lead','#7C3AED','🌐','Multilingual content, hreflang implementation, Arabic SEO for MENA markets. Sofia crosses language barriers.', ['Hreflang','Arabic SEO','Multilingual','International strategy']],
    ] as $a)
    <div class="agent-card">
      <div class="agent-header">
        <div class="agent-avatar" style="background:{{ $a[5]=='#3B82F6'?'rgba(59,139,245,.12)':($a[5]=='#06B6D4'?'rgba(6,182,212,.12)':'rgba(124,58,237,.12)') }}">{{ $a[4] }}</div>
        <div><div class="agent-name">{{ $a[0] }}</div><div class="agent-title" style="color:{{ $a[5] }}">{{ $a[2] }}</div></div>
      </div>
      <div class="agent-desc">{{ $a[6] }}</div>
      <div class="agent-skills">@foreach($a[7] as $s)<span class="skill-tag">{{ $s }}</span>@endforeach</div>
    </div>
    @endforeach
  </div>

  <!-- Content Domain -->
  <div class="domain-label"><span>✍️</span> Content Specialists</div>
  <div class="grid-3">
    @foreach([
      ['Priya','priya','Content Strategist','#7C3AED','📋','Editorial calendars, content briefs, content-market fit analysis. Priya makes sure the right content gets made at the right time.', ['Content strategy','Editorial calendar','Briefs','Content planning']],
      ['Leo','leo','Senior Copywriter','#F97316','🖊️','Long-form articles, blog posts, web copy, brand voice consistency. Leo writes the words that rank and convert.', ['Articles','Blog posts','Web copy','Long-form']],
      ['Maya','maya','Email Copywriter','#7C3AED','📧','Email sequences, newsletters, drip campaigns. Maya gets opens and clicks.', ['Email sequences','Newsletters','Drip campaigns','Subject lines']],
      ['Chris','chris','Ad Copywriter','#F97316','📢','PPC headlines, social ad copy, CTAs. Chris makes every word earn its place.', ['PPC copy','Social ads','Headlines','CTAs']],
      ['Nora','nora','Video Script Writer','#7C3AED','🎬','VSL scripts, explainer videos, social video scripts. Nora gives your camera something to say.', ['VSL scripts','Explainer videos','Social video','Scriptwriting']],
    ] as $a)
    <div class="agent-card">
      <div class="agent-header">
        <div class="agent-avatar" style="background:{{ $a[5]=='#7C3AED'?'rgba(124,58,237,.12)':'rgba(249,115,22,.12)' }}">{{ $a[4] }}</div>
        <div><div class="agent-name">{{ $a[0] }}</div><div class="agent-title" style="color:{{ $a[5] }}">{{ $a[2] }}</div></div>
      </div>
      <div class="agent-desc">{{ $a[6] }}</div>
      <div class="agent-skills">@foreach($a[7] as $s)<span class="skill-tag">{{ $s }}</span>@endforeach</div>
    </div>
    @endforeach
  </div>

  <!-- Social Domain -->
  <div class="domain-label"><span>📱</span> Social Media Specialists</div>
  <div class="grid-3">
    @foreach([
      ['Marcus','marcus','Social Media Manager','#EC4899','📲','Social strategy, posting schedules, community management. Marcus keeps your audience engaged.', ['Social strategy','Scheduling','Community','Content calendar']],
      ['Zara','zara','Instagram & Visual Lead','#EC4899','📸','Reels, stories, visual content strategy. Zara makes your brand look stunning.', ['Reels','Stories','Visual strategy','Instagram']],
      ['Tyler','tyler','LinkedIn & B2B Lead','#3B82F6','💼','LinkedIn posts, thought leadership, B2B content. Tyler builds your professional reputation.', ['LinkedIn','Thought leadership','B2B content','Networking']],
      ['Aria','aria','TikTok & Short Video','#EC4899','🎵','Trends, hooks, short-form video strategy. Aria speaks Gen-Z fluently.', ['TikTok','Short video','Trending hooks','Viral strategy']],
      ['Jordan','jordan','Twitter/X & Community','#3B82F6','𝕏','Real-time engagement, trending topics, community building. Jordan keeps you in the conversation.', ['Twitter/X','Real-time','Trending','Community']],
    ] as $a)
    <div class="agent-card">
      <div class="agent-header">
        <div class="agent-avatar" style="background:{{ $a[5]=='#EC4899'?'rgba(236,72,153,.12)':'rgba(59,139,245,.12)' }}">{{ $a[4] }}</div>
        <div><div class="agent-name">{{ $a[0] }}</div><div class="agent-title" style="color:{{ $a[5] }}">{{ $a[2] }}</div></div>
      </div>
      <div class="agent-desc">{{ $a[6] }}</div>
      <div class="agent-skills">@foreach($a[7] as $s)<span class="skill-tag">{{ $s }}</span>@endforeach</div>
    </div>
    @endforeach
  </div>

  <!-- CRM & Growth Domain -->
  <div class="domain-label"><span>📊</span> CRM & Growth Specialists</div>
  <div class="grid-3">
    @foreach([
      ['Elena','elena','CRM Manager','#00E5A8','🤝','Lead scoring, pipeline management, deal tracking. Elena keeps your sales organized.', ['Lead scoring','Pipeline','Deals','CRM']],
      ['Sam','sam','Growth Hacker','#F59E0B','🚀','Conversion optimization, A/B testing, funnel analysis. Sam finds the leaks and plugs them.', ['CRO','A/B testing','Funnel analysis','Growth']],
      ['Kai','kai','Paid Media Manager','#F59E0B','💰','Meta Ads, Google Ads, campaign optimization. Kai makes your ad budget work harder.', ['Meta Ads','Google Ads','Paid campaigns','ROAS']],
      ['Vera','vera','Customer Success Lead','#00E5A8','💚','Retention strategy, upsell opportunities, health scoring. Vera keeps customers happy.', ['Retention','Upselling','Health scores','Success']],
      ['Max','max','Analytics Manager','#F59E0B','📈','Reporting, attribution modeling, KPI dashboards. Max tells you what the numbers mean.', ['Reporting','Attribution','KPIs','Analytics']],
    ] as $a)
    <div class="agent-card">
      <div class="agent-header">
        <div class="agent-avatar" style="background:{{ $a[5]=='#00E5A8'?'rgba(0,229,168,.12)':'rgba(245,158,11,.12)' }}">{{ $a[4] }}</div>
        <div><div class="agent-name">{{ $a[0] }}</div><div class="agent-title" style="color:{{ $a[5] }}">{{ $a[2] }}</div></div>
      </div>
      <div class="agent-desc">{{ $a[6] }}</div>
      <div class="agent-skills">@foreach($a[7] as $s)<span class="skill-tag">{{ $s }}</span>@endforeach</div>
    </div>
    @endforeach
  </div>

</div>

<!-- CTA -->
<div class="section section-center" style="padding-top:0">
  <div style="background:linear-gradient(135deg,rgba(108,92,231,.1),rgba(0,229,168,.05));border:1px solid rgba(108,92,231,.2);border-radius:20px;padding:56px 32px;max-width:680px;margin:0 auto">
    <h2 style="font-size:clamp(24px,4vw,36px);margin-bottom:12px">Your team is ready.<br>Are you?</h2>
    <p style="color:var(--muted);margin-bottom:28px">On Growth and above, Sarah and your chosen specialists start working the moment you approve the plan.</p>
    <a href="/sign-up" class="btn btn-primary btn-lg">Hire your AI team</a>
    <div style="margin-top:14px;font-size:13px;color:var(--muted)">Free 3-day trial · No credit card</div>
  </div>
</div>

</x-marketing-layout>
