# Epidemiología

The context of surfacing public epidemiological information to a CESFAM: what MINSAL and the ISP
publish nationally. No patient data enters this context. These are the terms *this* app consumes.
**It does not fetch REM**: [ADR-0001](docs/adr/0001-analizador-rem-owns-rem.md) gives that to
`analizador_rem`, whose `docs/GLOSARIO.md` is authoritative for serie, corte, prevalencia, PIV,
compensación, población en control and every other REM term. Read them there, not here.

## What is published nationally

**Fuente** — one thing this app fetches, parses and caches, carrying its own freshness and its own
estado degradado: *alertas vigentes* and *informes ETI/IRAG* from MINSAL, *alertas sanitarias* from
the ISP. Not an institution — MINSAL publishes two — and not a class: those same two share one
repository. It is the unit everything outside a repository counts in — payload key, sondeo failure
count, the name a notificación carries. Conflating fuente with institución made an ISP outage report
itself as MINSAL. The **tablero** and **EPIVIGILA** are *not* fuentes: nothing about them is fetched,
parsed, cached or capable of going stale — they are links.
_Avoid_: feed, canal, origen — and **institución as a synonym for fuente**. Naming the institution
is correct and necessary; a result that does not say which one it came from is the defect this
distinction prevents.

**Sondeo** — the daily check that each fuente can still be read and still parses. It exists to
**detect**, not to refresh; a warm cache is a side effect. Sole writer of the consecutive-failure
counts that both the notificación and Administración → Visión general read, so those two can be
incomplete but never contradict each other.
_Avoid_: sync, actualización, refresco

**Freno** — how long a repository declines to re-request one thing it fetches, after that thing
failed. Its grain is **what is requested** — one categoría of the ISP feed, one MINSAL page — finer
than a fuente: the ISP is one fuente behind 24 requests. Read by nothing outside its own repository,
and **not** the sondeo's failure count, which is per fuente and written by the sondeo alone. A
**frenada request is not an unreachable one**: if every request is braked the app has learned
nothing, which is not an outage — but the *detector* asks through the freno anyway, because reusing
the reader's rule made the sondeo report a week-long outage as silence (#75). A freno never makes a
stale fuente look fresh, staleness being measured from the last fetch.
_Avoid_: cooldown, backoff, reintento — and **fuente as its grain**

**Alerta vigente** — an oficio MINSAL currently in force, published as a PDF on a curated page.
Identified by its URL: MINSAL reassigns numeric ids when it republishes.
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

**Informe ETI/IRAG** — the MINSAL surveillance report on influenza-like illness and severe acute
respiratory infection, published weekly as a PDF and identified by its semana epidemiológica.
_Avoid_: reporte, boletín, informe semanal

**Semana epidemiológica (SE)** — the numbered week an informe reports on. It does not map to the
calendar month the PDF is filed under: SE 29 sits in the July folder, SE 24 in June.

**Tablero MINSAL** — the domain term for the Power BI report MINSAL publishes to the web and this
app embeds; the pane that shows it is named **Tablero ETI e IRAG** by `src/panes.js`, which is
authoritative for what a reader sees. Always
current; it has no "latest".
_Avoid_: **Tablero** unqualified, dashboard, panel — all collide with Nextcloud's own Dashboard

**EPIVIGILA** — MINSAL's credentialed system for notifying Enfermedades de Notificación Obligatoria.
Reachable only from Red MINSAL, and linked from here rather than embedded.

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
