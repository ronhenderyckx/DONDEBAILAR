# Opdracht – Maak de horizontale swipe-richting in de agenda gelijk

## Repository
`ronhenderyckx/DONDEBAILAR`

## Verplichte branch
`copilot/php-review-fixes`

## Doel
Zorg dat horizontaal swipen in de agenda voor zowel de media links als de informatie rechts dezelfde richting en betekenis heeft.

## Gewenste bediening
Gebruik voor beide onderdelen exact dezelfde horizontale swipe-logica:
- Swipe naar links: ga naar het volgende media-item of de volgende informatiepagina.
- Swipe naar rechts: ga naar het vorige media-item of de vorige informatiepagina.

De media links en de informatie rechts moeten dus dezelfde richting gebruiken. Een gebruiker mag niet bij het ene onderdeel naar links en bij het andere onderdeel naar rechts moeten swipen om vooruit te gaan.

## Verplichte voorbereiding
1. Lees dit volledige bestand.
2. Lees `copilot/README.md` volledig.
3. Controleer dat de actieve branch exact `copilot/php-review-fixes` is. Bij een afwijking: stop en rapporteer `GEBLOKKEERD`.
4. Onderzoek de bestaande agenda-implementatie en lokaliseer de swipe-afhandeling voor media links en informatie rechts.
5. Controleer hoe de huidige navigatie, touch-events en eventuele muisbediening samenwerken voordat je iets wijzigt.

## Uit te voeren werk
1. Pas uitsluitend de noodzakelijke applicatiebestanden aan om de swipe-richting en betekenis gelijk te maken.
2. Houd de bestaande vormgeving, afmetingen, inhoud, animaties en overige agenda-functionaliteit ongewijzigd.
3. Behoud de bestaande grenzen: geen navigatie voorbij het eerste of laatste item.
4. Voorkom dat één swipe dubbel wordt verwerkt of dat touch- en muisgebeurtenissen elkaar onbedoeld activeren.
5. Gebruik waar mogelijk één gedeelde, consistente interpretatie van horizontale swipe-richting voor beide onderdelen.
6. Voeg geen nieuwe functies of visuele wijzigingen toe die niet nodig zijn voor deze opdracht.

## Tests en controle
- Voer passende controles uit op de gewijzigde bestanden.
- Voer beschikbare relevante tests uit.
- Controleer specifiek dat links swipen bij beide onderdelen vooruitgaat en rechts swipen bij beide onderdelen teruggaat.
- Als echte browser- of apparaattests niet beschikbaar zijn, vermeld dat uitdrukkelijk. Claim die tests niet als uitgevoerd.
- Rapporteer welke bestanden zijn gewijzigd en welke controles/tests daadwerkelijk zijn uitgevoerd.

## Toegestane wijzigingen
- Applicatiecode die aantoonbaar nodig is voor de swipe-aanpassing.
- `copilot/resultaat.md` voor de rapportage.
- Geen andere bestanden, tenzij een noodzakelijke wijziging vooraf duidelijk wordt gemotiveerd en eerst aan de gebruiker wordt voorgelegd.

## Commit- en workflowtoestemming
- Deze opdracht geeft geen toestemming om applicatiecode te committen, een pull request te maken of te mergen.
- Voer de codewijzigingen uit op de verplichte branch en laat ze ongecommit staan.
- Je mag maximaal één commit maken die uitsluitend `copilot/resultaat.md` bijwerkt.
- Deze opdracht geeft uitdrukkelijk beperkte toestemming voor die ene rapportagecommit op `copilot/php-review-fixes`.
- ChatGPT heeft de vorige opdracht en het vorige resultaat vooraf gearchiveerd. Overschrijf of verwijder niets onder `copilot/archive/`.

## Strikte beperkingen
- Werk uitsluitend op `copilot/php-review-fixes`.
- Wijzig `main` of `develop` niet.
- Maak geen branches of tags.
- Maak geen pull request en voer geen merge, rebase of cherry-pick uit.
- Voer geen productie-installatie, deployment of release uit.
- Voer geen database-, backup- of migratieacties uit.
- Verwijder geen bestanden.
- Als de gevraagde wijziging niet veilig kan worden uitgevoerd binnen deze grenzen, stop en rapporteer `GEBLOKKEERD`.

## Rapportage
Schrijf de volledige uitkomst naar `copilot/resultaat.md`. Gebruik voor de algemene status en elke controle uitsluitend:
- `GESLAAGD`
- `MISLUKT`
- `NIET UITGEVOERD`
- `GEBLOKKEERD`

Vermeld minstens:
- gecontroleerde branch en begincommit;
- wat je hebt vastgesteld over de bestaande swipe-logica;
- welke bestanden je hebt gewijzigd;
- hoe de swipe-richting voor media en informatie nu werkt;
- uitgevoerde controles en tests, met eerlijke beperkingen;
- expliciet dat geen applicatiecodecommit, pull request, merge, databasewijziging of productiehandeling is uitgevoerd;
- de eindstatus en eventuele resterende aandachtspunten.
