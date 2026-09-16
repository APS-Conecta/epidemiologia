# Epidemiología

The context of surfacing public epidemiological information to a CESFAM: what MINSAL and the ISP
publish nationally. No patient data enters this context. These are the terms *this* app consumes.
**It does not fetch REM**, and no REM term is defined here — serie, corte, prevalencia, PIV,
compensación, población en control and the rest belong to whatever consumes REM, which is not
this app.

## What is published nationally

**Fuente** — one thing this app fetches, parses and caches, carrying its own freshness and its own
estado degradado: *alertas vigentes* from MINSAL, *informes de Circulación de Virus Respiratorios*
and *alertas sanitarias* from the ISP. Not an institution — the ISP publishes two — and not a class:
those same two have a repository each, because a class boundary follows the rules and sharing a host
is not sharing a rule (`Sources`). It is the unit everything outside a repository counts in — payload
key, sondeo failure count, the name a notificación carries. Conflating fuente with institución made
an ISP outage report itself as MINSAL. The **tablero** and **EPIVIGILA** are *not* fuentes: nothing
about them is fetched, parsed, cached or capable of going stale — they are links.
_Avoid_: feed, canal, origen — and **institución as a synonym for fuente**. Naming the institution is
correct; a result that does not say which one it came from is the defect this distinction prevents.

**Sondeo** — the daily check that each fuente can still be read and still parses. It exists to
**detect**, not to refresh; a warm cache is a side effect. Sole writer of the consecutive-failure
counts that both the notificación and Administración → Visión general read, so those two can be
incomplete but never contradict each other.
_Avoid_: sync, actualización, refresco

**Freno** — how long a repository declines to re-request one thing it fetches, after that thing
failed. Its grain is **what is requested** — one categoría of the ISP feed, one MINSAL page — finer
than a fuente: the ISP is one fuente behind 24 requests. Read by nothing outside its own repository,
and **not** the sondeo's failure count, which is per fuente and **written by the sondeo alone**. A **frenada request is not an unreachable one**:
if every request is braked the app has learned nothing, which is not an outage — but the *detector*
asks through the freno anyway, because reusing the reader's rule made the sondeo report a week-long
outage as silence (#75). A freno never makes a stale fuente look fresh, staleness being measured
from the last fetch.
_Avoid_: cooldown, backoff, reintento — and **fuente as its grain**

**Alerta vigente** — an oficio MINSAL currently in force, published as a PDF on a curated page.
Identified by its URL, the only stable identifier that page carries — and MINSAL links the same PDF
from two rows of its own table, so the URL is also what keeps one alerta from being shown twice.
_Avoid_: alerta, aviso, circular — note that *aviso* is avoided for **this document**; the
thing Nextcloud puts in the bell is a **notificación**, and the two are easy to blur

**Alerta sanitaria ISP** — a product-level notice from the Instituto de Salud Pública: recall,
falsification, pharmacovigilance, device withdrawal. A different kind of object from an alerta
vigente, never merged with one.
_Avoid_: alerta ISP, alerta ANAMED

**Día de publicación** — the day a fuente says it published something, rendered in **the
publisher's** timezone; the ISP's is `America/Santiago`. Not the server's, which is UTC here and
moved anything published after 20:00 in Chile to the next day (one alert of 173, measured
2026-08-05). Not the reader's either: Chile spans three timezones, and a recall does not change date
because the clinic is in Punta Arenas.
_Avoid_: fecha del sistema, fecha local

**Informe de Circulación de Virus Respiratorios** — the **ISP's** weekly surveillance report on the
circulation of respiratory viruses, published as a PDF and identified by its semana epidemiológica.
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

**Semana epidemiológica (SE)** — the numbered week an informe reports on. It does not map to the
calendar month the PDF is filed under, which records when the file was uploaded: measured on the
previous publisher's own uploads, SE 29 sat in the July folder and SE 24 in June. The ISP files the
same way, so the month a pane shows is decoration and never identity — an informe filed outside the
upload path is kept without a month rather than dropped.

**Tablero MINSAL** — the domain term for the Power BI report MINSAL publishes to the web and this app
embeds; the pane is named **Tablero ETI e IRAG** by `src/panes.js`, authoritative for what a reader
sees. Always current; it has no "latest".
_Avoid_: **Tablero** unqualified, dashboard, panel — all collide with Nextcloud's own Dashboard

**EPIVIGILA** — MINSAL's credentialed system for notifying Enfermedades de Notificación Obligatoria.
Reachable only from Red MINSAL, and linked from here rather than embedded.

**Archivo del ISP** — every alerta sanitaria this app has ever seen, accreted across the 24 feed
windows plus a backwards walk of two pages an hour. It is a **cache**, not a store: Nextcloud keys
the distributed cache by every enabled app's version, so any app update on the instance empties it
and the walk rebuilds it over about three days ([ADR-0012](docs/adr/0012-the-archive-is-a-cache-and-any-app-update-empties-it.md)).
_Avoid_: base de datos, histórico — nothing here is stored, and an *alerta sanitaria* never expires,
which is why the archive accretes rather than being replaced.

**Estado degradado** — a fetch returned zero items and the app is serving its last good copy
instead. A displayed state, not an error path: an empty list read as reassurance is the failure this
context most needs to avoid.
_Avoid_: error, caído, sin datos

## What this context deliberately has no word for

There is no term here for a locally measured disease rate, because no source produces one. REM
yields counts; prevalencia is a national constant; **incidencia has no REM source at this grain**.
Naming a derived figure "incidencia" or "prevalencia local" would assert a classification neither
MINSAL nor DEIS published — the same reason this context carries no severity levels and no disease
tags on alerts.

**Nothing here is organised by disease, and a pane that were would have to invent its own subject.**
None of the three fuentes publishes at that grain: an alerta vigente is an oficio, an alerta
sanitaria is a product notice, an informe de Circulación de Virus Respiratorios is one weekly PDF per
semana epidemiológica.
Grouping any of them under a disease means classifying documents by a term this context has no word
for — which is the paragraph above, arriving as layout instead of vocabulary. REM is not the way
round it either: it is not this app's to read, and it yields counts per corte, not a
per-disease view.
