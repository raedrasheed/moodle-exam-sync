<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class package
{
    public static function cleanup(string $uuid, int $revision): void
    {
        global $CFG;
        protocol::uuid($uuid);
        protocol::revision($revision);
        $parent = $CFG->dataroot.'/local_examsync/'.$uuid;
        foreach (glob($parent . '/*', GLOB_ONLYDIR) as $path) {
            if (ctype_digit(basename($path)) && (int)basename($path) <= $revision) {
                remove_dir($path);
            }
        }
    }
    public static function directory(string $uuid, int $revision): string
    {
        global $CFG;
        protocol::uuid($uuid);
        protocol::revision($revision);
        return make_upload_directory('local_examsync/'.$uuid.'/'.$revision);
    }
    public static function xml(string $file): \DOMDocument
    {
        $d = new \DOMDocument();
        $d->preserveWhiteSpace = false;
        if (!$d->load($file, LIBXML_NONET) || $d->doctype) {
            throw new \RuntimeException('Invalid backup XML');
        }
        return $d;
    }
    /** Remove unrelated bank entries and their files before transmitting the package. */
    public static function prune(string $dir, array $entries, string $stamp): array
    {
        $d = self::xml($dir.'/questions.xml');
        $xp = new \DOMXPath($d);
        $keep = array_fill_keys($entries, true);
        foreach (iterator_to_array($xp->query('//question_bank_entry')) as $e) {
            if (!isset($keep[(int)$e->getAttribute('id')])) {
                $e->parentNode->removeChild($e);
            }
        }
        // Question comments and arbitrary local plugin payloads are not needed to deliver an attempt.
        foreach (iterator_to_array($xp->query('//*[starts-with(name(),"plugin_qbank_comment") or starts-with(name(),"plugin_local_")]')) as $e) {
            $e->parentNode->removeChild($e);
        }
        $ids = [];
        $qtypes = [];
        $questions = 0;
        $qids = [];
        $answerids = [];
        $hintids = [];
        $subids = [];
        $dragids = [];
        foreach ($xp->query('//question') as $q) {
            $questions++;
            $qtype = $q->getElementsByTagName('qtype')->item(0)->nodeValue;
            $qtypes[] = $qtype;
            $qid = (int)$q->getAttribute('id');
            $qids[$qid] = true;
            $ids['qtype_'.$qtype][$qid] = true;
            foreach ($xp->query('.//*[@id]', $q) as $e) {
                $ids['qtype_'.$qtype][(int)$e->getAttribute('id')] = true;
            }
            foreach ($xp->query('.//answer', $q) as $e) {
                $answerids[(int)$e->getAttribute('id')] = true;
            }
            foreach ($xp->query('.//question_hint', $q) as $e) {
                $hintids[(int)$e->getAttribute('id')] = true;
            }
            foreach ($xp->query('.//match', $q) as $e) {
                $subids[(int)$e->getAttribute('id')] = true;
            }
            foreach ($xp->query('.//drag', $q) as $e) {
                $dragids[(int)$e->getAttribute('id')] = true;
            }
        }
        if (!$questions) {
            throw new \RuntimeException('Pruned package has no questions');
        }
        // Give every revision isolated stamps so restore never edits another exam's question bank.
        foreach ($xp->query('//question/stamp | //question_category/stamp') as $e) {
            $e->nodeValue = hash('sha256', $stamp.':'.$e->nodeValue);
        }
        foreach ($xp->query('//question/createdby | //question/modifiedby | //question_bank_entry/ownerid') as $e) {
            $e->nodeValue = '0';
        }
        $d->save($dir.'/questions.xml');
        $files = self::xml($dir.'/files.xml');
        $fx = new \DOMXPath($files);
        $hashes = [];
        foreach (iterator_to_array($fx->query('//file')) as $f) {
            $component = $f->getElementsByTagName('component')->item(0)->nodeValue;
            $itemid = (int)$f->getElementsByTagName('itemid')->item(0)->nodeValue;
            $area = $f->getElementsByTagName('filearea')->item(0)->nodeValue;
            $allowed = $component === 'mod_quiz' || isset($ids[$component][$itemid]);
            // Core qtypes use explicit item namespaces; IDs in different tables can collide.
            if (str_starts_with($component, 'qtype_') && in_array($area, ['questiontext', 'generalfeedback',
                'correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback', 'bgimage', 'graderinfo', 'instruction'], true)) {
                $allowed = isset($qids[$itemid]);
            }
            if ($component === 'qtype_match' && $area === 'subquestion') {
                $allowed = isset($subids[$itemid]);
            }
            if ($component === 'qtype_ddimageortext' && $area === 'dragimage') {
                $allowed = isset($dragids[$itemid]);
            }
            if ($component === 'question') {
                $allowed = match($area) {
                    'answer','answerfeedback' => isset($answerids[$itemid]),
                    'hint' => isset($hintids[$itemid]),
                    'questiontext','generalfeedback','correctfeedback','partiallycorrectfeedback','incorrectfeedback' => isset($qids[$itemid]),
                    default => false,
                };
            }
            if (!$allowed) {
                $f->parentNode->removeChild($f);
                continue;
            }
            foreach (['userid', 'author', 'source'] as $tag) {
                $owner = $f->getElementsByTagName($tag)->item(0);
                if ($owner) {
                    $owner->nodeValue = $tag === 'userid' ? '0' : '';
                }
            }
            $hashes[$f->getElementsByTagName('contenthash')->item(0)->nodeValue] = true;
        }
        $files->save($dir.'/files.xml');
        if (is_dir($dir.'/files')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/files', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !isset($hashes[$f->getBasename()])) {
                    unlink($f->getPathname());
                }
            }
        }
        // Native activity user data is excluded; enrolled-user identity mapping is sent separately.
        foreach (glob($dir.'/activities/quiz_*/quiz.xml') as $quizfile) {
            $q = self::xml($quizfile);
            $qx = new \DOMXPath($q);
            foreach (iterator_to_array($qx->query('//overrides/override')) as $e) {
                $e->parentNode->removeChild($e);
            }
            $q->save($quizfile);
        }
        foreach (glob($dir.'/activities/quiz_*/module.xml') as $modulefile) {
            $m = self::xml($modulefile);
            foreach (['visible' => '0','visibleoncoursepage' => '0','idnumber' => $stamp,'availability' => '$@NULL@$','groupmode' => '0','groupingid' => '0'] as $tag => $value) {
                $e = $m->getElementsByTagName($tag)->item(0);
                if ($e) {
                    $e->nodeValue = $value;
                }
            }
            $m->save($modulefile);
        }
        return ['questioncount' => $questions,'qtypes' => array_values(array_unique($qtypes))];
    }
    public static function export(\stdClass $map): array
    {
        global $CFG,$DB;
        require_once($CFG->dirroot.'/backup/util/includes/backup_includes.php');
        $deps = dependencies::collect($map);
        $quiz = $DB->get_record('quiz', ['id' => $map->quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('quiz', $map->cmid, 0, false, MUST_EXIST);
        if ($quiz->allowofflineattempts) {
            throw new \RuntimeException('Offline attempts are not supported for remote exams');
        }
        $course = get_course($map->courseid);
        $dir = self::directory($map->uuid, $map->revision);
        $path = $dir.'/exam.mbz';
        $extract = $dir.'/export';
        if (is_dir($extract)) {
            remove_dir($extract);
        }
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $map->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $map->actorid
        );
        try {
            foreach (['users','role_assignments','user_files','comments','logs','histories','groups','blocks','calendarevents'] as $name) {
                if ($bc->get_plan()->setting_exists($name)) {
                    $bc->get_plan()->get_setting($name)->set_value(0);
                }
            }
            $bc->execute_plan();
            $file = $bc->get_results()['backup_destination'];
            if (!$file) {
                throw new \RuntimeException('Backup did not produce a file');
            }
            $packer = get_file_packer('application/vnd.moodle.backup');
            if (!$packer->extract_to_pathname($file, $extract)) {
                throw new \RuntimeException('Cannot extract backup');
            }
            $details = self::prune($extract, $deps['entries'], 'examsync:'.$map->uuid.':'.$map->revision);
            file_put_contents($extract.'/examsync-runtime.json', json_encode(membership::snapshot($map), JSON_THROW_ON_ERROR));
            $input = [];
            foreach (new \DirectoryIterator($extract) as $f) {
                if (!$f->isDot()) {
                    $input[$f->getFilename()] = $f->getPathname();
                }
            }
            if (!$packer->archive_to_pathname($input, $path)) {
                throw new \RuntimeException('Cannot create scoped package');
            }
            $file->delete();
        } finally {
            $bc->destroy();
        }
        $max = (int)(get_config('local_examsync', 'maxpackage') ?: 134217728);
        if (filesize($path) > $max) {
            throw new \RuntimeException('Package exceeds configured size limit');
        }
        return ['path' => $path,'manifest' => [
         'uuid' => $map->uuid,'revision' => (int)$map->revision,'size' => filesize($path),'sha256' => hash_file('sha256', $path),
         'branch' => '501','qtypes' => $details['qtypes'],'questioncount' => $details['questioncount'],
         'slotcount' => $DB->count_records('quiz_slots', ['quizid' => $quiz->id]),
         'course' => ['id' => (int)$course->id,'fullname' => $course->fullname,'shortname' => $course->shortname,'idnumber' => $course->idnumber],
         'quiz' => ['name' => $quiz->name,'grade' => (float)$quiz->grade,'timeopen' => (int)$quiz->timeopen,'timeclose' => (int)$quiz->timeclose],
         'groupmode' => (int)$cm->groupmode,'groupingid' => (int)$cm->groupingid,
        ]];
    }
}
