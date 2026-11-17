# Opdracht – Maak swipe-richting informatie gelijk aan media

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`

## Doel
Pas de horizontale swipe-bediening van de informatie rechts in de agenda aan, zodat die dezelfde richting en betekenis heeft als de mediagalerij links.

## Gewenste bediening
Voor media én informatie geldt:
- Swipe naar links = vooruit / volgend item of volgende informatiepagina.
- Swipe naar rechts = terug / vorig item of informatie sluiten/teruggaan.

De mediawerking is al de referentie en mag niet onbedoeld veranderen. De informatie rechts moet dezelfde richting volgen.

## Verplichte voorbereiding
1. Lees dit volledige bestand en de volledige `copilot/README.md`.
2. Controleer dat je daadwerkelijk op `copilot/php-review-fixes` werkt. Zo niet: stop met status `GEBLOKKEERD`.
3. Controleer de actuele branch-head en noteer de begincommit.
4. Lees de actuele versies van:
   - `public/assets/js/agenda.js`
   - `public/assets/js/agenda-filters.js`
5. Controleer de bestaande handlers opnieuw; vertrouw niet blind op het vorige rapport. Let op gewone paginaweergave én AJAX-vernieuwde agenda-inhoud.
6. Controleer dat de twee bestanden nog steeds de enige noodzakelijke applicatiebestanden zijn. Als meer bestanden nodig lijken, stop en leg eerst uit waarom in `copilot/resultaat.md`; breid de opdracht niet zelfstandig uit.

## Uit te voeren wijziging
1. Pas uitsluitend deze bestanden aan:
   - `public/assets/js/agenda.js`
   - `public/assets/js/agenda-filters.js`
2. Zorg dat in beide informatie-handlers:
   - een horizontale swipe naar links de informatie opent/toont of vooruitgaat;
   - een horizontale swipe naar rechts de informatie sluit/verbergt of teruggaat.
3. Houd de mediagalerijbediening ongewijzigd: links = volgende media, rechts = vorige media.
4. Houd bestaande swipe-drempels, verticale-scrollbescherming, navigatiegrenzen, event-cleanup en overige functionaliteit intact, tenzij een minimale aanpassing aantoonbaar noodzakelijk is.
5. Voorkom dubbele verwerking van één gebaar en ongewenste overlap tussen touch- en muisbediening.
6. Gebruik waar mogelijk één consistente interpretatie van de swipe-richting; introduceer geen onnodige nieuwe architectuur.
7. Wijzig geen PHP-bestanden, CSS, HTML, database, configuratie of andere applicatiebestanden.

## Tests en controles
- Voer beschikbare JavaScript-syntaxcontroles uit op beide gewijzigde bestanden.
- Voer relevante bestaande tests uit als die beschikbaar zijn.
- Controleer statisch beide informatie-handlers, inclusief de AJAX-handler, op de gewenste richting.
- Controleer dat de mediagalerijcode niet onbedoeld is gewijzigd.
- Browser-, touch- en apparaattests alleen als je die daadwerkelijk kunt uitvoeren. Anders duidelijk `NIET UITGEVOERD vermelden.
- Rapporteer exacte commando's en resultaten; verzin geen tests of uitkomsten.

## Committoestemming – expliciete beperkte uitzondering
De vorige opdracht blokkeerde een codecommit. Voor deze vervolgopdracht geef ik nu uitdrukkelijk toestemming voor:
- maximaal één applicatiecodecommit op `copilot/php-review-fixes`;
- die commit mag uitsluitend de twee hierboven genoemde bestanden bevatten:
  - `public/assets/js/agenda.js`
  - `public/assets/js/agenda-filters.js`
- commit alleen nadat de wijzigingen en beschikbare controles zijn afgerond;
- geen andere applicatiebestanden of workflowbestanden in deze codecommit opnemen.

Je mag daarnaast maximaal één afzonderlijke commit maken die uitsluitend `copilot/resultaat.md` bijwerkt. De vorige opdracht en het vorige resultaat zijn al gearchiveerd; wijzig of verwijder niets onder `copilot/archive/`.

Als de omgeving geen veilige commit met exact deze twee applicatiebestanden kan maken, voer dan geen codecommit uit en rapporteer `GEBLOKKEERD`. Maak in dat geval geen alternatieve commit.

## Strikte beperkingen
- Geen wijzigingen aan `main` of `develop`.
- Geen branches of tags maken.
- Geen pull request, merge, rebase of cherry-pick.
- Geen deployment, release of productie-installatie.
- Geen database-, backup- of migratieacties.
- Geen bestanden verwijderen.
- Geen herstel van de eerder gemelde PHP-bestandsindeling in deze opdracht; dat is afzonderlijk werk.
- Stop bij onverwachte branchstatus, conflicten, onduidelijke code of veiligheidsrisico's en rapporteer `GEBLOKKEERD`.

## Rapportage in copilot/resultaat.md
Vul het volledige resultaat in. Gebruik uitsluitend deze statuswaarden: `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD`, `GEBLOKKEERD`.

Vermeld:
- branch en begincommit;
- bevestiging van de bestaande handlers;
- exacte gewijzigde bestanden;
- hoe links/rechts nu werkt voor media en informatie;
- syntaxcontroles en andere uitgevoerde tests met resultaten;
- commit-ID van de codecommit, indien gemaakt, en bevestiging dat die exact de twee toegestane bestanden bevat;
- eventuele niet-uitgevoerde browser-/apparaatcontroles;
- expliciet dat geen PR, merge, database- of productiehandeling is uitgevoerd;
- algemene eindstatus en resterende aandachtspunten.
