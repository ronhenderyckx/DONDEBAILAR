# DONDEBAILAR

Regionale agenda voor dansactiviteiten en evenementen.

## Projectstructuur

- `app/` – gedeelde applicatielogica en authenticatie
- `config/` – configuratievoorbeelden; lokale geheimen blijven buiten Git
- `database/` – database-schema's en migraties
- `public/` – publieke webbestanden en entry points
- `includes/` – gedeelde PHP-onderdelen
- `modules/` – functionele modules
- `languages/` – taalbestanden (NL, EN, ES, FR; vertalingen volgen later)
- `assets/` – CSS, JavaScript en afbeeldingen
- `uploads/` – gebruikersuploads (niet versioneren)
- `api/` – API-endpoints
- `cron/` – geplande taken
- `storage/` – tijdelijke en gegenereerde bestanden (niet versioneren)
- `tests/` – tests

## Ontwikkelomgeving

De bestaande lokale ontwikkelomgeving gebruikt PHP 8.1 en MySQL 8.0.
De lokale PHP-server kan gestart worden met:

```powershell
D:\Projecten\DONDEBAILAR\.tools\php-8.1.34\php.exe -S 127.0.0.1:8000 -t D:\Projecten\DONDEBAILAR\public
```

## Belangrijk

Deze repository bevat voorlopig alleen de basisstructuur. De bestaande applicatiebestanden moeten nog vanaf de oorspronkelijke computer worden toegevoegd.

Commit nooit wachtwoorden, database-dumps, lokale configuratiebestanden, uploads, logs, sessies of de lokale PHP-runtime naar Git.
