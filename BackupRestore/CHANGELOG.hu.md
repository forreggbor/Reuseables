# Changelog (magyar)

Ez a fájl a projekt lényeges változásait dokumentálja.

A formátum a [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) elveit követi,
a verziószámozás pedig a [Semantic Versioning](https://semver.org/spec/v2.0.0.html) szabványt.

## [0.3.2] - 2026-09-22

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A `createFileArchive()` már nem próbálja becsomagolni a saját folyamatban lévő temp munkakönyvtárát, ha a tempPath a rootPath alá van ágyazva |
| Javítva   | A `.git` és más ponttal kezdődő kizárások/befoglalások mostantól tényleg érvényesülnek (mindkét tar-készítési háttérnél) |
| Javítva   | Egy nem végzetes `tar` figyelmeztetés (exit code 1) mostantól nem szakítja meg az egyébként sikeres mentést |

### Javítva
- A `BackupEngine::createFileArchive()` csak a mentési könyvtárat és a `.git`-et zárta ki. Ha a hoszt a temp útvonalát a projekt gyökere alá konfigurálta (pl. `<base>/storage/temp`), a folyamatban lévő `files.tar` a saját maga által becsomagolt fába került, és a `tar` megtagadta a mentését ("archive cannot contain itself; not dumped"). Az `Excludes::always()` mostantól feltétel nélkül kizárja a temp útvonalat, ha az a rootPath alá esik, ugyanúgy, ahogy a visszaállítás-oldali `Excludes::fileSync()` már eddig is tette (Reuseables#40).
- Mindkét tar-készítési háttér (`Exec\ShellHelper::tarCreate()` és `Exec\PhpHelper::tarCreate()`) az `ltrim($path, './')`/`trim($path, './')` hívással normalizálta a kizárási/befoglalási útvonalakat, ami egy ponttal kezdődő útvonal (pl. `.git`) elejéről is levágja a pontot (`.git` → `git`), mivel a második argumentum karaktermaszk, nem szó szerinti előtag. Minden ponttal kezdődő kizárás/befoglalás némán soha nem talált — egy valódi TFL-ERP mentésben bizonyítottan a teljes `.git` bekerült, holott az `Excludes::always()` explicit kizárta. A javítás mostantól csak a szó szerinti `./` előtagot vágja le (Reuseables#42).
- Az `Exec\ShellHelper::tarCreate()`/`tarCreateGz()` a `tar` 1-es kilépési kódját (nem végzetes — „egyes fájlok eltérnek", pl. egy session-fájl a beolvasás közben megváltozott) ugyanúgy kezelte, mint a 2-es (végzetes) kódot, így egy élő fájlrendszeren átmeneti, gyakorlatilag elkerülhetetlen állapot is megszakította a teljes mentést. Az 1-es kilépési kód mostantól figyelmeztetésként naplózódik, az archívum pedig sikeresen elkészültnek számít; csak a 2-es vagy magasabb kód jelent hibát (Reuseables#41).

## [0.3.1] - 2026-09-22

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A mentési profil „Futtatás most” gombja mostantól átadja a hosztnak, melyik profilt kell futtatni, így a profil kizárási/befoglalási útvonalai érvényesülnek |
| Javítva   | A mentési profil és a távoli szerver létrehozása/módosítása/törlése/tesztelése mostantól naplózásra kerül |

### Javítva
- A profilok oldal „Futtatás most” gombja csak a mentés típusát és egy megjegyzést küldött, így a hoszt nem tudta alkalmazni a profil befoglalt/kizárt útvonalait — egy mappát kizáró profil is becsomagolta azt. A kérés mostantól a `profile_id`-t is tartalmazza; az ezt kezelő hoszt betölti a profilt és annak útvonalait (és azonosítóját) adja át a `createBackup()`-nak, a mezőt figyelmen kívül hagyó hoszt pedig pontosan úgy működik, mint eddig (Reuseables#38).
- A `ProfileService` egyáltalán nem naplózott, a `RemoteService` pedig csak a gazdakulcs visszaállítási/rögzítési eseményeit — egy mentési profil vagy távoli szerver létrehozása, módosítása, törlése vagy tesztelése nyomtalanul maradt az aktivitásnaplóban. Mindkét szolgáltatás mostantól naplóz létrehozáskor/módosításkor/törléskor (távoli szervernél sikeres kapcsolat-tesztkor is), ugyanazt az `acting_user_id` mezőt használva, amit a hoszt már eddig is átadott a gazdakulcs-visszaállításhoz. A `ProfileService::delete()` és a `RemoteService::delete()`/`testConnection()` egy opcionális záró `$actingUserId` paramétert kapott; a meglévő, e nélküli hívások továbbra is működnek, csak a naplóbejegyzés marad felhasználó nélküli (Reuseables#39).

## [0.3.0] - 2026-09-16

| Kategória | Leírás |
|-----------|--------|
| Új        | Az admin nézetek nonce-alapú Content-Security-Policy mellett is működnek (nincs beágyazott eseménykezelő) |
| Új        | Feltöltött mentési archívum regisztrálható a motoron keresztül |
| Változott | Szinkronizáláskor a hosztnak újra kell másolnia a `js/backup-restore.js` fájlt |
| Javítva   | Elgépelés a magyar „Biztonsági mentés & Visszaállítás” címsorban |

### Új
- Az `index`, `profiles` és `remote-servers` admin nézetek már nem használnak beágyazott `onclick`/`onchange` attribútumokat. A gombok és választók `data-br-action` / `data-br-change` attribútumot kapnak, amelyet a `js/backup-restore.js` a változatlan, nyilvános `BackupRestoreUI` API-ra irányít. Így azok a hosztok is működnek, amelyek CSP-je tiltja a beágyazott eseménykezelőket, anélkül hogy lazítaniuk kellene a szabályzaton. A saját nézettel rendelkező hosztok, amelyek továbbra is beágyazva hívják a `BackupRestoreUI.*` függvényeket, változatlanul működnek.
- A `BackupEngine::registerUploadedArchive()` metódussal a hoszt egy már a mentési könyvtárba helyezett archívumot (pl. admin feltöltést) befejezett mentésként regisztrálhat, tartalmazási és integritás-ellenőrzéssel és `upload_backup` naplóbejegyzéssel. Korábban a hosztnak közvetlenül kellett a modul `backups` táblájába írnia.

### Változott
- Mivel a nézetek és a JS a `data-br-*` szerződésen keresztül összefüggnek, a `views/` szinkronizálásával együtt a hosztnak a `js/backup-restore.js` fájlt is újra kell másolnia a nyilvános asset könyvtárába (az integrációs útmutatóban leírt telepítési lépés). Ha csak az egyik frissül, a gombok nem reagálnak.

### Javítva
- A magyar vezérlőpult-címsor „Visszaéllítás” helyett most már „Visszaállítás”.

## [0.2.0] - 2026-08-31

| Kategória | Leírás |
|-----------|--------|
| Új        | Az admin nézetek mostantól CSP nonce-ot is támogatnak a beágyazott `<script>` tageken |

### Új
- Az `index`, `profiles` és `remote-servers` admin nézetek mostantól elfogadnak egy opcionális `$nonce` értéket, és minden beágyazott `<script>` tagre alkalmazzák, így a nonce-alapú Content-Security-Policy-t érvényesítő hosztok anélkül engedélyezhetik ezeket a szkripteket, hogy gyengíteniük kellene a policyt.

## [0.1.3] - 2026-08-03

| Kategória | Leírás |
|-----------|--------|
| Javítva   | A shell-hozzáférés nélküli visszaállítás mostantól működik olyan mentéseknél, amelyek shell-hozzáféréssel, normál módon készültek |
| Biztonság | Shell-hozzáférés nélküli hosztokon egy célzottan rosszindulatú mentés visszaállítása többé nem írhat fájlokat a célterületen kívülre |

### Javítva
- A mentés visszaállítása shell-hozzáférés nélküli hosztokon (néhány osztott tárhelyen használt tartalék mód) mostantól helyesen működik az olyan mentéseknél, amelyek normál (shell-hozzáféréssel rendelkező) módon készültek — korábban ez teljes visszaállításnál egyértelmű hibával meghiúsult, részleges/szűkített visszaállításnál pedig csendben, hibaüzenet nélkül kihagyta az adatbázis vagy a fájlok visszaállítását (#10).

### Biztonság
- Shell-hozzáférés nélküli hosztokon a célzottan rosszindulatú mentések elutasítására szolgáló biztonsági ellenőrzés mostantól ténylegesen lefut — korábban ezen a tárhelyi módon csendben kimaradhatott, és csak egy nem dokumentált, véletlenszerű védelemre lehetett hagyatkozni máshol (#11).

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
