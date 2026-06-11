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

/**
 * Processes the outbound retry queue.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\site_registry;
use block_alphabees\local\ws_setup;

/**
 * Scheduled task that drains the outbound retry queue.
 */
class process_retry_queue extends \core\task\scheduled_task {

    /** Stop trying after this many attempts. */
    private const MAX_ATTEMPTS = 8;

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_process_retry_queue', 'block_alphabees');
    }

    /**
     * Process up to 50 due retry-queue rows, applying exponential backoff.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $lifecyclerows = $DB->get_records_select(
            'block_alphabees_retryqueue',
            'nextattempt <= ? AND endpoint = ?',
            [$now, site_registry::path_lifecycle()],
            'nextattempt ASC',
            '*',
            0,
            50
        );
        $normalrows = $DB->get_records_select(
            'block_alphabees_retryqueue',
            'nextattempt <= ? AND endpoint <> ?',
            [$now, site_registry::path_lifecycle()],
            'nextattempt ASC',
            '*',
            0,
            50
        );
        $rows = $lifecyclerows + $normalrows;

        $blocked = site_registry::is_registration_blocked();
        $registered = site_registry::is_registered();
        $syncpaused = site_registry::is_sync_paused();
        $reportedregistrationskip = false;
        $reportedsyncpaused = false;

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload)) {
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                continue;
            }

            $islifecycle = self::is_lifecycle_request((string)$row->endpoint, $payload);
            if ($islifecycle && self::is_pause_lifecycle($payload)) {
                $payload += [
                    'api_key_present' => site_registry::api_key_present(),
                    'api_key_status' => site_registry::api_key_status(),
                ];
            }
            if ($islifecycle && self::is_resume_lifecycle($payload) && !self::can_resume_site()) {
                $DB->update_record('block_alphabees_retryqueue', (object)[
                    'id' => $row->id,
                    'nextattempt' => time() + 300,
                    'lasterror' => 'resume_requires_valid_api_key',
                ]);
                continue;
            }
            if (!$islifecycle && ($blocked || !$registered)) {
                if (!$reportedregistrationskip) {
                    mtrace('[block_alphabees] retry: site is not registered or registration is blocked, '
                        . 'skipping non-lifecycle rows.');
                    $reportedregistrationskip = true;
                }
                continue;
            }
            if (!$islifecycle && $syncpaused) {
                if (!$reportedsyncpaused) {
                    mtrace('[block_alphabees] retry: sync paused, skipping non-lifecycle rows.');
                    $reportedsyncpaused = true;
                }
                continue;
            }

            $result = backend_client::post((string)$row->endpoint, $payload);
            if ($result['status'] === backend_client::STATUS_OK) {
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                if ($islifecycle) {
                    self::handle_lifecycle_success($payload);
                }
                continue;
            }

            $attempts = (int)$row->attempts + 1;
            $httpcode = (int)($result['httpcode'] ?? 0);
            if ($result['status'] === backend_client::STATUS_TRANSIENT
                && !empty($result['ignored'])
                && ($result['health_status'] ?? null) === 'paused') {
                site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
            }
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                mtrace('[block_alphabees] retry: site requires reconnect, dropping row.');
                return;
            }
            if ($result['status'] === backend_client::STATUS_ERROR || $attempts >= self::MAX_ATTEMPTS) {
                // 4xx → won't ever succeed without intervention; or exhausted.
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                mtrace('[block_alphabees] retry: dropping ' . $row->endpoint . ' after ' . $attempts . ' attempts.');
                continue;
            }

            // Exponential backoff: 30s, 60s, 2m, 4m, 8m, 16m, 32m, 64m.
            $delay = 30 * (2 ** ($attempts - 1));
            $DB->update_record('block_alphabees_retryqueue', (object)[
                'id' => $row->id,
                'attempts' => $attempts,
                'nextattempt' => $now + $delay,
                'lasterror' => substr((string)($result['error'] ?? 'unknown'), 0, 250),
            ]);
        }
    }

    /**
     * Whether a retry row targets the lifecycle endpoint.
     *
     * @param string $endpoint
     * @param array $payload
     * @return bool
     */
    private static function is_lifecycle_request(string $endpoint, array $payload): bool {
        return $endpoint === site_registry::path_lifecycle()
            && isset($payload['event_type'])
            && in_array((string)$payload['event_type'], [
                'site.paused',
                'site.resumed',
                'site.disconnected',
            ], true);
    }

    /**
     * Whether a lifecycle payload is a resume request.
     *
     * @param array $payload
     * @return bool
     */
    private static function is_resume_lifecycle(array $payload): bool {
        return isset($payload['event_type']) && (string)$payload['event_type'] === 'site.resumed';
    }

    /**
     * Whether a lifecycle payload is a pause request.
     *
     * @param array $payload
     * @return bool
     */
    private static function is_pause_lifecycle(array $payload): bool {
        return isset($payload['event_type']) && (string)$payload['event_type'] === 'site.paused';
    }

    /**
     * Whether resume may be sent for the current saved API key.
     *
     * @return bool
     */
    private static function can_resume_site(): bool {
        return site_registry::api_key_present()
            && !site_registry::is_registration_blocked();
    }

    /**
     * Apply local follow-up behaviour after the backend accepted a lifecycle event.
     *
     * @param array $payload
     * @return void
     */
    private static function handle_lifecycle_success(array $payload): void {
        $eventtype = isset($payload['event_type']) ? (string)$payload['event_type'] : '';
        if ($eventtype !== 'site.resumed') {
            return;
        }

        site_registry::resume_syncs();
        try {
            \block_alphabees\local\connection_manager::activate_defaults();
        } catch (\Throwable $e) {
            ws_setup::record_token_post_status('error', $e->getMessage());
            mtrace('[block_alphabees] retry: WS token refresh after resume failed: '
                . $e->getMessage());
        }
    }
}
