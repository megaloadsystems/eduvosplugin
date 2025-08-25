<?php
class block_onlineuserview extends block_base {

    public function init() {
        $this->title = get_string('onlineuserview', 'block_onlineuserview');
    }

    public function applicable_formats() {
        return ['all' => true];
    }

    public function get_content() {
        global $DB;

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text = '';

        require_once(__DIR__ . '/classes/local/service.php');

        // --- Quick counts with role filters (All users | Staff | Students): 3h, 6h, 12h, 24h ---
        $baseurl = new moodle_url('/blocks/onlineuserview/users.php');
        $rows = [];

        // Hours we want to display as rows.
        $hours = [3, 6, 12, 24];

        // Column labels (with graceful fallback if lang strings not present).
        $strmgr = get_string_manager();
        $label_all      = $strmgr->string_exists('allusers', 'block_onlineuserview') ? get_string('allusers', 'block_onlineuserview') : get_string('allusers');
        $label_staff    = $strmgr->string_exists('staff', 'block_onlineuserview') ? get_string('staff', 'block_onlineuserview') : get_string('teachers');
        $label_students = $strmgr->string_exists('students', 'block_onlineuserview') ? get_string('students', 'block_onlineuserview') : get_string('students');

        foreach ($hours as $h) {
            $since = time() - ($h * 3600); // convert hours to seconds

            // Row label strings: h3, h6, h12, h24 (fallback to 'X hours').
            $stringkey = 'h' . $h;
            $label = $strmgr->string_exists($stringkey, 'block_onlineuserview')
                ? get_string($stringkey, 'block_onlineuserview')
                : ($h . ' ' . get_string('hours'));

            // Build cells for All / Staff / Students
            $cells = [];

            // Helper to count users by role filter.
            $count_all_sql = "
                SELECT COUNT(DISTINCT u.id)
                  FROM {user} u
                  JOIN {logstore_standard_log} l
                    ON l.userid = u.id
                   AND l.timecreated >= :since
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.confirmed = 1";
            $cnt_all = (int)$DB->get_field_sql($count_all_sql, ['since' => $since]);

            // Staff = users with teacher/editingteacher role anywhere.
            $count_staff_sql = $count_all_sql . "
               AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = u.id
                       AND r.shortname IN ('teacher','editingteacher')
                )";
            $cnt_staff = (int)$DB->get_field_sql($count_staff_sql, ['since' => $since]);

            // Students = users with student role anywhere.
            $count_students_sql = $count_all_sql . "
               AND EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.userid = u.id
                       AND r.shortname = 'student'
                )";
            $cnt_students = (int)$DB->get_field_sql($count_students_sql, ['since' => $since]);

            // users.php expects window in MINUTES like before.
            $windowminutes = $h * 60;

            $url_all      = new moodle_url($baseurl, ['window' => $windowminutes, 'role' => 'all']);
            $url_staff    = new moodle_url($baseurl, ['window' => $windowminutes, 'role' => 'staff']);
            $url_students = new moodle_url($baseurl, ['window' => $windowminutes, 'role' => 'students']);

            $cells[] = html_writer::tag('td', html_writer::link($url_all,      $label_all      . ': ' . $cnt_all));
            $cells[] = html_writer::tag('td', html_writer::link($url_staff,    $label_staff    . ': ' . $cnt_staff));
            $cells[] = html_writer::tag('td', html_writer::link($url_students, $label_students . ': ' . $cnt_students));

            $rows[] = html_writer::tag('tr',
                html_writer::tag('td', $label) . implode('', $cells)
            );
        }

        $tablehtml = html_writer::tag('table',
            html_writer::tag('thead',
                html_writer::tag('tr',
                    html_writer::tag('th', get_string('period','block_onlineuserview')) .
                    html_writer::tag('th', $label_all) .
                    html_writer::tag('th', $label_staff) .
                    html_writer::tag('th', $label_students)
                )
            ) .
            html_writer::tag('tbody', implode('', $rows)),
            ['class' => 'generaltable', 'style' => 'margin-top:8px;']
        );

        $this->content->text .= html_writer::tag('div',
            html_writer::tag('h5', get_string('onlinesummary','block_onlineuserview')) . $tablehtml
        );

        // --- Links to other pages ---
        $reporturl = new moodle_url('/blocks/onlineuserview/report.php', [
            'granularity' => 'hour',
            'day' => date('Y-m-d')
        ]);
        $reportlink = html_writer::link($reporturl, get_string('viewreport', 'block_onlineuserview'));
        $this->content->text .= html_writer::div($reportlink, '', ['style' => 'margin-top:6px;']);

        $heaturl = new moodle_url('/blocks/onlineuserview/heatmap.php', ['month' => date('Y-m')]);
        $heatlink = html_writer::link($heaturl, get_string('viewheatmap', 'block_onlineuserview'));
        $this->content->text .= html_writer::div($heatlink, '', ['style' => 'margin-top:6px;']);

        // Default "Users by email" link: set to 3h window, 'all' role.
        $usersurl = new moodle_url('/blocks/onlineuserview/users.php', ['window' => 180, 'role' => 'all']);
        $userslink = html_writer::link($usersurl, get_string('usersbyemail','block_onlineuserview'));
        $this->content->text .= html_writer::div($userslink, '', ['style' => 'margin-top:6px;']);

        $this->content->footer = '';

        return $this->content;
    }
}
