<?php
/**
 * Form definition for local_groupassign upload.
 *
 * @package     local_groupassign
 * @author      Arjun Singh <moodlerarjun@gmail.com>
 * @copyright   2025 Arjun Singh
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groupassign\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class upload_form extends \moodleform {

    public function definition() {
        global $DB;
        $mform = $this->_form;

        // ---------------------------------------------
        // Searchable Course Selector (autocomplete)
        // ---------------------------------------------
        // Fetch courses (id => fullname)
        $courses = $DB->get_records_menu('course', null, 'fullname ASC', 'id, fullname');

        $courselist = [];
        foreach ($courses as $id => $name) {
            $courselist[$id] = format_string($name);
        }

        // Autocomplete element (searchable)
        $mform->addElement(
            'autocomplete',
            'courseid',
            get_string('choosecourse', 'local_groupassign'),
            $courselist,
            [
                'placeholder' => get_string('choosecourse', 'local_groupassign'),
                'multiple'   => false,
                'noselectionstring' => get_string('choosecourse', 'local_groupassign')
            ]
        );
        $mform->addRule('courseid', null, 'required', null, 'client');
        $mform->setType('courseid', PARAM_INT);

        // ---------------------------------------------
        // Identify by: username or email
        // ---------------------------------------------
        $mform->addElement('select', 'identifyby', get_string('identifyby', 'local_groupassign'), array(
            'username' => get_string('identifyby_username', 'local_groupassign'),
            'email' => get_string('identifyby_email', 'local_groupassign')
        ));
        $mform->setDefault('identifyby', 'username');

        // ---------------------------------------------
        // Create missing groups option
        // ---------------------------------------------
        $mform->addElement('select', 'creategroups', get_string('creategroups', 'local_groupassign'), array(
            1 => get_string('creategroups_yes', 'local_groupassign'),
            0 => get_string('creategroups_no', 'local_groupassign')
        ));
        $mform->setDefault('creategroups', 1);

        // ---------------------------------------------
        // File picker (CSV)
        // ---------------------------------------------
        $mform->addElement('filepicker', 'csvfile', get_string('uploadcsv', 'local_groupassign'), null,
            ['accepted_types' => ['.csv']]
        );
        $mform->addRule('csvfile', null, 'required', null, 'client');

        // Submit
        $this->add_action_buttons(true, get_string('submit', 'local_groupassign'));
    }
}
