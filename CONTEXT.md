# Epidemiología

The context of surfacing public epidemiological information to a CESFAM: what MINSAL and the ISP
publish nationally. No patient data enters this context.

The definitions below are the ones *this* app consumes: what MINSAL and the ISP publish. REM belongs
to `analizador_rem` and its `docs/GLOSARIO.md` is authoritative for every REM term — see the section
at the bottom, and [ADR-0001](docs/adr/0001-analizador-rem-owns-rem.md).

## What is published nationally

**Fuente**:
One thing this app fetches, parses and caches, carrying its own freshness and its own estado
degradado: *alertas vigentes* and *informes ETI/IRAG* from MINSAL, *alertas sanitarias* from the ISP.
A fuente is **not an institution** — MINSAL publishes two of them — and **not a class**: those same
two share one repository. It is the unit that everything outside a repository counts in: the payload
other apps read is keyed by fuente, the sondeo keeps one failure count per fuente, and a notificación
names the fuente that failed. Treating "fuente" and "institución" as the same thing is what made an ISP
outage report itself as MINSAL.
The **tablero** and **EPIVIGILA** are *not* fuentes: nothing about them is fetched, parsed, cached or
capable of going stale. They are links.
_Avoid_: feed, canal, origen — and **institución as a synonym for fuente**. Naming the
institution itself is correct and necessary: MINSAL and the ISP are institutions, and a result that
does not say which one it came from is the defect this distinction exists to prevent.

**Sondeo**:
The daily check that each fuente can still be read and still parses. It exists to **detect**, not to
refresh — a warm cache is a side effect, which is why it earns its place where a cache-warming job
would not. It is the only writer of the consecutive-failure counts that both the notificación
and Administración → Visión general read, so those two can be incomplete but never contradict each other.
_Avoid_: sync, actualización, refresco

**Freno**:
How long a repository declines to re-request one thing it fetches, after that thing has failed. Its
grain is **what is requested** — one categoría of the ISP feed, or a MINSAL page — which is finer
than a fuente: the ISP is one fuente behind 24 requests. It counts *fetch* failures, it is written on
every read, and it decides one thing only: skip this request now.

It is **not** the sondeo's failure count and must never be merged with it. That count is per fuente,
counts daily probe failures, is written by the sondeo alone, and is read by the notificación and by
Administración → Visión general. The freno is read by nothing outside its own repository and reaches
no screen. Keeping them apart is what lets the sondeo remain the only writer of the count two
surfaces agree on — the invariant above — while a single category can still back off on its own.

Two consequences follow and are load-bearing. A **frenada request is not an unreachable one**: if
every request were braked the app has learned nothing, which is not an outage, and it must not report
one on that basis. And a freno never makes a stale fuente look fresh — staleness is measured from
when data was last fetched, so a braked fuente still reports itself degraded once it ages out.
_Avoid_: cooldown, backoff, reintento — and **fuente as its grain**

**Alerta vigente**:
An oficio MINSAL currently in force, published as a PDF on a curated page. Identified by its URL —
MINSAL reassigns numeric ids when it republishes.
_Avoid_: alerta, aviso, circular — note that *aviso* is avoided for **this document**; the
thing Nextcloud puts in the bell is a **notificación**, and the two are easy to blur

**Alerta sanitaria ISP**:
A product-level notice from the Instituto de Salud Pública — recall, falsification, pharmacovigilance,
device withdrawal. A different kind of object from an alerta vigente and never merged with one.
_Avoid_: alerta ISP, alerta ANAMED

**Día de publicación**:
The day a fuente says it published something, rendered in **the publisher's** timezone — the ISP's
is `America/Santiago`. Not the server's, which is UTC here and moved anything published after 20:00
in Chile to the next day (one alert of 173, measured 2026-08-05). Not the reader's either: Chile
spans three timezones, and a recall does not change date because the clinic is in Punta Arenas.
_Avoid_: fecha del sistema, fecha local

**Informe ETI/IRAG**:
The MINSAL surveillance report on influenza-like illness and severe acute respiratory infection,
published weekly as a PDF and identified by its semana epidemiológica.
_Avoid_: reporte, boletín, informe semanal

**Semana epidemiológica (SE)**:
The numbered week an informe reports on. It does not map to the calendar month the PDF is filed
under — SE 29 sits in the July folder, SE 24 in June.

**Tablero MINSAL**:
The Power BI report MINSAL publishes to the web and this app embeds. Always current; it has no
"latest".
_Avoid_: **Tablero** unqualified, dashboard, panel — all collide with Nextcloud's own Dashboard

**EPIVIGILA**:
MINSAL's credentialed system for notifying Enfermedades de Notificación Obligatoria. Reachable only
from Red MINSAL, and linked from here rather than embedded.

**Estado degradado**:
The condition where a fetch returned zero items and the app is serving its last good copy instead. A
displayed state, not an error path — an empty list read as reassurance is the failure this context
most needs to avoid.
_Avoid_: error, caído, sin datos

## What this establishment reports

**This app does not fetch REM.** [ADR-0001](docs/adr/0001-analizador-rem-owns-rem.md) gives it to
`analizador_rem`, and that repo's glossary is authoritative for every REM term — serie, corte,
prevalencia, PIV, compensación, población en control and the rest. Seventy-four lines defining them
lived here and described data this app never reads; they were removed in #54 rather than left to
drift out of step with the app that owns them.

Read them there before using any of those words here.

## What this context deliberately has no word for

There is no term here for a locally measured disease rate, because no source produces one. REM
yields counts; prevalencia is a national constant; **incidencia has no REM source at this grain**.
Naming a derived figure "incidencia" or "prevalencia local" would assert a classification neither
MINSAL nor DEIS published — the same reason this context carries no severity levels and no disease
tags on alerts.
