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

---

## [2.6.0] - 2026-05-27

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | A kétnyelvű változásnaplók (PatchCreator v1.09.00+ által generált `# English` / `# Magyar` szekciók) nyelvenkénti renderelése |
| Hozzáadva | `setCurrentLanguage()` / `getCurrentLanguage()` metódus a `PatchModule`-on, hogy a host átadhassa a bejelentkezett admin felületi nyelvét |
| Módosítva | A `patch_history.release_notes` oszlop `TEXT`-ről `MEDIUMTEXT`-re bővült; migráció mellékelve |
| Javítva   | A telepítéskori 60 KB-os korlát megszűnt a `release_notes.md` fájlon — mostantól bármilyen praktikus méretű változásnapló rögzítésre kerül |

### Hozzáadva

- **Nyelvenkénti változásnapló** — amikor a patch archívumon belüli `release_notes.md` fájl `# English` és `# Magyar` H1 szekciójelölőket tartalmaz (a PatchCreator v1.09.00+ verziója által generálva), a PatchModule mostantól csak a bejelentkezett admin felületi nyelvének megfelelő szekciót választja ki és rendereli. A csak angol nyelvű változásnaplók (jelölők nélkül) változtatás nélkül, bájtra azonosan futnak át, mint korábban.
- **`PatchModule::setCurrentLanguage(?string $lang)`** — kérésenként egyszer hívandó a modul létrehozása után, átadva a bejelentkezett admin nyelvi kódját (`'hu'`, `'hu_HU'`, `'en'`, `'en_US'` stb.). A nyelvi normalizálás (prefix-egyeztetés) belsőleg történik; `null` esetén az alapértelmezett az angol.
- **`SimpleMarkdownRenderer::selectLanguageSection(string $markdown, ?string $language): string`** — statikus segédfüggvény, amely a célnyelvi tartalmat kinyeri egy kétszekciós változásnapló-szövegből. Tartalék-lánc: célnyelv → angol → a teljes bemenet változatlanul.

### Módosítva

- A `patch_history.release_notes` mostantól `MEDIUMTEXT` (16 MB-os felső korlát) a sémában. A nem destruktív `ALTER TABLE` migráció itt található: `schema/migrations/2026_05_27_150920_patch_history_release_notes_mediumtext.sql`.

### Javítva

- A 60 KB-os korlát, amely telepítéskor csendben kihagyta a túlméretezett `release_notes.md` fájlokat, megszűnt. A korlát azért létezett, mert a `TEXT` oszlop nem tudott 65 535 bájtnál többet tárolni; a `MEDIUMTEXT` bevezetésével ez a megkötés megszűnt.

---

## [2.5.0] - 2026-05-27

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A fejlesztés során törölt mappák mostantól a patch telepítése után a produkciós szerveren is eltávolításra kerülnek |

### Javítva

- **Üres könyvtárak eltávolítása fájltörlés után** — amikor egy patch eltávolítja egy könyvtár utolsó nyomon követett fájlját, a PatchModule mostantól az üres könyvtárat is eltávolítja a produkciós szerveren. Azok a könyvtárak, amelyek még tartalmaznak a patch által nem kezelt fájlokat (naplók, feltöltések, gyorsítótárak), érintetlenül maradnak. A visszaállítás (rollback) törölt fájlok helyreállításakor helyesen újra létrehozza a korábban eltávolított könyvtárakat (closes #2).

---

## [2.4.0] - 2026-05-19

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Új `findLatestHistoryByVersion()` adatbázis-adapter metódus, amelyet a patch-adatdúsítás utolsó lehetőségként használt tartalékmegoldásként alkalmaz |
| Javítva   | A Részletek gomb továbbra is `id=0` értéket kaphatott, amikor egy ismert verzióhoz minden státusz-szűrt keresés sikertelen volt |

### Hozzáadva

- **`DatabaseAdapterInterface::findLatestHistoryByVersion()`** — visszaadja egy adott verzió legutóbbi `patch_history` sorát, státusztól függetlenül (legmagasabb `id`). A `PdoAdapter` és a `CallableAdapter` implementálja. A patch-adatdúsítás tartalék-útvonalához szükséges.

### Javítva

- **Részletek gomb utolsó lehetőségként alkalmazott tartalékmegoldása** — az `enrichPatchesWithLocalIds()` mostantól meghívja a `findLatestHistoryByVersion()` metódust, amikor minden státusz-szűrt keresés érvényes `id` előállítása nélkül fut le. Ez megakadályozza, hogy a Részletek gomb csendben ne csináljon semmit olyan verzióknál, amelyek előzményrekordja a meglévő keresési logika által nem lefedett, váratlan státuszban van.

---

## [2.3.0] - 2026-05-14

| Kategória | Leírás |
|-----------|--------|
| Módosítva | Az adminisztrátori értesítési sáv helyett mostantól egy fix pozíciójú, jobb felső sarokban megjelenő toast értesítés jelenik meg, amely nem ütközik a változó szélességű oldalsávokkal |
| Javítva   | Az Elérhető frissítések táblázat soronkénti Részletek gombja hatástalan volt — a modal mostantól minden verziónál helyesen megnyílik |
| Javítva   | A Telepítés gomb megjelenhetett egy már befejezett vagy sikertelen patch-verziónál, ha a patch-szerver még elérhetőként listázta azt |
| Javítva   | A telepítési engedélyezési token 30 perc után lejárt, megszakítva a telepítéseket hosszabb admin munkameneteknél |
| Javítva   | Egy patch újrafeltöltése mostantól nem írja felül `null` értékkel a meglévő, nem-`null` változásnapló-bejegyzést, ha az archívum nem tartalmaz `release_notes.md` fájlt |

### Módosítva

- **Admin értesítési toast** — az oldal tetején lévő, teljes szélességű, rögzített értesítési sáv helyébe egy kompakt, fix pozíciójú, jobb felső sarokba rögzített toast lépett (`position: fixed; top: 1rem; right: 1rem; z-index: 1080`). A toast mostantól nem ütközik a változó szélességű oldalsávokkal, minden más oldalelem felett lebeg, és nincs bezárás gombja — csak akkor tűnik el, ha nincs függőben lévő frissítés.

### Javítva

- **Telepítés gomb befejezett/sikertelen verzióknál** — amikor a patch-szerver egy verziót továbbra is elérhetőként listázott, miután az helyben már befejeződött, sikertelen volt, vagy vissza lett állítva, a Telepítés gomb megjelent az adott verziónál, és a régi, lezárt státuszú rekordra mutatott. A Telepítés gombra kattintás ekkor vagy csendben sikertelen volt (a feltöltött patch-eknél, amelyek archívumát már eltávolították), vagy egy felesleges újratelepítést kísérelt meg. A gyökérok az volt, hogy a `selectInstallableId()` nem különböztette meg a lezárt státuszú adatbázis-rekorddal rendelkező patch-eket a valóban telepíthető rekorddal rendelkezőktől.
- **Soronkénti Részletek gomb** — az Elérhető frissítések táblázat egy elérhető patch sorában a „Részletek" gombra kattintás csendben nem csinált semmit. A gyökérok az volt, hogy az `enrichPatchesWithLocalIds()` nem állította be a helyi adatbázis-azonosítót, amikor ugyanahhoz a verzióhoz lezárt státuszú rekord (befejezett, sikertelen, visszaállított, elavult) létezett, emiatt a nézet `data-patch-id="0"` értéket renderelt, és a JS kattintáskezelő kihagyta a kérést. Az azonosító mostantól helyesen kerül hozzárendelésre a lezárt státuszú sorokhoz is.
- **Telepítési engedélyezés élettartama** — az egyszer használatos token, amelyet a rendszergazda jelszavának megerősítése után állít ki a rendszer patch telepítése előtt, 30 perc után lejárt. Hosszabb admin munkameneteknél ez „a jelszó-megerősítés lejárt" hibát eredményezett a telepítés közben. A token élettartama mostantól 24 óra, ami a gyakorlatban azt jelenti, hogy a host alkalmazás munkamenet-élettartamához kötődik, nem egy tetszőleges belső időzítőhöz.
- **Változásnapló nem íródik felül újrafeltöltéskor** — amikor egy patch-archívumot újra feltöltenek, és az új archívum nem tartalmaz `release_notes.md` fájlt, a meglévő változásnapló-bejegyzés az adatbázisban mostantól megmarad. Korábban egy `null` érték feltétel nélkül bekerült az UPDATE-be, csendben törölve a tárolt bejegyzést.

---

## [2.2.0] - 2026-05-14

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | A „Változásnapló megtekintése" gomb minden patch-előzmény soron megnyit egy modalt az adott verzió renderelt változásnaplójával |
| Hozzáadva | „Kézi feltöltés" jelvény a Státusz oszlopban a feltöltött archívumból telepített patch-eknél |
| Javítva   | A kézi feltöltések mostantól tárolják a változásnaplót — az archívum `release_notes.md` fájlja feltöltéskor beolvasásra kerül |

### Hozzáadva

- **Változásnapló modal** — a patch-előzmény táblázat minden során mostantól van egy „Változásnapló megtekintése" gomb. A rákattintás egy Bootstrap modalt nyit meg az adott verzió változásnaplójával, formázott HTML-ként renderelve (címsorok, listák, táblázatok, félkövér/dőlt szöveg, inline kód, linkek, elválasztóvonalak). A modal „Nincs elérhető changelog" üzenetet jelenít meg azoknál a soroknál, ahol nincs bejegyzés.
- **Kézi feltöltés jelvény** — egy „Kézi feltöltés" jelvény jelenik meg a Státusz oszlopban minden olyan patch-előzmény sornál, ahol a `patch_server_id IS NULL`, így könnyen megkülönböztethetők a feltöltött archívumból telepített patch-ek a patch-szerverről lekért patch-ektől.
- **`SimpleMarkdownRenderer`** — új osztály, amely egy Keep-a-Changelog formátumú Markdown-szeletet külső függőségek nélkül alakít tisztított HTML-lé. A bemenet a feldolgozás előtt teljes egészében HTML-escape-elésre kerül; a kimenetben csak a renderer által kibocsátott tag-ek jelenhetnek meg. Támogatja a PatchCreator által generált részhalmazt: `##`/`###`/`####` címsorok, listázatlan listák, pipe-táblázatok, félkövér, dőlt, inline kód, linkek (csak http/https/mailto), és elválasztóvonalak.
- **`release_notes_html` és `is_manual_upload` mezők** a `GET /details/{id}` válaszban — a `release_notes_html` az előre renderelt HTML-t hordozza (érték `null`, ha nincs változásnapló), az `is_manual_upload` egy `patch_server_id`-ből levezetett logikai érték.

### Javítva

- **A kézi feltöltések `NULL` értéket tároltak változásnaplóként** — a `PatchFileManager::extractPatch()` mostantól beolvassa a `release_notes.md` fájlt a kicsomagolt archívum gyökeréből, és `release_notes_md` néven adja vissza. Az `AdminActions::extractManifestFromArchive()` ezt a mezőt használja a nem létező `manifest.release_notes` kulcs helyett, így a kézzel feltöltött patch-ek mostantól helyesen töltik ki a `patch_history.release_notes` mezőt minden, a PatchCreator v1.03.00 vagy újabb verziójával épített archívumnál.

---

## [2.1.2] - 2026-05-14

| Kategória | Leírás |
|-----------|--------|
| Javítva   | Az integrációs útmutató vanilla PHP router példájából hiányzott a v2.1.0-ban bevezetett `GET /details/{id}` route |

### Javítva

- **Az integrációs útmutató vanilla PHP példájából hiányzott a route** — a `GET /details/{id}` route (amely egyetlen `patch_history` rekordot kér le a soronkénti Részletek és Telepítés gombokhoz) szerepelt a Slim 4 és Laravel példákban, de hiányzott a vanilla PHP router példából. Egy `preg_match` ellenőrzés került hozzáadásra a `match` blokk elé ennek a dinamikus útvonal-szegmensnek a kezelésére. Emellett egy „Új route szükséges" teendő is bekerült a v2.0.x → v2.1.0 frissítési megjegyzésekbe, hogy a meglévő integrációk tudják, hogy hozzá kell adniuk.

---

## [2.1.0] - 2026-05-13

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Új `obsolete` patch-státusz — visszavont szerverpatch-ekhez és a közvetlen fájlmásolással telepített, felülírt patch-ekhez |
| Hozzáadva | Telepítés gomb korlátozása — csak a legrégebbi elérhető patch (verzió szerint) mutat Telepítés gombot; a többi csak Részleteket mutat |
| Hozzáadva | Automatikus elavult-elérhetőség seprés minden admin index-renderelésnél a fájlmásolásos telepítések észlelésére |
| Javítva   | Egy elérhető patch-en a Telepítés vagy Részletek gombra kattintás üres modalt nyitott meg az adott patch részletei helyett |
| Javítva   | A kézi feltöltési modal üres változásnaplót és üres „Kiadás dátuma" mezőt mutatott |
| Javítva   | Egy ismert patch-verzió újrafeltöltése duplikált előzménysorokat hozott létre a meglévő rekord frissítése helyett |
| Javítva   | Az előzménytáblázat üres telepítés-dátumot, telepítő-felhasználót és előző verziót mutatott, mert egy későbbi frissítésellenőrzés felülírta ezeket |

### Hozzáadva

- **`obsolete` patch-státusz** — a patch-ek `obsolete` (elavult) státuszt kapnak, amikor visszavonásra kerülnek a patch-szerverről (a Frissítések ellenőrzésekor észlelve), vagy amikor az aktuális alkalmazásverzió meghalad egy korábban elérhető patch-verziót (minden admin oldal renderelésekor észlelve). Az elavult sorok áthúzott jelvénnyel és szürkített sorral jelennek meg az előzménytáblázatban; ki vannak zárva az elérhető patch-ek táblázatából, és nem telepíthetők vagy állíthatók vissza.
- **Telepítés gomb korlátozása** — az elérhető patch-ek táblázatában a Telepítés gomb csak a legrégebbi elérhető patch-nél jelenik meg. Az összes többi patch egy Részletek gombot és egy „Sorban" jelvényt mutat, jelezve, hogy sorrendben kell telepíteni őket.
- **Elavult-elérhetőség seprés** — az `AdminActions::index()` renderelés előtt `obsolete` státuszúra jelöli azokat az `available` patch-sorokat, amelyek verziója ≤ az aktuális alkalmazásverzió. Ez azokat a telepítéseket kezeli, ahol az alkalmazást a patch-telepítő helyett közvetlen fájlmásolással frissítik a szerveren.
- **`DatabaseAdapterInterface::findAvailableServerVersions(): array`** — visszaadja a jelenleg `available` státuszú, szerverről lekért patch-ek verziósztringjeit. A patch-szerver-ellenőrzés utáni különbség kiszámítására szolgál.
- **`DatabaseAdapterInterface::markObsoleteByVersions(array $versions): int`** — `obsolete` státuszúra jelöli a szerverről lekért `available` és `downloading` sorokat. Csak azokat a sorokat érinti, amelyeknél `patch_server_id IS NOT NULL`.
- **`PatchHistoryStatus::OBSOLETE`** konstans.
- **Sémamigráció** `schema/migrations/2026_05_13_103450_patch_history_add_obsolete_status.sql` — kibővíti a `status` ENUM-ot az `'obsolete'` értékkel. A v2.1.0 telepítése előtt le kell futtatni.
- **Fordítási kulcsok** `TEXT_PATCH_HISTORY_STATUS_OBSOLETE` és `TEXT_LABEL_QUEUED_PATCH` hozzáadva mind az en_US, mind a hu_HU nyelvhez.

### Javítva

- **Soronkénti Telepítés / Részletek kattintás** — a delegált kattintáskezelő mostantól a gomb `data-patch-id` attribútumát olvassa be, és egy új `showSinglePatchDetails(id)` JS-függvényt hív meg, amely a `GET /details/{id}` (soronkénti) végpontot kéri le. Korábban mindkét gomb argumentum nélkül hívta a `showDetails()`-t, amely a teljes elérhető-patch listát kérte le, és „Nincs elérhető frissítés" üzenetet mutatott, valahányszor a távoli gyorsítótár elavult volt.
- **Feltöltési modal üres „Kiadás dátuma" mező** — a feltöltési válasz mostantól tartalmazza az archívum `manifest.json` fájljából kinyert `released_at` értéket (a jelenlegi időbélyegre esik vissza, ha a manifest nem tartalmazza a mezőt). A JS `onUploadSuccess` kezelő mostantól ezt az értéket adja át a modalnak a `null` érték kőbe vésése helyett.
- **Kézi feltöltés duplikáció-kezelése** — egy olyan patch-verzió újrafeltöltése, amelyhez már létezik `patch_history` sor (beleértve a szerverellenőrzés által létrehozott sorokat is), mostantól a meglévő sort frissíti a helyén (`patch_server_id → NULL`) új sor beszúrása helyett. A staged archívum átnevezésre kerül, hogy illeszkedjen a meglévő rekord azonosítójához.
- **Előzménytáblázat üres egy későbbi ellenőrzés után** — a `PatchChecker::createOrUpdateHistoryRecord()` mostantól kihagyja egy új `available` sor létrehozását, ha a verzióhoz már létezik befejezett, sikertelen, visszaállított vagy elavult rekord. Korábban minden ellenőrzéskor létrehozott egy új `available` sort, ami egy üres telepítési adatokkal rendelkező duplikátumot eredményezett, amely kiszorította a befejezett sort az előzménynézetből.
- **Telepítés gomb rossz patch-hez rendelve** — az elérhető-patch lista nem volt rendezve a telepíthető patch meghatározása előtt, így egy feltöltött vagy szerverről érkező patch beszúrási sorrendben, nem pedig verziósorrendben kerülhetett kiválasztásra. A lista mostantól verzió szerint (növekvő sorrendben) rendezve van, mielőtt a telepíthető sor kiválasztásra kerül.

---

## [2.0.1] - 2026-05-12

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A kézi feltöltés „érvénytelen kérés" hibával meghiúsult — a CSRF token hiányzott az XHR fejlécből |

### Javítva

- **Kézi feltöltés CSRF-validációja** — a feltöltési XHR a CSRF tokent csak űrlapmezőként küldte a kérés törzsében, míg a host CSRF-adaptere az `X-CSRF-Token` HTTP fejlécből olvassa be. A fejléc mostantól be van állítva az XHR-en, összhangban minden más admin kéréssel.

---

## [2.0.0] - 2026-05-12

| Kategória   | Leírás |
|-------------|--------|
| Eltávolítva | A kézi feltöltés különálló `.sig` fájl követelménye — mostantól csak a `.tgz` kerül feltöltésre és elfogadásra |
| Módosítva   | A kézi feltöltés szekció az admin felületen mostantól egy Bootstrap accordion, alapértelmezésben összecsukva |
| Módosítva   | A bizalmi figyelmeztetés átírva, kizárólagos elfogadott archívumforrásként a PatrikMol Solutions Kft.-t nevezi meg |
| Biztonság   | A kézi feltöltés bizalmi kapuja mostantól rendszergazda-hitelesítés + CSRF (aláírásfájl sem nem szükséges, sem nem elfogadott) |

### Eltávolítva

- **A kézi feltöltés `.sig` követelménye** — a feltöltési űrlap többé nem fogad el különálló aláírásfájlt. Az archívum elfogadása kizárólag a rendszergazda-hitelesítésen és a CSRF-validáción alapul.
- **`ArchiveSignatureVerifierInterface`** — a szerződés törölve; csak az automatikus folyamathoz tartozó `SignatureVerifierInterface` marad meg.
- **`OpenSslArchiveSignatureVerifier`** — az implementáció törölve; kézi feltöltés során nem történik `openssl dgst` alfolyamathívás.
- **Konfigurációs kulcsok** `archive_signature_verifier` és `max_signature_size` — többé nem kerülnek beolvasásra vagy dokumentálásra.
- **Accessorok** `getArchiveSignatureVerifier()` és `getMaxSignatureSize()` eltávolítva a `PatchModule`-ból.
- **`AdminActions` konstruktor paraméterei** `$archiveSignatureVerifier`, `$expectedPublicKeyPem`, `$maxSignatureSize` eltávolítva.
- **Hibakódok** `upload_invalid_signature`, `upload_missing_pinned_key`, `upload_missing_signature` eltávolítva az `ErrorCode`-ból és minden locale-fájlból.
- **Fordítási kulcsok** `TEXT_PATCH_ERROR_UPLOAD_INVALID_SIGNATURE`, `TEXT_PATCH_ERROR_UPLOAD_MISSING_PINNED_KEY`, `TEXT_PATCH_ERROR_UPLOAD_MISSING_SIGNATURE`, `TEXT_LABEL_SIGNATURE_FILE`, `TEXT_LABEL_SIGNATURE_FILE_HINT`, `TEXT_MANUAL_UPLOAD_VERIFYING` eltávolítva az en_US és hu_HU nyelvekből.
- **`/usr/bin/openssl` futásidejű függőség** — kézi feltöltéshez többé nem szükséges.

### Módosítva

- **Kézi feltöltés szekció** — a feltöltési kártya mostantól egy Bootstrap accordion, alapértelmezésben összecsukva. A rendszergazdáknak ki kell nyitniuk a feltöltési űrlap eléréséhez.
- **Bizalmi figyelmeztetés** (`TEXT_MANUAL_UPLOAD_TRUST_WARNING`) — átírva, kifejezetten a PatrikMol Solutions Kft.-t nevezve meg egyetlen elfogadott archívumforrásként, és kimondva, hogy a rendszergazda felelős a forrás ellenőrzéséért telepítés előtt.
- **`expected_public_key_pem`** — mostantól kizárólag az automatikus folyamathoz (patch-szerver kulcs-pinning) van dokumentálva és felhasználva; a kézi feltöltési folyamat többé nem olvassa be ezt a konfigurációs kulcsot.

---

## [1.8.0] - 2026-05-12

| Kategória   | Leírás |
|-------------|--------|
| Hozzáadva   | Több fájlos SQL-migrációk — a patch-ek egy `migrations/` könyvtárat tartalmaznak; a PatchModule sorrendben hajtja végre a fájlokat |
| Hozzáadva   | `patch_migrations` tábla automatikus első futáskori bootstrap-pal és a `database/migrations/*.sql` fájlokból történő visszatöltéssel |
| Módosítva   | A manifest `migrations[]` tömbje váltja fel a korábbi `has_migration` logikai értéket |
| Módosítva   | Az `execute_migration` lépés naplózási szintje DEBUG-ról INFO-ra emelve — a migráció-nélküli eset mostantól látható produkciós környezetben |
| Eltávolítva | A korábbi, egyetlen `migration.sql` fájl az archívum gyökerében; a manifest `has_migration` logikai értéke |

### Hozzáadva

- **Több fájlos SQL-migrációk** — a PatchCreator automatikusan felismeri a git diffből a `database/migrations/*.sql` fájlokat, és egy `migrations/` könyvtárban szállítja őket a patch-archívumon belül. A PatchInstaller v1.8.0 lexikografikus (időrendi `YYYY_MM_DD_HHMMSS_` előtag szerinti) sorrendben hajtja végre őket a `PatchMigrator::executeMigrationsDirectory()` segítségével.
- **`patch_migrations` nyomon követő tábla** — minden alkalmazott SQL-fájl fájlnév szerint kerül rögzítésre (UNIQUE megkötéssel). Ugyanazon patch újratelepítése az SQL szempontjából no-op. A tábla automatikusan létrejön az első használatkor (`CREATE TABLE IF NOT EXISTS`), és visszatöltésre kerül a projekt meglévő `database/migrations/*.sql` fájljaiból (nem rekurzív, csak `.sql`) — a v1.6.x-ről frissítő meglévő telepítéseknél nincs szükség kézi operátori lépésre.
- **`PatchMigrator::executeMigrationsDirectory()`** — új publikus metódus; a patch-telepítéseknél felváltja a korábbi, egyfájlos `executeMigration()` útvonalat.
- **`PatchMigrator::ensureMigrationsTable()`** — privát önbootstrap: létrehozza a `patch_migrations` táblát, visszatölti a `database/migrations/` tartalmából, és az instance-on rögzíti, hogy legfeljebb egyszer fusson le.
- **`schema/patch_migrations.sql`** — kanonikus sémafájl friss integrációkhoz. A `patch_history.sql` után töltendő be (FK-függőség).

### Módosítva

- **Manifest formátum** — a `manifest.migrations[]` (mindig jelen lévő tömb) váltja fel a `has_migration` (logikai) mezőt. Üres tömb = nincs migráció.
- **`execute_migration` lépés** — a naplózási szint DEBUG-ról INFO-ra emelve, amikor nincs futtatandó migráció. A lépés mindig kipipálásra kerül a folyamatkövetőben.
- **`PatchFileManager`** — a `migrations` mostantól kötelező manifest-mező; minden elem a `^[A-Za-z0-9_][A-Za-z0-9._-]*\.sql$` mintával kerül validálásra; a kicsomagolás utáni realpath/symlink védelem kiterjed a `migrations/` könyvtár tartalmára is.

### Eltávolítva

- **A korábbi `migration.sql` az archívum gyökerében** — a PatchInstaller v1.8.0 kizárólag a `migrations/` könyvtárat dolgozza fel. A korábbi formátumot használó régi archívumok nem támogatottak.
- **A `has_migration` logikai mező a manifestben** — eltávolítva mind a PatchCreator kimenetéből, mind a PatchFileManager validációjából.

---

## [1.7.1] - 2026-05-12

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A feltöltési űrlap fájlok kiválasztása nélkül is engedélyezte a beküldést, visszajelzés nélkül a felhasználó számára |

### Javítva

- **A feltöltési űrlap csendben nem csinált semmit hiányzó fájlok esetén** — a kézi feltöltési űrlapon be volt állítva a `novalidate`, amely letiltotta a böngésző beépített `required` attribútum-érvényesítését. A Feltöltés gombra kattintás fájlok kiválasztása nélkül azt eredményezte, hogy a beküldéskezelő csendben visszatért, toast, validációs üzenet és vizuális visszajelzés nélkül. A `novalidate` eltávolításra került; a böngésző mostantól elfogja a hiányzó fájl esetét a submit esemény kiváltása előtt, és natív validációs jelzést mutat.

---

## [1.7.0] - 2026-05-12

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Kézi patch-feltöltés — a rendszergazda feltölthet egy `.tgz` + `.sig` párost, és internetkapcsolat nélkül telepítheti |
| Hozzáadva | `ArchiveSignatureVerifierInterface` + `OpenSslArchiveSignatureVerifier` a különálló RSA-SHA256 aláírás-ellenőrzéshez |
| Hozzáadva | 10 új `UPLOAD_*` hibakód és a hozzájuk tartozó locale-kulcsok az en_US és hu_HU nyelveken |

### Hozzáadva

- **Kézi patch-feltöltés** — egy új feltöltési kártya a `/admin/patch-management` oldalon lehetővé teszi a rendszergazda számára, hogy feltöltse a `.tgz` patch-archívumot és a hozzá tartozó különálló `.sig` aláírásfájlt, majd helyben telepítse azt a patch-szerverhez való kapcsolódás nélkül. Akkor is működik, ha a távoli csatorna nem elérhető, a licenc lejárt, vagy a szerver nem érhető el. Az aláírás ellenőrzésre kerül a rögzített `expected_public_key_pem` alapján, mielőtt az archívum elfogadásra kerül.
- **`ArchiveSignatureVerifierInterface`** — új szerződés a különálló bináris aláírás-ellenőrzéshez, elkülönítve a meglévő, JSON-payloadhoz tartozó `SignatureVerifierInterface`-től.
- **`OpenSslArchiveSignatureVerifier`** — alapértelmezett implementáció, amely a `/usr/bin/openssl dgst -sha256 -verify` parancsot hívja meg `proc_open`-en keresztül (tömbszintaxis — nincs shell injection). Az archívumot streamként dolgozza fel anélkül, hogy PHP memóriába töltené.
- **`PatchInstaller::installFromLocalArchive()`** — új pipeline belépési pont, amely újrafelhasználja a meglévő lépés-segédfüggvényeket (kicsomagolás → mentés → migráció → másolás → eltávolítás → verziófrissítés → ellenőrzés → takarítás), csak a letöltési lépést hagyva ki.
- **`PatchModule::installFromUploadedArchive()`** — facade metódus a kézi telepítési útvonalhoz.
- **Új konfigurációs kulcsok**: `archive_signature_verifier`, `max_upload_size` (alapértelmezés 100 MB), `max_signature_size` (alapértelmezés 10 KB).
- **`findUploadedAvailablePatches()`** a `DatabaseAdapterInterface` / `PdoAdapter` / `CallableAdapter`-en — visszaadja a `status='available'` állapotú, kézzel feltöltött patch-eket, hogy megjelenjenek az összefésült elérhető-patch táblázatban.
- **`sweepStaleTmpFiles()` kibővítve** — mostantól eltávolítja azokat az árva `patch_uploaded_*.tgz` fájlokat is, amelyekhez hiányzik a `patch_history` sor, vagy az lezárt státuszú.
- **A feltöltési kártya mindig látható** — a feltöltési kártya akkor is renderelődik, ha a távoli csatorna le van tiltva, megőrizve a katasztrófa-helyreállítási útvonalat.
- **„Kézi feltöltés" jelvény** — az elérhető-patch táblázat egy másodlagos (Secondary) jelvényt mutat a verzió mellett a kézi feltöltésből származó soroknál.
- **22 új locale-kulcs** mind az `en_US`, mind a `hu_HU` nyelveken, amelyek a feltöltés gombot, a kártya fejlécét, a fájlbeviteli mezők feliratait, a bizalmi figyelmeztetést, a folyamatüzeneteket és mind a 10 feltöltési hibakódot lefedik.
- **`POST {base}/upload`** végpont hozzáadva a wire formátumhoz (10. admin végpont).

---

## [1.6.4] - 2026-05-12

| Kategória     | Leírás |
|---------------|--------|
| Javítva       | A sáv-elrejtési védelem notice-t dobhatott, amikor a `$disabled` nem volt beállítva a hívó scope-ban |
| Dokumentáció  | A README API-referenciája javítva és kibővítve a hiányzó metódusokkal és hibakódokkal |

### Javítva

- **`_banner.php` null-biztonsági védelem** — a korai visszatérési feltétel `if ($disabled || empty($patches))` PHP notice-t adhatott ki, amikor a `$disabled` nem volt definiálva a befoglaló scope-ban. Módosítva `if (($disabled ?? false) || empty($patches))`-re. Nincs viselkedésbeli változás, ha a változó megfelelően be van állítva.

### Dokumentáció

- **README API-referencia javítva** — az `install()` mostantól dokumentálja a `?string $language = null` paramétert; a `rollback()` mostantól dokumentálja a `?int $userId = null` paramétert.
- **README API-referencia kibővítve** — új **Admin UI** szakasz dokumentálja a `getAdminActions()`, `isAvailable()` és `getBaseUrl()` metódusokat; új **Accessorok** szakasz dokumentálja a `getDatabase()`, `getVersionResolver()`, `getProgressTracker()` és `getMaintenanceMode()` metódusokat.
- **README hibakód-táblázat** — hozzáadva az `invalid_manifest_schema` és a `verification_failed`.

---

## [1.6.3] - 2026-05-11

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Új `TEXT_PATCH_ERROR_REQUEST_FAILED` fordítási kulcs, `genericError` néven elérhetővé téve a JS i18n konfigurációban |
| Módosítva | Minden fetch-művelet mostantól egy egységes `parseResponse` segédfüggvényt használ a következetes hibakezeléshez és CSRF-token rotációhoz |

### Hozzáadva

- **`TEXT_PATCH_ERROR_REQUEST_FAILED` fordítási kulcs** — hozzáadva mind az `en_US`, mind a `hu_HU` locale-fájlhoz, és `genericError` néven elérhetővé téve a `#patch-mount` elemen lévő `data-i18n` JSON-ban. A JS kliens mostantól egy lokalizált tartalék toastot mutat, valahányszor egy általános hálózati vagy szerverhiba lép fel a `dismissAll`, `dismissPatch`, `checkUpdates`, `verifyPassword` vagy `installCurrent` műveletekben.

### Módosítva

- **`parseResponse` segédfüggvény bevezetve** — mind az öt fetch-művelet (`dismissAll`, `verifyPassword`, `installCurrent`, `checkUpdates`, `dismissPatch`) mostantól egy egységes `parseResponse(response)` függvényen keresztül fut, amely biztonságosan feldolgozza a JSON-t, rotálja a CSRF tokent, és egy normalizált `{ok, data, errorMessage}` objektumot ad vissza. Korábban a `dismissAll` és a `dismissPatch` csendben figyelmen kívül hagyta a szerverhibákat; mostantól toastot jelenítenek meg. A `checkUpdates` mostantól hiba esetén újra engedélyezi a gombját. A `verifyPassword` az i18n tartalékot használja a kőbe vésett angol szöveg helyett.

---

## [1.6.2] - 2026-05-07

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A „Részletek" és „Telepítés" gombok az elérhető patch-ek táblázatában helytelenül le voltak tiltva |
| Javítva   | A frissítési sáv stílus nélküli egyszerű szövegként jelent meg — a sávhoz tartozó CSS szabályok hiányoztak |

### Javítva

- **Az elérhető patch-ek táblázatában lévő műveleti gombok mostantól mindig engedélyezettek** — a „Részletek" és „Telepítés" gombok le voltak tiltva, amikor a patch-hez nem tartozott illeszkedő, `available` vagy `downloading` státuszú `patch_history` sor. Ez akkor fordult elő, amikor a patch-gyorsítótár egy kézi törlés után frissült, vagy amikor egy korábbi sor `failed`/`rolled_back` állapotba került. Az admin oldal mostantól önmagát javítja: ha nem létezik megfelelő sor, létrehoz egyet renderelés előtt, így a gombok mindig kattinthatók. Az önjavítás közbeni bármilyen adatbázis-hiba naplózásra kerül, és az oldal továbbra is renderelődik.
- **A frissítési sáv mostantól stílusozott megjelenésű** — az elérhető frissítéseket hirdető rögzített felső sávnak nem volt vizuális megjelenése, mivel az egyéni CSS osztályaihoz (`patch-update-banner`, `patch-banner-inner` stb.) nem tartoztak szabályok. A hiányzó stílusok hozzáadásra kerültek, ugyanazt a sötétkék gradienst használva, mint a modal fejléce, keskeny képernyőkön reszponzív egymás alá rendezéssel.

---

## [1.6.1] - 2026-05-07

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A „Frissítések keresése" gomb nem adott visszajelzést, amikor nem talált semmit, vagy amikor az ellenőrzés sikertelen volt |
| Hozzáadva | Három fordítási kulcs a frissítésellenőrzés kimeneteihez (`CHECK_FAILED`, `CHECK_FOUND`, `CHECK_NO_UPDATES`) |

### Javítva

- **A `checkUpdates()` mostantól toastot mutat feltétel nélküli újratöltés helyett** — korábban a `PatchUpdate.checkUpdates()` a `data.available` értékétől függetlenül meghívta a `window.location.reload()`-ot. Az oldal mostantól csak akkor töltődik újra, ha `data.available === true` (új patch-eket talált). Amikor `data.available === false`, egy lokalizált „A telepítés naprakész." toast jelenik meg, és a gomb újra engedélyezésre kerül. A korábban a gombot végleg letiltó hálózati vagy szerverhibák mostantól egy „A frissítésellenőrzés sikertelen." toastot mutatnak, és a gombot is újra engedélyezik.

### Hozzáadva

- **Három új fordítási kulcs** a frissítésellenőrzés kimeneteihez — `TEXT_MESSAGE_PATCH_CHECK_FAILED`, `TEXT_MESSAGE_PATCH_CHECK_FOUND` és `TEXT_MESSAGE_PATCH_CHECK_NO_UPDATES` hozzáadva mind a `locale/en_US/messages.php`, mind a `locale/hu_HU/messages.php` fájlokhoz. A `CHECK_FOUND` szimmetria és jövőbeli felhasználás céljából került be; a másik kettő be van kötve a `checkUpdates()`-be.
- **`checkFailed`, `checkFound`, `checkNoUpdates` i18n kulcsok** elérhetővé téve a `#patch-mount` elemen lévő `data-i18n` JSON-on keresztül, hogy a JS kliens oldal-újratöltés nélkül is felvegye őket.

---

## [1.6.0] - 2026-05-07

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | `base_url` konfigurációs kulcs és `PatchModule::getBaseUrl()` az önálló admin UI URL-kezeléshez |
| Hozzáadva | Rollback audit-események (`rollback_patch`, `rollback_patch_failed`) — megszünteti a megfelelőségi rést a régi kontrollerekhez képest |
| Hozzáadva | `AdminActions::getViewTranslator()` — publikus closure-gyár host-beágyazott modulnézetekhez |
| Hozzáadva | `CsrfRotatableInterface` — opcionális szerződés, amely lehetővé teszi a kérésenkénti CSRF token rotációt |
| Módosítva | Az admin nézetek mostantól automatikusan megkapják az alap URL-t — a host kontrollereknek többé nem kell manuálisan beadagolniuk |
| Módosítva | Minden sikeres mutáló válasz mostantól tartalmazza a `csrf_token`-t, hogy a JS mindig az aktuális tokennel rendelkezzen |
| Módosítva | A `PatchModule::rollback()` és a `PatchInstaller::rollback()` opcionális `?int $userId` paramétert fogad el |
| Biztonság | A `base_url` létrehozáskor nyolc szabály alapján validálásra kerül, hogy megakadályozza a nem biztonságos URL-mintákat |
| Biztonság | A rollback audit-nyomvonal megszünteti a megfelelőségi rést; a hibaválaszok soha nem tartalmazzák és nem rotálják a CSRF tokent |

### Hozzáadva

- **`base_url` konfigurációs kulcs** — kötelező, ha az `auth_adapter` és a `csrf_adapter` be van állítva. Elfogadott formátum: azonos-eredetű útvonal `/`-lel kezdve, záró perjel nélkül, `..`, `?`, `#`, `//`, szóköz, vezérlőkarakter vagy százalék-kódolt szekvencia nélkül. A `PatchModule::__construct()` metódusnál gyorsan, leíró hibaüzenettel meghiúsul, megszüntetve egy csendes integrációs csapdát, ahol egy elfelejtett alap URL az összes admin JS végpontot 404-be küldte.
- **`PatchModule::getBaseUrl(): string`** — visszaadja a validált és normalizált admin UI alap útvonalat. A `$module->getBaseUrl()` használandó a host layout sáv include-jában a literál URL-sztring ismétlése helyett.
- **Rollback audit-események** — a `PatchInstaller::doRollback()` mostantól kibocsát egy `rollback_patch` eseményt sikeres esetben, és egy `rollback_patch_failed` eseményt hiba esetén a `LoggerInterface::activity()`-n keresztül. A felhasználói azonosító az `AdminActions::rollback()`-ből kerül átfűzésre az új `?int $userId` paraméteren keresztül. A telepítési hibák által kiváltott belső rollback-ek is kibocsátják ezeket az eseményeket (a telepítés által hordozott `userId`-vel), a meglévő `install_patch_failed` esemény mellett — a kettős kibocsátás a régi, projektenkénti `PatchController` viselkedését tükrözi, és minden műveletnek saját audit-sort ad.
- **`AdminActions::getViewTranslator(): \Closure`** — egy variadikus-tömbbé alakító híd closure-t ad vissza, amely megegyezik a modul index nézete által belsőleg már használttal. A saját layoutjukból modulnézeteket beágyazó hosztoknak (pl. `_banner.php` egy admin layout include-jában) a `$tr = $actions->getViewTranslator()` hívást kell használniuk a híd kézi felépítése helyett.
- **`CsrfRotatableInterface`** (`src/Contracts/CsrfRotatableInterface.php`) — opcionális interfész egyetlen `rotate(): string` metódussal. Azok a hosztok, amelyek CSRF implementációja minden mutáló művelet után új tokent generál, ezt a `CsrfAdapterInterface` mellett implementálják. A modul minden sikeres mutáló műveletnél pontosan egyszer hívja meg a `rotate()`-ot, és az új tokent belefoglalja a válaszba. A csak `CsrfAdapterInterface`-t implementáló meglévő adapterek nem érintettek.

### Módosítva

- **Az `AdminActions::index()` automatikusan beszúrja a `baseUrl`-t** — az `index()` által visszaadott adattömb mostantól tartalmazza a modul konfigurációjából származó `baseUrl`-t. A host kontrollereknek többé nem kell manuálisan beleolvasztaniuk az URL-t a `renderView()` hívás előtt.
- **Integrációs útmutató és README frissítve** — az `INTEGRATION-GUIDE.md` 5., 7., 8. és 11. lépése, valamint a `README.md` konfigurációs táblázata és gyorsindítási ellenőrzőlistája frissítve az új funkciók dokumentálásával.
- **A `base_url` validáció kiemelve `validateBaseUrl()`-be** — a nyolc validációs szabály mostantól egy dedikált privát metódusban van, így a `validateConfig()` a fő kötelező kulcsokra koncentrálhat.
- **Minden sikeres mutáló válasz tartalmazza a `csrf_token`-t** — a `check`, `dismiss`, `dismissAll`, `verifyPassword`, `install` és `rollback` mostantól mindig visszaadja a `csrf_token`-t a válasz törzsében. Ha az adapter implementálja a `CsrfRotatableInterface`-t, az érték egy frissen rotált token; egyébként a változatlan munkamenet-token. A JS kliens minden válasznál alkalmazza azt, oldal-újratöltés nélkül tartva szinkronban a belső tokenjét.
- **A `PatchModule::rollback()` és a `PatchInstaller::rollback()` `?int $userId = null` paramétert fogad el** — visszafelé kompatibilis; a `rollback($id)` hívása felhasználói azonosító nélkül továbbra is működik. A paraméter átadásra kerül az audit-eseménynek.
- **Az `AdminActions::index()` a `getViewTranslator()`-t használja** — az inline closure-duplikátum eltávolítva; a `'tr'` adatkulcsot mostantól az új publikus gyármetódus szolgáltatja.

### Biztonság

- **A `base_url` validálásra kerül létrehozáskor** — nyolc egymást követő ellenőrzés utasítja el: nem-sztring vagy üres érték; nem `/`-lel kezdődő útvonal; protokoll-relatív előtag (`//…`); abszolút URL (`scheme://…`); útvonal-bejárás (`..`); százalék-kódolt szekvenciák (`%`); lekérdezési sztring (`?`), töredék (`#`), szóköz vagy nem-ASCII karakterek (beleértve a magas bájtú UTF-8-at, 0x80–0xFF); valamint egymást követő perjelek az útvonalon belül (`//`). Bármilyen megsértés azonnal `\InvalidArgumentException`-t dob, mielőtt az objektum felhasználásra kerülne.
- **Rollback audit-nyomvonal** — az admin által kezdeményezett és az automatikus rollback-ek mostantól audit-naplózásra kerülnek, megszüntetve egy megfelelőségi rést, ahol a rollback-műveletek láthatatlanok voltak az aktivitási napló számára. A host meglévő `LoggerInterface` implementációja változtatás nélkül kapja meg ezeket az eseményeket.
- **CSRF token nem kerül visszaadásra hiba-útvonalakon** — a `csrfError()`, a `forbidden()` és minden 4xx/5xx hibaválasz nem hívja meg a `rotate()`-ot, és nem fűzi hozzá a `csrf_token`-t. Ez megakadályozza, hogy egy támadó ismételt CSRF-validációs hibák kiváltásával token-lecserélési szolgáltatásmegtagadási (DoS) támadást indítson az admin felhasználó ellen.

## [1.5.1] - 2026-05-07

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Naplózási integrációs útmutató, amely lefedi minden kibocsátott naplóüzenetet és aktivitási eseményt |

### Hozzáadva

- **`doc/LOGGING.md`** — teljes referencia a `LoggerInterface` host alkalmazásban történő implementálásához: mind a négy aktivitási esemény pontos payload-formátummal, minden `log()` üzenet szinttel és forrássorral, konstruktor-bekötési példák, és egy minimális, azonnal használható implementáció.

## [1.5.0] - 2026-05-06

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Beépített admin UI: sáv, modal, index oldal, több patch-es sor, folyamatnézet |
| Hozzáadva | `AuthAdapterInterface`, `CsrfAdapterInterface`, `TranslatorInterface` szerződések |
| Hozzáadva | `AdminActions` osztály: 9 típusos HTTP action metódus, keretrendszer-függőség nélkül |
| Hozzáadva | `PatchHistoryStatus` osztály konstansokkal az összes `patch_history` státuszértékhez |
| Hozzáadva | `WIRE-FORMAT.md` és `INTEGRATION-GUIDE.md` a host-oldali integrációhoz |
| Javítva   | `escapeHtml` és `showNotification` segédfüggvények definiálva a `patch-update.js`-ben |
| Módosítva | `AdminActions` átalakítva: a telepítési zár, útvonal-validáció és válaszépítés privát segédfüggvényekbe kiemelve |
| Módosítva | `patch-update.js` átalakítva: a telepítési UI beállítása, válaszkezelés és a folyamatsáv frissítése nevesített metódusokba kiemelve |
| Biztonság | A sikertelen jelszó-próbálkozásokat mostantól naplózza az `AdminActions` |

### Javítva

- **Az `escapeHtml` és a `showNotification` mostantól önálló** — mindkét segédfüggvényt hívta a `js/patch-update.js`, de sosem lettek ott definiálva, ami `ReferenceError` hibát okozott minden HTML-építő kódútvonalon. Mindkettő mostantól a fájl elején van definiálva egy host-felülírási védelemmel, így azok a hosztok, amelyek már biztosítanak egy globális verziót, nem érintettek.

### Biztonság

- **A sikertelen jelszó-próbálkozások naplózásra kerülnek** — az `AdminActions::verifyPassword()` mostantól minden helytelen jelszónál meghívja az `error_log()`-ot, így a sikertelen próbálkozások feltétel nélkül megjelennek a PHP hibanaplóban, függetlenül attól, hogy a host rendelkezik-e throttling middleware-rel.

### Módosítva

- **Az `AdminActions` telepítési/visszaállítási zárkezelése központosítva** — a duplikált zár-megszerzés/feloldás kód kiemelve a `withInstallLock(callable $fn)`-be. Mind az `install()`, mind a `rollback()` mostantól ehhez az egyetlen segédfüggvényhez delegálja a zárkezelést; a zár kivétel esetén sosem maradhat lezárva.
- **Az `AdminActions::install()` bemenet-validációja kiemelve** — öt védőfeltétel (rendszergazda, CSRF, hitelesítési token, azonosítói határok, folyamattoken-formátum) áthelyezve a `validateInstallRequest()`-be, így az `install()` egy tömör orkesztrátor a védekező ellenőrzések fala helyett.
- **Útvonal-biztonsági ellenőrzés központosítva az `AdminActions`-ban** — a `buildFilesManifest()`-ben ismétlődő háromfeltételes útvonalvédelem kiemelve az `isUnsafePath()`-be, megszüntetve a duplikált logikát a fájlok és az eltávolított fájlok ciklusai között.
- **A `PatchHistoryStatus` konstansok váltják fel a nyers sztring-literálokat** — a `'completed'`, `'rolled_back'` és a többi előzmény-státuszérték mostantól a `PatchHistoryStatus::COMPLETED` stb. konstansokon keresztül van hivatkozva az `AdminActions`-ban és a `views/admin/index.php`-ban.
- **A `patchStatusBadge()` védve az újradeklarálás ellen** — a nézet-lokális függvény mostantól `if (!function_exists(...))` blokkba van csomagolva, így az `index.php` kétszeri include-olása ugyanazon kérésben többé nem okoz végzetes PHP hibát.
- **A `patch-update.js` telepítési folyamata célzott metódusokra bontva** — a `startInstall()` 107 sorról 42 sorra csökkent a `setupInstallUI(currentPatch, createBackup)` (modal állapot és lépéslista) és a `handleInstallResponse(data)` (siker/hiba elágazás) kiemelésével; a beágyazási mélység 5-ről 2-re csökkent.
- **`updateProgressBar(steps)` kiemelve az `updateStepsUI()`-ból** — a folyamatsáv szélessége, színe és animációs állapota mostantól egy dedikált metódus által frissül, amelyet a lépés-ciklus végén hívnak meg, elkülönítve az ikonrenderelést a folyamat megjelenítésétől.

### Hozzáadva

- **`PatchHistoryStatus` osztály** — központi konstansok az összes `patch_history.status` értékhez (`available`, `downloading`, `installing`, `completed`, `failed`, `rolled_back`), ugyanazt a mintát követve, mint az `ErrorCode`. Megszünteti a nyers sztring-literálokat az `AdminActions`-ban és az admin index nézetben.
- **Beépített admin UI nézetek** — a `views/admin/index.php`, `_modal.php` és `_banner.php` egy teljes patch-kezelési felületet biztosítanak. Nincs szükség projektenkénti nézet-duplikálásra.
- **`AdminActions` osztály** — 9 metódus, HTTP végpontonként egy (index, check, details, dismiss, dismissAll, verifyPassword, install, progress, rollback). Mindegyik egy egyszerű `['status' => int, 'data' => array]` tömböt ad vissza — nincs `echo`, nincs `header()`, nincsenek szuperglobálisok. A host kontrollerek 5 soros áteresztők.
- **`AuthAdapterInterface`** — keretrendszer-független szerződés a rendszergazda-ellenőrzéshez, jelszó-verifikációhoz, felhasználó-leképezéshez és az egyszer használatos telepítési engedélyezési tokenekhez. Felváltja a közvetlen `$_SESSION` írásokat, így a Laravel, Symfony, JWT és egyéni munkamenet-backendek is működnek.
- **`CsrfAdapterInterface`** — becsomagolja a host CSRF token lekérdezőjét és validátorát.
- **`TranslatorInterface`** — opcionális; a modul a saját `locale/en_US/messages.php` fájljára esik vissza, ha nincs megadva.
- **`PatchModule::getAdminActions()`** — lusta gyár, amely egy `AdminActions` instance-t ad vissza, vagy `null`-t, ha az auth/csrf adapterek nincsenek konfigurálva.
- **`PatchModule::isAvailable()`** — `{enabled: bool, reason: string}` értéket ad vissza; a sáv ezt használja az összes adatbázis-lekérdezés kihagyására, ha a modul nincs teljesen konfigurálva.
- **CSP-szigorú nézetek** — sehol nincs inline `<script>`, `<style>` vagy `onclick=`. Minden PHP-ből JS-be irányuló konfiguráció `data-*` attribútumokon keresztül van áthidalva a `#patch-mount` / `#patchUpdateBanner` elemeken.
- **Több patch-es telepítési token-láncolás** — a sikeres telepítési válasz tartalmazza a `next_install_token`-t, amikor a `has_next` igaz, így a felhasználónak nem kell újra megadnia a jelszavát a sorban álló patch-ekhez.
- **`css/patch-update.css`** — kiemelve a projektenkénti inline stílusokból; lépés-ikon, verzió-kártya, sor-elem és sáv CSS egyetlen fájlban.
- **`doc/WIRE-FORMAT.md`** — lefagyasztott HTTP szerződés mind a 9 végponthoz.
- **`doc/INTEGRATION-GUIDE.md`** — teljes host-oldali recept adapter-mintákkal, route-példákkal Slim / Laravel / vanilla PHP-hoz, fordító-bekötéssel, biztonsági követelményekkel, és migrációs receptekkel a TrafficJournal, JupitERP és UniCMS projektekhez.

## [1.4.0] - 2026-05-06

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Atomikus fájlírás mód-megőrzéssel, telepítési zárral, megszakítás elleni védelemmel és Twig gyorsítótár-érvénytelenítéssel |
| Hozzáadva | DELIMITER-tudatos SQL-parser tárolt eljárásokhoz és triggerekhez |
| Hozzáadva | A befejezett telepítések pillanatképei és mentései mostantól megőrzésre kerülnek visszaállításhoz (konfigurálható, alapértelmezés 3) |
| Javítva   | A manifest-séma validáció mostantól elutasítja a nem sztring típusú fájlútvonalakat és az érvénytelen verzióformátumokat |
| Javítva   | Az ellenőrzési lépés mostantól megerősíti, hogy minden manifest-fájl létezik, és az új verzió helyesen olvasható vissza |
| Javítva   | A karbantartási mód sosem lett bekapcsolva telepítés közben; mostantól a teljes telepítés/visszaállítás alatt engedélyezve van |

### Hozzáadva

- **Atomikus fájlírás** — a fájlok először egy `.patchtmp` ideiglenes néven kerülnek kiírásra, majd a helyükre átnevezésre (POSIX-atomikus); ez elkerüli egy félig megírt fájl kiszolgálását, ha a PHP folyamat telepítés közben megszakad. Fájlrendszerek közötti `EXDEV` tartalék (másolás + törlés) kerül alkalmazásra, ha a forrás és a cél különböző csatolási pontokon van.
- **Fájlmód-megőrzés** — minden cserélt vagy eltávolított fájl eredeti `chmod` értéke rögzítésre kerül a `snapshot_meta.json`-ban a pillanatkép készítésekor, és visszaállításra kerül rollback esetén, így a futtatható szkriptek és konfigurációs fájlok megtartják jogosultságaikat telepítés vagy visszaállítás után.
- **Elavult ideiglenes fájlok takarítása** — minden telepítés kezdetén az összes 24 óránál régebbi `*.patchtmp` fájl törlésre kerül a projekt gyökeréből, megelőzve a korábban megszakított telepítésekből maradt törmeléket.
- **`ignore_user_abort(true)` + `set_time_limit(0)`** — a telepítési/visszaállítási belépési pont mostantól túléli a böngésző-kapcsolat megszakadását, és a művelet teljes időtartamára nem állít be PHP futásidő-korlátot. Egy `finally` blokk garantálja, hogy a karbantartási mód el legyen kapcsolva még el nem kapott kivétel esetén is.
- **Karbantartási mód aktiválása** — a `MaintenanceMode::enable()` mostantól minden telepítés és visszaállítás kezdetén meghívásra kerül, és egy `finally` blokkban kikapcsolásra kerül. Korábban be volt kötve a modulba, de sosem lett meghívva a telepítési pipeline során.
- **Konfigurálható lefordított gyorsítótár-ürítés** — egy új `cache_paths_to_clear` konfigurációs kulcs abszolút könyvtárútvonalak listáját fogadja el (pl. a Twig lefordított sablon-gyorsítótára). A modul minden fájlmutáló lépés és minden rollback után kiüríti ezeket a könyvtárakat, megelőzve az elavult renderelt kimenetet.
- **Konfigurálható visszaállítás-megőrzés** — egy új `keep_last_snapshots` konfigurációs kulcs (alapértelmezés `3`) szabályozza, hogy hány befejezett telepítés őrzi meg pillanatképét és adatbázis-mentését későbbi visszaállításhoz. A régebbi pillanatképek automatikusan törlésre kerülnek minden sikeres telepítés után. A sikertelen telepítések pillanatképei kézi elvetésig megmaradnak.
- **DELIMITER-tudatos SQL-parser** — a `PatchMigrator::parseSqlStatements()` mostantól felismeri a `DELIMITER` direktívákat, így a patch-ek tárolt eljárásokat, triggereket és függvényeket is szállíthatnak. Tetszőleges záró jelölők (`//`, `$$`, `;;` stb.) támogatottak. Az egyéni záró jelölők eltávolításra kerülnek az utasítástörzsekből végrehajtás előtt.
- **Központosított hibakódok** — az `ErrorCode` osztály tartalmazza az összes belső hibakód-sztring konstansát (`INVALID_MANIFEST_SCHEMA`, `INSTALL_IN_PROGRESS`, `VERIFICATION_FAILED`, és minden korábban létező kódot). Minden belső használat átállt a konstansokra.

### Javítva

- A manifest-séma validáció mostantól ellenőrzi, hogy a `version` egy szigorú semver mintára illeszkedik (`x.y.z` vagy `x.y.z-pre`), és hogy a `files` és `removed_files` minden eleme `string` — korábban egy vegyes típusú tömb vagy egy `../../etc/passwd`-hez hasonló útvonal nem lett volna elutasítva séma szinten.
- Az ellenőrzési lépés (`verifyInstallation`) mostantól ellenőrzi, hogy a `manifest.files`-ben felsorolt minden fájl létezik a célútvonalán, és visszaolvassa a tárolt `app_version`-t, hogy megerősítse annak egyezését az újonnan telepített verzióval. Egy pásztázásos tartalék-telepítés (üres `manifest.files`) újra pásztázza az archívum könyvtárát a fájllistáért, így a tartalék-telepítések is ellenőrzésre kerülnek. A fájlméretek az archívum forrásához vannak hasonlítva, nem pedig egy nem-nulla méretet követelnek meg, így a legitim nulla bájtos fájlok is átmennek az ellenőrzésen.
- A karbantartási mód be volt kötve a modulba, de sosem lett meghívva a telepítési vagy visszaállítási pipeline során; mostantól feltétel nélkül bekapcsolásra kerül a művelet teljes időtartamára.
- A visszaállítási mód- és méret-ellenőrzések mostantól következetesen alkalmazásra kerülnek egy patch által eltávolított fájlok visszaállításakor is (korábban csak a cserélt fájlokra vonatkoztak).

## [1.3.0] - 2026-05-02

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A letöltési előfeltétel-észlelés csendben hibás volt — a `not_recently_verified` újrapróbálkozási útvonal mostantól helyesen működik |
| Hozzáadva | Teljes szerver hibakód-leképezés, 429 rate-limit kezelés `Retry-After` fejléccel, fájltörlés-támogatás, `ServerErrorMapper` segédeszköz |
| Módosítva | A fordítások migrálva gettext `.po`-ról PHP-tömb `locale/{lang}/messages.php` formátumra |
| Biztonság | Útvonal-bejárás elleni megerősítés `safeJoin()`-nal, symlink elutasítás archívumokban és könyvtár-pásztázásoknál |

### Javítva

- A `PatchDownloader` az `error.message`-et úgy olvasta ki a szerver hibaválaszából, hogy az `error` objektumot sztringgé kasztolta, ami mindig a szó szerinti `"Array"` értéket eredményezte. A `not_recently_verified` előfeltétel-ellenőrzés sosem illeszkedett — a `license_verify_callback` újrapróbálkozási útvonal gyakorlatilag holt kód volt minden korábbi verzióban. Ez mostantól javítva van az új `ServerErrorMapper` osztállyal, amely helyesen olvassa ki az `error.message`-et.

### Hozzáadva

- **`ServerErrorMapper`** — megosztott segédeszköz, amely minden dokumentált szerver HTTP-választ egy stabil kliensoldali `error_code` sztringre és opcionális `retry_after` egész számra képez le
- A **`PatchDownloader::download()`** mostantól `error_code` és `retry_after` kulcsokat ad vissza minden hiba-útvonalnál, lefedve: `not_recently_verified`, `invalid_license`, `license_revoked`, `license_expired`, `license_ip_mismatch`, `package_mismatch`, `rate_limited`, `signing_unavailable`, `server_error`, `network_error`
- A **`PatchChecker::checkForUpdates()`** mostantól `error_code`-ot és `retry_after`-t ad vissza sikertelen szerverhívásoknál (mind az IP-nkénti router-szintű 429, mind a végpont-szintű 429 kezelve van)
- A **`PatchInstaller::install()`** visszatérési tömbje mostantól tartalmazza az `error_code`-ot és a `retry_after`-t; a `patch_history`-ban tárolt `error_message` `[error_code]` előtaggal van ellátva a könnyű grepelhetőségért
- **429 rate-limit kezelés** `Retry-After` fejléc-elemzéssel (mind a delta-másodperces, mind a HTTP-dátum formátumot támogatja)
- **`PatchFileManager::removeFiles()`** — törli a `manifest.removed_files`-ben felsorolt elavult fájlokat; minden útvonalat validál a `safeJoin()`-nal törlés előtt; fájlonként érvényteleníti az OPcache-t; a hiányzó fájlok INFO szinten naplózásra kerülnek, és sikerként számítanak (idempotens)
- **A manifest `removed_files` opcionális tömb támogatása**: a felsorolt fájlok törlésre kerülnek a projekt gyökeréből a `copyFiles()` sikeres lefutása után; teljesen visszafelé kompatibilis (a hiányzó mező üresként kezelt)
- **A pillanatkép (`snapshot_meta.json`)** mostantól tartalmaz egy `files_to_remove` listát: a törlésre ütemezett meglévő fájlok mentésre kerülnek eltávolítás előtt, és visszaállításra kerülnek rollback esetén
- A **`TEXT_PATCH_STEP_REMOVE_FILES`** telepítési lépés hozzáadva a folyamat-pipeline-hoz
- **`doc/reviewed/ERROR-CODES.md`** — teljes referencia az összes kliensoldali `error_code` értékről, az azokat előidéző szerveroldali feltételekről és az ajánlott fordítási kulcsokról
- **`doc/reviewed/PATH-SAFETY.md`** — dokumentálja a `safeJoin()`-t, a symlink elutasítást, valamint az `invalid_manifest_path` / `invalid_archive` hibakódokat

### Módosítva

- A fordítások migrálva lettek a gettext `.po` fájlokról a projekt-szabvány PHP-tömbökre, a `locale/en_US/messages.php` és `locale/hu_HU/messages.php` fájlokban. Nincs szükség fordítási lépésre. A régi `locale/{lang}/LC_MESSAGES/patch.po` fájlok eltávolítva.
- A `CurlHttpClient::postJson()` mostantól rögzíti és kisbetűsíti a válaszfejléceket (megegyezően a `downloadFile()` viselkedésével), így a `/patches/check` `Retry-After` fejlécei elérhetők a hívók számára

### Biztonság

- A `PatchFileManager::safeJoin()` privát segédfüggvény minden fájlútvonalat validál bármilyen fájlrendszer-művelet előtt: elutasítja az üres sztringeket, az abszolút útvonalakat (Unix és Windows), a fordított perjeleket, a NUL bájtokat, valamint a `..`/`.`/üres szegmenseket; bármilyen bejárási kísérlet megszakítja a telepítést `error_code = 'invalid_manifest_path'` értékkel
- Az archívum kicsomagolását egy teljes symlink-pásztázás követi; bármilyen symlink a kicsomagolt fastruktúrában a telepítés sikertelenségét okozza `error_code = 'invalid_archive'` értékkel, és a kicsomagolási könyvtár megtisztításra kerül
- A `scanDirectory()` kihagyja a symlinkeket a projekt gyökerében a telepítés utáni ellenőrzési pásztázás során
- Az `ExecTarAdapter` mostantól `--no-same-owner --no-same-permissions` kapcsolókat ad át a `tar`-nak, megelőzve, hogy az archívumok váratlan fájltulajdonost vagy jogosultságokat kényszerítsenek ki

## [1.2.0] - 2026-04-27

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Patch-metaadat aláírás-ellenőrzés a szerver nyilvános kulcsa alapján (RSA/Ed25519) |
| Módosítva | A letöltési előfeltétel-hiba kezelése automatikus licenc-újraellenőrzési újrapróbálkozással |

### Hozzáadva

- **`SignatureVerifierInterface`** — szerződés aláírás-ellenőrzési implementációkhoz
- **`OpenSslSignatureVerifier`** — alapértelmezett implementáció, amely az `openssl_verify`-t használja SHA-256-tal
- **Patch-metaadat aláírás-ellenőrzés a `PatchChecker`-ben**: a szerver által aláírt patch-ek ellenőrzésre kerülnek a velük együtt visszaadott nyilvános kulcs alapján; az ellenőrzésen elbukó patch-ek kizárásra kerülnek a gyorsítótárból
- **Nyilvános kulcs pinning**: az opcionális `expected_public_key_pem` konfigurációs kulcs rögzíti a megbízható szerverkulcsot; a más kulcsot bemutató patch-ek elutasításra kerülnek, függetlenül attól, hogy maga az aláírás ellenőrizhető lett volna-e
- Az aláírás, a nyilvános kulcs és a lejárati mezők mostantól tárolásra kerülnek a patch-gyorsítótárban, így az újraellenőrzés lehetséges, ha a szerver lejáratot ad a válaszokhoz
- **`license_verify_callback` konfigurációs opció**: egy hívható, amelyet minden letöltési kísérlet előtt meghívnak, hogy frissen tartsa a szerveroldali „nemrég ellenőrzött" ablakot

### Módosítva

- A `PatchDownloader::download()` mostantól egy `error_code` kulcsot ad vissza (`'not_recently_verified'`), amikor a szerver elutasítja a letöltést, mert a licenc nem lett elég nemrég ellenőrizve (HTTP 403 `license_key_not_recently_verified`-vel vagy a régebbi `license_key_ip_mismatch` hibakóddal)
- A `PatchInstaller::install()` automatikusan újrapróbálja a letöltést egyszer a `license_verify_callback` meghívása után, amikor a szerver egy „nemrég ellenőrzött" előfeltétel-hibát ad vissza; ha az újrapróbálkozás is sikertelen, a hiba normál módon jelenik meg
- A `HttpClientInterface::downloadFile()` mostantól egy `body` kulcsot is tartalmaz a hibaválaszokban, így a szerver által visszaadott hibakódok vizsgálhatók a letöltő által

## [1.1.0] - 2026-03-11

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Beépített adatbázis-mentési adapter, amely mind a MariaDB-t, mind a MySQL-t támogatja |
| Javítva   | A mentés mostantól kimarad, ha egy patch-hez nem tartozik SQL-migráció, megelőzve az időveszteséget és a sérült visszaállítási állapotot |
| Javítva   | A MariaDB elavulási figyelmeztetései többé nem rontják el az SQL dump fájlokat |
| Javítva   | A pipe-olt parancsokban fellépő `mysqldump` hibák mostantól helyesen kerülnek észlelésre és jelentésre |

### Hozzáadva

- **`MysqldumpBackupAdapter`** — azonnal használható `BackupAdapterInterface` implementáció, amely automatikusan felismeri a `mariadb-dump`-ot vagy a `mysqldump`-ot (és a `mariadb`-t vagy `mysql`-t visszaállításhoz), megszüntetve a projektek számára a saját mentési adapter megírásának szükségességét

### Javítva

- Az adatbázis-mentés többé nem készül el, ha a patch nem tartalmaz `migration.sql` fájlt — korábban mindig egy teljes dump készült kicsomagolás előtt, még a csak-fájlos patch-eknél is
- A MariaDB elavulási figyelmeztetései `2>&1`-en keresztül belekerültek az SQL dumpba, csendben elrontva a mentési fájlt; a stderr mostantól egy ideiglenes fájlba van irányítva, elkülönítve az SQL adatfolyamtól
- A pipe-olt shell parancsok (`mysqldump | gzip`, `gunzip | mysql`) mostantól `set -o pipefail`-t használnak, így az első parancs hibája nem lesz elfedve a második parancs kilépési kódja által
- A telepítési pipeline lépéssorrendje frissítve: a mentés mostantól kicsomagolás után készül, így a `migration.sql` jelenléte előbb megerősíthető

## [1.0.0] - 2026-02-16

| Kategória | Részletek |
|-----------|-----------|
| Típus     | Kezdeti kiadás |
| Összegzés | Keretrendszer-független patch-kezelés kiemelve a FlowerShop projektből |
| Fájlok    | 22 fájl létrehozva |

### Hozzáadva

- `PatchModule` fő facade konfiguráció-vezérelt adapter-bekötéssel
- 6 adapter-interfész: `DatabaseAdapterInterface`, `HttpClientInterface`, `ArchiveAdapterInterface`, `BackupAdapterInterface`, `LoggerInterface`, `VersionResolverInterface`
- Alapértelmezett adapterek: `PdoAdapter`, `CallableAdapter`, `CurlHttpClient`, `ExecTarAdapter`, `PharTarAdapter`
- `PatchChecker` a frissítésellenőrzéshez, gyorsítótárazáshoz és elvetés-kezeléshez
- `PatchDownloader` SHA-256 hash-ellenőrzéssel
- `PatchInstaller` orkesztrátor teljes pipeline-nal (előellenőrzés → mentés → letöltés → telepítés → ellenőrzés → takarítás)
- `PatchFileManager` fájlmásoláshoz, szelektív pillanatképhez és visszaállításhoz
- `PatchMigrator` SQL-utasítások elemzéséhez és végrehajtásához FK-ellenőrzés kapcsolgatással
- `ProgressTracker` atomikus, JSON-fájl alapú folyamatkövetéshez
- `MaintenanceMode` jelzőfájl-alapú karbantartási mód kapcsolgatáshoz
- Adatbázis-séma: `patch_history` és `patch_settings` táblák
- Önálló karbantartási oldal nézet (Bootstrap 5, keretrendszer-függőségek nélkül)
- Frontend JavaScript állapotgép (`patch-update.js`) a modalhoz és a folyamat-UI-hoz
- Kétnyelvű fordítások (magyar, angol) gettext PO formátumban
