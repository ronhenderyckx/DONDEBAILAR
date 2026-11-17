# Opdracht – Swipe naar links opent evenementinformatie, swipe naar rechts sluit

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`.
- Controleer vóór elke wijziging de repository, actieve branch en begincommit. Bij een afwijkende branch: stop met status `GEBLOKKEERD`.

## Doel
Wijzig uitsluitend de swipe-richting van de extra evenementinformatie:
- **Swipe naar links:** toon/open de extra evenementinformatie.
- **Swipe naar rechts:** verberg/sluit de extra evenementinformatie.

Dit moet identiek werken in:
1. de gewone agendaweergave;
2. de agenda nadat filters via AJAX zijn ververst.

## Verplichte voorbereiding
1. Lees deze volledige opdracht en de volledige `copilot/README.md`.
2. Controleer en noteer repository, branch en begincommit.
3. Lees de volledige actuele inhoud van beide toegestane JavaScript-bestanden voordat je code wijzigt.
4. Zoek en begrijp de gewone informatie-swipe-handler en de AJAX-handler, inclusief initialisatie/herinitialisatie na filterverversing.
5. Stel vast hoe de bestaande swipe-drempel en bescherming tegen verticale scroll werken. Noteer de bestaande waarden/voorwaarden.

## Enige toegestane applicatiebestanden
Je mag uitsluitend deze twee bestanden aanpassen:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`

Geen enkel ander applicatiebestand mag worden gewijzigd. Als de fix niet veilig en volledig met uitsluitend deze twee bestanden kan, stop dan zonder andere bestanden aan te passen en rapporteer `GEBLOKKEERD`.

## Uit te voeren wijziging
- Corrigeer de richting in de relevante informatie-swipe-handler(s): links opent/toont; rechts sluit/verbergt.
- Zorg dat de AJAX-filterverversing exact hetzelfde gedrag behoudt.
- Behoud de bestaande swipe-drempel exact, zonder deze te verlagen, verhogen of anders te interpreteren.
- Behoud de bestaande verticale-scroll-bescherming exact.
- Behoud bestaande event-listeners, cleanup, navigatiegrenzen en overige agenda-functionaliteit.
- Laat de mediagalerij en de bediening daarvan volledig ongemoeid.
- Geen CSS-, HTML-, PHP-, configuratie-, inhouds- of andere functionele wijzigingen.
- Maak geen onnodige refactor en voorkom dubbele verwerking van één swipegebaar.

## Committoestemming
**Er is uitdrukkelijk toestemming voor maximaal één applicatiecodecommit** op uitsluitend branch `copilot/php-review-fixes`, mits die commit uitsluitend deze twee bestanden bevat:
- `public/assets/js/agenda.js`
- `public/assets/js/agenda-filters.js`

Controleer vóór het committen de volledige wijzigingslijst/diff. Als er ook maar één ander bestand in de codecommit terechtkomt of je dit niet kunt verifiëren, commit de applicatiecode dan niet en rapporteer `GEBLOKKEERD`. Geen tweede applicatiecodecommit.

Een afzonderlijke rapportagecommit voor uitsluitend `copilot/resultaat.md` is toegestaan. Wijzig of verwijder niets onder `copilot/archive/`.

## Controles en eerlijke rapportage
Voer beschikbare controles werkelijk uit en vermeld per controle:
- status: `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`;
- exacte opdracht of werkwijze;
- concrete uitkomst.

Controleer minimaal:
1. JavaScript-syntax van beide bestanden met een daadwerkelijk beschikbare runtime/syntaxchecker. Noteer exact commando en resultaat; als geen runtime beschikbaar is, noteer `NIET UITGEVOERD`.
2. Statisch of via tests dat de gewone informatie-handler links toont en rechts verbergt.
3. Statisch of via tests dat de AJAX-handler na filterverversing dezelfde richting gebruikt.
4. Dat swipe-drempel en verticale-scroll-bescherming ongewijzigd zijn.
5. Dat de diff uitsluitend de twee toegestane bestanden bevat en geen onbedoelde wijzigingen omvat.
6. Relevante bestaande tests, indien aanwezig en uitvoerbaar.

Browser-, touch-, mobiel- en Raspberry Pi-tests mogen alleen als uitgevoerd worden vermeld wanneer ze echt zijn uitgevoerd. Anders expliciet `NIET UITGEVOERD`. Verzin geen tests, commando's, uitkomsten of validaties.

## Strikte beperkingen
- Geen wijzigingen aan `main` of `develop`; blijf uitsluitend op `copilot/php-review-fixes`.
- Geen pull request, merge, rebase, cherry-pick, branch of tag.
- Geen database-, backup- of migratieacties.
- Geen productie-, hosting-, deployment- of releasehandelingen.
- Geen wijzigingen aan andere bestanden of onderdelen.
- Stop bij onverwachte wijzigingen, conflicten of veiligheidsrisico's.

## Rapportage in copilot/resultaat.md
Schrijf de volledige uitkomst naar `copilot/resultaat.md`. Vermeld minimaal:
1. repository, branch en begincommit;
2. welke code vooraf is gelezen en welke handlers zijn gevonden;
3. de exacte codewijziging;
4. het gedrag voor gewone agenda én AJAX-filterverversing;
5. de bestaande swipe-drempel en verticale-scroll-bescherming, met bevestiging dat die behouden zijn;
6. alle daadwerkelijk uitgevoerde controles/tests met exacte commando's en uitkomsten;
7. niet-uitgevoerde tests expliciet als `NIET UITGEVOERD`;
8. codecommit-ID en de geverifieerde bestandslijst, of de reden waarom geen codecommit is gemaakt;
9. bevestiging dat geen andere bestanden, `main`, `develop`, database of productie zijn gewijzigd/aangeraakt;
10. algemene eindstatus en resterende aandachtspunten.

Gebruik uitsluitend de vier statuswaarden uit de README. Stop na het bijwerken van `copilot/resultaat.md` en wacht op verdere instructies.
