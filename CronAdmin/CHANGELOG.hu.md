# Változásnapló

A CronAdmin modul összes jelentős változása ebben a fájlban kerül dokumentálásra.

Formátum: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + Szemantikus verziózás.

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
