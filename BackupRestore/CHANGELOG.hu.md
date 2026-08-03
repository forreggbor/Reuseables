# Changelog (magyar)

Ez a fájl a projekt lényeges változásait dokumentálja.

A formátum a [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) elveit követi,
a verziószámozás pedig a [Semantic Versioning](https://semver.org/spec/v2.0.0.html) szabványt.

## [0.1.2] - 2026-08-03

| Kategória   | Leírás |
|-------------|--------|
| Biztonság   | Javítva egy hiba, amely miatt egy sérült/hamisított mentés visszaállítása a célterületen kívüli fájlokat törölhetett |
| Biztonság   | A visszaállítási/mentési audit-napló többé nem marad csendben, ha a naplózó modul hiányzik |
| Biztonság   | Shell-hozzáférés nélküli hosztokon a sikertelen adatbázis-visszaállítás azonnal leáll, ahelyett hogy egy már hibás mentésfájlon tovább dolgozna |
| Biztonság   | A katasztrófa-helyreállító visszaállító szkript többé nem jelez sikert, ha az adatbázis-visszaállítás valójában meghiúsult |
| Változtatva | Egyszerűsítve a részleges adatbázis-visszaállítás belső idegenkulcs-kezelő kódja |

### Biztonság
- Javítva egy hiba a visszaállítás utáni takarítási folyamatban, amely miatt egy speciálisan előkészített vagy sérült mentési archívum a célterületen kívüli fájlok törlését okozhatta — mind közvetlenül a visszaállítás után, mind az elavult visszaállítási fájlok ütemezett takarítása során (#8).
- A mentési/visszaállítási audit-napló többé nem áll le csendben figyelmeztetés nélkül, ha a naplózó modul nem érhető el — ehelyett egyértelmű figyelmeztetés kerül naplózásra (#8).
- Shell-hozzáférés nélküli hosztokon (néhány osztott tárhelyen használt tartalék mód) egy hibás utasításba ütköző adatbázis-visszaállítás mostantól azonnal leáll, ahelyett hogy tovább próbálná feldolgozni egy már meghiúsult mentésfájl hátralévő részét — ezzel elkerülhető, hogy a visszaállítási folyamatot a szerver leállítsa, mielőtt biztonságosan visszavonhatná a saját változtatásait (#9).
- A katasztrófa-helyreállító visszaállító szkript mostantól helyesen sikertelenként jelzi az adatbázis-visszaállítást, ha az valójában meghiúsult shell-hozzáférés nélküli hosztokon — korábban csendben tovább futhatott a hiba után, és sikert jelezhetett (#9).

### Változtatva
- Egyszerűsítve a részleges adatbázis-visszaállítás során használt belső idegenkulcs-kezelő kód, csökkentve az ismétlődő logikát és a jövőbeli következetlenségek kockázatát (#8).

## [0.1.1] - 2026-08-01

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A facade docblockja egy nem létező `handle()` metódusra hivatkozott |

### Javítva
- **Facade docblock pontosítása** — az osztályszintű docblock szerint a hosztnak "handle() burkoló visszatérési értéke köré kell szerveznie" ezeket, de ilyen metódus nem létezik a facade-on. Javítva, hogy a tényleges, közvetlenül hívható metódusfelületet írja le (`restore()`, `backupEngine()`, `profileService()`, `remoteService()`, ...), összhangban a `doc/INTEGRATION-GUIDE.md`-vel és a fájl saját belső megjegyzésével ugyanerről.

## [0.1.0] - 2026-07-12

| Kategória | Leírás |
|-----------|--------|
| Új        | Első önálló kiadás: adatbázis- és fájl-mentés/visszaállítás, automatikus rollbackkel rendelkező atomikus és helyben történő visszaállítás, ütemezett profilok, távoli SFTP-átvitel, és egy önálló katasztrófa-helyreállító szkript |

### Új
- Mentéskészítés (teljes, csak adatbázis vagy csak fájlok) integritás-ellenőrzéssel, listázással, letöltéssel és törléssel.
- Adatbázis-visszaállítás két stratégiával: atomikus (ideiglenes adatbázis-csere, `CREATE DATABASE` jogosultságot igényel) és helyben történő (táblaátnevezéses tartalék) — mindkettő automatikusan visszagörgeti magát hiba esetén, valós, szándékosan előidézett hibákkal tesztelve. Kivétel: ha az atomikus stratégia csere utáni idegenkulcs-újraépítési lépése önmagában meghiúsul, a csere már megtörtént, ezért az ideiglenes adatbázisok szándékosan a helyükön maradnak kézi helyreállításra, ahelyett hogy visszagörgetnék őket.
- Fájl-visszaállítás visszaállítás előtti pillanatkép-készítéssel és automatikus rollbackkel, ha a visszaállítás megszakad.
- Újrafelhasználható mentési profilok napi/heti/havi ütemezéssel és automatikus megőrzési (retention) takarítással.
- Távoli szerverek kezelése és mentések átvitele SFTP-n keresztül, titkosított hitelesítő adat tárolással.
- Önálló, függőségmentes katasztrófa-helyreállító szkript (`standalone/restore.php`), amely akkor is működik, ha az alkalmazás többi része hibás.
- Minden mentési és visszaállítási művelet naplózása az `ActivityLogs` újrafelhasználható modulon keresztül.
- Admin felületek (irányítópult, profilok, távoli szerverek) önálló, külső CSS/JS keretrendszert nem igénylő megjelenéssel.
- Magyar és angol fordítások (214 kifejezés).
- Reprodukálható, végponttól-végpontig tesztelő szkript, amely valós adatbázis ellenében bizonyítja minden funkció működését.

### Javítva
- A katasztrófa-helyreállító szkript adatbázis-lekérdezései többé nem zavarodnak össze egyes adatbázis-szerverek tájékoztató üzeneteitől — ez egy ritka szélsőérték-eset volt, amely újabb adatbázis-szerver verziókon meghiúsíthatta a helyreállító szkriptet.
