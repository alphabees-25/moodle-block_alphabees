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

use block_alphabees\external\get_start_token;
use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\start_token::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_start_token::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\output\mobile::class)]
/**
 * Tests for the widget's signed start token.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\start_token
 * @covers    \block_alphabees\external\get_start_token
 * @covers    \block_alphabees\output\mobile
 */
final class start_token_test extends \externallib_advanced_testcase {
    /**
     * A course with one visible and one hidden page, and a learner in it.
     *
     * @return array{course: \stdClass, user: \stdClass, page: \stdClass, hidden: \stdClass}
     */
    private function fixture(): array {
        site_registry::ensure_keypair();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $page = $generator->create_module('page', ['course' => $course->id]);
        $hidden = $generator->create_module('page', ['course' => $course->id, 'visible' => 0]);
        return ['course' => $course, 'user' => $user, 'page' => $page, 'hidden' => $hidden];
    }

    public function test_token_names_the_learner_and_the_page(): void {
        $this->resetAfterTest();
        $f = $this->fixture();

        $issued = start_token::issue((int)$f['user']->id, (int)$f['course']->id, (int)$f['page']->cmid, 'uuid-1', 'abc');
        $payload = start_token::decode_own($issued['token']);

        $this->assertSame('moodle_start', $payload['typ']);
        $this->assertSame(site_registry::site_identifier(), $payload['site']);
        $this->assertSame(site_registry::key_id(), $payload['kid']);
        $this->assertSame((int)$f['user']->id, $payload['uid']);
        $this->assertSame((int)$f['course']->id, $payload['course']);
        $this->assertSame((int)$f['page']->cmid, $payload['cm']);
        $this->assertSame('uuid-1', $payload['placement']);
        $this->assertSame('abc', $payload['sid']);
        $this->assertSame(start_token::TTL, $payload['exp'] - $payload['iat']);
        $this->assertSame($payload['exp'], $issued['expiresat']);

        $again = start_token::decode_own(start_token::issue((int)$f['user']->id, 0, 0, '')['token']);
        $this->assertNotSame($payload['jti'], $again['jti']);
    }

    public function test_tampered_token_does_not_verify(): void {
        $this->resetAfterTest();
        $f = $this->fixture();
        [$v, $body, $sig] = explode('.', start_token::issue((int)$f['user']->id, 0, 0, '')['token']);

        $claims = json_decode(crypto::base64url_decode($body), true);
        $claims['uid'] = 2;
        $forged = $v . '.' . crypto::base64url_encode(json_encode($claims)) . '.' . $sig;
        $this->assertNull(start_token::decode_own($forged));
    }

    public function test_scope_the_learner_cannot_open_is_left_out(): void {
        $this->resetAfterTest();
        $f = $this->fixture();
        $other = $this->getDataGenerator()->create_course();
        $othercm = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $uid = (int)$f['user']->id;

        $scope = function (array $issued): array {
            $payload = start_token::decode_own($issued['token']);
            return [$payload['course'], $payload['cm']];
        };
        // Hidden activity, a course without enrolment, an activity of another course.
        $this->assertSame([0, 0], $scope(start_token::issue($uid, (int)$f['course']->id, (int)$f['hidden']->cmid, '')));
        $this->assertSame([0, 0], $scope(start_token::issue($uid, (int)$other->id, 0, '')));
        $this->assertSame([0, 0], $scope(start_token::issue($uid, (int)$f['course']->id, (int)$othercm->cmid, '')));
        $this->assertSame([0, 0], $scope(start_token::issue($uid, 999999, 0, '')));
        // The activity alone is enough to find its course.
        $this->assertSame(
            [(int)$f['course']->id, (int)$f['page']->cmid],
            $scope(start_token::issue($uid, 0, (int)$f['page']->cmid, ''))
        );
        $this->assertSame([(int)SITEID, 0], $scope(start_token::issue($uid, SITEID, 0, '')));
    }

    public function test_no_token_for_guests_or_an_unregistered_site(): void {
        $this->resetAfterTest();
        $this->assertNull(start_token::issue((int)guest_user()->id, 0, 0, ''));

        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(start_token::issue((int)$user->id, 0, 0, ''), 'no keypair yet');
        site_registry::ensure_keypair();
        $this->assertNull(start_token::issue(0, 0, 0, ''));
        $this->assertNotNull(start_token::issue((int)$user->id, 0, 0, ''));
    }

    public function test_console_and_start_tokens_cannot_stand_in_for_each_other(): void {
        $this->resetAfterTest();
        $f = $this->fixture();
        $console = console_token::issue((int)$f['user']->id);

        $this->assertSame('console', console_token::decode_own($console)['typ']);
        $this->assertNull(start_token::decode_own($console));
    }

    public function test_refresh_is_for_the_caller_and_rate_limited(): void {
        $this->resetAfterTest();
        $f = $this->fixture();
        $this->setUser($f['user']);

        $call = function () use ($f): array {
            return external_api::clean_returnvalue(
                get_start_token::execute_returns(),
                get_start_token::execute((int)$f['course']->id, (int)$f['page']->cmid, 'not-a-placement')
            );
        };
        $result = $call();
        $payload = start_token::decode_own($result['token']);
        $this->assertSame((int)$f['user']->id, $payload['uid']);
        $this->assertSame((int)$f['page']->cmid, $payload['cm']);
        $this->assertSame('', $payload['placement'], 'unknown placement is not signed');
        $this->assertSame(session_tracker::session_hash(), $payload['sid']);

        for ($i = 1; $i < 20; $i++) {
            $call();
        }
        $this->expectException(\moodle_exception::class);
        $call();
    }

    public function test_refresh_is_reachable_from_the_app_not_by_service_token(): void {
        global $DB;
        $this->resetAfterTest();
        $sql = "SELECT s.shortname
                  FROM {external_services_functions} f
                  JOIN {external_services} s ON s.id = f.externalserviceid
                 WHERE f.functionname = ?";
        $services = $DB->get_fieldset_sql($sql, ['block_alphabees_get_start_token']);
        $this->assertContains(MOODLE_OFFICIAL_MOBILE_SERVICE, $services);
        $this->assertNotContains('block_alphabees', $services);
    }

    public function test_app_view_carries_a_token(): void {
        $this->resetAfterTest();
        $f = $this->fixture();
        $this->setUser($f['user']);

        $html = \block_alphabees\output\mobile::mobile_ws_marker(['courseid' => $f['course']->id])['templates'][0]['html'];
        $this->assertSame(1, preg_match('/data-starttoken="([^"]+)"/', $html, $m));
        $payload = start_token::decode_own(html_entity_decode($m[1]));
        $this->assertSame((int)$f['user']->id, $payload['uid']);
        $this->assertSame('', $payload['sid']);
    }
}
