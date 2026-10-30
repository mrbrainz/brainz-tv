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
  "B12",
  "B13",
  "B14",
  "B15",
  "B16",
  "B17",
  "B18",
  "B19",
  "B20",
  "B21",
  "B22",
  "B23"
 ],
 "activities": [
  {
   "id": "B24",
   "order": 24,
   "phase": "A4",
   "size": "XS",
   "status": "Ready",
   "depends": [],
   "read": [
    "src/Auth.php",
    "tests/auth_test.php"
   ],
   "title": "Atomic login throttle",
   "description": "Review 2026-10-30: Auth::login checks the lockout and counts failures in separate locked steps, so concurrent guesses during the free tries are not all counted.",
   "done_when": "Each attempt is counted in the same update as the lockout check and cleared on success; auth_test passes."
  },
  {
   "id": "B25",
   "order": 25,
   "phase": "A4",
   "size": "XS",
   "status": "Ready",
   "depends": [],
   "read": [
    "public/index.php"
   ],
   "title": "Safe redirect after listener POST",
   "description": "Review 2026-10-30: The listener POST redirects to strtok(REQUEST_URI, ?), so a path like //evil.com would give an open redirect if routed here.",
   "done_when": "The redirect target is a fixed relative path; curl of //x-style paths never yields an off-site Location."
  },
  {
   "id": "B26",
   "order": 26,
   "phase": "A4",
   "size": "XS",
   "status": "Ready",
   "depends": [],
   "read": [
    "public/index.php",
    "config.example.php"
   ],
   "title": "Dedicated salt for IP hashing",
   "description": "Review 2026-10-30: The IP HMAC key is overlay_token . admin_password_hash: empty if both unset, and changing the password resets all limits.",
   "done_when": "IP keys use a dedicated secret (config ip_salt or one generated into data/) that is never empty."
  },
  {
   "id": "B27",
   "order": 27,
   "phase": "A4",
   "size": "S",
   "status": "Ready",
   "depends": [],
   "read": [
    "public/index.php"
   ],
   "title": "Tests for settings and admin gates",
   "description": "Review 2026-10-30: saveSettings validation, CSRF/login gating of admin-* routes, the honeypot and forListener stripping of ip have no tests.",
   "done_when": "A tests/*.php script covers these and passes."
  },
  {
   "id": "B28",
   "order": 28,
   "phase": "A1",
   "size": "XS",
   "status": "Ready",
   "depends": [],
   "read": [
    "public/index.php"
   ],
   "title": "Ignore stale search responses",
   "description": "Review 2026-10-30: Two quick searches can finish out of order, so older results overwrite newer ones.",
   "done_when": "Only the latest search request renders its results."
  },
  {
   "id": "B29",
   "order": 29,
   "phase": "A4",
   "size": "XS",
   "status": "Ready",
   "depends": [],
   "read": [
    "public/index.php",
    "src/Auth.php"
   ],
   "title": "Input and cookie tidy-ups",
   "description": "Review 2026-10-30: Admin add name skips the control-char strip and block key/label have no length cap; a non-array flash cookie can throw; logout clears the session cookie without the flags it was set with.",
   "done_when": "Admin name is sanitised like the listener one, key/label capped, flash checked with is_array, logout cookie flags match."
  }
 ]
};
