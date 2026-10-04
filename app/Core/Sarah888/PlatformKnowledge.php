<?php

namespace App\Core\Sarah888;

/**
 * LUG-KB (2026-09-04, Owner): the platform knowledge base Sarah uses to answer "how do I…",
 * "where is…", "what is this screen" (incl. from a screenshot), and general product/FAQ questions.
 *
 * Grounded in the real app: sidebar sections (Workspace/Publishing/Engines/Hosting/Data/Team), the two
 * visibility modes (Basic / Advanced), the specialist agents, and the credit/plan model. Keep this
 * FACTUAL — it is injected into Sarah's context, so anything invented here becomes a confident wrong
 * answer. Update it when the UI changes.
 */
class PlatformKnowledge
{
    /**
     * Is this turn a platform / UI / how-to / where-is / FAQ / screenshot question? Only then do we inject
     * the guide, so normal task turns are not bloated (an always-on 4KB guide overran the runtime budget).
     */
    public static function isPlatformQuestion(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') { return false; }
        return (bool) preg_match('/\b(how (do|can|would) i|how to|where (is|are|do|can|would)|what (is|does|are)|what am i looking at|can i|is there|do you (have|support)|advanced mode|basic mode|turn on|switch (on|to)|how do i (find|get to|open|use)|navigate|which (tab|menu|section|screen|page)|find (the|my)|guide me|walk me (through)?|help me (find|navigate|use)|this (screen|page|area|section)|screenshot|credits? (work|cost)|how much (does|is)|pricing|upgrade|which plan|what plan|what can (you|i|this|the platform|it)\b|what (do you|does (it|this|the platform)) do|capabilit|features?)\b/', $t);
    }

    /** The compact guide injected into Sarah's context (only for platform questions — see isPlatformQuestion). */
    public static function guide(): string
    {
        // ARTHUR DELEGATION (2026-09-06): the live builder capability list is appended so Sarah answers
        // "can you add a booking page?" from facts, and always delegates to Arthur.
        return self::guideText() . "\n\nWEBSITE ADDITIONS — HOW IT WORKS\nSarah never builds. When a customer wants a page, a section/row, or a copy change on their website, Sarah asks Arthur (tool builder.ask_arthur). Arthur uses the built-in templates, keeps the site's colour palette and fonts, writes the copy for the business, links new pages in the menu, and quotes the credits before/after.\n" . \App\Engines\Builder\Support\BuilderCapabilities::describe();
    }

    private static function guideText(): string
    {
        return <<<'KB'
LEVELUPGROWTH — PLATFORM GUIDE (use this to answer how-to / where-is / what-is-this / FAQ questions, and to interpret screenshots of the app). Only state what is here; if the user's screen isn't covered, say what you can see and offer to check.

WHAT LUG IS
An all-in-one AI growth platform. Customers build & run websites, publish blog/SEO content, generate images & video, manage leads (CRM) and email, and track performance — with an AI team (you, Sarah, plus specialist agents) doing the work. The app lives at /app (the "workspace").

VISIBILITY MODES
- BASIC: a simplified surface for everyday tasks.
- ADVANCED: reveals every tool and setting. The customer toggles it with the "Advanced" link in the left sidebar. If someone can't find a feature, tell them to switch on "Advanced".

LEFT SIDEBAR — SECTIONS & WHERE THINGS LIVE
• Workspace: Sarah (this chat), Agents (your AI team), Reports/Insights (analytics & performance).
• Publishing: Website / Websites / Website Wizard (create & manage sites), Builder (edit pages — sections, copy, images), Content/Write (blog articles), SEO (keywords, rankings, audits, meta, internal links), AI Studio (generate images & video by prompt), Calendar (scheduling).
• Engines: CRM (leads, deals, follow-ups), Email (email accounts & campaigns), Social (limited at launch), Chatbot (the website chatbot).
• Hosting: Domains (connect/buy domains), Hosting, Infrastructure (business email & hosting stack).
• Data: Reports/Insights.
• Team: Team members, Billing (plan & credits), Account/Settings.

YOUR AI TEAM (agents)
- Sarah (you): the growth manager — the customer talks to you; you plan and delegate.
- James: SEO / search visibility (keywords, rankings, audits, meta, internal links).
- Priya: writing / blog content.
- Elena: customers / CRM.
- Marcus: social.
- Alex: site health / technical.
- Arthur: website building & editing.
- Studio: images & video generation.

CREDITS & PLANS
- AI actions cost credits: an article = 2, a social post draft = 4 (+2 to publish it), an image = 4 (mini 2, high quality 21), a video = 28 (10 seconds = 52), a design = 5, editing an image = 6. Chat costs 1 credit per 5 messages. Publishing an article is free. Website work by Arthur: first draft 10, a page 5, a section 2, a change 1; the customer's own edits in the editor are free.
- Every AI plan (AI Lite and up) and the free trial include Sarah, the team, images and video. Free and Starter have no AI. If a customer can't use an AI feature, it's a plan limit — point them to Billing/upgrade.
- Credits show in the workspace and under Billing. Credits come with the plan each month; there is no separate top-up — more credits come from a bigger plan or the next monthly renewal.

COMMON HOW-TOs (walk the customer through these)
- Write a blog post: tell Sarah the topic (or "write about X") → Priya drafts it → review → say "publish" to make it live. Failed/other tasks never block publishing.
- Build a website: describe the business (name, what it offers, any colours) → Arthur builds a multi-page site (review-gated, ~10 credits) → review → publish.
- Generate an image: describe it ("an image of …") → confirm → it generates and shows inline in this chat; also saved under Results. AI can't render exact logos/brand text — those come out garbled, so it warns and offers a plain version.
- Edit / refine an image: after one is made, say "make it hyperrealistic / a cartoon version / more vibrant" — it regenerates that image with the change.
- Improve SEO: ask about keywords, rankings, a site audit, meta descriptions, or internal links → James handles it.
- Add / manage leads: use CRM (Elena) — add a lead, log a follow-up, move a deal stage.
- Connect a domain: Hosting → Domains.

FAQ
- "Where are my generated images?" → In AI Studio → Results, and they now appear inline here in chat when Sarah makes them.
- "How do I publish?" → Just tell Sarah "publish" (she'll confirm what goes live), or Advanced → Content/Write.
- "Why doesn't the image show the exact logo / brand?" → AI image models can't reproduce specific logos or exact text; they garble it. For an exact logo it must be overlaid as a real graphic.
- "How do credits work?" → Each AI action costs credits (see above); publishing an article is free; credits come with the plan each month (Billing).
- "I can't find a feature." → Turn on "Advanced" mode (sidebar), which reveals all tools.
- "Can't generate video." → Video needs a Pro+ plan; check Billing/upgrade.
- "How do I edit my website?" → Builder (page sections, copy, images), or ask Arthur.
- "Where do I see performance / analytics?" → Reports / Insights (Data section).
KB;
    }
}
