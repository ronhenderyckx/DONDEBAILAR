# Eerste opdracht – Afronden van de handmatige Copilot-workflowcontrole

## Repository
`ronhenderyckx/DONDEBAILAR`

## Verplichte branch
`copilot/php-review-fixes`

## Doel
Leg de uitgevoerde statische controles vast in `copilot/resultaat.md` en rond de workflowcontrole af.

## Verplichte voorbereiding
1. Lees dit volledige bestand voordat je begint.
2. Lees daarna `copilot/README.md` volledig.
3. Controleer dat de actieve branch exact `copilot/php-review-fixes` is. Als dat niet zo is, stop dan onmiddellijk en rapporteer `GEBLOKKEERD`.
4. Controleer dat de inhoud van `copilot/resultaat.md` overeenkomt met de bevindingen uit je uitgevoerde controles. Verzin geen ontbrekende bevindingen.

## Te rapporteren controles
Gebruik de werkelijk uitgevoerde controles en de volgende bevindingen als uitgangspunt:
- De verplichte branchnaam kwam overeen met `copilot/php-review-fixes`.
- `copilot/README.md` was aanwezig en beschreef de handmatige workflow, veiligheidsregels en statuswaarden.
- `copilot/opdracht.md` was aanwezig en bevatte de controleopdracht.
- `copilot/resultaat.md` was aanwezig, maar bevatte nog het beginsjabloon.
- `copilot/archive/` was vertegenwoordigd door `copilot/archive/README.md`.
- Applicatiecode, database en productie zijn niet gewijzigd of aangeraakt.
- PHP-syntaxcontroles, applicatietests en GitHub Actions zijn niet uitgevoerd; ze waren niet gevraagd voor deze documentatiecontrole.

Controleer de huidige bestanden opnieuw waar nodig. Als een bewering niet door je eigen controle kan worden bevestigd, vermeld dat dan eerlijk.

## Toegestane wijzigingen
- Wijzig uitsluitend `copilot/resultaat.md` om het definitieve controlerapport op te slaan.
- Maak voor het opslaan van dit rapport maximaal één commit op `copilot/php-review-fixes`.
- Deze opdracht geeft uitdrukkelijk beperkte toestemming voor die ene rapportagecommit, uitsluitend voor `copilot/resultaat.md`.
- De wijziging aan `copilot/README.md` beschrijft de algemene beperkte commitregel; deze opdracht staat geen andere bestandswijzigingen toe.

## Strikte beperkingen
- Werk uitsluitend op `copilot/php-review-fixes`.
- Wijzig geen applicatiecode of andere bestanden.
- Wijzig `main` of `develop` niet.
- Maak geen branch, tag, merge of pull request.
- Voer geen productiehandelingen, deployment of installatie uit.
- Voer geen database-, backup- of migratieacties uit.
- Verwijder of overschrijf geen bestanden behalve het bijwerken van `copilot/resultaat.md`.
- Als je de rapportage niet kunt opslaan zonder een commit buiten deze uitdrukkelijke toestemming, stop dan en rapporteer `GEBLOKKEERD`.

## Rapportage
Schrijf de uitkomst naar `copilot/resultaat.md`. Gebruik uitsluitend deze statuswaarden:
- GESLAAGD
- MISLUKT
- NIET UITGEVOERD
- GEBLOKKEERD

Vermeld per controle wat je daadwerkelijk hebt vastgesteld. Vermeld expliciet dat applicatiecode, database en productie niet zijn gewijzigd of aangeraakt. Vermeld ook of de rapportagecommit is gemaakt en geef de commit-SHA als die beschikbaar is.
