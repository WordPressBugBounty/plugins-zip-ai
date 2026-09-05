=== ZIP AI – AI Website Builder & AI Agent (Beta) ===
Contributors: brainstormforce
Tags: ai website builder, website builder, wordpress ai, ai agent, gutenberg
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.me/BrainstormForce

WordPress AI website builder and AI agent for block themes. Build complete sites, pages, layouts, content and images by chatting inside WordPress.

== Description ==

ZIP AI is a **WordPress AI website builder and AI agent** that lets you create, edit, and manage block-based WordPress websites by chatting inside your dashboard.

Describe the website, page, section, or change you want, and ZIP AI can create layouts, copy, imagery, headers, footers, and pages using Gutenberg and Spectra blocks. The result remains editable in the WordPress Block Editor.

= Important: Theme Compatibility =

**ZIP AI currently supports Astra and WordPress Block / Full Site Editing (FSE) themes for its full website-building functionality.**

Astra is currently the only supported classic WordPress theme. Other classic themes such as Divi and traditional or custom classic themes are not currently supported for the full website-building workflow.

ZIP AI can build and edit websites using Astra or a compatible Block / FSE theme.

Please confirm that your site uses Astra or a supported Block / FSE theme before installing and connecting ZIP AI.

**ZIP AI is also in active beta.** It is best suited to new, test, staging, or development websites and is not yet recommended for an established production website you are not willing to rebuild.

= Build WordPress Websites With AI =

Tell ZIP AI what you want to build in plain language. It can help turn your instructions into WordPress pages, sections, content, and visual elements.

Use ZIP AI to:

* Build pages and sections from prompts
* Generate layouts, headlines, copy, and calls to action
* Create supported headers, footers, and site templates
* Add relevant imagery
* Create home, about, service, landing, contact, and FAQ pages
* Refine generated pages through follow-up instructions
* Continue editing with native WordPress blocks

For example, ask ZIP AI to "build a homepage for my bakery," "add a services section," "rewrite this headline," or "create an FAQ section."

= AI Agent for WordPress =

ZIP AI also works as a conversational AI agent inside wp-admin. Describe what you want it to do, review the proposed action, and approve it before a supported change is applied.

For supported operations, ZIP AI uses WordPress tools including the REST API, WP-CLI, and the Abilities API.

= Edit WordPress With AI =

Select a supported block in the WordPress Block Editor and describe the change you want. ZIP AI can help rewrite copy, improve headlines, add sections, update content, refine layouts, and adapt content while preserving the surrounding page structure.

ZIP AI uses Gutenberg and Spectra blocks. It can also use WordPress Global Styles and Spectra design tokens so supported pages can follow your site's colors and typography.

= Site Awareness and Memory =

ZIP AI can use site context including posts, pages, options, metadata, and optional site scanning.

Per-site memory and "Your Instructions" let you provide preferences for later conversations. Stored memory can be reviewed and cleared.

= You're Always in Control =

ZIP AI shows you proposed supported actions and asks for confirmation before applying them. Failed operations are reported rather than marked as successful.

AI-generated content, edits, and command output can contain mistakes or unexpected results. Review each action before approving it.

= Account and SaaS Connection =

ZIP AI is a SaaS-connected plugin and requires a ZipWP account.

After you choose to connect, ZipWP returns a short-lived, single-use token that WordPress verifies server-to-server before storing an access token.

= Code Snippets =

ZIP AI includes a Code Snippets manager for PHP, CSS, JavaScript, and HTML. It is restricted to administrators with the `manage_options` capability, and new snippets are disabled by default.

PHP snippets execute on your site. Validation is not a security sandbox; only run code you trust.

Snippet code is stored on your own site and run via a PHP `include` of a plugin-managed file - the plugin never downloads executable code from a remote server to run it. When you test a snippet, it is validated in a separate, short-lived PHP process (PHP's `-l` lint with a time limit) so a syntax error cannot take your site down; this requires `exec()` on your host and is skipped when it is unavailable.

= Built by Brainstorm Force =

ZIP AI is built by Brainstorm Force, the team behind Astra, Spectra, Starter Templates, and other WordPress products.

== External services ==

This plugin connects to external services to provide its AI features. Data is only sent after you explicitly authenticate and start a conversation.

**1. ZIP AI credit server** - `https://credits.zipwp.com`
Used to: route chat messages, manage your account, track AI-usage credits, and run server-side agent logic.
Data sent: chat messages, site structure (page titles, post counts, active plugin names, active theme name, content categories), site identity (title, tagline, language, domain), optional e-commerce summary (product counts, categories, currency), and the results of the WordPress tools the agent runs at your request, which can include post and page content, option values, and command output.
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
Terms: [Anthropic Terms of Service](https://www.anthropic.com/legal/consumer-terms) - [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)

**6. Unsplash CDN** - `https://images.unsplash.com/`
Used to: fetch the binary of an Unsplash stock photo you have explicitly picked from the in-chat image picker, so the image can be uploaded into your WordPress media library. The Unsplash *search* itself runs through the ZipWP API (see #3 above); only the chosen image's bytes are downloaded directly from the Unsplash CDN.
Data sent: a standard HTTP GET request for the image URL. No personal data, no chat content.
Terms: [Unsplash Terms](https://unsplash.com/terms) - [Unsplash Privacy Policy](https://unsplash.com/privacy)

No data leaves your site until you authenticate and send a message. You can clear all stored memory at any time via "Clear Site Memory" in the plugin menu.

== Installation ==

**Important:** ZIP AI currently supports Astra and compatible Block / FSE themes and is an active beta best suited to a new, test, staging, or development site.

= Before You Begin =

Confirm that your site uses Astra or a WordPress Block / Full Site Editing theme. Astra is currently the only supported classic theme; other classic themes such as Divi and traditional/custom classic themes are not currently supported for the full website-building workflow.

= From WordPress =

1. Go to **Plugins > Add New**.
2. Search for **ZIP AI**.
3. Click **Install Now**, then **Activate**.
4. Open ZIP AI and connect your ZipWP account.
5. Start chatting and review proposed actions before approving them.

= Manual Installation =

1. Go to **Plugins > Add New > Upload Plugin**.
2. Upload the ZIP AI plugin ZIP file, install it, and activate it.
3. Confirm your site uses Astra or a compatible Block / FSE theme and connect your ZipWP account.

== Frequently Asked Questions ==

= Can ZIP AI build a complete WordPress website? =

Yes, for sites using Astra or a supported Block / FSE theme. ZIP AI can create pages, layouts, copy, imagery, headers, footers, and site templates.

= Does ZIP AI work with my WordPress theme? =

ZIP AI currently supports Astra and compatible Block / Full Site Editing themes for the full website-building experience. Astra is currently the only supported classic theme. Other classic themes are not currently supported for that workflow.

= I use Astra or another classic theme. Can I use ZIP AI? =

Yes, if you use Astra. Astra is currently the only classic WordPress theme supported for the full website-building workflow. Other classic themes are not currently supported. ZIP AI also supports compatible WordPress Block / FSE themes.

= Do I need an FSE theme to use ZIP AI? =

No. ZIP AI supports Astra as well as compatible WordPress Block / FSE themes. Astra is currently the only supported classic theme.

= Does ZIP AI work with Gutenberg? =

Yes. ZIP AI is designed around the WordPress Block Editor and uses Gutenberg and Spectra blocks for supported building and editing workflows.

= Should I use ZIP AI on a live production website? =

ZIP AI is currently an active beta and is best suited to new, test, staging, or development sites. It is not yet recommended for an established production website you are not willing to rebuild.

= Do I need a ZipWP account? =

Yes. ZIP AI requires a ZipWP account to connect your WordPress website to the services that power its AI features.

= Is ZIP AI an AI agent or a chatbot? =

Both. You communicate with ZIP AI through chat, and for supported tasks it can propose and perform WordPress operations after you approve them.

= Will ZIP AI change my website without asking? =

For supported agent actions, ZIP AI shows the proposed action and asks for confirmation before applying the change.

= Can I use my own AI API key? =

ZIP AI supports Bring Your Own Key (BYOK) as well as plan-based usage where available.

= Can I clear what ZIP AI remembers? =

Yes. You can clear stored site memory from the plugin. Deleting the plugin also removes locally stored plugin data handled by its uninstall process.

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
