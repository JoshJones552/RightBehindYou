# Presentation Notes

Use this as a fact sheet for the demo, then explain the code in your own words. The recording must include the actual team process and contributions; fill those details from real records rather than inventing them. Disclose Codex assistance accurately.

## Demo sequence

1. Show the public home screen and the field-log story.
2. Register or sign in, then submit `2412`, `ember`, and `home` in order.
3. Point out that the lift returns to the entry hall and the camera note reveals the left-wall exit.
4. Show the points, current session leaderboard, and logout clearing the session.
5. In a separate run, enter a wrong answer several times to show the PHP-selected hint tiers and score penalties.
6. Walk through `index.php`, `app.css`, and `README.md` in VS Code. No JavaScript or database is used.

## Five selected Q1–Q11 questions

### Q1 — PHP server-side logic

`index.php` routes requests, validates forms, updates the session, and renders the current screen. The `ROOMS` array stores the three room definitions; helper functions handle escaping, CSRF, hint selection, and session scores. One file keeps this small Codd deployment simple; the trade-off is that routing and templates share a file.

### Q2 — Form handling and data flow

The answer form posts to `?action=solve`. PHP reads the value with `filter_input()`, checks the CSRF token and current session room, normalizes the answer, and either returns feedback or advances the session. A redirect then renders the next screen, so refreshing does not repeat the POST.

### Q3 — Sessions and state

The session stores the signed-in player, current room, unlocked rooms, attempts, score, loop count, moves, and start time. Completed scores are added to `$_SESSION['scores']`; they are not saved to a persistent leaderboard file or cookie. Logout destroys the session.

### Q4 — Input validation and security

Registration checks the name length, email format, and password length on the server. Passwords use `password_hash()` and `password_verify()`. Puzzle posts check CSRF, input length, and active room; rendered player names and form values pass through `htmlspecialchars()`.

### Q10 — Architecture and file structure

A single PHP entry point handles routes and server-side game state; `app.css` holds the responsive interface; `storage/` holds only account records and has an Apache access block. Keeping these responsibilities in a small package fits Codd and avoids a database or JavaScript dependency. A future refactor could split routing and templates into PHP includes.

## Five required Q12 Implementation Defense answers

### D1 — Full feature trace

The player submits the labeled POST form in `index.php`. The `solve` handler checks the session and CSRF token, reads and normalizes the answer with `filter_input()`, compares it with the current room in `ROOMS`, applies the hint/score rule on a miss, or unlocks the room and redirects on a correct answer. The next request renders the updated room from session state.

### D2 — Bug discovery and fix

The earlier build set its data folder one level above the app, while the access-blocking `.htaccess` was inside the app's `storage/` folder. Account data could therefore be written outside the folder meant to protect it. The current `DATA_DIR` points directly to `__DIR__ . '/storage'`, so storage and its access rule now match. There was no useful first fix to preserve; the path itself was corrected and the local registration flow was rerun.

### D3 — Architecture trade-off

A multi-file controller/model/template layout was considered against one PHP router. The project uses one router because it is a small three-room app deployed in a single Codd folder; that keeps session and POST flow easy to follow. The trade-off is a longer `index.php`, which could later be split into includes without changing the session model.

### D4 — Data integrity scenario

An unauthenticated POST to the puzzle route redirects to sign-in. For a wrong answer, PHP increments only that room's attempt count, selects the matching feedback tier, deducts the penalty, and keeps the player in the same room. The local checks confirmed that a wrong-format answer does not unlock or advance the room.

### D5 — Fragile area and refactor plan

The flat-file account store is the most fragile area: separate requests could read and rewrite the account file at the same time. The next refactor would lock the complete read-modify-write operation and replace the file atomically, while retaining flat-file storage to match the course constraint. Verify it with duplicate and simultaneous registration attempts and confirm that both valid accounts remain present.

## Evidence still needed for the recording

- Add actual stand-up notes, sprint reviews, and hours from your real work history.
- Show the real GitHub commit history and use truthful team contribution details.
- Present the test cases in `TEST_PLAN.md` and disclose Codex use.
- Record 20–30 minutes total and answer all five D1–D5 questions plus 5–10 selected questions.
