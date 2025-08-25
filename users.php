<?php
require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('block/onlineuserview:view', $context);

// Inputs:
// A) Exact range via UNIX timestamps: fromts (inclusive), endts (exclusive)
// B) OR a window in minutes: window (if fromts/endts not supplied)
$window = optional_param('window', 30, PARAM_INT);          // minutes (fallback)
$role   = optional_param('role', 'all', PARAM_ALPHA);       // all|students|staff
$fromts = optional_param('fromts', 0, PARAM_INT);
$endts  = optional_param('endts', 0, PARAM_INT);

if (!in_array($role, ['all','students','staff'], true)) { $role = 'all'; }
if ($fromts > 0 && $endts > 0 && $endts <= $fromts) { $endts = $fromts + 1; }
if ($fromts <= 0 || $endts <= 0) {
    $endts = time();
    $fromts = $endts - max(1, (int)$window) * 60;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/onlineuserview/users.php', [
    'role' => $role, 'fromts' => $fromts, 'endts' => $endts
]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'block_onlineuserview'));
$PAGE->set_heading(get_string('heading', 'block_onlineuserview'));

// ---- Role filter helpers ----------------------------------------------------
/**
 * WHERE fragment to restrict users by role archetype.
 * Works against outer alias "u".
 */
function ousv_role_filter_sql_users(string $role, array &$params): string {
    if ($role === 'students') {
        $params['rf_student'] = 'student';
        return " AND EXISTS (
                    SELECT 1 FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                   WHERE ra.userid = u.id AND r.archetype = :rf_student
               )";
    }
    if ($role === 'staff') {
        $params += [
            'rf_staff0' => 'manager',
            'rf_staff1' => 'coursecreator',
            'rf_staff2' => 'editingteacher',
            'rf_staff3' => 'teacher',
        ];
        return " AND EXISTS (
                    SELECT 1 FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                   WHERE ra.userid = u.id AND r.archetype IN (:rf_staff0, :rf_staff1, :rf_staff2, :rf_staff3)
               )";
    }
    return '';
}

/** Map flags -> role label. Staff outranks Student if user has both. */
function ousv_role_label_from_flags(int $isstaff, int $isstudent): string {
    if ($isstaff > 0) { return get_string('role_staff', 'block_onlineuserview'); }
    if ($isstudent > 0) { return get_string('role_students', 'block_onlineuserview'); }
    return get_string('col_all', 'block_onlineuserview'); // fallback "All users"/Other
}

global $DB;

// Build query for users who produced any event within the range.
// Also compute 2 flags: has student archetype? has staff archetype?
$params = ['fromts' => $fromts, 'endts' => $endts];
$rolewhere = ousv_role_filter_sql_users($role, $params);

$sql = "
    SELECT
        u.id,
        u.firstname,
        u.lastname,
        u.email,
        MAX(l.timecreated) AS lastseen,
        -- student role count
        (SELECT COUNT(1)
           FROM {role_assignments} ra_s
           JOIN {role} r_s ON r_s.id = ra_s.roleid
          WHERE ra_s.userid = u.id AND r_s.archetype = 'student') AS hasstudent,
        -- staff role count (manager, coursecreator, editingteacher, teacher)
        (SELECT COUNT(1)
           FROM {role_assignments} ra_t
           JOIN {role} r_t ON r_t.id = ra_t.roleid
          WHERE ra_t.userid = u.id AND r_t.archetype IN ('manager','coursecreator','editingteacher','teacher')) AS hasstaff
      FROM {user} u
      JOIN {logstore_standard_log} l
        ON l.userid = u.id
     WHERE u.deleted   = 0
       AND u.suspended = 0
       AND u.confirmed = 1
       AND l.timecreated >= :fromts
       AND l.timecreated <  :endts
       $rolewhere
  GROUP BY u.id, u.firstname, u.lastname, u.email
  ORDER BY lastseen DESC, u.lastname ASC, u.firstname ASC
";
$users = $DB->get_records_sql($sql, $params);

// Quick summary counts for 5/30/60 anchored at endts (handy shortcuts).
function ousv_quick_counts_anchor(int $endts, string $role): array {
    global $DB;
    $mins = [5,30,60];
    $out = [];
    foreach ($mins as $m) {
        $from = $endts - ($m * 60);
        $p = ['from' => $from, 'to' => $endts];
        if ($role === 'students') {
            $p['rf_student'] = 'student';
            $rw = " AND EXISTS (SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                                 WHERE ra.userid = u.id AND r.archetype = :rf_student)";
        } elseif ($role === 'staff') {
            $p += ['rf0'=>'manager','rf1'=>'coursecreator','rf2'=>'editingteacher','rf3'=>'teacher'];
            $rw = " AND EXISTS (SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                                 WHERE ra.userid = u.id AND r.archetype IN (:rf0,:rf1,:rf2,:rf3))";
        } else {
            $rw = '';
        }
        $countsql = "SELECT COUNT(DISTINCT u.id)
                       FROM {user} u
                       JOIN {logstore_standard_log} l ON l.userid = u.id
                      WHERE u.deleted=0 AND u.suspended=0 AND u.confirmed=1
                        AND l.timecreated>=:from AND l.timecreated<:to
                        $rw";
        $out[$m] = (int)$DB->get_field_sql($countsql, $p);
    }
    return $out;
}
$counts = ousv_quick_counts_anchor($endts, $role);

// Heading pieces
$rolelabel = [
    'all'      => get_string('role_all', 'block_onlineuserview'),
    'students' => get_string('role_students', 'block_onlineuserview'),
    'staff'    => get_string('role_staff', 'block_onlineuserview'),
][$role];

$heading = get_string('usersbyemail', 'block_onlineuserview') . ' — ' .
           $rolelabel . ' — ' .
           userdate($fromts, '%Y-%m-%d %H:%M') . ' → ' . userdate($endts-1, '%Y-%m-%d %H:%M');

echo $OUTPUT->header();
echo html_writer::tag('h3', $heading);

// Filters (role selector + quick window presets that keep the same endts)
$formurl = new moodle_url('/blocks/onlineuserview/users.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl,
    'style' => 'margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;']);

// Role dropdown
$roleopts = [
    'all'      => get_string('role_all', 'block_onlineuserview'),
    'students' => get_string('role_students', 'block_onlineuserview'),
    'staff'    => get_string('role_staff', 'block_onlineuserview'),
];
echo html_writer::label(get_string('rolefilter', 'block_onlineuserview'), 'ousv_role');
echo html_writer::select($roleopts, 'role', $role, false, ['id'=>'ousv_role']);

// Window presets (5/30/60) anchored to current endts
foreach ([5,30,60] as $m) {
    $preseturl = new moodle_url('/blocks/onlineuserview/users.php', [
        'role' => $role, 'fromts' => $endts - $m*60, 'endts' => $endts
    ]);
    $label = ($m===60) ? get_string('h1','block_onlineuserview')
            : (($m===30) ? get_string('m30','block_onlineuserview') : get_string('m5','block_onlineuserview'));
    echo html_writer::tag('span', html_writer::link($preseturl, $label), ['style'=>'margin-right:8px;']);
}

// Hidden range persistence
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fromts', 'value' => $fromts]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'endts',  'value' => $endts]);

// Submit
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('applyfilters', 'block_onlineuserview')
]);
echo html_writer::end_tag('form');

// Quick counts row (5/30/60) for the same role/end
$quickbits = [];
foreach ([5,30,60] as $m) {
    $url = new moodle_url('/blocks/onlineuserview/users.php', [
        'role' => $role, 'fromts' => $endts - $m*60, 'endts' => $endts
    ]);
    $label = ($m===60) ? get_string('h1','block_onlineuserview')
            : (($m===30) ? get_string('m30','block_onlineuserview') : get_string('m5','block_onlineuserview'));
    $quickbits[] = html_writer::link($url, $label) . ': ' . $counts[$m];
}
echo html_writer::div(implode(' | ', $quickbits), '', ['style'=>'margin-bottom:10px;']);

// Users table (with Role column)
$t = new html_table();
$t->head = [get_string('fullname'), get_string('email'), get_string('role'), get_string('lastactivity', 'block_onlineuserview')];

if ($users) {
    foreach ($users as $u) {
        $rolecol = ousv_role_label_from_flags((int)$u->hasstaff, (int)$u->hasstudent);
        $t->data[] = new html_table_row([
            fullname($u),
            s($u->email),
            $rolecol,
            $u->lastseen ? userdate($u->lastseen, '%Y-%m-%d %H:%M') : '-'
        ]);
    }
} else {
    $t->data[] = new html_table_row([get_string('nousersfound', 'block_onlineuserview'), '', '', '']);
}
echo html_writer::table($t);

echo $OUTPUT->footer();
