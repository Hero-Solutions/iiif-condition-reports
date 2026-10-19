# Eenmalig dubbele TMS-actoren samenvoegen

**Alleen door de beheerder handmatig uit te voeren. Codex benadert productie niet.**

De aangeleverde controle van 19 oktober 2026 toont voor actoren **17946** en **17947** dezelfde constituent `11311`, naam-ID `1137`, ontbrekend adres en gelijke actorvelden. Alleen `UnMasked` verschilt (`0`/`1`). Beide hebben één projectkoppeling en zes objectkoppelingen, zonder rapport- of contactpersoonkoppelingen.

De nieuwe import gebruikt `ConstituentID + NameID + AddressID` als actoridentiteit. `UnMasked` blijft in de oorspronkelijke project-/objectrelatie bewaard. Verschillende naam- en adres-ID's blijven afzonderlijk; tegenstrijdige bronvelden voor dezelfde identiteit breken de import af. Er wordt geen andere naam of standaardadres opgezocht.

## Volgorde

1. Maak een herstelbare databaseback-up. Pauzeer TMS-cronjobs en wijzigingen via de app tijdens de correctie.
2. Zet de nieuwe `src/Service/TmsActorSync.php`, `src/Service/TmsReportSync.php` en `tools/reconcile-tms-actors.php` klaar. **Start nog geen sync.** De bestaande importsleutels moeten eerst worden omgezet.
3. Voer de controle hieronder uit vanuit de applicatiemap. Hiervoor is PHP 8.2+ met PDO MySQL nodig. Het script leest geen appconfiguratie, `.env` of TMS-API.
4. Controleer het plan. Voer daarna dezelfde opdracht met `--apply` uit.
5. Vernieuw de Symfony-cache volgens de normale uitrolprocedure en hervat de app en synchronisatie pas na een geslaagde correctie. Laat bij een fout de sync gepauzeerd; herstel eerst het gemelde conflict of rol de nieuwe importcode terug.

## Controle, zonder wijzigingen

Deze opdrachten zijn voor een interactieve Bash-shell van de beheerder. Het wachtwoord wordt verborgen ingevoerd, via stdin doorgegeven en daarna uit de shellvariabele verwijderd.

```bash
read -r -s -p 'Databasewachtwoord: ' actor_db_password
printf '\n'
printf '%s\n' "$actor_db_password" | php tools/reconcile-tms-actors.php --database=condition_reports --user=condition_reports
unset actor_db_password
```

Het script controleert **alle geïmporteerde actoren**, omdat ook hun importsleutels moeten worden omgezet. Het toont alleen actor-ID's en aantallen. Voor dit paar hoort het plan `Actor 17946 -> 17947` te bevatten. Wanneer er geen andere duplicaten zijn, worden één projectkoppeling en zes objectkoppelingen omgezet. De aantallen zijn geen aantallen te verwijderen relaties.

## Toepassen

Na beoordeling van het controleplan:

```bash
read -r -s -p 'Databasewachtwoord: ' actor_db_password
printf '\n'
printf '%s\n' "$actor_db_password" | php tools/reconcile-tms-actors.php --database=condition_reports --user=condition_reports --apply
unset actor_db_password
```

De toepassing controleert het plan opnieuw. Alle wijzigingen gebeuren in één transactie. Bij fouten volgt een rollback. Het script vereist InnoDB voor de betrokken tabellen.

- Alleen records met dezelfde drie bron-ID's, exact gelijke actorvelden én dezelfde importbaseline worden samengevoegd. Afwijkende lokale gegevens blokkeren de hele correctie; er wordt niets stilzwijgend gekozen.
- Contactpersoonrelaties bij een samen te voegen paar blokkeren de correctie voor handmatige beoordeling.
- Alle project-, object- en rapportkoppelingen worden eerst overgezet. Hun ID, rol, bronmetadata, contactpersoon en overige velden blijven behouden. Ook bij definitieve rapporten wordt alleen de verwijzing naar de identieke actor omgezet; er worden geen rapportregels verwijderd.
- Daarna verdwijnen de overtollige actorrecords. Er blijven voor het gecontroleerde paar twee bronkoppelingen op projectniveau en twaalf op objectniveau bestaan, allemaal naar actor 17947. De projectweergave toont gelijke actor/rol/contactcombinaties eenmaal; verschillende rollen blijven zichtbaar.
- Alle behouden TMS-actoren krijgen de nieuwe importsleutel. Lokale actoren en de synthetische instellingsactor blijven ongewijzigd. Een tweede uitvoering verandert niets.

Er komt geen automatische samenvoegfunctie, aliastabel of compatibiliteitslaag in de app. Het losse script kan na de succesvolle correctie uit de uitrol worden verwijderd. De lokale regressietest gebruikt de scriptfuncties uitsluitend met een SQLite-geheugendatabase en fictieve gegevens; het MySQL-startpunt is daarbij niet uitgevoerd.
