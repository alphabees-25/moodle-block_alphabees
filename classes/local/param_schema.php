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

/**
 * The accepted shape of the inbound write parameters, declared once.
 *
 * A real Moodle web service carries external_function_parameters: types,
 * required flags and defaults that Moodle enforces and publishes. This channel
 * is signed rather than tokenised, so it has none of that, and the parameter
 * list lived as a hand-written allowlist inside the dispatcher. Twice that list
 * was wrong, and both times it failed in the worst available way — a parameter
 * the caller had sent was dropped without a word, so a module was created with
 * its fields unset, or refused for a field that was in fact present.
 *
 * This class is the replacement: one declaration that the mapper reads, that
 * validation reads, and that describe_actions publishes. Adding a parameter is
 * one edit here instead of two in separate files, and a caller can ask the site
 * what it accepts instead of guessing the spelling.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class param_schema {
    /** @var string Marks a parameter every module type accepts. */
    public const ANY_MODNAME = '*';

    /**
     * Spellings of the course id. Accepted per entry and once per batch.
     *
     * @var string[]
     */
    public const COURSE_ALIASES = ['course_id', 'courseId', 'courseid', 'course'];

    /**
     * Every module parameter: internal key => how it is spelled, what it holds,
     * which module types read it, and what it is for.
     *
     * 'accepts' is ordered by preference; the first spelling present wins.
     * 'modnames' is either ANY_MODNAME or the list of types that read it — it
     * documents intent and drives the warning about a parameter sent to a type
     * that will ignore it.
     *
     * @var array<string, array>
     */
    public const MODULE_PARAMS = [
        'cmid' => [
            'accepts' => ['cmid'],
            'type' => 'int',
            'modnames' => '*',
            'about' => 'Existing course module id. Present means update, absent means create.',
        ],
        'section' => [
            'accepts' => ['section', 'sectionnum', 'section_num', 'sectionNum'],
            'type' => 'int',
            'modnames' => '*',
            'about' => 'Section number, 0-based. Defaults to 0.',
        ],
        'modname' => [
            'accepts' => ['modname'],
            'type' => 'string',
            'modnames' => '*',
            'about' => 'Activity type. Required to create.',
        ],
        'name' => [
            'accepts' => ['name'],
            'type' => 'string',
            'modnames' => '*',
            'about' => 'Activity name shown in the course.',
        ],
        'intro' => [
            'accepts' => ['intro'],
            'type' => 'html',
            'modnames' => '*',
            'about' => 'Description shown under the activity.',
        ],
        'introformat' => [
            'accepts' => ['intro_format', 'introFormat'],
            'type' => 'int',
            'modnames' => '*',
            'about' => 'Format of intro. 1 = HTML.',
        ],
        'visible' => [
            'accepts' => ['visible'],
            'type' => 'bool',
            'modnames' => '*',
            'about' => 'Whether learners see the activity.',
        ],
        'showdescription' => [
            'accepts' => ['show_description', 'showDescription'],
            'type' => 'bool',
            'modnames' => '*',
            'about' => 'Show the description on the course page.',
        ],
        'display' => [
            'accepts' => ['display'],
            'type' => 'int',
            'modnames' => ['page', 'url', 'folder', 'resource'],
            'about' => 'How the content opens. See RESOURCELIB_DISPLAY_*.',
        ],
        'grade' => [
            'accepts' => ['grade'],
            'type' => 'int',
            'modnames' => ['h5pactivity', 'assign', 'quiz'],
            'about' => 'Maximum grade.',
        ],
        'grademethod' => [
            'accepts' => ['grademethod', 'gradeMethod'],
            'type' => 'int',
            'modnames' => ['h5pactivity', 'quiz'],
            'about' => 'How repeated attempts are graded.',
        ],
        'files_itemid' => [
            'accepts' => ['files_itemid', 'filesItemId'],
            'type' => 'itemid',
            'modnames' => ['folder', 'resource', 'h5pactivity'],
            'about' => 'Draft item id from upload_file.',
        ],
        'content' => [
            'accepts' => ['content'],
            'type' => 'html',
            'modnames' => ['page'],
            'about' => 'Page body.',
        ],
        'contentformat' => [
            'accepts' => ['content_format', 'contentFormat'],
            'type' => 'int',
            'modnames' => ['page'],
            'about' => 'Format of content. 1 = HTML.',
        ],
        'externalurl' => [
            'accepts' => ['external_url', 'externalUrl'],
            'type' => 'string',
            'modnames' => ['url'],
            'about' => 'Target address.',
        ],
        'showexpanded' => [
            'accepts' => ['show_expanded', 'showExpanded'],
            'type' => 'bool',
            'modnames' => ['folder'],
            'about' => 'Show the folder expanded on the course page.',
        ],
        'displayoptions' => [
            'accepts' => ['displayoptions', 'displayOptions'],
            'type' => 'int',
            'modnames' => ['h5pactivity'],
            'about' => 'H5P display option bitmask.',
        ],
        'enabletracking' => [
            'accepts' => ['enabletracking', 'enableTracking'],
            'type' => 'bool',
            'modnames' => ['h5pactivity'],
            'about' => 'Record learner attempts.',
        ],
        'reviewmode' => [
            'accepts' => ['reviewmode', 'reviewMode'],
            'type' => 'int',
            'modnames' => ['h5pactivity'],
            'about' => 'What the learner may review afterwards.',
        ],
        'duedate' => [
            'accepts' => ['duedate', 'dueDate'],
            'type' => 'timestamp',
            'modnames' => ['assign'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'allowsubmissionsfromdate' => [
            'accepts' => ['allowsubmissionsfromdate', 'allowSubmissionsFromDate'],
            'type' => 'timestamp',
            'modnames' => ['assign'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'cutoffdate' => [
            'accepts' => ['cutoffdate', 'cutoffDate'],
            'type' => 'timestamp',
            'modnames' => ['assign'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'gradingduedate' => [
            'accepts' => ['gradingduedate', 'gradingDueDate'],
            'type' => 'timestamp',
            'modnames' => ['assign'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'submissiondrafts' => [
            'accepts' => ['submissiondrafts', 'submissionDrafts'],
            'type' => 'bool',
            'modnames' => ['assign'],
            'about' => 'Let learners keep a draft before submitting.',
        ],
        'submission_onlinetext' => [
            'accepts' => ['submission_onlinetext', 'submissionOnlinetext'],
            'type' => 'bool',
            'modnames' => ['assign'],
            'about' => 'Accept typed text. Default on.',
        ],
        'submission_file' => [
            'accepts' => ['submission_file', 'submissionFile'],
            'type' => 'bool',
            'modnames' => ['assign'],
            'about' => 'Accept file uploads. Default off.',
        ],
        'submission_file_maxfiles' => [
            'accepts' => ['submission_file_maxfiles', 'submissionFileMaxfiles'],
            'type' => 'int',
            'modnames' => ['assign'],
            'about' => 'Maximum uploaded files.',
        ],
        'numbering' => [
            'accepts' => ['numbering'],
            'type' => 'int',
            'modnames' => ['book'],
            'about' => 'Chapter numbering style.',
        ],
        'navstyle' => [
            'accepts' => ['navstyle', 'navStyle'],
            'type' => 'int',
            'modnames' => ['book'],
            'about' => 'Navigation style.',
        ],
        'customtitles' => [
            'accepts' => ['customtitles', 'customTitles'],
            'type' => 'bool',
            'modnames' => ['book'],
            'about' => 'Chapters carry their own titles.',
        ],
        'chapters' => [
            'accepts' => ['chapters'],
            'type' => 'list',
            'modnames' => ['book'],
            'about' => '[{title, content, subchapter}] written in the same transaction.',
        ],
        'forumtype' => [
            'accepts' => ['forumtype', 'forumType'],
            'type' => 'string',
            'modnames' => ['forum'],
            'about' => 'general | qanda | eachuser | single | news.',
        ],
        'forcesubscribe' => [
            'accepts' => ['forcesubscribe', 'forceSubscribe'],
            'type' => 'int',
            'modnames' => ['forum'],
            'about' => 'Subscription mode.',
        ],
        'discussions' => [
            'accepts' => ['discussions'],
            'type' => 'list',
            'modnames' => ['forum'],
            'about' => '[{subject, message}] posted as the acting user.',
        ],
        'displayformat' => [
            'accepts' => ['displayformat', 'displayFormat'],
            'type' => 'string',
            'modnames' => ['glossary'],
            'about' => 'Entry display format, e.g. dictionary.',
        ],
        'entries' => [
            'accepts' => ['entries'],
            'type' => 'list',
            'modnames' => ['glossary'],
            'about' => '[{concept, definition}].',
        ],
        'timeopen' => [
            'accepts' => ['timeopen', 'timeOpen'],
            'type' => 'timestamp',
            'modnames' => ['quiz'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'timeclose' => [
            'accepts' => ['timeclose', 'timeClose'],
            'type' => 'timestamp',
            'modnames' => ['quiz'],
            'about' => 'Unix timestamp, 0 for none.',
        ],
        'timelimit' => [
            'accepts' => ['timelimit', 'timeLimit'],
            'type' => 'int',
            'modnames' => ['quiz'],
            'about' => 'Seconds, 0 for none.',
        ],
        'preferredbehaviour' => [
            'accepts' => ['preferredbehaviour', 'preferredBehaviour'],
            'type' => 'string',
            'modnames' => ['quiz'],
            'about' => 'Question behaviour, default deferredfeedback.',
        ],
        'attempts' => [
            'accepts' => ['attempts'],
            'type' => 'int',
            'modnames' => ['quiz'],
            'about' => 'Allowed attempts, 0 for unlimited.',
        ],
        'questionsperpage' => [
            'accepts' => ['questionsperpage', 'questionsPerPage'],
            'type' => 'int',
            'modnames' => ['quiz'],
            'about' => 'Questions per page.',
        ],
        'shuffleanswers' => [
            'accepts' => ['shuffleanswers', 'shuffleAnswers'],
            'type' => 'bool',
            'modnames' => ['quiz'],
            'about' => 'Shuffle answers within a question.',
        ],
        'questions' => [
            'accepts' => ['questions'],
            'type' => 'list',
            'modnames' => ['quiz'],
            'about' => '[{qtype, name, questiontext, answers[]}]. multichoice and truefalse.',
        ],
    ];

    /**
     * Resolve a caller's spelling to the internal key, or null if unknown.
     *
     * @param string $given
     * @return string|null
     */
    public static function internal_key(string $given): ?string {
        foreach (self::MODULE_PARAMS as $key => $declaration) {
            if (in_array($given, $declaration['accepts'], true)) {
                return $key;
            }
        }
        if (in_array($given, self::COURSE_ALIASES, true)) {
            return 'course';
        }
        return null;
    }

    /**
     * The module parameters a given type reads, by internal key.
     *
     * @param string $modname
     * @return string[]
     */
    public static function keys_for(string $modname): array {
        $keys = [];
        foreach (self::MODULE_PARAMS as $key => $declaration) {
            $forall = $declaration['modnames'] === self::ANY_MODNAME;
            if ($forall || in_array($modname, $declaration['modnames'], true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * The whole accepted surface, as data a caller can read at runtime.
     *
     * Returned by the describe_actions inbound action, so the generator can
     * check its payload against the site it is actually talking to instead of
     * against documentation that may describe a different release.
     *
     * @return array
     */
    public static function describe(): array {
        $entry = [];
        foreach (self::MODULE_PARAMS as $key => $declaration) {
            $entry[$key] = [
                'accepts' => $declaration['accepts'],
                'type' => $declaration['type'],
                'modnames' => $declaration['modnames'],
                'about' => $declaration['about'],
            ];
        }

        return [
            'upsert_modules' => [
                'about' => 'Create or update activities. 200 when at least one entry was written, '
                    . '422 when none were; every entry carries its own ok flag.',
                'batch' => [
                    'course_id' => [
                        'accepts' => self::COURSE_ALIASES,
                        'type' => 'int',
                        'about' => 'Applies to every entry that does not name its own course.',
                    ],
                    'modules' => [
                        'accepts' => ['modules'],
                        'type' => 'list',
                        'about' => 'The entries to write. Required.',
                    ],
                ],
                'entry' => $entry,
                'required_to_create' => ['course', 'modname'],
                'modnames' => course_writer::SUPPORTED_MODNAMES,
            ],
            'upsert_section' => [
                'about' => 'Set a section name, summary HTML or visibility.',
                'params' => [
                    'course_id' => ['accepts' => self::COURSE_ALIASES, 'type' => 'int'],
                    'section' => [
                        'accepts' => ['section', 'sectionnum', 'section_num', 'sectionNum'],
                        'type' => 'int',
                        'about' => '0-based.',
                    ],
                    'name' => ['accepts' => ['name'], 'type' => 'string'],
                    'summary' => ['accepts' => ['summary'], 'type' => 'html'],
                    'summary_format' => ['accepts' => ['summary_format', 'summaryFormat'], 'type' => 'int'],
                    'visible' => ['accepts' => ['visible'], 'type' => 'bool'],
                ],
                'required' => ['course_id', 'section'],
            ],
            'upload_file' => [
                'about' => 'Put one file into a draft area. Pass the returned itemid as files_itemid.',
                'params' => [
                    'filename' => ['accepts' => ['filename', 'fileName', 'file_name'], 'type' => 'string'],
                    'content' => [
                        'accepts' => ['content', 'content_base64', 'contentBase64'],
                        'type' => 'string',
                        'about' => 'Base64 unless contentisbase64 is false.',
                    ],
                    'itemid' => [
                        'accepts' => ['itemid', 'itemId', 'item_id', 'draftitemid', 'draftItemId', 'draft_item_id'],
                        'type' => 'int',
                        'about' => 'Reuse to collect several files in one draft area.',
                    ],
                    'filepath' => ['accepts' => ['filepath', 'filePath', 'file_path'], 'type' => 'string'],
                    'contentisbase64' => [
                        'accepts' => ['contentisbase64', 'contentIsBase64', 'content_is_base64'],
                        'type' => 'bool',
                    ],
                ],
                'required' => ['filename', 'content'],
            ],
        ];
    }
}
