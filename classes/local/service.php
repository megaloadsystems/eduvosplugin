<?php
namespace block_onlineuserview\local;

defined('MOODLE_INTERNAL') || die();

class service {
    /** @return string[] */
    public static function staff_archetypes(): array {
        return ['manager', 'coursecreator', 'editingteacher', 'teacher'];
    }

    /** Count online users for a given window, segmented by role class. */
    public static function count_online_by_roles(int $windowseconds): array {
        global $DB;
        $since = time() - $windowseconds;

        $all = $DB->count_records_select('user',
            "deleted = 0 AND suspended = 0 AND confirmed = 1 AND lastaccess >= :since AND username <> 'guest'",
            ['since' => $since]
        );

        $studentsql = "SELECT COUNT(DISTINCT u.id)
                         FROM {user} u
                         JOIN {role_assignments} ra ON ra.userid = u.id
                         JOIN {role} r ON r.id = ra.roleid
                        WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
                          AND u.lastaccess >= :since
                          AND r.archetype = :student";
        $studentcount = (int)$DB->get_field_sql($studentsql, [
            'since' => $since,
            'student' => 'student',
        ]);

        list($insql, $inparams) = $DB->get_in_or_equal(self::staff_archetypes(), SQL_PARAMS_NAMED, 'par', true);
        $staffsql = "SELECT COUNT(DISTINCT u.id)
                       FROM {user} u
                       JOIN {role_assignments} ra ON ra.userid = u.id
                       JOIN {role} r ON r.id = ra.roleid
                      WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
                        AND u.lastaccess >= :since
                        AND r.archetype $insql";
        $staffcount = (int)$DB->get_field_sql($staffsql, ['since' => $since] + $inparams);

        return [
            'all' => (int)$all,
            'students' => $studentcount,
            'staff' => $staffcount,
        ];
    }

    /** Fetch online users and role classification flags in one pass. */
    public static function get_online_users_with_roleflags(int $windowseconds): array {
        global $DB;
        $since = time() - $windowseconds;

        list($staffinsql, $staffinparams) = $DB->get_in_or_equal(self::staff_archetypes(), SQL_PARAMS_NAMED, 'par', true);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                       EXISTS (SELECT 1 FROM {role_assignments} ra1
                                JOIN {role} r1 ON r1.id = ra1.roleid
                               WHERE ra1.userid = u.id AND r1.archetype = 'student') AS is_student,
                       EXISTS (SELECT 1 FROM {role_assignments} ra2
                                JOIN {role} r2 ON r2.id = ra2.roleid
                               WHERE ra2.userid = u.id AND r2.archetype $staffinsql) AS is_staff
                  FROM {user} u
                 WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
                   AND u.lastaccess >= :since
                   AND u.username <> 'guest'
              ORDER BY u.email ASC, u.lastname ASC, u.firstname ASC";

        $params = ['since' => $since] + $staffinparams;
        $rows = $DB->get_records_sql($sql, $params);
        return array_values($rows);
    }
}
