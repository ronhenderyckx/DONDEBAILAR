# DONDEBAILAR

Regionale agenda en sociale feed voor dansactiviteiten en evenementen.

## Projectstructuur

- `app/` – gedeelde applicatielogica, bootstrap en databaseverbinding
- `config/` – configuratievoorbeelden; lokale geheimen blijven buiten Git
- `database/migrations/` – versiebeheer van databasewijzigingen
- `public/` – publieke webbestanden en entry points
- `includes/` – gedeelde PHP-onderdelen
- `modules/` – functionele modules
- `languages/` – taalbestanden (NL, EN, ES, FR)
- `assets/` – CSS, JavaScript en afbeeldingen
- `uploads/` – gebruikersuploads (niet versioneren)
- `api/` – API-endpoints
- `cron/` – geplande taken
- `storage/` – tijdelijke en gegenereerde bestanden (niet versioneren)
- `tests/` – tests

## Lokale start

1. Gebruik PHP 8.1+ en MySQL 8.0+.
2. Kopieer `config/config.example.php` naar `config/config.local.php`.
3. Vul in `config/config.local.php` de lokale databasegegevens in. Dit lokale bestand wordt door Git genegeerd.
4. Maak de lege database `dondebailar` aan.
5. Voer de SQL-migraties in numerieke volgorde uit. Op een bestaande installatie die migraties 001 t/m 004 al heeft toegepast, voer je alleen de nieuwe migratie 005 uit:
   `database/migrations/005_event_media_reels.sql`.
   Registreer de migratie daarna in `schema_migrations` als die tabel op de installatie wordt gebruikt.
6. Voer op bestaande installaties ook migratie 006 uit om openbare Facebook- en Instagram-berichten/reels aan evenementen te koppelen.\n7. Zorg voor voldoende PHP-uploadruimte voor media: `upload_max_filesize=80M` en `post_max_size=256M`. De applicatie controleert foto's tot 15 MB en video's/reels tot 80 MB per bestand.
8. Start de lokale server:

   ```powershell
   D:\Projecten\DONDEBAILAR\.tools\php-8.1.34\php.exe -S 127.0.0.1:8000 -t D:\Projecten\DONDEBAILAR\public
   ```

8. Open `http://127.0.0.1:8000`.

## Architectuurafspraken

- Iedere regio krijgt een eigen subdomein en eigen instellingen.
- De platformbeheerder bepaalt wie welke regio's mag beheren.
- Een regio kan meerdere beheerders hebben; één beheerder kan meerdere regio's beheren.
- Regionale toegang wordt altijd server-side gecontroleerd.
- De gebruikersvoorkeurstaal staat los van de inhoudstaal van een evenement.
- Geheimen, lokale configuratie, database-dumps, uploads, logs, sessies en de lokale PHP-runtime worden nooit naar Git gecommit.

## Status

De eerste technische basis is in opbouw. De volledige applicatie, beheeromgeving en regionale feed zijn nog niet geïmplementeerd.
