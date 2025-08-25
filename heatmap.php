<?php
require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('block/onlineuserview:view', $context);

// Params
$month = optional_param('month', date('Y-m'), PARAM_RAW_TRIMMED); // e.g. 2025-08
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$role = optional_param('role', 'all', PARAM_ALPHA); // all|students|staff
if (!in_array($role, ['all','students','staff'], true)) { $role = 'all'; }

list($y, $m) = array_map('intval', explode('-', $month));
$firstdayts  = strtotime(sprintf('%04d-%02d-01 00:00:00', $y, $m));
$nextmonthts = strtotime('+1 month', $firstdayts);
$daysinmonth = (int)date('t', $firstdayts);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/onlineuserview/heatmap.php', ['month' => $month, 'role' => $role]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'block_onlineuserview'));
$PAGE->set_heading(get_string('heading', 'block_onlineuserview'));

// ---- Role filter helper ----------------------------------------------------
/**
 * Returns SQL snippet (prefixed with a leading space) and augments $params.
 * Applies to outer log alias "l".
 */
function ousv_role_filter_sql(string $role, array &$params): string {
    if ($role === 'students') {
        $params['rf_student'] = 'student';
        return " AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = l.userid AND r.archetype = :rf_student
                )";
    }
    if ($role === 'staff') {
        // Named params for IN clause (manager, coursecreator, editingteacher, teacher)
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
                     WHERE ra.userid = l.userid AND r.archetype IN (:rf_staff0, :rf_staff1, :rf_staff2, :rf_staff3)
                )";
    }
    return ''; // all
}

global $DB;

// Bucket to the start of each hour (Unix ts). Use distinct placeholders to satisfy Moodle.
$params = [
  'h1'      => HOURSECS,
  'h2'      => HOURSECS,
  'startts' => $firstdayts,
  'endts'   => $nextmonthts,
];
$rolewhere = ousv_role_filter_sql($role, $params);

$sql = "
  SELECT
    FLOOR(l.timecreated / :h1) * :h2 AS bucketts,
    COUNT(DISTINCT l.userid)        AS users
  FROM {logstore_standard_log} l
  WHERE l.timecreated >= :startts
    AND l.timecreated <  :endts
    AND l.userid > 0
    $rolewhere
  GROUP BY bucketts
  ORDER BY bucketts
";
$rows = $DB->get_records_sql($sql, $params);

// Build 24 x N matrix initialised to 0.
$matrix = [];
for ($h = 0; $h < 24; $h++) {
    $matrix[$h] = array_fill(1, $daysinmonth, 0);
}

// Fill matrix using Moodle/user timezone for day/hour.
$maxval = 0;
foreach ($rows as $r) {
    $ts  = (int)$r->bucketts;
    $dom = (int)userdate($ts, '%e'); // 1..31
    $hh  = (int)userdate($ts, '%H'); // 0..23
    if ($dom >= 1 && $dom <= $daysinmonth && $hh >= 0 && $hh <= 23) {
        $val = (int)$r->users;
        $matrix[$hh][$dom] = $val;
        if ($val > $maxval) { $maxval = $val; }
    }
}

echo $OUTPUT->header();

// Month + Role picker form.
$formurl = new moodle_url('/blocks/onlineuserview/heatmap.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl,
    'style' => 'margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;']);
echo html_writer::label(get_string('heatmap_selectmonth', 'block_onlineuserview'), 'ousv_month');
echo html_writer::empty_tag('input', ['type' => 'month', 'id' => 'ousv_month', 'name' => 'month', 'value' => $month, 'class' => 'form-control']);

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

echo html_writer::tag('h3', get_string('heatmaptitle2', 'block_onlineuserview', userdate($firstdayts, '%B %Y')));

// ===== Colour scale helper: Green → Yellow → Red (relative to max) =====
function ousv_cell_style($v, $max) {
    if ($max <= 0 || $v <= 0) return 'background:#f6f6f6;color:#000;';
    $p = min(1, $v / $max); // 0..1

    $low  = [232, 245, 233]; // #e8f5e9 (very light green)
    $mid  = [255, 245, 157]; // #fff59d (light yellow)
    $high = [211,  47,  47]; // #d32f2f (red)

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
    return "background: rgb({$r},{$g},{$b}); color: {$fg};";
}

// Build table: header row = days of month, first col = hour labels.
$tbl = new html_table();
$head = ['']; // top-left corner blank
for ($h = 0; $h < 24; $h++) {
    $label = sprintf('%02d:00', $h);
    $cells = [s($label)];
    for ($d = 1; $d <= $daysinmonth; $d++) {
        $v = (int)$matrix[$h][$d];
        $style = ousv_cell_style($v, $maxval);

        // Build exact hour range for this cell.
        $fromts = make_timestamp($y, $m, $d, $h, 0, 0);
        $endts  = $fromts + HOURSECS;
        $url = new moodle_url('/blocks/onlineuserview/users.php', [
            'role' => $role, 'fromts' => $fromts, 'endts' => $endts
        ]);

        $cellcontent = html_writer::div($v, 'ousv-heatcell', ['style' => $style]);
        $cells[] = html_writer::link($url, $cellcontent);
    }
    $tbl->data[] = new html_table_row($cells);
}

$tbl->head = $head;

// Rows: 00:00 .. 23:00
for ($h = 0; $h < 24; $h++) {
    $label = sprintf('%02d:00', $h);
    $cells = [s($label)];
    for ($d = 1; $d <= $daysinmonth; $d++) {
        $v = (int)$matrix[$h][$d];
        $style = ousv_cell_style($v, $maxval);
        $cells[] = html_writer::div($v, 'ousv-heatcell', ['style' => $style]);
    }
    $tbl->data[] = new html_table_row($cells);
}

echo html_writer::table($tbl);

// Legend
echo html_writer::div(
    html_writer::span(get_string('heatmap_legend_low', 'block_onlineuserview'), 'badge', ['style'=>'background:#e8f5e9;color:#000;margin-right:8px;']) .
    html_writer::span(get_string('heatmap_legend_mid', 'block_onlineuserview'), 'badge', ['style'=>'background:#fff59d;color:#000;margin-right:8px;']) .
    html_writer::span(get_string('heatmap_legend_high', 'block_onlineuserview'), 'badge', ['style'=>'background:#d32f2f;color:#fff;']),
    '', ['style'=>'margin-top:8px;']
);

// Tighten cells.
echo '<style>
  .ousv-heatcell { min-width: 42px; padding: 6px 4px; text-align: center; border-radius: 4px; }
  table.generaltable th, table.generaltable td { vertical-align: middle; }
</style>';

echo $OUTPUT->footer();
