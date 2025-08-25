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

//        // --- 5 minute snapshot by role ---
//        $summary = \block_onlineuserview\local\service::count_online_by_roles(300);
//        $items = html_writer::start_tag('ul');
//        $items .= html_writer::tag('li', get_string('window_300', 'block_onlineuserview'));
//        $items .= html_writer::tag('li', get_string('col_all', 'block_onlineuserview') . ': ' . (int)$summary['all']);
//        $items .= html_writer::tag('li', get_string('col_students', 'block_onlineuserview') . ': ' . (int)$summary['students']);
//        $items .= html_writer::tag('li', get_string('col_staff', 'block_onlineuserview') . ': ' . (int)$summary['staff']);
//        $items .= html_writer::end_tag('ul');
//        $this->content->text .= $items;

        // --- Quick 5/30/60 counts (All users) ---
        $baseurl = new moodle_url('/blocks/onlineuserview/users.php', ['role' => 'all']);
        $rows = [];
        foreach ([5, 30, 60] as $m) {
            $since = time() - ($m * 60);
            $countsql = "
                SELECT COUNT(DISTINCT u.id)
                  FROM {user} u
                  JOIN {logstore_standard_log} l ON l.userid = u.id AND l.timecreated >= :since
                 WHERE u.deleted=0 AND u.suspended=0 AND u.confirmed=1";
            $cnt = (int)$DB->get_field_sql($countsql, ['since' => $since]);

            $label = ($m === 60) ? get_string('h1','block_onlineuserview')
                   : (($m === 30) ? get_string('m30','block_onlineuserview')
                                  : get_string('m5','block_onlineuserview'));
            $url = new moodle_url($baseurl, ['window' => $m]);
            $rows[] = html_writer::tag('tr',
                html_writer::tag('td', html_writer::link($url, $label)) .
                html_writer::tag('td', $cnt)
            );
        }

        $tablehtml = html_writer::tag('table',
            html_writer::tag('thead',
                html_writer::tag('tr',
                    html_writer::tag('th', get_string('period','block_onlineuserview')) .
                    html_writer::tag('th', get_string('usersonline','block_onlineuserview'))
                )
            ) .
            html_writer::tag('tbody', implode('', $rows)),
            ['class'=>'generaltable', 'style'=>'margin-top:8px;']
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

        $usersurl = new moodle_url('/blocks/onlineuserview/users.php', ['window'=>30,'role'=>'all']);
        $userslink = html_writer::link($usersurl, get_string('usersbyemail','block_onlineuserview'));
        $this->content->text .= html_writer::div($userslink, '', ['style' => 'margin-top:6px;']);

        $this->content->footer = '';

        return $this->content;
    }
}
