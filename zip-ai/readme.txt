=== ZIP AI (Beta) – AI Website Builder, AI Agent & Assistant for WordPress ===
Contributors: brainstormforce
Tags: ai, ai website builder, ai agent, ai assistant, ai chatbot
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.me/BrainstormForce

Beta: An AI agent that builds & edits WordPress sites by chat. Requires a block (FSE) theme - does NOT work with classic themes.

== Description ==

= Please Read First: ZIP AI Requires a Block (FSE) Theme - It Will NOT Work With Classic Themes =

ZIP AI currently supports only WordPress block (FSE) themes. Classic themes (such as Astra and other traditional themes) are not supported yet.
Support for classic themes is coming soon. Until then, please ensure your site is using an FSE theme before installing and connecting ZIP AI.

This requirement applies even to building a single page: today ZIP AI generates the page's header, footer, and template using FSE templates, so an FSE theme is required for anything it builds to render correctly.

**Please confirm you are on a block/FSE theme before installing and connecting.** If you are on a classic theme and are not willing to switch to an FSE theme, ZIP AI is not the right fit for your site yet. We would rather tell you now than have you set up an account only to find it can't run on your current theme.

= ZIP AI Is Also an Early Beta =

ZIP AI is in active beta and is not yet recommended for production use on a live or established website. It builds and edits using Spectra blocks, and syncs your site's global colors and typography through WordPress Global Styles. We are actively improving it, and making standalone pages fully theme-independent (so they work without FSE) is on our roadmap.

= What ZIP AI Does =

**ZIP AI is a conversational AI website builder and AI agent that lives inside your WordPress dashboard.** Tell it what you want in plain English - "build a homepage for my bakery," "change the hero heading," "add a contact section" - and it does the work for you, right inside wp-admin.

Instead of digging through menus and settings, you describe what you need, review what ZIP AI proposes, and approve it. It interacts with WordPress the same way WordPress itself does - through the official **WordPress REST API**, **WP-CLI**, and the emerging **Abilities API** standard (via the **WordPress MCP Adapter**) - so there are no black-box database writes.

ZIP AI is built by **Brainstorm Force**, the team behind Astra, Spectra, and Starter Templates - WordPress products trusted on **millions of websites.**

= What You Can Do With ZIP AI =

**Build pages and content by chatting**
Describe the page you want and ZIP AI assembles it - layout, sections, copy, and real stock imagery - using Gutenberg and Spectra blocks.

**Vibe editing - edit any block from inside Block Editor only**
Select a block, open the toolbar, and tell ZIP AI what to change. Edits land on exactly the block you picked, apply cleanly, and preserve the original design - including counters, countdowns, and composite blocks.

**On-brand every time**
ZIP AI syncs your site's global colors and typography through WordPress Global Styles. It does not hardcode styles - pages use Spectra design tokens and CSS variables. For new sites it creates a fresh design system; for existing sites it reuses your site's existing design tokens where available.

**Site-wide search & site awareness**
ZIP AI can search across your posts, pages, options, and meta, and (optionally) scan your site so it has day-zero awareness of your content structure.

**Memory that remembers you**
With per-site memory and "Your Instructions," you can pin rules ZIP AI always follows. Your brand and preferences carry across conversations, and Site Memory is shown in plain text so you always know what it remembers.

**Real WordPress operations**
Beyond content, ZIP AI can inspect and manage the state of your site - themes install, switch, and delete instantly, and safe WP-CLI command execution (with output truncation) is available for advanced tasks.

**Code Snippets manager**
Save and run your own custom PHP, CSS, JavaScript, and HTML - with strong, administrator-only safeguards (details below).

**Bring Your Own Key (BYOK) & plan-based usage**
This is open for all in beta.

= You're Always in Control =

ZIP AI is an assistant, not autopilot. **It always shows you what it's about to do and asks for confirmation before applying any change.** Failed steps are reported honestly - never marked as successful - and the approval card updates if ZIP AI recovers from a failed step. You review every action before it touches your site.

= Disclaimer =

ZIP AI is a SaaS-connected plugin and requires an account on our platform. When you install this plugin, you'll need to register for a free ZipWP account. Or, if you already have an account with us, you can simply connect this plugin to it.

This plugin helps you connect your WordPress website to the ZipWP SaaS platform, which powers its AI features.

= External Service Connection / Security =

ZIP AI connects your WordPress site with the ZipWP SaaS platform securely, and only after you choose to connect. When you initiate a connection, you are redirected to the ZipWP login page. After you authenticate, the platform issues a short-lived, single-use token that is sent back to WordPress via a redirect. WordPress then verifies that token server-to-server before storing an access token for future API calls. Tokens are time-limited, single-use, and encrypted in transit using HTTPS. No actions are taken by the plugin until token verification succeeds.

Once connected, ZIP AI uses this connection to power its AI features - routing your chat messages, generating responses, and returning content and imagery for your site. It relies on the following ZipWP services: the ZipWP authentication service (`https://app.zipwp.com/auth/`), the ZipWP credit and agent server, and the ZipWP API (`https://api.zipwp.com/api/`). AI responses are generated by third-party models (such as Google Gemini and Anthropic Claude) accessed through the ZipWP platform. No site data is sent until you authenticate and send a message, and you can clear all stored memory at any time via **Clear Site Memory** in the plugin menu.

Your use of the ZipWP platform is governed by its [Terms of Service](https://store.brainstormforce.com/terms-and-conditions/) and [Privacy Policy](https://store.brainstormforce.com/privacy-policy/). All privacy and data handling comply with WordPress.org Plugin Guidelines.

= Important Note =

ZIP AI is an AI assistant. Generated content, edits, and command output may contain mistakes or unexpected results. ZIP AI always asks for confirmation before applying changes, but **you are responsible for reviewing every action it suggests on your site.**

= Code Snippets =

ZIP AI includes a Code Snippets manager (**Settings &rarr; ZIP AI Code Snippets**) that lets you save and run your own custom code - PHP, CSS, JavaScript, and HTML - on your site. This is a deliberate feature: enabled PHP snippets are executed on your site, exactly like the code in a plugin or your theme's `functions.php`.

Because this feature runs arbitrary code, it is tightly gated:

* **Administrators only.** Creating, editing, enabling, and executing snippets all require the `manage_options` capability. Every REST endpoint enforces this; no snippet runs for any lower-privileged user.
* **Disabled by default.** New snippets are created in a disabled state and only execute after an administrator explicitly enables them.
* **Local execution, no remote code.** Snippet code is stored on your own site and run via PHP `include` of a plugin-managed file. The plugin never downloads executable code from a remote server to run it.
* **Validation, not a sandbox.** Before a PHP snippet is saved it passes a blocklist that rejects common dangerous calls (`eval`, `exec`, `shell_exec`, `system`, backtick shell execution, and similar). This is a guardrail to catch mistakes - it is **not** a security sandbox. As with any code you add to WordPress, only run snippets you trust.
* **Syntax checking.** When you test a snippet, the plugin validates it in a separate, short-lived PHP process (using PHP's `-l` lint with a time limit) so a syntax error cannot take your site down. This requires `exec()` to be available on your host; if it is not, the test step is skipped.

= About Brainstorm Force =

ZIP AI is built by **Brainstorm Force**, the team behind Astra, Spectra, Starter Templates, SureForms, SureCart, and other widely used WordPress products serving millions of websites worldwide.

== External services ==

This plugin connects to external services to provide its AI features. Data is only sent after you explicitly authenticate and start a conversation.

**1. ZIP AI credit server** - `https://credits.zipwp.com`
Used to: route chat messages, manage your account, track AI-usage credits, and run server-side agent logic.
Data sent: chat messages, site structure (page titles, post counts, active plugin names, active theme name, content categories), site identity (title, tagline, language, domain), and optional e-commerce summary (product counts, categories, currency).
Terms: [Terms of Service](https://store.brainstormforce.com/terms-and-conditions/) - [Privacy Policy](https://store.brainstormforce.com/privacy-policy/)

**2. ZipWP authentication middleware** - `https://app.zipwp.com/auth/`
Used to: authenticate your ZipWP account and issue access tokens to the plugin.
Data sent: email address and OAuth state during the login flow.
Terms: [Terms of Service](https://store.brainstormforce.com/terms-and-conditions/) - [Privacy Policy](https://store.brainstormforce.com/privacy-policy/)

**3. ZipWP API** - `https://api.zipwp.com/api/`
Used to: fetch ZipWP product data (templates, design tokens, block presets) when assembling AI responses.
Data sent: authenticated product-lookup queries.
Terms: [Terms of Service](https://store.brainstormforce.com/terms-and-conditions/) - [Privacy Policy](https://store.brainstormforce.com/privacy-policy/)

**4. Google Gemini (via the credit server)**
Used to: generate AI responses to your chat messages.
Data sent: the text of your chat messages and the site context collected above.
Terms: [Google Terms of Service](https://policies.google.com/terms) - [Google Privacy Policy](https://policies.google.com/privacy)

**5. Anthropic Claude (via the credit server)**
Used to: generate AI responses to your chat messages.
Data sent: the text of your chat messages and the site context collected above.
Terms: [Anthropic Terms of Service](https://www.anthropic.com/legal/consumer-terms) - [Anthropic Privacy Policy](https://www.anthropic.com/privacy)

**6. Unsplash CDN** - `https://images.unsplash.com/`
Used to: fetch the binary of an Unsplash stock photo you have explicitly picked from the in-chat image picker, so the image can be uploaded into your WordPress media library. The Unsplash *search* itself runs through the ZipWP API (see #3 above); only the chosen image's bytes are downloaded directly from the Unsplash CDN.
Data sent: a standard HTTP GET request for the image URL. No personal data, no chat content.
Terms: [Unsplash Terms](https://unsplash.com/terms) - [Unsplash Privacy Policy](https://unsplash.com/privacy)

No data leaves your site until you authenticate and send a message. You can clear all stored memory at any time via "Clear Site Memory" in the plugin menu.

== Installation ==

**Important:** ZIP AI requires a block (FSE) theme and does not work with classic themes. It is also an early beta best used on a new or test site. Please read the notes at the top of the Description before installing.

= Before you begin =

Make sure your site is using a block theme that supports Full Site Editing (for example, Twenty Twenty-Five or Twenty Twenty-Four). If you are on a classic theme such as Astra, Divi, or a custom theme, ZIP AI will not work today.

= From your WordPress dashboard =

1. Go to **Plugins &rarr; Add New**.
2. Search for **ZIP AI**.
3. Click **Install Now**, then **Activate**.

= Manual upload =

1. Download the plugin `.zip` file.
2. Go to **Plugins &rarr; Add New &rarr; Upload Plugin** and choose the file.
3. Click **Install Now**, then **Activate**.

= After activation =

1. Confirm your site is running a block (FSE) theme.
2. Click the floating chat button in your admin, or open **ZIP AI** from the Settings menu.
3. Connect your free ZipWP account.
4. Start chatting. ZIP AI will always ask for confirmation before making any changes to your site.

== Frequently Asked Questions ==

= Does ZIP AI work with my theme? =

Only if you are using a block theme that supports Full Site Editing (FSE), such as Twenty Twenty-Five or Twenty Twenty-Four. ZIP AI does **not** work with classic themes, which includes Astra, Divi, and most existing or custom themes.

= I use Astra (or another classic theme). Can I use ZIP AI on my existing site? =

Not today. Astra and other classic (non-FSE) themes are not supported yet. ZIP AI builds site templates (header, footer, and page templates) using FSE templates and syncs global colors and typography through WordPress Global Styles, both of which currently require an FSE theme. Broader theme support is on our roadmap.

= Why is an FSE theme required - even for a single page? =

ZIP AI builds the header, footer, and site templates using FSE templates, and it syncs your site's global colors and typography through WordPress Global Styles. Today, even a standalone page still generates FSE templates for the page's header, footer, and template, so an FSE theme is required for it to render correctly. Making standalone pages fully theme-independent is on our roadmap.

= Does ZIP AI hardcode styles into my pages? =

No. Pages use Spectra design tokens and CSS variables rather than hardcoded styles. For new sites, ZIP AI creates a fresh design system; for existing sites, it reuses your site's existing design tokens where available.

= Should I use ZIP AI on my live website? =

Not yet. ZIP AI is an early beta and is best suited to brand-new or test sites running an FSE theme. We do not recommend using it on an established site you are not willing to rebuild.

= Do I need an account? =

Yes. ZIP AI is a SaaS-connected plugin that requires a free ZipWP account. It connects your WordPress site to the ZipWP platform, which powers the AI features.

= Is ZIP AI an AI agent or just a chatbot? =

Both. It's a WordPress AI chatbot you talk to in plain English, and an AI agent that performs tasks - building pages, editing blocks, and managing site operations - through the official WordPress REST API, WP-CLI, and Abilities API.

= Will ZIP AI change my site without asking? =

No. ZIP AI always shows you what it plans to do and waits for your confirmation before applying any change.

= Can I use my own AI API key? =

Yes. ZIP AI supports Bring Your Own Key (BYOK) as well as plan-based usage.

= How does the connection to the SaaS work, and is it secure? =

You are redirected to the ZipWP login page and, after you authenticate, a short-lived, single-use token is returned to WordPress and verified server-to-server over HTTPS before any access token is stored. No actions are taken until verification succeeds. See "External Service Connection / Security" in the description.

= Can I clear what ZIP AI remembers about my site? =

Yes. You can wipe all stored memory at any time using **Clear Site Memory** in the plugin menu.

= How do I remove all my data? =

Use the **Clear Site Memory** option in the plugin menu to wipe your server-side memory. Deleting the plugin also removes all locally stored options, transients, and the added capability (via `uninstall.php`).

== Screenshots ==

1. Chat with ZIP AI directly inside your WordPress dashboard to build and edit your site.

== Changelog ==

= 0.0.10 - August 28, 2026 =
- New: A finished website build is celebrated with confetti and a "View my website" button.
- Improvement: Branded confirm and alert dialogs replace the browser's native pop-ups.
- Fix: Imported pages keep their section spacing instead of collapsing edge to edge.

= 0.0.9 - August 25, 2026 =
- New: Pick a Look keeps every palette you generate per design, applies the whole color set when you switch, and Shuffle always returns fresh options.
- New: Improve a single block inside a section, styled to match the section around it.
- Improvement: UI improvements.
- Fix: Clear Site Memory no longer removes your Personalized Memory.
- Fix: Chat history is no longer cleared when a screen reloads.
- Fix: The chat panel stays draggable on every screen, and its menus stay inside it.
- Fix: Quick edits apply to the block you picked, on the page you have open.
- Fix: Hand-picked brand colors are no longer replaced when the site is built.

= 0.0.8 - August 17, 2026 =
- New: Pick a Look — preview page designs and brand colors, adjust them, and choose before building your site.
- New: Connect ZIP AI with external AI agents using MCP.
- New: Support for SureRank, SureDonation, SureCookie, and SureMembers.
- New: See live progress when making bulk changes.
- New: Move the chat launcher, reset chat settings, and undo the last change.
- New: Update outdated plugins with one click when needed for imports.
- New: Imported headers and footers now work and remain editable on classic themes.
- Improvement: Quick edits now apply to the correct page and location.
- Improvement: Refreshed plugin theme — updated colors, icon, and overall UI.
- Improvement: Stronger MCP security.

= 0.0.7 - July 13, 2026 =
- Improvement: UI improvements.

= 0.0.6 =
- Fix: Issue regarding vendor files autoloading.

= 0.0.5 =
- Public alpha release of ZIP AI.

== Upgrade Notice ==

= 0.0.5 =
Public alpha release of ZIP AI.

== Support ==

* [Support Forum](https://wordpress.org/support/plugin/zip-ai/)
* [Documentation](https://zipwp.com/docs/)
