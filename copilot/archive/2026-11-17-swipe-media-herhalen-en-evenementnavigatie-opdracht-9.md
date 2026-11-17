# Vervolgopdracht – resterende swipe-richtingen in gewone agenda corrigeren

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op: `copilot/php-review-fixes`.
- Lees de volledige `copilot/README.md` en deze opdracht.
- Controleer de actuele branch-tip en werk vanuit de huidige branchinhoud. De vorige gedeeltelijke fix is al aanwezig; behoud die.
- Een lokale terminal is niet verplicht. Gebruik de beschikbare Copilot-werkomgeving, GitHub-editor/API of lokale Git-tools.
- Bij GitHub-bestandsbewerking: haal de volledige actuele inhoud van `public/assets/js/agenda.js` op en controleer dat die niet is afgekapt. Pas de twee gerichte wijzigingen zorgvuldig toe en controleer de volledige oude/nieuwe diff. Geen blinde overschrijving.

## Aanleiding
De vorige codecommit `30ad1810ed8d2915557566925579d1342929c292` heeft de AJAX-handler in `public/assets/js/agenda-filters.js` correct aangepast naar `setEventDescription(info, dx < 0)`.

De controle van de huidige branch toont dat in `public/assets/js/agenda.js` nog twee tegengestelde richtingvoorwaarden staan:
- Touch-handler: `setEventDescription(info, dx > 0)` (rond regel 88).
- Pointer-handler: `setEventDescription(start.info, dx > 0)` (rond regel 106).

Controleer deze actuele bron opnieuw voordat je wijzigt. Regels kunnen intussen verschoven zijn.

## Opdracht – uitsluitend resterende correctie
1. Pas in `public/assets/js/agenda.js` de touch-handler aan zodat een swipe naar links de extra informatie opent en een swipe naar rechts sluit. Gebruik de correcte richtingvoorwaarde `dx < 0`.
2. Pas in hetzelfde bestand de pointer-handler op dezelfde manier aan: `dx < 0`.
3. Laat `public/assets/js/agenda-filters.js` ongemoeid; de AJAX-richting is in de vorige commit al correct aangepast.
4. Zoek in de volledige relevante bron of er nog andere informatie-swipe-handlers met een tegengestelde richting bestaan. Als er geen andere relevante handlers zijn, wijzig niets extra. Als je toch een derde relevante handler vindt, rapporteer die eerst als blokkade en wijzig hem niet zonder nieuwe toestemming.
5. Wijzig geen andere code, CSS, HTML, PHP, database, migraties, configuratie, mediafunctionaliteit of workflowbeleid.

## Behouden
- Horizontale swipe-drempel blijft exact 45 pixels.
- Verticale-scrollbescherming blijft exact verhouding 1,25.
- Bestaande touch-/pointer-listeners, cleanup en navigatie blijven behouden.
- Mediagalerij blijft volledig ongemoeid.
- AJAX-handler in `agenda-filters.js` blijft zoals die is.

## Controle
- Controleer de volledige inhoud en diff van `public/assets/js/agenda.js`.
- Bevestig dat alleen de twee richtingvoorwaarden in dit bestand zijn gewijzigd.
- Bevestig dat `agenda-filters.js` niet is gewijzigd en de AJAX-voorwaarde `dx < 0` behoudt.
- Controleer de 45px-drempel en 1,25-verhouding.
- Voer een JavaScript-syntaxcontrole uit als de omgeving dit ondersteunt. Noteer het exacte commando en resultaat. Als dit niet kan: `NIET UITGEVOERD` met reden.
- Voer geen test uit alsof die is geslaagd wanneer je hem niet echt hebt uitgevoerd. Browser-/mobiele tests alleen rapporteren als ze echt zijn uitgevoerd.

## Committoestemming
- Je hebt expliciete toestemming voor maximaal **één codecommit** voor deze vervolgopdracht, uitsluitend op `copilot/php-review-fixes`.
- De codecommit mag uitsluitend `public/assets/js/agenda.js` bevatten.
- Deze expliciete committoestemming geldt ook als de beschikbare GitHub-schrijfomgeving bij opslaan automatisch een commit maakt.
- Commit alleen na controle van de volledige diff. Geen codecommit als meer bestanden zijn gewijzigd of de diff niet betrouwbaar gecontroleerd kan worden.
- Een aparte rapportagecommit voor uitsluitend `copilot/resultaat.md` is toegestaan.
- Geen andere commits, branches, tags, PR, merge, rebase of cherry-pick.

## Productie en Raspberry Pi
- Productie, hosting en Raspberry Pi niet benaderen of wijzigen.
- Geen SSH/SCP/SFTP/rsync, deployment, installatie, databaseactie of serviceherstart.
- De gebruiker werkt de Raspberry Pi later handmatig bij.
- Vermeld welke bestanden handmatig naar de Pi moeten worden overgezet. Er is voor deze opdracht geen databasewijziging toegestaan of verwacht.

## Rapportage
Vul `copilot/resultaat.md` volledig in met:
1. repository, branch en begin-/eindcommit;
2. gebruikte werkomgeving en methode;
3. bevestiging van de twee gewijzigde voorwaarden en hun gedrag;
4. volledige gewijzigde-bestandenlijst en codecommit-ID;
5. bewijs dat alleen `public/assets/js/agenda.js` is gewijzigd;
6. bevestiging dat AJAX-code, 45px-drempel, 1,25-verhouding en mediagalerij behouden zijn;
7. syntaxcontrole en andere werkelijk uitgevoerde tests, inclusief niet-uitgevoerde controles;
8. bevestiging dat geen database, productie, hosting of Pi is aangeraakt;
9. handmatige Pi-update: uitsluitend het gewijzigde bestand `public/assets/js/agenda.js`, na goedkeuring;
10. algemene status: `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`.

Stop na het bijwerken van `copilot/resultaat.md`.


## Startcommando voor Copilot
Gebruik deze instructie als startcommando voor deze opdracht:

> Lees de volledige actuele `copilot/README.md` en `copilot/opdracht.md` op branch `copilot/php-review-fixes`. Voer de nieuwe gerichte vervolgopdracht uit. Corrigeer de twee resterende swipe-richtingen in `public/assets/js/agenda.js`. Behoud alle bestaande veiligheidsafspraken. Controleer de volledige diff, maak maximaal één toegestane codecommit en vul `copilot/resultaat.md` volledig in. Stop daarna en wacht op controle.
