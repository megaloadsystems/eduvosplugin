<?php
// Create a batch of teacher users: teacher5 .. teacher20 by default.
// Usage (from Moodle root):
// php blocks/onlineuserview/cli/seed_create_teachers.php --start=5 --count=16 --prefix=teacher --domain=mbse.local --password='P@ssw0rd!23'

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

list($opts, $unrec) = cli_get_params([
    'start'    => 5,               // first index (teacher5)
    'count'    => 16,              // how many to create
    'prefix'   => 'teacher',       // username prefix
    'domain'   => 'mbse.local',    // email domain
    'password' => 'P@ssw0rd!23',   // initial password
    'firstname'=> 'Teacher',
    'lastname' => 'Demo',
    'help'     => false,
], ['h' => 'help']);

if (!empty($opts['help'])) {
    cli_writeln("Creates sequential teacher users.\n\nOptions:\n".
        "  --start=INT       First index (default 5)\n".
        "  --count=INT       How many to create (default 16)\n".
        "  --prefix=STR      Username prefix (default teacher)\n".
        "  --domain=STR      Email domain (default mbse.local)\n".
        "  --password=STR    Initial password (default P@ssw0rd!23)\n".
        "  --firstname=STR   Firstname base (default Teacher)\n".
        "  --lastname=STR    Lastname base (default Demo)\n");
    exit(0);
}

$start    = (int)$opts['start'];
$count    = (int)$opts['count'];
$prefix   = trim($opts['prefix']);
$domain   = trim($opts['domain']);
$password = (string)$opts['password'];
$fnbase   = (string)$opts['firstname'];
$lnbase   = (string)$opts['lastname'];

$created = 0;
for ($i = $start; $i < $start + $count; $i++) {
    $username = $prefix . $i;

    // Skip if user already exists.
    if ($DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
        cli_writeln("Skip: {$username} already exists.");
        continue;
    }

    $user = (object)[
        'auth'       => 'manual',
        'confirmed'  => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'username'   => $username,
        'password'   => $password,
        'firstname'  => $fnbase,
        'lastname'   => "{$lnbase} {$i}",
        'email'      => "{$username}@{$domain}",
        'city'       => 'Johannesburg',
        'country'    => 'ZA',
        'timecreated'=> time(),
        'timemodified'=> time(),
        'lang'       => current_language(), // use site default
    ];

    $newid = user_create_user($user, false, false); // no email, no password hash input
    $created++;
    cli_writeln("Created user {$username} (id={$newid})");
}

cli_writeln("Done. Created {$created} user(s).");
