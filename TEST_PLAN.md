# Test Plan

The local PHP 8.5 run below was performed in a temporary directory so the checks did not create accounts in the working copy or on Codd.

| Case | Steps | Observed result |
|---|---|---|
| Correct room flow | Register; submit `2412`, `ember`, and `home`; return to the entry hall; take the left-wall exit. | PASS — PHP advanced through all rooms, returned to the entry hall, recorded 300 points and a time/score entry, showed the ending, and cleared the session leaderboard on logout. |
| Wrong-format and tiered hint | In Room 1 submit a word three times; then submit a number in Room 2. | PASS — PHP stayed in the active room, selected the digit-specific error and three progressively specific Room 1 hints, deducted 10/20/30 points, and gave a word-specific error in Room 2. |
| Registration validation | Submit a valid-length name, invalid email, and short password; inspect the re-rendered form. | PASS — the server returned a validation message and retained the safe name/email values; the password was not repopulated. |
| Protected route | Submit a puzzle answer without an authenticated session. | PASS — PHP redirected to the sign-in page. |

## Rule-based hint test cases

1. **Normal solve:** correct answer for the active room advances one room and awards 100 points the first time that room is cleared.
2. **Input-shape error:** a word in the numeric clock room returns a digits-specific message plus the current hint tier; the room does not advance.
3. **Repeated failure:** attempts one, two, and three select increasingly specific hints and apply 10, 20, and 30-point penalties; the current room stays active.

## Limits

The server normalizes case and whitespace but compares against one exact answer per room. The hint rule uses room number, attempt count, and basic input shape; it does not interpret alternate wording.
