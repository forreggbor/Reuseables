# Változásnapló

A PatchModule összes jelentős változása ebben a fájlban kerül dokumentálásra.

A formátum a [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) szabványon alapul,
a verziókövetés a [Szemantikus verziózás](https://semver.org/spec/v2.0.0.html) elvei szerint történik.

## [2.6.1] - 2026-05-30

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A frissítés telepítési ablaka mindkét nyelven megjelenítette a változásnaplót; mostantól csak az adminisztrátor aktuális nyelvén jelenik meg |

### Javítva

- **Telepítési ablak változásnapló-nyelvszűrése** — a „Frissítés telepítése" gomb (az elérhető frissítések táblázatában és a kézi feltöltési folyamatban) a kétnyelvű változásnaplót nyers formában jelenítette meg (mind az `# English`, mind a `# Magyar` szekciót). Mostantól a szerver által már nyelvszűrt és renderelt `release_notes_html` mezőt használja. Ez egységessé teszi a telepítési ablak viselkedését a „Változások megtekintése" gombbal, amely már korábban is helyesen szűrt.
