<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class dependencies
{
    public static function collect(\stdClass $map): array
    {
        global $DB,$CFG;
        require_once($CFG->libdir.'/questionlib.php');
        require_once($CFG->dirroot.'/question/engine/lib.php');
        $ctx = \context_module::instance($map->cmid);
        $entries = $DB->get_fieldset_sql("SELECT DISTINCT qr.questionbankentryid FROM {question_references} qr
   JOIN {quiz_slots} s ON s.id=qr.itemid WHERE s.quizid=? AND qr.component='mod_quiz' AND qr.questionarea='slot'
   AND qr.usingcontextid=?", [$map->quizid,$ctx->id]);
        $contexts = [];
        $loader = new \core_question\local\bank\random_question_loader(new \qubaid_list([]), []);
        $sets = $DB->get_records('question_set_references', ['usingcontextid' => $ctx->id,'component' => 'mod_quiz','questionarea' => 'slot']);
        foreach ($sets as $set) {
            $contexts[] = (int)$set->questionscontextid;
            $conditions = \core_question\question_reference_manager::convert_legacy_set_reference_filter_condition(json_decode($set->filtercondition, true, 512, JSON_THROW_ON_ERROR));
            $ids = array_keys($loader->get_filtered_questions($conditions['filter'], 0));
            if (!$ids) {
                throw new \RuntimeException('A random question pool is empty');
            }
            [$sql,$params] = $DB->get_in_or_equal($ids);
            $entries = array_merge($entries, $DB->get_fieldset_select('question_versions', 'questionbankentryid', 'questionid '.$sql, $params));
        }
        $entries = array_values(array_unique(array_map('intval', $entries)));
        if (!$entries) {
            throw new \RuntimeException('The quiz has no exportable questions');
        }
        // Embedded subquestions are dependencies of their parent questions.
        do {
            $before = count($entries);
            [$sql,$params] = $DB->get_in_or_equal($entries);
            $children = $DB->get_fieldset_sql("SELECT DISTINCT child.questionbankentryid FROM {question_versions} parent
     JOIN {question} q ON q.parent=parent.questionid JOIN {question_versions} child ON child.questionid=q.id
     WHERE parent.questionbankentryid $sql", $params);
            $entries = array_values(array_unique(array_merge($entries, array_map('intval', $children))));
        } while (count($entries) > $before);
        [$sql,$params] = $DB->get_in_or_equal($entries);
        $qtypes = $DB->get_fieldset_sql("SELECT DISTINCT q.qtype FROM {question} q JOIN {question_versions} qv ON qv.questionid=q.id WHERE qv.questionbankentryid $sql", $params);
        $questionids = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid '.$sql, $params);
        $DB->delete_records('local_examsync_dep', ['mapid' => $map->id]);
        foreach (['entry' => $entries,'question' => array_unique($questionids),'context' => array_unique($contexts)] as $kind => $ids) {
            foreach ($ids as $id) {
                $DB->insert_record('local_examsync_dep', (object)['mapid' => $map->id,'kind' => $kind,'itemid' => $id]);
            }
        }
        return ['entries' => $entries,'contexts' => $contexts,'qtypes' => $qtypes];
    }
}
