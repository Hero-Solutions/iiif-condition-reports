# Dagelijkse TMS-import

`app:tms:sync` importeert alle bruiklenen en tentoonstellingen uit de zelfstandige [TMS API](../../tms-api/README.md) op de DataHub-server. Het commando draait op de server van de conditierapportenapp. Het gebruikt uitsluitend de API, niet SQL Server of de MySQL-database van DataHub.

## Ingebruikname door de beheerder

Alle onderstaande serverhandelingen zijn **uitsluitend voor de beheerder om zelf uit te voeren**. Ze zijn niet uitgevoerd tijdens de ontwikkeling. Er zijn geen productieverbindingen, credentials of bestaande `.env`-bestanden gelezen.

1. Installeer de gewijzigde applicatiecode en bekijk de openstaande migraties. Migratie `Version20261018180000` voegt de TMS-projecten en -objecten toe; `Version20261018200000` voegt actoridentiteiten en actorkoppelingen toe. Voer beide en de vereiste eerdere applicatiemigraties uit vóór gebruik van de gewijzigde app.
2. Neem de instellingen uit [tms-api-settings.example](tms-api-settings.example) over in de eigen `.env`/`.env.local`. Vul URL, Basic-authgebruikersnaam en wachtwoord in. Zet `EXTERNAL_LOANS_ENABLED=1`. De instelling schakelt zowel bruiklenen als tentoonstellingen in.
3. Zorg dat de app de private map `var/tms-sync/` kan maken en beschrijven. Gebruik één vaste scheduler op één appserver. Het commando gebruikt daar een bestandslot tegen gelijktijdige uitvoeringen. Plan de DataHub-objectharvest en TMS-import na elkaar.
4. Plan de appimport na een geslaagde MySQL-dump, zonder overlap. De API leest de actuele MySQL-tabellen rechtstreeks; ze detecteert geen lopende of mislukte dump. De API moet alle tentoonstellingen aanbieden (`exhibition_ids=null`). Werk het script en de app samen bij naar API-contract 2; er zijn geen dumpcommando's nodig.
5. Vul op de API-server de toegestane `party_visibility`-regels in `loan_projects.php` in, zoals beschreven in de [API-documentatie](../../tms-api/README.md#rechten-voor-partijen-en-documenten). Een lege lijst schakelt actoren uit. De app meldt dat expliciet en markeert eerdere TMS-actorkoppelingen als niet actief voor nieuwe rapporten.
6. Voer daarna op de appserver zelf een eerste import uit:

   ```sh
   php bin/console app:tms:sync --env=prod --no-debug --no-interaction
   ```

Controleer de tellingen en de geïmporteerde projecten. Onvolledige datasets, ontbrekende relatie-ID's, dubbele bron-ID's en conflicterende inventarisnummers laten de volledige import mislukken. Het commando geeft exitcode 1; een geslaagde import of een bewust overgeslagen run geeft 0. Een uitgeschakelde import doet geen API-aanvragen en verandert geen gegevens.

Bij API-fouten toont het commando de concrete gecontroleerde foutmelding, met `manifest` of de dataset en offset. Verbindingsfouten bevatten de cURL-code en de vaste beschrijving; HTTP-fouten bevatten de statuscode. Dit werkt zonder `-vvv`. Standaard worden wachtwoorden, HTTP-antwoorden en SQL niet weergegeven. Overige onverwachte fouten behouden de algemene melding; de foutklasse alleen bewijst geen databaseprobleem.

Voor diagnose kan de beheerder zelf `php bin/console app:tms:sync --env=prod --no-debug --no-interaction -v` uitvoeren. Vóór elke aanvraag verschijnt de volledige URL met dataset, offset en limiet. Daarna volgen HTTP-status, tijdsduur, ontvangen bytes en een responsfragment van maximaal 2048 bytes na afscherming. De native client toont ook het bereikte IP, content-type en cumulatieve DNS-, TCP-, TLS- en eerste-bytetijden sinds het begin van de aanvraag (nul betekent dat er geen tijd voor die stap is gemeten). Een time-out toont de reeds ontvangen body, of expliciet dat er geen status/body ontvangen is. Het commando meldt ook wanneer het opslaan in de appdatabase begint. `-vv` en `-vvv` tonen dezelfde begrensde diagnostiek.

Verbose responsfragmenten kunnen object- en actorgegevens of foutdetails van de API bevatten; deel alleen de relevante, geschoonde uitvoer. De bekende Basic-gebruikersnaam, het wachtwoord en hun gebruikelijke JSON/URL/Base64-vormen worden afgeschermd. Authorization-headers en ruwe cURL-debuglogs worden niet afgedrukt. Zonder `-v` blijven response bodies verborgen. Dit blijft een echte import en is geen dry-run.

## Dagelijks plannen

Voorbeeld voor de **crontab van de applicatiegebruiker**, door de beheerder aan te passen en zelf toe te voegen:

```cron
15 4 * * * cd /pad/naar/iiif-condition-reports && /usr/bin/php bin/console app:tms:sync --env=prod --no-debug --no-interaction >> var/log/tms-sync.log 2>&1
```

Dit is dagelijks om 04:15 in de tijdzone van de scheduler. Kies een tijdstip na de gecontroleerde dump en de objectharvest. Zorg dat `var/log/` bestaat en neem het log op in de bestaande logrotatie/controle van mislukte jobs. Start de appimport alleen na een geslaagde dump. Na een fout kan de import opnieuw worden uitgevoerd; de API kan een onvolledige dump niet herkennen. Er is lokaal geen cronjob geïnstalleerd.

## Wat wordt bijgewerkt?

| Bron | Gedrag in de app |
| --- | --- |
| Bruikleen / tentoonstelling | Afzonderlijk project met vaste identiteit `(loans of exhibitions, TMS-ID)`. Een lokaal project met hetzelfde referentienummer wordt niet automatisch samengevoegd. |
| Titel, referentie, omschrijving, bruikleendata | Bijgewerkt zolang het appveld nog gelijk is aan de vorige geïmporteerde waarde. Een lokale afwijking blijft behouden. De laatste bronwaarde blijft zichtbaar onder **Gegevens uit TMS** bij de projectdetails. |
| Objecten | Eerst via vaste TMS-ID, anders exact inventarisnummer. Ontbrekende objecten worden aangemaakt, ook inkomende bruikleenobjecten zonder DataHub-record. Voorloopnullen blijven behouden. Conflicten stoppen de import. |
| Bestaande DataHub-/manuele objecten | Identiteit, beschrijving, afbeeldingen en andere lokale velden blijven intact. De TMS-associatie staat in afzonderlijke velden die de DataHub-harvest niet wist. |
| Nieuwe TMS-objecten | Titel volgt de actieve/weergegeven titels, met voorkeur voor Nederlands (LanguageID 2), daarna DisplayOrder en TitleID. Lokale titelwijzigingen blijven bij volgende imports behouden. |
| Projectobjecten | Bruikleen- en tentoonstellingskoppelingen blijven afzonderlijk. Meerdere bronregels voor dezelfde project/objectcombinatie blijven als bronmetadata behouden bij één appkoppeling. Notities, zaal en werkstatus blijven lokaal. |
| Tentoonstelling–bruikleen | Koppelingen zichtbaar bij de projectdetails. Objecten van een bruikleen worden niet automatisch als tentoonstellingsobjecten aangemerkt. |
| Verdwenen project/koppeling | Krijgt de markering **niet meer aanwezig in TMS**. Geen verwijdering, annulering of wijziging van rapporten. Een terugkerende bronrij hergebruikt de bestaande appidentiteit. |
| Actoren en adressen | Actieve, weergegeven `parties` worden herbruikbare actoren met naam en dossieradres. Bruikleenactoren worden `ProjectActor`; objectactoren worden `ProjectObjectActor`. Lokale wijzigingen in naam, adres, type, rol en contactpersoon blijven behouden bij dezelfde bronidentiteit. |
| Tentoonstellingsactoren | Bruikleenactoren worden aan een tentoonstellingsobject gekoppeld als het object zowel bij die bruikleen als die tentoonstelling hoort, én de tentoonstelling–bruikleenkoppeling bestaat. Ze gelden niet automatisch voor alle werken in de tentoonstelling. |
| Vervaardigers | Herkende vervaardigersrollen vullen het vervaardigersveld van TMS-objecten. DataHub- en handmatige objectvelden blijven behouden. De oorspronkelijke rollen blijven beschikbaar bij de actorkoppelingen. |
| Verdwenen/uitgeschakelde actorrelatie | Wordt niet meer overgenomen in nieuwe rapporten. De actor en bestaande rapportkoppelingen worden niet verwijderd. Handmatige actorkoppelingen blijven actief. |
| Documenten, transporten, voorwaarden | Blijven brongegevens; geen downloads of automatische overschrijving van rapportteksten. Documenten mogen via het API-manifest uitgeschakeld zijn. |

TMS-statusnamen worden bewust niet naar appwerkstatussen vertaald. Een `Closed`-bruikleen is bijvoorbeeld geen opdracht om lokale conditierapporten af te sluiten. Nieuwe projecten beginnen met de bestaande standaardwerkstatus **actief**. Gedeeltelijke datums en leesbare tentoonstellingsdateringen blijven brongegevens; er worden geen kalenderdatums bij verzonnen.

Actoren worden gekoppeld op TMS-identiteit, nooit uitsluitend op naam. De combinatie `ConstituentID`, `NameID`, `AddressID` en `UnMasked` bewaart verschillende naam-/adresvarianten afzonderlijk. Daardoor kunnen een lokale actor en meerdere TMS-actoren dezelfde naam hebben. Alleen de door de API aangeleverde relatienaam wordt gebruikt; nooit een andere standaardnaam. Een gewijzigde naam- of adres-ID kiest een afzonderlijke actorvariant en wist een eventuele contactpersoon op die gewijzigde koppeling. De oude variant blijft bestaan.

`RoleTypeID=17/18` wordt respectievelijk eigenaar/bruikleengever en bruikleennemer, overeenkomstig de aangeleverde KMSKA-exports. Daarnaast worden herkenbare Nederlandse/Engelse rollabels vertaald. Andere rollen worden **Andere**, met de oorspronkelijke roltekst. `SystemFlag` heeft hier nog geen bewezen mapping naar persoon/organisatie: nieuwe TMS-actoren krijgen **Type nog te bepalen**, dat je zelf kunt aanpassen. Het script levert geen bewezen contactpersoonrelaties, e-mail of telefoon; die worden niet verzonnen of uit vrije tekst afgeleid.

Nieuwe rapporten krijgen de actuele project- en objectactoren. Bij een TMS-project worden TMS-actoren uit het vorige rapport niet blind overgenomen; de huidige koppelingen bepalen de selectie. Bestaande rapportkoppelingen en lokale rapportdata worden niet herschreven. Actornaam en adres zijn, net als bij handmatige actoren, gedeelde actorvelden: een wijziging daarvan kan wel zichtbaar worden in bestaande weergaven die naar dezelfde actor verwijzen. Een handmatig verwijderde TMS-projectobject- of actorkoppeling wordt bij de volgende import opnieuw aangemaakt als die nog in TMS staat.

## Consistentie en controle

Alle pagina's worden eerst naar tijdelijke bestanden buiten `public/` geschreven. Elke pagina moet dezelfde scope hebben; na de download worden de datasetinstellingen en scope opnieuw gecontroleerd. Pas daarna wordt één databasetransactie gestart. Fouten tijdens het koppelen of opslaan rollen alle appwijzigingen en bijbehorende objectlogregels terug. Bestaande rijen worden tijdens bijwerken vergrendeld. Tijdelijke bestanden worden na succes of een opgevangen fout verwijderd. Na een harde processtop kunnen restbestanden achterblijven in de private importmap; de bestandsvergrendeling komt vanzelf vrij.

De API biedt geen consistente bronsnapshot over meerdere aanvragen. Gegevenswijzigingen tijdens het ophalen kunnen daardoor een gemengde import opleveren. De apptransactie beschermt alleen het opslaan in de app. De lokale `import_id` is uitsluitend een traceer-ID; er is geen dump-ID of gedeeld statusbestand. Bronmetadata blijft voor verdwenen records als geschiedenis bewaard. De benodigde tabellen in de app moeten transactioneel zijn (de applicatiemigraties gebruiken InnoDB).

Geïsoleerde controles vanuit de workspace, zonder appbootstrap of netwerk:

```powershell
php -n -d 'extension_dir=C:/Program Files/PHP/ext' -d extension=pdo_sqlite -d extension=mbstring iiif-condition-reports/tests/tms_project_sync.php
php -n iiif-condition-reports/tests/tms_api_client.php
php -n -d 'extension_dir=C:/Program Files/PHP/ext' -d extension=curl iiif-condition-reports/tests/tms_api_client_curl.php
php -n -d 'extension_dir=C:/Program Files/PHP/ext' -d extension=mbstring iiif-condition-reports/tests/tms_source_view.php
php -n -d 'extension_dir=C:/Program Files/PHP/ext' -d extension=pdo_sqlite -d extension=mbstring tms-api/tests/import-integration.php
```

De tests gebruiken verzonnen gegevens, een nep-HTTP-transport en SQLite in geheugen. Ze controleren onder meer idempotentie, paginering, rollback, actoridentiteiten, adresvarianten, rapportovername, verdwenen/terugkerende relaties, lokale wijzigingen, DataHub-samenwerking, uitgeschakelde imports en overlappende runs. De ketentest vereist de naastliggende `tms-api`- en appcheckout en gebruikt de daadwerkelijke queries en JSON van `loan_projects.php`. Er is geen test tegen de uiteindelijke productie-API, MySQL-dump of serverplanning uitgevoerd.
