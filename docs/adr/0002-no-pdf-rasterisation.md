# We do not rasterise PDFs

Thumbnailing each ETI/IRAG informe's first page was designed and then dropped: **the official
`nextcloud:34-apache` image ships the `imagick` PHP extension but no Ghostscript**, and ImageMagick
delegates PDF decoding to `gs` at run time. Informes render as semana-epidemiológica cards linking to
MINSAL's PDF. Recorded because `imagick` is present and `Imagick::queryFormats('PDF')` returns
`["PDF"]` — **compile-time** coder registration, not a working delegate — so a reader concludes
rendering works and tries it. Rendering a real one-page PDF inside the stock image:

```
sh: 1: gs: not found
FAILED: ImagickException: FailedToExecuteCommand `'gs' … -sDEVICE=pngalpha … '-r72x72' …`
```

Also absent: `pdftoppm`, `pdftocairo`, `mutool`, `qpdf`, `pdfinfo`, and any `convert`/`magick` CLI.
The image's ImageMagick policy separately sets `<policy domain="coder" rights="none" pattern="HTTPS"/>`,
so a remote URL could not be read even with a working delegate.

Installing it is not ours to do: both fixes need packages in the **runtime** image (`ghostscript`
pulls 32, `poppler-utils` 27), i.e. a derived production image, which `AGENTS.md` sanctions only for
development — a `gestion` platform decision, touching `gestion`'s #109 digest pinning. A cached thumbnail is
also a derivative work of a state document on our server, reopening issue #1's "no rehosting" and
"no parsing PDF contents" lines.

## Consequences

- Reopen as a `gestion` decision if PDF previews are wanted for **staff documents in Files** — a much
  stronger reason than this one, and Ghostscript would serve both, since core's `OC\Preview\PDF` uses
  the same Imagick extension already present.
- Nothing here should call `Imagick` against a PDF; any check that "PDF support exists" must render a
  real file rather than trust `queryFormats`.
