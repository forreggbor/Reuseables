# Változásnapló

A CronAdmin modul összes jelentős változása ebben a fájlban kerül dokumentálásra.

Formátum: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + Szemantikus verziózás.

---

## 1.3.1 — 2026-09-05

| Kategória | Leírás |
|-----------|--------|
| Javítva   | Az admin felület JavaScript hibaüzenetei hiányzó/üres általános hibaszöveget jelenítettek meg |

### Javítva

- Pótolva a hiányzó `TEXT_CRON_ERROR_GENERIC` fordítási kulcs mind az `en_US`, mind a `hu_HU` nyelvi fájlban, és javítva az admin nézet, hogy a nem kapcsolódó `TEXT_ERROR_GENERIC` kulcs helyett ezt a modulspecifikus kulcsot használja

---

## 1.3.0 — 2026-05-28

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Új `display_timezone` konfigurációs kulcs; az admin felületen megjelenített időpontok ettől a verziótól a beállított időzónában jelennek meg az UTC helyett |
| Módosítva | Az összes DATETIME írás mostantól `UTC_TIMESTAMP()` függvényt használ — az adatbázis munkamenet-időzónája már nem meghatározó |
| Javítva   | Az ütemező nyári/téli időszámítás visszaálláskor az alapértelmezett PHP-időzónában hasonlította össze a dátumokat a megjelenítési időzóna helyett, ami éjfél körül kettős futáshoz vezethetett |

### Hozzáadva

- Új, opcionális `display_timezone` konfigurációs kulcs (IANA azonosító, pl. `'Europe/Budapest'`); alapértelmezés szerint `date_default_timezone_get()` értékét veszi fel. Ajánlott explicit megadni, ha az FPM és a CLI `php.ini` fájlokban eltérő `date.timezone` értékek szerepelnek
- Új `TimeZoneHelper` belső osztály kezeli az összes UTC → megjelenítési időzóna konverziót egy helyen
- Az admin táblázat és a „Futtatás most" lekérdezési ciklus mostantól a beállított megjelenítési időzónában mutatja az időpontokat (utolsó futás oszlop, sorban állás tooltip, AJAX-válasz)

### Módosítva

- Az összes DATETIME írási hely (`last_run_at`, `trigger_pending_at`, `updated_at`, `created_at`) mostantól `UTC_TIMESTAMP()` függvényt használ `NOW()` helyett — az értékek explicit UTC-ben tárolódnak, az adatbázis munkamenet-időzónájától függetlenül
- A Dispatcher mostantól a megjelenítési időzónában hozza létre a `$now` változót, így az ütemezési mezők (`hour`, `minute`) abban az időzónában értékelődnek ki, amelyet az adminisztrátor a feladat konfigurálásakor használt
- `doc/INTEGRATION-GUIDE.md`: Az időzóna-illesztési előfeltétel helyett az új UTC-tárolás és `display_timezone` szerződés szerepel
- `doc/MANIFEST-FORMAT.md`: A `default_hour` és `default_minute` értékek értelmezési időzónája dokumentálva

### Javítva

- Az ütemező nyári/téli időszámítás visszaállás elleni védelme a `last_run_at` értékét az alapértelmezett PHP-időzónában elemezte; az UTC-tárolással most UTC-ként olvassa, majd megjelenítési időzónává alakítja a dátum összehasonlítás előtt — ez megakadályozza az azonos napon való kettős futást az óra visszaállításakor

### Megjegyzés

- **Frissítési figyelmeztetés:** azokon a hosztgépeken, ahol a MariaDB munkamenet-időzóna a frissítés előtt nem UTC volt, a régi `last_run_at` értékek ideiglenesen eltolt időpontként jelenhetnek meg az adminfelületen. Az új írások helyesen UTC-ben kerülnek mentésre. Az eltérés megjelenési jellegű, és a következő feladatfutásnál automatikusan megszűnik.

---

## 1.2.0 — 2026-05-28

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Három új áttekintő oszlop a táblázatban: kézi sorbaállás jelzője, DB-naplózás jelölője és e-mail értesítési mód |
| Módosítva | A „Futtatás most" gomb le van tiltva, amíg a feladat sorban áll; az oldal betöltésekor elindul a lekérdezési ciklus a már sorban álló feladatokhoz |
| Módosítva | Az integrációs útmutató pontosítva: az `index()` csak egy nézet-töredéket ad vissza — a fogadó vezérlőnek kell az admin elrendezésbe csomagolnia |

### Hozzáadva

- Három új, csak olvasható oszlop a feladatlistában: ⌛ homokóra (az indíttatás idejével és a sorba helyező nevével), ha egy feladatot kézileg indítottak; 💾 ikon, ha az adatbázis-naplózás aktív; ⚠ vagy ✉ az e-mail értesítési módhoz — mindezek korábban csak a szerkesztő ablakban voltak láthatók
- Oldalbetöltéskori alapciklus: az oldal megnyitása előtt már sorban álló feladatok is mutatják a jelzőt, és automatikusan törlődnek, ha az ütemező felveszi őket — oldal-frissítés nélkül

### Módosítva

- A „Futtatás most" gomb `disabled` állapotban jelenik meg tooltip-pel, amíg `trigger_pending = 1`, megelőzve az ismételt kattintásnál keletkező 409-es „már sorban áll" hibát
- Az `INTEGRATION-GUIDE.md` 10. lépése egyértelműen elkülöníti a válasz-felelősségi köröket: az `index()` egy nézet-töredéket ad vissza, amelyet a fogadónak kell elrendezésbe burkolnia; az AJAX végpontok teljes választ írnak, azokat nem szabad pufferelni

---

## 1.1.0 — 2026-05-21

| Kategória | Leírás |
|-----------|--------|
| Javítva   | Hét hibajavítás: a csak vasárnapi ismétlődésű heti feladatok soha nem futottak le, az OOM által kilőtt folyamatok időtúllépésig tartották a zárolást, és a 255 bájtnál hosszabb hibaüzenetek némán meghiúsították az eredmény mentését |
| Hozzáadva | Manifest-elgépelés-észlelés, job-key és útvonal-validáció, `since_ts` bemeneti ellenőrzés, sématá bővítés a `last_error` mezőhöz |
| Biztonság | A `manifest_path` dokumentálva mint kritikus bizalmi határ; útvonal-bejárás elleni védelem hozzáadva a `ConfigValidator`-hoz |
| Módosítva | Az integrációs útmutató és a manifest-formátum dokumentáció frissítve az időzóna-illesztéssel és az átnevezési szemantikával |

### Javítva

- A csak vasárnapi ütemezésű heti feladatok (`days_of_week='0'`) soha nem futottak le — a callback nélküli `array_filter` hamisnak kezelte a `"0"` értéket; mostantól explicit, nem-üres callback-et használ
- Az OOM vagy SIGKILL által kilőtt folyamatok akár `lock_timeout_seconds` másodpercig (alapértelmezés: 3600 mp) is tartották a feladat zárolását, mert a PID-életjel-ellenőrzés csak az mtime küszöb elérése után futott le; mostantól minden zárolásfájl-létezés-ellenőrzésnél lefut, és azonnal felszabadítja a halott PID-hez tartozó zárolásokat
- A 255 bájtnál hosszabb kivételüzenetek némán meghiúsították a `last_status` / `last_duration_ms` `UPDATE`-et (MariaDB „Data too long"); a hibaüzenet mostantól 1024 bájtra van csonkítva mind a kivétel elkapásának helyén, mind a mentési határon
- A `PdoAdapter::lastInsertId()` `false` értéket adhatott vissza a PDO-tól; mostantól mindig stringgé alakítja
- A `PdoAdapter::withTransaction()` hibát dobott, ha egy már futó tranzakción belül hívták meg; mostantól `inTransaction()` ellenőrzéssel csatlakozik a külső tranzakcióhoz; a rollback-hibák többé nem nyelik el az eredeti hibát
- Az admin nézetekben a fordítási szövegek nyersen jelentek meg; mindegyik mostantól `htmlspecialchars`-szal van becsomagolva
- Az `AdminActions::json()` és a `requireManifestHealthy()` nem használt `JSON_THROW_ON_ERROR`-t; a kódolási hibák némán elnyelődtek

### Hozzáadva

- A `ManifestReader` mostantól elutasítja az ismeretlen bejegyzés-kulcsokat, és validációs hibaként jelenti őket — így az olyan elgépelések, mint a `default_minutes`, már nem maradnak észrevétlenül hatástalanok
- A `LockManager::acquire()` a zárolásfájl útvonalának felépítése előtt ellenőrzi a `job_key` értékét a `/^[a-z0-9_]+$/` mintával
- A `ConfigValidator` elutasítja a `..`-t tartalmazó `manifest_path` értékeket; azt is ellenőrzi, hogy a meglévő `lock_dir` írható-e az aktuális folyamat számára, és hiba esetén `InvalidConfigException`-t dob
- Az `AdminActions::pollRunStatus()` ellenőrzi a `since_ts` lekérdezési paraméter formátumát, és hibás bemenet esetén HTTP 400-at ad vissza
- `TEXT_CRON_INVALID_SINCE_TS` fordítási kulcs hozzáadva az `en_US` és `hu_HU` nyelvi fájlokhoz
- `schema/migrations/0002_widen_last_error.sql` séma-migráció — a `last_error` mezőt `VARCHAR(255)`-ről `VARCHAR(1024)`-re bővíti

### Biztonság

- `doc/reviewed/SECURITY.md`: új „Terhelő bizalmi feltételezés" szakasz dokumentálja, hogy a `manifest_path` minden dispatch-ciklusnál és admin oldalbetöltésnél `require`-rel kerül végrehajtásra; a fájlnak a telepítő felhasználó tulajdonában lévő, webről el nem érhető könyvtárban kell lennie
- `ConfigValidator`: a `..`-t tartalmazó `manifest_path` értékek mostantól induláskor elutasításra kerülnek

### Módosítva

- `doc/INTEGRATION-GUIDE.md`: időzóna-illesztési előfeltétel hozzáadva — az adatbázis munkamenet-időzónájának egyeznie kell a PHP `date.timezone` beállításával, hogy elkerülhető legyen a nyári/téli időszámítás-védelem téves működése; manifest írásvédelmi biztonsági megjegyzés hozzáadva a 6. lépés elé
- `doc/MANIFEST-FORMAT.md`: explicit „job_key átnevezése" szakasz hozzáadva, amely figyelmeztet, hogy egy kulcs átnevezése elveszíti az összes admin testreszabást, és megadja az adatbázis-migrációs megoldást
- `doc/reviewed/LOCKING.md`: az elavult zárolás észlelésének leírása frissítve, tükrözve, hogy a PID-ellenőrzés mostantól minden zárolásfájl-létezés-ellenőrzésnél lefut, nem csak az mtime küszöb elérése után

---

## 1.0.0 — 2026-05-20

| Kategória | Leírás |
|-----------|--------|
| Hozzáadva | Kezdeti kiadás — teljes cron admin modul kiemelve a JupitERP-ből |

### Hozzáadva

- Manifest-vezérelt feladatdeklaráció (`cron/jobs.php`) teljes validációval és soft-delete szinkronizálással
- Ütemező nyári/téli időszámítás-védelemmel és `* * * * *` 1 perces granularitási feltételezéssel
- Dispatcher-ciklus kill-switch kapuval, mtime-vezérelt manifest-szinkronizálással (szinkronizálási flock-kal), POSIX flock + PID elavultság-észleléssel
- JobRunner: kimenet rögzítése (8 KB, UTF-8-biztos), háromállapotú e-mail értesítés (2 KB kivonat), ActivityLogger integráció
- AdminActions: feladatonkénti szerkesztő ablak (AJAX saveOne), engedélyezés/letiltás kapcsoló, „Futtatás most" lekérdezéssel, dispatcher kill switch
- Önálló vanilla CSS (`cron-admin.css`) + Bootstrap 5 változat (`cron-admin-bootstrap.css`)
- Vanilla JS (`cron-admin.js`) beépített modal, értesítés és megerősítés tartalék megoldásokkal
- Nyelvi fájlok: `en_US` és `hu_HU`, az összes `TEXT_CRON_*` és `TEXT_DAY_OF_WEEK_*` kulccsal
- Séma: `schema/cron_jobs.sql` (zöldmezős) + `schema/migrations/0001_uplift_from_v2_85_0.sql` (JupitERP-felemelés)
- Dokumentáció: `INTEGRATION-GUIDE.md`, `MANIFEST-FORMAT.md`, `ADAPTER-INTERFACES.md`, `reviewed/LOCKING.md`, `reviewed/SECURITY.md`
