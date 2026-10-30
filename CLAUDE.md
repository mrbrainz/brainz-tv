# brainz-tv

Lightweight single-page PHP app. No framework, no Composer, no build step.

## Layout
- `public/index.php` — the whole app (entry point, docroot).
- `src/Store.php` — locked JSON file storage (no DB). Data lives in `data/` (gitignored).
- `tests/*.php` — plain PHP test scripts: `php tests/store_test.php`.
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
- Keep this file short: rules and current state only.

## Status
- 2026-10-30: B02 routing/config and B03 storage done. Next: B04 YouTube validation. Local setup: cp config.example.php config.php.
