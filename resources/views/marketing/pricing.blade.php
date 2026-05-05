<x-marketing-layout title="Pricing — LevelUp" description="Simple, transparent pricing. Start free, upgrade when your AI team delivers results.">

@push('styles')
<style>
  .pricing-hero{text-align:center;padding:80px 24px 48px;max-width:760px;margin:0 auto}
  .pricing-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;max-width:1240px;margin:0 auto;padding:0 24px 80px}
  .plan-card{background:var(--s1);border:1px solid var(--border);border-radius:16px;padding:24px 18px;display:flex;flex-direction:column;gap:0;position:relative}
  .plan-card.featured{border-color:var(--p);background:linear-gradient(160deg,rgba(108,92,231,.08),var(--s1))}
  .plan-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--p);color:#fff;font-size:11px;font-weight:700;padding:3px 12px;border-radius:100px;white-space:nowrap}
  .plan-name{font-family:'Syne',system-ui,sans-serif;font-size:18px;font-weight:700;margin-bottom:4px}
  .plan-price{font-family:'Syne',system-ui,sans-serif;font-size:32px;font-weight:800;margin:12px 0 4px}
  .plan-price span{font-size:15px;font-weight:400;color:var(--muted)}
  .plan-desc{font-size:12px;color:var(--muted);line-height:1.5;margin-bottom:16px;min-height:48px}
  .plan-cta{display:block;text-align:center;padding:10px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:20px;transition:all .2s}
  .cta-primary{background:var(--p);color:#fff}
  .cta-primary:hover{opacity:.85}
  .cta-outline{border:1px solid var(--border);color:var(--text)}
  .cta-outline:hover{border-color:var(--p);color:var(--p)}
  .plan-features{display:flex;flex-direction:column;gap:8px}
  .feat{display:flex;align-items:flex-start;gap:8px;font-size:12px;line-height:1.4}
  .feat-icon{flex-shrink:0;margin-top:1px}
  .feat-no{color:var(--muted)}
  .compare-section{max-width:1200px;margin:0 auto;padding:0 24px 80px}
  .compare-table{width:100%;border-collapse:collapse;font-size:13px}
  .compare-table th{padding:12px 16px;text-align:left;background:var(--s2);font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
  .compare-table td{padding:12px 16px;border-bottom:1px solid rgba(45,55,72,.5);vertical-align:middle}
  .compare-table tr:hover td{background:rgba(255,255,255,.02)}
  .check{color:var(--ac)}
  .dash{color:var(--muted)}
  @media(max-width:1024px){.pricing-grid{grid-template-columns:repeat(3,1fr)}}
  @media(max-width:640px){.pricing-grid{grid-template-columns:1fr 1fr}}
  @media(max-width:420px){.pricing-grid{grid-template-columns:1fr}}
</style>
@endpush

<div class="pricing-hero">
  <span class="badge badge-p" style="margin-bottom:16px">Simple pricing</span>
  <h1 style="font-size:clamp(32px,5vw,56px);font-weight:800;margin-bottom:16px">Pay for what you use.<br><span class="gradient-text">Upgrade when ready.</span></h1>
  <p style="font-size:16px;color:var(--muted)">Start free. Your trial (3 days · 50 credits) activates when you create your first website. No credit card needed.</p>
</div>

<div class="pricing-grid">

  <!-- Free -->
  <div class="plan-card">
    <div class="plan-name">Free</div>
    <div class="plan-price">$0<span>/mo</span></div>
    <div class="plan-desc">Manual tools, no AI. Perfect to explore the platform.</div>
    <a href="/sign-up" class="plan-cta cta-outline">Get started</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span>1 website</div>
      <div class="feat"><span class="feat-icon check">✓</span>CRM + Calendar</div>
      <div class="feat"><span class="feat-icon check">✓</span>Manual tools</div>
      <div class="feat feat-no"><span class="feat-icon">✗</span>No AI credits</div>
      <div class="feat feat-no"><span class="feat-icon">✗</span>No agents</div>
    </div>
  </div>

  <!-- Starter -->
  <div class="plan-card">
    <div class="plan-name">Starter</div>
    <div class="plan-price">$19<span>/mo</span></div>
    <div class="plan-desc">Manual tools + custom domain. For solo operators getting started.</div>
    <a href="/sign-up?plan=starter" class="plan-cta cta-outline">Get started</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span>1 website</div>
      <div class="feat"><span class="feat-icon check">✓</span>Custom domain</div>
      <div class="feat"><span class="feat-icon check">✓</span>3 team seats</div>
      <div class="feat"><span class="feat-icon check">✓</span>All manual tools</div>
      <div class="feat feat-no"><span class="feat-icon">✗</span>No AI credits</div>
    </div>
  </div>

  <!-- AI Lite -->
  <div class="plan-card">
    <div class="plan-name">AI Lite</div>
    <div class="plan-price">$49<span>/mo</span></div>
    <div class="plan-desc">Sarah researches your market automatically. First taste of AI.</div>
    <a href="/sign-up?plan=ai-lite" class="plan-cta cta-outline">Get started</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span>50 credits/mo</div>
      <div class="feat"><span class="feat-icon check">✓</span>Sarah's research pipeline</div>
      <div class="feat"><span class="feat-icon check">✓</span>1 website</div>
      <div class="feat"><span class="feat-icon check">✓</span>3 team seats</div>
      <div class="feat feat-no"><span class="feat-icon">✗</span>No content generation</div>
    </div>
  </div>

  <!-- Growth -->
  <div class="plan-card featured">
    <div class="plan-badge">Most popular</div>
    <div class="plan-name" style="color:var(--p)">Growth</div>
    <div class="plan-price">$99<span>/mo</span></div>
    <div class="plan-desc">Full AI access. Sarah + 2 specialists. Everything you need to grow.</div>
    <a href="/sign-up?plan=growth" class="plan-cta cta-primary">Start free trial</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span><strong>300 credits/mo</strong></div>
      <div class="feat"><span class="feat-icon check">✓</span>Sarah (DMM) included</div>
      <div class="feat"><span class="feat-icon check">✓</span>2 specialist agents</div>
      <div class="feat"><span class="feat-icon check">✓</span>3 websites</div>
      <div class="feat"><span class="feat-icon check">✓</span>Full content + images</div>
      <div class="feat"><span class="feat-icon check">✓</span>5 team seats</div>
    </div>
  </div>

  <!-- Pro -->
  <div class="plan-card">
    <div class="plan-name">Pro</div>
    <div class="plan-price">$199<span>/mo</span></div>
    <div class="plan-desc">Scale your AI team. Video generation + mobile app included.</div>
    <a href="/sign-up?plan=pro" class="plan-cta cta-outline">Get started</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span><strong>900 credits/mo</strong></div>
      <div class="feat"><span class="feat-icon check">✓</span>Sarah + 5 junior agents</div>
      <div class="feat"><span class="feat-icon check">✓</span>10 websites</div>
      <div class="feat"><span class="feat-icon check">✓</span>Video generation</div>
      <div class="feat"><span class="feat-icon check">✓</span>APP888 mobile</div>
      <div class="feat"><span class="feat-icon check">✓</span>10 team seats</div>
      <div class="feat"><span class="feat-icon check">✓</span>API access</div>
    </div>
  </div>

  <!-- Agency -->
  <div class="plan-card">
    <div class="plan-name" class="gradient-text-warm">Agency</div>
    <div class="plan-price">$399<span>/mo</span></div>
    <div class="plan-desc">Unlimited team, 10 senior agents, white-label. For agencies and enterprises.</div>
    <a href="/sign-up?plan=agency" class="plan-cta cta-outline">Get started</a>
    <div class="plan-features">
      <div class="feat"><span class="feat-icon check">✓</span><strong>2,500 credits/mo</strong></div>
      <div class="feat"><span class="feat-icon check">✓</span>Sarah + 10 senior agents</div>
      <div class="feat"><span class="feat-icon check">✓</span>50 websites</div>
      <div class="feat"><span class="feat-icon check">✓</span>Unlimited team seats</div>
      <div class="feat"><span class="feat-icon check">✓</span>White-label outputs</div>
      <div class="feat"><span class="feat-icon check">✓</span>Priority processing</div>
    </div>
  </div>

</div>

<!-- Feature comparison table -->
<div class="compare-section">
  <h2 style="font-size:28px;font-weight:700;margin-bottom:28px;font-family:'Syne',system-ui,sans-serif">Full comparison</h2>
  <div style="overflow-x:auto">
    <table class="compare-table">
      <thead>
        <tr>
          <th>Feature</th>
          <th>Free</th><th>Starter</th><th>AI Lite</th><th>Growth</th><th>Pro</th><th>Agency</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><strong>Monthly credits</strong></td><td class="dash">0</td><td class="dash">0</td><td>50</td><td>300</td><td>900</td><td>2,500</td></tr>
        <tr><td>Websites</td><td>1</td><td>1</td><td>1</td><td>3</td><td>10</td><td>50</td></tr>
        <tr><td>Team seats</td><td>1</td><td>3</td><td>3</td><td>5</td><td>10</td><td>Unlimited</td></tr>
        <tr><td>Manual tools (CRM, Calendar, Builder)</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>Sarah's research pipeline</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>AI content generation</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>AI image generation</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>AI video generation</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>Agent dispatch (Sarah + specialists)</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>APP888 mobile companion</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>API access</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>White-label outputs</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td></tr>
        <tr><td>Priority queue processing</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td class="check">✓</td><td class="check">✓</td></tr>
        <tr><td>Agent add-ons</td><td class="dash">—</td><td class="dash">—</td><td class="dash">—</td><td>+$20/agent</td><td>+$20/agent</td><td>+$10/agent</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- FAQ snippet -->
<div class="section section-center" style="padding-top:0">
  <h2 style="font-size:26px;font-weight:700;margin-bottom:32px">Questions?</h2>
  <div style="max-width:680px;margin:0 auto;text-align:left;display:flex;flex-direction:column;gap:16px">
    <div class="card"><strong>What's a credit?</strong><p style="font-size:14px;color:var(--muted);margin-top:8px">Credits are consumed when AI generates content, runs an audit, or executes a task. Reading, planning, and notifications are always free. 1 credit ≈ 1 SEO keyword analysis. Writing an article uses 10 credits.</p></div>
    <div class="card"><strong>When does my trial start?</strong><p style="font-size:14px;color:var(--muted);margin-top:8px">Your 3-day trial (50 credits, Growth-level access) starts the moment you create your first website — not when you sign up. No card needed.</p></div>
    <div class="card"><strong>Can I change plans?</strong><p style="font-size:14px;color:var(--muted);margin-top:8px">Yes, upgrade or downgrade anytime. Unused credits don't roll over between monthly periods.</p></div>
  </div>
  <div style="margin-top:28px"><a href="/faq" class="btn btn-outline">Read full FAQ</a></div>
</div>

</x-marketing-layout>
