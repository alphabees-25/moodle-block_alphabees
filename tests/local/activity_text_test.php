<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_alphabees\local;

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\activity_text::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\inbound_dispatcher::class)]
/**
 * Tests for reading the text of the open activity.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\activity_text
 * @covers    \block_alphabees\local\inbound_dispatcher
 */
final class activity_text_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $learner;

    /** @var \stdClass */
    private \stdClass $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        // The lesson and H5P generators need someone signed in.
        $this->setAdminUser();
    }

    /**
     * Call the action as the signed channel does.
     *
     * @param int $cmid
     * @param array $extra
     * @param \stdClass|null $user Defaults to the learner.
     * @return array{0: int, 1: array}
     */
    private function call(int $cmid, array $extra = [], ?\stdClass $user = null): array {
        $params = ['cmid' => $cmid, 'userid' => ($user ?? $this->learner)->id] + $extra;
        $result = inbound_dispatcher::execute(caller::site(), 'get_activity_text', $params);
        return [$result['httpStatus'], $result['body']];
    }

    public function test_a_text_page_with_a_table(): void {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'name' => 'Modelle',
            'content' => '<p>Zwei Modelle:</p><table><tr><th>Modell</th><th>Jahr</th></tr>'
                . '<tr><td>St. Gallen</td><td>1972</td></tr></table>',
        ]);
        [$status, $body] = $this->call($page->cmid);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame(['page', 'Modelle'], [$body['modname'], $body['name']]);
        $this->assertStringContainsString('Zwei Modelle:', $body['content_text']);
        $this->assertStringContainsString("| Modell | Jahr |\n| --- | --- |\n| St. Gallen | 1972 |", $body['content_text']);
        $this->assertFalse($body['truncated']);

        [, $body] = $this->call($page->cmid, ['max_chars' => 5]);
        $this->assertTrue($body['truncated']);
        $this->assertSame(5, \core_text::strlen($body['content_text']));
    }

    public function test_a_lesson_stops_at_an_unanswered_question(): void {
        global $DB;
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $this->course->id, 'name' => 'Systemtheorie']);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        // The generator puts every new page first, so they are created back to front.
        $behind = $generator->create_content($lesson, ['title' => 'Vertiefung',
            'contents_editor' => ['text' => '<p>Zweite Seite</p>', 'format' => FORMAT_HTML, 'itemid' => 0]]);

        $question = $generator->create_question_truefalse($lesson);
        $first = $generator->create_content($lesson, ['title' => 'Einstieg', 'contents_editor' => ['text' => '<p>Erste Seite</p>',
            'format' => FORMAT_HTML, 'itemid' => 0]]);

        [$status, $body] = $this->call($lesson->cmid);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('lesson', $body['part']['kind']);
        $this->assertStringContainsString('Erste Seite', $body['content_text']);
        $this->assertStringContainsString("## Einstieg\n\nErste Seite", $body['content_text']);
        $this->assertStringNotContainsString('Zweite Seite', $body['content_text']);

        [$status, $body] = $this->call($lesson->cmid, ['pageid' => $first->id]);
        $this->assertSame(['lesson_page', (int)$first->id, 'Einstieg'], array_values($body['part']));
        [$status, $body] = $this->call($lesson->cmid, ['pageid' => $behind->id]);
        $this->assertSame([200, false, 'not_visible_for_user'], [$status, $body['ok'], $body['error']]);
        [, $body] = $this->call($lesson->cmid, ['pageid' => $question->id]);
        $this->assertSame('not_supported', $body['error']);

        // Answered: the page behind it is reached. The teacher reads everything anyway.
        $DB->insert_record('lesson_attempts', (object)['lessonid' => $lesson->id, 'pageid' => $question->id,
            'userid' => $this->learner->id, 'answerid' => 0, 'retry' => 0, 'correct' => 1, 'useranswer' => '1',
            'timeseen' => time()]);
        [, $body] = $this->call($lesson->cmid);
        $this->assertStringContainsString('Zweite Seite', $body['content_text']);
        $DB->delete_records('lesson_attempts');
        [, $body] = $this->call($lesson->cmid, [], $this->teacher);
        $this->assertStringContainsString('Zweite Seite', $body['content_text']);

        // A password is a gate that stays shut.
        $DB->set_field('lesson', 'usepassword', 1, ['id' => $lesson->id]);
        [, $body] = $this->call($lesson->cmid);
        $this->assertSame('not_visible_for_user', $body['error']);
    }

    public function test_a_book_without_its_hidden_chapters(): void {
        $book = $this->getDataGenerator()->create_module('book', ['course' => $this->course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $open = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Kapitel 1', 'content' => '<p>Sichtbar</p>']);
        $hidden = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Kapitel 2', 'content' => '<p>Versteckt</p>',
            'hidden' => 1]);

        [$status, $body] = $this->call($book->cmid);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertStringContainsString('Sichtbar', $body['content_text']);
        $this->assertStringNotContainsString('Versteckt', $body['content_text']);

        [, $body] = $this->call($book->cmid, ['chapterid' => $open->id]);
        $this->assertSame('book_chapter', $body['part']['kind']);
        [, $body] = $this->call($book->cmid, ['chapterid' => $hidden->id]);
        $this->assertSame('not_visible_for_user', $body['error']);
        [, $body] = $this->call($book->cmid, ['chapterid' => $hidden->id], $this->teacher);
        $this->assertStringContainsString('Versteckt', $body['content_text']);
    }

    public function test_refusals_carry_a_code_never_an_empty_text(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $hidden = $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Geheim', 'visible' => 0]);
        [$status, $body] = $this->call($hidden->cmid);
        $this->assertSame([200, false, 'not_visible_for_user'], [$status, $body['ok'], $body['error']]);
        $this->assertArrayNotHasKey('name', $body);
        $this->assertArrayNotHasKey('modname', $body);

        $h5p = $generator->create_module('h5pactivity', ['course' => $this->course->id]);
        [, $body] = $this->call($h5p->cmid);
        $this->assertSame(['rendered_only', 'h5pactivity', false], [$body['error'], $body['modname'], $body['retryable']]);

        $forum = $generator->create_module('forum', ['course' => $this->course->id]);
        $this->assertSame('not_supported', $this->call($forum->cmid)[1]['error']);

        $empty = $generator->create_module('url', ['course' => $this->course->id]);
        // The generator fills an empty description with its own text.
        $DB->set_field('url', 'intro', '', ['id' => $empty->id]);
        $this->assertSame('empty', $this->call($empty->cmid)[1]['error']);

        $this->assertSame([404, 'module_not_found'], [$this->call(999999)[0], $this->call(999999)[1]['error']]);
        $this->assertSame(400, inbound_dispatcher::execute(caller::site(), 'get_activity_text', ['cmid' => 5])['httpStatus']);
    }

    public function test_a_quiz_gives_its_description_and_nothing_during_an_attempt(): void {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'intro' => '<p>Zehn Fragen.</p>']);
        [$status, $body] = $this->call($quiz->cmid);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('description', $body['part']['kind']);
        $this->assertStringContainsString('Zehn Fragen.', $body['content_text']);

        $DB->insert_record('quiz_attempts', (object)['quiz' => $quiz->id, 'userid' => $this->learner->id, 'attempt' => 1,
            'uniqueid' => 987654, 'layout' => '', 'state' => 'inprogress', 'timestart' => time()]);
        $this->assertSame('assessment_running', $this->call($quiz->cmid)[1]['error']);
    }
}
