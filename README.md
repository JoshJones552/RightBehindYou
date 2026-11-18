# Right Behind You

A PHP escape-room game for CSC 4370/6370, Topic 04: The PHP Vault. Find your way through three connected rooms, return to the first hall, and notice the exit that was there all along.

## Run locally

Requirements: PHP 8.1 or later with JSON, sessions, and mbstring enabled.

```sh
php -S 127.0.0.1:8000
```

Open <http://127.0.0.1:8000>. The first player creates an account. PHP writes accounts and escape records to `storage/`; keep that folder writable by the PHP process and outside the public web root. Use HTTPS in production.

## Gameplay

1. Register or sign in. Passwords are stored with PHP's `password_hash`.
2. Solve the clock clue, the greenhouse clue, and the archive clue in order.
3. The archive brings you back to the first hall and starts another loop.
4. On a later visit, use **Try the other way** to take the newly revealed exit.
5. The escape is recorded in the hall of echoes.

Puzzle answers for the project demonstration: `2412`, `ember`, and `home`.

## PHP features

- PHP sessions hold the player ID, current room, unlocked areas, attempt counters, loop count, moves, and run timing.
- Forms are validated on the server and protected by a session CSRF token.
- Puzzle submissions use `fetch` for an asynchronous response, with PHP enforcing room order and answers.
- The adaptive hint logic offers a more specific nudge after repeated incorrect answers.
- Registration and sign-in persist hashed passwords; logout clears the session.
- A JSON-backed leaderboard records successful escapes. It is intended for the class demonstration and a single-server deployment.

## Screens and transitions

| Screen | URL | Session behavior |
| --- | --- | --- |
| Welcome | `?page=home` | Links to account creation or sign-in |
| Register | `?page=register` | POST to `?action=register`; creates player session |
| Sign in | `?page=login` | POST to `?action=login`; restores player session |
| Room | `?page=game` | Reads `current_room`, `area_unlocked`, loop, and attempt state |
| Puzzle response | POST `?action=solve` | Validates CSRF and active room, updates session; returns JSON |
| Hall of echoes | `?page=leaderboard` | Reads saved escape records; handles an empty board |
| Escape ending | `?page=ending` | Shows result after the hidden exit is used |
| Sign out | `?action=logout` | Destroys the active session |

## Edge cases handled

- Wrong answer: the room remains locked and the player receives progressively more specific hints.
- Invalid sign-in, duplicate registration, short password, and invalid email: a clear form error is shown.
- Missing or expired session: protected screens redirect to sign-in; puzzle requests return an error.
- Empty leaderboard: an invitation to play appears in place of a table.
- Out-of-order room submission or stale CSRF token: the request is rejected.

## Deployment

Deploy the repository to a PHP 8.1+ host, enable HTTPS, and ensure the PHP process can write to `storage/`. The included `storage/.htaccess` blocks direct web access on Apache. Do not deploy this JSON store to a multi-instance/serverless platform; use a database for that setup.
