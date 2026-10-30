# brainz-tv

A request queue that puts listener-picked YouTube videos on a live stream. Listeners add videos from a web page, the DJ moderates from an admin page, and OBS shows the current video through a browser-source overlay.

Plain PHP: no framework, no Composer, no build step, no database (state is JSON files).

## Features

- **Listener page:** search for a video or paste a YouTube link, see what's playing and what's next.
- **Admin page:** skip, pause, reorder, remove, approve requests, quick-add favourites, block videos or channels, change settings live.
- **OBS overlay:** plays the current video in sync with the queue, with an optional caption and a standby screen when the queue is empty.
- **Limits:** per-listener queue limit, request cooldown, replay cooldown, queue size cap, optional approval mode.

## Requirements

- PHP 8.1+ with `curl` and `mbstring`
- A writable data folder outside the web root

## Installation

```bash
git clone <repo-url> brainz-tv
cd brainz-tv
cp config.example.php config.php
```

Then fill in `config.php`:

| Key | What to set |
|---|---|
| `admin_password_hash` | Output of `php tools/hash-password.php` |
| `overlay_token` | A random secret: `php -r 'echo bin2hex(random_bytes(16)), "\n";'` |
| `data_dir` | Writable folder for the JSON data (default `./data`) |

Every other key (rotation time, limits, search instances, quick-add videos) has a working default and is explained in `config.example.php`. Most of them can also be changed later on the admin page.

### Run locally

```bash
php -S localhost:8000 -t public
```

### Deploy

Point the web root at `public/`, or put a one-line loader `index.php` in your web root that requires `brainz-tv/public/index.php`. Keep `config.php` and `data/` out of the web root.

## Usage

| Who | URL |
|---|---|
| Listeners | `/` |
| Admin | `/?view=admin` |
| OBS overlay | `/?view=overlay&token=YOUR_TOKEN` |

**In OBS:** add a **Browser** source with the overlay URL, size 1280×720 (or 1920×1080), untick *Shutdown source when not visible*, and tick *Control audio via OBS*. Overlay options:

- `&caption=0`: hide the title / "requested by" caption
- `&standby=0`: stay transparent instead of showing the standby GIF when the queue is empty

The overlay is muted by default. Set **Overlay volume** on the admin page to hear it.

Full OBS walkthrough: [docs/obs-setup.md](docs/obs-setup.md).

## Tests

Plain PHP scripts, no test framework:

```bash
for t in store queue auth search youtube http; do php tests/${t}_test.php; done
```

`search_test.php` and `youtube_test.php` also take `--live` to hit the real services.

## Project layout

```
public/index.php     entry point: routing, listener page, APIs
src/                 Queue, Store, Auth, Search, YouTube, admin + overlay views
tests/               test scripts
tools/               hash-password.php, backlog.mjs
docs/                OBS setup, backlog
assets/              standby GIF
```
