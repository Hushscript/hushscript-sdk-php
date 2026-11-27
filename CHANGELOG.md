# Changelog

## [1.2.0](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.2.1...v1.2.0) (2026-11-27)


### Features

* initial release ([798cf11](https://github.com/Hushscript/hushscript-sdk-php/commit/798cf11698b722a42fbf38888a30fdc66350fa98))
* require guzzle 8 ([4af426b](https://github.com/Hushscript/hushscript-sdk-php/commit/4af426b68243851525780de87e7cd7e977337977))
* sync with the api spec ([dcd2c05](https://github.com/Hushscript/hushscript-sdk-php/commit/dcd2c054db836ba0f6361b8077e2a98cf4401189))


### Bug Fixes

* compare the language for medical and vet as the api normalizes it ([14f8439](https://github.com/Hushscript/hushscript-sdk-php/commit/14f8439fdade314599fa796e4704be3d84762459))
* **deps:** update dependencies to latest ([4e6fba4](https://github.com/Hushscript/hushscript-sdk-php/commit/4e6fba404e54fb8e1615c1ae8a1e1b9c1f5703f0))
* sync the api spec with production and pin conformance v1.1.0 ([be12c8e](https://github.com/Hushscript/hushscript-sdk-php/commit/be12c8e007f52498bddcb6a986600937f099b387))
* sync the spec with the backend: org summary drops is_owner and owner_user_id (never sent) and adds matter_code_policy, rebill_rate_cents_per_hour and letterhead; upload mode is standard or private; six more upload options; rotate always returns expires_at ([c244c89](https://github.com/Hushscript/hushscript-sdk-php/commit/c244c894bdbe8fc7667ca0055bf8985a34f8f58f))
* translation no longer forces speakers; cap translate_to at 15 targets; regen spec ([2b38e4d](https://github.com/Hushscript/hushscript-sdk-php/commit/2b38e4d4ed55fabb94c8b679d06a695f7ddb6ac0))


### Miscellaneous Chores

* **deps-dev:** bump phpstan/phpstan-strict-rules to 2.1.1, compare the auto-hint flag strictly ([c732f32](https://github.com/Hushscript/hushscript-sdk-php/commit/c732f32c57d2d8e98a8171782014455be76ae6bd))
* **deps-dev:** bump vimeo/psalm from 6.20.4 to 6.20.5 ([e1b77bc](https://github.com/Hushscript/hushscript-sdk-php/commit/e1b77bcbaa8b938fffb03fe71da6ff667227d65b))
* **deps-dev:** bump vimeo/psalm from 6.20.4 to 6.20.5 ([76a38b9](https://github.com/Hushscript/hushscript-sdk-php/commit/76a38b92ebb18c8775bdcb0486160f504f2939c9))
* **deps:** bump python in /docker ([#3](https://github.com/Hushscript/hushscript-sdk-php/issues/3)) ([2f805bb](https://github.com/Hushscript/hushscript-sdk-php/commit/2f805bb8e4fb5d954a9c583129d5e01c34bba709))
* **deps:** refresh compatible sdk dependencies and ci tools ([d835314](https://github.com/Hushscript/hushscript-sdk-php/commit/d8353149cb090cc367f180f1a8fce775c0ce23cf))
* **deps:** update dependencies to latest ([3ca0614](https://github.com/Hushscript/hushscript-sdk-php/commit/3ca0614efbd68105634b80cda5a4e7ba8d6a686d))
* **deps:** update symfony/filesystem for the path-root advisory ([ae26046](https://github.com/Hushscript/hushscript-sdk-php/commit/ae260466da7b867fe3e63bfc31d121fba2b69c6d))
* fold the unreleased notes into the release ([077deef](https://github.com/Hushscript/hushscript-sdk-php/commit/077deef7a230bfc67f05b6456a2d083e0fdb2766))
* keep the release-please changelog out of typos ([592d138](https://github.com/Hushscript/hushscript-sdk-php/commit/592d1389258c8f42891f4bfecf8a574ad5eba4d6))
* keep the release-please changelog out of typos ([48c3e0c](https://github.com/Hushscript/hushscript-sdk-php/commit/48c3e0cd08566236d3e32804b223cff80457c962))
* **main:** release 1.0.0 ([59a3e71](https://github.com/Hushscript/hushscript-sdk-php/commit/59a3e716060a909bed806982b325f76cb19a4c3f))
* **main:** release 1.0.0 ([eccbc71](https://github.com/Hushscript/hushscript-sdk-php/commit/eccbc713de220c4825f92d8c0f6050724132d158))
* **main:** release 1.1.0 ([e403486](https://github.com/Hushscript/hushscript-sdk-php/commit/e403486648e99b9ad9fe92306fc9d04ca52918a0))
* **main:** release 1.1.0 ([eb114c8](https://github.com/Hushscript/hushscript-sdk-php/commit/eb114c888d8ff27fc64b9c7e200a6e45d23a314a))
* **main:** release 1.1.1 ([43ca41d](https://github.com/Hushscript/hushscript-sdk-php/commit/43ca41d923a9a54bde2b45abaad3cea545ff8340))
* **main:** release 1.1.1 ([c4c10a0](https://github.com/Hushscript/hushscript-sdk-php/commit/c4c10a0d35f61269b96632d84d65d63e77dcf9f0))
* **main:** release 1.1.2 ([6c49495](https://github.com/Hushscript/hushscript-sdk-php/commit/6c49495b8e481e973a5d443f96480221048258fe))
* **main:** release 1.1.2 ([a7ac0fb](https://github.com/Hushscript/hushscript-sdk-php/commit/a7ac0fbc53dd724359e7dfc27583e9602b113f79))
* **main:** release 1.1.3 ([805b565](https://github.com/Hushscript/hushscript-sdk-php/commit/805b565de68278038d36de79145b339edc0bc6b5))
* **main:** release 1.1.3 ([e6b225f](https://github.com/Hushscript/hushscript-sdk-php/commit/e6b225fcee2a890a13de7c936000a349dd10859d))
* **main:** release 1.2.0 ([aaca1e2](https://github.com/Hushscript/hushscript-sdk-php/commit/aaca1e2a75959260b9ed3c5b658ec4e07912be4f))
* **main:** release 1.2.0 ([0a1bc87](https://github.com/Hushscript/hushscript-sdk-php/commit/0a1bc87739343ab2bf72735f3a091a3d844af1e1))
* **main:** release 1.2.1 ([906e289](https://github.com/Hushscript/hushscript-sdk-php/commit/906e28924ad3f189a2f6026e5716f28948aee263))
* **main:** release 1.2.1 ([a024db6](https://github.com/Hushscript/hushscript-sdk-php/commit/a024db60a94957813eb56bedfb27fe53e51cceb1))
* pin conformance to the stt-3.7 suite on develop ([d61c53e](https://github.com/Hushscript/hushscript-sdk-php/commit/d61c53e67e7e8b68efa75901353914f2f1215644))
* promote develop to main ([d8c2be5](https://github.com/Hushscript/hushscript-sdk-php/commit/d8c2be5f6320c9c49b4604c7f99b036b98ec93ea))
* promote develop to main ([cf77050](https://github.com/Hushscript/hushscript-sdk-php/commit/cf770508838dddf8cb87f8a560079098cc26d305))
* promote develop to main ([c69a6eb](https://github.com/Hushscript/hushscript-sdk-php/commit/c69a6eb48491aa4f40f3bb17ddc2ec6cb46295b0))
* promote develop to main ([02e7b67](https://github.com/Hushscript/hushscript-sdk-php/commit/02e7b67c9e6cec60eb2607e338f1443f6dff9263))
* promote develop to main ([529a817](https://github.com/Hushscript/hushscript-sdk-php/commit/529a81791b8f67c4f1871a003a591b07e4716817))
* promote develop to main ([9e43e88](https://github.com/Hushscript/hushscript-sdk-php/commit/9e43e8835f48ad40fb88a9aaca1f4f4542b99846))
* promote develop to main ([416d463](https://github.com/Hushscript/hushscript-sdk-php/commit/416d463c5a11d6cbf3181ef263ca986ec783e143))
* promote develop to main ([91b79ae](https://github.com/Hushscript/hushscript-sdk-php/commit/91b79aeefd70442575de12d16167392c6d76d96f))
* unify dependabot config across sdk repos ([19a9670](https://github.com/Hushscript/hushscript-sdk-php/commit/19a96706c279c2c6a8b484494298c1787a2e035d))

## [1.2.1](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.2.0...v1.2.1) (2026-11-27)


### Bug Fixes

* sync the spec with the backend: org summary drops is_owner and owner_user_id (never sent) and adds matter_code_policy, rebill_rate_cents_per_hour and letterhead; upload mode is standard or private; six more upload options; rotate always returns expires_at ([c244c89](https://github.com/Hushscript/hushscript-sdk-php/commit/c244c894bdbe8fc7667ca0055bf8985a34f8f58f))


### Miscellaneous Chores

* promote develop to main ([d8c2be5](https://github.com/Hushscript/hushscript-sdk-php/commit/d8c2be5f6320c9c49b4604c7f99b036b98ec93ea))

## [1.2.0](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.1.3...v1.2.0) (2026-11-27)

### Features

* add the `vet_mode` (Veterinary mode, beta; en, en_au, en_uk, en_us, es, fr, de, de_ch; refused with `medical_mode` and in private mode) and `keep_disfluencies` (free, default off) transcription options, both echoed in the job options summary; the medical language list is synced.

### Bug Fixes

* `translate_to` no longer forces speaker detection on: `speakers: false` is sent as given, translations or not.
* `translate_to` takes up to 15 distinct targets (trimmed, lowercased); more raises `PreflightError` (`invalid_option`) before any request.
* the bundled API spec is regenerated from the current backend and synced with production: `translation_targets` no longer declares `maxItems` (the backend counts the 15-target cap after dedup, as the SDK preflight already does).
* the conformance suite is pinned to the released `v1.1.0` tag.

### Documentation

* README links point at the live pricing page and the SDK section of the developers page; the old `/pricing` and `/sdks` URLs redirect there.

## [1.1.3](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.1.2...v1.1.3) (2026-11-09)


### Miscellaneous Chores

* **deps-dev:** bump phpstan/phpstan-strict-rules to 2.1.1, compare the auto-hint flag strictly ([c732f32](https://github.com/Hushscript/hushscript-sdk-php/commit/c732f32c57d2d8e98a8171782014455be76ae6bd))
* **deps-dev:** bump vimeo/psalm from 6.20.4 to 6.20.5 ([e1b77bc](https://github.com/Hushscript/hushscript-sdk-php/commit/e1b77bcbaa8b938fffb03fe71da6ff667227d65b))
* **deps-dev:** bump vimeo/psalm from 6.20.4 to 6.20.5 ([76a38b9](https://github.com/Hushscript/hushscript-sdk-php/commit/76a38b92ebb18c8775bdcb0486160f504f2939c9))
* promote develop to main ([c69a6eb](https://github.com/Hushscript/hushscript-sdk-php/commit/c69a6eb48491aa4f40f3bb17ddc2ec6cb46295b0))
* promote develop to main ([02e7b67](https://github.com/Hushscript/hushscript-sdk-php/commit/02e7b67c9e6cec60eb2607e338f1443f6dff9263))
* unify dependabot config across sdk repos ([19a9670](https://github.com/Hushscript/hushscript-sdk-php/commit/19a96706c279c2c6a8b484494298c1787a2e035d))

## [1.1.2](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.1.1...v1.1.2) (2026-11-08)


### Miscellaneous Chores

* promote develop to main ([529a817](https://github.com/Hushscript/hushscript-sdk-php/commit/529a81791b8f67c4f1871a003a591b07e4716817))

## [1.1.1](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.1.0...v1.1.1) (2026-10-31)


### Bug Fixes

* **deps:** update dependencies to latest ([4e6fba4](https://github.com/Hushscript/hushscript-sdk-php/commit/4e6fba404e54fb8e1615c1ae8a1e1b9c1f5703f0))


### Miscellaneous Chores

* promote develop to main ([416d463](https://github.com/Hushscript/hushscript-sdk-php/commit/416d463c5a11d6cbf3181ef263ca986ec783e143))

## [1.1.0](https://github.com/Hushscript/hushscript-sdk-php/compare/v1.0.0...v1.1.0) (2026-10-23)


### Features

* require guzzle 8 ([4af426b](https://github.com/Hushscript/hushscript-sdk-php/commit/4af426b68243851525780de87e7cd7e977337977))
* sync with the api spec ([dcd2c05](https://github.com/Hushscript/hushscript-sdk-php/commit/dcd2c054db836ba0f6361b8077e2a98cf4401189))


### Miscellaneous Chores

* **deps:** bump python in /docker ([#3](https://github.com/Hushscript/hushscript-sdk-php/issues/3)) ([2f805bb](https://github.com/Hushscript/hushscript-sdk-php/commit/2f805bb8e4fb5d954a9c583129d5e01c34bba709))
* **deps:** refresh compatible sdk dependencies and ci tools ([d835314](https://github.com/Hushscript/hushscript-sdk-php/commit/d8353149cb090cc367f180f1a8fce775c0ce23cf))
* **deps:** update dependencies to latest ([3ca0614](https://github.com/Hushscript/hushscript-sdk-php/commit/3ca0614efbd68105634b80cda5a4e7ba8d6a686d))
* keep the release-please changelog out of typos ([592d138](https://github.com/Hushscript/hushscript-sdk-php/commit/592d1389258c8f42891f4bfecf8a574ad5eba4d6))
* keep the release-please changelog out of typos ([48c3e0c](https://github.com/Hushscript/hushscript-sdk-php/commit/48c3e0cd08566236d3e32804b223cff80457c962))
* promote develop to main ([91b79ae](https://github.com/Hushscript/hushscript-sdk-php/commit/91b79aeefd70442575de12d16167392c6d76d96f))

## 1.0.0 (2026-10-13)


### Features

* initial release ([798cf11](https://github.com/Hushscript/hushscript-sdk-php/commit/798cf11698b722a42fbf38888a30fdc66350fa98))
