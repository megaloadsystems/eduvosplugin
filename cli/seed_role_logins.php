<?php
// Usage (from Moodle root):
// php blocks/onlineuserview/cli/seed_role_logins.php --days=30 --staff=600 --students=3000
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrec) = cli_get_params([
    'days' => 30,
    'staff' => 600,     // number of staff login events
    'students' => 3000, // number of student login events
    'help' => false,
], ['h' => 'help']);

if (!empty($options['help'])) {
    cli_writeln("Seed role-specific login events (logstore_standard_log)\n\n".
        "Options:\n  --days=INT\n  --staff=INT  (# of staff events)\n  --students=INT  (# of student events)\n");
    exit(0);
}

$days = (int)$options['days'];
$staffevents = (int)$options['staff'];
$studentevents = (int)$options['students'];

$end = time();
$start = $end - ($days * DAYSECS);

function pick_users_by_archetypes(array $archetypes, int $limit = 1000) {
    global $DB;
    list($insql, $inparams) = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED, 'arc', true);
    $sql = "SELECT DISTINCT u.id
              FROM {user} u
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {role} r ON r.id = ra.roleid
             WHERE u.confirmed = 1 AND u.suspended = 0 AND u.deleted = 0
               AND r.archetype $insql";
    return $DB->get_fieldset_sql($sql, $inparams, 0, $limit);
}

$staffids = pick_users_by_archetypes(['manager','coursecreator','editingteacher','teacher']);
$studentids = pick_users_by_archetypes(['student']);

if (empty($staffids) && empty($studentids)) {
    cli_writeln("No users with those roles found.");
    exit(0);
}

$records = [];
$make = function($userid, $ts) {
    $r = new stdClass();
    $r->eventname      = '\\core\\event\\user_loggedin';
    $r->component      = 'core';
    $r->action         = 'loggedin';
    $r->target         = 'user';
    $r->objecttable    = 'user';
    $r->objectid       = $userid;
    $r->crud           = 'r';
    $r->edulevel       = 0;
    $r->contextid      = 1;
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

for ($i = 0; $i < $staffevents; $i++) {
    if (!$staffids) break;
    $uid = $staffids[array_rand($staffids)];
    $ts  = rand($start, $end);
    $records[] = $make($uid, $ts);
}
for ($i = 0; $i < $studentevents; $i++) {
    if (!$studentids) break;
    $uid = $studentids[array_rand($studentids)];
    $ts  = rand($start, $end);
    $records[] = $make($uid, $ts);
}

if ($records) {
    $DB->insert_records('logstore_standard_log', $records);
}
cli_writeln("Inserted ".count($records)." events: staff={$staffevents}, students={$studentevents}, days={$days}.");
