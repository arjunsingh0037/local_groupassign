<?php
/**
 * Access definitions for local_groupassign.
 *
 * @package     local_groupassign
 * @author      Arjun Singh <moodlerarjun@gmail.com>
 * @copyright   2025 Arjun Singh
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/groupassign:manage' => array(
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW
        )
    ),
);
