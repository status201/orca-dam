# ORCA DAM — Snelstartgids

**ORCA Retrieves Cloud Assets** — Je vriendelijke Digital Asset Manager

---

## Inhoudsopgave

1. [Welkom bij ORCA!](#welkom-bij-orca)
2. [De Gouden Regels](#de-gouden-regels)
3. [Aan de slag](#aan-de-slag)
4. [Bestanden uploaden](#bestanden-uploaden)
5. [Bladeren & zoeken](#bladeren--zoeken)
6. [Werken met tags](#werken-met-tags)
7. [Assetgegevens bewerken](#assetgegevens-bewerken)
8. [Assets vervangen](#assets-vervangen)
9. [De Prullenbak (alleen admin)](#de-prullenbak-alleen-admin)
10. [Bulksgewijs verplaatsen (alleen admin)](#bulksgewijs-verplaatsen-alleen-admin)
11. [Bestanden verplaatsen (de omweg)](#bestanden-verplaatsen-de-omweg)
11. [Discover-functie (alleen admin)](#discover-functie-alleen-admin)
12. [Metadata importeren (alleen admin)](#metadata-importeren-alleen-admin)
13. [Exporteren naar CSV (alleen admin)](#exporteren-naar-csv-alleen-admin)
13. [API Docs & tokenbeheer (alleen admin)](#api-docs--tokenbeheer-alleen-admin)
14. [S3-integriteitscontrole (alleen admin)](#s3-integriteitscontrole-alleen-admin)
15. [Gebruikersvoorkeuren](#gebruikersvoorkeuren)
16. [Tips & trucs](#tips--trucs)
17. [Woordenlijst](#woordenlijst)
18. [Hulp nodig?](#hulp-nodig)

---

## Welkom bij ORCA!

Gefeliciteerd met je toegang tot ORCA DAM! Of je nu afbeeldingen uploadt voor lesmateriaal, documenten beheert of mediabestanden organiseert voor Studyflow — je bent op de juiste plek.

Ooit geprobeerd om bestanden rechtstreeks op Amazon S3 te beheren? Geen zoekfunctie, geen notities bij bestanden, geen idee wie wat heeft geüpload, en één verkeerde klik maakt iets openbaar dat privé hoorde te zijn. **Niet leuk.** ORCA is de vriendelijke receptie vóór dat enorme magazijn — het zit tussen jou en de ruwe cloudopslag, en maakt alles veiliger, doorzoekbaar en beheersbaar.

---

## De Gouden Regels

ORCA heeft een paar bewuste beperkingen. Dit zijn geen bugs — het zijn veiligheidsfuncties!

**1. Je kunt bestanden hernoemen — maar niet verplaatsen**
Je kunt de **weergavenaam** van een asset altijd wijzigen via de Bewerk-pagina. De daadwerkelijke URL (S3 key) blijft hetzelfde, dus bestaande links blijven gewoon werken.

**2. Je kunt bestanden niet tussen mappen verplaatsen**
Verplaatsen zou de URL veranderen en alle bestaande links breken. Zie "Bestanden verplaatsen (de omweg)" hieronder voor de workaround.

**3. Soft delete is je vangnet**
Verwijderde bestanden gaan eerst naar de Prullenbak ("soft delete"). Alleen admins kunnen bestanden permanent verwijderen of herstellen. Per ongeluk iets verwijderd? Geen paniek — vraag een admin!

---

## Aan de slag

Na het inloggen zie je op je dashboard: **Totaal assets**, **Mijn assets**, **Totale opslag** en **Tag**-tellingen. Admins zien ook het aantal gebruikers en items in de prullenbak.

### Je rol: Editor vs Admin

| Functie | Editor | Admin |
|---------|:------:|:-----:|
| Alle assets bekijken | ✓ | ✓ |
| Bestanden uploaden | ✓ | ✓ |
| Assetgegevens bewerken (bestandsnaam, alt-tekst, bijschrift, tags) | ✓ | ✓ |
| Assets vervangen | ✓ | ✓ |
| Bestanden verwijderen (naar Prullenbak) | ✓ | ✓ |
| Persoonlijke voorkeuren instellen | ✓ | ✓ |
| Mappen aanmaken | ✗ | ✓ |
| Prullenbak bekijken | ✗ | ✓ |
| Herstellen uit Prullenbak | ✗ | ✓ |
| Bestanden permanent verwijderen | ✗ | ✓ |
| Niet-gekoppelde S3-bestanden ontdekken | ✗ | ✓ |
| Assets exporteren naar CSV | ✗ | ✓ |
| Gebruikers beheren | ✗ | ✓ |
| Systeeminstellingen openen | ✗ | ✓ |
| API-tokens & JWT-secrets beheren | ✗ | ✓ |

---

## Bestanden uploaden

Onthoud: je kunt bestanden later niet verplaatsen, dus **kies de juiste map vóór het uploaden**. Bedenk waar het bestand thuishoort en of andere teamleden het kunnen vinden. Nieuwe map nodig? Vraag een admin!

### Hoe uploaden

1. Klik op **Upload** in het navigatiemenu
2. Selecteer de doelmap
3. Sleep bestanden naar het uploadgebied, of klik om te bladeren
4. Bekijk de voortgangsbalken — grotere bestanden kunnen even duren
5. Klaar! Thumbnails en AI-tags worden op de achtergrond gegenereerd; ververs de pagina even

**Bestandslimiet:** Maximaal 500MB per bestand. Grotere bestanden worden automatisch in chunks geüpload, dus verbindingsproblemen kosten je geen voortgang.

Na het uploaden wordt je bestand opgeslagen in S3, een thumbnail gegenereerd (voor afbeeldingen), AI-tags toegevoegd indien ingeschakeld, en het asset verschijnt in je bibliotheek.

---

## Bladeren & zoeken

Bekijk assets in **Rasterweergave** (visuele thumbnails) of **Lijstweergave** (gedetailleerde tabel) — schakel met de knoppen rechtsboven.

**Zoeken:** Typ een deel van een bestandsnaam, tag, map, S3 key, alt-tekst of bijschrift. Gebruik zoekoperatoren voor precisie:
- `+term` — **verplicht** deze term (moet voorkomen in resultaten)
- `-term` — **sluit uit** deze term (mag niet voorkomen in resultaten)
- Voorbeeld: `landschap +berg -sneeuw` vindt "landschap"-assets die "berg" moeten bevatten maar niet "sneeuw"

**Filters:** Bestandstype (afbeeldingen/video's/documenten), map, tags (meervoudige selectie).

**Sorteren:** Datum gewijzigd, datum geüpload, grootte, naam of S3 key — elk oplopend of aflopend.

### Snelle acties

Beweeg over een asset om te zien: **👁 Bekijken**, **📋 URL kopiëren**, **✏️ Bewerken**, **⇄ Vervangen**, **🗑 Verwijderen**.

In Lijstweergave kun je tags en licentie-info direct inline bewerken.

### Meerdere assets selecteren

Klik op checkboxes om individuele assets te selecteren. Houd **Shift** ingedrukt en klik om een reeks te selecteren. Eenmaal geselecteerd verschijnt een werkbalk met bulkacties, waaronder **bulksgewijs tags beheren** (tags toevoegen aan of verwijderen van alle geselecteerde assets tegelijk).

---

## Werken met tags

Tags zijn labels waarmee je assets organiseert en vindt. Er zijn drie typen:

| Type | Icoon | Hoe aangemaakt |
|------|-------|----------------|
| **Gebruikerstags** | Blauw badge | Handmatig door jou toegevoegd |
| **AI-tags** | Paars badge | Automatisch gegenereerd door AI |
| **Referentietags** | Oranje badge met link-icoon | Toegevoegd door externe systemen via API |

**Tags zijn uniek** — je kunt geen twee tags met dezelfde naam hebben. Het type van een tag (gebruiker/AI/referentie) wordt bepaald bij aanmaak en verandert niet, zelfs als dezelfde tag later handmatig aan een ander asset wordt toegevoegd. Dit is vooral relevant voor statistieken, niet voor dagelijks gebruik.

**Referentietags** worden aangemaakt door externe systemen (bijv. een Rich Text Editor-integratie) om bij te houden welke assets ze gebruiken. Ze verschijnen als oranje badges met een link-icoon. Je kunt referentietags hernoemen of verwijderen net als gebruikerstags, maar ze kunnen alleen via de API worden aangemaakt.

**Tags toevoegen:** Op de Bewerk-pagina typ je een tagnaam en druk je op Enter. In Lijstweergave klik je op de **+** knop in de Tags-kolom.

**Tags verwijderen:** Klik op de **×** naast een tag. Dit verwijdert alleen de koppeling — de tag zelf blijft bestaan.

**Bulksgewijs taggen:** Selecteer meerdere assets (gebruik Shift+klik voor reeksen) en gebruik de bulktag-werkbalk om tags toe te voegen aan of te verwijderen van alle geselecteerde assets tegelijk.

> **Tags met "0 assets" zijn misschien niet echt leeg!** Ze kunnen nog gekoppeld zijn aan assets in de Prullenbak. Bij herstellen uit de Prullenbak blijven nog gekoppelde tags behouden, maar tags die je vóór het herstellen hebt verwijderd zijn voorgoed verdwenen.

---

## Assetgegevens bewerken

Klik op een asset of druk op Bewerken om aan te passen:

- **Bestandsnaam** — Alleen de weergavenaam; de URL en S3 key blijven hetzelfde, dus links breken nooit
- **Alt-tekst** — Korte beschrijving voor toegankelijkheid. Houd het beknopt maar beschrijvend (bijv. "Student studeert aan een laptop in een bibliotheek")
- **Bijschrift** — Langere beschrijving of creditregel die bij de afbeelding wordt weergegeven
- **Licentie-info** — Gebruiksrechten bijhouden:
  - **Licentietype** — Public Domain, Creative Commons-varianten, Fair Use, All Rights Reserved
  - **Licentie-vervaldatum** — Wanneer verloopt de licentie? (laat leeg als onbeperkt)
  - **Auteursrechthebbende** — Wie bezit de rechten?
  - **Auteursrechtbron** — Link naar waar je de licentie-informatie hebt gevonden

### AI-tags genereren

Als AI-tagging is ingeschakeld en je wilt nieuwe suggesties:
1. Open de Bewerk-pagina van een afbeelding
2. Klik op **AI-tags genereren**
3. Nieuwe tags verschijnen automatisch

*Let op: dit vervangt alle bestaande AI-tags op dat asset.*

---

## Assets vervangen

Moet je een bestand bijwerken zonder de URL te veranderen? **Asset vervangen** behoudt dezelfde URL, bewaart alle metadata (alt-tekst, bijschrift, tags, licentie-info) en wisselt alleen het bestand zelf. Alle bestaande links blijven werken.

### Hoe te vervangen

1. Ga naar de **Bewerk**-pagina → klik op **Bestand vervangen**
2. Je ziet een preview van het huidige bestand en een dropzone voor het nieuwe bestand
3. Sleep het vervangende bestand of blader ernaar
4. **Het nieuwe bestand moet dezelfde extensie hebben** (bijv. `.jpg` → `.jpg`, niet `.jpg` → `.png`)
5. Klik op **Bestand vervangen** en bevestig de waarschuwing

Als je het bestandsformaat volledig wilt wijzigen, moet je verwijderen en opnieuw uploaden (en dus alle links bijwerken).

### De placeholder-workflow

Hier komt Asset vervangen echt tot zijn recht:

1. **Upload placeholders** met duidelijke namen zoals `hero-image-DRAFT.jpg` — tag ze met `draft`!
2. **Link ze in Studyflow** via de ORCA-URLs
3. **Vervang wanneer gereed** — wissel de definitieve versies in
4. **Geen gebroken links** — Studyflow toont automatisch de nieuwe afbeeldingen

Filter op de `draft`-tag om al je placeholders te zien, vervang ze één voor één en verwijder de tag als je klaar bent.

### Belangrijke waarschuwingen

> **Het originele bestand is na vervanging permanent verdwenen** (tenzij S3-versioning is ingeschakeld — vraag je admin). Er is geen undo.

Bij het vervangen van een afbeelding worden de thumbnail, afmetingen en bestandsgrootte automatisch bijgewerkt naar het nieuwe bestand.

---

## De Prullenbak (alleen admin)

Verwijderde bestanden gaan naar de Prullenbak — een wachtruimte vóór definitieve verwijdering. Admins hebben toegang via het navigatiemenu.

- **Herstellen** — Breng het asset weer tot leven
- **Permanent verwijderen** — Voorgoed verwijderen (verwijdert ook het bestand uit S3)

Dit is je vangnet: per ongeluk iets verwijderd? Herstel het! Wil je controleren wat er is verwijderd? Check de Prullenbak. Voorkomt het "oh nee"-moment van onherroepelijke verwijdering.

---

## Bulksgewijs verplaatsen (alleen admin)

Admins kunnen assets tussen S3-mappen verplaatsen wanneer de **onderhoudsmodus** is ingeschakeld (Systeem → Instellingen → Onderhoud).

1. Schakel **onderhoudsmodus** in via Systeem → Instellingen
2. Ga naar de assetpagina en selecteer de bestanden die je wilt verplaatsen
3. Klik op de oranje **Verplaatsen**-knop in de bulkactiebalk
4. Kies de doelmap en klik op **Toepassen**
5. Bevestig de waarschuwing — S3-keys veranderen, waardoor oude externe links niet meer werken
6. Een overzicht toont oude → nieuwe keys (kopieerbaar) zodat je referenties kunt bijwerken
7. Schakel de onderhoudsmodus uit wanneer je klaar bent

Alle bijbehorende bestanden (miniatuur, formaatpresets S/M/L) worden automatisch meeverplaatst.

---

## Bestanden verplaatsen (de omweg)

Als de onderhoudsmodus niet beschikbaar is, of je een enkel bestand zonder admin-toegang wilt verplaatsen, is hier de handmatige workaround:

1. **Download** het bestand naar je computer
2. **Soft delete** het origineel in ORCA
3. Vraag een **admin om permanent te verwijderen** uit de prullenbak
4. **Upload** het bestand naar de juiste map
5. **Werk alle links bij** in Studyflow naar de nieuwe URL

Ja, het is omslachtig. Dat is met opzet — het dwingt je om goed na te denken en herinnert je eraan die links bij te werken.

---

## Discover-functie (alleen admin)

Soms belanden bestanden in S3 zonder via ORCA te gaan (directe uploads, migraties, etc.). **Discover** laat admins S3 scannen op niet-gekoppelde bestanden, ze bekijken en geselecteerde bestanden importeren in ORCA.

Bestanden die bij assets in de prullenbak horen krijgen een rood "Verwijderd"-badge om te voorkomen dat je iets per ongeluk opnieuw importeert dat bewust is verwijderd.

---

## Metadata importeren (alleen admin)

Werk asset-metadata in bulk bij vanuit een CSV. Ga naar het gebruikersmenu > **Importeren**, kies of je wilt matchen op `s3_key` of `filename`, en plak CSV-gegevens of upload/sleep een `.csv`-bestand.

Klik op **Importvoorbeeld bekijken** om te zien welke assets matchen en wat er verandert. Lege velden in de CSV worden overgeslagen (bestaande waarden blijven behouden). Tags worden toegevoegd aan bestaande tags, nooit verwijderd. Klik op **Importeren** om toe te passen.

---

## Exporteren naar CSV (alleen admin)

Admins kunnen de assetbibliotheek exporteren naar CSV: ga naar Assets, pas eventueel filters toe en klik op **Exporteren**. De export bevat bestandsgegevens, tags (gebruiker, AI en referentie in aparte kolommen), licentie-/auteursrechtinfo, publieke URLs en uploaderinformatie.

---

## API Docs & tokenbeheer (alleen admin)

Externe systemen kunnen je DAM benaderen via de API. Beheer authenticatie via de **API Docs**-pagina (klik op je naam → API Docs).

### API-tokens (Sanctum)

Langlevende credentials voor backend-naar-backend integraties. Zet deze nooit in frontend-code.

1. Ga naar API Docs → **API Tokens** tab
2. Selecteer een gebruiker, geef het token een beschrijvende naam (bijv. "Website CMS")
3. Klik op **Token aanmaken**
4. **Kopieer direct — wordt maar één keer getoond!**

Intrekken kan altijd vanuit de tokenlijst.

### JWT-secrets

Voor frontend-integraties (bijv. rich text editors). Je externe backend genereert kortstondige JWTs met het secret, en ORCA valideert ze.

1. Ga naar API Docs → **JWT Secrets** tab
2. Selecteer een gebruiker, klik op **Secret genereren**
3. **Kopieer direct — wordt maar één keer getoond!**
4. Deel het secret veilig met de backend-ontwikkelaar van het externe systeem

Intrekken kan vanuit de lijst wanneer het niet meer nodig is.

> JWT-authenticatie moet ingeschakeld zijn (`JWT_ENABLED=true` in `.env`). Je kunt het ook aan/uitzetten vanuit het API Docs-dashboard.

---

## S3-integriteitscontrole (alleen admin)

Bestanden kunnen soms verdwijnen uit S3 zonder via ORCA te gaan (per ongeluk verwijderd, bucketproblemen). De integriteitscontrole detecteert dit.

1. Ga naar de **Systeem**-pagina
2. Zoek de **S3-integriteit** kaart
3. Klik op **S3-integriteit verifieren** — dit plaatst een achtergrondcontrole in de wachtrij voor elke asset
4. De statustekst bevestigt hoeveel controles in de wachtrij staan
5. Klik op de **ververs**-knop om het aantal ontbrekende assets bij te werken terwijl de jobs worden verwerkt

**Ontbrekende assets bekijken:** Ga naar de Assets-pagina en filter met `?missing=1` in de URL. Ontbrekende assets tonen een waarschuwingsindicator.

**Wat te doen met ontbrekende assets:** Onderzoek waarom ze ontbreken, en herstel ze van een back-up of verwijder de verweesde records permanent via de Prullenbak.

---

## Gebruikersvoorkeuren

Pas ORCA aan via de **Profiel**-pagina (klik op je naam → Profiel → sectie Voorkeuren → Opslaan).

### Beschikbare voorkeuren

- **Thuismap** — Standaard startmap bij het bladeren door assets. Handig als je vooral in één map werkt (bijv. `assets/marketing`). Laat leeg voor de root. Gebruik het ververs-icoon (↻) om de mappenlijst te herladen als er nieuwe mappen zijn aangemaakt.
- **Items per pagina** — Kies uit 12, 24, 36, 48, 60, 72 of 96. Selecteer "Standaard gebruiken" om de systeeminstelling te volgen. De per-pagina dropdown op de Assets-pagina werkt nog steeds als sessie-override.
- **Taal** — Engels of Nederlands. Selecteer "Standaard gebruiken" om de instelling van de admin te volgen. Wijzigingen gelden vanaf de volgende paginalading.

Voorkeuren volgen een prioriteit: URL-parameters > jouw gebruikersvoorkeur > systeeminstelling. Je voorkeuren worden gerespecteerd, maar vrij navigeren (klikken op mappen, dropdowns wijzigen) reset pas wanneer je een nieuwe pagina laadt.

---

## Tips & trucs

**Sneltoetsen:** Enter om te bevestigen, Escape om te annuleren.

**Best practices:**
1. **Geef bestanden een duidelijke naam vóór het uploaden** — je kunt later hernoemen, maar duidelijke originelen helpen
2. **Gebruik tags royaal** — ze maken zoeken veel makkelijker
3. **Vul alt-tekst in** — goed voor toegankelijkheid en het helpt je herinneren wat er op de afbeelding staat
4. **Kies mappen verstandig** — de mappenstructuur is permanent
5. **Check de Prullenbak** voordat je vraagt "waar is mijn bestand gebleven?"

---

## Woordenlijst

| Term | Betekenis |
|------|-----------|
| **S3** | Amazons cloudopslag waar je bestanden daadwerkelijk staan |
| **Soft delete** | Een bestand naar de Prullenbak sturen (herstelbaar) |
| **Hard delete** | Een bestand voorgoed verwijderen (niet herstelbaar) |
| **AI-tags** | Tags die automatisch zijn gegenereerd door kunstmatige intelligentie |
| **Gebruikerstags** | Tags die handmatig door mensen zijn toegevoegd |
| **Referentietags** | Tags toegevoegd door externe systemen om assetgebruik bij te houden (oranje badges) |
| **S3 Key** | Het technische pad/adres van een bestand in cloudopslag |
| **Custom domain** | Een vriendelijke URL (zoals `cdn.example.com`) in plaats van de ruwe S3-bucket-URL |
| **Rekognition** | Amazons AI-dienst die afbeeldingen analyseert en tags voorstelt |
| **Vervangen** | Een nieuw bestand uploaden dat een bestaand asset overschrijft, met behoud van dezelfde URL |

---

## Hulp nodig?

- Check eerst deze handleiding (je bent er al!)
- Vraag je admin voor hulp met rechten of het herstellen van bestanden
- Voor technische problemen, neem contact op met je systeembeheerder
- Admins: zie `README.md`, `CLAUDE.md` en `DEPLOYMENT.md` voor technische documentatie

---