<?php
// Usage (from Moodle root):
//   php blocks/onlineuserview/cli/seed_logins.php --days=14 --users=50 --events=500

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Params
list($options, $unrecognized) = cli_get_params([
    'days' => 14,      // how many past days
    'users' => 50,     // how many distinct users to simulate
    'events' => 500,   // how many login events to create
    'help' => false,
], ['h' => 'help']);

if (!empty($options['help'])) {
    $help = "Seed dummy login events into logstore_standard_log.\n\n"
          . "Options:\n"
          . "  --days=INT     How many past days to spread events (default 14)\n"
          . "  --users=INT    Max user IDs to pick from (default 50)\n"
          . "  --events=INT   How many total events to insert (default 500)\n";
    cli_writeln($help);
    exit(0);
}

$days   = (int)$options['days'];
$users  = (int)$options['users'];
$events = (int)$options['events'];

$end   = time();
$start = $end - ($days * DAYSECS);

$records = [];
for ($i = 0; $i < $events; $i++) {
    $user = rand(2, $users); // skip guest (id=1)
    $ts   = rand($start, $end);

    $r = new stdClass();
    $r->eventname      = '\\core\\event\\user_loggedin';
    $r->component      = 'core';
    $r->action         = 'loggedin';
    $r->target         = 'user';
    $r->objecttable    = 'user';
    $r->objectid       = $user;
    $r->crud           = 'r';
    $r->edulevel       = 0;
    $r->contextid      = 1; // system context
    $r->contextlevel   = CONTEXT_SYSTEM;
    $r->contextinstanceid = 0;
    $r->userid         = $user;
    $r->relateduserid  = null;
    $r->courseid       = 0;
    $r->timecreated    = $ts;
    $r->origin         = 'web';
    $r->ip             = '127.0.0.1';
    $r->realuserid     = null;

    $records[] = $r;
}

// Bulk insert
$DB->insert_records('logstore_standard_log', $records);

cli_writeln("Inserted {$events} dummy login events spanning {$days} days across {$users} users.");
