<x-marketing-layout title="FAQ — LevelUp" description="Everything you need to know about LevelUp, credits, agents, and how the platform works.">

@push('styles')
<style>
  .faq-hero{text-align:center;padding:80px 24px 56px;max-width:640px;margin:0 auto}
  .faq-grid{max-width:820px;margin:0 auto;padding:0 24px 80px;display:flex;flex-direction:column;gap:12px}
  .faq-item{background:var(--s1);border:1px solid var(--border);border-radius:14px;overflow:hidden}
  .faq-q{padding:20px 24px;font-size:16px;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none}
  .faq-q:hover{color:var(--p)}
  .faq-icon{font-size:18px;color:var(--muted);transition:transform .2s;flex-shrink:0}
  .faq-a{padding:0 24px;font-size:14px;color:var(--muted);line-height:1.8;max-height:0;overflow:hidden;transition:max-height .3s,padding .3s}
  .faq-item.open .faq-a{max-height:400px;padding-bottom:20px}
  .faq-item.open .faq-icon{transform:rotate(45deg)}
  .faq-section-title{font-family:'Syne',system-ui,sans-serif;font-size:18px;font-weight:700;padding:8px 0;color:var(--muted);border-bottom:1px solid var(--border);margin-bottom:12px}
</style>
@endpush

<div class="faq-hero">
  <span class="badge badge-p" style="margin-bottom:16px">FAQ</span>
  <h1 style="font-size:clamp(32px,5vw,52px);font-weight:800;margin-bottom:16px">Questions, <span class="gradient-text">answered</span></h1>
  <p style="font-size:16px;color:var(--muted)">Everything you need to know before you start.</p>
</div>

<div class="faq-grid">

  <div class="faq-section-title">Getting started</div>

  @foreach([
    ['What exactly is LevelUp?', 'LevelUp is an AI marketing operating system for small and medium businesses. Instead of hiring a marketing agency, you get a dedicated team of AI specialists — led by Sarah, your Digital Marketing Manager — who research your market, build a strategy, create content, and execute campaigns. You stay in control: nothing goes live without your approval.'],
    ['Who is it for?', 'LevelUp is built for owner-operators and SMBs who need agency-quality marketing but can\'t afford a full agency. If you\'ve ever said "I know I need to do marketing, I just don\'t have the time or expertise" — LevelUp is for you. It\'s not for agencies or professional marketers.'],
    ['How does the trial work?', 'Your 3-day trial (50 credits, Growth-level access) activates when you create your first website — not when you sign up. This means you can explore the platform, set up your workspace, and meet the team before your trial clock starts. No credit card required.'],
    ['Do I need any marketing knowledge?', 'No. Sarah will explain what she\'s doing, why, and what it costs in credits before she does it. Every recommendation comes with plain-language explanations. You just need to know your business — Sarah knows the marketing.'],
  ] as $faq)
  <div class="faq-item" onclick="toggle(this)">
    <div class="faq-q">{{ $faq[0] }}<span class="faq-icon">+</span></div>
    <div class="faq-a">{{ $faq[1] }}</div>
  </div>
  @endforeach

  <div class="faq-section-title" style="margin-top:12px">Credits & pricing</div>

  @foreach([
    ['What is a credit?', 'Credits are consumed when AI performs work: generating content, running an analysis, creating images, or executing a task. Reading data, navigation, and notifications are always free. As a guide: 1 credit ≈ a keyword SERP analysis; 10 credits ≈ a full blog article; 25 credits ≈ a video generation. The cost is always shown to you before work begins.'],
    ['Do unused credits roll over?', 'No. Credits reset at the start of each monthly billing period. However, any credits added via add-ons are separate and persist until used.'],
    ['What happens when I run out of credits?', 'AI actions will be paused until your next billing cycle or until you add more credits. Manual tools (CRM, calendar, forms) remain fully available — you\'re never locked out of your data.'],
    ['Can I add more credits if I need them?', 'Yes. Agent add-ons are available on Growth ($20/agent/month) and Pro ($20/agent/month) and Agency ($10/agent/month) plans. Contact us if you need a custom credit package.'],
    ['Can I change plans?', 'Yes, anytime. Upgrades take effect immediately. Downgrades take effect at the next billing cycle. Unused credits from your current period are not refunded on downgrade.'],
  ] as $faq)
  <div class="faq-item" onclick="toggle(this)">
    <div class="faq-q">{{ $faq[0] }}<span class="faq-icon">+</span></div>
    <div class="faq-a">{{ $faq[1] }}</div>
  </div>
  @endforeach

  <div class="faq-section-title" style="margin-top:12px">Agents & AI</div>

  @foreach([
    ['Will the AI ever publish or send anything without my permission?', 'Never. Every action that creates, publishes, sends, or changes something external requires your explicit approval. Sarah presents the plan and cost, you approve. Agents execute only within the approved scope. This is a hard architectural guarantee — not a setting you can accidentally turn off.'],
    ['How does Sarah know what to do?', 'When you onboard (industry, goal, location, website URL), Sarah automatically runs research: keyword analysis, competitor audit, site health check. She then calls a strategy meeting with your team, where each specialist contributes their expert analysis. The result is a 30/60/90-day plan that she presents to you for approval.'],
    ['Can I choose which agents work on my account?', 'On Growth and above, you select which specialists join your team (up to your plan\'s agent limit). Sarah is always included and cannot be removed. You can switch specialists at any time.'],
    ['Are the agents real AI or just automated templates?', 'Real AI. Each agent\'s contribution to a strategy meeting is a genuine LLM call with that agent\'s specific system prompt, domain knowledge, and your workspace context. No pre-written templates.'],
    ['What AI models power LevelUp?', 'LevelUp uses DeepSeek (primary text), OpenAI GPT-4o (vision + reasoning), gpt-image-1 (images), and MiniMax Hailuo-02 (video). All outputs are presented as "LevelUp AI" — no provider names are shown to you.'],
  ] as $faq)
  <div class="faq-item" onclick="toggle(this)">
    <div class="faq-q">{{ $faq[0] }}<span class="faq-icon">+</span></div>
    <div class="faq-a">{{ $faq[1] }}</div>
  </div>
  @endforeach

  <div class="faq-section-title" style="margin-top:12px">Platform & data</div>

  @foreach([
    ['Is my data safe?', 'Yes. Your workspace data is stored on encrypted Digital Ocean infrastructure in Frankfurt. Your API keys are encrypted at rest using Laravel\'s AES-256 encryption. We do not sell your data. GDPR compliance is built in.'],
    ['Which countries does LevelUp operate in?', 'LevelUp is built for MENA (UAE, KSA, Qatar, Kuwait, Egypt), DACH (Germany, Austria, Switzerland), and SEA (Philippines, Australia). It works globally, but these markets have specific localization built in.'],
    ['Can I use my own domain?', 'Yes, on Starter and above. Free plan gets a levelupgrowth.io subdomain.'],
    ['Is there a mobile app?', 'Yes — APP888, our executive companion app, is available on Pro and Agency plans. It gives you an executive briefing, agent conversations, task approvals, and campaign status from your phone.'],
  ] as $faq)
  <div class="faq-item" onclick="toggle(this)">
    <div class="faq-q">{{ $faq[0] }}<span class="faq-icon">+</span></div>
    <div class="faq-a">{{ $faq[1] }}</div>
  </div>
  @endforeach

</div>

<div class="section section-center" style="padding-top:0">
  <p style="color:var(--muted);margin-bottom:20px">Still have questions? We're happy to help.</p>
  <a href="mailto:hello@levelupgrowth.io" class="btn btn-outline">Contact us</a>
  <span style="display:inline-block;margin:0 12px;color:var(--muted)">or</span>
  <a href="/sign-up" class="btn btn-primary">Start your free trial</a>
</div>

@push('scripts')
<script>
function toggle(el) {
  el.classList.toggle('open');
}
</script>
@endpush

</x-marketing-layout>
