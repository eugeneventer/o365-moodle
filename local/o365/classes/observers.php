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
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365;

require_once($CFG->dirroot.'/lib/filelib.php');

/**
 * Handles events.
 */
class observers {
    /**
     * Handle an authentication-only OIDC event.
     *
     * Does the following:
     *     - This is used for setting the system API user, so store the received token appropriately.
     *
     * @param \auth_oidc\event\user_authed $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_oidc_user_authed(\auth_oidc\event\user_authed $event) {
        $eventdata = $event->get_data();

        $tokendata = [
            'idtoken' => $eventdata['other']['tokenparams']['id_token'],
            $eventdata['other']['tokenparams']['resource'] => [
                'token' => $eventdata['other']['tokenparams']['access_token'],
                'scope' => $eventdata['other']['tokenparams']['scope'],
                'refreshtoken' => $eventdata['other']['tokenparams']['refresh_token'],
                'resource' => $eventdata['other']['tokenparams']['resource'],
                'expiry' => $eventdata['other']['tokenparams']['expires_on'],
            ]
        ];

        set_config('systemtokens', serialize($tokendata), 'local_o365');
        set_config('sharepoint_initialized', '0', 'local_o365');
        redirect(new \moodle_url('/admin/settings.php?section=local_o365'));
    }

    /**
     * Handles an existing Moodle user connecting to OpenID Connect.
     *
     * @param \auth_oidc\event\user_connected $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_oidc_user_connected(\auth_oidc\event\user_connected $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        // Get additional tokens for the user.
        $eventdata = $event->get_data();
        if (!empty($eventdata['userid'])) {
            try {
                $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
                $httpclient = new \local_o365\httpclient();
                $azureresource = \local_o365\rest\calendar::get_resource();
                $token = \local_o365\oauth2\token::instance($eventdata['userid'], $azureresource, $clientdata, $httpclient);
                return true;
            } catch (\Exception $e) {
                \local_o365\utils::debug($e->getMessage());
                return false;
            }
        }
        return false;
    }

    /**
     * Handle a user being created .
     *
     * Does the following:
     *     - Check if user is using OpenID Connect auth plugin.
     *     - If so, gets additional information from Azure AD and updates the user.
     *
     * @param \core\event\user_created $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_user_created(\core\event\user_created $event) {
        global $DB;

        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        $eventdata = $event->get_data();

        if (empty($eventdata['objectid'])) {
            return false;
        }
        $createduserid = $eventdata['objectid'];

        $user = $DB->get_record('user', ['id' => $createduserid]);
        if (!empty($user) && isset($user->auth) && $user->auth === 'oidc') {
            static::get_additional_user_info($createduserid, 'create');
        }

        return true;
    }

    /**
     * Handles an existing Moodle user disconnecting from OpenID Connect.
     *
     * @param \auth_oidc\event\user_disconnected $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_oidc_user_disconnected(\auth_oidc\event\user_disconnected $event) {
        global $DB;
        $eventdata = $event->get_data();
        if (!empty($eventdata['userid'])) {
            $DB->delete_records('local_o365_token', ['user_id' => $eventdata['userid']]);
        }
    }

    /**
     * Handles logins from the OpenID Connect auth plugin.
     *
     * Does the following:
     *     - Uses the received OIDC auth code to get tokens for the other resources we use: onedrive, sharepoint, outlook.
     *
     * @param \auth_oidc\event\user_loggedin $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_oidc_user_loggedin(\auth_oidc\event\user_loggedin $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        // Get additional tokens for the user.
        $eventdata = $event->get_data();
        if (!empty($eventdata['other']['username']) && !empty($eventdata['userid'])) {
            static::get_additional_user_info($eventdata['userid'], 'login');
        }

        return true;
    }

    /**
     * Get additional information about a user from Azure AD.
     *
     * @param int $userid The ID of the user we want more information about.
     * @param string $eventtype The type of event that triggered this call. "login" or "create".
     * @return bool Success/Failure.
     */
    public static function get_additional_user_info($userid, $eventtype) {
        global $DB;

        try {
            // Azure AD must be configured for us to fetch data.
            if (\local_o365\rest\azuread::is_configured() !== true) {
                return true;
            }

            $aadresource = \local_o365\rest\azuread::get_resource();
            $sql = 'SELECT tok.*
                      FROM {auth_oidc_token} tok
                      JOIN {user} u
                           ON tok.username = u.username
                     WHERE u.id = ? AND tok.resource = ?';
            $params = [$userid, $aadresource];
            $tokenrec = $DB->get_record_sql($sql, $params);
            if (empty($tokenrec)) {
                // No OIDC token for this user and resource - maybe not an Azure AD user.
                return false;
            }

            $httpclient = new \local_o365\httpclient();
            $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
            $token = \local_o365\oauth2\token::instance($userid, $aadresource, $clientdata, $httpclient);
            $apiclient = new \local_o365\rest\azuread($token, $httpclient);
            $aaduserdata = $apiclient->get_user($tokenrec->oidcuniqid);

            $updateduser = new \stdClass;
            $updateduser = \local_o365\feature\usersync\main::apply_configured_fieldmap($aaduserdata, $updateduser, $eventtype);

            if (!empty($updateduser)) {
                $updateduser->id = $userid;
                $DB->update_record('user', $updateduser);
                profile_save_data($updateduser);
            }
            return true;
        } catch (\Exception $e) {
            \local_o365\utils::debug($e->getMessage());
        }
        return false;
    }

    /**
     * Handle user_enrolment_created event.
     *
     * @param \core\event\user_enrolment_created $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_user_enrolment_created(\core\event\user_enrolment_created $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        $userid = $event->relateduserid;
        $courseid = $event->courseid;

        if (empty($userid) || empty($courseid)) {
            return true;
        }

        try {
            // Add user from course usergroup.
            $configsetting = get_config('local_o365', 'creategroups');
            if (!empty($configsetting)) {
                $httpclient = new \local_o365\httpclient();
                $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
                $aadresource = \local_o365\rest\azuread::get_resource();
                $aadtoken = \local_o365\oauth2\systemtoken::instance(null, $aadresource, $clientdata, $httpclient);
                if (!empty($aadtoken)) {
                    $aadclient = new \local_o365\rest\azuread($aadtoken, $httpclient);
                    $aadclient->add_user_to_course_group($courseid, $userid);
                    return true;
                }
            }
        } catch (\Exception $e) {
            \local_o365\utils::debug($e->getMessage());
        }
        return false;
    }

    /**
     * Handle user_enrolment_deleted event
     *
     * Tasks
     *     - remove user from course usergroups.
     *
     * @param \core\event\user_enrolment_deleted $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;

        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }

        $userid = $event->relateduserid;
        $courseid = $event->courseid;

        if (empty($userid) || empty($courseid)) {
            return true;
        }

        try {
            // Remove user from course usergroup.
            $configsetting = get_config('local_o365', 'creategroups');
            if (!empty($configsetting)) {
                $httpclient = new \local_o365\httpclient();
                $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
                $aadresource = \local_o365\rest\azuread::get_resource();
                $aadtoken = \local_o365\oauth2\systemtoken::instance(null, $aadresource, $clientdata, $httpclient);
                if (!empty($aadtoken)) {
                    $aadclient = new \local_o365\rest\azuread($aadtoken, $httpclient);
                    $aadclient->remove_user_from_course_group($courseid, $userid);
                    return true;
                }
            }
        } catch (\Exception $e) {
            \local_o365\utils::debug($e->getMessage());
        }
        return false;
    }

    /**
     * Construct a sharepoint API client using the system API user.
     *
     * @return \local_o365\rest\sharepoint|bool A constructed sharepoint API client, or false if error.
     */
    public static function construct_sharepoint_api_with_system_user() {
        try {
            $spresource = \local_o365\rest\sharepoint::get_resource();
            if (!empty($spresource)) {
                $httpclient = new \local_o365\httpclient();
                $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
                $sptoken = \local_o365\oauth2\systemtoken::instance(null, $spresource, $clientdata, $httpclient);
                if (!empty($sptoken)) {
                    $sharepoint = new \local_o365\rest\sharepoint($sptoken, $httpclient);
                    return $sharepoint;
                }
            }
        } catch (\Exception $e) {
            \local_o365\utils::debug($e->getMessage(), get_called_class());
        }
        return false;
    }

    /**
     * Handle course_created event.
     *
     * Does the following:
     *     - create a sharepoint site and associated groups.
     *
     * @param \core\event\course_created $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_course_created(\core\event\course_created $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        $sharepoint = static::construct_sharepoint_api_with_system_user();
        if (!empty($sharepoint)) {
            $sharepoint->create_course_site($event->objectid);
        }
    }

    /**
     * Handle course_updated event.
     *
     * Does the following:
     *     - update associated sharepoint sites and associated groups.
     *
     * @param \core\event\course_updated $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_course_updated(\core\event\course_updated $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        $courseid = $event->objectid;
        $eventdata = $event->get_data();
        if (!empty($eventdata['other'])) {
            $sharepoint = static::construct_sharepoint_api_with_system_user();
            if (!empty($sharepoint)) {
                $sharepoint->update_course_site($courseid, $eventdata['other']['shortname'], $eventdata['other']['fullname']);
            }
        }
    }

    /**
     * Handle course_deleted event
     *
     * Does the following:
     *     - delete sharepoint sites and groups, and local sharepoint site data.
     *
     * @param \core\event\course_deleted $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_course_deleted(\core\event\course_deleted $event) {
        global $DB;
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        $courseid = $event->objectid;

        $sharepoint = static::construct_sharepoint_api_with_system_user();
        if (!empty($sharepoint)) {
            $sharepoint->delete_course_site($courseid);
        }
        return true;
    }

    /**
     * Sync Sharepoint course site access when a role was assigned or unassigned for a user.
     *
     * @param int $roleid The ID of the role that was assigned/unassigned.
     * @param int $userid The ID of the user that it was assigned to or unassigned from.
     * @param int $contextid The ID of the context the role was assigned/unassigned in.
     * @return bool Success/Failure.
     */
    public static function sync_spsite_access_for_roleassign_change($roleid, $userid, $contextid) {
        global $DB;
        $requiredcap = \local_o365\rest\sharepoint::get_course_site_required_capability();

        // Check if the role affected the required capability.
        $rolecapsql = "SELECT *
                         FROM {role_capabilities}
                        WHERE roleid = ? AND capability = ?";
        $capassignrec = $DB->get_record_sql($rolecapsql, [$roleid, $requiredcap]);

        if (empty($capassignrec) || $capassignrec->permission == CAP_INHERIT) {
            // Role doesn't affect required capability. Doesn't concern us.
            return false;
        }

        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (empty($context)) {
            // Invalid context, stop here.
            return false;
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            $courseid = $context->instanceid;
            $user = $DB->get_record('user', ['id' => $userid]);
            if (empty($user)) {
                // Bad userid.
                return false;
            }

            $userupn = \local_o365\rest\azuread::get_muser_upn($user);
            if (empty($userupn)) {
                // No user UPN, can't continue.
                return false;
            }

            $spgroupsql = 'SELECT *
                             FROM {local_o365_coursespsite} site
                             JOIN {local_o365_spgroupdata} grp ON grp.coursespsiteid = site.id
                            WHERE site.courseid = ? AND grp.permtype = ?';
            $spgrouprec = $DB->get_record_sql($spgroupsql, [$courseid, 'contribute']);
            if (empty($spgrouprec)) {
                // No sharepoint group, can't fix that here.
                return false;
            }

            // If the context is a course context we can change SP access now.
            $sharepoint = static::construct_sharepoint_api_with_system_user();
            if (empty($sharepoint)) {
                // O365 not configured.
                return false;
            }
            $hascap = has_capability($requiredcap, $context, $user);
            if ($hascap === true) {
                // Add to group.
                $sharepoint->add_user_to_group($userupn, $spgrouprec->groupid, $user->id);
            } else {
                // Remove from group.
                $sharepoint->remove_user_from_group($userupn, $spgrouprec->groupid, $user->id);
            }
            return true;
        } else if ($context->get_course_context(false) == false) {
            // If the context is higher than a course, we have to run a sync in cron.
            $spaccesssync = new \local_o365\task\sharepointaccesssync();
            $spaccesssync->set_custom_data([
                'roleid' => $roleid,
                'userid' => $userid,
                'contextid' => $contextid,
            ]);
            \core\task\manager::queue_adhoc_task($spaccesssync);
            return true;
        }
    }

    /**
     * Handle role_assigned event
     *
     * Does the following:
     *     - check if the assigned role has the permission needed to access course sharepoint sites.
     *     - if it does, add the assigned user to the course sharepoint sites as a contributor.
     *
     * @param \core\event\role_assigned $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_role_assigned(\core\event\role_assigned $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        return static::sync_spsite_access_for_roleassign_change($event->objectid, $event->relateduserid, $event->contextid);
    }

    /**
     * Handle role_unassigned event
     *
     * Does the following:
     *     - check if, by unassigning this role, the related user no longer has the required capability to access course sharepoint
     *       sites. If they don't, remove them from the sharepoint sites' contributor groups.
     *
     * @param \core\event\role_unassigned $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_role_unassigned(\core\event\role_unassigned $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        return static::sync_spsite_access_for_roleassign_change($event->objectid, $event->relateduserid, $event->contextid);
    }

    /**
     * Handle role_capabilities_updated event
     *
     * Does the following:
     *     - check if the required capability to access course sharepoint sites was removed. if it was, check if affected users
     *       no longer have the required capability to access course sharepoint sites. If they don't, remove them from the
     *       sharepoint sites' contributor groups.
     *
     * @param \core\event\role_capabilities_updated $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_role_capabilities_updated(\core\event\role_capabilities_updated $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        $roleid = $event->objectid;
        $contextid = $event->contextid;

        // Role changes can be pretty heavy - run in cron.
        $spaccesssync = new \local_o365\task\sharepointaccesssync();
        $spaccesssync->set_custom_data(['roleid' => $roleid, 'userid' => '*', 'contextid' => null]);
        \core\task\manager::queue_adhoc_task($spaccesssync);
        return true;
    }

    /**
     * Handle role_deleted event
     *
     * Does the following:
     *     - Unfortunately the role has already been deleted when we hear about it here, and have no way to determine the affected
     *     users. Therefore, we have to do a global sync.
     *
     * @param \core\event\role_deleted $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_role_deleted(\core\event\role_deleted $event) {
        if (\local_o365\utils::is_configured() !== true) {
            return false;
        }
        $roleid = $event->objectid;

        // Role deletions can be heavy - run in cron.
        $spaccesssync = new \local_o365\task\sharepointaccesssync();
        $spaccesssync->set_custom_data(['roleid' => '*', 'userid' => '*', 'contextid' => null]);
        \core\task\manager::queue_adhoc_task($spaccesssync);
        return true;
    }

    /**
     * Handle user_deleted event.
     *
     * @param \core\event\user_deleted $event The triggered event.
     * @return bool Success/Failure.
     */
    public static function handle_user_deleted(\core\event\user_deleted $event) {
        global $DB;
        $userid = $event->objectid;
        $DB->delete_records('local_o365_token', ['user_id' => $userid]);
        $DB->delete_records('local_o365_objects', ['type' => 'user', 'moodleid' => $userid]);
        $DB->delete_records('local_o365_appassign', ['user_id' => $userid]);
        return true;
    }
}
