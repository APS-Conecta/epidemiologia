# ISP intermediate certificate

`gsgccr6alphasslca2025.pem` is public Certificate Authority material. It is not
a secret and contains no private key — it is the same bytes anyone's browser
already downloads and trusts when it visits `https://www.ispch.gob.cl/`.

## Why it is here

`www.ispch.gob.cl` serves only its leaf certificate; it never sends the
intermediate that chains that leaf up to a root. A browser recovers by
chasing the certificate's AIA (Authority Information Access) extension and
fetching the missing link on its own. PHP, curl and Guzzle do not do this —
without the intermediate in the trust store they cannot build the chain, so
every request to the ISP fails TLS verification (`cURL error 60: unable to
get local issuer certificate`), and the app reports a generic outage instead
of the real cause.

Shipping this file lets `OCA\Epidemiologia\Service\CertificateBundle` merge
it into the instance's own trust anchors at request time, so a fresh install
works on any machine with no manual import.

## Where it came from

Fetched from the leaf certificate's own AIA "CA Issuers" URL:
`http://secure.globalsign.com/cacert/gsgccr6alphasslca2025.crt` (DER, converted
to PEM). Subject: `C = BE, O = GlobalSign nv-sa, CN = GlobalSign GCC R6
AlphaSSL CA 2025`.

## It will need to be replaced

The ISP's leaf certificate expires **2026-11-23**. Whoever reissues it may
switch to a different intermediate — this file pins one issuer, and nothing
in this app detects a rotation. If ISP requests start failing again after
that date, re-derive the current intermediate's URL directly from the live
leaf rather than assuming this one is still correct:

```bash
echo | openssl s_client -connect www.ispch.gob.cl:443 -servername www.ispch.gob.cl 2>/dev/null \
  | openssl x509 -noout -ext authorityInfoAccess
```

Download the URL named under "CA Issuers", convert DER to PEM if needed
(`openssl x509 -inform DER -in new.der -out gsgccr6alphasslca2025.pem`), and
replace this file.
