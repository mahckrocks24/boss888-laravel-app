<?php
// RFC-0026 P7: the affiliate terms (Owner 2026-10-05). Written to the programme's rules as the platform enforces them
// (app/Core/Partners). The legal entity and governing law are Owner-held facts and render as "to be confirmed".
$__owner = '<mark class="owner" data-key="LEGAL_ENTITY">to be confirmed before launch</mark>';
$pages[] = ['slug' => 'affiliates', 'title' => 'Affiliate Program Terms', 'source' => 'legal-data.php (RFC-0026)', 'body' => <<<HTML
<p>These terms apply when you join the LevelUpGrowth Affiliate Program and share your affiliate codes for LevelUpGrowth. They sit alongside our <a href="/next/legal/terms/">Terms of Service</a> and <a href="/next/legal/privacy/">Privacy Policy</a>. The program is run by {$__owner}.</p>

<h2>1. Joining</h2>
<p>Anyone aged 18 or over may apply. We read every application and may approve or decline it without giving a reason. You join as an independent affiliate, not as an employee, agent or representative of LevelUpGrowth, and you may not make promises or accept terms on our behalf.</p>

<h2>2. What you earn</h2>
<p>Each payment made by a business you referred carries a share you split between that business's discount and your commission:</p>
<ul>
<li><strong>Monthly plans:</strong> 20% of the plan price, on the business's first 6 monthly payments.</li>
<li><strong>Yearly plans:</strong> 15% of the yearly price, on the first yearly payment.</li>
<li><strong>New domain registrations:</strong> 10% of the registration price, for registrations in the business's first 6 months.</li>
</ul>
<p>A code with no discount gives you the full share. A code can instead give the business part of the share as a discount, and your commission is what remains. Shares are worked out on the price before any discount and before tax. We may raise an affiliate's share by written agreement.</p>
<p>The following do not earn: free trials until their first payment, credit top-ups, domain renewals, promotional domain prices below our cost, payments that are refunded or disputed, and any account, business or workspace that you own, manage, belong to or control.</p>

<h2>3. Who counts as your referral</h2>
<p>A business is yours when it signs up with one of your codes, or enters one of your codes in its account before its first payment. The program uses codes only: there are no tracking links and no tracking cookies. If a business enters more than one code before its first payment, the last code entered counts. Once a business makes its first payment, the referral is fixed. Codes are for new customers: a business that has already paid us cannot be added later.</p>

<h2>4. Holding, payment and tax</h2>
<p>Each commission is held for 30 days after the payment it comes from, then becomes ready to pay. We pay ready commissions monthly, on or around the 15th, once your ready balance is at least US$50; smaller balances roll over. We pay in US dollars to the payout account you set up in your affiliate portal. You must keep your payout details accurate and complete any identity and tax checks we or our payment provider require; payouts may wait until they are complete. You are responsible for your own taxes.</p>

<h2>5. Refunds, disputes and corrections</h2>
<p>If a payment that earned you a commission is refunded or disputed, we reverse the matching commission in proportion. If it was already paid, the amount is deducted from your future payouts. We may also correct commissions created by error, and hold or withdraw commissions while we review suspected abuse.</p>

<h2>6. How you promote LevelUpGrowth</h2>
<ul>
<li>Say clearly, wherever you share a code, that it is an affiliate code and that you may earn from it, as the advertising rules where you and your audience live require.</li>
<li>Describe LevelUpGrowth truthfully and only promise what our public pages say. Do not offer discounts, guarantees, features or support that we do not provide.</li>
<li>Do not send unsolicited messages, post in places that forbid promotion, or use fake reviews, fake accounts or misleading pages.</li>
<li>Do not bid on LevelUpGrowth or its product names in paid search, or register domains, accounts or handles that could be mistaken for ours.</li>
<li>Do not publish your codes on coupon or voucher sites, use automated sign-ups, offer incentives to sign up without interest, or use any other way to create referrals that did not genuinely come from you.</li>
<li>Use our name and logo only as provided in your affiliate portal, and do not suggest that we endorse you beyond this program.</li>
</ul>

<h2>7. Your codes</h2>
<p>Codes must be 4 to 20 letters or numbers and may not contain our names or other reserved words. We may pause or remove a code that is offensive, misleading or confusing. Changing a code affects new customers only; businesses that already joined keep the terms they joined on.</p>

<h2>8. What you see about your referrals</h2>
<p>Your portal shows when each business joined, how it found you, its plan, its payment count and your earnings, with its email partly hidden. You must not try to identify, contact or market to businesses through information from the portal, and you must handle any personal data you hold lawfully.</p>

<h2>9. Changes, suspension and ending</h2>
<p>We may change these terms or the program by giving you at least 30 days' notice by email or in your portal; changes do not reduce commissions already earned. Either of us may end your participation at any time. We may suspend you at once, pause your codes and hold commissions under review if we reasonably believe you have broken these terms. On ending, commissions already earned under these terms are paid on the normal schedule, except those connected to a breach.</p>

<h2>10. Liability and law</h2>
<p>The program is provided as is. To the extent the law allows, our total liability to you under these terms is limited to the commissions owed to you in the 12 months before the claim. These terms are governed by the laws of {$__owner}.</p>

<h2>11. Contact</h2>
<p>Questions about the program or a payout: <a href="/next/contact/?topic=affiliates">contact us</a> and choose Affiliates.</p>
HTML];
