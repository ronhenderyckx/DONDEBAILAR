# Opdracht – Swipe-richting evenementinformatie veilig aanpassen vanuit lokale werkkopie

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`.
- Voer deze opdracht uit vanuit de **lokale Git-werkkopie van Copilot**, met terminal en normale patch-/diffmogelijkheden.
- Gebruik voor applicatiecode **geen GitHub Contents API, volledige-bestandsvervanging of andere methode die een heel bestand overschrijft**. De vorige poging is geblokkeerd omdat de GitHub-bestandsinterface geen veilige gerichte patch bood en de uitvoer van `agenda.js` werd afgekapt.
- Als Copilot geen lokale werkkopie met terminal/patchondersteuning heeft, stop dan met `GEBLOKKEERD`. Vervang geen volledige bestanden en improviseer geen andere schrijfroute.

## Doel
- Swipe naar links: open/toon de extra evenementinformatie.
- Swipe naar rechts: sluit/verberg de extra evenementinformatie.
- Dit moet werken in de gewone agenda én na AJAX-filterverversing.
- De mediagalerij en de swipebediening daarvan blijven volledig ongemoeid.

## Verplichte controles vóór wijziging
1. Lees de volledige `copilot/README.md` en deze volledige opdracht.
2. Controleer in de lokale werkkopie:
   - `git rev-parse --show-toplevel`
   - `git branch --show-current`
   - `git status --short`
   - `git rev-parse HEAD`
   - `git ls-remote origin refs/heads/copilot/php-review-fixes`
3. Werk alleen verder als de repository klopt, de actieve branch exact `copilot/php-review-fixes` is, de lokale HEAD overeenkomt met de remote branch-tip en de werkkopie geen onverklaarde wijzigingen bevat. Bij afwijkingen of lokale wijzigingen die niet bij deze opdracht horen: stop zonder ze te overschrijven, resetten, stashen of committen.
4. Lees de volledige actuele inhoud van beide toegestane JavaScript-bestanden in de lokale werkkopie:
   - `public/assets/js/agenda.js`
   - `public/assets/js/agenda-filters.js`
5. Zoek alle relevante informatie-swipe-handlers, waaronder touch-, pointer- en AJAX-herinitialisatiecode. Begrijp de bestaande logica vóór je patcht.

## Enige toegestane applicatiebestanden
Wijzig uitsluitend:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`

Geen enkel ander applicatiebestand mag worden gewijzigd.

## Gewenste codeaanpassing
- Pas de bestaande richtingvoorwaarde in de gewone informatie-swipe-handler(s) aan zodat een horizontale swipe naar links de informatie opent/toont en een swipe naar rechts de informatie sluit/verbergt.
- Pas dezelfde richting aan in de AJAX-filter/herinitialisatie-handler.
- Controleer alle relevante handlers; laat geen tweede handler met de oude tegengestelde richting achter.
- De vorige statische inspectie vond onder meer `setEventDescription(..., dx > 0)` in beide bestanden. Beoordeel de actuele lokale bron opnieuw; wijzig alleen de relevante richtingvoorwaarde(n), niet blind op basis van deze oude bevinding.

## Strikt behouden
- Behoud de bestaande horizontale swipe-drempel exact (eerder vastgesteld op 45 pixels).
- Behoud de bestaande bescherming tegen verticale scroll exact (eerder vastgesteld op de verhouding 1,25).
- Behoud bestaande event-listeners, cleanup, navigatiegrenzen, touch/pointer-afhandeling en overige agenda-functionaliteit.
- Laat mediagalerij, CSS, HTML, PHP, configuratie en alle andere onderdelen ongemoeid.
- Geen refactor, geen herstructurering en geen extra functionaliteit.

## Patch- en diffwerkwijze
1. Maak de wijziging met een gerichte patch in de lokale werkkopie (bijvoorbeeld `apply_patch` of een gelijkwaardige contextuele patch).
2. Gebruik geen script dat een onvolledig of afgekapt bestand opnieuw opbouwt.
3. Bekijk vóór commit:
   - `git diff --check`
   - `git diff -- public/assets/js/agenda.js public/assets/js/agenda-filters.js`
   - `git status --short`
4. De volledige diff en status moeten aantonen dat uitsluitend de twee toegestane JavaScript-bestanden gewijzigd zijn. Als dat niet zo is, stop en commit niets. Herstel geen vooraf bestaande wijzigingen van iemand anders.
5. Controleer expliciet dat de drempel van 45 pixels en de verticale-scroll-voorwaarde/verhouding 1,25 niet zijn veranderd en dat de mediagalerij niet is aangepast.

## Tests en controles
- Voer voor beide bestanden een echte JavaScript-syntaxcontrole uit als `node` of een andere geschikte checker beschikbaar is. Noteer exact commando en resultaat. Als niet beschikbaar: `NIET UITGEVOERD`.
- Voer relevante bestaande tests uit als die beschikbaar zijn; noteer exacte commando's en resultaten.
- Controleer statisch of met tests dat:
  1. de gewone agenda links opent en rechts sluit;
  2. de AJAX-herinitialisatie na filterverversing hetzelfde doet;
  3. de swipe-drempel en verticale-scroll-bescherming ongewijzigd zijn;
  4. geen ongewenste wijzigingen of dubbele verwerking zijn ontstaan.
- Browser-, touch-, mobiele en Raspberry Pi-tests mogen alleen als uitgevoerd worden vermeld als ze echt zijn uitgevoerd. Anders `NIET UITGEVOERD`.
- Verzin geen commando's, tests, resultaten of claims.

## Committoestemming
**Uitdrukkelijke toestemming voor maximaal één applicatiecodecommit** op uitsluitend `copilot/php-review-fixes`, en uitsluitend als die commit exact deze twee bestanden bevat:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`

Commit alleen als alle controles van de diff en bestandslijst slagen. Als niet exact deze twee bestanden veilig in één commit kunnen, maak geen codecommit en rapporteer `GEBLOKKEERD`.

Een afzonderlijke rapportagecommit voor uitsluitend `copilot/resultaat.md` is toegestaan. Geen commits met andere bestanden.

## Verboden handelingen
- Geen wijzigingen aan `main` of `develop`.
- Geen branch/tag, pull request, merge, rebase of cherry-pick.
- Geen database-, backup- of migratieacties.
- Geen productie-, hosting-, deployment- of releasehandelingen.
- Geen wijzigingen aan andere bestanden, ook niet aan workflowbestanden behalve `copilot/resultaat.md`.
- Geen reset, force-push, stash, checkout van andere branches of overschrijven van onverwachte lokale wijzigingen.
- Stop bij ontbrekende rechten, afwijkende branch, onverwachte status of onveilige situatie.

## Rapportage in copilot/resultaat.md
Vul na uitvoering de volledige uitkomst in `copilot/resultaat.md`. Vermeld:
1. repository, branch, lokale begincommit en remote branch-tip;
2. bevestiging dat vanuit de lokale werkkopie met een gerichte patch is gewerkt (of waarom dit niet kon);
3. welke volledige bestanden vooraf zijn gelezen en welke handlers zijn gevonden;
4. de exacte inhoudelijke wijziging en het gedrag voor gewone agenda en AJAX;
5. bestaande swipe-drempel en verticale-scroll-bescherming, met bewijs dat die behouden zijn;
6. alle echte controles/tests, exacte commando's en concrete uitkomsten;
7. niet-uitgevoerde tests expliciet als `NIET UITGEVOERD`;
8. codecommit-ID en geverifieerde bestandslijst, of reden waarom geen codecommit is gemaakt;
9. bevestiging dat geen andere bestanden, `main`, `develop`, database of productie zijn gewijzigd/aangeraakt;
10. algemene eindstatus: uitsluitend `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`.

Stop na het bijwerken van `copilot/resultaat.md` en wacht op verdere instructies.
