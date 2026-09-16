# The fuentes follow their publishers, not the address they used to have

**Status: accepted, 2026-09-16.** Two of the three fuentes changed address. The host both of them
read is closed to every network, deliberately and since October 2025, and neither fuente is coming
back to it: MINSAL republished the in-force alerts elsewhere itself, and the informes were not
republished at all. This records what was measured before either move, and what each move costs —
because both costs are paid by a clinician, not by this app.

## The measurement: the previous source host is closed to everyone, deliberately

**403 from five independent vantage points**, measured 2026-09-15: this VPS (AS47583), a second and
unrelated datacentre network, a real headless Chromium engine from the same address as a failing PHP
client, the Internet Archive's crawler, and the owner's own computer on a Chilean residential
connection. Re-confirmed from this VPS on 2026-09-16, where the body is Cloudflare's own block page
— `Attention Required! | Cloudflare` — and not anything the site itself produced.

Five networks and three client stacks give one answer, so this is **not an egress problem**: no
proxy, no relay, no user agent and no residential exit is a fix, and a browser on the owner's desk
gets the same 403. That is what closed every option except moving.

**It is per-hostname, and it was staggered over months.** From this machine, in the same second, a
different host on the same estate answers 200 — `degreyd.minsal.cl`, which is where `alerts` reads
now. Sibling hosts went dark inside the same window; another held out until March–April 2026.
Whoever applied this applied it host by host, which is why "MINSAL is blocking us" is the wrong
reading and "that hostname is closed" is the right one.

**The Internet Archive dates the changeover.** Captures of that host, by year and status:

| year | 200 | 403 |
|---|---|---|
| 2024 | 779 | 15 |
| 2025 | 293 | 49 |
| 2026 | 2 | 88 |

Both 2026 "successes" are block pages recorded as 200, so there are **zero usable captures since
2025-10-11**. The window is tight: the last response that reached the origin is **2025-10-13
05:54 UTC**, the first 403 of the unbroken run **2025-10-19 11:18 UTC**. Anything that host published
after 2025-10-11 exists at no other address, in no archive, in no cache.

## Decision

**Each fuente reads from whoever publishes it today, and neither waits for the previous host.**

**`alerts` reads the page MINSAL publishes the in-force set on.** MINSAL moved the alerts itself, so
the app follows the publisher rather than the address. **13 alerts in force**, read from the anchors
of MINSAL's own five-column table and keyed by URL, because MINSAL links the same PDF from two rows
of it (`tests/unit/fixtures/degreyd-alertas.html`, a real capture taken 2026-09-15).

**Read as a page, and not over RSS — measured, then rejected.** RSS carries the ten most recent
posts and the alerts post already sits behind three news items, so one busy news week empties the
fuente with nothing wrong upstream and nothing a reader could act on. RSS is a window on what is
recent; this fuente needs a fixed address for one document. The `/wp-json/` route was rejected
separately and on a different ground: it is closed across MINSAL's estate, and the query form that
still answers on this host is the same interface reached by a spelling somebody missed. This app's
only alerts source should not rest on an oversight.

**`reports` reads the ISP's weekly *Circulación de Virus Respiratorios*, by semana epidemiológica.**
The previous publisher's informes were **not** moved anywhere: they have no reachable home and no
archive copy, and nothing in this app could conjure one. The ISP publishes the equivalent weekly
informe, is reachable, and is already a publisher this app reads — so the fuente changed publisher
instead of going dark. `Sources::name(REPORTS)` and `src/panes.js` now name the ISP, and the
navigation row moved under the ISP's caption, which is what
[ADR-0008](0008-nav-groups-do-not-collapse.md)'s group counts record.

**The intermediate certificate the ISP does not send ships in this repo.** `www.ispch.gob.cl` serves
its leaf alone, and PHP, curl and Guzzle do not chase the AIA extension the way a browser does.
`CertificateBundle` merges `certs/gsgccr6alphasslca2025.crt` with the instance's own anchors for the
ISP requests only; verification stays fully on and `verify => false` remains forbidden. Measured
from this VPS on 2026-09-16: `cURL error 60` against the instance's anchors alone, **200** against
the same anchors with that one file appended. A fresh install now works on any machine with no
provisioning step, which is what turned a one-instance manual import into a property of the app.

## What the two moves cost

**8 of the 13 alerts link a PDF that no longer resolves anywhere.** MINSAL's live page links them
back to the previous host, and no archive copy exists for anything published there after 2025-10-11.
By the owner's explicit decision the links render **exactly as MINSAL publishes them**, unmarked: the
app rehosts no state document and does not annotate what a ministry says. A clinician who clicks one
of those eight reaches a block page. This is the honest cost of the only reachable alerts source
there is, and it is recorded rather than hidden — the fixture carries all 8 occurrences precisely
because a capture that had been edited to pass a grep would be evidence about nothing.

**Two of the 13 were invisible at first, and nothing said so.** MINSAL's publisher pads two hrefs
with a trailing space *inside* the attribute quotes, which the anchor pattern required to be absent,
so the fuente held 11 of 13 and looked completely healthy — no estado degradado, no log line, no
count that could have been compared against anything. Both were Ébola (virus Bundibugyo) alerts. The
parser now tolerates the whitespace, as a browser does, and `warnOfMissedAnchors()` counts every
`href="…pdf"` in the raw document independently of that pattern, so a **shortfall between the links
present and the items parsed is a warning on day one** instead of a quiet undercount. The counter
that existed before could not have caught this: it counted URLs the regex captured and then
rejected, and these were never captured at all.

**The informe series has to accumulate, one week at a time.** The ISP publishes only the current
informe on its page, and its year archive renders client-side, so the back catalogue is not fetchable
by a server at all. `IspReportRepository` therefore keeps what it has seen, keyed by semana.
[ADR-0012](0012-the-archive-is-a-cache-and-any-app-update-empties-it.md) applies unchanged: this is a
cache, so any app update on the instance empties it and the series restarts from the current week.
Accepted, for the reason that ADR gives — the app has no database, and the current informe is the one
a clinician opens.

**Only the anchor's label separates an informe from the page furniture.** That page carries seven PDF
anchors and six satisfy the upload-folder rule that identifies a document elsewhere in this app: the
informe, plus two genotipificación summaries, a 2023 ordinario, an antiviral-resistance study and the
ISP's own code of ethics. So the label is the filter, and it is also the only place the semana
epidemiológica appears, which makes one match do both jobs. The filenames cannot be constructed
either: the ISP appends a version suffix when it reissues a week (`…SE35-08-09-2026-V3`) that no week
number yields.

**The shipped intermediate is a maintenance obligation, not a fix that stays fixed.** The ISP's leaf
expires **2026-11-23** (measured against the live certificate, 2026-09-16), and whoever reissues it
may chain it under a different intermediate — as happened on 2026-09-15, when a rotation broke every
ISP request on an instance whose imported certificate was still the previous issuer's. Nothing in
this app detects a rotation. `certs/README.md` carries the one command that re-derives the current
intermediate's URL from the live leaf's AIA extension, and `README.md` repeats it where an
administrator diagnosing an outage will look.

## Reopen when

- **MINSAL publishes its informes ETI/IRAG at a reachable address again**, which would give `reports`
  two candidates and make the publisher a choice rather than the only option — note the pane is named
  for the ISP's document, so this is a decision about which document a clinician gets, not a URL swap;
- **the ISP serves a complete chain**, at which point `certs/` and `CertificateBundle` are dead weight
  and deleting them is the whole change;
- **the ISP publishes its year archive server-side, or any bulk endpoint**, which retires the
  accumulation and with it this app's only fuente whose completeness depends on uptime;
- **the previous host answers again from more than one network** — and even then the alerts live
  where MINSAL publishes them now, so what would reopen is the 8 dead PDF links, not the address.
