# Epidemiología

The context of surfacing public epidemiological information to a CESFAM: what MINSAL and the ISP
publish nationally, and what the OPS/OMS publishes for the Americas. No patient data enters this context. These are the terms *this* app consumes.
**It does not fetch REM**, and no REM term is defined here — serie, corte, prevalencia, PIV,
compensación, población en control and the rest belong to whatever consumes REM, which is not
this app.

## What is published

**Fuente** — one thing this app fetches, parses and persists: the cache is its hot path, the table behind it the durable copy, and each carries its own freshness and its own estado degradado. Seven: the *alertas vigentes* on two of MINSAL's pages, the ISP's *informes de Circulación de Virus Respiratorios* and three of its alert categories, and the OPS/OMS's *alertas y actualizaciones epidemiológicas* (`Sources::ALL`). Not an institution and not a class: the six alert fuentes run through one pipeline, one instance each, and the informe keeps a class of its own, because a class boundary follows the rules and the informe publishes under one the alertas do not — a series of weekly documents, each kept. It is the unit everything outside a repository counts in — payload key, sondeo failure count, the name a notificación carries. Conflating fuente with institución made an ISP outage report itself as MINSAL. The **tablero** and **EPIVIGILA** are *not* fuentes: nothing about them is fetched, parsed, cached or capable of going stale — they are links.
_Avoid_: feed, canal, origen — and **institución as a synonym for fuente**. Naming the institution is
correct; a result that does not say which one it came from is the defect this distinction prevents.

**Sector** — an alert fuente as a reader meets it: its pane, its row in the navigation and its tile on
Inicio, named by `src/panes.js`. Six, one per alert fuente. The informe has a pane but is not a
sector: it is a series of informes, not a list of alertas.
_Avoid_: **sector as a synonym for fuente** — the fuente is what is fetched, the sector is where it is
read, and the two are counted differently

**In-force set / stream** — the two ways an alert fuente publishes, and so the two ways it is kept. An
**in-force** page (degreyd's, epi's) names what is in force today: its set is replaced whole, and an
alerta vanishes when the page stops naming it. A **stream** (the ISP's three categories, the OPS/OMS's
list) shows only its latest posts: every item it ever showed is kept, and its two older pages are
walked once for what the first read could not see. `Fuente::$backfill` is what tells them apart.
_Avoid_: **archive** — it retired with the ISP's twenty-four-feed archive (ADR-0016), and a stream keeps
what it read, not its publisher's back catalogue

**Sondeo** — the daily check that each fuente can still be read and still parses. It exists to **detect**, not to refresh; a warm cache is a side effect. Sole writer of the consecutive-failure counts that both the notificación and Administración → Visión general read, so those two can be incomplete but never contradict each other. Neither job that reads the fuentes is the sondeo: the hourly **warm pass** (`WarmJob`) keeps an unopened install fresh and the nightly **deep pass** (`RefreshJob`) walks the back catalogues, and neither writes those counts — detection, its schedule and its counts stay here.
_Avoid_: sync, actualización, refresco

**Freno** — how long a repository declines to re-request one thing it fetches, after that thing failed or its host answered no: a 4xx/5xx is an answer, not a challenge, and arms the brake; a timeout or a TLS fault carries no answer and keeps the cooldown, because those lift on their own. Its grain is **the host**: everything one host serves answers to one brake — the ISP's informe and its three alert feeds share www.ispch.gob.cl's, while degreyd, epi.minsal.cl and the OPS/OMS each brake alone — so a host that said no is not re-asked for a sibling. Read by nothing outside the repositories that share the host, and **not** the sondeo's failure count, which is per fuente and **written by the sondeo alone**. A **braked request is not an unreachable one**: if every request is braked the app has learned nothing, which is not an outage — but the *detector* asks through the freno anyway, because reusing the reader's rule made the sondeo report a week-long outage as silence (#75), and the hourly warm pass rides the reader's rule for the same reason: it never re-asks a host that said no. A freno never makes a stale fuente look fresh, staleness being measured from the last fetch.
_Avoid_: cooldown, backoff, reintento — and **fuente as its grain**

**Alerta vigente** — an oficio MINSAL currently in force, published as a PDF on one of two curated
pages: degreyd's, and the Departamento de Epidemiología's at epi.minsal.cl, which files 77 where
degreyd lists 13. Identified by its URL within its fuente, the only stable identifier either page
carries — and MINSAL links the same PDF from two rows of its own table, so the URL is also what keeps
one alerta from being shown twice. The same PDF on both pages is one alerta on each.
_Avoid_: alerta, aviso, circular — note that *aviso* is avoided for **this document**; the
thing Nextcloud puts in the bell is a **notificación**, and the two are easy to blur

**Día de publicación** — the day a fuente says it published something, rendered in **the publisher's** timezone; the ISP's is `America/Santiago`, and the OPS/OMS's is the offset its own `datetime` carries. Not the server's, which is UTC here and moved anything published after 20:00 in Chile to the next day (one alert of 173, measured 2026-08-05). Not the reader's either: Chile spans three timezones, and an alerta vigente does not change date because the clinic is in Punta Arenas. The year of an informe's semana epidemiológica derives from this day — the publisher's zone decides which year a December-31 publication belongs to, and a URL decides nothing.
_Avoid_: fecha del sistema, fecha local

**Informe de Circulación de Virus Respiratorios** — the **ISP's** weekly surveillance report on the
circulation of respiratory viruses, published as a PDF and identified by its semana epidemiológica.
The app keeps one copy of each document — downloaded once, hashed, served from the instance — and a
revision replaces the copy rather than re-presenting it as new; the publisher's address stays on the
row as provenance.
One name, the publisher's own: it is what the pane is called (`src/panes.js`) and what the app calls
the fuente mid-sentence and in a search result (`Sources::name`, `Sources::kind`). *ETI/IRAG* is the
**previous publisher's** framing for a document this app no longer reads — MINSAL's own informes were
not moved anywhere when this fuente changed publisher and have no reachable home
([ADR-0014](docs/adr/0014-the-fuentes-follow-their-publishers.md)) — so the acronym is kept here to
stay findable, not as a second live name. It is not the **tablero**, which is MINSAL's and *does*
keep ETI e IRAG in its name because that report really is MINSAL's; carrying the acronym on both
made two series from two publishers read as one.
_Avoid_: informe ETI/IRAG, reporte, boletín, **informe semanal** unqualified — the fuente's name
says *semanal* before the publisher's title, never instead of it

**Tipo** — how the OPS/OMS names its own publication, in the first words of its title: *Alerta
epidemiológica*, *Actualización epidemiológica*, or neither (*Otra publicación*). Read, never assigned:
it is the publisher's word for a document, not a severity, which this context still has no word for.

**Semana epidemiológica (SE)** — the numbered week an informe reports on. It does not map to the calendar month the pane groups by, which derives from the publication day: measured on the previous publisher's own uploads, SE 29 sat in a July that SE 24 spent in June. The month is decoration and never identity — the pair **(year, semana)** is the identity, and a reissue replaces its own week, never a third row.

**Tablero MINSAL** — the domain term for the Power BI report MINSAL publishes to the web and this app
embeds; the pane is named **Tablero ETI e IRAG** by `src/panes.js`, authoritative for what a reader
sees. Always current; it has no "latest".
_Avoid_: **Tablero** unqualified, dashboard, panel — all collide with Nextcloud's own Dashboard

**EPIVIGILA** — MINSAL's credentialed system for notifying Enfermedades de Notificación Obligatoria.
Reachable only from Red MINSAL, and linked from here rather than embedded.

**Estado degradado** — a fetch failed or returned zero items and the app is serving its last good copy instead, from the cache or, after a flush, from the table behind the fuente. A fuente never yet read is degraded with nothing to serve, and shows the warning alone — never *Sin alertas*, which is what a fuente that answered with nothing says. A displayed state, not an error path: an empty list read as reassurance is the failure this context most needs to avoid.
_Avoid_: error, caído, sin datos

## What this context deliberately has no word for

There is no term here for a locally measured disease rate, because no source produces one. REM
yields counts; prevalencia is a national constant; **incidencia has no REM source at this grain**.
Naming a derived figure "incidencia" or "prevalencia local" would assert a classification neither
MINSAL nor DEIS published — the same reason this context carries no severity levels and no disease
tags on alerts.

**Nothing here is organised by disease, and a pane that were would have to invent its own subject.**
**No fuente publishes at that grain**: an alerta vigente is an oficio, an ISP alert a notice about a medicine or a product, an OPS/OMS publication a dated document, and an informe de Circulación de Virus Respiratorios one weekly PDF per semana epidemiológica.
Grouping any of them under a disease means classifying documents by a term this context has no word
for — which is the paragraph above, arriving as layout instead of vocabulary. REM is not the way
round it either: it is not this app's to read, and it yields counts per corte, not a
per-disease view.
