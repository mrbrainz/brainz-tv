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
  "B04"
 ],
 "activities": [
  {
   "id": "B05",
   "order": 5,
   "phase": "A1",
   "size": "S",
   "status": "Ready",
   "depends": [
    "B03",
    "B04"
   ],
   "read": [],
   "title": "User view: submit form and visible queue",
   "description": "Mobile-friendly page: optional display name + URL/ID field, submit via POST (works without JS, enhanced with fetch). Show now-playing, the upcoming queue with titles/thumbnails/who added them, and the listener's position. Escape all user text.",
   "done_when": "A listener on a phone can submit a video and see it appear in the queue with its title; errors from B04 show inline."
  },
  {
   "id": "B06",
   "order": 6,
   "phase": "A1",
   "size": "S",
   "status": "Ready",
   "depends": [
    "B03"
   ],
   "read": [],
   "title": "Rotation logic without cron",
   "description": "Advance the queue lazily: whenever state is read (overlay poll, page load), if now-playing started more than the rotation interval ago (or the video's own length, if shorter and known), pop the next item and record the start time. Empty queue = nothing playing (or idle state). Deterministic from timestamps, so no cron or long-running process is needed on shared hosting.",
   "done_when": "With rotation set to e.g. 20s, items advance on schedule from overlay polling alone, and two simultaneous polls never skip an item."
  },
  {
   "id": "B07",
   "order": 7,
   "phase": "A2",
   "size": "M",
   "status": "Backlog",
   "depends": [
    "B06"
   ],
   "read": [],
   "title": "OBS overlay view (browser source)",
   "description": "?view=overlay: transparent background, sized for a browser source (e.g. 1280x720 or configurable), embeds the current video with the YouTube IFrame API, starts at the right offset from the server start time, and polls a JSON endpoint every few seconds to swap videos. Optional caption: title + 'requested by'. Keyed by a secret token in the URL so only the user's OBS can open it.",
   "done_when": "Added as an OBS browser source, the overlay shows the queued videos in turn and switches without a manual refresh; with an empty queue it shows nothing (transparent)."
  },
  {
   "id": "B08",
   "order": 8,
   "phase": "A2",
   "size": "XS",
   "status": "Backlog",
   "depends": [
    "B07"
   ],
   "read": [],
   "title": "Decide and implement overlay audio policy",
   "description": "Videos playing over a live radio mix: muted by default, with an admin setting for volume/unmute. Confirm with the user which they want. Note OBS browser sources can route audio via 'Control audio via OBS'; document that option.",
   "done_when": "The user has chosen the default, the setting works in OBS, and the OBS setup notes say how audio is handled."
  },
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
   "description": "Admin view: see now-playing + queue, skip current, remove/reorder items, add a video directly, clear queue, pause/resume rotation, open/close submissions. Settings: rotation seconds, max queue length, per-user limit. Optional approval mode: submissions wait in a pending list until approved.",
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
