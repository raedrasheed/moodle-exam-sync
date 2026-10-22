<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class preflight
{
    public static function checks(): array
    {
        global $CFG,$DB;
        $cfg = get_config('local_examsync');
        $issues = [];
        if ($CFG->branch !== '501') {
            $issues[] = 'This release supports Moodle 5.1 only.';
        }
        if (!in_array($cfg->role ?? '', ['source','target'], true)) {
            $issues[] = 'Choose source or target role.';
        }
        if (strlen($cfg->secret ?? '') < 32 || str_starts_with($cfg->secret ?? '', 'REPLACE_')) {
            $issues[] = 'Replace the placeholder with a random secret of at least 32 characters.';
        }
        foreach (['siteid','peerid'] as $key) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $cfg->$key ?? '')) {
                $issues[] = 'Invalid '.$key.'.';
            }
        }
        if (($cfg->siteid ?? '') === ($cfg->peerid ?? '')) {
            $issues[] = 'Site identifiers must differ.';
        }
        try {
            protocol::baseurl($cfg->peerurl ?? '', !empty($cfg->labhttp));
        } catch (\Throwable $e) {
            $issues[] = $e->getMessage();
        }
        if (($cfg->peerurl ?? '') === $CFG->wwwroot) {
            $issues[] = 'The peer must be the other Moodle site.';
        }
        if (!\core_component::get_plugin_directory('quizaccess', 'examsync')) {
            $issues[] = 'Install quizaccess_examsync on both sites.';
        }
        if (($cfg->role ?? '') === 'source') {
            $type = $DB->get_record('lti_types', ['id' => (int)($cfg->ltitypeid ?? 0)]);
            if (!$type || $type->ltiversion !== '1.3.0' || (int)$type->state !== 1) {
                $issues[] = 'Select a configured LTI 1.3 tool type.';
            }
        } elseif (($cfg->role ?? '') === 'target') {
            if (!enrol_is_enabled('lti') || !is_enabled_auth('lti')) {
                $issues[] = 'Enable LTI authentication and Publish as LTI tool enrolment.';
            }
            if (empty($cfg->issuer)) {
                $issues[] = 'Set the exact source LTI issuer (source wwwroot).';
            }
            if (!$DB->record_exists('course_categories', ['id' => (int)($cfg->categoryid ?? 0)])) {
                $issues[] = 'Select an existing course category.';
            }
            foreach (['instructorrole' => 'teacher','learnerrole' => 'student'] as $key => $archetype) {
                if (!$DB->record_exists('role', ['id' => (int)($cfg->$key ?? 0),'archetype' => $archetype])) {
                    $issues[] = $key.' must use the '.$archetype.' archetype.';
                }
            }
        }
        return $issues;
    }
    public static function health(): array
    {
        global $CFG;
        $issues = self::checks();
        return ['role' => state::role(),'branch' => $CFG->branch,'ready' => $issues === [],'issues' => $issues];
    }
}
