# Podcasting Manager

A WordPress plugin for managing a podcast: an episode post type, show-level settings, a Podcasting 2.0 RSS feed, an episode list shortcode, and WPGraphQL support for headless sites.

## Features

- **Podcast RSS feed** at `/feed/podcast/` with iTunes tags and [Podcasting 2.0](https://podcastindex.org/namespace/1.0) tags (`podcast:guid`, `locked`, `funding`, `location`, `person`, `license`, `trailer`, `updateFrequency`, `episode`, `season`, `transcript`, `chapters`).
- **Episodes** as a `podcast` post type, with URLs like `/podcast/12/` (the number is the episode number). If two episodes share a number (e.g. numbering restarts each season), the oldest keeps `/podcast/12/` and the other uses its slug URL.
- **Audio**: paste a URL or upload to the Media Library. File size and length fill in automatically for uploads; for remote URLs the size is looked up once on save. Single episode pages show an audio player.
- **Video**: optionally set a video URL (e.g. YouTube) per episode. It is embedded on the single episode page below the audio player, only when populated.
- **Transcripts**: an HTML editor on each episode, shown on the episode page, plus a transcript URL for the feed.
- **Settings page** with dropdowns for license, update frequency, language, explicit rating, show type and Apple Podcasts categories, tooltips on every field, and artwork upload.
- **Episode defaults**: new episodes are pre-filled with today's date, the next episode number, the current season and default hosts.
- **Breadcrumbs** (Home › Podcast › Episode) on the archive and episode pages, with schema.org structured data. Toggle under Episode Defaults, or use the `podcast_show_breadcrumbs` filter; rename the "Podcasts" crumb with `podcast_breadcrumb_label`.
- **Episode archive** at `/podcast/` (native WordPress page, players included), plus a shortcode to list episodes on any page.
- **WPGraphQL** fields for settings and episodes (optional; requires WPGraphQL).

## Installation

1. Download the zip (or build it, see [Development](#development)).
2. In WordPress go to **Plugins → Add New → Upload Plugin** and upload it, then activate.
3. Go to **Settings → Permalinks** and click **Save Changes** (needed once so `/feed/podcast/` and `/podcast/{n}/` work).
4. Open **Settings → Podcast Settings** and fill in your show details.

Requires WordPress with pretty permalinks enabled. WPGraphQL is optional.

## Setup

1. **Podcast Settings** (Settings → Podcast Settings): name, description, artwork, owner and owner email, language, categories, explicit rating, license. Apple Podcasts requires square artwork between 1400x1400 and 3000x3000 pixels.
2. **Add episodes** under **Podcasts → Add New**. In the *Podcast Details* box set the audio (URL or upload), episode number, date, and optional season, hosts, guests, transcript, transcript URL, chapters URL and video URL.
3. **Submit your feed** to Apple Podcasts, Spotify, Podcast Index and others using the feed URL shown at the top of the settings page. Validate it first with the link next to the URL.

## Episode archive

Episodes are listed at `/podcast/` (change the path under *Episode Defaults → Archive URL*), newest episode number first, 10 per page, with native `/page/2/` pagination and `?season=2` filtering. An audio player is shown for each episode unless you untick *Player on Archive*. Single episode and `/podcast/{n}/` URLs always stay under `/podcast/` so episode links never break when the archive path changes (override with the `podcast_episode_base` filter).

- To restyle it, copy `templates/archive-podcast.php` into your theme as `archive-podcast.php`.
- Works with classic and block themes.
- If a published Page already uses the archive path, the archive stays off (a notice appears in Podcast Settings) so your page keeps working. Rename or unpublish the page, or pick another Archive URL.
- The per-page count can be changed with the `podcast_archive_per_page` filter.
- Password-protected episodes never expose their audio, transcript or content in the archive, feed or on the episode page.

## Shortcode

```
[podcast_episodes]
[podcast_episodes limit="5" season="2" order="ASC" player="yes"]
```

| Attribute  | Default | Description                                     |
|------------|---------|-------------------------------------------------|
| `limit`    | `10`    | Episodes per page (1-100)                       |
| `season`   | (all)   | Only show this season                           |
| `order`    | `DESC`  | `ASC` or `DESC`, by episode number              |
| `player`   | `no`    | Show an audio player on each entry              |
| `excerpt`  | `yes`   | Show the episode excerpt                        |
| `paginate` | `yes`   | Show Newer/Older links                          |

Episodes without an episode number are not listed.

## Feed

- URL: `/feed/podcast/` (also `?feed=podcast`). `/podcast/feed/` (following the Archive URL) serves the same feed, so either can be submitted; `/feed/podcast/` is the canonical address written into the feed.
- Includes the newest published episodes that have an audio URL (default 20, configurable under *Episodes in Feed*).
- Ordered by episode date (falling back to publish date). The rendered feed is cached for an hour and refreshed automatically when an episode or podcast setting changes; it also answers conditional (`ETag`) requests.
- `podcast:guid` is generated once from the feed URL and stored, so it stays stable.
- Subcategories are written nested inside their parent category, as Apple expects.

## GraphQL

With WPGraphQL active:

```graphql
{
  podcastSettings { name description image feedUrl hosts license categories }
  podcasts {
    nodes {
      title episodeNumber seasonNumber mp3File videoUrl episodeDate
      episodeLength fileSize transcript transcriptUrl chaptersUrl guests
    }
  }
}
```

## Development

The plugin is plain PHP with no build step.

```
podcasting-manager.php             main class: settings, post type, meta box, shortcode, GraphQL
includes/class-podcast-feed.php    RSS feed
includes/class-podcast-options.php dropdown choice lists and sanitizers
tests/                             PHPUnit integration suite (boots a disposable WordPress)
```

### Tests

```sh
composer install
bash tests/bin/install-wordpress.sh   # one-time: fetches pristine WP core into vendor/
vendor/bin/phpunit                    # or: composer test
```

The suite needs MySQL. Defaults target this project's LocalWP site; override with
`PODCAST_TEST_DB_HOST`, `PODCAST_TEST_DB_SOCKET`, `PODCAST_TEST_DB_NAME`,
`PODCAST_TEST_DB_USER`, `PODCAST_TEST_DB_PASSWORD` (and `PODCAST_TEST_WP_DIR` /
`PODCAST_TEST_WP_VERSION` for the WP core location). The database named must
contain `test` — the suite drops and recreates it on every run. CI runs the same
suite (`.github/workflows/tests.yml`).

Coverage (requires pcov or xdebug):

```sh
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
```

Releases: publishing a GitHub release builds `podcasting-manager.zip` and attaches it to the release (`.github/workflows/release.yml`). The release tag must match the `Version:` in `podcasting-manager.php` (for example tag `v1.5.0` for version `1.5.0`), otherwise the build fails.

Build an installable zip locally from the repository root:

```
git archive --format=zip --prefix=podcasting-manager/ --output=podcasting-manager.zip main
```

## Author

[Jeffrey Haug](https://hozt.com)

## License

Add a license for this repository (for example GPL-2.0-or-later, the usual choice for WordPress plugins).
