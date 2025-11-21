Plugin: local_groupassign
Purpose: Upload CSV (username|email,group) and add already-enrolled users into groups inside a selected course.
Install: place folder in moodle/local/local_groupassign, then go to Site administration -> Notifications to install.
Use: Site administration -> Plugins -> Local plugins -> Group CSV Assign
CSV format: first row header `username,group` OR `email,group` depending on the chosen identity method.
Options on form:
 - Identify users by: username OR email
 - Create missing groups automatically: Yes/No

Author: Arjun Singh <moodlerarjun@gmail.com>