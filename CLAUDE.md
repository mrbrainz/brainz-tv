# brainz-tv

Lightweight single-page PHP app. No framework, no Composer, no build step.

## Layout
- `public/index.php` — the whole app (entry point, docroot).
- `src/admin.php` — admin dashboard (`?view=admin`), actions via `?api=admin-action`.
- `src/overlay.php` — OBS overlay page (`?view=overlay&token=...`).
- `src/Auth.php` — admin login, CSRF, login throttling. Hash: `php tools/hash-password.php`.
- `src/Queue.php` — queue state (`now`, `queue`) on top of Store.
- `src/YouTube.php` — parse YouTube URL/ID, check via oEmbed.
- `src/Store.php` — locked JSON file storage (no DB). Data lives in `data/` (gitignored).
- `tests/*.php` — plain PHP test scripts: `php tests/store_test.php`, `php tests/queue_test.php`, `php tests/auth_test.php`, `php tests/youtube_test.php [--live]`.
- `docs/obs-setup.md` — OBS setup notes for the user.
- `docs/backlog.js` — every to-do, as tickets. Board: `docs/backlog.html`.
- `tools/backlog.mjs` — backlog CLI (Node, no deps).

## Run
`php -S localhost:8000 -t public`

## Work loop
1. `node tools/backlog.mjs list` → pick the lowest-order `Ready` ticket whose depends are done.
2. Read only that ticket (`node tools/backlog.mjs show ID`) and its `read` refs.
3. Set it `In progress`, do it, then set `Done` with a dated `note` (what changed, how verified).
4. `node tools/backlog.mjs sync` (archives closed tickets), `check` before committing.

## Rules
- Every to-do lives in `docs/backlog.js`, nowhere else (not in code comments or this file). New work = new ticket.
- Ticket fields, in order: `id, order, phase, size, status, depends, read, title, description, done_when` (+ optional `note`).
  Status: Backlog, Ready, In progress, Blocked, Done, Won't fix. Size: XS <1h, S half day, M 1–2 days, L several days, XL split it.
- Don't read `docs/archive/` unless you need a closed ticket's detail.
- Keep this file short: rules and current state only. History belongs in ticket `note`s and git log, not here.

## Working lean with tokens
- Read only what the ticket needs: its `read` refs and the files you'll change. No exploratory sweeps of the repo.
- Read large files with offset/limit or grep for the part you need; never re-read a file you just wrote or edited.
- Keep command output small: `| tail -n 5`, `grep -c`, `--quiet`/`-q` flags. Never dump whole logs, JSON blobs or HTML responses; print status codes or one matching line.
- Batch independent tool calls in one message; chain related shell steps in one command.
- Prefer small targeted edits over rewriting whole files.
- No subagents unless the user asks.
- Keep replies short: what changed, how it was verified, what's next. No restating the plan or the code.
- Ticket `note`s: 1–3 sentences (what changed, how verified, limits). No narration.

## Status (max 3 lines; replace, never append)
- Next: B13 deploy to Krystal, then B14 setup notes. Done: B02–B12, B15, B16.
- Local setup: `cp config.example.php config.php`.
