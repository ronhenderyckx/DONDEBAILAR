# Opdracht – Swipe-richting evenementinformatie volledig en veilig herstellen

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`.
- Voer deze opdracht uit vanuit de **lokale Git-werkkopie van Copilot**, met terminal en normale patch-/diffmogelijkheden.
- Gebruik voor applicatiecode **geen GitHub Contents API, volledige-bestandsvervanging of andere methode die bestaande bronbestanden blind overschrijft**. Werk vanuit de lokale werkkopie met gerichte patches en controleer volledige bestanden en diffs.
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

## Toegestane wijzigingsomvang – volledige repository

Er is expliciete toestemming om **alle bestanden en onderdelen binnen deze repository** aan te passen wanneer dat aantoonbaar nodig is om de swipe-functionaliteit correct en samenhangend te herstellen. Dit kan onder meer JavaScript, PHP, HTML, CSS, templates, configuratie, documentatie, tests en database-schema-/migratiebestanden omvatten.

Deze toestemming is geen opdracht tot een brede herbouw: wijzig alleen wat functioneel noodzakelijk is voor het gevraagde swipegedrag. Onderzoek eerst waar de functionaliteit werkelijk wordt aangestuurd en volg eventuele afhankelijkheden door de volledige applicatie. Behoud bestaande functionaliteit die niet bij dit probleem hoort.

Database:
- Database-aanpassingen en migratiebestanden zijn toegestaan als ze technisch noodzakelijk zijn voor deze swipe-fix.
- Een migratie mag uitsluitend op een lokale of expliciet niet-productie testdatabase worden uitgevoerd, en alleen nadat een bruikbare backup is gemaakt en gecontroleerd.
- Wijzig of migreer geen productie-, hosting- of Raspberry Pi-database. Daarvoor voert de gebruiker later zelf de handmatige update uit.
- Als de fix geen databasewijziging vereist, voer dan geen databaseactie uit.


## Gewenste codeaanpassing
- Pas de bestaande richtingvoorwaarde in de gewone informatie-swipe-handler(s) aan zodat een horizontale swipe naar links de informatie opent/toont en een swipe naar rechts de informatie sluit/verbergt.
- Pas dezelfde richting aan in de AJAX-filter/herinitialisatie-handler.
- Controleer alle relevante handlers; laat geen tweede handler met de oude tegengestelde richting achter.
- De vorige statische inspectie vond onder meer `setEventDescription(..., dx > 0)` in beide bestanden. Beoordeel de actuele lokale bron opnieuw; wijzig alleen de relevante richtingvoorwaarde(n), niet blind op basis van deze oude bevinding.

## Strikt behouden
- Behoud de bestaande horizontale swipe-drempel exact (eerder vastgesteld op 45 pixels).
- Behoud de bestaande bescherming tegen verticale scroll exact (eerder vastgesteld op de verhouding 1,25).
- Behoud bestaande event-listeners, cleanup, navigatiegrenzen, touch/pointer-afhandeling en overige agenda-functionaliteit.
- Laat de mediagalerij en niet-gerelateerde onderdelen ongemoeid.
- Pas CSS, HTML, PHP, configuratie of andere onderdelen alleen aan als de analyse aantoont dat dit noodzakelijk is voor de gevraagde swipe-fix.
- Geen onnodige refactor, herstructurering of extra functionaliteit.

## Patch- en diffwerkwijze
1. Maak de wijziging met een gerichte patch in de lokale werkkopie (bijvoorbeeld `apply_patch` of een gelijkwaardige contextuele patch).
2. Gebruik geen script dat een onvolledig of afgekapt bestand opnieuw opbouwt.
3. Bekijk vóór commit:
   - `git diff --check`
   - `git diff -- public/assets/js/agenda.js public/assets/js/agenda-filters.js`
   - `git status --short`
4. De volledige diff en status moeten aantonen dat uitsluitend bestanden zijn gewijzigd die aantoonbaar nodig zijn voor de swipe-fix. Vermeld en motiveer ieder gewijzigd bestand. Als dat niet zo is, stop en commit niets. Herstel geen vooraf bestaande wijzigingen van iemand anders.
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
**Uitdrukkelijke toestemming voor maximaal één applicatiecodecommit** op uitsluitend `copilot/php-review-fixes`. Die commit mag alle repositorybestanden bevatten die aantoonbaar noodzakelijk zijn voor de swipe-fix, inclusief eventuele noodzakelijke migratiebestanden. Controleer vóór commit de volledige diff en motiveer ieder gewijzigd bestand. Neem geen tijdelijke bestanden, secrets, gebruikersdata, backups of build-artifacts op. Als de diff ongerelateerd werk bevat of niet veilig kan worden afgebakend, stop dan zonder codecommit en rapporteer `GEBLOKKEERD`.

Een afzonderlijke rapportagecommit voor uitsluitend `copilot/resultaat.md` is toegestaan. Geen commits met andere bestanden.

## Handmatige update naar de Raspberry Pi

- De gebruiker heeft expliciet bepaald dat de update naar de Raspberry Pi **uitsluitend handmatig door de gebruiker** gebeurt nadat de codewijziging is gecontroleerd en goedgekeurd.
- Copilot mag de Raspberry Pi niet benaderen of aanpassen.
- Voer geen SSH-, SCP-, SFTP-, rsync- of andere overdrachtsactie naar de Pi uit.
- Voer geen installatie, synchronisatie, configuratiewijziging, databasewijziging, migratie, serviceherstart of deployment op de Pi uit.
- Bereid noodzakelijke migratiebestanden en duidelijke uitvoerinstructies voor de latere handmatige Pi-update wel voor, indien nodig.
- Vermeld in `copilot/resultaat.md` exact welke handmatige stappen de gebruiker later op de Pi moet uitvoeren, inclusief migratievolgorde en backupwaarschuwingen indien van toepassing.
- Maak geen automatische of handmatige productie-/Pi-release.


- De update naar de Raspberry Pi gebeurt **uitsluitend handmatig door de gebruiker** nadat de codewijziging op GitHub is gecontroleerd en goedgekeurd.
- Copilot mag de Raspberry Pi niet benaderen of aanpassen.
- Voer geen SSH-, SCP-, SFTP-, rsync- of andere overdrachtsactie naar de Pi uit.
- Voer geen installatie, synchronisatie, configuratiewijziging, serviceherstart of deployment op de Pi uit.
- Maak geen automatische of handmatige productie-/Pi-release.
- Vermeld in `copilot/resultaat.md` expliciet dat de Pi niet is bijgewerkt en dat de gebruiker de update later handmatig uitvoert.
- Stop na de codewijziging (indien veilig), de toegestane commit en het invullen van `copilot/resultaat.md`.

## Verboden handelingen
- Geen verbinding maken met of wijzigingen uitvoeren op de Raspberry Pi; de Pi-update is handmatig door de gebruiker.
- Geen wijzigingen aan `main` of `develop`.
- Geen branch/tag, pull request, merge, rebase of cherry-pick.
- Geen database- of migratieacties op productie, hosting of Raspberry Pi. Lokale/niet-productie databasewijzigingen zijn alleen toegestaan volgens de expliciete backup- en verificatievoorwaarden hierboven.
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
9. lijst en motivatie van alle gewijzigde repositorybestanden; bevestiging dat `main`, `develop`, productie en Raspberry Pi niet zijn gewijzigd/aangeraakt; vermeld eventuele lokale/testdatabasewijzigingen en backupbewijs;
10. algemene eindstatus: uitsluitend `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`.

Stop na het bijwerken van `copilot/resultaat.md` en wacht op verdere instructies.
