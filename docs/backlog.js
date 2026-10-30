// Active backlog. Models: read this file; completed_ids resolves Done dependencies without archive reads.
// Complete a ticket here, then run: node tools/backlog.mjs sync
window.BACKLOG = {
 "title": "brainz-tv backlog",
 "phases": [
  {
   "id": "A1",
   "name": "Queue core"
  },
  {
   "id": "A2",
   "name": "OBS overlay"
  },
  {
   "id": "A3",
   "name": "Admin"
  },
  {
   "id": "A4",
   "name": "Hardening + deploy"
  }
 ],
 "completed_ids": [
  "B01",
  "B02",
  "B03",
  "B04",
  "B05",
  "B06",
  "B07",
  "B08"
 ],
 "activities": [
  {
   "id": "B09",
   "order": 9,
   "phase": "A3",
   "size": "S",
   "status": "Ready",
   "depends": [
    "B02"
   ],
   "read": [],
   "title": "Admin login",
   "description": "?view=admin behind a password checked with password_verify against the hash in config.php; PHP session with regenerated ID, secure/httponly cookies, CSRF token on every admin POST, logout. Brute-force slow-down (delay/lockout per IP stored in the data dir).",
   "done_when": "Admin pages and admin API calls reject unauthenticated or CSRF-less requests; a helper command prints a hash for a new password."
  },
  {
   "id": "B10",
   "order": 10,
   "phase": "A3",
   "size": "M",
   "status": "Backlog",
   "depends": [
    "B05",
    "B06",
    "B09"
   ],
   "read": [],
   "title": "Admin queue controls",
   "description": "Admin view: see now-playing + queue, skip current, remove/reorder items, add a video directly, clear queue, pause/resume rotation, open/close submissions. Settings: rotation seconds, max queue length, per-user limit, overlay volume (0 = muted; config overlay_volume is the default). Optional approval mode: submissions wait in a pending list until approved.",
   "done_when": "Every control changes what the overlay shows within one poll, and settings persist in the data dir across restarts."
  },
  {
   "id": "B11",
   "order": 11,
   "phase": "A4",
   "size": "S",
   "status": "Backlog",
   "depends": [
    "B05"
   ],
   "read": [],
   "title": "Abuse limits for submissions",
   "description": "Without accounts: per-IP rate limit (e.g. one submission per N minutes, max M in queue at once), duplicate-in-queue rejection, recently-played cooldown, max video length if known, and a blocklist of video IDs/channels the admin can add to. Honeypot field against bots.",
   "done_when": "Each limit returns a friendly message on the user view and is configurable; limit state is cleaned up automatically."
  },
  {
   "id": "B12",
   "order": 12,
   "phase": "A4",
   "size": "XS",
   "status": "Backlog",
   "depends": [
    "B01"
   ],
   "read": [],
   "title": "Confirm open product questions with the user",
   "description": "Ask: default rotation time; should each video play to the end or be cut at the interval; is approval mode on by default; do listeners need a display name or Twitch username; any content rules; overlay size/position in the OBS layout. Update affected tickets with the answers.",
   "done_when": "Answers are recorded in the relevant tickets' descriptions."
  },
  {
   "id": "B13",
   "order": 13,
   "phase": "A4",
   "size": "S",
   "status": "Backlog",
   "depends": [
    "B07",
    "B10"
   ],
   "read": [],
   "title": "Deploy to Krystal",
   "description": "Check Krystal's PHP version, set the docroot to public/ (or a subdomain pointing at it), put config.php and the data dir above the docroot with write permissions, HTTPS on. Write a short deploy section in CLAUDE.md (how files get uploaded, where config lives).",
   "done_when": "User and admin views work on the live domain, the overlay works as an OBS browser source from the live URL, and data/ and config.php are not reachable over HTTP."
  },
  {
   "id": "B14",
   "order": 14,
   "phase": "A4",
   "size": "XS",
   "status": "Backlog",
   "depends": [
    "B13"
   ],
   "read": [],
   "title": "OBS + Twitch setup notes",
   "description": "Short guide: adding the overlay as a browser source (URL with token, size, 'Shutdown source when not visible' off, refresh on scene activation), plus a chat command/panel text pointing listeners at the user view.",
   "done_when": "The user can set up the overlay from the notes alone."
  }
 ]
};
