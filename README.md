# WP Settings Exporter

Export and import WordPress site settings as JSON — move configuration between staging and live, or keep a backup, without touching the database directly.

## Why

Moving a site between staging and live usually means either re-clicking every setting by hand or dumping the whole database (which also drags across URLs, users and content). Settings are just options — this plugin exports exactly those, grouped by settings screen, so a staging-to-live config move takes one download and one upload.

## Features

- **Grouped export** — General, Reading, Discussion, Media and Permalinks groups, each selectable; download as a dated `.json` file.
- **Dry-run import preview** — uploading a file shows what will change, what will be added, what is unchanged and what is skipped — nothing is applied until you click **Apply import**.
- **Safe by default** — site URLs (`siteurl`, `home`), active plugins, theme and cron are never exported, and any option whose name contains `password`, `secret`, `salt`, `token` or `api_key` is blocked.
- **Rewrite flush** — importing a permalink structure automatically flushes rewrite rules so the new structure takes effect.
- **Extensible** — add your own groups/options with the `wse_exportable_options` filter; block extra keys with `wse_is_blocked_option`.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Open **Tools → Settings Export**.

## Uninstall

Deleting the plugin removes any pending (unconfirmed) import payload.
