# DONDEBAILAR
## Projectdoelstelling, functionele werking en technische uitgangspunten

*Centraal werkdocument voor de ontwikkeling van het DONDEBAILAR-platform.*

## Inhoud
1. Project in het kort
2. Gebruikers en rollen
3. Regio's en talen
4. Belangrijkste onderdelen
5. Vaste standaard voor alle beheerlijsten
6. Locaties zoeken via Google Maps
7. Reacties, chat en meldingen
8. Huisstijl en logo's
9. Technische uitgangspunten en beveiliging
10. Raspberry Pi-testomgeving en productie
11. Ontwikkelfasen
12. Documentstatus

---

## 1. Project in het kort

DONDEBAILAR wordt een meertalig, regionaal platform voor dansactiviteiten en dansevenementen. Het helpt dansliefhebbers om activiteiten in hun omgeving te vinden. Organisatoren kunnen hun evenementen bekendmaken en beheren.

De eerste regionale uitwerking is **Marina Alta (Spanje)**. De technische opzet moet uitbreiding naar andere regio's mogelijk maken zonder de basis opnieuw te moeten bouwen.

### 1.1 Activiteiten

De agenda richt zich in eerste instantie op:
- Salsa, bachata en kizomba.
- Danslessen en workshops.
- Dansavonden en socials.
- Festivals en congressen.
- Andere activiteiten die passen binnen een regionale dansagenda.

Bevoegde beheerders moeten categorieën later kunnen uitbreiden en aanpassen.

## 2. Gebruikers en rollen

| Rol | Hoofdtaak |
|---|---|
| **admin** | Volledig platformbeheer |
| **region_admin** | Beheer van toegewezen regio's |
| **editor** | Redactioneel beheer volgens toegekende rechten |
| **organizer** | Beheer van eigen organisator- en evenementgegevens |
| **viewer/bezoeker** | Raadplegen van publiek beschikbare informatie |

Bezoekers zoeken activiteiten en bekijken evenementen, organisatoren en locaties. Organisatoren krijgen een profiel en kunnen na toekenning van rechten hun eigen evenementen beheren. Regionale beheerders beheren hun regio; platformbeheerders beheren het volledige platform.

Rechten worden centraal gecontroleerd. Een gebruiker mag nooit meer gegevens of functies aanpassen dan zijn of haar rechten toelaten. De precieze rechten en eventuele goedkeuringsstappen worden tijdens de ontwikkeling verder uitgewerkt.

## 3. Regio's en talen

### 3.1 Regionale structuur

DONDEBAILAR is één centraal platform met meerdere regio's. Iedere regio kan beschikken over:
- Een eigen naam en herkenbare URL of subdomein.
- Een eigen tijdzone en landinstelling.
- Een eigen logo en kleurenpalet.
- Eigen evenementen, locaties en regionale organisatoren.
- Beheerders die alleen toegang hebben tot de regio's waarvoor ze bevoegd zijn.

Gegevens van verschillende regio's blijven logisch van elkaar gescheiden. Een platformbeheerder kan het geheel beheren.

### 3.2 Meertaligheid

De technische structuur wordt vanaf het begin voorbereid op Nederlands (NL), Engels (EN), Spaans (ES) en Frans (FR). De taalstructuur wordt nu voorzien; de volledige vertalingen volgen later. Evenementen moeten waar nodig vertaalde titels en beschrijvingen kunnen ondersteunen.

## 4. Belangrijkste onderdelen

### 4.1 Evenementen

Een evenement kan bevatten:
- Titel en beschrijving.
- Datum, beginuur en einduur.
- Dansstijl of categorie.
- Organisator.
- Locatie en adres.
- Toegangsprijs en praktische informatie.
- Afbeelding of affiche.
- Publicatiestatus.

Evenementen worden gekoppeld aan de juiste regio, organisator en locatie. Bezoekers moeten activiteiten eenvoudig kunnen terugvinden.

### 4.2 Locaties

Locaties worden centraal beheerd en kunnen aan meerdere evenementen gekoppeld worden. Een locatie bevat waar beschikbaar de naam, het adres, postcode, gemeente/stad, land, geografische coördinaten, Google Maps-verwijzing, telefoonnummer, website en eventueel een logo of afbeelding. De Google Maps-invoer staat beschreven in hoofdstuk 6.

### 4.3 Organisatoren

Organisatoren krijgen een eigen profiel. Na toekenning van de juiste rechten kunnen ze hun eigen evenementen beheren en reageren op vragen over hun evenementen.

### 4.4 Gebruikersbeheer

Gebruikers kunnen een account en profiel hebben. Beheer, wijzigingen en toegang tot functies zijn afhankelijk van de toegekende rol en bevoegdheden.

### 4.5 Publieke kalender als sociale feed

De publieke kalender is standaard een **sociale evenementenfeed**, geïnspireerd op de gebruikservaring van Instagram. Bezoekers krijgen evenementen als visuele kaarten te zien in één doorlopende stroom. De indeling past zich automatisch aan het schermformaat aan.

- **Smartphone:** één evenementkaart per rij, onder elkaar, zodat de feed prettig verticaal te bekijken en te scrollen is.
- **Tablet:** een aangepaste rasterindeling met meerdere kolommen, afhankelijk van de beschikbare schermbreedte.
- **Desktop:** drie of vier evenementkaarten naast elkaar. Zodra een rij vol is, verschijnen de volgende evenementen op de rij eronder. De kolommen passen zich aan de beschikbare breedte aan.
- De feed blijft één doorlopende stroom; alleen de presentatie en het aantal kolommen veranderen per schermformaat.
- Een evenementkaart kan een affiche of foto, titel, datum en uur, locatie, organisator en korte beschrijving tonen.
- **Meerdere media per evenement:** een evenement kan meerdere foto's/afbeeldingen en video's bevatten. In de feed wordt telkens één media-item tegelijk getoond. Als er meerdere media-items zijn, kan de bezoeker horizontaal door de afbeeldingen en video's swipen; de bediening en eventuele positie-indicator passen zich automatisch aan het aantal items aan. Bij één media-item is er geen onnodige swipebediening of navigatie-indicator. De volgorde van de media wordt door de organisator bepaald.
- De mediacarrousel ondersteunt zowel foto's als video's. Video's worden duidelijk herkenbaar weergegeven en starten niet onverwacht met geluid. De bediening moet het gewone verticale scrollen van de feed niet hinderen.
- **Locatie-icoon:** het locatie-icoon op een evenementkaart en in de evenementinformatie is aanklikbaar en opent de bijbehorende locatie in Google Maps. Hiervoor wordt bij voorkeur de opgeslagen Google Maps-link gebruikt; als die ontbreekt, worden de opgeslagen geografische coördinaten gebruikt om de kaartlocatie te openen. Dit werkt op smartphone, tablet en desktop. De gebruiker kan vanuit Google Maps indien gewenst de routebeschrijving starten. Zonder geldige locatiegegevens wordt het icoon niet als actieve link getoond.
- Bezoekers kunnen evenementen openen voor alle details en, volgens de beschikbare rechten, liken, reageren, delen of bewaren.
- **Like-knop:** de like-knop gebruikt een herkenbaar symbool van dansschoenen in plaats van een hartje. Na aanklikken krijgt het dansschoenen-symbool een duidelijke actieve kleur of vorm. Het aantal likes blijft zichtbaar. Dit symbool wordt consequent gebruikt op de evenementkaarten in de feed en in de evenementdetailweergave, op smartphone, tablet en desktop.
- Organisatorprofielen en relevante zoek- en filtermogelijkheden maken deel uit van de publieke ontdekkingservaring.
- De weergave moet snel, overzichtelijk en gebruiksvriendelijk blijven op smartphone, tablet en desktop.
- **Extra informatie via swipe:** op een evenementkaart kan de bezoeker naar rechts swipen om meer informatie over dat evenement te tonen. Dit gebaar werkt zowel wanneer de beweging op de afbeelding/affiche begint als wanneer ze op de tekst van de kaart begint.
- De extra informatie wordt binnen de feed getoond, zodat de bezoeker de overzichtsfeed niet onbedoeld verlaat. De bezoeker kan de uitgebreide weergave weer sluiten en verder scrollen.
- Op apparaten zonder aanraakscherm, zoals een desktopcomputer, is dezelfde extra informatie bereikbaar via een duidelijke klikactie of knop op de evenementkaart.
- De horizontale swipebediening mag het normale verticale scrollen door de feed niet hinderen. De interactie moet ook toegankelijk zijn via toetsenbord en duidelijke bedieningselementen.

De sociale feed is de standaardweergave van de publieke kalender. De exacte werking van de regiokeuze en het tonen van evenementen uit andere regio's wordt nog afzonderlijk vastgelegd.

### 4.6 Bovenbalk en filters van de publieke feed

De publieke feed krijgt bovenaan een bedieningsbalk met een gebruikservaring die aansluit bij Instagram. Vanuit deze balk kan de bezoeker de feed bedienen en evenementen filteren.

- De bovenbalk bevat een duidelijke toegang tot de filtermogelijkheden.
- **Vast zichtbare bovenbalk:** de bovenbalk blijft tijdens het scrollen permanent zichtbaar bovenaan het scherm (sticky/fixed gedrag). De evenementenfeed scrolt eronder door. Dit werkt op smartphone, tablet en desktop en houdt rekening met de beschikbare schermruimte.
- **Filter op organisator:** de bezoeker kan een organisator selecteren en alleen de evenementen van die organisator bekijken.
- **Filter op locatie:** de bezoeker kan filteren op stad of gemeente.
- De actieve filter(s) zijn zichtbaar, zodat duidelijk is waarom bepaalde evenementen worden getoond.
- Een filter kan eenvoudig worden gewijzigd of verwijderd; er moet ook een duidelijke mogelijkheid zijn om alle filters te wissen en terug te keren naar de volledige feed.
- De filterbediening moet goed werken op smartphone, tablet en desktop en mag het bekijken en verticaal scrollen van de feed niet hinderen.

## 5. Vaste standaard voor alle beheerlijsten

**Iedere lijst in de beheeromgeving gebruikt dezelfde opbouw en basisbediening.** Dit geldt voor gebruikers, evenementen, locaties, organisatoren, regio's, categorieën, berichten en alle toekomstige beheermodules.

### 5.1 Overzichtsscherm

Iedere beheerlijst bevat waar relevant:
1. Een zoekveld om records op relevante gegevens te vinden.
2. Een knop **Toevoegen** die een afzonderlijk, leeg invoerscherm opent.
3. Een overzicht van bestaande records met de belangrijkste gegevens.
4. Per record een actie **Aanpassen** die een afzonderlijk invoerscherm opent met de bestaande gegevens ingevuld.
5. Per record een actie **Wissen**, of **Archiveren/Deactiveren** als definitief verwijderen niet veilig of toegestaan is.
6. Paginering bij lange lijsten.
7. Duidelijke meldingen bij geen zoekresultaten en na geslaagde of mislukte acties.

### 5.2 Invoerscherm voor toevoegen en aanpassen

Toevoegen en aanpassen gebeuren op een afzonderlijk scherm; gegevens worden niet rechtstreeks in de overzichtslijst gewijzigd.

- **Toevoegen:** lege velden met passende standaardwaarden.
- **Aanpassen:** bestaande gegevens worden vooraf ingevuld.
- Verplichte velden en invoerformaten worden duidelijk aangegeven en gecontroleerd.
- Elk formulier heeft de acties **Opslaan** en **Annuleren**.
- Na succesvol opslaan keert de gebruiker terug naar de overzichtslijst. Waar mogelijk blijven zoekopdracht en huidige pagina behouden.
- Bij validatiefouten blijven ingevulde gegevens behouden en worden de fouten duidelijk getoond.

### 5.3 Wissen, archiveren en relaties

- Wissen vraagt altijd eerst een expliciete bevestiging.
- Voor verwijderen wordt gecontroleerd of het record aan andere gegevens gekoppeld is.
- Gegevens die nodig zijn voor evenementen, gebruikers, berichten of historische informatie mogen niet ongemerkt worden verwijderd.
- Als definitief verwijderen niet veilig of functioneel toegestaan is, wordt archiveren of deactiveren gebruikt.

### 5.4 Rechten en uniforme werking

- Bekijken, toevoegen, aanpassen, wissen, archiveren en deactiveren zijn afhankelijk van gebruikersrechten.
- Rechten worden zowel in de gebruikersinterface als op de server afgedwongen. Alleen een knop verbergen is geen beveiliging.
- Alle lijsten gebruiken dezelfde herkenbare bediening, knopbenamingen, formulieropbouw, bevestigingen en meldingen.
- De velden verschillen per module, maar de werking blijft hetzelfde.
- Er wordt één gedeelde, herbruikbare technische basis voor lijsten en formulieren ontwikkeld. Nieuwe beheermodules gebruiken die basis.

## 6. Locaties zoeken via Google Maps

Bij het toevoegen of aanpassen van een locatie kan de beheerder de locatie opzoeken en selecteren via een geïntegreerde Google Maps- of Google Places-zoekfunctie. Na selectie vult DONDEBAILAR de beschikbare gegevens automatisch in.

### 6.1 Automatisch invullen

Voor zover Google Places deze informatie beschikbaar stelt, worden onder andere ingevuld:
- Naam van de locatie of zaak.
- Straatnaam en huisnummer.
- Postcode en gemeente/stad.
- Land.
- Geografische coördinaten (breedte- en lengtegraad).
- Google Maps-link of plaatsreferentie.
- Telefoonnummer en website.
- Andere toegestane praktische locatiegegevens.

De beheerder kan alle ingevulde gegevens controleren en handmatig corrigeren voordat de locatie wordt opgeslagen.

### 6.2 Logo's en afbeeldingen van locaties

Google Maps/Places levert niet voor iedere locatie een officieel logo als afzonderlijk gegeven. DONDEBAILAR mag daarom niet aannemen dat elk logo automatisch beschikbaar is.

- Als een betrouwbaar officieel logo via een toegestane bron beschikbaar is, kan dit als voorstel worden getoond.
- Als geen officieel logo beschikbaar is, kan de beheerder zelf een logo of afbeelding uploaden of de locatie zonder logo opslaan.
- Een categorie-icoon of gewone foto mag niet als officieel bedrijfslogo worden voorgesteld.
- Afbeeldingen en logo's worden alleen gebruikt met inachtneming van de toepasselijke gebruiksvoorwaarden en rechten.

### 6.3 Controle en technische voorwaarden

- De beheerder selecteert en bevestigt de juiste locatie; een zoekterm alleen maakt geen record aan.
- De gegevens kunnen vóór opslaan worden aangevuld of gecorrigeerd.
- De koppeling gebruikt de officiële Google Maps Platform/Places-functionaliteit.
- API-sleutels worden veilig per omgeving ingesteld, beperkt tot de nodige API's en toegestane toepassingen, en nooit in publieke broncode of GitHub opgeslagen.
- Er wordt rekening gehouden met gebruiksvoorwaarden, privacy, kosten en quota.
- Als Google Maps niet beschikbaar is of geen resultaat oplevert, blijft handmatige invoer mogelijk.
- De locatie wordt pas definitief opgeslagen nadat de beheerder op **Opslaan** klikt.

## 7. Reacties, chat en meldingen

### 7.1 Reacties bij evenementen

Bezoekers en ingelogde gebruikers moeten vragen kunnen stellen, praktische informatie delen en reageren op evenementen.

Beoogde mogelijkheden:
- Een reactie plaatsen bij een evenement en eventueel reageren op bestaande reacties.
- Auteur, datum en tijd tonen.
- Reacties aanpassen of verwijderen volgens de ingestelde rechten.
- Ongepaste reacties melden.
- Reacties modereren of verwijderen door bevoegde beheerders.
- Reacties per evenement in- of uitschakelen.

Organisatoren kunnen reageren op vragen en opmerkingen bij hun eigen evenementen. Zij mogen reacties van andere gebruikers niet zomaar aanpassen of verwijderen. Er komen passende maatregelen tegen misbruik en spam; de privacy van gebruikers wordt gerespecteerd.

### 7.2 Chat en berichten

De ingebouwde chat wordt voorbereid in verschillende vormen:
- **Privéchat:** rechtstreeks berichten sturen tussen gebruikers, volgens de ingestelde rechten.
- **Organisatorcommunicatie:** bezoekers kunnen contact opnemen met een organisator over diens profiel of evenementen.
- **Regionale communicatie:** bevoegde beheerders kunnen berichten sturen aan gebruikers of organisatoren binnen hun regio.
- **Beheerderskanaal:** een afzonderlijk afgeschermd kanaal voor platformbeheerders en bevoegde regionale beheerders.

De chat ondersteunt waar mogelijk een overzicht van gesprekken en nieuwe berichten, datum en tijd, meldingen, het melden van ongewenste berichten, blokkeren van gebruikers en moderatie door bevoegde beheerders.

Gebruikers zien alleen gesprekken en berichten waarvoor ze bevoegd zijn. Privégesprekken blijven afgeschermd. Beheerders krijgen niet automatisch toegang tot privégesprekken; eventuele inzage of moderatie volgt vooraf vastgelegde regels en respecteert de privacy.

### 7.3 Overige meldingen en communicatie

Het platform wordt voorbereid op:
- E-mailmeldingen en wachtwoordherstel.
- Aanpasbare e-mailinstellingen.
- Pushmeldingen voor relevante evenementen of wijzigingen.
- Meldingen bij nieuwe chatberichten en reacties.
- Berichtenkanalen voor gebruikers, organisatoren en beheerders.
- Rapportage en geplande onderhoudstaken.

Deze functies worden gefaseerd ontwikkeld en gelden niet als beschikbaar zolang ze niet gebouwd en getest zijn.

## 8. Huisstijl en logo's

DONDEBAILAR krijgt een eigen centraal merklogo. Het officiële DONDEBAILAR-logo moet zichtbaar zijn op de publieke website, zodat bezoekers op iedere pagina het platform herkennen.

### 8.1 Centraal DONDEBAILAR-logo

- Duidelijk zichtbaar in de algemene websiteheader op desktop en mobiel.
- Aanklikbaar en verwijzend naar de startpagina van de betreffende website of regio.
- Waar passend ook op de inlogpagina, in de beheeromgeving en als favicon/app-icoon.
- Scherp en leesbaar op verschillende schermformaten en achtergronden.
- Centraal beheerd als gedeeld merkmiddel, niet afzonderlijk vast ingebouwd op iedere pagina.

### 8.2 Regionale herkenbaarheid

Iedere regio kan een eigen regionaal logo en kleurenpalet krijgen. Het regionale logo is aanvullend op de DONDEBAILAR-identiteit en vervangt het centrale platformlogo niet. Beide herkenningselementen worden evenwichtig getoond.

De vormgeving moet duidelijk en gebruiksvriendelijk zijn, goed werken op computer, tablet en smartphone, en de agenda en evenementinformatie centraal stellen.

## 9. Technische uitgangspunten en beveiliging

- De applicatie wordt ontwikkeld in **PHP** en gebruikt **MySQL** voor gegevensopslag.
- De applicatie wordt modulair opgebouwd, zodat onderdelen afzonderlijk kunnen worden uitgebreid.
- De lokale ontwikkelomgeving moet zo goed mogelijk overeenkomen met de uiteindelijke hostingomgeving.
- Regio's, gebruikers, rollen en rechten worden centraal en consequent beheerd.
- Wachtwoorden worden veilig gehasht.
- Invoer wordt gevalideerd en databasebewerkingen worden veilig uitgevoerd.
- Chatberichten en evenementreacties worden veilig opgeslagen en alleen getoond aan bevoegde gebruikers.
- Bij communicatie wordt rekening gehouden met privacy, spambeperking, meldingen en moderatie.
- Databasewijzigingen worden via versieerbare migraties bijgehouden.
- Geheimen, lokale configuratie, databaseback-ups, logs, sessies en gebruikersuploads worden niet in de Git-repository opgenomen.
- API-sleutels en wachtwoorden worden per omgeving veilig geconfigureerd.
- Het centrale DONDEBAILAR-logo wordt als gedeeld merkmiddel beheerd; regionale logo's blijven afzonderlijke instellingen.

## 10. Raspberry Pi-testomgeving en productie

De Raspberry Pi dient als afzonderlijke ontwikkel- en testserver. Nieuwe functies, updates en databasewijzigingen worden daar eerst uitgeprobeerd voordat ze naar de productieomgeving gaan.

**Hoofduitgangspunt:** de testomgeving moet snel, gecontroleerd en herhaalbaar kunnen worden opgebouwd. Test- en productiegegevens en configuraties blijven strikt gescheiden.

### 10.1 Dezelfde applicatiebasis

- Test en productie gebruiken dezelfde applicatiecode uit GitHub.
- PHP-versie, benodigde extensies en databaseversie worden vastgelegd en waar mogelijk op elkaar afgestemd.
- Verschillen tussen Raspberry Pi en productiehosting worden gedocumenteerd.
- De applicatie bevat geen vaste paden, hostnamen of IP-adressen die alleen op de Raspberry Pi werken.

### 10.2 Configuratie per omgeving

Iedere omgeving krijgt een eigen configuratie voor databaseverbinding, domeinnaam, e-mailinstellingen, foutmeldingen, opslagpaden, API-sleutels en andere geheimen. Omgevingsinstellingen staan buiten de algemene applicatiecode. Er komt een voorbeeldconfiguratie zonder wachtwoorden of geheimen.

### 10.3 Installatie en opnieuw opbouwen

- Installatie- en configuratiestappen maken een lege omgeving zo veel mogelijk automatisch klaar.
- PHP-afhankelijkheden worden reproduceerbaar geïnstalleerd, bijvoorbeeld met Composer en een vastgelegde lockfile.
- Databasewijzigingen verlopen via versieerbare migraties.
- Mappen voor cache, logs, tijdelijke bestanden en uploads worden met de juiste rechten aangemaakt.
- De installatie wordt gedocumenteerd en waar mogelijk voorzien van scripts voor Raspberry Pi/Linux en productiehosting.
- Een nieuwe installatie mag niet afhankelijk zijn van verborgen of handmatige wijzigingen op een oude server.

### 10.4 Werkwijze voor een productie-update

1. Nieuwe of aangepaste code ontwikkelen en vastleggen in GitHub.
2. De afgesproken versie op de Raspberry Pi ophalen.
3. Installatie- en database-updates op de testomgeving uitvoeren.
4. De werking testen, waaronder rechten, regio's, evenementen, uploads en communicatie.
5. Een back-up maken van productiegegevens en relevante bestanden.
6. De geteste code naar productie overzetten.
7. De productieconfiguratie toepassen en alleen nog niet uitgevoerde migraties uitvoeren.
8. De productie-installatie controleren en bij problemen kunnen terugkeren naar de vorige werkende versie.

Productiegegevens worden nooit onbedoeld overschreven door testgegevens. Een gegevensmigratie gebeurt alleen bewust en gecontroleerd.

### 10.5 Back-up en herstel

- Voor belangrijke updates wordt een databaseback-up gemaakt.
- Gebruikersuploads, logo's en andere bestanden buiten GitHub krijgen een afzonderlijke back-upstrategie.
- De herstelprocedure wordt beschreven en periodiek getest.
- Terugdraaien houdt rekening met eventuele databasewijzigingen.

**Rolverdeling:** de Raspberry Pi is de test- en proefomgeving; de productiehosting is bestemd voor echte bezoekers en gegevens. De testomgeving bootst productie zo goed mogelijk na, maar gebruikt eigen configuratie en gegevens.

## 11. Ontwikkelfasen

De ontwikkeling verloopt gefaseerd. De volgorde kan tijdens het project worden verfijnd, maar nieuwe functies worden eerst gebouwd en getest voordat ze als beschikbaar worden beschouwd.

1. **Basisplatform:** projectstructuur, configuratie, database en veilige gebruikersauthenticatie.
2. **Beheerbasis:** dashboard, gebruikers, rollen en rechten, met de vaste standaard voor alle beheerlijsten.
3. **Regiobeheer:** regio's aanmaken en beheren, inclusief regionale kleuren en logo's.
4. **Kerngegevens:** locaties, organisatoren, categorieën en evenementen beheren; Google Maps-koppeling voor locaties.
5. **Publieke agenda en sociale feed:** de Instagram-geïnspireerde evenementenfeed bouwen, met een responsieve weergave: één kolom op smartphone, een aangepaste rasterindeling op tablet en drie of vier kolommen op desktop.
6. **Meertaligheid:** taalkeuze en vertalingen uitwerken.
7. **Interactie:** evenementreacties, antwoorden van organisatoren en moderatie.
8. **Chat:** privéchat en afgeschermde communicatiekanalen met rechten en meldingen.
9. **Test- en productiebeheer:** Raspberry Pi-testomgeving, reproduceerbare installatie, migraties, back-ups en gecontroleerde publicatie.
10. **Verdere uitbreidingen:** pushmeldingen, deelnamefuncties en rapportage.
11. **Productie-ingebruikname:** testen, beveiligingscontrole, back-upstrategie en gecontroleerde ingebruikname op de hostingomgeving.

## 12. Documentstatus

Dit document beschrijft de functionele doelstelling, de beoogde werking en de technische uitgangspunten van DONDEBAILAR. Het is het centrale projectreferentiedocument voor verdere ontwikkeling.

**Belangrijk onderscheid:** een beschreven functie is een gewenste of geplande functionaliteit. Dit document is geen bewijs dat die functie al gebouwd, beschikbaar of getest is. De voortgang wordt tijdens de ontwikkeling afzonderlijk bijgehouden.

Nieuwe eisen en beslissingen worden in dit document verwerkt, zodat het project één actueel en overzichtelijk uitgangspunt behoudt.
