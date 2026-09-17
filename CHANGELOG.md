# Changelog

All notable changes to the Alphabees Moodle block are recorded here. Versions
follow the plugin's `release` string in `version.php`.

This file starts at 3.1.0, the first release published after 3.0.3.

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

