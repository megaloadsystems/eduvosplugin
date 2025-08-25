<?php
// Enrol a batch of teacher users (e.g., teacher5..teacher20) into multiple random courses.
// Works on Moodle 4.5+ (uses lib/enrollib.php).
//
// Usage (from Moodle root):
//   php blocks/onlineuserview/cli/seed_enrol_teachers.php \
//       --start=5 --count=16 --prefix=teacher --role=editingteacher \
//       --mincourses=2 --maxcourses=4
//
// Or fixed courses per teacher:
//   php blocks/onlineuserview/cli/seed_enrol_teachers.php --start=5 --count=16 --prefix=teacher --courses=3

define('CLI_SCRIPT', true);

/** -------- Locate and require config.php robustly -------- */
$root = realpath(__DIR__);
$found = false;
for ($i = 0; $i < 6; $i++) { // walk up a few levels just in case
    $try = $root . str_repeat('/..', $i) . '/config.php';
    if (file_exists($try)) {
        require($try);
        $found = true;
        break;
    }
}
if (!$found || empty($CFG) || empty($CFG->dirroot)) {
    fwrite(STDERR, "ERROR: Could not locate Moodle config.php from: " . __DIR__ . "\n");
    exit(1);
}

/** -------- Include Moodle libs (Moodle 4.5) -------- */
require_once($CFG->libdir . '/clilib.php');        // CLI helpers
require_once($CFG->libdir . '/enrollib.php');      // core enrol API (Moodle 4.5)
require_once($CFG->dirroot . '/enrol/manual/lib.php'); // manual enrol plugin

list($opts, $unrec) = cli_get_params([
    'start'      => 5,                // first user index e.g. teacher5
    'count'      => 16,               // how many sequential users
    'prefix'     => 'teacher',        // username prefix
    'role'       => 'editingteacher', // editingteacher|teacher
    'courses'    => null,             // fixed courses per user (overrides min/max)
    'mincourses' => 2,                // min random courses per user
    'maxcourses' => 4,                // max random courses per user
    'help'       => false,
], ['h' => 'help']);

if (!empty($opts['help'])) {
    cli_writeln("Multi-course teacher enrol seeder (Moodle 4.5)\n\nOptions:\n".
        "  --start=INT        First user index (default 5)\n".
        "  --count=INT        How many users (default 16)\n".
        "  --prefix=STR       Username prefix (default teacher)\n".
        "  --role=STR         Role archetype: editingteacher|teacher (default editingteacher)\n".
        "  --courses=INT      EXACT number of courses per user (overrides min/max)\n".
        "  --mincourses=INT   Min courses per user (default 2)\n".
        "  --maxcourses=INT   Max courses per user (default 4)\n");
    exit(0);
}

$start       = (int)$opts['start'];
$count       = (int)$opts['count'];
$prefix      = trim($opts['prefix']);
$archetype   = ($opts['role'] === 'teacher') ? 'teacher' : 'editingteacher';
$courses_fix = is_null($opts['courses']) ? null : (int)$opts['courses'];
$mincourses  = (int)$opts['mincourses'];
$maxcourses  = (int)$opts['maxcourses'];

if (!is_null($courses_fix) && $courses_fix < 1) {
    cli_error("--courses must be >= 1");
}
if ($mincourses < 1 || $maxcourses < 1 || $mincourses > $maxcourses) {
    cli_error("Invalid min/max courses (ensure 1 <= min <= max).");
}

// Find role id by archetype.
$role = $DB->get_record('role', ['archetype' => $archetype], '*', IGNORE_MISSING);
if (!$role) {
    cli_error("Role with archetype '{$archetype}' not found.");
}
$roleid = (int)$role->id;

// Candidate courses (exclude front page id=1).
$courses = $DB->get_records_select_menu('course', 'id <> 1', [], 'id ASC', 'id,shortname');
if (!$courses) {
    cli_error('No courses found (besides front page). Create at least one course.');
}
$courseids = array_keys($courses);

// Ensure manual enrol plugin available.
$enrol = enrol_get_plugin('manual');
if (!$enrol) {
    cli_error('Manual enrol plugin not available.');
}

// Helper: already enrolled?
function user_already_enrolled_in_course(int $userid, int $courseid): bool {
    global $DB;
    $sql = "SELECT ue.id
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE ue.userid = :uid AND e.courseid = :cid";
    return $DB->record_exists_sql($sql, ['uid' => $userid, 'cid' => $courseid]);
}

// Helper: get/create a manual enrol instance on a course.
function get_or_create_manual_instance($courseid) {
    $instances = enrol_get_instances($courseid, true);
    foreach ($instances as $inst) {
        if ($inst->enrol === 'manual') {
            return $inst;
        }
    }
    // Create default manual instance if missing.
    $plugin = enrol_get_plugin('manual');
    if ($plugin) {
        $plugin->add_default_instance(get_course($courseid));
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') {
                return $inst;
            }
        }
    }
    return null;
}

// Helper: pick N unique random course ids.
function pick_random_courses(array $pool, int $n): array {
    if ($n >= count($pool)) { return $pool; }
    $keys = array_rand($pool, $n);
    if (!is_array($keys)) { $keys = [$keys]; }
    $picked = [];
    foreach ($keys as $k) { $picked[] = $pool[$k]; }
    return $picked;
}

$enrolled_pairs = 0;
for ($i = $start; $i < $start + $count; $i++) {
    $username = $prefix . $i;
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', IGNORE_MISSING);
    if (!$user) {
        cli_writeln("Skip: user {$username} not found.");
        continue;
    }

    // Decide #courses for this user.
    $target = $courses_fix ?? rand($mincourses, $maxcourses);
    if ($target < 1) { $target = 1; }

    $pool = $courseids;
    shuffle($pool);
    $chosen = pick_random_courses($pool, $target);

    foreach ($chosen as $courseid) {
        if (user_already_enrolled_in_course((int)$user->id, $courseid)) {
            continue; // avoid duplicate enrolments
        }
        $instance = get_or_create_manual_instance($courseid);
        if (!$instance) {
            cli_writeln("Skip: no manual enrol instance for course id {$courseid}");
            continue;
        }
        // Enrol with requested role.
        $enrol->enrol_user($instance, (int)$user->id, $roleid, time());
        $enrolled_pairs++;
        cli_writeln("Enrolled {$username} (id={$user->id}) as {$archetype} in course {$courseid}.");
    }
}

cli_writeln("Done. Created {$enrolled_pairs} enrolments for users {$prefix}{$start}..{$prefix}".($start+$count-1).".");
