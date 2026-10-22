<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class membership
{
    public static function remember(int $mapid, int $userid): void
    {
        global $DB;
        if (!$DB->record_exists('local_examsync_member', ['mapid' => $mapid,'userid' => $userid])) {
            $DB->insert_record('local_examsync_member', (object)['mapid' => $mapid,'userid' => $userid]);
        }
    }
    public static function snapshot(\stdClass $map): array
    {
        global $DB,$CFG;
        require_once($CFG->dirroot.'/group/lib.php');
        $ctx = \context_course::instance($map->courseid);
        $users = get_enrolled_users($ctx, '', 0, 'u.id,u.firstname,u.lastname,u.email,u.lang,u.suspended,u.deleted', null, 0, 0, true);
        $members = [];
        foreach ($users as $u) {
            if ($u->deleted || $u->suspended) {
                continue;
            }
            self::remember((int)$map->id, (int)$u->id);
            $teacher = has_capability('mod/quiz:grade', \context_module::instance($map->cmid), $u->id);
            $members[] = ['user_id' => (string)$u->id,'given_name' => $u->firstname,'family_name' => $u->lastname,
             'email' => $u->email,'role' => $teacher ? 'teacher' : 'student'];
        }
        $groups = [];
        $needed = [];
        $overrides = [];
        $cm = get_coursemodule_from_id('quiz', $map->cmid, 0, false, MUST_EXIST);
        foreach ($DB->get_records('quiz_overrides', ['quiz' => $map->quizid]) as $o) {
            $row = array_intersect_key((array)$o, array_flip(['userid','groupid','timeopen','timeclose','timelimit','attempts','password']));
            $overrides[] = $row;
            if ($o->groupid) {
                $needed[(int)$o->groupid] = true;
            }
        }
        if ($cm->groupmode || $cm->groupingid) {
            foreach (groups_get_all_groups($map->courseid, 0, $cm->groupingid) as $g) {
                $needed[$g->id] = true;
            }
        }
        foreach (array_keys($needed) as $id) {
            $g = $DB->get_record('groups', ['id' => $id], '*', MUST_EXIST);
            $groups[] = ['sourceid' => $g->id,'name' => $g->name,'members' => array_map('intval', $DB->get_fieldset_select('groups_members', 'userid', 'groupid=?', [$g->id]))];
        }
        return ['members' => $members,'groups' => $groups,'overrides' => $overrides,'groupmode' => (int)$cm->groupmode];
    }
    public static function apply(\stdClass $map, array $snapshot): void
    {
        global $DB,$CFG;
        require_once($CFG->dirroot.'/group/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');
        if (!is_enabled_auth('lti') || !enrol_is_enabled('lti')) {
            throw new \RuntimeException('Enable LTI authentication and enrolment');
        }
        $auth = get_auth_plugin('lti');
        $issuer = get_config('local_examsync', 'issuer');
        if (!$issuer) {
            throw new \RuntimeException('Configure the exact source LTI issuer');
        }
        $tool = $DB->get_record('enrol_lti_tools', ['id' => $map->toolid], '*', MUST_EXIST);
        $enrol = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('lti');
        $userids = [];
        $mapping = [];
        $roleteacher = (int)get_config('local_examsync', 'instructorrole');
        $rolestudent = (int)get_config('local_examsync', 'learnerrole');
        foreach ($snapshot['members'] as $member) {
            $remote = (int)$member['user_id'];
            if ($remote < 1) {
                throw new \RuntimeException('Invalid source user');
            }
            $user = $auth->find_or_create_user_from_membership($member, $issuer);
            if (is_siteadmin($user) || $user->deleted || $user->suspended) {
                throw new \RuntimeException('Unsafe or inactive linked account');
            }
            $existing = $DB->get_record('local_examsync_user', ['sourceid' => $remote]);
            if ($existing && (int)$existing->userid !== (int)$user->id) {
                throw new \RuntimeException('Identity mapping conflict');
            }
            if (!$existing) {
                $DB->insert_record('local_examsync_user', (object)['sourceid' => $remote,'userid' => $user->id]);
            }
            $role = $member['role'] === 'teacher' ? $roleteacher : $rolestudent;
            $plugin->enrol_user($enrol, $user->id, $role, 0, 0, ENROL_USER_ACTIVE);
            // Remove the obsolete role from this exact LTI enrolment instance only.
            role_unassign($member['role'] === 'teacher' ? $rolestudent : $roleteacher, $user->id, \context_course::instance($map->courseid)->id, 'enrol_lti', $enrol->id);
            $userids[] = (int)$user->id;
            $mapping[$remote] = (int)$user->id;
            self::remember((int)$map->id, (int)$user->id);
        }
        foreach ($DB->get_records('user_enrolments', ['enrolid' => $enrol->id]) as $ue) {
            if (!in_array((int)$ue->userid, $userids, true)) {
                $plugin->update_user_enrol($enrol, $ue->userid, ENROL_USER_SUSPENDED);
            }
        }
        $groups = [];
        foreach ($snapshot['groups'] as $g) {
            $idnumber = 'examsync-'.$map->uuid.'-'.$g['sourceid'];
            $local = $DB->get_record('groups', ['courseid' => $map->courseid,'idnumber' => $idnumber]);
            if (!$local) {
                $gid = groups_create_group((object)['courseid' => $map->courseid,'name' => $g['name'],'idnumber' => $idnumber]);
            } else {
                $gid = $local->id;
                groups_update_group((object)['id' => $gid,'courseid' => $map->courseid,'name' => $g['name']]);
            }
            $desired = [];
            foreach ($g['members'] as $remote) {
                if (isset($mapping[$remote])) {
                    $desired[] = $mapping[$remote];
                    groups_add_member($gid, $mapping[$remote]);
                }
            }
            foreach (groups_get_members($gid, 'u.id') as $u) {
                if (!in_array((int)$u->id, $desired, true)) {
                    groups_remove_member($gid, $u->id);
                }
            }
            $groups[$g['sourceid']] = $gid;
        }
        // An exam-specific grouping prevents groups from other exams in this course leaking into reports.
        $groupingname = 'ExamSync '.$map->uuid;
        $grouping = $DB->get_record('groupings', ['courseid' => $map->courseid,'name' => $groupingname]);
        $groupingid = $grouping ? $grouping->id : groups_create_grouping((object)['courseid' => $map->courseid,'name' => $groupingname]);
        foreach ($DB->get_records('groupings_groups', ['groupingid' => $groupingid]) as $gg) {
            if (!in_array((int)$gg->groupid, array_map('intval', $groups), true)) {
                groups_unassign_grouping($groupingid, $gg->groupid);
            }
        }
        foreach ($groups as $gid) {
            groups_assign_grouping($groupingid, $gid);
        }
        $DB->update_record('course_modules', (object)['id' => $map->cmid,'groupmode' => (int)($snapshot['groupmode'] ?? 0),'groupingid' => $groupingid]);
        rebuild_course_cache($map->courseid, true);
        $quiz = $DB->get_record('quiz', ['id' => $map->quizid], '*', MUST_EXIST);
        $quiz->cmid = $map->cmid;
        $manager = new \mod_quiz\local\override_manager($quiz, \context_module::instance($map->cmid));
        // Overrides are owned by the source. Apply in a single DB transaction; protect attempts with the map lock.
        $tx = $DB->start_delegated_transaction();
        $manager->delete_all_overrides(false);
        foreach ($snapshot['overrides'] as $o) {
            if (!empty($o['userid'])) {
                if (!isset($mapping[$o['userid']])) {
                    continue;
                }
                $o['userid'] = $mapping[$o['userid']];
                unset($o['groupid']);
            } else {
                if (!isset($groups[$o['groupid']])) {
                    throw new \RuntimeException('Unmapped override group');
                }
                $o['groupid'] = $groups[$o['groupid']];
                unset($o['userid']);
            }
            $manager->save_override($o);
        }
        $tx->allow_commit();
    }
}
