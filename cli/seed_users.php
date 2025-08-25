<?php
// Usage (from Moodle root):
//   php blocks/onlineuserview/cli/seed_users.php --students=20 --staff=3 --domain=example.com --course=MBSE101

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

list($options, $unrecognized) = cli_get_params([
    'students' => 20,
    'staff' => 3,
    'domain' => 'example.com',
    'course' => 'MBSE101',
    'password' => 'P@ssw0rd!23',
    'help' => false,
], [
    'h' => 'help',
]);

if (!empty($options['help'])) {
    $help = "Seed demo users and a course.\n\n" .
        "Options:\n" .
        "  --students=INT   Number of students to create (default 20)\n" .
        "  --staff=INT      Number of staff to create (default 3)\n" .
        "  --domain=STR     Email/username domain (default example.com)\n" .
        "  --course=STR     Course shortname to create/use (default MBSE101)\n" .
        "  --password=STR   Password for all users (default P@ssw0rd!23)\n";
    cli_writeln($help);
    exit(0);
}

$students = max(0, (int)$options['students']);
$staff = max(0, (int)$options['staff']);
$domain = preg_replace('/[^a-zA-Z0-9\.-]/', '', $options['domain']);
$cshort = trim($options['course']);
$password = (string)$options['password'];

// Ensure course exists.
$course = $DB->get_record('course', ['shortname' => $cshort]);
if (!$course) {
    $catid = $DB->get_field('course_categories', 'id', ['idnumber' => 'SEED'], IGNORE_MISSING);
    if (!$catid) {
        $cat = new stdClass();
        $cat->name = 'Seed Data';
        $cat->idnumber = 'SEED';
        $cat->parent = 0;
        $catid = $DB->insert_record('course_categories', $cat);
        $DB->execute("UPDATE {course_categories} SET path = ?, depth = 1 WHERE id = ?", ['/' . $catid, $catid]);
    }
    $c = new stdClass();
    $c->fullname = 'MBSE Demo Course';
    $c->shortname = $cshort;
    $c->category = $catid;
    $c->summary = 'Demo course created by block_onlineuserview seeding script.';
    $course = create_course($c);
    cli_writeln("Created course: {$course->fullname} ({$course->shortname})");
} else {
    cli_writeln("Using existing course: {$course->fullname} ({$course->shortname})");
}

$coursectx = context_course::instance($course->id);

// manual enrol instance
$enrol = enrol_get_plugin('manual');
$instances = enrol_get_instances($course->id, true);
$manual = null;
foreach ($instances as $instance) {
    if ($instance->enrol === 'manual') { $manual = $instance; break; }
}
if (!$manual) {
    $enrolid = $enrol->add_default_instance($course);
    $manual = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
}

// helper
function upsert_user(string $username, string $email, string $firstname, string $lastname, string $password) {
    global $DB;
    if ($u = $DB->get_record('user', ['username' => $username])) {
        return $u;
    }
    $user = new stdClass();
    $user->username = $username;
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    $user->auth = 'manual';
    $user->confirmed = 1;
    $user->password = $password;
    return user_create_user($user, false, false);
}

$created = [];

// staff => editingteacher
$role_editingteacher = $DB->get_field('role', 'id', ['archetype' => 'editingteacher'], MUST_EXIST);
for ($i = 1; $i <= $staff; $i++) {
    $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $username = "staff{$num}";
    $email = $username . '@' . $domain;
    $u = upsert_user($username, $email, 'Staff', $num, $password);
    $enrol->enrol_user($manual, $u->id, $role_editingteacher);
    role_assign($role_editingteacher, $u->id, $coursectx);
    $created[] = ['username' => $username, 'email' => $email, 'role' => 'editingteacher'];
}

// students
$role_student = $DB->get_field('role', 'id', ['archetype' => 'student'], MUST_EXIST);
for ($i = 1; $i <= $students; $i++) {
    $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $username = "student{$num}";
    $email = $username . '@' . $domain;
    $u = upsert_user($username, $email, 'Student', $num, $password);
    $enrol->enrol_user($manual, $u->id, $role_student);
    role_assign($role_student, $u->id, $coursectx);
    $created[] = ['username' => $username, 'email' => $email, 'role' => 'student'];
}

cli_writeln("\nCreated/updated users (password: {$password})\n-----------------------------------------------");
foreach ($created as $row) {
    cli_writeln(str_pad($row['username'], 12) . "  " . str_pad($row['email'], 30) . "  " . $row['role']);
}
cli_writeln("\nDone.");
