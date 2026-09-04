# Microsoft Entra SSO

De conditierapporteringstool gebruikt OpenID Connect via Microsoft Entra ID. Dit vervangt de oude SimpleSAML/ADFS-aanpak van Imagehub.

Als KMSKA nog ADFS gebruikt voor de eigen accounts, kan Entra die login op de achtergrond doorsturen naar ADFS. De applicatie zelf blijft dan gewoon met Entra communiceren.

## Werking

- Alleen accounts met een toegewezen Entra-app-rol worden toegelaten.
- De gebruiker wordt bij de eerste login lokaal aangemaakt.
- Naam, e-mail en rol worden bij elke SSO-login gesynchroniseerd.
- De hoogste toegewezen rol wint: administrator, alleen-lezen, gebruiker.
- Een lokaal gedeactiveerd account wordt bij de volgende aanvraag uitgelogd.
- Lokale accounts kunnen via de deploymentconfiguratie volledig worden uitgeschakeld.

## Eenmalige configuratie door de Entra-beheerder

### 1. Applicatie registreren

1. Open [Microsoft Entra admin center](https://entra.microsoft.com/).
2. Kies **Entra ID**.
3. Kies **App registrations**.
4. Klik **New registration**.
5. Vul bij **Name** in: `KMSKA Conditierapporten`.
6. Kies bij **Supported account types**: **Accounts in this organizational directory only**.
7. Kies bij **Redirect URI** als platform **Web**.
8. Vul exact in: `https://conditierapporten.kmska.be/sso/callback`.
9. Klik **Register**.
10. Kopieer op **Overview**:
    - **Application (client) ID**;
    - **Directory (tenant) ID**.
11. Open **Owners**.
12. Klik **Add owners**, selecteer Floris en bevestig. Zo kan hij de toepassing en gebruikerstoewijzingen beheren zonder dat dit bij Michiel terechtkomt.

De callback-URL is hoofdlettergevoelig en moet exact overeenkomen met de URL hierboven.

### 2. Client secret maken

1. Open in de appregistratie **Certificates & secrets**.
2. Open **Client secrets**.
3. Klik **New client secret**.
4. Vul bij **Description** in: `Conditierapporten productie`.
5. Kies een vervaldatum volgens het KMSKA-beleid.
6. Klik **Add**.
7. Kopieer onmiddellijk de volledige **Value**. Kopieer niet de **Secret ID**.
8. Noteer ook de vervaldatum voor tijdige rotatie.

### 3. OpenID Connect-rechten instellen

1. Open **API permissions**.
2. Klik **Add a permission**.
3. Kies **Microsoft Graph**.
4. Kies **Delegated permissions**.
5. Open **OpenId permissions**.
6. Selecteer `email`, `openid` en `profile`.
7. Klik **Add permissions**.
8. Klik indien het KMSKA-beleid dit vereist op **Grant admin consent for KMSKA** en bevestig.

Er zijn geen rechten nodig om gebruikers of groepen uit Microsoft Graph te lezen.

### 4. Applicatierollen maken

Open **App roles** en klik voor iedere rij op **Create app role**. Vul de waarden exact en hoofdlettergevoelig in.

| Display name | Allowed member types | Value | Description | Enabled |
|---|---|---|---|---|
| Conditierapporten - gebruiker | Users/Groups | `ConditionReports.User` | Rapporten bekijken en bewerken | Yes |
| Conditierapporten - alleen lezen | Users/Groups | `ConditionReports.ReadOnly` | Rapporten alleen bekijken | Yes |
| Conditierapporten - administrator | Users/Groups | `ConditionReports.Admin` | Volledig beheer | Yes |

Wijs per gebruiker of groep bij voorkeur precies één rol toe.

### 5. Toegang beperken tot toegewezen gebruikers

1. Ga terug naar **Entra ID**.
2. Kies **Enterprise applications**.
3. Kies **All applications**.
4. Open `KMSKA Conditierapporten`.
5. Kies **Properties**.
6. Zet **Assignment required?** op **Yes**.
7. Controleer dat **Enabled for users to sign-in?** op **Yes** staat.
8. Klik **Save**.
9. Open **Owners**, klik **Add user** en voeg Floris ook hier als eigenaar toe. Dit is de relevante eigenaar voor het dagelijkse beheer van de enterprise application.

### 6. Eerste testgebruiker toevoegen

1. Open binnen de enterprise application **Users and groups**.
2. Klik **Add user/group**.
3. Klik onder **Users and groups** op **None selected**.
4. Zoek en selecteer de testgebruiker.
5. Klik **Select**.
6. Klik onder **Select a role** op **None selected**.
7. Kies `Conditierapporten - administrator` voor de eerste beheerder.
8. Klik **Select**.
9. Klik **Assign**.

Groepstoewijzing vereist doorgaans Microsoft Entra ID P1 of P2. Individuele gebruikers kunnen ook zonder groepstoewijzing worden toegevoegd.

### 7. Gegevens veilig doorgeven aan Michiel

Geef deze vier waarden door:

```text
Tenant ID:
Client ID:
Client secret VALUE:
Vervaldatum client secret:
```

## Configuratie en deployment door Michiel

### 1. Ansible host-config aanvullen

Voeg in het bestaande `condition_reports`-blok toe:

```yaml
condition_reports:
  local_login_enabled: false
  sso:
    enabled: true
    tenant_id: 'DIRECTORY-TENANT-ID'
    client_id: 'APPLICATION-CLIENT-ID'
    client_secret: 'CLIENT-SECRET-VALUE'
    role_user: ConditionReports.User
    role_read_only: ConditionReports.ReadOnly
    role_admin: ConditionReports.Admin
```

Gebruik bij `client_secret` de **Value**, niet de Secret ID.

Met `local_login_enabled: false` verdwijnen de lokale login en het wachtwoordherstel, wordt het aanmaken van lokale gebruikers geblokkeerd en worden bestaande lokale sessies afgemeld. Entra-gebruikers houden een technisch lokaal profiel voor applicatiegegevens, maar kunnen uitsluitend via SSO aanmelden.

De Ansible-role maakt geen lokaal administratoraccount aan. Verwijder een eventueel oud `condition_reports.admin`-blok uit de hostconfiguratie.

### 2. Deployen

1. Push de applicatiecode naar de branch die Ansible uitrolt.
2. Push de aangepaste configuration-management-code.
3. Voer de gewone Ansible-run uit.
4. De run installeert de OAuth-dependencies, schrijft de SSO-configuratie en voert de database-migratie uit.

### 3. Server controleren

```bash
sudo -u vkc php /opt/condition_reports/bin/console debug:router | grep sso
sudo -u vkc php /opt/condition_reports/bin/console doctrine:migrations:status
grep -E '^SSO_(ENABLED|ENTRA_TENANT_ID|ENTRA_CLIENT_ID|ENTRA_ROLE_)' /opt/condition_reports/.env
```

Toon de regel `SSO_ENTRA_CLIENT_SECRET` niet in terminaloutput of screenshots.

### 4. Functioneel testen

1. Open `https://conditierapporten.kmska.be/nl/login` in een privévenster.
2. Klik **Inloggen met KMSKA-account**.
3. Meld aan met de toegewezen testgebruiker.
4. Controleer dat `/nl/projects` opent.
5. Open **Beheer > Gebruikers**.
6. Controleer dat de gebruiker als bron **KMSKA SSO** heeft en de juiste rol toont.
7. Test daarna een gebruiker zonder toewijzing. Die mag geen toegang krijgen.
8. Test indien nodig ook de rollen alleen-lezen en gebruiker.

## Dagelijks gebruikersbeheer door Floris

### Gebruiker of groep toevoegen

1. Open [Microsoft Entra admin center](https://entra.microsoft.com/).
2. Kies **Entra ID > Enterprise applications > All applications**.
3. Open `KMSKA Conditierapporten`.
4. Kies **Users and groups**.
5. Klik **Add user/group**.
6. Selecteer de gebruiker of groep.
7. Selecteer één van de drie conditierapportenrollen.
8. Klik **Assign**.

Het lokale account verschijnt automatisch zodra die persoon de eerste keer inlogt.

### Rol wijzigen

1. Verwijder de bestaande toewijzing onder **Users and groups**.
2. Voeg dezelfde gebruiker of groep opnieuw toe met de nieuwe rol.
3. Laat de gebruiker uitloggen en opnieuw inloggen. Dan wordt de lokale rol bijgewerkt.

### Toegang verwijderen

1. Open **Users and groups** in de enterprise application.
2. Selecteer de gebruiker of groep.
3. Klik **Remove** en bevestig.

Hierdoor kan de gebruiker geen nieuwe SSO-login meer uitvoeren. Een bestaande sessie kan nog actief zijn tot de sessie verloopt. Voor onmiddellijke blokkering zet een applicatiebeheerder de gebruiker daarnaast onder **Beheer > Gebruikers** op **Inactief**.

## Veelvoorkomende fouten

| Fout | Controle |
|---|---|
| `AADSTS50011` | De Web redirect URI moet exact `https://conditierapporten.kmska.be/sso/callback` zijn. |
| `AADSTS7000215` | Gebruik de client secret **Value**, niet de Secret ID; controleer ook de vervaldatum. |
| Niet toegewezen aan de applicatie | Voeg de gebruiker toe onder **Enterprise applications > Users and groups**. |
| Applicatie meldt geen toegang | Wijs een van de drie app-rollen toe; `Default Access` volstaat niet. |
| Verkeerde rol zichtbaar | Laat de gebruiker uitloggen en opnieuw via SSO aanmelden. |
| SSO-knop ontbreekt | Controleer `condition_reports.sso.enabled: true` en voer Ansible opnieuw uit. |

## Referenties

- [Microsoft: OIDC SSO voor een custom application](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/add-application-portal-setup-oidc-sso)
- [Microsoft: app roles maken en toewijzen](https://learn.microsoft.com/en-us/entra/identity-platform/howto-add-app-roles-in-apps)
- [Microsoft: toegang beperken tot toegewezen gebruikers](https://learn.microsoft.com/en-us/entra/identity-platform/howto-restrict-your-app-to-a-set-of-users)
- [Microsoft: redirect URI-regels](https://learn.microsoft.com/en-us/entra/identity-platform/reply-url)
