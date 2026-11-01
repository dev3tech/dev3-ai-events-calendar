=== DEV3 AI Event Writer for The Events Calendar ===
Contributors: dev3tech
Tags: events, the events calendar, ai, social media, event tickets
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: the-events-calendar
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DEV3 AI writes event descriptions, social posts, emails, recaps, FAQs and SEO meta for The Events Calendar, and imports events from flyers.

== Description ==

DEV3 AI Event Writer is an add-on for the free The Events Calendar plugin. It works inside the TEC event editor (block editor sidebar and classic editor box) and on a bulk screen. Everything DEV3 AI writes is a draft: you see a diff against the current text, edit it, and accept or reject it.

* Event descriptions as native blocks (headings, paragraphs, lists). TEC's own date, venue, organizer and ticket blocks are kept.
* Social posts per network (X, Facebook, Instagram, LinkedIn, Threads, Bluesky, Mastodon) within each network's character limit, with live counters, copy and share links.
* Promo and reminder emails, and post-event recap emails that use Event Tickets attendance counts. Test send, send to attendees, optional automatic reminder.
* FAQ with FAQPage schema, shown on the event page, as a block or with [dev3ec_faq].
* SEO title and meta description, written into Yoast SEO or Rank Math, or printed by DEV3 when no SEO plugin is active.
* Import an event from a flyer or poster (image or PDF): DEV3 AI reads it, matches existing venues and organizers, and you create a reviewed draft event.
* Bulk generation in the background for many events, with a review queue.
* Works without AI: templates, manual editing, manual flyer entry.

Uses your own Google Gemini API key. No licence key, no account.

== Installation ==

1. Install and activate The Events Calendar (free). Event Tickets (free) is optional.
2. Upload the plugin zip in Plugins > Add New > Upload, then activate.
3. Open DEV3 AI Events > API Config and paste your Google Gemini API key.

== Frequently Asked Questions ==

= Does it publish anything by itself? =
No. Every output is a draft until you accept it. Flyer imports create draft events.

= Is attendee data sent to the AI? =
No. Recap emails only send counts (registered, checked in). Names and emails are used for sending only.

= Which editors are supported? =
The block editor (TEC's "Activate Block Editor for Events" setting) and the classic editor.

== Privacy ==

Event details you choose to generate for are sent to Google Gemini with your API key. See the privacy policy text DEV3 adds under Settings > Privacy.

== Screenshots ==

1. Dashboard
2. Description draft with diff
3. Block editor sidebar
4. Flyer import review with venue and organizer matching
5. Bulk events screen
6. Writer on a phone
7. Event FAQ on the front end

== Changelog ==

= 1.0.0 =
* Initial release.
