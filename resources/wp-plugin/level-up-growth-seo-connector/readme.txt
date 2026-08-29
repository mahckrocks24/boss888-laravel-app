=== LevelUp Growth SEO ===
Contributors: levelupgrowth
Tags: seo, optimization, content analysis, ai, internal links
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to LevelUp Growth for AI-powered SEO analysis and optimization. All intelligence runs in your LevelUp workspace.

== Description ==

LevelUp Growth SEO is a thin connector between your WordPress site and your **LevelUp Growth** workspace. The plugin itself contains no SEO scoring, no LLM calls, and no proprietary logic — every decision happens in LevelUp Growth, and the plugin just sends content up and displays the results.

**What you get inside the editor:**

* SEO score (0–100) for the post you're editing
* Editable meta title + meta description with live character counters
* Quick-win suggestions for this specific page
* Internal link opportunities — click to copy as HTML
* Auto-analyze on publish (toggleable)

**What stays in LevelUp Growth:**

* Content scoring algorithms
* Anchor / link / cluster / equity analysis
* Competitor SERP analysis
* AI-driven insights and recommendations
* DataForSEO + DeepSeek integrations

**Why a thin connector?**

* Your SEO data is centralised — same view across all your sites.
* Plugin updates are minimal because the logic lives upstream.
* Smaller plugin footprint, fewer plugin conflicts.
* Your API key stays in WordPress; tokens never leak to the front-end.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**.
2. Activate **LevelUp Growth SEO**.
3. Go to **Settings → LevelUp SEO**.
4. Enter:
   * **API URL** — your LevelUp Growth instance (e.g. `https://staging.levelupgrowth.io/api`)
   * **API Key** — from LevelUp Growth → Settings → API Keys
   * **Workspace ID** — from your LevelUp Growth dashboard URL
5. Click **Test Connection** — you should see your workspace name, plan, and credits.
6. Edit any post or page — the **LevelUp SEO** meta box appears below the editor.

== Frequently Asked Questions ==

= Is anything stored locally? =

Only your API key, workspace ID, API URL, and a webhook secret — all in `wp_options`. Per-post: a cached SEO score, the last-saved meta title/description, and a last-analyzed timestamp in post meta. No content is duplicated.

= What does "Auto-analyze on publish" do? =

When checked (the default), publishing or updating a post automatically sends its content to LevelUp Growth and refreshes the score. Uncheck if you prefer to click **Analyze** manually each time.

= How do incoming webhooks work? =

LevelUp Growth can push score updates to your site at `POST /wp-json/lgsc/v1/update-meta`. Set the **Webhook Secret** field to the value configured in your LevelUp workspace; the plugin verifies the secret before applying any update.

= What gets removed when I uninstall? =

All `lgsc_*` options and all `_lgsc_*` post meta. Uninstall is destructive — deactivate first if you only want to disable temporarily.

== Changelog ==

= 1.0.0 =
* Initial release.
* REST client for LevelUp Growth API.
* Editor meta box with score, meta editing, quick wins, link suggestions.
* Settings page with test-connection action.
* Webhook receiver for push score updates.

== Upgrade Notice ==

= 1.0.0 =
First release. Connect your WordPress site to LevelUp Growth.
