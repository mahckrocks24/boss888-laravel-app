<x-marketing-layout title="Features — LevelUp" description="11 AI-powered marketing engines. All working together, all approval-gated, all in one platform.">

@push('styles')
<style>
  .features-hero{text-align:center;padding:80px 24px 56px;max-width:760px;margin:0 auto}
  .engine-section{max-width:1200px;margin:0 auto;padding:48px 24px;display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center}
  .engine-section.reverse{direction:rtl}
  .engine-section.reverse > *{direction:ltr}
  .engine-visual{background:var(--s2);border:1px solid var(--border);border-radius:20px;padding:28px;aspect-ratio:4/3;display:flex;flex-direction:column;justify-content:space-between}
  .tool-item{display:flex;align-items:center;gap:10px;background:var(--s1);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:8px}
  .tool-dot{width:8px;height:8px;border-radius:50%;background:var(--ac);flex-shrink:0}
  .tool-cost{margin-left:auto;font-size:11px;color:var(--muted)}
  .engine-badge{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-bottom:14px;padding:6px 14px;border-radius:100px;width:fit-content}
  .feature-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}
  .feature-row{display:flex;align-items:flex-start;gap:10px;font-size:14px}
  .feature-row-icon{color:var(--ac);flex-shrink:0;margin-top:2px}
  .divider-section{border-top:1px solid var(--border);margin:0 24px}
  @media(max-width:768px){.engine-section,.engine-section.reverse{grid-template-columns:1fr;direction:ltr}}
</style>
@endpush

<div class="features-hero">
  <span class="badge badge-p" style="margin-bottom:16px">Platform features</span>
  <h1 style="font-size:clamp(32px,5vw,54px);font-weight:800;margin-bottom:16px">11 engines. One platform.<br><span class="gradient-text">Infinite reach.</span></h1>
  <p style="font-size:16px;color:var(--muted)">Each engine is a complete marketing capability — and they all work together through your AI team.</p>
</div>

<!-- SEO -->
<div class="engine-section">
  <div>
    <div class="engine-badge" style="background:rgba(59,139,245,.1);color:var(--bl)">🔍 SEO Engine</div>
    <h2 style="font-size:clamp(24px,3vw,36px);font-weight:800;margin-bottom:14px">15 SEO tools, from audits to autonomous goals</h2>
    <p style="color:var(--muted);font-size:15px;margin-bottom:16px">James, Alex, Diana, Ryan, and Sofia handle every aspect of your search visibility — from technical fixes to content-driven ranking strategies.</p>
    <div class="feature-list">
      <div class="feature-row"><span class="feature-row-icon">✓</span>SERP analysis with AI insights (5 credits)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Deep technical site audit (15 credits)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Link opportunity discovery + one-click insertion</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Autonomous ranking goals (set and forget)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>AI-generated SEO reports + recommendations</div>
    </div>
  </div>
  <div class="engine-visual">
    <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">SEO Tools Available</div>
    <div class="tool-item"><div class="tool-dot"></div>SERP Analysis<div class="tool-cost">5 cr</div></div>
    <div class="tool-item"><div class="tool-dot"></div>Deep Audit<div class="tool-cost">15 cr</div></div>
    <div class="tool-item"><div class="tool-dot"></div>AI Report<div class="tool-cost">10 cr</div></div>
    <div class="tool-item"><div class="tool-dot"></div>Link Suggestions<div class="tool-cost">3 cr</div></div>
    <div class="tool-item"><div class="tool-dot"></div>Outbound Links<div class="tool-cost">2 cr</div></div>
    <div style="font-size:12px;color:var(--muted);text-align:right">+ 10 more tools</div>
  </div>
</div>

<div class="divider-section"></div>

<!-- CONTENT -->
<div class="engine-section reverse">
  <div>
    <div class="engine-badge" style="background:rgba(124,58,237,.1);color:var(--pu)">✍️ Write Engine</div>
    <h2 style="font-size:clamp(24px,3vw,36px);font-weight:800;margin-bottom:14px">Content that sounds like you, ranks like a pro</h2>
    <p style="color:var(--muted);font-size:15px;margin-bottom:16px">Priya plans, Leo writes, Nora scripts. Every piece of content goes through your brand kit before generation.</p>
    <div class="feature-list">
      <div class="feature-row"><span class="feature-row-icon">✓</span>Full article generation with SEO optimization</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Content briefs, outlines, and editorial calendar</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Improve existing drafts with AI feedback</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Meta title + description generation</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Version history — never lose a draft</div>
    </div>
  </div>
  <div class="engine-visual" style="background:linear-gradient(160deg,rgba(124,58,237,.05),var(--s2))">
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Content pipeline</div>
    <div class="tool-item"><div class="tool-dot" style="background:var(--pu)"></div>Brief generation<div class="tool-cost">2 cr</div></div>
    <div class="tool-item"><div class="tool-dot" style="background:var(--pu)"></div>Outline (AI)<div class="tool-cost">3 cr</div></div>
    <div class="tool-item"><div class="tool-dot" style="background:var(--pu)"></div>Full article write<div class="tool-cost">10 cr</div></div>
    <div class="tool-item"><div class="tool-dot" style="background:var(--pu)"></div>Improve draft<div class="tool-cost">5 cr</div></div>
    <div class="tool-item"><div class="tool-dot" style="background:var(--pu)"></div>SEO meta generation<div class="tool-cost">2 cr</div></div>
  </div>
</div>

<div class="divider-section"></div>

<!-- CREATIVE -->
<div class="engine-section">
  <div>
    <div class="engine-badge" style="background:rgba(108,92,231,.1);color:var(--p)">🎨 Creative Engine</div>
    <h2 style="font-size:clamp(24px,3vw,36px);font-weight:800;margin-bottom:14px">Images, videos, and brand consistency — automatically</h2>
    <p style="color:var(--muted);font-size:15px;margin-bottom:16px">Every creative output runs through your brand kit and creative blueprint before generation. "LevelUp AI" powers all visuals — no provider names leaked to users.</p>
    <div class="feature-list">
      <div class="feature-row"><span class="feature-row-icon">✓</span>AI image generation (gpt-image-1)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>AI video generation — MiniMax Hailuo-02 (Pro+)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Brand kit: colors, fonts, voice, visual style</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Creative Intelligence Memory System (CIMS)</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Blueprint strategy injected into every generation</div>
    </div>
  </div>
  <div class="engine-visual" style="background:linear-gradient(160deg,rgba(108,92,231,.06),var(--s2))">
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Creative Blueprint Flow</div>
    <div style="display:flex;flex-direction:column;gap:6px;font-size:12px">
      <div style="background:rgba(108,92,231,.1);border:1px solid rgba(108,92,231,.2);border-radius:8px;padding:8px 12px">1. Brand kit loaded (colors, voice, style)</div>
      <div style="text-align:center;color:var(--muted)">↓</div>
      <div style="background:rgba(108,92,231,.1);border:1px solid rgba(108,92,231,.2);border-radius:8px;padding:8px 12px">2. Blueprint strategy generated</div>
      <div style="text-align:center;color:var(--muted)">↓</div>
      <div style="background:rgba(0,229,168,.1);border:1px solid rgba(0,229,168,.2);border-radius:8px;padding:8px 12px">3. Image / video / copy generated</div>
      <div style="text-align:center;color:var(--muted)">↓</div>
      <div style="background:rgba(0,229,168,.1);border:1px solid rgba(0,229,168,.2);border-radius:8px;padding:8px 12px">4. White-label pass → "LevelUp AI"</div>
    </div>
  </div>
</div>

<div class="divider-section"></div>

<!-- SOCIAL + MARKETING -->
<div class="engine-section reverse">
  <div>
    <div class="engine-badge" style="background:rgba(236,72,153,.1);color:#EC4899">📱 Social + Marketing</div>
    <h2 style="font-size:clamp(24px,3vw,36px);font-weight:800;margin-bottom:14px">Campaigns out. Engagement up. Leads in.</h2>
    <p style="color:var(--muted);font-size:15px;margin-bottom:16px">Marcus, Zara, Tyler, Maya, and Kai handle your social presence and email marketing end-to-end.</p>
    <div class="feature-list">
      <div class="feature-row"><span class="feature-row-icon">✓</span>Native publishing to Facebook &amp; Instagram. Drafts and content for LinkedIn, TikTok, X to post manually.</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>AI post generation with platform-specific rules</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Visual email campaign builder with AI-assisted copy</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Campaign analytics + open/click tracking</div>
      <div class="feature-row"><span class="feature-row-icon">✓</span>Everything requires your approval before publish/send</div>
    </div>
  </div>
  <div class="engine-visual">
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Platform integrations</div>
    @foreach(['Facebook · Native posting + Pages', 'Instagram · Native posting + Reels content', 'LinkedIn · Draft + script generation', 'TikTok · Draft + script generation', 'Twitter/X · Draft generation', 'Email · Campaign builder + tracking'] as $p)
    <div class="tool-item" style="margin-bottom:6px"><div class="tool-dot" style="background:#EC4899"></div>{{ $p }}</div>
    @endforeach
  </div>
</div>

<!-- Final CTA -->
<div class="section section-center" style="padding-top:24px">
  <div style="background:linear-gradient(135deg,rgba(108,92,231,.1),rgba(0,229,168,.05));border:1px solid rgba(108,92,231,.2);border-radius:24px;padding:64px 40px">
    <h2 style="font-size:clamp(26px,4vw,42px);margin-bottom:14px">All 11 engines. All your agents.<br>One monthly subscription.</h2>
    <p style="color:var(--muted);font-size:16px;max-width:480px;margin:0 auto 32px">Start with a free trial and see how much your AI team can do in 3 days.</p>
    <a href="/sign-up" class="btn btn-primary btn-lg">Start free trial</a>
    <div style="margin-top:14px;font-size:13px;color:var(--muted)">No credit card · 50 credits · Growth access</div>
  </div>
</div>

</x-marketing-layout>
