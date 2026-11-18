# Right Behind You

A PHP Vault escape-room game about a video vlogger who hides from a storm inside an abandoned ranger station in the woods. The station loops back on itself: the archive lift returns the vlogger to the entry hall, where the exit is finally noticed on the left wall.

## Run locally

Requirements: PHP 8.1+ with sessions, JSON, and mbstring enabled. No JavaScript, database, external AI service, or build step is used.

```sh
php -S 127.0.0.1:8000
```

Open <http://127.0.0.1:8000>. Create a field log or sign in. PHP stores password-hashed accounts in `storage/users.json`; keep the `storage/` directory writable by PHP. The included `storage/.htaccess` blocks direct access on Apache. Game state and leaderboard entries live only in the PHP session and clear when the player logs out.

## The run

1. **Storm Entry:** a four-slot lock uses the hours in a day followed by the stopped clock's hour: `2412`.
2. **Glasshouse:** a warm planter points to the glow left after a flame: `ember`.
3. **Ranger Archive:** the lift asks where the vlogger wants to return: `home`.
4. The lift opens back at the entry hall. The player has re-entered the station's loop and can notice the exit on the left wall.

## PHP Vault features

- Three puzzle forms submit with POST. PHP validates the CSRF token, input length, active room, and answer; only a correct answer advances the room.
- PHP sessions hold authentication, current room, room unlocks, attempts, score, loop count, moves, start time, and the current-session leaderboard.
- Each newly solved room awards 100 points. A wrong answer costs 10, 20, then 30 points by attempt tier, with a floor of zero. The final score and time are recorded when the exit is taken.
- The leaderboard ranks the top 10 scores for the current browser session, breaking ties by time and then loops. It is visible without re-login while that session exists; logout clears it.
- Registration and login use flat-file account storage with `password_hash()` and `password_verify()`. Game and ending pages require an authenticated session; logout destroys it.
- HTML forms use server-side validation, sticky name/email values on registration errors, clear feedback, escaped output, and CSRF protection.

## Rule-based hint feature

The PHP Vault baseline is implemented as a transparent decision rule. PHP looks at the active room, the current attempt number, and whether the guess has the expected input shape. It selects a room-specific error message and one of three increasingly specific hints. The hint tier also sets the score penalty: 10 points on the first miss, 20 on the second, and 30 on the third and later misses. A correct answer unlocks the room and awards its points.

This is a small deterministic rule, not a language model. Its limit is that it compares normalized text to one exact answer and cannot understand alternate phrasings. Test cases and observed results are recorded in `TEST_PLAN.md`.

## Project card

The **Project** link in the site contains the project leader, title, topic, and responsibilities.

## AI-use disclosure

OpenAI Codex assisted with PHP implementation, interface copy, CSS, and project documentation. The project owner should review the code and accurately disclose this assistance in the presentation and final report. This README does not claim that AI-created work is unaided original work.
