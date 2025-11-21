<?php
/**
 * Main page for Group CSV Assign (upload and process CSV).
 *
 * @package     local_groupassign
 * @author      Arjun Singh <moodlerarjun@gmail.com>
 * @copyright   2025 Arjun Singh
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once(__DIR__ . '/classes/form/upload_form.php');

use local_groupassign\form\upload_form;

admin_externalpage_setup('local_groupassign');
$context = context_system::instance();
require_capability('local/groupassign:manage', $context);

$PAGE->set_url(new moodle_url('/local/local_groupassign/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_groupassign'));
$PAGE->set_heading(get_string('pluginname', 'local_groupassign'));

$form = new upload_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/index.php'));
}

if ($data = $form->get_data()) {

    // -----------------------------
    // 1) Get course id
    // -----------------------------
    $courseid = (int)$data->courseid;
    if (!$course = $DB->get_record('course', ['id' => $courseid])) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification('Invalid course');
        echo $OUTPUT->footer();
        exit;
    }

    // -----------------------------
    // 2) Retrieve file from filepicker draft area (recommended)
    // -----------------------------
    $tmpname = '';
    $draftitemid = file_get_submitted_draft_itemid('csvfile');

    if ($draftitemid) {
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        // get_area_files returns file objects; exclude directories so false = no files
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        if (!empty($files)) {
            $file = reset($files); // first uploaded file
            // create a true temp file
            $tmpdir = make_temp_directory('local_groupassign');
            $tmpname = $tmpdir . '/' . uniqid('csv_') . '.csv';
            $file->copy_content_to($tmpname);
        }
    }

    // -----------------------------
    // 3) Fallback: direct PHP upload (rare on Moodle filepicker setups)
    // -----------------------------
    if (empty($tmpname) && isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
        $tmpname = $_FILES['csvfile']['tmp_name'];
    }

    // -----------------------------
    // 4) Validate file exists
    // -----------------------------
    if (empty($tmpname) || !file_exists($tmpname)) {
        // Better diagnostics for admins
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('invalidfile', 'local_groupassign') . '<br><br>'
            . 'Diagnostics:<br>'
            . '- Draft item id: ' . (int)$draftitemid . '<br>'
            . '- $_FILES present: ' . (isset($_FILES['csvfile']) ? 'yes' : 'no') . '<br>'
            . '- PHP upload_max_filesize: ' . ini_get('upload_max_filesize') . '<br>'
            . '- PHP post_max_size: ' . ini_get('post_max_size') . '<br>'
            . 'If you used the filepicker choose the file then click the blue <em>Upload this file</em> (or Save) button before Import.'
        );
        echo $OUTPUT->footer();
        exit;
    }

    // -----------------------------
    // 5) Process CSV
    // -----------------------------
    $results = local_groupassign_process_csv($tmpname, $courseid);

    // remove temp copy if we created it
    if (strpos($tmpname, '/temp/') !== false || strpos($tmpname, 'csv_') !== false) {
        @unlink($tmpname);
    }

    // Build a friendly summary and show auto-refresh
    $coursefullname = format_string($course->fullname);
    $courseinfo = s("{$coursefullname} (id: {$courseid})");

    // Create nicely formatted result lines (escape for safety)
    $listitems = [];
    foreach ($results as $r) {
        $listitems[] = html_writer::tag('li', s($r));
    }
    $resultul = html_writer::tag('ul', implode("\n", $listitems));

    // Header + summary
    echo $OUTPUT->header();
    echo $OUTPUT->box_start('generalbox boxaligncenter', 'groupassign-summary');

    // Title
    echo html_writer::tag('h2', get_string('success', 'local_groupassign'));

    // Course info line
    echo html_writer::tag('p', html_writer::tag('strong', 'Course: ') . $courseinfo);

    // Result list
    echo $resultul;

    // Refresh notice with countdown
    $refreshseconds = 5;
    $note = "This page will refresh automatically in <span id=\"ga-countdown\">{$refreshseconds}</span> seconds.";
    echo html_writer::tag('p', $note);

    // Add JS to countdown and refresh
    $js = "
    <script type=\"text/javascript\">
    (function(){
        var secs = {$refreshseconds};
        var el = document.getElementById('ga-countdown');
        var t = setInterval(function(){
            secs--;
            if (!el) return;
            el.textContent = secs;
            if (secs <= 0) {
                clearInterval(t);
                location.reload();
            }
        }, 1000);
    })();
    </script>
    ";
    echo $js;

    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
    exit;

}

// display form
echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();


// -----------------------------
// CSV processor (same as before)
// -----------------------------
// REPLACE the old local_groupassign_process_csv function with this one
function local_groupassign_process_csv($filepath, $courseid) {
    global $DB;

    $resultlines = [];
    $context = context_course::instance($courseid);

    if (!file_exists($filepath)) {
        return [ get_string('invalidfile','local_groupassign') ];
    }

    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return [ get_string('invalidfile','local_groupassign') ];
    }

    // Read first line raw (header) and remove any BOM
    $firstline = fgets($handle);
    if ($firstline === false) {
        fclose($handle);
        return [ get_string('invalidfile','local_groupassign') ];
    }

    // Remove UTF-8 BOM if present
    if (substr($firstline, 0, 3) === "\xEF\xBB\xBF") {
        $firstline = substr($firstline, 3);
    }

    $firstline = rtrim($firstline, "\r\n");

    // Detect delimiter by counting occurrences (comma, semicolon, tab)
    $delimiters = [',', ';', "\t"];
    $best = ',';
    $maxcount = -1;
    foreach ($delimiters as $d) {
        $c = substr_count($firstline, $d);
        if ($c > $maxcount) {
            $maxcount = $c;
            $best = $d;
        }
    }
    $delimiter = $best;

    // Parse header robustly
    $header = str_getcsv($firstline, $delimiter);
    $header = array_map('trim', $header);
    $header = array_map('strtolower', $header);

    $usernamepos = array_search('username', $header);
    $grouppos   = array_search('group', $header);

    if ($usernamepos === false || $grouppos === false) {
        fclose($handle);
        return [ 'CSV must contain headers: username,group (detected delimiter: ' . ($delimiter === "\t" ? '\\t' : $delimiter) . ')' ];
    }

    // Now read remaining rows using detected delimiter
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        // Some rows may have fewer columns; guard indexes
        $username = isset($row[$usernamepos]) ? trim($row[$usernamepos]) : '';
        $groupname = isset($row[$grouppos]) ? trim($row[$grouppos]) : '';

        if ($username === '' || $groupname === '') {
            $resultlines[] = "Skipped empty row";
            continue;
        }

        // user lookup
        if (!$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0])) {
            $resultlines[] = "$username: user not found";
            continue;
        }

        // enrollment check
        if (!is_enrolled($context, $user)) {
            $resultlines[] = "$username: not enrolled";
            continue;
        }

        // group check/create
        $group = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $groupname]);
        if (!$group) {
            $g = new stdClass();
            $g->courseid = $courseid;
            $g->name = $groupname;
            $g->id = groups_create_group($g);
            $group = $DB->get_record('groups', ['id' => $g->id]);
            $resultlines[] = "Created group: $groupname";
        }

        // membership
        if (!$DB->record_exists('groups_members', ['groupid' => $group->id, 'userid' => $user->id])) {
            groups_add_member($group->id, $user->id);
            $resultlines[] = "$username added to $groupname";
        } else {
            $resultlines[] = "$username already in $groupname";
        }
    }

    fclose($handle);
    return $resultlines;
}
