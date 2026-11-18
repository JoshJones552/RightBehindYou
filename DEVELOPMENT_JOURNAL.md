# Development Journal

This journal records the work completed on the project and the design decisions made during the Codex-assisted implementation. Add the student's own work sessions and dates before final submission; do not use it as a substitute for actual stand-up notes or sprint records.

## November 18, 2026 — Story and implementation alignment

- Reframed the game around a vlogger sheltering from a storm in an abandoned ranger station in the woods.
- Connected the three clue answers to that setting: the stopped clock, the last glow in the greenhouse, and the lift's `home` destination.
- Made the archive lift return the vlogger to the entry hall, where the left-wall exit becomes available.
- Changed the art direction to an original hand-drawn storm scene with a camera recording indicator and field-log notes.

## November 18, 2026 — Rubric alignment

- Removed JavaScript puzzle handling and changed answers to standard PHP POST forms.
- Moved account storage into the app's protected `storage/` folder and kept leaderboard scores only in PHP sessions.
- Added room-specific tiered hints, attempt tracking, score penalties, a session-based top-10 leaderboard, and score/time entries on escape.
- Added a project card, README, test plan, and presentation notes.

## Decisions and limitations

- One PHP router keeps the small app easy to deploy on Codd; the trade-off is that routing, state handling, and templates share a file.
- User accounts use a flat JSON file because the course allows flat-file storage. The game state and leaderboard remain session-only.
- Hint selection is deterministic PHP logic using room, attempt tier, and input shape. It does not understand alternate answers or natural-language paraphrases.
- AI tools helped produce code, interface copy, styling, and documentation. The final presentation should disclose this accurately.
