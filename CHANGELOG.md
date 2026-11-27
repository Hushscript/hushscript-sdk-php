# Changelog

## Unreleased

### Features

* add the `vet_mode` (Veterinary mode, beta; en, en_au, en_uk, en_us, es, fr, de, de_ch; refused with `medical_mode` and in private mode) and `keep_disfluencies` (free, default off) transcription options, both echoed in the job options summary; the medical language list is synced.

### Bug Fixes

* `translate_to` no longer forces speaker detection on: `speakers: false` is sent as given, translations or not.
* `translate_to` takes up to 15 distinct targets (trimmed, lowercased); more raises `PreflightError` (`invalid_option`) before any request.
* the bundled API spec is regenerated from the current backend and synced with production: `translation_targets` no longer declares `maxItems` (the backend counts the 15-target cap after dedup, as the SDK preflight already does).
* the conformance suite is pinned to the released `v1.1.0` tag.

### Documentation

* README links point at the live pricing page and the SDK section of the developers page; the old `/pricing` and `/sdks` URLs redirect there.

## 1.0.0 (2026-10-13)


### Features

* initial release ([798cf11](https://github.com/Hushscript/hushscript-sdk-php/commit/798cf11698b722a42fbf38888a30fdc66350fa98))
