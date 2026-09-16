<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Security and data';
$page['description'] = 'How LevelUpGrowth handles your data: ownership and export, workspace isolation, the approval gate, a delivery ledger for every email, TLS everywhere, and what we do not claim.';
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Security and data</p>
    <h1>What we do with your data, stated plainly.</h1>
    <p class="lede">No badges we have not earned. Everything on this page describes how the platform is built today.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-2">
    <div class="card"><?= icon('shield', 22) ?><h3>Ownership and export</h3><p>Your site can be exported as files and your contacts and leads as a CSV at any time from your account. Leaving is a download, not a negotiation.</p></div>
    <div class="card"><?= icon('layers', 22) ?><h3>Workspace isolation</h3><p>Every business is a workspace. Sites, contacts, content, credits and the workforce's memory are scoped to it; agencies see only the workspaces they manage.</p></div>
    <div class="card"><?= icon('users', 22) ?><h3>The approval gate</h3><p>The workforce cannot publish, post or send on its own. Finished work is reviewed by the manager agent for alignment, metadata and provenance, then waits for you. Rejected work returns to draft and is excluded from bulk actions.</p></div>
    <div class="card"><?= icon('mail', 22) ?><h3>A ledger for every email</h3><p>Every message the platform sends is recorded with its purpose, recipient and provider outcome. Bounces and suppressions are visible, and marketing email is not part of the product.</p></div>
    <div class="card"><?= icon('globe', 22) ?><h3>TLS everywhere</h3><p>Your account, your published sites and every subdomain are served over HTTPS with automatically provisioned certificates.</p></div>
    <div class="card"><?= icon('bot', 22) ?><h3>Agents never hold your credentials</h3><p>The workforce works inside your account through the platform's own services. It does not receive your passwords and does not log in to third-party tools on your behalf.</p></div>
  </div>
</section>
<section class="section-tight">
  <div class="container narrow prose">
    <h2>AI providers and your data</h2>
    <p>The workforce uses third-party language and image models to do its work. Your briefs and content are sent to those providers to produce the output you asked for, under their business terms, and are not used by us to train models. The <a href="/next/legal/ai-disclosure/">AI disclosure</a> lists the current providers.</p>
    <h2>Incidents</h2>
    <p>If something goes wrong with your data, you hear it from us first: what happened, what was affected, what we did, and what changes. Write to hello@levelupgrowth.io to report a security concern.</p>
    <h2>What we do not claim</h2>
    <p>We do not hold a SOC 2 report or an ISO certification, and we do not print uptime percentages we have not measured over time. The <a href="/next/status/">status page</a> shows live checks only. When we earn a certification it will appear here with its date.</p>
  </div>
</section>
