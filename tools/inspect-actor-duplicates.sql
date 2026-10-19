-- ALLEEN DOOR DE BEHEERDER HANDMATIG UIT TE VOEREN in de appdatabase.
-- Niet uitgevoerd door Codex. Alleen inspectie: geen wijzigingen.
-- Dit script toont bron-ID's, aantallen en gelijkheidsvlaggen, geen contactgegevens.

SELECT
    a.id,
    JSON_UNQUOTE(JSON_EXTRACT(a.tms_data, '$.record.ConstituentID')) AS constituent_id,
    JSON_UNQUOTE(JSON_EXTRACT(a.tms_data, '$.record.NameID')) AS name_id,
    JSON_UNQUOTE(JSON_EXTRACT(a.tms_data, '$.record.AddressID')) AS address_id,
    JSON_UNQUOTE(JSON_EXTRACT(a.tms_data, '$.record.UnMasked')) AS unmasked,
    (SELECT COUNT(*) FROM project_actors p WHERE p.actor_id = a.id) AS project_links,
    (SELECT COUNT(*) FROM project_actors p WHERE p.actor_id = a.id AND p.tms_present = 1) AS active_tms_project_links,
    (SELECT COUNT(*) FROM project_object_actors p WHERE p.actor_id = a.id) AS object_links,
    (SELECT COUNT(*) FROM report_actors r WHERE r.actor_id = a.id) AS report_links,
    (SELECT COUNT(*) FROM organization_contacts c WHERE c.organization_id = a.id OR c.person_id = a.id) AS contact_links
FROM actors a
WHERE a.id IN (17946, 17947)
ORDER BY a.id;

-- De beheerweergave toont niet alle actorvelden. Verschillen hier moeten eerst
-- worden beoordeeld; gelijke namen alleen bewijzen niet dat samenvoegen veilig is.
SELECT
    a.id AS actor_a,
    b.id AS actor_b,
    (BINARY a.name <=> BINARY b.name) AS same_name,
    (BINARY a.type <=> BINARY b.type) AS same_type,
    (BINARY a.alias <=> BINARY b.alias) AS same_alias,
    (BINARY a.address <=> BINARY b.address)
        AND (BINARY a.postal <=> BINARY b.postal)
        AND (BINARY a.city <=> BINARY b.city)
        AND (BINARY a.state_province <=> BINARY b.state_province)
        AND (BINARY a.country <=> BINARY b.country) AS same_address,
    (BINARY a.email <=> BINARY b.email)
        AND (BINARY a.phone <=> BINARY b.phone)
        AND (BINARY a.mobile <=> BINARY b.mobile)
        AND (BINARY a.website <=> BINARY b.website) AS same_contact_fields,
    (BINARY a.logo <=> BINARY b.logo)
        AND (BINARY a.vat <=> BINARY b.vat)
        AND (BINARY a.notes <=> BINARY b.notes) AS same_other_fields
FROM actors a
JOIN actors b ON b.id = 17947
WHERE a.id = 17946;

-- Nog niets verwijderen of omkoppelen. De oude import gebruikte ook UnMasked
-- als actoridentiteit. De correctie gebruikt alleen constituent-, naam- en adres-ID.
-- Bestaande importsleutels moeten eenmalig worden omgezet voor de volgende sync.
-- Zie docs/tms-actor-cleanup.md en tools/reconcile-tms-actors.php voor de
-- handmatige correctie met behoud van alle project-, object- en rapportverwijzingen.
