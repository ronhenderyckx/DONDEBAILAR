# Opdracht – Swipe naar links opent evenementinformatie, swipe naar rechts sluit

## Repository en verplichte branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`.
- Controleer de actieve branch vóór je bestanden wijzigt. Bij een andere branch: stop en rapporteer `GEBLOKKEERD`.

## Doel en exact gewenst gedrag
Pas uitsluitend de swipe-bediening van de extra evenementinformatie aan:
- **Swipe naar links:** opent/toont de extra evenementinformatie.
- **Swipe naar rechts:** sluit/verbergt de extra evenementinformatie.

Dit gedrag moet identiek werken:
1. bij de gewone agendaweergave;
2. na een AJAX-filterverversing.

## Verplichte voorbereiding
1. Lees dit volledige bestand en lees `copilot/README.md` volledig.
2. Controleer en noteer repository, actieve branch en begincommit.
3. Lees de actuele volledige inhoud van:
   - `public/assets/js/agenda.js`
   - `public/assets/js/agenda-filters.js`
4. Zoek en begrijp eerst alle relevante handlers voor de extra evenementinformatie, zowel de gewone pagina-handler als de AJAX-handler.
5. Controleer hoe de bestaande swipe-drempel en bescherming tegen verticale scroll zijn geïmplementeerd.
6. Voer pas daarna de minimale codewijziging uit. Ga niet af op een eerder rapport als de actuele broncode daarvan afwijkt.

## Toegestane bestanden
Je mag uitsluitend deze twee applicatiebestanden aanpassen:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`

Wijzig geen enkel ander applicatiebestand. Als je vaststelt dat de opdracht niet uitsluitend met deze twee bestanden veilig kan worden uitgevoerd, stop dan en rapporteer `GEBLOKKEERD` met de reden. Breid de toegestane bestanden niet zelf uit.

## Uitvoering en behoud van bestaande werking
1. Pas de richting van de relevante informatie-swipe-handler(s) aan zodat links opent/toont en rechts sluit/verbergt.
2. Zorg dat dezelfde logica blijft gelden nadat de agenda via AJAX opnieuw is opgebouwd of ververst.
3. Behoud de bestaande swipe-drempel exact.
4. Behoud de bestaande bescherming tegen verticale scroll exact.
5. Behoud bestaande event-listeners, cleanup, navigatiegrenzen en overige agenda-functionaliteit.
6. Laat de mediagalerijbediening ongewijzigd.
7. Pas geen vormgeving, afmetingen, inhoud of andere functionaliteit aan.
8. Voorkom dubbele verwerking van hetzelfde gebaar; wijzig dit alleen als de bestaande code anders niet correct kan werken en motiveer de minimale ingreep.

## Controles en tests
Voer alleen controles uit die in jouw omgeving werkelijk beschikbaar zijn. Rapporteer per controle de exacte opdracht/werkwijze en de echte uitkomst.
- Controleer de syntax van beide gewijzigde JavaScript-bestanden met een beschikbare JavaScript-runtime of syntaxchecker.
- Controleer de gewone informatie-handler op links-openen/rechts-sluiten.
- Controleer de AJAX-handler op hetzelfde gedrag na filterverversing.
- Controleer dat swipe-drempel en verticale-scrollbescherming behouden zijn.
- Controleer dat geen andere functionaliteit of bestanden zijn gewijzigd.
- Voer relevante bestaande tests uit als die beschikbaar zijn.
- Een browser-, touch-, mobiele-apparaat- of Raspberry Pi-test mag uitsluitend als uitgevoerd worden gerapporteerd wanneer die daadwerkelijk is uitgevoerd. Anders noteer je `NIET UITGEVOERD`.

## Committoestemming – expliciet
**JA, een applicatiecodecommit op branch `copilot/php-review-fixes` is toegestaan.**

Omdat de beschikbare GitHub-bestandsbewerking bij opslaan automatisch een commit maakt, geef ik uitdrukkelijk toestemming voor:
- maximaal één applicatiecodecommit;
- uitsluitend de twee toegestane bestanden `public/assets/js/agenda.js` en `public/assets/js/agenda-filters.js` in die commit;
- geen andere bestanden in die codecommit.

Controleer vóór het committen dat de wijziging uitsluitend deze twee bestanden raakt. Als je niet kunt garanderen dat de commit exact deze twee bestanden bevat, maak dan geen codecommit en rapporteer `GEBLOKKEERD`.

Een afzonderlijke rapportagecommit voor uitsluitend `copilot/resultaat.md` is eveneens toegestaan (maximaal één).

De vorige opdracht en het vorige resultaat zijn door ChatGPT gearchiveerd. Wijzig of verwijder niets onder `copilot/archive/`.

## Strikte beperkingen
- Werk uitsluitend op `copilot/php-review-fixes`.
- Raak `main` en `develop` niet aan.
- Geen branches of tags maken.
- Geen pull request, merge, rebase of cherry-pick.
- Geen database-, backup- of migratieacties.
- Geen productie-, hosting-, deployment- of releasehandelingen.
- Geen wijzigingen aan PHP, CSS, HTML, configuratie, database of andere bestanden.
- Verwijder geen bestanden.
- Stop bij conflicten, onverwachte wijzigingen of veiligheidsrisico's en rapporteer `GEBLOKKEERD`.

## Rapportage
Schrijf de volledige uitkomst naar `copilot/resultaat.md`. Gebruik voor algemene status en controles uitsluitend:
- `GESLAAGD`
- `MISLUKT`
- `NIET UITGEVOERD`
- `GEBLOKKEERD`

Vermeld minimaal:
1. repository, gecontroleerde branch en begincommit;
2. welke handlers en bestanden vooraf zijn onderzocht;
3. welke exacte codewijzigingen zijn aangebracht;
4. bevestiging dat links de informatie opent en rechts sluit, zowel normaal als na AJAX-filterverversing;
5. bevestiging dat swipe-drempel en verticale-scrollbescherming behouden zijn;
6. alle werkelijk uitgevoerde syntaxcontroles/tests, inclusief commando's en uitkomsten;
7. browser-/touch-/Raspberry Pi-tests die niet zijn uitgevoerd expliciet als `NIET UITGEVOERD`;
8. codecommit-ID en bevestiging dat die commit exact de twee toegestane JS-bestanden bevat, of waarom geen codecommit is gemaakt;
9. bevestiging dat geen andere applicatiebestanden, `main`, `develop`, database of productie zijn aangeraakt;
10. algemene eindstatus en eventuele resterende aandachtspunten.

Stop na het invullen van het resultaatbestand. Wacht op verdere instructies.
