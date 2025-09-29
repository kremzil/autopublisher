=== Moodbooster Autopublisher ===
Contributors: moodbooster
Requires at least: 6.2
Tested up to: 6.6
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moodbooster Autopublisher ingests curated fashion and lifestyle sources, rewrites them into Slovak using OpenAI structured outputs, deduplicates, and schedules publication.

== Description ==

* Fetches articles from European fashion and lifestyle outlets (FashionPost, EuropaWire, Elle Polska, Miasto Kobiet, Marie Claire HU, Fashion Street Online, L'Officiel BE, Together Magazine).
* Normalises HTML, extracts lead imagery, and respects polite crawling hints.
* Runs a planner → writer → editor pipeline against the OpenAI Responses API with strict JSON schema validation.
* Translates and rewrites content into Slovak (sk_SK) while preserving named entities.
* Applies URL, title, and embedding deduplication with optional update flow for recent related stories.
* Sideloads featured images, enforces minimum dimensions, and crops to 16:9 when configured.
* Publishes as draft or live, with standard WordPress fields for Newspaper 12 compatibility.
* Offers a full Settings page, admin log viewer (WP_List_Table), WP-Cron scheduling, and WP-CLI commands (`wp mb:*`).

== Installation ==
1. Upload the `moodbooster-autopublisher` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Open **Settings → Moodbooster Autopublisher** to provide the OpenAI API key and adjust sources, cadence, and image thresholds.

== Settings Highlights ==
* Enable/disable individual sources, select fetch mode, and choose publication status.
* Configure cron cadence (hourly, twice daily, daily) and per-run post limit.
* Set minimum image dimensions, 16:9 enforcement, and dedupe strategies.
* Export or purge logs directly from the settings screen.

== Cron ==
The plugin registers `moodbooster_autopub_run` on activation. Adjust cadence in the settings to reschedule automatically.

== Logs ==
View ingestion and publication activity in **Tools → Moodbooster Logs** with filtering, pagination, and CSV-friendly downloads.

== WP-CLI ==
* `wp mb:plan [--source=<key>]` – preview planner / writer / editor output for the first item in each enabled source.
* `wp mb:run [--limit=<n>] [--source=<key>]` – run the batch pipeline on demand.
* `wp mb:rehash-images --post=<id>` – enforce 16:9 crop for a post's featured image.

== Changelog ==
= 1.0.0 =
* Initial release with full ingestion pipeline, admin UI, logging, and CLI tooling.
