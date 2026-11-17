# ChatGPT ↔ GitHub Copilot workflow

Deze map bevat de gecontroleerde, handmatige workflow voor opdrachten tussen ChatGPT en GitHub Copilot in deze repository.

## Vaste bestanden

- `copilot/README.md`: deze werkinstructies en veiligheidsregels.
- `copilot/opdracht.md`: de enige actieve opdracht voor Copilot.
- `copilot/resultaat.md`: het resultaat van de actieve opdracht.
- `copilot/archive/`: archief van afgeronde opdrachten en resultaten.

## Verplichte werkwijze voor Copilot

1. Controleer eerst de huidige repository en branch.
2. Lees `copilot/opdracht.md` volledig voordat je iets uitvoert.
3. Lees deze volledige `copilot/README.md`.
4. Controleer of de branch in de opdracht overeenkomt met de actieve branch. Bij een afwijking: stop en rapporteer `GEBLOKKEERD`.
5. Voer uitsluitend de acties uit die expliciet in `copilot/opdracht.md` zijn gevraagd.
6. Wijzig alleen bestanden die de opdracht uitdrukkelijk toestaat. Behandel deze workflowbestanden als toegestane bestanden wanneer de opdracht de workflow zelf laat controleren of bijwerken.
7. Schrijf alle bevindingen en uitgevoerde controles naar `copilot/resultaat.md`.
8. Meld eerlijk welke controles wel en niet zijn uitgevoerd. Bewijs geen test of controle die niet werkelijk is uitgevoerd.
9. Stop na de gevraagde opdracht. Wacht op een nieuwe opdracht.

## Veiligheidsregels

- Werk uitsluitend op de branch die letterlijk in de actieve opdracht staat.
- Wijzig `main` of `develop` nooit zonder expliciete toestemming in de opdracht.
- Maak geen merge en voer geen rebase, cherry-pick of pull request-merge uit zonder expliciete toestemming.
- Installeer niets op productie en voer geen release of deployment uit zonder expliciete toestemming.
- Voer geen databasewijzigingen, schemawijzigingen of migraties uit zonder expliciete toestemming én een gecontroleerde, bruikbare backup.
- Voer geen destructieve operaties uit, zoals bestanden of data verwijderen, tenzij dit expliciet is opgedragen en veilig afgebakend.
- Wijzig geen applicatiecode, configuratie, secrets, uploads of gebruikersdata tenzij dit expliciet in de opdracht staat.
- Maak geen commits, branches of tags tenzij de opdracht dit expliciet toestaat.
- **Beperkte committoestemming voor workflowadministratie:** een opdracht mag uitdrukkelijk toestemming geven voor commits die uitsluitend nodig zijn om `copilot/opdracht.md`, `copilot/resultaat.md` en bestanden onder `copilot/archive/` bij te werken. Deze toestemming geldt alleen op de branch die in die opdracht staat en alleen voor de genoemde workflowbestanden. Ze geeft geen toestemming om applicatiecode of andere bestanden te wijzigen, branches of tags te maken, te mergen of productie-, database- of migratiehandelingen uit te voeren.
- Als de opdracht deze beperkte committoestemming niet uitdrukkelijk geeft, maak dan geen commit. Als de beschikbare omgeving een bestand alleen kan opslaan door automatisch een commit te maken, stop dan en rapporteer `GEBLOKKEERD`.
- Verzin geen testresultaten, uitgevoerde acties, foutmeldingen of bevindingen.
- Bij onduidelijke instructies, ontbrekende rechten, onverwachte branchstatus of risico: stop en rapporteer `GEBLOKKEERD`. Ga niet zelf de opdracht uitbreiden.

## Verplichte statuswaarden

Gebruik voor de algemene status en per controle uitsluitend:

- `GESLAAGD`: de gevraagde actie of controle is daadwerkelijk uitgevoerd en geslaagd.
- `MISLUKT`: de actie is uitgevoerd, maar is mislukt.
- `NIET UITGEVOERD`: de actie is niet uitgevoerd en er is geen blokkade vastgesteld.
- `GEBLOKKEERD`: de actie kon niet veilig of bevoegd worden uitgevoerd.

Geef bij elke status een korte, feitelijke toelichting.

## Handmatige taakcyclus

1. ChatGPT stelt een opdracht op en schrijft die naar `copilot/opdracht.md`.
2. De gebruiker start Copilot handmatig en laat Copilot de opdracht uitvoeren.
3. Copilot schrijft het resultaat naar `copilot/resultaat.md`.
4. ChatGPT leest het resultaat rechtstreeks vanuit GitHub en beoordeelt of vervolgwerk nodig is.
5. Voordat een nieuwe opdracht wordt geplaatst, worden de vorige opdracht en het bijbehorende resultaat naar `copilot/archive/` gekopieerd met herkenbare bestandsnamen. Overschrijf het archief niet.
6. Daarna worden de actieve bestanden bijgewerkt voor de volgende opdracht.

## Wat deze eerste versie niet doet

- Geen automatische start van Copilot.
- Geen GitHub Actions-workflow.
- Geen automatische merge, deployment of productie-installatie.
- Geen automatische databasebackup of migratie.

GitHub Actions of aanvullende machtigingen zijn voor deze handmatige eerste versie niet nodig. Copilot moet wel via de door de gebruiker gebruikte GitHub/Copilot-omgeving toegang hebben tot de repository en de gekozen branch. De benodigde toegang wordt bepaald door de bestaande repository- en organisatie-instellingen.
