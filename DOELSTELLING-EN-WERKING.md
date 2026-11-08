# DONDEBAILAR – Doelstelling en werking

## 1. Doelstelling

DONDEBAILAR wordt een meertalige, regionale evenementenkalender die dansliefhebbers helpt om dansactiviteiten en evenementen in hun omgeving te vinden.

Het platform brengt dansactiviteiten op één centrale, overzichtelijke plaats samen. Organisatoren kunnen hun evenementen bekendmaken en beheren. Bezoekers kunnen de agenda raadplegen en, waar de functionaliteit beschikbaar is, aangeven dat ze deelnemen of interesse hebben.

De eerste regionale uitwerking is **Marina Alta (Spanje)**. De opzet moet later uitbreiding naar andere regio's mogelijk maken.

## 2. Voor wie is DONDEBAILAR bedoeld?

- **Bezoekers en dansliefhebbers:** zoeken activiteiten, bekijken evenementgegevens en ontdekken organisatoren en locaties.
- **Organisatoren:** plaatsen en beheren hun eigen evenementen.
- **Regionale beheerders:** beheren de agenda en instellingen van hun toegewezen regio.
- **Platformbeheerders:** beheren het volledige platform, de regio's en de algemene instellingen.

## 3. Welke activiteiten?

De agenda richt zich in eerste instantie op sociale dans en aanverwante activiteiten, waaronder:

- Salsa
- Bachata
- Kizomba
- Danslessen en workshops
- Dansavonden en socials
- Festivals en congressen
- Andere activiteiten die passen binnen de regionale dansagenda

De categorieën moeten later door bevoegde beheerders kunnen worden uitgebreid of aangepast.

## 4. Regionale opbouw

DONDEBAILAR wordt opgebouwd als één centraal platform met meerdere regio's.

Elke regio krijgt eigen instellingen, waaronder:
- Een naam en herkenbare URL of subdomein.
- Een eigen tijdzone en landinstelling.
- Een eigen logo en kleurenpalet.
- Eigen evenementen, locaties en regionale organisatoren.
- Beheerders die alleen toegang krijgen tot de regio's waarvoor ze bevoegd zijn.

Gegevens van verschillende regio's moeten logisch van elkaar gescheiden blijven. Een platformbeheerder kan het geheel beheren.

## 5. Evenementen en locaties

Een evenement kan onder andere de volgende gegevens bevatten:
- Titel en beschrijving.
- Datum en begin- en einduur.
- Dansstijl of categorie.
- Organisator.
- Locatie en adres.
- Toegangsprijs of andere praktische informatie.
- Afbeelding of affiche.
- Publicatiestatus.

Evenementen kunnen aan een regio, organisator en locatie worden gekoppeld. De agenda moet bezoekers helpen activiteiten eenvoudig terug te vinden.

## 6. Organisatoren

Organisatoren moeten een eigen profiel kunnen krijgen en, na toekenning van de juiste rechten, hun evenementen kunnen beheren.

De precieze rechten en goedkeuringsstappen worden verder uitgewerkt tijdens de ontwikkeling. Een organisator mag nooit meer gegevens of functies kunnen aanpassen dan zijn of haar rechten toelaten.

## 7. Gebruikers en toegangsrechten

Het platform werkt met gebruikersaccounts en rolgebonden toegangsrechten.

De voorziene basisrollen zijn:
- **admin:** volledig platformbeheer.
- **region_admin:** beheer van toegewezen regio's.
- **editor:** redactioneel beheer volgens toegekende rechten.
- **organizer:** beheer van eigen organisator- en evenementgegevens volgens toegekende rechten.
- **viewer/bezoeker:** raadplegen van publiek beschikbare informatie.

De uiteindelijke rechten worden centraal gecontroleerd. Beveiligde beheerpagina's en acties zijn alleen toegankelijk voor bevoegde gebruikers.

## 8. Meertaligheid

De technische structuur wordt vanaf het begin voorbereid op vier talen:
- Nederlands (NL)
- Engels (EN)
- Spaans (ES)
- Frans (FR)

De vertaalstructuur wordt nu voorzien. De volledige vertalingen worden in een latere ontwikkelfase toegevoegd.

Evenementen kunnen later vertaalde titels en beschrijvingen ondersteunen, zodat informatie begrijpelijk is voor bezoekers in verschillende talen.

## 9. Vormgeving en herkenbaarheid

Elke regio kan een eigen visuele identiteit krijgen via een logo en kleurenpalet. De algemene structuur en bediening blijven herkenbaar en gelijk voor alle regio's.

De vormgeving moet:
- Duidelijk en gebruiksvriendelijk zijn.
- Goed werken op computer, tablet en smartphone.
- De agenda en evenementinformatie centraal stellen.
- Rekening houden met de regionale huisstijl.

## 10. Beheer en communicatie

Het platform wordt voorbereid op uitbreidingen zoals:
- E-mailmeldingen en wachtwoordherstel.
- Pushmeldingen voor relevante evenementen of wijzigingen.
- Berichten of communicatiekanalen voor gebruikers en organisatoren.
- Beheer van e-mailinstellingen.
- Rapportage en geplande onderhoudstaken.

Deze onderdelen worden gefaseerd ontwikkeld. Ze gelden niet als beschikbaar zolang ze niet daadwerkelijk zijn gebouwd en getest.

## 11. Technische uitgangspunten

- De applicatie wordt ontwikkeld in PHP en gebruikt MySQL voor gegevensopslag.
- De lokale ontwikkelomgeving moet zo goed mogelijk overeenkomen met de uiteindelijke hostingomgeving.
- De applicatie wordt modulair opgebouwd, zodat onderdelen afzonderlijk kunnen worden uitgebreid.
- Regio's, gebruikers, rollen en rechten worden centraal en consequent beheerd.
- Wachtwoorden worden veilig gehasht.
- Invoer wordt gevalideerd en databasebewerkingen worden veilig uitgevoerd.
- Geheimen, lokale configuratie, databaseback-ups, logs, sessies en gebruikersuploads worden niet in de Git-repository opgenomen.
- Wijzigingen aan de database worden waar mogelijk als versieerbare migraties bijgehouden.

## 12. Gefaseerde ontwikkeling

De ontwikkeling verloopt in stappen:

1. **Basisplatform:** projectstructuur, configuratie, database en veilige gebruikersauthenticatie.
2. **Beheer:** dashboard, gebruikers, rollen en rechten.
3. **Regiobeheer:** regio's aanmaken en beheren, met eigen kleuren en logo.
4. **Agenda:** evenementen, categorieën, locaties en organisatoren beheren.
5. **Publieke website:** agenda en evenementinformatie toegankelijk maken voor bezoekers.
6. **Meertaligheid:** vertalingen en taalkeuze uitwerken.
7. **Uitbreidingen:** meldingen, communicatie, deelnamefuncties en verdere verbeteringen.
8. **Productie:** testen, beveiligingscontrole, back-upstrategie en ingebruikname op de hostingomgeving.

## 13. Uitgangspunt voor toekomstige uitbreidingen

DONDEBAILAR moet kunnen groeien van een regionale dansagenda naar een platform voor meerdere regio's, zonder dat de basis opnieuw moet worden gebouwd.

Nieuwe functies moeten passen binnen de bestaande regionale structuur, gebruikersrechten en meertalige opzet. Gebruiksgemak, betrouwbaarheid, veiligheid en eenvoudig beheer blijven daarbij de belangrijkste uitgangspunten.

---

**Documentstatus:** functionele doelstelling en globale beschrijving van de beoogde werking. Dit document beschrijft de richting van het project; het is geen bewijs dat alle genoemde functies al zijn gerealiseerd.
