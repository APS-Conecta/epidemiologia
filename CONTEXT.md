# Epidemiología

The context of surfacing public epidemiological information to a CESFAM: what MINSAL and the ISP
publish nationally, and what this establishment's own REM figures say about the conditions it treats.
No patient data enters this context.

Terms are grouped by where the facts come from, because the two halves have different owners and
different failure modes.

**Sources of truth for the REM half**, in precedence order:

1. `Manual Resumen Estadístico (REM) 2026 SERIE P`, DEIS/MINSAL, v1.1 — and the Serie A/BS/BM/D
   manual beside it. Each sección of each hoja is defined there in three fixed parts:
   *definiciones conceptuales* (what the datum means), *definiciones operacionales* (how it is
   recorded and in which cell), and *reglas de consistencia interna*. Rules come from there, not
   from inference.
2. `analizador_rem` — the app that **owns** the REM context. Its `docs/GLOSARIO.md` is authoritative
   where it overlaps with what follows.

The definitions below are the ones *this* app consumes.

## What is published nationally

**Alerta vigente**:
An oficio MINSAL currently in force, published as a PDF on a curated page. Identified by its URL —
MINSAL reassigns numeric ids when it republishes.
_Avoid_: alerta, aviso, circular

**Alerta sanitaria ISP**:
A product-level notice from the Instituto de Salud Pública — recall, falsification, pharmacovigilance,
device withdrawal. A different kind of object from an alerta vigente and never merged with one.
_Avoid_: alerta ISP, alerta ANAMED

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

**REM**:
Resumen Estadístico Mensual — "registros agrupados que son parte fundamental de las estadísticas
sanitarias de Chile". Aggregates only: no patient identifiers, no diagnoses.

**Serie**:
One official REM instrument. **Serie A** is monthly production; **Serie P** is the instrument for
Población en Control and is **semestral**. The two are never summed together.

**Corte**:
The date a Serie P figure refers to — **junio or diciembre**. The manual is explicit that data are
collected at those two cortes and *loaded* during **julio and enero**, so a corte is not published
in the month it describes.
_Avoid_: período, mes, fecha de medición

**Población en Control**:
People in active control by a programme at the corte. A **stock**: never summed across cortes, and
not a count of cases, of patients, or of attendances. The manual uses this phrase in every sección
title; its introduction says *Población Bajo Control* for the same thing, and `analizador_rem`
abbreviates it **PBC**.
_Avoid_: pacientes, casos, prevalencia, población atendida

**Prevalencia**:
The national figure by age band from the Encuesta Nacional de Salud, fixed by decree. An external
constant applied to a denominator — never something this establishment measures or derives from its
own figures.
_Avoid_: "nuestra prevalencia", prevalencia local, tasa

**PIV**:
Población Inscrita Validada by FONASA — the external denominator. Not part of REM, and no norm fixes
it; it is supplied.
_Avoid_: población inscrita, per cápita, padrón

**Compensación**:
The manual's term for a person under control meeting the programme's clinical target — P4 Sección B
is *Metas de Compensación*. A compensated person is a subset of the Población en Control, never a
separate population.
_Avoid_: controlado, compensado sano, en meta

**Cobertura efectiva**:
Compensated Población en Control over the population *estimated by prevalencia* (PIV × %).
Explicitly not over Población en Control — using it as its own denominator measures something else.

**Código de prestación**:
The DEIS activity code carried by every REM figure. The only machine-readable anchor from a number
to what that number counts, and unique per (hoja, sección) rather than per hoja.
_Avoid_: código, diagnóstico — REM carries no CIE-10 and no diagnosis dimension

**Establecimiento**:
The clinic an instance belongs to, identified by its DEIS code from the establishment list DEIS
republishes weekly. **It is configuration, never a constant** — one establishment per instance,
supplied at install. This instance is CESFAM Los Castaños, `114302`, La Florida, SSMSO; another
clinic is another instance, not another option in a menu.
_Avoid_: centro, consultorio, sede, "nuestro CESFAM" in code

## What this context deliberately has no word for

There is no term here for a locally measured disease rate, because no source produces one. REM
yields counts; prevalencia is a national constant; **incidencia has no REM source at this grain**.
Naming a derived figure "incidencia" or "prevalencia local" would assert a classification neither
MINSAL nor DEIS published — the same reason this context carries no severity levels and no disease
tags on alerts.
