# Opdracht – media automatisch herstarten en verticaal swipen tussen evenementen

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op: `copilot/php-review-fixes`.
- Lees de volledige `copilot/README.md` en deze opdracht.
- Controleer de actuele branch-tip en werk vanuit de actuele inhoud.
- Gebruik de bestaande workflow; geen PR, merge, rebase, cherry-pick of deployment.

## Gewenst gedrag (expliciet door gebruiker bevestigd)
1. Een geüploade video in de agenda-mediaweergave moet na afloop automatisch opnieuw vanaf het begin afspelen. Er mag geen lege dagachtergrond of stilstaand leeg videovlak achterblijven.
2. Een verticale swipe op het media-/videovlak van een evenementkaart navigeert tussen evenementkaarten:
   - swipe omhoog = volgend evenement;
   - swipe omlaag = vorig evenement.
3. De bestaande horizontale swipebediening voor evenementinformatie blijft ongewijzigd:
   - swipe naar links/rechts behoudt de huidige werking zoals in de actuele bron.
4. De bestaande horizontale swipe binnen een mediagalerij blijft werken om tussen media-items van hetzelfde evenement te bladeren.

## Eerst onderzoeken
Controleer de volledige actuele inhoud van minimaal:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`
- `app/views/agenda/event-card.php`
- `public/assets/css/agenda.css`
Zoek alle video-ended handlers, media lazy-loading/release-logica, galerijscroll, event-info swipehandlers en AJAX-initialisatie.
De actuele bron bevat al een ended-handler die bij meerdere media-items naar het volgende media-item gaat, maar bij het laatste item niets doet. Controleer dit opnieuw; ga niet blind uit van deze beschrijving.

## Uitvoering
- Maak de videoherstart robuust voor zowel kaarten met één video als galerijen met meerdere media-items.
- Zorg dat het einde van een video de afgesproken automatische herstart niet verhindert door een bestaande ended-handler die naar een volgende slide springt. Kies een oplossing die geen onbedoelde dubbele afspeelacties veroorzaakt.
- Houd rekening met mobiele browserbeperkingen rond autoplay en geluid. Behoud de bestaande mute-/geluidsvoorkeur. Rapporteer eerlijk wanneer een browser herstart met geluid blokkeert; forceer geen geluid.
- Verticale swipe wordt alleen herkend wanneer de gesture duidelijk verticaal is en de afstand een passende minimumdrempel haalt. Horizontale gallery-swipe, gewone verticale pagina-scroll, knoppen, links en interactieve controls mogen niet onbedoeld navigeren.
- Navigeer naar de aangrenzende evenementkaart in de actuele `.agenda-feed`; sla verborgen/paginering-kaarten over en voorkom navigatie buiten de beschikbare lijst.
- Laat de pagina soepel naar de kaart scrollen, met respect voor `prefers-reduced-motion`.
- Zorg dat de handlers ook na AJAX-filterverversing correct werken, zonder dubbele eventlisteners. Gebruik bij voorkeur één gedelegeerde handler of een aantoonbaar idempotente initialisatie.
- Behoud bestaande horizontale info-swipe, 45px-drempel en verticale-scrollbescherming voor die horizontale gesture. Wijzig de betekenis daarvan niet.
- Behoud bestaande mediagalerijhoogte-/buffer-/lazyloadlogica, tenzij een gerichte aanpassing aantoonbaar noodzakelijk is.

## Toegestane bestanden
Alleen indien noodzakelijk:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`
- `public/assets/css/agenda.css`
- `app/views/agenda/event-card.php`
Geen andere applicatiebestanden wijzigen zonder eerst te stoppen en de noodzaak te rapporteren.

## Verboden
- Geen wijzigingen aan PHP/database-schema/migraties tenzij aantoonbaar onvermijdelijk; bij noodzaak eerst blokkeren en toestemming vragen.
- Geen configuratie, secrets, uploads, gebruikersdata of updater wijzigen.
- Geen productie, hosting of Raspberry Pi benaderen.
- Geen SSH/SCP/SFTP/rsync, installatie, synchronisatie of serviceherstart.
- Geen wijzigingen aan `main` of `develop`.

## Controle en bewijs
- Controleer volledige diff en gewijzigde-bestandenlijst.
- Controleer dat video na ended vanaf tijdstip 0 opnieuw afspeelt; geen dubbele ended-listeners of conflicterende slide-navigatie.
- Controleer swipe omhoog/omlaag op media en begrenzing bij eerste/laatste zichtbare evenement.
- Controleer dat horizontale galerij-swipe en bestaande info-swipe behouden zijn.
- Controleer initialisatie na AJAX-verversing en dat geen dubbele listeners ontstaan.
- Voer JavaScript-syntaxcontrole uit als beschikbaar; noteer exact commando en resultaat.
- Voer beschikbare geautomatiseerde tests uit. Browser-/mobiele tests alleen als ze werkelijk uitgevoerd zijn; verzin geen resultaten.
- Rapporteer alle niet-uitgevoerde tests expliciet.

## Committoestemming
- Maximaal één codecommit is toegestaan, uitsluitend op `copilot/php-review-fixes`.
- Die codecommit mag uitsluitend de hierboven toegestane applicatiebestanden bevatten.
- Controleer de volledige diff vóór commit. Als extra bestanden wijzigen of de diff niet betrouwbaar kan worden gecontroleerd: geen codecommit, status `GEBLOKKEERD`.
- Afzonderlijke workflowcommits voor archivering en `copilot/resultaat.md` zijn toegestaan.
- Geen andere commits, branches, tags, PR, merge, rebase of cherry-pick.

## Rapportage
Vul `copilot/resultaat.md` volledig in met:
1. repository, branch en begin-/eindcommit;
2. gewijzigde bestanden en codecommit-ID;
3. precieze werking van automatisch herstarten;
4. precieze werking van omhoog/omlaag swipen;
5. behoud horizontale media- en info-swipe;
6. AJAX-initialisatie en bescherming tegen dubbele listeners;
7. syntaxcontrole/tests met werkelijk resultaat;
8. niet-uitgevoerde controles;
9. bevestiging dat productie, database en Pi niet zijn aangeraakt;
10. handmatige Pi-updatebestanden (alleen na afzonderlijke goedkeuring);
11. algemene status: `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`.

Stop na het bijwerken van `copilot/resultaat.md`.
