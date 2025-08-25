<?php
require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('block/onlineuserview:view', $context);

$window = optional_param('window', 300, PARAM_INT); // 300, 1800, 3600
$rolefilter = optional_param('role', 'all', PARAM_ALPHA); // all|students|staff

$validwindows = [300, 1800, 3600];
if (!in_array($window, $validwindows, true)) { $window = 300; }
$validroles = ['all', 'students', 'staff'];
if (!in_array($rolefilter, $validroles, true)) { $rolefilter = 'all'; }

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/onlineuserview/view.php', ['window' => $window, 'role' => $rolefilter]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'block_onlineuserview'));
$PAGE->set_heading(get_string('heading', 'block_onlineuserview'));

require_once(__DIR__ . '/classes/local/service.php');
use block_onlineuserview\local\service;

// Summary counts.
$summaryrows = [
    300 => service::count_online_by_roles(300),
    1800 => service::count_online_by_roles(1800),
    3600 => service::count_online_by_roles(3600),
];

// Current window list.
$allonline = service::get_online_users_with_roleflags($window);

// Filter by role.
$filtered = array_values(array_filter($allonline, function($u) use ($rolefilter) {
    if ($rolefilter === 'all') { return true; }
    if ($rolefilter === 'students') { return !empty($u->is_student); }
    if ($rolefilter === 'staff') { return !empty($u->is_staff); }
    return true;
}));

echo $OUTPUT->header();

echo html_writer::tag('p', get_string('intro', 'block_onlineuserview'));

// Filter form
$opts = [
    300 => get_string('window_300', 'block_onlineuserview'),
    1800 => get_string('window_1800', 'block_onlineuserview'),
    3600 => get_string('window_3600', 'block_onlineuserview'),
];
$roles = [
    'all' => get_string('role_all', 'block_onlineuserview'),
    'students' => get_string('role_students', 'block_onlineuserview'),
    'staff' => get_string('role_staff', 'block_onlineuserview'),
];

$filterurl = new moodle_url('/blocks/onlineuserview/view.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl]);
echo html_writer::start_div('form-inline', ['style' => 'display:flex; gap:12px; align-items:center; flex-wrap:wrap;']);
echo html_writer::label(get_string('window', 'block_onlineuserview'), 'id_window');
echo html_writer::select($opts, 'window', $window, null, ['id' => 'id_window']);
echo html_writer::label(get_string('rolefilter', 'block_onlineuserview'), 'id_role');
echo html_writer::select($roles, 'role', $rolefilter, null, ['id' => 'id_role']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('applyfilters', 'block_onlineuserview')]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

// Summary table
echo html_writer::tag('h3', get_string('summary', 'block_onlineuserview'));
$sumtable = new html_table();
$sumtable->head = [
    get_string('col_window', 'block_onlineuserview'),
    get_string('col_all', 'block_onlineuserview'),
    get_string('col_students', 'block_onlineuserview'),
    get_string('col_staff', 'block_onlineuserview'),
];
foreach ([300, 1800, 3600] as $w) {
    $label = $opts[$w];
    $row = new html_table_row([
        s($label),
        (string)$summaryrows[$w]['all'],
        (string)$summaryrows[$w]['students'],
        (string)$summaryrows[$w]['staff'],
    ]);
    $sumtable->data[] = $row;
}
echo html_writer::table($sumtable);

// Detailed list
echo html_writer::tag('h3', get_string('users_online', 'block_onlineuserview'));
if (empty($filtered)) {
    echo $OUTPUT->notification(get_string('nonefound', 'block_onlineuserview'), 'info');
} else {
    $listtable = new html_table();
    $listtable->head = [
        get_string('col_name', 'block_onlineuserview'),
        get_string('col_email', 'block_onlineuserview'),
        get_string('col_when', 'block_onlineuserview'),
        get_string('col_roleclass', 'block_onlineuserview'),
    ];
    foreach ($filtered as $u) {
        $name = fullname($u);
        $email = s($u->email);
        $when = userdate($u->lastaccess) . ' (' . format_time(time() - $u->lastaccess) . ' ago)';
        $roleclass = ($u->is_student && $u->is_staff) ? 'Both' : ($u->is_staff ? 'Staff' : ($u->is_student ? 'Student' : 'N/A'));
        $listtable->data[] = new html_table_row([
            s($name),
            html_writer::link('mailto:' . $email, $email),
            s($when),
            s($roleclass),
        ]);
    }
    echo html_writer::table($listtable);
}

echo $OUTPUT->footer();
