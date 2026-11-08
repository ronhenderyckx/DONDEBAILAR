# DONDEBAILAR
## Projectdoelstelling en functionele specificaties

*Centraal referentiedocument voor ontwerp, ontwikkeling, testen en beheer.*

> **Leeswijzer**
> - Dit document beschrijft wat DONDEBAILAR moet kunnen en hoe het platform moet werken.
> - Een beschreven functie is niet automatisch al gebouwd of getest.
> - Nieuwe beslissingen worden op de passende plaats toegevoegd; dubbele of tegenstrijdige beschrijvingen worden vermeden.

## Inhoud

1. [Projectvisie en uitgangspunten](#1-projectvisie-en-uitgangspunten)
2. [Regio's en talen](#2-regios-en-talen)
3. [Gebruikers, rollen en rechten](#3-gebruikers-rollen-en-rechten)
4. [Publieke website en evenementenfeed](#4-publieke-website-en-evenementenfeed)
5. [Beheeromgeving en vaste lijststandaard](#5-beheeromgeving-en-vaste-lijststandaard)
6. [Gegevens en modules](#6-gegevens-en-modules)
7. [Locaties en Google Maps](#7-locaties-en-google-maps)
8. [Reacties, chat en meldingen](#8-reacties-chat-en-meldingen)
9. [Huisstijl en logo's](#9-huisstijl-en-logos)
10. [Techniek, privacy en beveiliging](#10-techniek-privacy-en-beveiliging)
11. [Testomgeving, productie en back-ups](#11-testomgeving-productie-en-back-ups)
12. [Ontwikkelvolgorde](#12-ontwikkelvolgorde)
13. [Status en besluitvorming](#13-status-en-besluitvorming)

---

## 1. Projectvisie en uitgangspunten

DONDEBAILAR wordt een meertalig, regionaal platform waarop dansliefhebbers dansevenementen en activiteiten kunnen vinden. Organisatoren kunnen hun activiteiten publiceren en beheren.

De eerste regio is **Marina Alta in Spanje**. De opbouw moet uitbreiding naar andere regio's mogelijk maken zonder de applicatie opnieuw te moeten ontwikkelen.

### 1.1 Soorten activiteiten

- Salsa, bachata en kizomba.
- Danslessen en workshops.
- Dansavonden en socials.
- Festivals en congressen.
- Andere activiteiten die passen binnen een regionale dansagenda.

Bevoegde beheerders kunnen categorieën later uitbreiden en aanpassen.

### 1.2 Centrale uitgangspunten

- Eén centraal platform met meerdere regionale agenda's.
- Een herkenbare DONDEBAILAR-huisstijl, aangevuld met regionale kleuren en logo's.
- Een sociale evenementenfeed als standaardweergave van de publieke kalender.
- Een uniforme beheeromgeving voor alle gegevenslijsten.
- Meertaligheid vanaf de technische basis.
- Rechten en gegevensscheiding op platform- en regioniveau.
- Eerst ontwikkelen en testen, daarna gecontroleerd publiceren.

## 2. Regio's en talen

### 2.1 Regionale structuur

Iedere regio kan beschikken over:
- Een eigen naam en URL of subdomein.
- Een eigen landinstelling en tijdzone.
- Een eigen logo en kleurenpalet.
- Eigen evenementen, locaties en organisatoren.
- Eigen regionale beheerders.

Gegevens van verschillende regio's blijven logisch van elkaar gescheiden. Een platformbeheerder kan alle regio's beheren.

### 2.2 Talen

De technische structuur wordt voorbereid op:
- Nederlands (NL).
- Engels (EN).
- Spaans (ES).
- Frans (FR).

De taalstructuur wordt vanaf het begin voorzien. De volledige vertalingen worden later ingevuld. Evenementen moeten waar nodig vertaalde titels en beschrijvingen kunnen ondersteunen.

## 3. Gebruikers, rollen en rechten

### 3.1 Gebruikerstypen

| Rol | Bevoegdheid |
|---|---|
| **admin** | Volledig platformbeheer |
| **region_admin** | Beheer van toegewezen regio's |
| **editor** | Redactioneel beheer volgens toegekende rechten |
| **organizer** | Beheer van eigen organisatorprofiel en evenementen |
| **viewer/bezoeker** | Publieke informatie bekijken; extra functies volgens account en rechten |

### 3.2 Rechtenbeheer

- Rechten worden centraal gecontroleerd.
- Een gebruiker kan alleen gegevens bekijken of wijzigen waarvoor die gebruiker bevoegd is.
- Regionale beheerders zijn beperkt tot hun toegewezen regio's.
- Organisatoren beheren alleen hun eigen gegevens en evenementen, voor zover toegestaan.
- Rechten worden zowel in de gebruikersinterface als op de server afgedwongen. Een knop verbergen is op zichzelf geen beveiliging.

De precieze rechten per handeling worden tijdens de ontwikkeling verder uitgewerkt.


### 3.3 Nieuwe gebruikers registreren en e-mailadres bevestigen

Nieuwe gebruikers kunnen zich zelf registreren. Een account wordt pas actief nadat het opgegeven e-mailadres is bevestigd.

De registratie verloopt als volgt:

1. De gebruiker vult de gevraagde gegevens in, waaronder naam, e-mailadres en wachtwoord.
2. DONDEBAILAR controleert de invoer en controleert of het e-mailadres al in gebruik is.
3. Het systeem maakt het account aan met de status **in afwachting van e-mailbevestiging**.
4. DONDEBAILAR stuurt een e-mail met een persoonlijke bevestigingslink.
5. De gebruiker klikt op de link. Na een geldige bevestiging wordt het account geactiveerd.
6. Pas daarna kan de gebruiker aanmelden en functies gebruiken die een actief account vereisen.

Beveiliging en gebruiksgemak:

- De bevestigingslink bevat een unieke, moeilijk te raden token en kan slechts één keer worden gebruikt.
- Tokens worden veilig opgeslagen (bij voorkeur als hash) en hebben een beperkte geldigheidsduur.
- Een verlopen link kan opnieuw worden aangevraagd; daarbij wordt de oude link ongeldig gemaakt.
- De gebruiker krijgt duidelijke meldingen over de status en kan de bevestigingsmail opnieuw laten versturen.
- Het systeem geeft bij registratie geen onnodige informatie prijs over bestaande accounts.
- Er gelden maatregelen tegen misbruik, geautomatiseerde registraties en het veelvuldig aanvragen van bevestigingsmails.
- E-mailadressen moeten uniek zijn.
- Beheerders kunnen de accountstatus en bevestiging beheren volgens hun rechten.
- De verzendinstellingen (SMTP) worden per omgeving veilig ingesteld en zijn niet opgenomen in GitHub.


## 4. Publieke website en evenementenfeed

### 4.1 Standaardweergave

De publieke kalender opent standaard als een **sociale evenementenfeed**, geïnspireerd op de gebruikservaring van Instagram. Evenementen verschijnen als visuele kaarten in één doorlopende stroom.

De feed past zich aan het scherm aan:
- **Smartphone:** één evenementkaart per rij.
- **Tablet:** meerdere kolommen, afhankelijk van de beschikbare breedte.
- **Desktop:** standaard drie of vier kaarten naast elkaar, met volgende kaarten op de volgende rij.

De feed blijft één doorlopende stroom; alleen de rasterindeling verandert.

### 4.2 Inhoud van een evenementkaart

Een kaart kan tonen:
- Affiche, foto of ander evenementbeeld.
- Titel, datum en uur.
- Locatie en organisator.
- Korte beschrijving.
- Like-aantal en beschikbare interacties.

Een bezoeker kan een evenement openen om de volledige informatie te bekijken. Afhankelijk van de rechten kan de bezoeker liken, reageren, delen of een evenement bewaren.

### 4.3 Media op evenementen

- Eén evenement kan meerdere foto's, afbeeldingen en video's bevatten.
- In de kaart wordt één media-item tegelijk getoond.
- Bij meerdere items kan horizontaal tussen de media worden geswipet.
- De bediening en positie-indicator passen zich aan het aantal media-items aan.
- Bij één item worden geen overbodige swipebediening of indicatoren getoond.
- De organisator bepaalt de volgorde.
- Foto's en video's mogen gecombineerd worden.
- Video's zijn herkenbaar en starten niet onverwacht met geluid.
- Horizontale mediabediening mag het verticale scrollen niet hinderen.

### 4.4 Interactie op evenementkaarten

**Dansschoenen als like-knop**
- Gebruik een herkenbaar symbool van dansschoenen in plaats van een hartje.
- Na aanklikken toont een actieve kleur of vorm dat de gebruiker het evenement leuk vindt.
- Het aantal likes blijft zichtbaar.
- Gebruik hetzelfde symbool op de kaart en op de detailpagina, op alle schermformaten.

**Extra informatie door naar rechts te swipen**
- De bezoeker kan op een evenementkaart naar rechts swipen om extra informatie te tonen.
- Dit werkt wanneer de beweging begint op de afbeelding/affiche of op de tekst.
- De extra informatie verschijnt binnen de feed; de bezoeker verlaat het overzicht niet.
- De uitgebreide weergave kan eenvoudig gesloten worden.
- Op desktop is dezelfde informatie beschikbaar via een duidelijke klikactie of knop.
- De bediening mag verticaal scrollen niet hinderen en moet ook met toetsenbord bruikbaar zijn.

**Locatie openen**
- Het locatie-icoon op de kaart en detailpagina opent de juiste locatie in Google Maps.
- Gebruik bij voorkeur de opgeslagen Google Maps-link; anders de opgeslagen coördinaten.
- Dit werkt op smartphone, tablet en desktop.
- Zonder geldige locatiegegevens is het icoon niet actief.

### 4.5 Vaste bovenbalk: filters

De publieke feed krijgt een bovenbalk die **altijd zichtbaar blijft tijdens het scrollen**. De feed beweegt onder deze balk door.

De bovenbalk biedt:
- Toegang tot de filtermogelijkheden.
- Filter op **organisator**: toon alleen evenementen van de gekozen organisator.
- Filter op **stad of gemeente**.
- Een duidelijke weergave van de actieve filters.
- Mogelijkheid om een filter te wijzigen of afzonderlijk te verwijderen.
- Een actie om alle filters te wissen en de volledige feed terug te tonen.

**Na iedere filterwijziging** — instellen, aanpassen of verwijderen — scrolt de feed automatisch terug naar de bovenkant. De eerste resultaten verschijnen direct onder de vaste bovenbalk.

De bovenbalk werkt op smartphone, tablet en desktop.

### 4.6 Vaste onderbalk: zoeken en geluid

Naast de bovenbalk krijgt de publieke feed een **onderbalk die altijd zichtbaar blijft tijdens het scrollen**. De feed beweegt tussen de boven- en onderbalk.

De onderbalk bevat:

1. **Manueel zoekveld**
   - De bezoeker voert zelf een zoekterm in.
   - De zoekopdracht wordt uitgevoerd met Enter of een duidelijke zoekactie.
   - Zoeken werkt samen met de actieve filters.

2. **Geluid aan/uit**
   - Een duidelijke knop schakelt het geluid in of uit.
   - Het icoon toont de actuele status, bijvoorbeeld een luidspreker met of zonder geluidsgolven.
   - De geluidskeuze wordt consequent toegepast op media in de feed.

De onderbalk werkt op smartphone, tablet en desktop. Hij houdt rekening met de beschikbare schermhoogte en veilige schermranden en bedekt geen belangrijke inhoud of bediening.

### 4.7 Algemene gebruikservaring

- De feed moet snel, overzichtelijk en gebruiksvriendelijk zijn.
- Boven- en onderbalk blijven beschikbaar terwijl de inhoud ertussen scrolt.
- Horizontale gebaren mogen verticaal scrollen niet blokkeren.
- Bediening moet bruikbaar zijn met aanraking, muis en toetsenbord.
- De precieze werking van regiokeuze en het tonen van evenementen uit andere regio's wordt nog vastgelegd.

## 5. Beheeromgeving en vaste lijststandaard

**Alle beheerlijsten gebruiken dezelfde opbouw en bediening.** Dit geldt onder andere voor gebruikers, evenementen, locaties, organisatoren, regio's, categorieën en berichten.

### 5.1 Overzichtsscherm

Iedere lijst bevat waar relevant:
1. Een zoekveld.
2. Een knop **Toevoegen**.
3. Een overzicht met de belangrijkste gegevens.
4. Een actie **Aanpassen** per record.
5. Een actie **Wissen**, of **Archiveren/Deactiveren** als verwijderen niet veilig of toegestaan is.
6. Paginering bij lange lijsten.
7. Duidelijke meldingen bij lege resultaten en geslaagde of mislukte acties.

### 5.2 Toevoegen en aanpassen

Toevoegen en aanpassen gebeuren op een **afzonderlijk invoerscherm**, niet rechtstreeks in de lijst.

- **Toevoegen:** een leeg formulier met passende standaardwaarden.
- **Aanpassen:** hetzelfde type formulier, vooraf ingevuld met de bestaande gegevens.
- Verplichte velden en formaten zijn duidelijk aangegeven en worden gecontroleerd.
- Elk formulier heeft **Opslaan** en **Annuleren**.
- Na opslaan keert de gebruiker terug naar de lijst.
- Waar mogelijk blijven de zoekopdracht en huidige pagina behouden.
- Bij validatiefouten blijven de ingevulde waarden behouden en worden de fouten duidelijk getoond.

### 5.3 Wissen en archiveren

- Wissen vraagt altijd een bevestiging.
- Voor verwijderen worden gekoppelde gegevens gecontroleerd.
- Gegevens die nodig zijn voor evenementen, gebruikers, berichten of historiek worden niet ongemerkt verwijderd.
- Als definitief verwijderen niet veilig of toegestaan is, wordt archiveren of deactiveren gebruikt.

### 5.4 Uniforme techniek en rechten

- Alle lijsten gebruiken dezelfde herkenbare knoppen, formulieren, meldingen en bevestigingen.
- Velden verschillen per module; de bediening blijft gelijk.
- Er komt één gedeelde, herbruikbare technische basis voor lijsten en formulieren.
- Nieuwe beheermodules gebruiken diezelfde basis.
- Alle handelingen worden zowel in de interface als op de server op rechten gecontroleerd.

## 6. Gegevens en modules

### 6.1 Evenementen

Een evenement kan de volgende gegevens bevatten:
- Titel en beschrijving.
- Datum, beginuur en einduur.
- Dansstijl of categorie.
- Organisator.
- Locatie en adres.
- Toegangsprijs en praktische informatie.
- Eén of meerdere afbeeldingen en video's.
- Publicatiestatus.
- Regio.

Evenementen worden gekoppeld aan de juiste regio, organisator en locatie.

### 6.2 Locaties

Locaties worden centraal beheerd en kunnen aan meerdere evenementen gekoppeld worden. Beschikbare gegevens kunnen zijn:
- Naam.
- Straat, huisnummer, postcode en stad/gemeente.
- Land.
- Geografische coördinaten.
- Google Maps-link of plaatsreferentie.
- Telefoonnummer en website.
- Logo of afbeelding, indien beschikbaar.

De automatische invoer via Google Maps staat beschreven in hoofdstuk 7.

### 6.3 Organisatoren

- Organisatoren krijgen een eigen profiel.
- Ze kunnen hun eigen profiel en evenementen beheren volgens hun rechten.
- Ze kunnen reageren op vragen en opmerkingen bij hun eigen evenementen.
- Bezoekers kunnen organisatorprofielen bekijken en evenementen van een organisator filteren.

### 6.4 Gebruikers

Gebruikers kunnen een account en profiel hebben. Het profiel kan onder meer een avatar bevatten. Beheer en toegang tot functies zijn afhankelijk van de rol en rechten.

## 7. Locaties en Google Maps

Bij het toevoegen of aanpassen van een locatie kan de beheerder zoeken via een geïntegreerde Google Maps- of Google Places-functie. Na selectie worden de beschikbare gegevens automatisch ingevuld.

### 7.1 Automatisch ingevulde gegevens

Voor zover beschikbaar en toegestaan:
- Naam van de locatie of zaak.
- Straatnaam en huisnummer.
- Postcode en stad/gemeente.
- Land.
- Geografische coördinaten.
- Google Maps-link of plaatsreferentie.
- Telefoonnummer en website.
- Andere toegestane praktische locatiegegevens.

De beheerder kan alle gegevens controleren en corrigeren vóór het opslaan.

### 7.2 Logo en afbeelding

Google Places levert niet voor iedere locatie een officieel logo als afzonderlijk gegeven.

- Als een betrouwbaar officieel logo via een toegestane bron beschikbaar is, kan het als voorstel worden getoond.
- Anders kan de beheerder zelf een logo of afbeelding uploaden.
- Een categorie-icoon of gewone foto wordt nooit voorgesteld als officieel bedrijfslogo.
- Een locatie kan ook zonder logo worden opgeslagen.
- Gebruik van afbeeldingen respecteert de toepasselijke rechten en voorwaarden.

### 7.3 Bevestiging en technische voorwaarden

- De beheerder selecteert en bevestigt de juiste locatie.
- Een zoekterm alleen maakt geen locatie aan.
- De gegevens blijven aanpasbaar vóór het opslaan.
- De koppeling gebruikt de officiële Google Maps Platform/Places-functionaliteit.
- API-sleutels worden per omgeving veilig ingesteld en beperkt tot noodzakelijke toepassingen.
- API-sleutels komen nooit in publieke broncode of GitHub.
- Er wordt rekening gehouden met voorwaarden, privacy, kosten en quota.
- Handmatige invoer blijft mogelijk als Google Maps niet beschikbaar is of geen resultaat geeft.
- De locatie wordt pas opgeslagen na een expliciete klik op **Opslaan**.

## 8. Reacties, chat en meldingen

### 8.1 Reacties bij evenementen

Beoogde mogelijkheden:
- Reacties plaatsen bij evenementen.
- Eventueel reageren op bestaande reacties.
- Auteur, datum en tijd tonen.
- Reacties aanpassen of verwijderen volgens rechten.
- Ongepaste reacties melden.
- Moderatie door bevoegde beheerders.
- Reacties per evenement in- of uitschakelen.

Organisatoren kunnen reageren op vragen bij hun eigen evenementen. Zij mogen reacties van anderen niet zomaar aanpassen of verwijderen. Er komen maatregelen tegen misbruik en spam, met respect voor de privacy van gebruikers.

### 8.2 Chat en berichten

De ingebouwde chat wordt voorbereid voor:
- **Privéchat:** berichten tussen gebruikers volgens de ingestelde rechten.
- **Organisatorcommunicatie:** bezoekers nemen contact op met een organisator over diens profiel of evenementen.
- **Regionale communicatie:** bevoegde beheerders communiceren met gebruikers of organisatoren binnen hun regio.
- **Beheerderskanaal:** afgeschermde communicatie voor platformbeheerders en bevoegde regionale beheerders.

De chat krijgt waar mogelijk:
- Een overzicht van gesprekken en nieuwe berichten.
- Datum en tijd bij berichten.
- Meldingen bij nieuwe berichten.
- Mogelijkheid om ongewenste berichten te melden.
- Mogelijkheid om gebruikers te blokkeren.
- Moderatie door bevoegde beheerders.

Gebruikers zien alleen gesprekken waarvoor ze bevoegd zijn. Privégesprekken blijven afgeschermd. Eventuele inzage door beheerders volgt vooraf vastgelegde regels en respecteert de privacy.

### 8.3 E-mail en accountmeldingen

E-mail wordt gebruikt voor onder meer:
- Bevestiging van nieuwe gebruikersregistraties.
- Opnieuw versturen van een bevestigingslink.
- Wachtwoordherstel via een beveiligde link.
- Relevante account- en evenementmeldingen.

SMTP-instellingen zijn per omgeving configureerbaar. Wachtwoorden en SMTP-geheimen worden buiten GitHub opgeslagen. E-mails bevatten geen wachtwoorden of andere gevoelige gegevens.

### 8.3 Overige meldingen

Het platform wordt voorbereid op:
- E-mailmeldingen.
- Wachtwoordherstel via e-mail.
- Aanpasbare e-mailinstellingen.
- Pushmeldingen voor relevante evenementen of wijzigingen.
- Meldingen bij nieuwe chatberichten en reacties.
- Rapportage en geplande onderhoudstaken.

Deze functies zijn pas beschikbaar nadat ze gebouwd en getest zijn.

## 9. Huisstijl en logo's

### 9.1 Centraal DONDEBAILAR-merk

Het centrale DONDEBAILAR-logo blijft herkenbaar op:
- De publieke websiteheader, op desktop en mobiel.
- De inlogpagina.
- De beheeromgeving waar passend.
- Favicon en app-icoon.

Het logo is waar passend aanklikbaar en verwijst naar de startpagina van de betreffende website of regio. Het wordt centraal als gedeeld merkmiddel beheerd.

### 9.2 Regionale huisstijl

- Iedere regio kan een eigen logo en kleurenpalet instellen.
- Het regionale logo vult de centrale DONDEBAILAR-identiteit aan en vervangt die niet.
- Beide logo's worden evenwichtig weergegeven.
- Vormgeving blijft duidelijk en bruikbaar op computer, tablet en smartphone.
- De agenda en evenementinformatie blijven centraal staan.

## 10. Techniek, privacy en beveiliging

- De applicatie wordt ontwikkeld in **PHP** met **MySQL** als database.
- De applicatie wordt modulair opgebouwd.
- De lokale ontwikkelomgeving sluit zo goed mogelijk aan op de hostingomgeving.
- Regio's, gebruikers, rollen en rechten worden centraal beheerd.
- Wachtwoorden worden veilig gehasht.
- Invoer wordt gevalideerd en databasebewerkingen worden veilig uitgevoerd.
- Chatberichten en reacties zijn alleen toegankelijk voor bevoegde gebruikers.
- Er wordt rekening gehouden met privacy, spambeperking, meldingen en moderatie.
- Databasewijzigingen verlopen via versieerbare migraties.
- Geheimen, lokale configuratie, databaseback-ups, logs, sessies en gebruikersuploads worden niet in GitHub opgenomen.
- API-sleutels en wachtwoorden worden per omgeving veilig geconfigureerd.
- Het centrale logo wordt gedeeld beheerd; regionale logo's zijn afzonderlijke instellingen.

## 11. Testomgeving, productie en back-ups

De Raspberry Pi is de afzonderlijke ontwikkel- en testserver. Productie is bestemd voor echte bezoekers en gegevens.

**Test- en productiegegevens en configuraties blijven strikt gescheiden.**

### 11.1 Gelijke applicatiebasis

- Test en productie gebruiken dezelfde applicatiecode uit GitHub.
- PHP-versie, extensies en databaseversie worden vastgelegd en waar mogelijk afgestemd.
- Verschillen tussen Raspberry Pi en productiehosting worden gedocumenteerd.
- De applicatie bevat geen vaste paden, hostnamen of IP-adressen die alleen op de testserver werken.

### 11.2 Configuratie per omgeving

Iedere omgeving krijgt een eigen configuratie voor:
- Databaseverbinding.
- Domeinnaam en URL.
- E-mailinstellingen.
- Foutmeldingen.
- Opslagpaden.
- API-sleutels en andere geheimen.

Omgevingsinstellingen staan buiten de algemene applicatiecode. Er komt een voorbeeldconfiguratie zonder wachtwoorden of geheimen.

### 11.3 Herhaalbare installatie

- Installatie- en configuratiestappen maken een lege omgeving zo veel mogelijk automatisch klaar.
- PHP-afhankelijkheden worden reproduceerbaar geïnstalleerd met Composer en een vastgelegde lockfile.
- Databasewijzigingen verlopen via migraties.
- Mappen voor cache, logs, tijdelijke bestanden en uploads krijgen de juiste rechten.
- Installatie wordt gedocumenteerd en waar mogelijk voorzien van scripts voor Raspberry Pi/Linux en productiehosting.
- Een nieuwe installatie mag niet afhangen van verborgen handmatige wijzigingen op een oude server.

### 11.4 Publicatie van een update

1. Code ontwikkelen en vastleggen in GitHub.
2. De afgesproken versie op de Raspberry Pi ophalen.
3. Installatie- en database-updates uitvoeren.
4. Rechten, regio's, evenementen, uploads en communicatie testen.
5. Een back-up maken van productiegegevens en relevante bestanden.
6. De geteste code naar productie overzetten.
7. De productieconfiguratie toepassen en alleen nog niet uitgevoerde migraties uitvoeren.
8. De productie-installatie controleren en bij problemen kunnen terugkeren naar de vorige werkende versie.

Testgegevens mogen nooit onbedoeld productiegegevens overschrijven.

### 11.5 Back-up en herstel

- Voor belangrijke updates wordt een databaseback-up gemaakt.
- Gebruikersuploads, logo's en andere bestanden buiten GitHub krijgen een afzonderlijke back-up.
- De herstelprocedure wordt beschreven en periodiek getest.
- Terugdraaien houdt rekening met eventuele databasewijzigingen.

## 12. Ontwikkelvolgorde

De ontwikkeling verloopt in stappen. De volgorde kan worden verfijnd. Een functie geldt pas als beschikbaar wanneer ze gebouwd en getest is.

1. **Basisplatform:** projectstructuur, configuratie, database, veilige authenticatie en registratie met e-mailbevestiging.
2. **Beheerbasis:** dashboard, gebruikers, rollen, rechten en uniforme lijsten/formulieren.
3. **Regiobeheer:** regio's, regionale kleuren en logo's.
4. **Kerngegevens:** locaties, organisatoren, categorieën en evenementen; Google Maps voor locaties.
5. **Publieke feed:** responsieve sociale evenementenfeed, media, likes, swipe-interactie, vaste boven- en onderbalk, filters en zoeken.
6. **Meertaligheid:** taalkeuze en vertalingen.
7. **Reacties:** evenementreacties, antwoorden van organisatoren en moderatie.
8. **Chat:** privéchat en afgeschermde communicatiekanalen.
9. **Test- en productiebeheer:** Raspberry Pi, reproduceerbare installatie, migraties, back-ups en gecontroleerde publicatie.
10. **Uitbreidingen:** pushmeldingen, deelnamefuncties en rapportage.
11. **Productie-ingebruikname:** volledige test, beveiligingscontrole, back-upstrategie en gecontroleerde lancering.

## 13. Status en besluitvorming

Dit document is het centrale functionele en technische referentiepunt voor DONDEBAILAR.

- Nieuwe wensen worden bij het juiste hoofdstuk toegevoegd.
- Dubbele beschrijvingen worden vermeden.
- Beslissingen die nog niet genomen zijn, worden als open punt aangeduid.
- Een beschreven wens betekent niet dat de functie al gebouwd of getest is.
- De werkelijke ontwikkelstatus wordt afzonderlijk bijgehouden.
