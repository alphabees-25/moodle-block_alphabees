# Privacy Policy for the Alphabees Moodle Plugin

## Introduction

The **Alphabees Moodle Plugin** (`block_alphabees`) adds the Alphabees AI tutor
to Moodle. To answer learners in the context of their course, the plugin
exchanges data with the Alphabees backend. This document lists what that data
is, when it leaves Moodle, and how it is protected.

The same list is part of the plugin's privacy declaration in Moodle
(*Site administration › Users › Privacy and policies › Plugin privacy registry*).

---

## What the plugin sends to Alphabees

Nothing is sent until an administrator enters an Alphabees API key.

**When the site connects**

- The site's name and address, the Moodle and plugin version, and the email
  address of the main administrator.

**When a learner uses the tutor**

- The Moodle user ID, so a conversation is recognised across sessions.
- The course, section and placement the tutor is used in, including the course
  name.
- The content of the messages exchanged with the tutor.
- The learner's first name — only if the site has switched on personal address
  in the plugin settings (off by default). Never the last name, email address
  or username.

**As events happen**

- Activity completion changes (complete, passed, failed, overridden by a
  teacher), with the user ID, course and activity.
- When a learner who used the tutor logs out: the time and a one-way hash of
  that browser session's key, so the tutor session ends with the Moodle session.

**Through the web-service connection**

Connecting creates a dedicated service account and a web-service token with
which Alphabees reads course data to prepare the tutor. The token is limited to
the functions of the plugin's own service. Besides course content, it can read:

- course participants' names, email addresses, enrolments and groups;
- course completion, progress and grades, as learners see them in their own
  reports;
- learners' contributions in activities: forum posts, assignment and quiz
  attempts with their grades, database, wiki and glossary entries, and
  calendar events;
- which activities and sections a learner cannot open. The access rules and
  profile values themselves are not sent.

Texts that Moodle filters personalise (for example templates that insert a
name) are rendered as a neutral viewer, so they carry no one's personal data.

---

## What the plugin stores in Moodle

- Replay-protection nonces and outbound messages awaiting retry.
- For sessions that loaded the tutor: two one-way hashes and a timestamp, no
  user ID. Removed at logout, or after the session's timeout.
- Completion changes waiting to be sent, removed once sent and after three days
  at the latest.

---

## Data storage and transmission

- **Encryption**: Data in transit is encrypted with **TLS 1.2+**. Data at rest
  is encrypted with **AES-256**. Every request between Moodle and Alphabees is
  signed (Ed25519), in both directions.
- **Server locations**: Our servers are hosted by **Hetzner Online GmbH** in Nuremberg
  and operate exclusively within German or European data centers.
- **No AI training**: Client data, including chat interactions and uploaded
  files, is not used to train AI models.
- **Data processing agreements**: We maintain data processing agreements with
  Hetzner Online GmbH to ensure compliance with GDPR and other applicable regulations.

---

## Control for site administrators

- The plugin sends nothing before an administrator enters an API key.
  Disconnecting revokes the web-service token and ends the signed connection;
  the chat widget itself runs on the API key, so removing the key or the
  blocks stops the tutor.
- Sending the first name is off unless switched on.
- Institutions remain responsible for informing their users and for the legal
  basis of the processing. A data processing agreement with Alphabees is
  available on request.

---

## Contact

For privacy-related inquiries or concerns, please contact us at:

- **Email**: [support@alphabees.de](mailto:support@alphabees.de)
