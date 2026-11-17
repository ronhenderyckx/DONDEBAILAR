# Eerste opdracht – Controle van de handmatige Copilot-workflow

## Repository
`ronhenderyckx/DONDEBAILAR`

## Verplichte branch
`copilot/php-review-fixes`

## Doel
Controleer uitsluitend of de handmatige ChatGPT-Copilot-workflow correct en veilig is ingericht.

## Verplichte voorbereiding
1. Lees dit volledige bestand voordat je begint.
2. Lees daarna `copilot/README.md` volledig.
3. Controleer dat de actieve branch exact `copilot/php-review-fixes` is. Als dat niet zo is, stop dan onmiddellijk en rapporteer `GEBLOKKEERD`.
4. Controleer de aanwezigheid en inhoud van de workflowbestanden en archiefmap.

## Gevraagde controles
- `copilot/README.md` bestaat en beschrijft de workflow en veiligheidsregels.
- `copilot/opdracht.md` bestaat en bevat deze opdracht.
- `copilot/resultaat.md` bestaat als resultatenbestand.
- `copilot/archive/` bestaat.
- De werkwijze maakt duidelijk dat Copilot de opdracht eerst volledig leest, uitsluitend op de opgegeven branch werkt en alleen gevraagde acties uitvoert.
- De statuswaarden en de regels voor rapportage zijn duidelijk.

## Toegestane wijzigingen
Voor deze eerste controle zijn geen wijzigingen nodig. Als een workflowbestand ontbreekt of onjuist is, rapporteer dit dan in `copilot/resultaat.md` en stop. Wacht op een nieuwe opdracht om bestanden te herstellen.

## Strikte beperkingen
- Wijzig uitsluitend het bestand `copilot/resultaat.md` om je bevindingen te rapporteren.
- Wijzig geen applicatiecode.
- Wijzig `main` of `develop` niet.
- Maak geen branch, commit, tag, merge of pull request.
- Voer geen productiehandelingen, deployment of installatie uit.
- Voer geen database-, backup- of migratieacties uit.
- Verwijder of overschrijf geen bestanden.
- Voer geen andere acties uit dan de hierboven genoemde controles en rapportage.

## Rapportage
Schrijf de uitkomst naar `copilot/resultaat.md`. Gebruik uitsluitend deze statuswaarden:
- GESLAAGD
- MISLUKT
- NIET UITGEVOERD
- GEBLOKKEERD

Vermeld per controle wat je daadwerkelijk hebt vastgesteld. Vermeld ook expliciet dat applicatiecode, database en productie niet zijn gewijzigd of aangeraakt.
