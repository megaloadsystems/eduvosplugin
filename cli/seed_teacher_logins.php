<?php
// Seed login events for staff/teachers (supports custom teacher roles).
// Works on Moodle 4.5+
//
// Usage examples (from Moodle root):
//   # Default: last 30 days, ~2000 events across detected teacher roles
//   php blocks/onlineuserview/cli/seed_teacher_logins.php
//
//   # Specify roles by shortname and total events
//   php blocks/onlineuserview/cli/seed_teacher_logins.php --roles=editingteacher,teacher,teacher1,teacher2 --events=3000 --days=45
//
//   # Fixed events per user (e.g., 50 per teacher) over last 60 days
//   php blocks/onlineuserview/cli/seed_teacher_logins.php --peruser=50 --days=60
//
// Notes:
// - Creates \\core\\event\\user_loggedin rows in {logstore_standard_log}.
// - Biased distribution: more events on weekdays and working hours to look realistic.
// - Safe to run multiple times (it just adds more synthetic events).

define('CLI_SCRIPT', true);

/** -------- Locate and require config.php robustly -------- */
$root = realpath(__DIR__);
$found = false;
for ($i = 0; $i < 6; $i++) {
    $try = $root . str_repeat('/..', $i) . '/config.php';
    if (file_exists($try)) { require($try); $found = true; break; }
}
if (!$found || empty($CFG) || empty($CFG->dirroot)) {
    fwrite(STDERR, "ERROR: Could not locate Moodle config.php from: " . __DIR__ . "\n");
    exit(1);
}

/** -------- Include Moodle libs -------- */
require_once($CFG->libdir . '/clilib.php');   // CLI helpers

list($opts, $unrec) = cli_get_params([
    'days'    => 30,                              // time window into the past
    'events'  => 2000,                            // total events to create (ignored if peruser is set)
    'peruser' => null,                            // fixed number of events per user
    'roles'   => 'editingteacher,teacher,teacher1,teacher2,teacher3,teacher4', // role shortnames to include
    'help'    => false,
], ['h' => 'help']);

if (!empty($opts['help'])) {
    cli_writeln("Seed teacher login events into logstore_standard_log.\n\nOptions:\n".
        "  --days=INT                 Days back from now (default 30)\n".
        "  --events=INT               Total events to create (default 2000)\n".
        "  --peruser=INT              Create this many events PER matched user (overrides --events)\n".
        "  --roles=CSV                Role shortnames to include (default editingteacher,teacher,teacher1,teacher2,teacher3,teacher4)\n".
        "Examples:\n".
        "  php blocks/onlineuserview/cli/seed_teacher_logins.php\n".
        "  php blocks/onlineuserview/cli/seed_teacher_logins.php --roles=editingteacher,teacher1 --events=5000 --days=45\n".
        "  php blocks/onlineuserview/cli/seed_teacher_logins.php --peruser=40 --days=90\n");
    exit(0);
}

$days     = max(1, (int)$opts['days']);
$events   = max(1, (int)$opts['events']);
$peruser  = is_null($opts['peruser']) ? null : max(1, (int)$opts['peruser']);
$rolescsv = trim((string)$opts['roles']);
$roles    = array_filter(array_map('trim', explode(',', $rolescsv)));

$end   = time();
$start = $end - ($days * DAYSECS);

// ---- Find teacher/staff users by role shortname ----
global $DB;

if (empty($roles)) {
    cli_error("No role shortnames provided.");
}

// Ensure provided roles exist, but continue with any valid ones.
list($insql, $inparams) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'rs');
$roleids = $DB->get_fieldset_sql("SELECT id FROM {role} WHERE shortname $insql", $inparams);
if (empty($roleids)) {
    cli_error("None of the specified role shortnames exist: " . $rolescsv);
}
list($rin, $rparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
$sql = "SELECT DISTINCT u.id
          FROM {user} u
          JOIN {role_assignments} ra ON ra.userid = u.id
         WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
           AND ra.roleid $rin";
$teacherids = $DB->get_fieldset_sql($sql, $rparams);

if (empty($teacherids)) {
    cli_writeln("No users found with roles: {$rolescsv}");
    exit(0);
}

cli_writeln("Found ".count($teacherids)." teacher/staff user(s) for roles: {$rolescsv}");

// ---- Build a realistic time distribution ----
// Hour weights (0..23): higher 08–18
$hourweights = [
    0=>1, 1=>1, 2=>1, 3=>1, 4=>1, 5=>2,
    6=>4, 7=>6, 8=>10, 9=>12, 10=>12, 11=>10,
    12=>8, 13=>10, 14=>12, 15=>12, 16=>10, 17=>8,
    18=>6, 19=>4, 20=>3, 21=>2, 22=>1, 23=>1
];
// Weekday weights (0=Sun..6=Sat): Mon–Fri heavier
$weekdayweights = [0=>2, 1=>8, 2=>9, 3=>9, 4=>8, 5=>6, 6=>3];

/** Pick a random hour using weights. */
function pick_weighted(array $weights): int {
    $sum = array_sum($weights);
    $r = mt_rand(1, max(1,$sum));
    $acc = 0;
    foreach ($weights as $k=>$w) { $acc += $w; if ($r <= $acc) return (int)$k; }
    return (int)array_key_first($weights);
}

/** Generate a timestamp biased by weekday/hour weights within [start,end]. */
function random_biased_ts(int $start, int $end, array $hourweights, array $weekdayweights): int {
    // Pick a random day first
    $dayspan = max(1, (int)floor(($end - $start) / DAYSECS));
    $dayoffset = mt_rand(0, $dayspan - 1);
    $daystart = $start + ($dayoffset * DAYSECS);

    // Weighted weekday retry loop to bias towards Mon–Fri
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $h = pick_weighted($hourweights);
        $candidate = $daystart + ($h * HOURSECS) + mt_rand(0, HOURSECS-1);
        $w = (int)date('w', $candidate); // 0..6
        $prob = $weekdayweights[$w] ?? 1;
        if (mt_rand(1, 10) <= min(10, $prob)) {
            return min(max($candidate, $start), $end-1);
        }
    }
    // Fallback simple random within window
    return mt_rand($start, $end-1);
}

// ---- Build records ----
$records = [];
$chunk   = 1000;
$created = 0;

$make = function(int $userid, int $ts): stdClass {
    $r = new stdClass();
    $r->eventname      = '\\core\\event\\user_loggedin';
    $r->component      = 'core';
    $r->action         = 'loggedin';
    $r->target         = 'user';
    $r->objecttable    = 'user';
    $r->objectid       = $userid;
    $r->crud           = 'r';
    $r->edulevel       = 0;
    $r->contextid      = 1; // system context
    $r->contextlevel   = CONTEXT_SYSTEM;
    $r->contextinstanceid = 0;
    $r->userid         = $userid;
    $r->relateduserid  = null;
    $r->courseid       = 0;
    $r->timecreated    = $ts;
    $r->origin         = 'web';
    $r->ip             = '127.0.0.1';
    $r->realuserid     = null;
    return $r;
};

if ($peruser !== null) {
    foreach ($teacherids as $uid) {
        for ($i = 0; $i < $peruser; $i++) {
            $ts = random_biased_ts($start, $end, $hourweights, $weekdayweights);
            $records[] = $make((int)$uid, $ts);
            if (count($records) >= $chunk) {
                $DB->insert_records('logstore_standard_log', $records);
                $created += count($records);
                $records = [];
            }
        }
    }
} else {
    // Distribute total events roughly evenly across users
    for ($i = 0; $i < $events; $i++) {
        $uid = (int)$teacherids[array_rand($teacherids)];
        $ts  = random_biased_ts($start, $end, $hourweights, $weekdayweights);
        $records[] = $make($uid, $ts);
        if (count($records) >= $chunk) {
            $DB->insert_records('logstore_standard_log', $records);
            $created += count($records);
            $records = [];
        }
    }
}

// Flush remainder
if (!empty($records)) {
    $DB->insert_records('logstore_standard_log', $records);
    $created += count($records);
}

cli_writeln("Inserted {$created} teacher login event(s) over last {$days} day(s) for roles: {$rolescsv}.");
