AgoraPress language packs
=========================

Place GNU gettext .mo catalogs here so the site can serve translated core strings.

Naming (first match wins for the default domain):
  {locale}.mo                 e.g. ar.mo, he_IL.mo
  agorapress-{locale}.mo      e.g. agorapress-ar.mo
  default-{locale}.mo

Plugins / themes:
  {domain}-{locale}.mo
  plugins/{domain}-{locale}.mo
  themes/{domain}-{locale}.mo

Site language is selected in Admin → Settings → General (WPLANG option).
Empty WPLANG means English (en_US) source strings.

Right-to-left locales (ar, he, fa, ur, …) automatically set dir="rtl" on
the HTML root and add a body.rtl class for styling.

Generate .mo files from .po with msgfmt, or use AP_L10n::writeMoFile() in tooling.
