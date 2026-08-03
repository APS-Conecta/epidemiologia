# We do not rasterise PDFs

Showing a thumbnail of each ETI/IRAG informe's first page was designed and then dropped, because the
runtime image cannot do it. **The official `nextcloud:34-apache` image ships the `imagick` PHP
extension but no Ghostscript**, and ImageMagick delegates PDF decoding to `gs` at run time. Reports
of informes therefore render as semana-epidemiológica cards linking to MINSAL's PDF, not as images.

This is recorded because the code shows no trace of it: a reader who sees `imagick` in the image and
`Imagick::queryFormats('PDF')` returning `["PDF"]` will reasonably conclude PDF rendering works, and
try it.

## The measurement

`queryFormats('PDF')` reports **compile-time** coder registration, not a working delegate. Rendering
a real one-page PDF inside the stock image fails:

```
sh: 1: gs: not found
FAILED: ImagickException: FailedToExecuteCommand `'gs' … -sDEVICE=pngalpha … '-r72x72' …`
```

Also absent: `pdftoppm`, `pdftocairo`, `mutool`, `qpdf`, `pdfinfo`, and any `convert`/`magick` CLI.
The image's ImageMagick policy separately sets `<policy domain="coder" rights="none" pattern="HTTPS"/>`,
so a remote URL could not be read directly even with a working delegate.

## Why not just install it

Both fixes need packages in the **runtime** image — `ghostscript` pulls 32 new packages,
`poppler-utils` 27 — which means a derived production image. `AGENTS.md` currently sanctions a
derived image only for development (*"Xdebug is a derived dev-only image, not a fork"*), so this is a
platform decision for `gestion`, not an app change, and it would alter the deployment story and the
digest pinning from #109.

Two further costs sat behind it: a cached thumbnail is a derivative work of a state document stored
on our server, which reopens `SPEC.md`'s "no rehosting" and "no parsing PDF contents" lines; and
rendering page 1 tells a clinician less than the semana epidemiológica already does.

## Consequences

- If PDF previews are ever wanted for **staff documents in Files** — a much stronger reason than this
  one — installing Ghostscript would serve both, since core's `OC\Preview\PDF` provider uses the same
  Imagick extension already present. Reopen this then, as a `gestion` decision.
- Nothing in this app should call `Imagick` against a PDF, and any check that "PDF support exists"
  must render a real file rather than trust `queryFormats`.
