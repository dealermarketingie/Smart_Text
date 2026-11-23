# Smart Text website

Marketing site for Smart Text, a customer engagement and database reactivation
platform for businesses in Ireland, the UK and the EU.

Plain HTML, CSS and vanilla JavaScript. No build step, no framework, no
dependencies. Open `index.html` in a browser and it runs.

## Files

| File | What it holds |
| --- | --- |
| `index.html` | The only HTML file. A shell of mount points; every page is a route rendered into it. |
| `app.js` | All content and logic: homepage, industry pages, pricing, agents, header and footer. |
| `header.js` | Mobile hamburger menu. Separate because it owns the header element that `app.js` re-renders. |
| `styles.css` | Every style for the whole site. |
| `content.md` | Standing copy rules. Read before editing any text. |
| `assets/` | Logo, client logos and videos. |

## Editing content

Nearly all copy lives in constants at the top of `app.js`:

- `INDUSTRY_DATA` drives the six industry pages. Each entry holds the banner,
  headline, use cases, sample messages for the popup form, and FAQs.
- `PLANS` drives the pricing page. Annual figures are stored as supplied rather
  than derived, so what is shown always matches billing: twelve months for the
  price of eleven on Smarter and Smartest, monthly-only on Smart.
- `STANDARD_FAQS` appear on every industry page, ahead of that industry's own.
- `PRICING_FAQ` appears on the pricing page.
- `FEATURES` drives the homepage accordion.
- `CLIENT_LOGOS` drives the logo carousel and its quotes.
- `SEED_AGENTS` seeds the agent map and list.

Read `content.md` before changing copy. The short version: Smart Text is
not a two-way messaging tool, it supports scheduling rather than automation,
the market is Ireland, the UK and the EU only, and no em dashes.

## Routing

Hash-based, handled in `applyHash()` at the bottom of `app.js`. Every page is a
route — none has its own file.

- `#` or anything unrecognised: homepage
- `#industry/automotive` and the other four keys in `INDUSTRY_DATA`
- `#pricing-plans`
- `#agents`
- `#used-by`, `#platform-features`, `#demo` and other section ids scroll the
  homepage to that section

Form submissions push `#thank-you` (`#thank-you-agent` for agent applications)
so they can be wired up as GA4 conversion destinations.

## Running locally

Opening `index.html` directly works. To serve it over HTTP instead:

```
python3 -m http.server 8000
```

Then open http://localhost:8000.

## Notes

- Leaflet, used by the agent map, is fetched from a CDN on demand when the
  `#agents` route renders, so it does not load on any other page.
- Agent edits made in the admin panel are saved to that browser's
  localStorage only. There is no shared backend — to change what every
  visitor sees, edit `SEED_AGENTS` and redeploy.
- The Agent Portal password is a soft client-side gate. It ships in `app.js`
  to every visitor and is not real security.
- Use case and banner images are hotlinked from Unsplash rather than committed
  to the repo.
- The Book a Demo enquiry form posts to `api/contact.php` for SMTP delivery.
- Sample SMS and agent application forms remain front-end only.

## Contact email setup

The contact form requires PHP 8.1+ with OpenSSL and mbstring. For local development:

```
php -S 127.0.0.1:8000 -t .
```

Open http://127.0.0.1:8000. Python's static server cannot process the contact endpoint.

Copy `config/mail.example.php` to `config/mail.local.php` and fill in the SMTP
host, port, encryption (`tls` for STARTTLS or `ssl` for SMTPS), username,
password, authorised sender address/name and receiving inbox. The local file is
ignored by Git and refuses direct web requests. Do not put backup copies of
credentials under the website directory. Production hosting must execute PHP
and deny access to config, lib, Git metadata and other non-public files.

PHPMailer is included under `lib/phpmailer`, with its licence and source commit.
SMTP certificate verification remains enabled. The visitor's email is Reply-To;
the sender stays the configured, authorised address. Success means the SMTP
server accepted the message, not that inbox delivery has been confirmed.

For local testing, run Mailpit and set `transport` to `mailpit` in
`config/mail.local.php`. This forces SMTP to `127.0.0.1:1025` without authentication
or encryption, matching the Smart Events local setup. SMTP credentials and CA
settings are ignored in this mode. The local sender defaults to
`hello@example.com` / `Smart Text`, configurable through `mailpit_from_email`
and `mailpit_from_name`. View captured messages at http://127.0.0.1:8025.
Mailpit should be used without a relay so messages stay local.

For production, set `transport` to `smtp` and use the production SMTP settings
(SSL on port 465). The saved production fields are preserved when Mailpit is
selected. Leave `ca_file` blank on production unless the host requires an
explicitly approved CA bundle. Local Norton certificate bundles are unused by
Mailpit and should not be transferred to production.


The endpoint validates fields and consent, uses a session token, a honeypot and
a 30-second session cooldown. Before public launch, add server-verified hCaptcha
and persistent IP-based rate limiting; the session cooldown alone does not stop
an attacker creating new sessions. The old site's hCaptcha keys are not included.

Test with a designated inbox first. Check receipt, Reply-To, non-ASCII names,
invalid submissions and SMTP failure behaviour. Missing settings return an error
and leave the entered details intact. No customer database or SMS service is
used by this endpoint.
