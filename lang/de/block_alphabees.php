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
 * German strings for Alphabees block.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_courseid_required'] = 'Dieser Vorgang braucht einen Kurs, in dem er ausgeführt wird.';
$string['action_not_permitted'] = 'Dieser Vorgang steht Ihnen hier nicht zur Verfügung ({$a}).';
$string['allow_remote_placement'] = 'Remote-Erstellung von Platzierungen erlauben';
$string['allow_remote_placement_desc'] = 'Wenn aktiviert, kann das Alphabees-Portal autonom neue Alphabees-Bloecke in Kurse auf dieser Site einfuegen (z.B. Tutor-Rollout ueber eine ganze Fachgruppe). Das Aktualisieren oder Entfernen bestehender Platzierungen funktioniert auch ohne diese Einstellung — nur die autonome Erstellung ist gegated. Standardmaessig aktiviert.';
$string['allow_remote_placement_short'] = 'Erlaubt dem Portal, autonom neue Tutor-Blöcke auf dieser Site zu platzieren. Standard: an.';
$string['alphabees:addinstance'] = 'Fügen Sie einen neuen Alphabees KI Tutor-Block hinzu';
$string['alphabees:myaddinstance'] = 'Fügen Sie einen neuen Alphabees KI Tutor-Block zu meiner Moodle-Seite hinzu';
$string['alphabees:receivelearnernotifications'] = 'Alphabees-Benachrichtigungen erhalten, die an Sie als lernende Person gerichtet sind';
$string['alphabees:receivenotifications'] = 'Alphabees-Benachrichtigungen für einen Kurs erhalten';
$string['alphabees:useagent'] = 'Den Alphabees-Assistenten nutzen, um Aufgaben in Moodle auszuführen';
$string['alphabees:useconsole'] = 'Die Alphabees-Console für einen Kurs öffnen';
$string['alphabees:usewebservice'] = 'Alphabees-Web-Services-Integration verwenden';
$string['alphabees:view'] = 'Zeigen Sie den Alphabees KI Tutor-Block an';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Geben Sie den API Key ein, um auf den Alphabees-Dienst zuzugreifen. Erhältlich auf portal.alphalearn.ai. Nach dem Speichern öffnen Sie die Alphabees-Einstellungen erneut und prüfen dort Status & Diagnose. Falls der Verbindungsstatus nicht Verbunden ist, klicken Sie einmal auf Jetzt verbinden.';
$string['apikeymissing'] = 'API Key fehlt. Bitte konfigurieren Sie die Plug-in-Einstellungen.';
$string['blocksettings'] = 'Blockeinstellungen';
$string['blocktitle'] = 'Alphabees';
$string['blocktitle_instance'] = 'Name dieses Blocks';
$string['blocktitle_instance_help'] = 'Die Überschrift, die diese Platzierung Lernenden anzeigt. Leer lassen, um den site-weiten Namen aus den Alphabees-Einstellungen zu verwenden; der greift wiederum auf den Plugin-Namen zurück.';
$string['botid'] = 'KI-Tutor-ID';
$string['botlist_unavailable_help'] = 'Die Liste der verfügbaren KI-Tutoren konnte nicht geladen werden. '
    . 'Bitte prüfen Sie die Alphabees-Verbindung unter Status & Diagnose und versuchen Sie es erneut.';
$string['brandheader_tagline'] = 'KI-Tutor für Ihre Moodle-Kurse';
$string['brandheader_version'] = 'Plugin-Version {$a}';
$string['connect_apikey_missing'] = 'Bitte zuerst einen API-Key speichern und danach erneut auf Jetzt verbinden klicken.';
$string['connect_failed'] = 'Verbindung zum Alphabees-Backend fehlgeschlagen. Details siehe Verbindungsstatus.';
$string['connect_failed_with_reason'] = 'Verbindung zum Alphabees-Backend fehlgeschlagen: {$a}';
$string['connect_now'] = 'Jetzt verbinden';
$string['connect_now_help'] = 'Registriert diese Moodle-Site sofort beim Alphabees-Backend. Nutzen Sie dies nach dem Speichern eines API-Keys oder nach Behebung eines Verbindungsproblems.';
$string['connect_pending'] = 'Alphabees-Backend ist gerade nicht erreichbar. Prüfen Sie den Verbindungsstatus und klicken Sie danach erneut auf Jetzt verbinden.';
$string['connect_success'] = 'Mit dem Alphabees-Backend verbunden.';
$string['connectionstatus'] = 'Verbindungsstatus';
$string['connectionstatus_apikey_help'] = 'Ihr Alphabees-API-Key. Erhältlich auf portal.alphalearn.ai. Speichern legt den Key ab; Jetzt verbinden führt die Registrierung aus.';
$string['connectionstatus_apikey_missing_help'] = 'Diese Site ist getrennt und es ist kein API-Key gespeichert. Speichern Sie zuerst einen gültigen Alphabees-API-Key und prüfen Sie danach diesen Statusbereich. Falls dort nicht Verbunden steht, klicken Sie einmal auf Jetzt verbinden.';
$string['connectionstatus_blocked_help'] = 'Alphabees hat den aktuellen API-Key dauerhaft abgelehnt. Moodle führt keine weiteren Alphabees-Backend-Aufrufe aus, bis ein anderer API-Key gespeichert wird.';
$string['connectionstatus_connect_required_help'] = 'Die Einstellungen sind gespeichert, aber diese Site ist noch nicht mit Alphabees verbunden. Klicken Sie einmal auf Jetzt verbinden, um sie zu registrieren, und prüfen Sie danach, ob der Status auf Verbunden wechselt.';
$string['connectionstatus_intro'] = 'Speichern Sie einen API-Key und klicken Sie danach auf Jetzt verbinden, um diese Moodle-Site beim Alphabees-Backend zu registrieren.';
$string['connectionstatus_publickey_help'] = 'Öffentlicher Teil des Ed25519-Signaturschlüssels, den Moodle lokal erzeugt hat. Der zugehörige private Schlüssel verlässt nie Ihren Server und signiert die Anfragen an das Alphabees-Backend.';
$string['connectionstatus_showdetails'] = '▸ Technische Details (Site-Identifier, Public-Key)';
$string['connectionstatus_siteidentifier_help'] = 'Eindeutiger Fingerabdruck dieser Moodle-Installation. Damit kann das Alphabees-Portal Ihre Site von anderen Moodle-Installationen desselben Kunden unterscheiden.';
$string['connectionstatus_sync_paused_apikey_missing_help'] = 'Pausiert, API-Key im Moodle-Plugin fehlt. Speichern Sie einen gültigen API-Key, bevor diese Verbindung fortgesetzt werden kann.';
$string['connectionstatus_sync_paused_help'] = 'Alphabees hat ausgehende Platzierungs-Syncs für diese Site pausiert. Die Registrierung bleibt gültig, aber Moodle sendet keine Placement-Heartbeats, Placement-Events oder eingereihten Placement-Retries, bis das Portal den Sync fortsetzt.';
$string['console_no_access'] = 'Sie haben keinen Zugriff auf die Alphabees-Console. Sie steht Lehrenden und Managern von Kursen offen, die den KI-Tutor einsetzen.';
$string['console_not_connected'] = 'Diese Site ist noch nicht mit Alphabees verbunden, deshalb kann die Console Sie nicht anmelden. Speichern Sie einen API-Key in den Alphabees-Einstellungen und verbinden Sie die Site.';
$string['console_open'] = 'In der Alphabees-Console öffnen';
$string['console_open_settings'] = 'Zu den Alphabees-Einstellungen';
$string['console_run_upgrade'] = 'Zur Upgrade-Seite';
$string['console_title'] = 'Alphabees-Console';
$string['console_unavailable'] = 'Die Alphabees-Console konnte nicht geladen werden. Laden Sie die Seite neu. Wenn es weiterhin fehlschlägt, wenden Sie sich an Ihre Administration — möglicherweise ist die Console für diese Site noch nicht freigeschaltet.';
$string['console_upgrade_pending'] = 'Die Alphabees-Console ist noch nicht verfügbar, weil auf dieser Site das Plugin-Upgrade noch aussteht. Eine Administration muss die Website-Administration öffnen und das ausstehende Upgrade bestätigen; danach funktioniert die Console ohne weitere Konfiguration.';
$string['console_user_not_in_course'] = 'Diese Person ist nicht in diesem Kurs eingeschrieben.';
$string['console_userid_required'] = 'Es wurde keine Person angegeben.';
$string['currentbotfallback'] = 'Aktueller Tutor ({$a})';
$string['custom_blocktitle'] = 'Name des Blocks';
$string['custom_blocktitle_desc'] = 'Die Überschrift, die jeder Tutor-Block anzeigt — sofern eine Platzierung sie nicht in ihrer eigenen Konfiguration überschreibt. Leer lassen, um den Plugin-Namen zu verwenden.';
$string['custom_mobilehelplabel'] = 'Mobile App: Hilfe-Beschriftung';
$string['custom_mobilehelplabel_desc'] = 'Ersetzt die Beschriftung „Hilfe“ über dem Infotext in der Moodle Mobile App. Leer lassen, um die eingebaute Beschriftung zu verwenden.';
$string['custom_mobilehelptext'] = 'Mobile App: Infotext';
$string['custom_mobilehelptext_desc'] = 'Ersetzt den Infotext in der Moodle Mobile App. HTML ist erlaubt. Leer lassen, um den eingebauten Text zu verwenden.';
$string['custom_usagetext'] = 'Web: Nutzungshinweise';
$string['custom_usagetext_desc'] = 'Ersetzt die Nutzungshinweise im Tutor-Block auf Webseiten. Leer lassen, um den eingebauten Text zu verwenden.';
$string['custom_usagetitle'] = 'Web: Titel der Nutzungshinweise';
$string['custom_usagetitle_desc'] = 'Ersetzt den Titel der Nutzungshinweise im Tutor-Block auf Webseiten. Leer lassen, um den eingebauten Titel zu verwenden.';
$string['customtexts'] = 'Eigene Blocktexte';
$string['customtexts_builtin'] = 'Eingebauter Text, solange dieses Feld leer ist:';
$string['customtexts_desc'] = 'Ersetzt optional die eingebauten Infotexte, die Lernenden im Tutor-Block (Web) und in der Moodle Mobile App angezeigt werden. Leere Felder nutzen die eingebauten Texte, die auf Englisch und Deutsch vorliegen und der Sprache der Nutzer/innen folgen. Ein eigener Text wird allen Nutzer/innen unverändert angezeigt, unabhängig von deren Sprache – für sprachabhängige eigene Texte den Moodle-Filter „Mehrsprachiger Inhalt“ aktivieren und {mlang} bzw. Multilang-Spans verwenden.';
$string['customtexts_enabled'] = 'Eigene Infotexte verwenden';
$string['customtexts_enabled_desc'] = 'Zeigt die vier Texte unten statt der eingebauten. Abwählen kehrt zu den eingebauten Texten zurück, ohne das Eingegebene zu löschen.';
$string['customtexts_open'] = 'Texte und Benennung öffnen';
$string['customtexts_pointer'] = 'Den Block umbenennen und die Infotexte ersetzen, die Lernende sehen.';
$string['disconnect_confirm'] = 'Diese Moodle-Site von Alphabees trennen? Bestehende Tutor-Bloecke bleiben in Moodle, aber Registrierung, Syncs und Web-Service-Zugriff sind deaktiviert, bis ein Admin neu verbindet.';
$string['disconnect_failed'] = 'Trennung von Alphabees fehlgeschlagen: {$a}';
$string['disconnect_now'] = 'Trennen';
$string['disconnect_success'] = 'Von Alphabees getrennt. Bestehende Tutor-Bloecke bleiben in Moodle, aber Syncs und Web-Service-Zugriff sind deaktiviert.';
$string['eventpost_transient'] = 'Alphabees-Backend war nicht erreichbar; die Aufgabe wird erneut versucht.';
$string['firstsetup'] = 'Ersteinrichtung';
$string['firstsetup_desc'] = 'Speichern Sie den API-Key und pruefen Sie danach Status & Diagnose. Falls der Verbindungsstatus nicht Verbunden ist, klicken Sie einmal auf Jetzt verbinden.';
$string['generalsettings'] = 'Allgemeine Einstellungen';
$string['generalsettings_desc'] = 'Konfiguriere wie sich dieses Moodle mit dem Alphabees-Backend verbindet. Schalter unten; Status & Diagnose-Details sind unten auf der Seite.';
$string['help'] = 'Hilfe';
$string['helptitle'] = 'Falls der Chat nicht automatisch lädt, ziehe die Seite zum Aktualisieren nach unten.';
$string['messageprovider:digest'] = 'Zusammenfassung der KI-Tutor-Aktivität in Ihren Kursen';
$string['messageprovider:escalation'] = 'Der KI-Tutor hat ein Gespräch an Sie übergeben';
$string['messageprovider:exercise_assigned'] = 'Ihnen wurde eine Übung im KI-Tutor geschickt';
$string['messageprovider:learner_question'] = 'Eine lernende Person wartet auf eine Antwort im KI-Tutor';
$string['messageprovider:tutor_reply'] = 'Ihre Frage im KI-Tutor wurde beantwortet';
$string['nobotsavailable'] = 'Keine KI-Tutoren verfügbar.';
$string['nobotselected'] = 'Es wurde kein KI-Tutor ausgewählt.';
$string['notify_content_required'] = 'Die Benachrichtigung braucht sowohl einen Betreff als auch einen Text.';
$string['notify_courseid_required'] = 'Die Benachrichtigung braucht einen gültigen Kurs.';
$string['notify_open_tutor'] = 'Im KI-Tutor öffnen';
$string['notify_recipients_required'] = 'Eine Benachrichtigung an Lernende muss ihre Empfänger benennen.';
$string['notify_unknown_provider'] = 'Unbekannter Benachrichtigungstyp: {$a}';
$string['overrideremote'] = 'Lokale Steuerung übernehmen';
$string['overrideremote_help'] = 'Aktivieren Sie diese Option, um die Remote-Konfiguration zu überschreiben. Beim nächsten Speichern wird Ihre lokale Auswahl übernommen und das Portal wird darüber informiert, dass diese Platzierung nun lokal verwaltet wird.';
$string['placementuuid'] = 'Platzierungs-ID';
$string['pluginname'] = 'Alphabees KI Tutor';
$string['privacy:metadata:alphabees_backend'] = 'Daten, die mit dem Alphabees-Backend ausgetauscht werden, damit der KI-Tutor im Kontext antworten kann und Lehrende Gespräche nachverfolgen können.';
$string['privacy:metadata:alphabees_backend:courseid'] = 'Der Kurs, zu dem ein Gespräch gehört, damit Antworten auf das richtige Material zugreifen und die richtigen Lehrenden erreichen.';
$string['privacy:metadata:alphabees_backend:firstname'] = 'Der Vorname der Person, und nur dann, wenn die Site die persönliche Ansprache in den Plugin-Einstellungen aktiviert hat.';
$string['privacy:metadata:alphabees_backend:message'] = 'Der Inhalt der Nachrichten, die mit dem KI-Tutor ausgetauscht werden.';
$string['privacy:metadata:alphabees_backend:userid'] = 'Die Moodle-Nutzer-ID der Person, die den Tutor oder die Console verwendet, damit ihr Gespräch über Sitzungen hinweg wiedererkannt wird.';
$string['quiz_no_question_category'] = 'Für dieses Quiz konnte keine Fragenkategorie angelegt werden.';
$string['quiz_unsupported_here'] = 'Diese Moodle-Version stellt die benötigten Schnittstellen der Fragensammlung nicht bereit; Quiz-Fragen können hier nicht angelegt werden.';
$string['registrationfailed'] = 'Registrierung beim Alphabees-Backend fehlgeschlagen.';
$string['registrationtransient'] = 'Vorübergehender Fehler bei der Registrierung beim Alphabees-Backend; wird wiederholt.';
$string['remotemanaged_banner'] = 'Diese Platzierung wird derzeit über das Alphabees-Portal verwaltet. Hier vorgenommene Änderungen werden überschrieben, sofern nicht unten die lokale Steuerung übernommen wird.';
$string['resume_sync_failed'] = 'Alphabees-Platzierungs-Syncs konnten nicht fortgesetzt werden: {$a}';
$string['resume_sync_now'] = 'Sync fortsetzen';
$string['resume_sync_queued'] = 'Resume-Anfrage eingereiht. Moodle versucht die Übermittlung an Alphabees im Hintergrund erneut.';
$string['resume_sync_requires_apikey'] = 'Speichern Sie zuerst einen gültigen Alphabees-API-Key, bevor diese pausierte Verbindung fortgesetzt werden kann.';
$string['resume_sync_success'] = 'Alphabees-Platzierungs-Syncs fortgesetzt.';
$string['selectabot'] = 'Wählen Sie einen KI-Tutor aus';
$string['send_userprofile'] = 'Vornamen der Nutzer an den KI-Tutor senden';
$string['send_userprofile_short'] = 'Wenn aktiviert, verlässt der Vorname des eingeloggten Nutzers diese Site: Er wird einmal pro Sitzung an das Alphabees-Chat-Widget übertragen, ausschließlich damit der Tutor den Namen anzeigen und die Lernenden persönlich ansprechen kann. Nachname, E-Mail-Adresse oder Anmeldename werden nicht gesendet. Standard: aus.';
$string['status_connected'] = 'Verbunden';
$string['status_disconnected'] = 'Nicht verbunden';
$string['status_lastattempt'] = 'Letzter Registrierungsversuch';
$string['status_lasterror'] = 'Letzter Fehler';
$string['status_lastsync'] = 'Letzter Sync';
$string['status_publickey'] = 'Site Public Key';
$string['status_registeredat'] = 'Registriert am';
$string['status_registration_blocked'] = 'API-Key abgelehnt';
$string['status_registration_blocked_chat'] = 'Der Alphabees KI Tutor ist aktuell nicht verfügbar, weil der konfigurierte API-Key abgelehnt wurde. Bitte wenden Sie sich an die Moodle-Administration.';
$string['status_registration_blocked_chat_admin'] = 'Der Alphabees KI Tutor ist nicht verfügbar, weil der konfigurierte API-Key abgelehnt wurde: {$a}. Speichern Sie einen anderen API-Key in den Plug-in-Einstellungen, um es erneut zu versuchen.';
$string['status_registration_blocked_detail'] = 'Registrierung blockiert';
$string['status_siteidentifier'] = 'Site-Identifier';
$string['status_state'] = 'Status';
$string['status_sync_pause_reason'] = 'Pausengrund';
$string['status_sync_paused'] = 'Sync pausiert';
$string['status_sync_paused_detail'] = 'Sync pausiert seit';
$string['statusheading'] = 'Status & Diagnose';
$string['task_cleanup_nonces'] = 'Abgelaufene Alphabees-Signatur-Nonces aufräumen';
$string['task_post_placement_event'] = 'Alphabees Placement-Lifecycle-Event senden';
$string['task_post_site_lifecycle'] = 'Alphabees Site-Lifecycle-Event senden';
$string['task_post_ws_token'] = 'Alphabees Web-Services-Token ans Backend senden';
$string['task_process_retry_queue'] = 'Alphabees Outbound-Retry-Queue verarbeiten';
$string['task_register_site'] = 'Moodle-Site beim Alphabees-Backend registrieren';
$string['task_sync_placements'] = 'Alphabees-Block-Platzierungen mit Backend synchronisieren';
$string['unsupported_modname'] = 'Dieser Aktivitätstyp kann über die Alphabees-Integration nicht angelegt werden: {$a}';
$string['upload_content_invalid'] = 'Der übertragene Dateiinhalt ist kein gültiges Base64.';
$string['upload_content_required'] = 'Der Upload braucht einen Dateiinhalt.';
$string['upload_filename_required'] = 'Der Upload braucht einen Dateinamen.';
$string['upload_too_large'] = 'Die Datei überschreitet die größte auf dieser Site zulässige Größe ({$a}).';
$string['usagetext'] = '<p>Dem KI-Tutor kannst du jederzeit Fragen stellen.</p><p><strong>So funktioniert es:</strong></p><ul><li>Unten rechts auf das Chat-Symbol klicken, um den Tutor zu öffnen.</li><li>In der Eingabeleiste Text tippen oder das Mikrofon-Symbol für eine Spracheingabe verwenden.</li><li>Danach einfach auf die Antwort warten – je nach Komplexität kann dies einen Moment dauern.</li></ul><p><em>Hinweis:</em> Der Tutor ist KI-basiert und wird von der Administration deiner Moodle-Seite verwaltet. Bei Fragen zur Nutzung wende dich bitte an die Kurs- oder Systemadministration.</p>';
$string['usagetitle'] = 'Nutzungshinweise KI-Tutor';
$string['ws_disable_failed'] = 'Alphabees-Web-Services-Token konnte nicht widerrufen werden: {$a}';
$string['ws_disable_success'] = 'Alphabees-Web-Services-Token widerrufen. Die Integration ist nicht mehr aktiv.';
$string['ws_enable'] = 'Alphabees-Web-Services-Integration aktivieren';
$string['ws_enable_consent'] = 'Ich autorisiere dieses Moodle, Kursinhalte, Teilnehmer, Noten und Completion-Daten über Web Services für das Alphabees-Backend bereitzustellen. Beim Speichern legt das Plugin einen dedizierten <code>alphabees-service</code>-Benutzer an, weist ihm die eingebaute Manager-Rolle im System-Kontext zu (site-weiter Lese-/Schreibzugriff auf Kurse, Benutzer und Noten — aber kein site:config), erzeugt ein dauerhaftes Token und sendet es über den bestehenden signierten Kanal an Alphabees. Häkchen entfernen widerruft das Token, entfernt die Rollenzuweisungen und baut die Integration komplett ab.';
$string['ws_enable_failed'] = 'Alphabees-Web-Services-Integration konnte nicht aktiviert werden: {$a}';
$string['ws_enable_short'] = 'Gewährt dem Alphabees-Backend site-weiten Lese-/Schreibzugriff auf Kurse, Nutzer und Noten über einen dedizierten Service-User. Voller Umfang siehe Status-Bereich unten. Standard: an.';
$string['ws_enable_success'] = 'Alphabees-Web-Services-Integration aktiviert. Token wurde an das Backend gesendet.';
$string['ws_grants_heading'] = 'Was wird damit gewährt';
$string['ws_grants_intro'] = 'Wenn aktiviert, kann das Alphabees-Backend folgende Moodle-Funktionen im Namen des automatisch angelegten Benutzers <code>alphabees-service</code> aufrufen:';
$string['ws_grants_summary'] = '{$a} Moodle-Web-Service-Funktionen freigegeben';
$string['ws_heading'] = 'Web-Services-Integration';
$string['ws_heading_desc'] = 'Erlaubt dem Alphabees-Backend, Kursinhalte für die Wissensbasis zu lesen und (optional) generierte Kurse zurück nach Moodle zu schreiben. Eine Checkbox ersetzt das frühere 11-Schritte-Manual-Setup. Abschalten ist ein Klick.';
$string['ws_missing_function'] = 'Moodle stellt die erforderliche Web-Service-Funktion nicht bereit: {$a}';
$string['ws_selftest_failed'] = 'Der erzeugte Web-Services-Token ist im Moodle-Selbsttest fehlgeschlagen: {$a}';
$string['ws_selftest_function_count'] = '{$a} Funktionen sind für diesen Token sichtbar';
$string['ws_selftest_missing_functions'] = 'Fehlende erforderliche Funktionen: {$a}';
$string['ws_selftest_status'] = 'Token-Selbsttest';
$string['ws_selftest_status_failed'] = 'Fehlgeschlagen';
$string['ws_selftest_status_never'] = 'Noch nicht ausgeführt';
$string['ws_selftest_status_passed'] = 'Bestanden';
$string['ws_show_full_consent'] = 'Vollen Consent-Text und freigegebene Funktionen anzeigen';
$string['ws_status_disconnected'] = 'Web-Services-Integration nicht aktiviert.';
$string['ws_status_token_present'] = 'Web-Services-Integration aktiviert. Ein dauerhaftes Token ist an den Benutzer <code>alphabees-service</code> gebunden.';
$string['ws_token_post_httpcode'] = 'Backend-HTTP-Status: {$a}';
$string['ws_token_post_status'] = 'Backend-Token-Austausch';
$string['ws_token_post_status_error'] = 'Dauerhaft fehlgeschlagen';
$string['ws_token_post_status_never'] = 'Noch nicht ausgeführt';
$string['ws_token_post_status_ok'] = 'Übermittelt';
$string['ws_token_post_status_queued'] = 'Eingereiht';
$string['ws_token_post_status_revoked_ok'] = 'Widerruf übermittelt';
$string['ws_token_post_status_revoked_queued'] = 'Widerruf eingereiht';
$string['ws_token_post_status_transient'] = 'Vorübergehender Fehler — Moodle versucht es erneut';
