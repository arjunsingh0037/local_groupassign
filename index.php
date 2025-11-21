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
require_once($CFG->dirroot . '/group/lib.php'); // groups API (groups_add_member etc)
require_once(__DIR__ . '/classes/form/upload_form.php');

use local_groupassign\form\upload_form;

admin_externalpage_setup('local_groupassign');
$context = context_system::instance();
require_capability('local/groupassign:manage', $context);

$PAGE->set_url(new moodle_url('/local/groupassign/index.php'));
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
        // get_area_files returns file objects; exclude directories
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
    // 5) Process CSV (identifyby + creategroups come from the form if used)
    // -----------------------------
    $identifyby = isset($data->identifyby) ? $data->identifyby : 'username';
    $creategroups = isset($data->creategroups) ? (int)$data->creategroups : 1;

    $results = local_groupassign_process_csv($tmpname, $courseid, $identifyby, $creategroups);

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

    // NEW: redirect to plugin page (fresh form) instead of reload
    $refreshseconds = 5;
    $redirecturl = (new moodle_url('/local/groupassign/index.php'))->out(false);

    // show note + button
    $note = "
        <div style='margin-top:15px;font-size:14px;'>
            This page will redirect to the form in 
            <strong><span id=\"ga-countdown\">{$refreshseconds}</span></strong> seconds.
            <br><br>
            <button id=\"ga-redirect-btn\" class=\"btn btn-primary\">
                Go to form now
            </button>
        </div>
    ";
    echo html_writer::tag('div', $note);

    // safe JSON encode the redirect URL for JS
    $redirectjson = json_encode($redirecturl);

    $js = "
    <script type=\"text/javascript\">
    (function(){
        var secs = {$refreshseconds};
        var el = document.getElementById('ga-countdown');
        var btn = document.getElementById('ga-redirect-btn');
        var redirect = {$redirectjson};

        // Manual button click -> immediate redirect to form (no POST re-submit)
        if (btn) {
            btn.addEventListener('click', function() {
                location.replace(redirect);
            });
        }

        // Automatic countdown -> redirect to form when finished
        var t = setInterval(function(){
            secs--;
            if (el) el.textContent = secs;
            if (secs <= 0) {
                clearInterval(t);
                // use replace() so user can't go back to the result-page which would re-run the POST
                location.replace(redirect);
            }
        }, 5000);
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
// CSV processor (robust BOM/delimiter + identifyby + creategroups)
// -----------------------------
function local_groupassign_process_csv($filepath, $courseid, $identifyby = 'username', $creategroups = 1) {
    global $DB;

    $resultlines = [];
    $context = context_course::instance($courseid);

    if (!file_exists($filepath)) {
        return [ get_string('invalidfile','local_groupassign') ];
    }

    $handle = fopen($filepath, 'r');
    if (!$handle) return [ get_string('invalidfile','local_groupassign') ];

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

    // Find identity column depending on chosen method
    $idcol = ($identifyby === 'email') ? 'email' : 'username';
    $idpos = array_search($idcol, $header);
    $grouppos = array_search('group', $header);

    if ($idpos === false || $grouppos === false) {
        fclose($handle);
        return [ 'CSV must contain headers: ' . $idcol . ',group (detected delimiter: ' . ($delimiter === "\t" ? '\\t' : $delimiter) . ')' ];
    }

    // Now read remaining rows using detected delimiter
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $identifier = isset($row[$idpos]) ? trim($row[$idpos]) : '';
        $groupname = isset($row[$grouppos]) ? trim($row[$grouppos]) : '';

        if ($identifier === '' || $groupname === '') {
            $resultlines[] = "Skipped empty row";
            continue;
        }

        // user lookup by chosen identity
        if ($identifyby === 'email') {
            $user = $DB->get_record('user', ['email' => $identifier, 'deleted' => 0]);
        } else {
            $user = $DB->get_record('user', ['username' => $identifier, 'deleted' => 0]);
        }

        if (!$user) {
            $resultlines[] = "{$identifier}: SKIPPED — user not found";
            continue;
        }

        // enrollment check
        if (!is_enrolled($context, $user)) {
            $resultlines[] = "{$identifier}: SKIPPED — user exists but is NOT enrolled in this course. Add this user to the course before importing group membership.";
            continue;
        }

        // group check/create
        $group = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $groupname]);
        if (!$group) {
            if ($creategroups) {
                $g = new stdClass();
                $g->courseid = $courseid;
                $g->name = $groupname;
                $g->id = groups_create_group($g);
                $group = $DB->get_record('groups', ['id' => $g->id]);
                $resultlines[] = "Created group: $groupname";
            } else {
                $resultlines[] = "{$identifier}: SKIPPED — group '$groupname' does not exist and group creation is disabled.";
                continue;
            }
        }

        // membership
        if (!$DB->record_exists('groups_members', ['groupid' => $group->id, 'userid' => $user->id])) {
            groups_add_member($group->id, $user->id);
            $resultlines[] = "{$identifier}: OK — added to $groupname";
        } else {
            $resultlines[] = "{$identifier}: already in $groupname";
        }
    }

    fclose($handle);
    return $resultlines;
}
