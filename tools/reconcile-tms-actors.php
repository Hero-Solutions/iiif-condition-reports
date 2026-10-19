<?php
declare(strict_types=1);

// ONE-OFF OPERATOR TOOL. Never loaded by the application. No kernel, .env or API clients.
// See docs/tms-actor-cleanup.md. The operator runs this manually, with sync/writes paused.
require_once dirname(__DIR__) . '/src/Service/TmsActorSync.php';

/** Build and validate the entire plan before changing any rows. */
function tmsActorCleanupPlan(iterable $rows): array
{
    $groups = [];
    $fields = ['name', 'type', 'alias', 'logo', 'vat', 'address', 'postal', 'city', 'state_province',
        'country', 'email', 'website', 'phone', 'mobile', 'notes'];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $data = json_decode($row['tms_data'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
        if (($data['institution_default'] ?? false) === true) continue;
        $record = $data['record'] ?? [];
        $identity = App\Service\TmsActorSync::sourceIdentity($record);
        if ($identity === null || count($identity) !== 3) {
            throw new RuntimeException("Actor $id: bronidentiteit ontbreekt of de nieuwe importcode ontbreekt.");
        }
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $oldKey = hash('sha256', json_encode([...$identity, $record['UnMasked']], JSON_THROW_ON_ERROR));
        if (!in_array($row['tms_key'], [$key, $oldKey], true) || $row['name_scope'] !== $row['tms_key']) {
            throw new RuntimeException("Actor $id: onverwachte importsleutel; niets wijzigen zonder controle.");
        }
        // Keep comparison fields only, not a decoded copy of every full TMS source record.
        unset($row['tms_data']);
        $row['_data'] = ['record' => ['UnMasked' => $record['UnMasked']], 'mapped' => $data['mapped'] ?? null];
        $groups[$key][] = $row;
    }
    $merges = $rekeys = [];
    foreach ($groups as $key => $actors) {
        // Prefer an already converted actor, otherwise the existing UnMasked=1 variant.
        usort($actors, static fn(array $a, array $b): int =>
            [$a['tms_key'] !== $key, $a['_data']['record']['UnMasked'] !== '1', (int) $a['id']]
            <=> [$b['tms_key'] !== $key, $b['_data']['record']['UnMasked'] !== '1', (int) $b['id']]);
        $keep = array_shift($actors);
        $keepId = (int) $keep['id'];
        foreach ($actors as $drop) {
            $dropId = (int) $drop['id'];
            foreach ($fields as $field) {
                if ($keep[$field] !== $drop[$field]) {
                    throw new RuntimeException("Actoren $dropId/$keepId verschillen in $field; niets samengevoegd.");
                }
            }
            // Different import baselines could change which local edits survive the next sync.
            $keepMapped = $keep['_data']['mapped'] ?? null;
            $dropMapped = $drop['_data']['mapped'] ?? null;
            if (!is_array($keepMapped) || !is_array($dropMapped)) {
                throw new RuntimeException("Actoren $dropId/$keepId: importbaseline ontbreekt.");
            }
            ksort($keepMapped);
            ksort($dropMapped);
            if ($keepMapped !== $dropMapped) {
                throw new RuntimeException("Actoren $dropId/$keepId hebben verschillende importbaselines.");
            }
            $merges[$dropId] = $keepId;
        }
        if ($keep['tms_key'] !== $key) $rekeys[$keepId] = $key;
    }
    return ['merges' => $merges, 'rekeys' => $rekeys];
}

/** A single transaction; SQLite is supported solely for isolated fixture tests. */
function reconcileTmsActors(PDO $db, bool $apply): array
{
    $db->beginTransaction();
    try {
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $db->query('SELECT * FROM actors WHERE tms_key IS NOT NULL ORDER BY id' . $lock, PDO::FETCH_ASSOC);
        $plan = tmsActorCleanupPlan($rows);
        $contacts = $db->prepare('SELECT COUNT(*) FROM organization_contacts WHERE organization_id IN (?, ?) OR person_id IN (?, ?)');
        foreach ($plan['merges'] as $drop => $keep) {
            $contacts->execute([$drop, $keep, $drop, $keep]);
            if ((int) $contacts->fetchColumn() !== 0) {
                throw new RuntimeException("Actoren $drop/$keep hebben contactpersoonrelaties; handmatig beoordelen.");
            }
        }
        $plan['links'] = [];
        foreach (['project_actors', 'project_object_actors', 'report_actors'] as $table) {
            $count = $db->prepare("SELECT COUNT(*) FROM $table WHERE actor_id = ?");
            $update = $db->prepare("UPDATE $table SET actor_id = ? WHERE actor_id = ?");
            $plan['links'][$table] = 0;
            foreach ($plan['merges'] as $drop => $keep) {
                $count->execute([$drop]);
                $plan['links'][$table] += (int) $count->fetchColumn();
                if ($apply) $update->execute([$keep, $drop]);
            }
        }
        if ($apply) {
            $delete = $db->prepare('DELETE FROM actors WHERE id = ?');
            foreach ($plan['merges'] as $drop => $keep) $delete->execute([$drop]);
            $update = $db->prepare('UPDATE actors SET tms_key = ?, name_scope = ? WHERE id = ?');
            foreach ($plan['rekeys'] as $id => $key) $update->execute([$key, $key, $id]);
            $db->commit();
        } else {
            $db->rollBack();
        }
        return $plan;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

// Tests include only the definitions above and supply their own in-memory SQLite connection.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$options = getopt('', ['database:', 'user:', 'host:', 'apply']);
if (!isset($options['database'], $options['user'])) {
    fwrite(STDERR, "Alleen handmatig door beheerder: php tools/reconcile-tms-actors.php --database=DB --user=USER [--host=localhost] [--apply]\nZonder --apply: alleen controleren, geen wijzigingen.\n");
    exit(1);
}
$database = $options['database'];
$host = $options['host'] ?? 'localhost';
if (!is_string($database) || !preg_match('/^[a-zA-Z0-9_]+$/D', $database)
    || !is_string($host) || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $host) || !is_string($options['user'])) {
    fwrite(STDERR, "Ongeldige database, host of gebruiker.\n");
    exit(1);
}
try {
    // Read from a pipe so the password never appears in command arguments or shell history.
    if (stream_isatty(STDIN)) throw new RuntimeException('Lees het wachtwoord verborgen in de shell en stuur het via stdin; zie docs/tms-actor-cleanup.md.');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $options['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    unset($password);
    // Rollback must also cover deletes and updates of all referencing tables.
    $tables = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('actors', 'project_actors', 'project_object_actors', 'report_actors', 'organization_contacts')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($tables) !== 5 || array_filter($tables, static fn($engine) => strcasecmp($engine, 'InnoDB') !== 0)) {
        throw new RuntimeException('De vijf actortabellen moeten aanwezig zijn en InnoDB gebruiken.');
    }
    $apply = isset($options['apply']);
    $plan = reconcileTmsActors($db, $apply);
    foreach ($plan['merges'] as $drop => $keep) echo "Actor $drop -> $keep\n";
    foreach ($plan['links'] as $table => $count) echo "$table: $count koppelingen omzetten\n";
    echo count($plan['rekeys']) . " importsleutels aanpassen\n";
    echo $apply ? "Opgeslagen. Alle bestaande koppelingen behouden.\n" : "Controle voltooid. Niets gewijzigd; gebruik --apply om dit plan opnieuw te controleren en toe te passen.\n";
} catch (Throwable $error) {
    // PDO errors may contain connection details; keep diagnostics to an error code.
    fwrite(STDERR, ($error instanceof PDOException ? 'Databasefout ' . $error->getCode() : $error->getMessage()) . "\n");
    exit(1);
}
