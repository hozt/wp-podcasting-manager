# Podcasting Manager

A WordPress plugin for managing a podcast: an episode post type, show-level settings, a Podcasting 2.0 RSS feed, an episode list shortcode, and WPGraphQL support for headless sites.

## Features

- **Podcast RSS feed** at `/feed/podcast/` with iTunes tags and [Podcasting 2.0](https://podcastindex.org/namespace/1.0) tags (`podcast:guid`, `locked`, `funding`, `location`, `person`, `license`, `trailer`, `updateFrequency`, `episode`, `season`, `transcript`, `chapters`).
- **Episodes** as a `podcast` post type, with URLs like `/podcast/12/` (the number is the episode number).
- **Audio**: paste a URL or upload to the Media Library. File size and length fill in automatically for uploads. Single episode pages show an audio player.
- **Transcripts**: an HTML editor on each episode, shown on the episode page, plus a transcript URL for the feed.
- **Settings page** with dropdowns for license, update frequency, language, explicit rating, show type and Apple Podcasts categories, tooltips on every field, and artwork upload.
- **Episode defaults**: new episodes are pre-filled with today's date, the next episode number, the current season and default hosts.
- **Shortcode** to list episodes on any page.
- **WPGraphQL** fields for settings and episodes (optional; requires WPGraphQL).

## Installation

1. Download the zip (or build it, see [Development](#development)).
2. In WordPress go to **Plugins → Add New → Upload Plugin** and upload it, then activate.
3. Go to **Settings → Permalinks** and click **Save Changes** (needed once so `/feed/podcast/` and `/podcast/{n}/` work).
4. Open **Settings → Podcast Settings** and fill in your show details.

Requires WordPress with pretty permalinks enabled. WPGraphQL is optional.

## Setup

1. **Podcast Settings** (Settings → Podcast Settings): name, description, artwork, owner and owner email, language, categories, explicit rating, license. Apple Podcasts requires square artwork between 1400x1400 and 3000x3000 pixels.
2. **Add episodes** under **Podcasts → Add New**. In the *Podcast Details* box set the audio (URL or upload), episode number, date, and optional season, hosts, guests, transcript, transcript URL and chapters URL.
3. **Submit your feed** to Apple Podcasts, Spotify, Podcast Index and others using the feed URL shown at the top of the settings page. Validate it first with the link next to the URL.

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

- URL: `/feed/podcast/` (also `?feed=podcast`)
- Includes the newest published episodes that have an audio URL (default 20, configurable under *Episodes in Feed*).
- `podcast:guid` is generated once from the feed URL and stored, so it stays stable.
- Subcategories are written nested inside their parent category, as Apple expects.

## GraphQL

With WPGraphQL active:

```graphql
{
  podcastSettings { name description image feedUrl hosts license categories }
  podcasts {
    nodes {
      title episodeNumber seasonNumber mp3File episodeDate
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
```

Releases: publishing a GitHub release builds `podcasting-manager.zip` and attaches it to the release (`.github/workflows/release.yml`). The release tag must match the `Version:` in `podcasting-manager.php` (for example tag `v1.4.0` for version `1.4.0`), otherwise the build fails.

Build an installable zip locally from the repository root:

```
git archive --format=zip --prefix=podcasting-manager/ --output=podcasting-manager.zip main
```

## Author

[Jeffrey Haug](https://hozt.com)

## License

Add a license for this repository (for example GPL-2.0-or-later, the usual choice for WordPress plugins).
