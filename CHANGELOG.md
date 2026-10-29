# Changelog

All notable changes to the Alphabees Moodle block are recorded here. Versions
follow the plugin's `release` string in `version.php`.

This file starts at 3.1.0, the first release published after 3.0.3.

## 3.2.0 — 2026-10-29

Includes 3.1.3 and 3.1.4, which were never published on their own.

- **Progress as the site measures it.** Sites that track progress with the
  Completion Progress block can now hand Alphabees exactly the numbers the
  block shows each learner — per bar, per activity, respecting groups and
  restrictions — instead of Moodle's course completion.
- **Large courses sync.** Course completion, grades and progress are fetched
  one page of learners at a time; courses with several thousand learners no
  longer time out.
- **Grades as the learner sees them.** Hidden grade items and grades that are
  hidden until a date stay hidden from the tutor too.
- **Course texts without anyone's personal data.** Texts built from filter
  templates (Generico) reach Alphabees rendered as a learner sees them, but
  without the name, address or progress of the account that fetched them.
- **Course fields with their visibility**, so fields meant for teachers do not
  end up in texts for learners.
- **Completions reach Alphabees as they happen.** When a learner completes,
  passes or fails an activity — or a teacher overrides it — the tutor learns of
  it within about a minute instead of the next night.
- **The tutor respects access restrictions.** Alphabees can ask Moodle which
  activities and sections a learner cannot open — hidden, or restricted by a
  profile field, a group, a date or any other condition — so the tutor no
  longer answers from material the learner is not allowed to see.
- **Alphabees can read the shape of every web-service function it uses**
  (parameters, return values, descriptions), as this site's Moodle version and
  plugins define them.
- **The tutor knows the course by name.** The widget now receives the
  course's full and short name next to its id, as learners read them, and is
  told explicitly when a learner moves to a different course.
- The plugin now reports its version code and its features to Alphabees.
- **Moodle 4.2 is now the minimum.** On 4.1 the connection to Alphabees could
  not be set up at all (since 3.0.2); the plugin now says so at install time
  instead of failing when connecting.
- **The privacy declaration is complete.** It now also names what the
  web-service connection lets Alphabees read — course participants' names,
  email addresses, enrolments and groups, and learners' contributions in
  activities — and the administrator's email address sent when connecting.
  `PRIVACY.md` is rewritten to match.

## 3.1.4 — 2026-10-16

- **The tutor knows when a learner logs out.** Logging out of Moodle now ends
  that person's tutor session in Alphabees as well, instead of leaving it to
  expire. Only sessions in which the tutor was actually used are reported.
- **Generico templates are visible to the portal.** Alphabees can now read the
  Generico filter templates a site has set up — their names, the values each
  one takes and its defaults — so generated content can use them.

## 3.1.3 — 2026-10-06

- **Moodle 5.3 is supported.** Moodle 5.3 refuses the plugin's service account
  unless the "Web services authentication" plugin is enabled, so connecting
  failed there and a connected site stopped syncing once Moodle itself was
  upgraded. The plugin now enables it where Moodle requires it — when
  connecting, when upgrading, and within the hour after a Moodle upgrade.
- Sites with required custom profile fields sync again. Moodle refused every
  request from the service account because that account had no value for those
  fields, while the connection still showed as healthy. The plugin now fills
  them in itself — when connecting, when upgrading, and within the hour when
  such a field is added later.
- Connecting now tests a real data request, not just the token, and names the
  profile field to complete if one could not be filled automatically.
- Assignments created from the portal keep their activity instructions. They
  were accepted and then discarded.

## 3.1.2 — 2026-09-17

- Pages generated in the portal now carry their body text. The page was
  created, the text was discarded, and Moodle showed an empty page.
- Images inside a generated page body are stored with the page instead of
  disappearing later.
- Assignments can carry activity instructions alongside the description.

## 3.1.1 — 2026-09-16

Courses generated in the Alphabees portal now arrive in Moodle complete.

- Quizzes and assignments are created. Both were missing before.
- Generated quizzes follow the site's own quiz defaults, instead of showing
  learners nothing after an attempt.
- Books, forums, glossaries and files import reliably.
- A failed import now says why.

## 3.1.0 — 2026-09-15

**Teachers can now answer learners directly inside Moodle.**

- **Teacher console.** A new page in Moodle showing the questions learners
  asked the AI tutor, with the learner's progress alongside, so a teacher can
  step in and reply. No Alphabees portal login — Moodle roles decide who gets
  in. Opens from a course's *More* menu or straight from a notification.
- **Moodle notifications.** Teachers are alerted through the bell, by email and
  in the Moodle app when a learner is waiting for a human. Learners are
  notified when a teacher sends them an exercise or answers their question, and
  land back in the tutor on the right item. Everyone keeps Moodle's usual
  per-channel notification settings.
- **Rename the block, replace its texts.** The tutor block can carry your own
  name — site-wide or differently per course — and the info texts learners see
  can be replaced. Both on their own settings page; leave a field empty and the
  built-in English or German wording applies, following each user's language.
- **More activity types from the portal.** Generated courses can now contain
  assignments, books, forums, glossaries, H5P activities and quizzes, not only
  pages, links and files.
- **Richer course knowledge.** The tutor can read quiz questions and a wider
  range of activity content, and can mark activities complete for a learner.
- **Optional personal address.** A new site setting lets the tutor greet
  learners by first name. Off by default; no other personal data is sent.

### Fixed

- Web-service access no longer fails on sites where no role grants the REST
  protocol capability — the plugin's own service role now carries it.

**After updating:** confirm the plugin upgrade in Moodle. The console
permissions are created during that upgrade and reach teachers, editing
teachers and managers automatically — no role configuration needed.

