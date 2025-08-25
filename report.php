<?php
require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('block/onlineuserview:view', $context);

$granularity = optional_param('granularity', 'hour', PARAM_ALPHA); // hour|day
$day        = optional_param('day', date('Y-m-d'), PARAM_RAW_TRIMMED);
$from       = optional_param('from', date('Y-m-01'), PARAM_RAW_TRIMMED); // for daily view
$to         = optional_param('to', date('Y-m-d'), PARAM_RAW_TRIMMED);
$download   = optional_param('download', 0, PARAM_BOOL); // CSV
$role       = optional_param('role', 'all', PARAM_ALPHA); // all|students|staff
if (!in_array($role, ['all','students','staff'], true)) { $role = 'all'; }

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/onlineuserview/report.php', [
    'granularity' => $granularity, 'day' => $day, 'from' => $from, 'to' => $to, 'role' => $role
]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'block_onlineuserview'));
$PAGE->set_heading(get_string('heading', 'block_onlineuserview'));

require_once(__DIR__ . '/classes/local/service.php');
use block_onlineuserview\local\service;

// ---- Role filter helper ----------------------------------------------------
/** Returns SQL snippet (prefixed with a leading space) and augments $params. */
function ousv_role_filter_sql(string $role, array &$params, string $logalias = 'l'): string {
    if ($role === 'students') {
        $params['rf_student'] = 'student';
        return " AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = {$logalias}.userid AND r.archetype = :rf_student
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
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = {$logalias}.userid AND r.archetype IN (:rf_staff0, :rf_staff1, :rf_staff2, :rf_staff3)
                )";
    }
    return '';
}

/** Colour shading used in table cells (Green → Yellow → Red). */
function ousv_cell_style_shaded($v, $max) {
    if ($max <= 0 || $v <= 0) {
        return 'background:#f6f6f6;color:#000;padding:4px;border-radius:4px;text-align:center;';
    }
    $p = min(1, $v / $max);
    $low  = [232,245,233];  // #e8f5e9
    $mid  = [255,245,157];  // #fff59d
    $high = [211, 47, 47];  // #d32f2f

    if ($p < 0.5) {
        $t = $p / 0.5;
        $r = (int)round($low[0] + ($mid[0]-$low[0]) * $t);
        $g = (int)round($low[1] + ($mid[1]-$low[1]) * $t);
        $b = (int)round($low[2] + ($mid[2]-$low[2]) * $t);
    } else {
        $t = ($p - 0.5) / 0.5;
        $r = (int)round($mid[0] + ($high[0]-$mid[0]) * $t);
        $g = (int)round($mid[1] + ($high[1]-$mid[1]) * $t);
        $b = (int)round($mid[2] + ($high[2]-$mid[2]) * $t);
    }
    $luma = 0.2126*$r + 0.7152*$g + 0.0722*$b;
    $fg = ($luma < 140) ? '#fff' : '#000';
    return "background:rgb({$r},{$g},{$b});color:{$fg};padding:4px;border-radius:4px;text-align:center;";
}

/**
 * Count distinct users per bucket (hour/day) with role filtering.
 * Returns an ordered label=>count array, zero-filled.
 */
function ousv_count_distinct_users_in_buckets($startts, $endts, $bucket = 'hour', $role = 'all') {
    global $DB;

    // Pre-seed buckets for nice zero-filled series.
    $series = [];
    $iter = ($bucket === 'day') ? DAYSECS : HOURSECS;
    for ($t = $startts; $t < $endts; $t += $iter) {
        $key = ($bucket === 'day') ? date('Y-m-d', $t) : date('H:00', $t);
        $series[$key] = 0;
    }

    $params = [
        'startts' => $startts,
        'endts'   => $endts,
    ];
    $rolewhere = ousv_role_filter_sql($role, $params, 'l');

    if ($bucket === 'day') {
        $params['step1'] = DAYSECS;
        $params['step2'] = DAYSECS;
        $sql = "
            SELECT
              FROM_UNIXTIME(FLOOR(l.timecreated / :step1) * :step2, '%Y-%m-%d') AS bucketlabel,
              COUNT(DISTINCT l.userid) AS users
            FROM {logstore_standard_log} l
            WHERE l.timecreated >= :startts AND l.timecreated < :endts
              AND l.userid > 0
              $rolewhere
            GROUP BY bucketlabel
        ";
    } else { // hour
        $params['step1'] = HOURSECS;
        $params['step2'] = HOURSECS;
        $sql = "
            SELECT
              FROM_UNIXTIME(FLOOR(l.timecreated / :step1) * :step2, '%H:00') AS bucketlabel,
              COUNT(DISTINCT l.userid) AS users
            FROM {logstore_standard_log} l
            WHERE l.timecreated >= :startts AND l.timecreated < :endts
              AND l.userid > 0
              $rolewhere
            GROUP BY bucketlabel
        ";
    }

    $rows = $DB->get_records_sql($sql, $params);
    foreach ($rows as $r) {
        if (isset($series[$r->bucketlabel])) {
            $series[$r->bucketlabel] = (int)$r->users;
        }
    }
    return $series;
}

/** Heatmap: last 6 weeks by day-of-week × hour with role filter. */
function ousv_heatmap_matrix($weeks = 6, $role = 'all') {
    global $DB;
    $endts = time();
    $startts = $endts - ($weeks * 7 * DAYSECS);

    $params = [
        'h1' => HOURSECS, 'h2' => HOURSECS,
        'startts' => $startts, 'endts' => $endts
    ];
    $rolewhere = ousv_role_filter_sql($role, $params, 'l');

    $sql = "
      SELECT FLOOR(l.timecreated / :h1) * :h2 AS bucketts, COUNT(DISTINCT l.userid) AS users
      FROM {logstore_standard_log} l
      WHERE l.timecreated >= :startts AND l.timecreated < :endts
        AND l.userid > 0
        $rolewhere
      GROUP BY bucketts
    ";
    $data = $DB->get_records_sql($sql, $params);

    $grid = [];
    for ($d = 0; $d < 7; $d++) { $grid[$d] = array_fill(0, 24, 0); }

    foreach ($data as $r) {
        $ts  = (int)$r->bucketts;
        $dow = (int)userdate($ts, '%w'); // 0..6
        $hh  = (int)userdate($ts, '%H'); // 0..23
        $grid[$dow][$hh] += (int)$r->users;
    }
    return $grid; // [dow][hour] => count
}

$renderer = $PAGE->get_renderer('core');

/* ---------- Hourly view (single day) ---------- */
if ($granularity === 'hour') {
    $start = strtotime($day . ' 00:00:00');
    $end   = $start + DAYSECS;
    $series = ousv_count_distinct_users_in_buckets($start, $end, 'hour', $role);
    $tabtitle = get_string('summary', 'block_onlineuserview') . ' — ' . userdate($start, '%Y-%m-%d');

    if ($download) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="onlineuserview-hours-' . date('Ymd', $start) . ".csv\"");
        echo "Period,Users Online\n";
        foreach ($series as $label => $count) {
            echo $label . "," . $count . "\n";
        }
        exit;
    }

    echo $OUTPUT->header();

    // Filter widget for role (keeps day)
    $formurl = new moodle_url('/blocks/onlineuserview/report.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl,
        'style' => 'margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'granularity', 'value' => 'hour']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'day', 'value' => $day]);
    $roleopts = [
        'all'      => get_string('role_all', 'block_onlineuserview'),
        'students' => get_string('role_students', 'block_onlineuserview'),
        'staff'    => get_string('role_staff', 'block_onlineuserview'),
    ];
    echo html_writer::label(get_string('rolefilter', 'block_onlineuserview'), 'ousv_role');
    echo html_writer::select($roleopts, 'role', $role, false, ['id' => 'ousv_role']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('applyfilters', 'block_onlineuserview')]);
    echo html_writer::end_tag('form');

    echo html_writer::tag('h3', $tabtitle);

    // ---- NEW: Quick links to actual user list (fullname + email) ----
    $userlist_links = [];
    $userlist_labels = [
        5  => get_string('m5',  'block_onlineuserview'),
        30 => get_string('m30', 'block_onlineuserview'),
        60 => get_string('h1',  'block_onlineuserview'),
    ];
    foreach ($userlist_labels as $mins => $label) {
        $userlisturl = new moodle_url('/blocks/onlineuserview/users.php', [
            'window' => $mins,
            'role'   => $role,
        ]);
        $userlist_links[] = html_writer::link($userlisturl, $label);
    }
    echo html_writer::div(
        get_string('usersbyemail', 'block_onlineuserview') . ': ' . implode(' | ', $userlist_links),
        '', ['style' => 'margin-bottom:10px;']
    );

    // Shaded table
// Shaded, clickable table (each count links to users list for that hour)
$t = new html_table();
$t->head = ['Date / Time Period', 'Users Online'];
$maxval = max($series);
foreach ($series as $label => $count) {
    // $label like "HH:00"
    $hour = (int)substr($label, 0, 2);
    $fromts = $start + ($hour * HOURSECS);
    $endts  = $fromts + HOURSECS;

    $style = ousv_cell_style_shaded($count, $maxval);
    $link  = new moodle_url('/blocks/onlineuserview/users.php', [
        'role' => $role, 'fromts' => $fromts, 'endts' => $endts
    ]);
    $counthtml = html_writer::div($count, '', ['style' => $style]);
    $clickable = html_writer::link($link, $counthtml);

    $t->data[] = new html_table_row([s($label), $clickable]);
}
echo html_writer::table($t);


    // Charts
    $line = new core\chart_line();
    $line->set_labels(array_keys($series));
    $line->add_series(new core\chart_series(get_string('users_online', 'block_onlineuserview'), array_values($series)));
    echo $renderer->render($line);

    $bar = new core\chart_bar();
    $bar->set_labels(array_keys($series));
    $bar->add_series(new core\chart_series(get_string('users_online', 'block_onlineuserview'), array_values($series)));
    echo $renderer->render($bar);

    echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['download' => 1]),
        get_string('downloadcsv', 'block_onlineuserview'));

    echo '<style>.generaltable td div{min-width:50px}</style>';
    echo $OUTPUT->footer();

/* ---------- Daily totals view (date range) ---------- */
} else {
    $start = strtotime($from . ' 00:00:00');
    $end   = strtotime($to   . ' 23:59:59') + 1; // exclusive
    if ($end <= $start) { $end = $start + DAYSECS; }
    $series = ousv_count_distinct_users_in_buckets($start, $end, 'day', $role);
    $tabtitle = get_string('summary', 'block_onlineuserview') . ' — ' . userdate($start, '%Y-%m-%d') . ' → ' . userdate($end - 1, '%Y-%m-%d');

    if ($download) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="onlineuserview-days-' . date('Ymd', $start) . '-' . date('Ymd', $end-1) . ".csv\"");
        echo "Date,Users Online\n";
        foreach ($series as $label => $count) {
            echo $label . "," . $count . "\n";
        }
        exit;
    }

    echo $OUTPUT->header();

    // Filter widget for role + range
    $formurl = new moodle_url('/blocks/onlineuserview/report.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl,
        'style' => 'margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'granularity', 'value' => 'day']);
    echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'from', 'value' => $from, 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'to',   'value' => $to,   'class' => 'form-control']);
    $roleopts = [
        'all'      => get_string('role_all', 'block_onlineuserview'),
        'students' => get_string('role_students', 'block_onlineuserview'),
        'staff'    => get_string('role_staff', 'block_onlineuserview'),
    ];
    echo html_writer::label(get_string('rolefilter', 'block_onlineuserview'), 'ousv_role');
    echo html_writer::select($roleopts, 'role', $role, false, ['id' => 'ousv_role']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('applyfilters', 'block_onlineuserview')]);
    echo html_writer::end_tag('form');

    echo html_writer::tag('h3', $tabtitle);

    // ---- NEW: Quick links to actual user list (fullname + email) ----
    $userlist_links = [];
    $userlist_labels = [
        5  => get_string('m5',  'block_onlineuserview'),
        30 => get_string('m30', 'block_onlineuserview'),
        60 => get_string('h1',  'block_onlineuserview'),
    ];
    foreach ($userlist_labels as $mins => $label) {
        $userlisturl = new moodle_url('/blocks/onlineuserview/users.php', [
            'window' => $mins,
            'role'   => $role,
        ]);
        $userlist_links[] = html_writer::link($userlisturl, $label);
    }
    echo html_writer::div(
        get_string('usersbyemail', 'block_onlineuserview') . ': ' . implode(' | ', $userlist_links),
        '', ['style' => 'margin-bottom:10px;']
    );

    // Shaded table
// Shaded, clickable table (each day links to users list for that day)
$t = new html_table();
$t->head = ['Date', 'Users Online'];
$maxval = max($series);
foreach ($series as $label => $count) {
    // $label like "YYYY-MM-DD"
    $fromts = strtotime($label . ' 00:00:00');
    $endts  = $fromts + DAYSECS;

    $style = ousv_cell_style_shaded($count, $maxval);
    $link  = new moodle_url('/blocks/onlineuserview/users.php', [
        'role' => $role, 'fromts' => $fromts, 'endts' => $endts
    ]);
    $counthtml = html_writer::div($count, '', ['style' => $style]);
    $clickable = html_writer::link($link, $counthtml);

    $t->data[] = new html_table_row([s($label), $clickable]);
}
echo html_writer::table($t);


    // Charts
    $line = new core\chart_line();
    $line->set_labels(array_keys($series));
    $line->add_series(new core\chart_series(get_string('users_online', 'block_onlineuserview'), array_values($series)));
    echo $renderer->render($line);

    $bar = new core\chart_bar();
    $bar->set_labels(array_keys($series));
    $bar->add_series(new core\chart_series(get_string('users_online', 'block_onlineuserview'), array_values($series)));
    echo $renderer->render($bar);

    // Weekly heatmap (last 6 weeks) with role filter.
    echo html_writer::tag('h3', get_string('heatmaptitle', 'block_onlineuserview'));
    $grid = ousv_heatmap_matrix(6, $role);
    $dowlabels = [get_string('sunday'), get_string('monday'), get_string('tuesday'), get_string('wednesday'), get_string('thursday'), get_string('friday'), get_string('saturday')];

    // Find max for scaling.
    $maxweek = 0;
    for ($d = 0; $d < 7; $d++) { for ($h = 0; $h < 24; $h++) { $maxweek = max($maxweek, (int)$grid[$d][$h]); } }

    $heat = new html_table();
    $heat->head = array_merge([''], array_map(function($h){ return sprintf('%02d:00', $h); }, range(0,23)));
    for ($d = 0; $d < 7; $d++) {
        $row = [s($dowlabels[$d])];
        for ($h = 0; $h < 24; $h++) {
            $val = (int)$grid[$d][$h];
            $style = ousv_cell_style_shaded($val, $maxweek);
            $row[] = html_writer::div($val, 'ousv-cell', ['style' => $style]);
        }
        $heat->data[] = new html_table_row($row);
    }
    echo html_writer::table($heat);

    echo '<style>.generaltable td div{min-width:50px}</style>';
    echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['download' => 1]),
        get_string('downloadcsv', 'block_onlineuserview'));

    echo $OUTPUT->footer();
}
