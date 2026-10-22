<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync\privacy;

defined('MOODLE_INTERNAL') || die();
use core_privacy\local\metadata\collection;
use core_privacy\local\request\{contextlist,approved_contextlist,userlist,approved_userlist,writer};
use local_examsync\{state,protocol};

/** Export and erase bridge-owned records. Native Moodle records belong to their own providers. */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider
{
    public static function get_metadata(collection $items): collection
    {
        $items->add_database_table('local_examsync_user', ['userid' => 'privacy:metadata:userid','sourceid' => 'privacy:metadata:sourceid'], 'privacy:metadata:user');
        $items->add_database_table('local_examsync_member', ['userid' => 'privacy:metadata:userid'], 'privacy:metadata:user');
        $items->add_database_table('local_examsync_grade', ['userid' => 'privacy:metadata:userid','sequence' => 'privacy:metadata:sequence'], 'privacy:metadata:grade');
        $items->add_database_table('local_examsync_map', ['actorid' => 'privacy:metadata:actorid'], 'privacy:metadata:map');
        $items->add_database_table('local_examsync_op', ['payload' => 'privacy:metadata:payload'], 'privacy:metadata:op');
        $items->add_external_location_link('peer_moodle', ['user_id' => 'privacy:metadata:sourceid',
         'name' => 'privacy:metadata:name','email' => 'privacy:metadata:email','grade' => 'privacy:metadata:grades'], 'privacy:metadata:remote');
        return $items;
    }
    public static function get_contexts_for_userid(int $userid): contextlist
    {
        global $DB;
        $list = new contextlist();
        $list->add_from_sql("SELECT DISTINCT c.id FROM {context} c JOIN {local_examsync_map} m
   ON m.courseid=c.instanceid AND c.contextlevel=:level
   LEFT JOIN {local_examsync_member} u ON u.mapid=m.id
   LEFT JOIN {local_examsync_grade} g ON g.mapid=m.id
   WHERE m.actorid=:a OR u.userid=:u OR g.userid=:g", ['level' => CONTEXT_COURSE,'a' => $userid,'u' => $userid,'g' => $userid]);
        if ($DB->record_exists('local_examsync_user', ['userid' => $userid])) {
            $list->add_user_context($userid);
        }
        return $list;
    }
    public static function get_users_in_context(userlist $userlist): void
    {
        $ctx = $userlist->get_context();
        if ($ctx->contextlevel === CONTEXT_USER) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_examsync_user} WHERE userid=?', [$ctx->instanceid]);
            return;
        }
        if ($ctx->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $userlist->add_from_sql('actorid', 'SELECT actorid FROM {local_examsync_map} WHERE courseid=? AND actorid>0', [$ctx->instanceid]);
        foreach (['member','grade'] as $t) {
            $userlist->add_from_sql(
                'userid',
                "SELECT u.userid FROM {local_examsync_$t} u JOIN {local_examsync_map} m ON m.id=u.mapid WHERE m.courseid=?",
                [$ctx->instanceid]
            );
        }
    }
    private static function personal_snapshot(array $snapshot, int $remote): array
    {
        $result = ['members' => [],'groups' => [],'overrides' => []];
        foreach ($snapshot['members'] ?? [] as $m) {
            if ((int)$m['user_id'] === $remote) {
                $result['members'][] = $m;
            }
        }
        foreach ($snapshot['groups'] ?? [] as $g) {
            if (in_array($remote, array_map('intval', $g['members']), true)) {
                unset($g['members']);
                $result['groups'][] = $g;
            }
        }
        foreach ($snapshot['overrides'] ?? [] as $o) {
            if ((int)($o['userid'] ?? 0) === $remote) {
                $result['overrides'][] = $o;
            }
        }
        return $result;
    }
    public static function export_user_data(approved_contextlist $contextlist): void
    {
        global $DB,$CFG;
        $userid = (int)$contextlist->get_user()->id;
        $link = $DB->get_record('local_examsync_user', ['userid' => $userid]);
        $remote = $link ? (int)$link->sourceid : $userid;
        foreach ($contextlist as $ctx) {
            if ($ctx->contextlevel === CONTEXT_USER && $ctx->instanceid === $userid && $link) {
                writer::with_context($ctx)->export_data(['ExamSync'], (object)['identity' => $link]);
                continue;
            }
            if ($ctx->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            foreach ($DB->get_records('local_examsync_map', ['courseid' => $ctx->instanceid]) as $m) {
                $data = ['publishingteacher' => (int)$m->actorid === $userid,'membership' => $DB->record_exists('local_examsync_member', ['mapid' => $m->id,'userid' => $userid]),
                 'grade_delivery' => $DB->get_record('local_examsync_grade', ['mapid' => $m->id,'userid' => $userid]),'snapshots' => []];
                foreach ($DB->get_records('local_examsync_op', ['mapid' => $m->id,'kind' => 'runtime']) as $op) {
                    $data['snapshots'][] = self::personal_snapshot(json_decode($op->payload ?: '{}', true), $remote);
                }
                protocol::uuid($m->uuid);
                foreach (glob($CFG->dataroot.'/local_examsync/'.$m->uuid.'/*/exam.mbz') as $archive) {
                    $temp = make_request_directory();
                    get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($archive, $temp, ['examsync-runtime.json']);
                    if (is_file($temp.'/examsync-runtime.json')) {
                        $data['snapshots'][] = self::personal_snapshot(
                            json_decode(file_get_contents($temp.'/examsync-runtime.json'), true),
                            $remote
                        );
                    }
                    remove_dir($temp);
                }
                writer::with_context($ctx)->export_data(['ExamSync',$m->uuid], (object)$data);
            }
        }
    }
    private static function erase(\context $ctx, ?array $users): void
    {
        global $DB,$CFG;
        if ($ctx->contextlevel === CONTEXT_USER) {
            if ($users === null || in_array((int)$ctx->instanceid, $users, true)) {
                $DB->delete_records('local_examsync_user', ['userid' => $ctx->instanceid]);
            }
            return;
        }
        if ($ctx->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        foreach ($DB->get_records('local_examsync_map', ['courseid' => $ctx->instanceid]) as $m) {
            $involved = $users === null;
            foreach ($users ?? [] as $uid) {
                $involved = $involved || (int)$m->actorid === $uid || $DB->record_exists('local_examsync_member', ['mapid' => $m->id,'userid' => $uid]) ||
                 $DB->record_exists('local_examsync_grade', ['mapid' => $m->id,'userid' => $uid]);
            }
            if (!$involved) {
                continue;
            }
            $lock = state::lock('resource:'.$m->uuid);
            try {
                // Cancel caches containing mixed-user snapshots as one unit. Never edit a signed backup in place.
                protocol::uuid($m->uuid);
                $path = $CFG->dataroot.'/local_examsync/'.$m->uuid;
                if (is_dir($path)) {
                    remove_dir($path);
                }
                $DB->set_field('local_examsync_op', 'payload', null, ['mapid' => $m->id]);
                foreach (['member','grade'] as $table) {
                    if ($users === null) {
                        $DB->delete_records('local_examsync_'.$table, ['mapid' => $m->id]);
                    } else {
                        foreach ($users as $uid) {
                            $DB->delete_records('local_examsync_'.$table, ['mapid' => $m->id,'userid' => $uid]);
                        }
                    }
                }
                if ($users === null || in_array((int)$m->actorid, $users, true)) {
                    $m->actorid = 0;
                }
                $m->state = 'privacyblocked';
                $m->error = null;
                $DB->update_record('local_examsync_map', $m);
            } finally {
                $lock->release();
            }
        }
    }
    public static function delete_data_for_all_users_in_context(\context $context): void
    {
        self::erase($context, null);
    }
    public static function delete_data_for_user(approved_contextlist $contextlist): void
    {
        foreach ($contextlist as $ctx) {
            self::erase($ctx, [(int)$contextlist->get_user()->id]);
        }
    }
    public static function delete_data_for_users(approved_userlist $userlist): void
    {
        self::erase($userlist->get_context(), array_map('intval', $userlist->get_userids()));
    }
}
