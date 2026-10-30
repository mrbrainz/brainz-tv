# OBS + Twitch setup

Live site: **https://tv.djbrainz.com**
- Listeners: `https://tv.djbrainz.com/`
- You (admin): `https://tv.djbrainz.com/?view=admin`
- OBS overlay: `https://tv.djbrainz.com/?view=overlay&token=YOUR_TOKEN` (keep this URL private)

## One-time setup
1. **Admin password.** Run, then paste the printed line into `~/sites/tv.djbrainz.com/brainz-tv/config.php` as `admin_password_hash`:
   ```
   ssh -t krystal 'cd ~/sites/tv.djbrainz.com/brainz-tv && php tools/hash-password.php'
   ```
   Krystal blocks the PHP function that hides typing, so the password shows as you type it. To keep it hidden, type it into your Mac's shell instead:
   ```
   read -rs PW && printf '%s\n' "$PW" | ssh krystal 'php ~/sites/tv.djbrainz.com/brainz-tv/tools/hash-password.php'; unset PW
   ```
2. **Overlay token.** Print it with:
   ```
   ssh krystal "php -r '\$c=require \"sites/tv.djbrainz.com/brainz-tv/config.php\"; echo \$c[\"overlay_token\"], PHP_EOL;'"
   ```
   If the URL ever leaks, put a new token in config.php (`php -r 'echo bin2hex(random_bytes(16)), "\n";'`) and update OBS.

## Add the overlay in OBS
1. In your scene: **Sources → + → Browser**, name it e.g. "brainz-tv".
2. **URL:** the overlay URL above. Add `&caption=0` to hide the title / "requested by" caption.
   When the queue is empty it shows `assets/standby.gif` with "Add a video to the queue at tv.djbrainz.com"; add `&standby=0` to stay transparent instead. To change the GIF, replace `assets/standby.gif` and deploy.
3. **Width / Height:** the size you want the video at, 16:9, e.g. `1280` × `720` (or `1920` × `1080` for full screen). Then drag/scale it into place in your layout.
4. Leave **Custom CSS** as OBS's default.
5. Untick **Shutdown source when not visible**, so it keeps in sync when you switch scenes.
6. Optional: tick **Refresh browser when scene becomes active** if you only show it in one scene.
7. Tick **Control audio via OBS** (see Audio).

To test: add a video on the listener page; it appears in the overlay within about 3 seconds.

## Audio
- Overlay videos are **muted by default**, so they never clash with the radio mix.
- To hear them, set **Overlay volume** (1–100) on the admin page. The overlay picks it up within 3 seconds; no OBS refresh needed.
- With **Control audio via OBS** ticked, the video audio gets its own fader in the OBS mixer, so you can duck it, mute it, or monitor it without sending it to stream.

## During a show (admin page)
- **Quick add:** one-click buttons for videos you list in `config.php` on the server (`~/sites/tv.djbrainz.com/brainz-tv/config.php`):
  ```php
  'quick_videos' => [
      ['id' => 'dQw4w9WgXcQ', 'title' => 'Rickroll'],
      ['id' => 'https://youtu.be/jNQXAC9IVRw', 'title' => 'Me at the zoo'],
  ],
  ```
  `id` takes a video ID or any YouTube link; `title` is the button label. Reload the admin page after editing.
- **Skip**, **Pause/Resume** (freezes the video and its timer), reorder (↑ ↓), remove (✕), **Clear queue**.
- **Settings:** seconds per video (default 180), queue size, per-listener limit, time between a listener's requests, how soon a played video can be requested again, overlay volume, **Requests open**, **Approve requests first**.
- **⛔** blocks a video; the Blocklist section blocks channels and unblocks anything, including videos YouTube refused to play.
- Short videos loop until their slot ends. Videos YouTube won't embed are skipped automatically.

## Listener search
Listeners can search for videos instead of pasting a link. Search goes through public Invidious servers (no YouTube API key), set in `config.php` as `invidious_instances` and tried in order. These volunteer-run servers come and go: if search says it "isn't working right now", pick working ones from https://api.invidious.io (they need the API enabled) and update the list. Pasting a link always works.

## Twitch
- **Chat command** (Nightbot / StreamElements / Streamer.bot), e.g. `!tv`:
  > Queue a YouTube video for the stream: https://tv.djbrainz.com — paste a link, it plays in rotation on screen.
- **Panel text** (About → Edit panels):
  > **Request a video** — paste a YouTube link at https://tv.djbrainz.com and it joins the on-stream queue. One request a minute, a couple at a time; recently played videos need a break first.
