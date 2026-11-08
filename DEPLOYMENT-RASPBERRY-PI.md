# DONDEBAILAR op een Raspberry Pi

Dit document beschrijft de afgescheiden **testinstallatie** op een Belgische Raspberry Pi. De bestaande VPN-Pi in Spanje wordt niet gebruikt of aangepast.

## Versies en werkwijze

- `main`: goedgekeurde basis / later productie.
- `develop`: ontwikkel- en testversie. Nieuwe ChatGPT-codewijzigingen worden bij voorkeur eerst hier geplaatst.
- De test-Pi haalt uitsluitend `develop` op.
- Productie wordt pas ingericht nadat de testomgeving en de eerste applicatiefuncties gecontroleerd zijn.
- Een update voert op dit moment **geen database-migraties** uit. Migraties krijgen een afzonderlijke, gecontroleerde stap.

## Voorgestelde mappen

- Testcode: `/var/www/dondebailar-test`
- Publieke documentroot van de testsite: `/var/www/dondebailar-test/public`
- Lokale configuratie: `/var/www/dondebailar-test/config/config.local.php`
- Gebruikersuploads: `/var/www/dondebailar-test/uploads/`
- Back-ups: `/var/backups/dondebailar/`

De webserver moet alleen de map `public/` aanbieden. Geef geen rechtstreekse webtoegang tot `app/`, `config/`, `database/`, `storage/` of `uploads/`.

## Eenmalige voorbereiding

1. Installeer Raspberry Pi OS 64-bit en werk het systeem bij.
2. Installeer Git, PHP CLI met de benodigde extensies, een database-server en Apache. De exacte pakketlijst wordt afgestemd op de gekozen Pi en Raspberry Pi OS-versie.
3. Maak een aparte Linux-gebruiker voor deployment aan. Gebruik geen root-account voor Git.
4. Maak in GitHub een **read-only deploy key** voor deze Pi en voeg de publieke sleutel toe aan de private repository `ronhenderyckx/DONDEBAILAR`. Bewaar de private sleutel alleen op de Pi.
5. Clone de testbranch:
   ```bash
   sudo mkdir -p /var/www
   sudo chown "$USER":"$USER" /var/www
   git clone --branch develop git@github.com:ronhenderyckx/DONDEBAILAR.git /var/www/dondebailar-test
   ```
6. Maak `config/config.local.php` op de Pi op basis van `config/config.example.php`. Vul eigen test-databasegegevens in. Commit dit bestand nooit.
7. Beperk leesrechten op de configuratie en zorg dat de PHP/webserver alleen de noodzakelijke schrijfrechten op uploads en runtime-mappen krijgt.
8. Configureer Apache met `DocumentRoot /var/www/dondebailar-test/public` en HTTPS voordat de testsite extern bereikbaar wordt.

## Een testupdate uitvoeren

Voer als deployment-gebruiker uit:

```bash
cd /var/www/dondebailar-test
bash scripts/update-test.sh
```

Het script:
- weigert te werken als de repository niet op branch `develop` staat;
- weigert lokale, niet-gecommitte wijzigingen te overschrijven;
- maakt eerst een Git-bundleback-up van de huidige repositorygeschiedenis;
- haalt de nieuwste `develop` op met fast-forward-only;
- controleert de PHP-syntax van alle gevolgde PHP-bestanden;
- zet de code terug naar de vorige commit als de syntaxcontrole mislukt.

De Git-bundle bevat de Git-geschiedenis, **geen database of uploads**. Maak daarom afzonderlijke database- en uploadback-ups voordat echte gegevens worden gebruikt.

## Wat een update niet doet

- Geen database verwijderen of opnieuw aanmaken.
- Geen migraties automatisch uitvoeren.
- Geen configuratie, wachtwoorden of uploads vervangen.
- Geen productiebranch bijwerken.
- Geen automatische publieke webhook of onbeveiligde updatepoort openen.

## Later toe te voegen

Voordat productie wordt geactiveerd:
- databaseback-up en herstelprocedure testen;
- versiebeheer voor database-migraties bouwen;
- automatische tests en een HTTP-healthcheck toevoegen;
- onderhoudsvenster en rollback van code én database ontwerpen;
- aparte productieconfiguratie, domein, HTTPS en back-upretentie instellen.

## Huidige projectstatus

De repository bevat de eerste technische basis en gedeelde onderdelen. De volledige website, installatieprocedure, database-migratierunner en productieconfiguratie zijn nog niet af. Deze handleiding bereidt de veilige updatewerkwijze voor; ze betekent niet dat de Pi al geïnstalleerd of bereikbaar is.
