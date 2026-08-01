# Változásnapló

A PatchModule összes jelentős változása ebben a fájlban kerül dokumentálásra.

A formátum a [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) szabványon alapul,
a verziókövetés a [Szemantikus verziózás](https://semver.org/spec/v2.0.0.html) elvei szerint történik.

## [2.6.2] - 2026-08-01

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A `patch_settings.setting_value` oszlop `TEXT`-ről `MEDIUMTEXT`-re bővült, hogy a frissítésellenőrzés ne hibázzon nagy méretű gyorsítótárazott adatoknál |
| Javítva   | A gyorsítótárazott patch-beállítások JSON kódolása többé nem escape-eli a nem-ASCII karaktereket, csökkentve a többnyelvű változásnaplók méretét |

### Javítva

- **`patch_settings.setting_value` bővítve `MEDIUMTEXT`-re** — a gyorsítótárazott `patch_available_data` JSON blob a teljes kétnyelvű (HU + EN) változásnaplókat tartalmazza; több verziót átfogó kumulatív frissítésnél a kódolt payload meghaladhatta a `TEXT` típus 65 535 bájtos korlátját, ami `SQLSTATE[22001] Data too long` hibával megszakította a "frissítések keresése" folyamatot. A nem destruktív migráció itt található: `schema/migrations/2026_08_01_143553_patch_settings_value_mediumtext.sql`.
- **Unicode-biztos JSON kódolás a gyorsítótárazott beállításokhoz** — a `patch_available_data` és `patch_dismissed_versions` mezők kódolása mostantól `JSON_UNESCAPED_UNICODE` flaggel történik, így a többbájtos (pl. magyar) változásnapló-szöveg nem duzzad fel `\uXXXX` escape-szekvenciákká, ami közelebb tolta volna a payloadot a tárolási korláthoz.

---

## [2.6.1] - 2026-05-30

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A frissítés telepítési ablaka mindkét nyelven megjelenítette a változásnaplót; mostantól csak az adminisztrátor aktuális nyelvén jelenik meg |

### Javítva

- **Telepítési ablak változásnapló-nyelvszűrése** — a „Frissítés telepítése" gomb (az elérhető frissítések táblázatában és a kézi feltöltési folyamatban) a kétnyelvű változásnaplót nyers formában jelenítette meg (mind az `# English`, mind a `# Magyar` szekciót). Mostantól a szerver által már nyelvszűrt és renderelt `release_notes_html` mezőt használja. Ez egységessé teszi a telepítési ablak viselkedését a „Változások megtekintése" gombbal, amely már korábban is helyesen szűrt.
