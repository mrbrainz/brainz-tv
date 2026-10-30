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
  "B08",
  "B09",
  "B10",
  "B11",
  "B15"
 ],
 "activities": [
  {
   "id": "B12",
   "order": 12,
   "phase": "A4",
   "size": "XS",
   "status": "Ready",
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
   "status": "Ready",
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
