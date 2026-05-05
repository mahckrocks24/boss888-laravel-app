<x-marketing-layout title="Get Started — LevelUp" description="Start your free 3-day trial. No credit card needed.">

@push('styles')
<style>
  .signup-layout{max-width:1100px;margin:0 auto;padding:80px 24px;display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:start}
  .signup-left h1{font-size:clamp(28px,4vw,44px);font-weight:800;margin-bottom:16px}
  .signup-left p{font-size:15px;color:var(--muted);margin-bottom:32px;line-height:1.7}
  .trial-card{background:linear-gradient(135deg,rgba(108,92,231,.08),rgba(0,229,168,.04));border:1px solid rgba(108,92,231,.2);border-radius:14px;padding:20px;margin-bottom:24px}
  .trial-row{display:flex;align-items:center;gap:12px;margin-bottom:10px}
  .trial-row:last-child{margin-bottom:0}
  .trial-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
  .trial-text{font-size:13px;line-height:1.4}
  .trial-text strong{display:block;font-weight:600}
  .trial-text span{color:var(--muted);font-size:12px}
  .form-card{background:var(--s1);border:1px solid var(--border);border-radius:20px;padding:36px}
  .form-card h2{font-size:22px;font-weight:700;margin-bottom:8px}
  .form-card .sub{font-size:13px;color:var(--muted);margin-bottom:28px}
  label{display:block;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
  input,select{width:100%;background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;outline:none;margin-bottom:16px;font-family:inherit}
  input:focus,select:focus{border-color:var(--p)}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .submit-btn{width:100%;background:var(--p);color:#fff;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer;transition:opacity .2s;font-family:inherit}
  .submit-btn:hover{opacity:.85}
  .submit-btn:disabled{opacity:.5;cursor:not-allowed}
  .terms{font-size:12px;color:var(--muted);text-align:center;margin-top:14px;line-height:1.6}
  .terms a{color:var(--p)}
  .error-msg{background:rgba(248,113,113,.1);border:1px solid var(--rd);border-radius:8px;padding:10px 14px;color:var(--rd);font-size:13px;margin-bottom:16px;display:none}
  .success-msg{background:rgba(0,229,168,.1);border:1px solid var(--ac);border-radius:8px;padding:10px 14px;color:var(--ac);font-size:13px;margin-bottom:16px;display:none}
  .divider{display:flex;align-items:center;gap:12px;margin-bottom:16px}
  .divider-line{flex:1;height:1px;background:var(--border)}
  .divider-text{font-size:12px;color:var(--muted)}
  @media(max-width:768px){.signup-layout{grid-template-columns:1fr;gap:40px}}
</style>
@endpush

<div class="signup-layout">

  <div class="signup-left">
    <span class="badge badge-ac" style="margin-bottom:20px">Free 3-day trial</span>
    <h1>Start building with your <span class="gradient-text">AI marketing team</span></h1>
    <p>No credit card needed. Your trial starts when you create your first website — so you have time to explore before the clock begins.</p>

    <div class="trial-card">
      <div class="trial-row">
        <div class="trial-icon" style="background:rgba(0,229,168,.12)">⏱️</div>
        <div class="trial-text"><strong>3-day free trial</strong><span>Activates on first website creation, not on signup</span></div>
      </div>
      <div class="trial-row">
        <div class="trial-icon" style="background:rgba(108,92,231,.12)">💎</div>
        <div class="trial-text"><strong>50 credits included</strong><span>Enough to run research, write articles, and test your team</span></div>
      </div>
      <div class="trial-row">
        <div class="trial-icon" style="background:rgba(245,158,11,.12)">👩‍💼</div>
        <div class="trial-text"><strong>Growth plan access</strong><span>Sarah + 2 specialist agents, full AI capabilities</span></div>
      </div>
      <div class="trial-row">
        <div class="trial-icon" style="background:rgba(248,113,113,.12)">🚫</div>
        <div class="trial-text"><strong>No credit card required</strong><span>Add a payment method only when you're ready to upgrade</span></div>
      </div>
    </div>

    <div style="font-size:13px;color:var(--muted)">Already have an account? <a href="/app" style="color:var(--p)">Sign in →</a></div>
  </div>

  <div class="form-card">
    <h2>Create your account</h2>
    <div class="sub">Free forever on the Free plan. Trial starts on first website.</div>

    <div class="error-msg" id="error"></div>
    <div class="success-msg" id="success"></div>

    <div class="form-row">
      <div><label>First name</label><input type="text" id="fname" placeholder="Sarah"></div>
      <div><label>Last name</label><input type="text" id="lname" placeholder="Smith"></div>
    </div>
    <label>Work email</label>
    <input type="email" id="email" placeholder="you@yourcompany.com">
    <label>Password</label>
    <input type="password" id="pass" placeholder="Min 8 characters">

    <div class="divider"><div class="divider-line"></div><div class="divider-text">About your business</div><div class="divider-line"></div></div>

    <label>Business / company name</label>
    <input type="text" id="biz" placeholder="Acme Restaurant Dubai">

    <label>Industry</label>
    <select id="industry">
      <option value="">Select your industry</option>
      <option>Restaurant & Food</option><option>Retail & E-commerce</option><option>Real Estate</option>
      <option>Healthcare & Wellness</option><option>Professional Services</option><option>Education</option>
      <option>Technology & SaaS</option><option>Beauty & Fashion</option><option>Construction</option>
      <option>Hospitality & Tourism</option><option>Finance & Accounting</option><option>Other</option>
    </select>

    <label>Primary goal</label>
    <select id="goal">
      <option value="">What do you want to achieve?</option>
      <option value="leads">Generate more leads</option><option value="brand">Build brand awareness</option>
      <option value="ecommerce">Drive online sales</option><option value="portfolio">Showcase my work</option>
    </select>

    <button class="submit-btn" id="submit-btn" onclick="register()">Create account — start free trial</button>
    <div class="terms">By signing up you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>. No spam. Ever.</div>
  </div>

</div>

@push('scripts')
<script>
  // Pre-fill plan from URL param
  const urlPlan = new URLSearchParams(window.location.search).get('plan');

  async function register() {
    const err = document.getElementById('error');
    const suc = document.getElementById('success');
    const btn = document.getElementById('submit-btn');
    err.style.display = 'none';

    const name = (document.getElementById('fname').value + ' ' + document.getElementById('lname').value).trim();
    const email = document.getElementById('email').value.trim();
    const pass = document.getElementById('pass').value;
    const biz = document.getElementById('biz').value.trim();
    const industry = document.getElementById('industry').value;
    const goal = document.getElementById('goal').value;

    if (!name || !email || !pass || !biz) {
      err.textContent = 'Please fill in all required fields.';
      err.style.display = 'block'; return;
    }
    if (pass.length < 8) {
      err.textContent = 'Password must be at least 8 characters.';
      err.style.display = 'block'; return;
    }

    btn.disabled = true; btn.textContent = 'Creating your account…';

    try {
      const res = await fetch('/api/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, email, password: pass, business_name: biz, industry, goal }),
      });
      const data = await res.json();

      if (!res.ok) throw new Error(data.message || data.error || 'Registration failed');

      // Store token
      if (data.access_token) {
        localStorage.setItem('lu_token', data.access_token);
        localStorage.setItem('lu_user', JSON.stringify(data.user));
      }

      suc.textContent = 'Account created! Redirecting you to the platform…';
      suc.style.display = 'block';
      setTimeout(() => window.location.href = '/app', 1500);
    } catch(e) {
      err.textContent = e.message;
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Create account — start free trial';
    }
  }

  document.addEventListener('keydown', e => { if (e.key === 'Enter') register(); });
</script>
@endpush

</x-marketing-layout>
