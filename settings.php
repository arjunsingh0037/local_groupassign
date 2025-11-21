<?php
/**
 * Admin settings registration for local_groupassign.
 *
 * @package     local_groupassign
 * @author      Arjun Singh <moodlerarjun@gmail.com>
 * @copyright   2025 Arjun Singh
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Add the page under: Site administration -> Plugins -> Local plugins
    $ADMIN->add('localplugins',
        new admin_externalpage(
            'local_groupassign',
            get_string('manage', 'local_groupassign'),
            new moodle_url('/local/groupassign/index.php'),
            'local/groupassign:manage'
        )
    );

    // If you really want to use the absolute dev URL instead of moodle_url(),
    // replace the moodle_url(...) above with:
    // new moodle_url('http://localhost/atypical-moodle/local/groupassign/index.php')
}
