# Google Play Data Safety + Permission Rationale (Working Draft)

Use this as the source text for Play Console declarations.

## Data usage summary

| Data Type | Collected | Purpose | Shared | Optional |
|---|---|---|---|---|
| Approx/Precise location | Yes (during active usage) | Live convoy map + coordination | Shared only with participants in same retreat | User can disable in-app |
| Personal info (name) | Yes | Participant identification in retreat | Shared only with participants in same retreat | Required for participation |
| Phone number | Yes (normalized to E.164) | Identity continuity across rejoin/device | Not publicly exposed; masked display only | Required for participation |
| User content (chat messages) | Yes | Group communication during retreat | Shared with participants in same retreat | Optional to send |
| Device/session identifiers | Yes | Session auth and reliability | Not sold/shared with third parties | Required for operation |

## Permission declarations

### Location (foreground)
- **Why:** keep participant marker updates visible on convoy map while app is active.
- **Scope:** foreground/while-in-use behavior in current build.
- **User control:** Profile → Unshare my location (immediate stop + location purge for current participant history).

### Notifications (optional)
- **Why:** convoy updates and leader emergency alerts.
- **Scope:** optional; app remains usable without notification permission.

## Account deletion compliance
- In-app path: `Profile → Delete account & data`
- API path: `DELETE /api/v1/retreat/account` with `confirm_delete=true`
- Public instructions URL: https://calvarycaravan.on-forge.com/account-deletion

## Retention notes
- Account deletion immediately removes participant profile, related messages, and location rows.
- Remaining retreat data (for non-deleted accounts) is removed after retreat lifecycle according to privacy policy.

## Owner confirmation needed in Play Console
- Data safety questionnaire entries must match current production behavior.
- App access/reviewer notes must include active test retreat code.
