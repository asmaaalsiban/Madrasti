# Third parties, licences and what leaves our server

Reviewed November 2026. Update this file whenever a dependency, a font, an image or an external service is added.

## What the app contacts outside our own server
| Service | Why | Data sent | Who turns it on |
|---|---|---|---|
| Payment provider (PayTabs today) | Card payments | The amount, the payer's name and the payment reference, from the server; the parent types the card on the provider's own page | Each school, with its own account |
| Email provider (`MAIL_*`) | Password reset, notices | The recipient's address and the message | The platform |
| Browser push services (the browser's own) | Notifications | An encrypted notification, only for people who switch them on | The user |

Nothing else: **no analytics, no advertising, no tracking, no social widgets, no external fonts, no CDN scripts.** The frontend makes requests only to our own `/api`.

## Fonts (self-hosted, never loaded from Google)
- **Nunito**, **Cairo**: SIL Open Font License 1.1 (via `@fontsource/nunito`, `@fontsource/cairo`). Free for commercial use; the licence text ships in `node_modules/@fontsource/*/LICENSE`.

## Icons and images
- **lucide-react**: ISC licence. The app's own logo, favicon and PWA icons are made for the project. School logos and photos are uploaded by the schools, who are responsible for having the right to use them.

## Main code dependencies (all permissive licences)
React, React Router, TanStack Query, Axios, i18next, Tailwind CSS (MIT); Laravel, Sanctum, stancl/tenancy (MIT).
Check with `npm ls` / `composer licenses` before each release for new or changed licences.
