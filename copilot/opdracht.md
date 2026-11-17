# Opdracht – Swipe-richting evenementinformatie herstellen

## Repository en branch
- Repository: `ronhenderyckx/DONDEBAILAR`
- Werk uitsluitend op branch: `copilot/php-review-fixes`.
- Lees eerst de volledige actuele `copilot/README.md` en deze volledige opdracht.
- Controleer dat je op de genoemde repository en branch werkt. Gebruik de controles die jouw beschikbare omgeving ondersteunt.
- **Je hoeft niet per se een lokale Git-werkkopie of terminal te hebben.** Je mag werken met de beschikbare Copilot-werkomgeving, GitHub-editor, GitHub-connector/API of lokale Git-tools.
- Gebruik de meest veilige beschikbare werkwijze. Een gerichte patch heeft de voorkeur.
- Als een omgeving alleen volledige-bestandsvervanging ondersteunt, mag dat uitsluitend wanneer je de volledige actuele inhoud van het bestand hebt opgehaald en gecontroleerd, de wijziging daarop nauwkeurig toepast, en de volledige resulterende diff kunt controleren. Overschrijf nooit een bestand op basis van afgekapt, gedeeltelijk of onzeker bronmateriaal.
- Als je de volledige bron of betrouwbare diff niet kunt verkrijgen, wijzig dat bestand niet en rapporteer precies wat blokkeert. Stop alleen voor het betreffende onveilige deel; voer ander onafhankelijk, veilig werk alleen uit als dat de opdracht niet in gevaar brengt.

## Doel
- Een horizontale swipe naar links opent/toont de extra evenementinformatie.
- Een horizontale swipe naar rechts sluit/verbergt de extra evenementinformatie.
- Dit gedrag werkt zowel in de gewone agenda als na AJAX-filterverversingen.
- De media-galerij en de swipebediening daarvan blijven volledig ongemoeid.

## Controleer eerst
1. Controleer repository, branch en actuele branch-tip.
2. Controleer welke wijzigingen al op de branch aanwezig zijn. Overschrijf geen onverwachte of niet-gerelateerde wijzigingen.
3. Lees de volledige actuele bronbestanden die relevant zijn voor evenementinformatie, swipe-afhandeling en AJAX-herinitialisatie.
4. Onderzoek de samenhang in de volledige applicatie voordat je beslist welke onderdelen aangepast moeten worden.
5. Stel vast of database- of migratiewijzigingen werkelijk noodzakelijk zijn. Ga er niet automatisch van uit.

## Toegestane wijzigingsomvang – volledige repository
Er is expliciete toestemming om **alle noodzakelijke bestanden en onderdelen binnen deze repository** aan te passen om de gevraagde swipe-fix volledig en correct uit te voeren. Dit omvat waar nodig JavaScript, PHP, HTML, CSS, templates, configuratie, documentatie, tests en database-schema-/migratiebestanden.

Deze toestemming is geen opdracht tot een brede herbouw. Wijzig uitsluitend wat aantoonbaar noodzakelijk is voor het gevraagde gedrag en behoud alle niet-gerelateerde functionaliteit.

### Database en migraties
- Databasewijzigingen en migratiebestanden zijn toegestaan als ze technisch noodzakelijk zijn voor deze fix.
- Een databasewijziging mag alleen op een lokale of expliciet niet-productie testdatabase worden uitgevoerd.
- Maak vóór iedere lokale/testdatabasewijziging een bruikbare backup en controleer dat die backup leesbaar en bruikbaar is.
- Voer geen migratie of wijziging uit op productie, hosting of de Raspberry Pi.
- Als er een migratie nodig is voor de latere Pi-update, maak dan het migratiebestand en beschrijf de exacte handmatige uitvoerstappen, volgorde en backupwaarschuwingen in `copilot/resultaat.md`.
- Als geen databasewijziging nodig is, voer dan geen databaseactie uit.

## Gewenste swipewerking
- Zoek alle relevante handlers voor de extra evenementinformatie, waaronder touch-, pointer- en eventuele gedeelde handlers.
- Zorg dat een swipe naar links de informatie opent en een swipe naar rechts de informatie sluit.
- Zorg dat de AJAX-filterverversing dezelfde correcte richting gebruikt en dat handlers na verversing niet ontbreken of dubbel worden geregistreerd.
- Een eerdere inspectie vond onder meer `setEventDescription(..., dx > 0)` in de gewone agenda en AJAX-code. Controleer de actuele implementatie opnieuw; behandel deze oude bevinding niet als bewijs van de huidige bron.
- Controleer alle relevante codepaden zodat geen tegengestelde richting achterblijft.

## Behouden functionaliteit
- Behoud de bestaande horizontale swipe-drempel exact (eerder vastgesteld op 45 pixels).
- Behoud de bestaande bescherming tegen verticale scroll exact (eerder vastgesteld op verhouding 1,25).
- Behoud bestaande event-listeners, cleanup, navigatiegrenzen, touch-/pointer-afhandeling en overige agenda-functionaliteit.
- Wijzig de media-galerij niet.
- Geen onnodige refactor, herstructurering of extra functionaliteit.

## Wijzigings- en diffcontrole
- Gebruik een gerichte patch waar mogelijk.
- Controleer vóór het opslaan/committen de volledige wijziging, de volledige diff en de lijst met gewijzigde bestanden.
- Bij gebruik van GitHub-editor/API: haal eerst de volledige actuele inhoud op; controleer dat geen bronmateriaal ontbreekt of is afgekapt; vergelijk daarna de volledige oude en nieuwe inhoud. Als dit niet betrouwbaar kan, voer die wijziging niet uit.
- Neem geen tijdelijke bestanden, secrets, gebruikersdata, backups of build-artifacts op in een codecommit.
- Ieder gewijzigd bestand moet aantoonbaar nodig zijn; vermeld de reden per bestand.
- Controleer dat de swipe-drempel, verticale-scroll-bescherming en mediagalerij niet onbedoeld zijn gewijzigd.

## Tests en controles
Voer de controles uit die in de beschikbare omgeving mogelijk zijn en vermeld exacte commando's of handelingen en de echte resultaten:
- JavaScript-syntaxcontrole voor gewijzigde JS-bestanden.
- PHP-syntaxcontrole voor gewijzigde PHP-bestanden.
- Relevante bestaande tests.
- Controle van de gewone agenda en AJAX-filtercode.
- Controle dat links opent, rechts sluit, de drempel en verticale-scroll-bescherming behouden zijn en de mediagalerij onaangeroerd bleef.
- Browser-, touch- en mobiele tests alleen als ze werkelijk zijn uitgevoerd.
- Als een test niet beschikbaar is, vermeld `NIET UITGEVOERD` met reden. Verzin geen resultaten.

## Committoestemming
- Er is expliciete toestemming voor maximaal **één applicatiecodecommit** op uitsluitend `copilot/php-review-fixes`.
- Die commit mag alle repositorybestanden bevatten die aantoonbaar noodzakelijk zijn voor de swipe-fix, inclusief noodzakelijke migratiebestanden.
- Controleer de volledige diff en gewijzigde-bestandenlijst vóór de commit.
- Maak geen codecommit als de diff ongerelateerde wijzigingen, onveilige wijzigingen of onvolledig gecontroleerde bestanden bevat.
- Een afzonderlijke commit die uitsluitend `copilot/resultaat.md` bijwerkt, is toegestaan.
- Geen andere commits, branches of tags. Geen PR, merge, rebase of cherry-pick.

## Raspberry Pi en productie – uitsluitend handmatige update door gebruiker
- De Raspberry Pi mag niet worden benaderd of aangepast door Copilot.
- Voer geen SSH-, SCP-, SFTP-, rsync- of andere overdracht naar de Pi uit.
- Voer op de Pi geen installatie, synchronisatie, configuratiewijziging, databasewijziging, migratie, serviceherstart of deployment uit.
- Raak productie en hosting niet aan; voer geen release of deployment uit.
- De gebruiker voert de update naar de Raspberry Pi later volledig handmatig uit.
- Bereid waar nodig duidelijke instructies voor de handmatige update voor, inclusief bestanden, volgorde, eventuele migratiecommando's en backupwaarschuwingen.
- Vermeld expliciet in het resultaat dat de Pi niet is bijgewerkt.

## Verboden handelingen
- Geen wijzigingen aan `main` of `develop`.
- Geen productie-, hosting-, Raspberry Pi- of deploymenthandelingen.
- Geen verbinding met of overdracht naar de Raspberry Pi.
- Geen ongerelateerde wijzigingen of brede herbouw.
- Geen reset, force-push, stash of overschrijven van onverwachte wijzigingen.
- Geen PR, merge, rebase, cherry-pick, extra branch of tag.
- Stop en rapporteer `GEBLOKKEERD` als repository/branch niet klopt, rechten ontbreken of een wijziging niet betrouwbaar kan worden gecontroleerd.

## Rapportage in copilot/resultaat.md
Vul na uitvoering de volledige uitkomst in `copilot/resultaat.md`. Vermeld:
1. repository, branch en gecontroleerde begin-/eindcommit;
2. gebruikte werkomgeving en wijzigingsmethode (lokale patch, Copilot-werkomgeving, GitHub-editor/API, enz.);
3. oorzaak van het probleem en relevante codepaden;
4. ieder gewijzigd bestand met reden;
5. exact gedrag in gewone agenda en na AJAX-filterverversing;
6. bewijs dat 45px-drempel en verticale-scroll-bescherming behouden zijn;
7. bevestiging dat de media-galerij niet is gewijzigd;
8. alle werkelijk uitgevoerde controles/tests met exacte resultaten;
9. niet-uitgevoerde controles met reden;
10. eventuele database-/migratiebestanden, backup en uitgevoerde lokale/testmigraties;
11. codecommit-ID en volledige bestandslijst, of reden waarom geen codecommit is gemaakt;
12. benodigde handmatige update-instructies voor de Raspberry Pi;
13. bevestiging dat productie, hosting en Raspberry Pi niet zijn benaderd of gewijzigd;
14. algemene eindstatus: uitsluitend `GESLAAGD`, `MISLUKT`, `NIET UITGEVOERD` of `GEBLOKKEERD`.

Stop na het bijwerken van `copilot/resultaat.md` en wacht op verdere instructies.
