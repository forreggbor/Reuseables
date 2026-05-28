# Változásnapló

A CronAdmin modul összes jelentős változása ebben a fájlban kerül dokumentálásra.

Formátum: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + Szemantikus verziózás.

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
