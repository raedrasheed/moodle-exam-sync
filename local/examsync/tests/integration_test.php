<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(receiver::class)]
final class integration_test extends \advanced_testcase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        state::$internal = false;
        set_config('role', 'disabled', 'local_examsync');
        set_config('instructorrole', 4, 'local_examsync');
        set_config('learnerrole', 5, 'local_examsync');
        set_config('issuer', 'https://source.example', 'local_examsync');
        set_config('peerid', 'source', 'local_examsync');
        set_config('secret', str_repeat('s', 48), 'local_examsync');
    }
    private function fixture(): array
    {
        global $CFG,$USER,$DB;
        require_once($CFG->dirroot.'/mod/quiz/locallib.php');
        $g = $this->getDataGenerator();
        $course = $g->create_course();
        $quiz = $g->create_module('quiz', ['course' => $course->id,'grade' => 10]);
        $student = $g->create_user();
        $teacher = $g->create_user();
        $g->enrol_user($student->id, $course->id, 'student');
        $g->enrol_user($teacher->id, $course->id, 'editingteacher');
        $qg = $g->get_plugin_generator('core_question');
        $cat = $qg->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $used = $qg->create_question('shortanswer', null, ['category' => $cat->id,'name' => 'Used question']);
        $unused = $qg->create_question('shortanswer', null, ['category' => $cat->id,'name' => 'UNRELATED QUESTION']);
        quiz_add_quiz_question($used->id, $quiz);
        $fs = get_file_storage();
        foreach ([$used,$unused] as $q) {
            $fs->create_file_from_string(['contextid' => $cat->contextid,'component' => 'question',
              'filearea' => 'questiontext','itemid' => $q->id,'filepath' => '/','filename' => 'attachment.txt'], 'content-for-'.$q->id);
        }
        $m = (object)['uuid' => \core\uuid::generate(),'side' => 'source','courseid' => $course->id,'cmid' => $quiz->cmid,
          'quizid' => $quiz->id,'revision' => 1,'applied' => 0,'state' => 'pending','frozen' => 0,'actorid' => $USER->id,
          'origvisible' => 1,'timecreated' => time(),'timemodified' => time()];
        $m->id = $DB->insert_record('local_examsync_map', $m);
        return [$DB->get_record('local_examsync_map', ['id' => $m->id]),$quiz,$used,$unused,$student,$teacher];
    }
    private function target_mode(): void
    {
        global $CFG;
        set_config('role', 'target', 'local_examsync');
        set_config('enrol_plugins_enabled', $CFG->enrol_plugins_enabled.',lti');
        set_config('auth', 'manual,lti');
    }
    public function test_registered_events_exist(): void
    {
        global $CFG;
        $observers = [];
        require($CFG->dirroot.'/local/examsync/db/events.php');
        foreach ($observers as $o) {
            $this->assertTrue(class_exists($o['eventname']), $o['eventname']);
            $this->assertTrue(is_callable($o['callback']), $o['callback']);
        }
    }
    public function test_signed_requests_reject_replay_and_tampering(): void
    {
        [$m] = $this->fixture();
        set_config('role', 'source', 'local_examsync');
        $body = json_encode(['version' => 1,'action' => 'status','data' => ['uuid' => $m->uuid]]);
        $h = ['sender' => 'source','timestamp' => (string)time(),'nonce' => bin2hex(random_bytes(16))];
        $h['signature'] = protocol::sign(str_repeat('s', 48), 'source', (int)$h['timestamp'], $h['nonce'], $body);
        $this->assertSame('pending', endpoint::handle($body, $h)['state']);
        try {
            endpoint::handle($body, $h);
            $this->fail('Replay accepted');
        } catch (\dml_write_exception $e) {
        }
        $this->expectException(\RuntimeException::class);
        endpoint::handle($body.' ', $h);
    }
    public function test_signatures_expire_and_http_requires_explicit_lab_mode(): void
    {
        $body = '{}';
        $ts = time() - 121;
        $h = ['sender' => 'source','timestamp' => (string)$ts,'nonce' => str_repeat('a', 32)];
        $h['signature'] = protocol::sign(str_repeat('s', 48), 'source', $ts, $h['nonce'], $body);
        try {
            protocol::verify(str_repeat('s', 48), 'source', $h, $body, time());
            $this->fail('Expired request accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Expired request', $e->getMessage());
        }
        $this->assertSame('http://localhost:8080', protocol::baseurl('http://localhost:8080/', true));
        $this->expectException(\InvalidArgumentException::class);
        protocol::baseurl('http://localhost:8080');
    }
    public function test_teacher_export_with_permission_locked_user_data(): void
    {
        global $DB,$CFG;
        [$source,$quiz,$used,$unused,$student,$teacher] = $this->fixture();
        $source->actorid = (int)$teacher->id;
        $DB->set_field('local_examsync_map', 'actorid', $source->actorid, ['id' => $source->id]);
        $context = \context_course::instance($source->courseid);
        $this->assertFalse(has_capability('moodle/backup:userinfo', $context, $teacher->id));

        require_once($CFG->dirroot.'/backup/util/includes/backup_includes.php');
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $source->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $source->actorid
        );
        try {
            $users = $bc->get_plan()->get_setting('users');
            $this->assertSame(\base_setting::LOCKED_BY_PERMISSION, $users->get_status());
            $this->assertFalse((bool)$users->get_value());
        } finally {
            $bc->destroy();
        }

        // Dispatch tasks run as the publishing teacher, without administrator privileges.
        $this->setUser($teacher);
        $bundle = package::export($source);
        $this->assertFileExists($bundle['path']);
        $this->assertSame(1, $bundle['manifest']['questioncount']);
        $this->assertSame(1, $bundle['manifest']['slotcount']);
        $dir = package::directory($source->uuid, $source->revision).'/export';
        // Moodle omits users.xml entirely when no native user records are exported.
        $usercount = is_file($dir.'/users.xml') ?
            package::xml($dir.'/users.xml')->getElementsByTagName('user')->length : 0;
        $this->assertSame(0, $usercount);
        $questions = file_get_contents($dir.'/questions.xml');
        $this->assertStringContainsString('Used question', $questions);
        $this->assertStringNotContainsString('UNRELATED QUESTION', $questions);
    }
    public function test_scoped_package_restore_and_idempotency(): void
    {
        global $DB,$CFG;
        [$source,$quiz,$used,$unused,$student,$teacher] = $this->fixture();
        $bundle = package::export($source);
        $dir = package::directory($source->uuid, 1).'/export';
        $xml = file_get_contents($dir.'/questions.xml');
        $this->assertStringContainsString('Used question', $xml);
        $this->assertStringNotContainsString('UNRELATED QUESTION', $xml);
        $files = package::xml($dir.'/files.xml');
        $xp = new \DOMXPath($files);
        $this->assertSame(0, $xp->query('//file[component="question" and itemid="'.$unused->id.'"]')->length);
        $this->assertGreaterThan(0, $xp->query('//file[component="question" and itemid="'.$used->id.'"]')->length);
        $this->assertFileExists($dir.'/examsync-runtime.json');
        // Simulate a separate site's plugin tables while retaining Moodle source fixtures for isolation checks.
        $DB->delete_records('local_examsync_map', ['id' => $source->id]);
        $this->target_mode();
        $this->assertFalse(receiver::dispatch('prepare', $bundle['manifest'])['done']);
        $raw = file_get_contents($bundle['path']);
        foreach (str_split($raw, protocol::CHUNK) as $i => $chunk) {
            receiver::dispatch('chunk', [
             'uuid' => $source->uuid,'revision' => 1,'index' => $i,'content' => base64_encode($chunk)]);
        }
        receiver::dispatch('commit', ['uuid' => $source->uuid,'revision' => 1]);
        $m = $DB->get_record('local_examsync_map', ['uuid' => $source->uuid]);
        $op = $DB->get_record('local_examsync_op', ['opkey' => 'package:'.$m->uuid.':1']);
        $result = target::restore($m, $op);
        $m = $DB->get_record('local_examsync_map', ['id' => $m->id]);
        $this->assertSame('ready', $m->state);
        $this->assertNotEquals($quiz->id, $m->quizid);
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => $m->quizid]));
        $this->assertSame('UNRELATED QUESTION', $DB->get_field('question', 'name', ['id' => $unused->id]));
        $tool = $DB->get_record('enrol_lti_tools', ['id' => $m->toolid]);
        $this->assertSame('0', $tool->gradesync);
        $this->assertSame('0', $tool->membersync);
        $link = $DB->get_record('local_examsync_user', ['sourceid' => $student->id]);
        $this->assertNotFalse($link);
        $this->assertTrue(is_enrolled(\context_course::instance($m->courseid), $link->userid, '', true));
        $teacherlink = $DB->get_record('local_examsync_user', ['sourceid' => $teacher->id]);
        $this->assertFalse(has_capability('mod/quiz:manage', \context_module::instance($m->cmid), $teacherlink->userid));
        $this->assertTrue(has_capability('mod/quiz:grade', \context_module::instance($m->cmid), $teacherlink->userid));
        $before = $DB->count_records('quiz');
        $op = $DB->get_record('local_examsync_op', ['id' => $op->id]);
        $this->assertSame($result, target::restore($m, $op));
        $this->assertTrue(receiver::dispatch('prepare', $bundle['manifest'])['done']);
        $this->assertSame($before, $DB->count_records('quiz'));
        $oldcm = $m->cmid;
        $source->revision = 2;
        $newbundle = package::export($source);
        receiver::dispatch('prepare', $newbundle['manifest']);
        $m = $DB->get_record('local_examsync_map', ['id' => $m->id]);
        $newop = $DB->get_record('local_examsync_op', ['opkey' => 'package:' . $m->uuid . ':2']);
        $newresult = target::restore($m, $newop);
        $m = $DB->get_record('local_examsync_map', ['id' => $m->id]);
        $this->assertSame($result['tooluuid'], $newresult['tooluuid']);
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $oldcm]));
        $this->assertSame(1, $DB->count_records('enrol_lti_tools', ['id' => $m->toolid]));
        $auth = get_auth_plugin('lti');
        $launched = $auth->find_or_create_user_from_launch(['iss' => 'https://source.example','sub' => (string)$student->id]);
        $this->assertEquals($link->userid, $launched->id);
        $snapshot = membership::snapshot($source);
        $snapshot['groupmode'] = 1;
        $snapshot['groups'] = [['sourceid' => 55,'name' => 'Exam group','members' => [(int)$student->id]]];
        $snapshot['overrides'] = [['userid' => $student->id,'timelimit' => 1800],['groupid' => 55,'attempts' => 3]];
        membership::apply($m, $snapshot);
        $this->assertSame(2, $DB->count_records('quiz_overrides', ['quiz' => $m->quizid]));
        $this->setUser($link->userid);
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($m->quizid, $link->userid);
        $correct = $DB->get_field('question_answers', 'answer', ['question' => $used->id, 'fraction' => 1], IGNORE_MULTIPLE);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->submit_responses($attempt->id, [1 => $correct], false, true);
        $this->setAdminUser();
        $this->assertGreaterThan(0, $attempt->id);
        $this->assertEquals(10, $DB->get_field('quiz_grades', 'grade', ['quiz' => $m->quizid, 'userid' => $link->userid]));
        $this->assertTrue($DB->record_exists('local_examsync_grade', ['mapid' => $m->id, 'userid' => $link->userid]));
        $this->assertEquals(1, $DB->get_field('local_examsync_map', 'frozen', ['id' => $m->id]));
        try {
            receiver::dispatch('resetfreeze', ['uuid' => $m->uuid]);
            $this->fail('A real attempt was unfrozen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Real attempts', $e->getMessage());
        }
        $this->assertEquals(1, $DB->get_field('course_modules', 'groupmode', ['id' => $m->cmid]));
        $this->assertEquals(1800, $DB->get_field('quiz_overrides', 'timelimit', ['quiz' => $m->quizid,'userid' => $link->userid]));
        membership::apply($m, $snapshot);
        $this->assertSame(2, $DB->count_records('quiz_overrides', ['quiz' => $m->quizid]));
        $snapshot['members'] = array_values(array_filter($snapshot['members'], fn ($u) => (int)$u['user_id'] !== (int)$student->id));
        $snapshot['overrides'] = [];
        membership::apply($m, $snapshot);
        $this->assertSame(0, $DB->count_records('quiz_overrides', ['quiz' => $m->quizid]));
        $this->assertFalse(is_enrolled(\context_course::instance($m->courseid), $link->userid, '', true));
    }
    public function test_pending_revision_and_freeze_reject_replacement(): void
    {
        global $DB;
        [$m] = $this->fixture();
        $DB->delete_records('local_examsync_map', ['id' => $m->id]);
        $this->target_mode();
        $meta = ['uuid' => $m->uuid,'revision' => 3,'size' => 50,'sha256' => str_repeat('a', 64),'branch' => '501'];
        receiver::dispatch('prepare', $meta);
        try {
            receiver::dispatch('prepare', array_replace($meta, ['revision' => 2]));
            $this->fail('Older revision accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $DB->set_field('local_examsync_map', 'frozen', 1, ['uuid' => $m->uuid]);
        $this->expectException(\RuntimeException::class);
        receiver::dispatch('prepare', array_replace($meta, ['revision' => 4]));
    }
    public function test_enabling_new_quiz_via_core_settings(): void
    {
        global $DB;
        set_config('role', 'source', 'local_examsync');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id,'examsync_enabled' => 1]);
        $m = state::map($quiz->id);
        $this->assertNotNull($m);
        $this->assertGreaterThan(0, (int)$m->revision);
        $this->assertGreaterThan(0, $DB->count_records('task_adhoc', ['component' => 'local_examsync']));
    }
    public function test_random_pool_and_dependencies(): void
    {
        global $DB,$CFG;
        [$m,$quiz] = $this->fixture();
        $qg = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qg->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $qg->create_question('truefalse', null, ['category' => $cat->id,'name' => 'Pool A']);
        $qg->create_question('truefalse', null, ['category' => $cat->id,'name' => 'Pool B']);
        $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id));
        $structure->add_random_questions(1, 1, ['filter' => ['category' => [
         'jointype' => \core\output\datafilter::JOINTYPE_ANY,'values' => [$cat->id],
         'filteroptions' => ['includesubcategories' => false]]]]);
        $bundle = package::export($m);
        $this->assertSame(3, $bundle['manifest']['questioncount']);
        $this->assertSame(2, $bundle['manifest']['slotcount']);
        $this->assertSame(3, $DB->count_records('local_examsync_dep', ['mapid' => $m->id,'kind' => 'question']));
        $DB->delete_records('local_examsync_map', ['id' => $m->id]);
        $this->target_mode();
        receiver::dispatch('prepare', $bundle['manifest']);
        $target = $DB->get_record('local_examsync_map', ['uuid' => $m->uuid]);
        $op = $DB->get_record('local_examsync_op', ['mapid' => $target->id]);
        target::restore($target, $op);
        $target = $DB->get_record('local_examsync_map', ['id' => $target->id]);
        $sets = $DB->get_records('question_set_references', ['usingcontextid' => \context_module::instance($target->cmid)->id]);
        $this->assertCount(1, $sets);
        $set = reset($sets);
        $filters = json_decode($set->filtercondition, true);
        $loader = new \core_question\local\bank\random_question_loader(new \qubaid_list([]), []);
        $this->assertCount(2, $loader->get_filtered_questions($filters['filter'], 0));
    }
    public function test_source_publication_grade_order_and_admission(): void
    {
        global $DB,$CFG;
        [$m,$quiz,,,$student,$teacher] = $this->fixture();
        require_once($CFG->dirroot.'/mod/lti/locallib.php');
        require_once($CFG->libdir.'/gradelib.php');
        $typeid = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
         'name' => 'Exam Moodle','baseurl' => 'https://exams.example/enrol/lti/launch.php','ltiversion' => '1.3.0',
         'state' => LTI_TOOL_STATE_CONFIGURED,'lti_typename' => 'Exam Moodle']);
        set_config('role', 'source', 'local_examsync');
        set_config('ltitypeid', $typeid, 'local_examsync');
        set_config('peerurl', 'https://exams.example', 'local_examsync');
        $m->actorid = (int)$teacher->id;
        $DB->set_field('local_examsync_map', 'actorid', $m->actorid, ['id' => $m->id]);
        $this->setUser($teacher);
        $payload = ['uuid' => $m->uuid,'revision' => 1,'tooluuid' => \core\uuid::generate(),'targetcmid' => 77,'targetcourseid' => 33];
        // Fractional maxima cannot be represented by mod_lti; reject before changing the course.
        $DB->set_field('quiz', 'grade', '10.50000', ['id' => $quiz->id]);
        try {
            source::publish($m, $payload);
            $this->fail('Fractional maximum grade was silently truncated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('whole number', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('lti', ['course' => $m->courseid]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $quiz->cmid]));
        $DB->set_field('quiz', 'grade', '10.00000', ['id' => $quiz->id]);
        source::publish($m, $payload);
        $m = $DB->get_record('local_examsync_map', ['id' => $m->id]);
        $this->assertGreaterThan(0, (int)$m->ltiid);
        $this->assertEquals(10, $DB->get_field('lti', 'grade', ['id' => $m->ltiid]));
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $quiz->cmid]));
        $item = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'quiz','iteminstance' => $quiz->id,'itemnumber' => 0]);
        $this->assertEquals(GRADE_TYPE_NONE, $item->gradetype);
        $grade = ['revision' => 1,'userid' => (int)$student->id,'sequence' => 2,'grade' => 8.5,'grademax' => 10,
         'hidden' => 0,'itemhidden' => 0,'dategraded' => time()];
        $this->assertTrue(grades::receive($m, $grade)['accepted']);
        $this->assertTrue(grades::receive($m, array_replace($grade, ['sequence' => 1,'grade' => 1]))['accepted']);
        $item = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'lti','iteminstance' => $m->ltiid,'itemnumber' => 0]);
        grade_regrade_final_grades($m->courseid);
        $value = \grade_grade::fetch(['itemid' => $item->id,'userid' => $student->id]);
        $this->assertEquals(8.5, (float)$value->finalgrade);
        $allowed = source::authorize(['uuid' => $m->uuid,'revision' => 1,'userid' => $student->id]);
        $this->assertTrue($allowed['allowed']);
        $this->assertEquals(1, $DB->get_field('local_examsync_map', 'frozen', ['id' => $m->id]));
        $DB->set_field('user', 'suspended', 1, ['id' => $student->id]);
        $this->assertFalse(source::authorize(['uuid' => $m->uuid,'revision' => 1,'userid' => $student->id])['allowed']);
    }
    public function test_privacy_erasure_cancels_mixed_user_payloads(): void
    {
        global $DB,$CFG;
        [$m,,,,$student] = $this->fixture();
        package::export($m);
        $contexts = \local_examsync\privacy\provider::get_contexts_for_userid($student->id);
        $ctx = \context_course::instance($m->courseid);
        $this->assertContains((int)$ctx->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($student, 'local_examsync', [$ctx->id]);
        \local_examsync\privacy\provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('local_examsync_member', ['mapid' => $m->id,'userid' => $student->id]));
        $this->assertDirectoryDoesNotExist($CFG->dataroot.'/local_examsync/'.$m->uuid);
        $this->assertSame('privacyblocked', $DB->get_field('local_examsync_map', 'state', ['id' => $m->id]));
    }
}
