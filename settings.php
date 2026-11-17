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
 * Admin settings tree setup for the Custom SQL admin report.
 *
 * @package report_customsql
 * @copyright 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/report/customsql/locallib.php');

    $settings->add(new admin_setting_heading(
        'report_customsql/customsqlwebservice',
        get_string('customsqlwebservice', 'report_customsql'),
        get_string('customsqlwebservicedesc', 'report_customsql')
    ));
    $customsqlcategories = [];
    if (!during_initial_install() && get_config('report_customsql', 'version')) {
        $customsqlcategories = report_customsql_category_options();
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'report_customsql/customsqlcategories',
        get_string('customsqlcategories', 'report_customsql'),
        get_string('customsqlcategoriesdesc', 'report_customsql'),
        [],
        $customsqlcategories
    ));
    $settings->add(new admin_setting_heading(
        'report_customsql/customsqlgraphitepaths',
        get_string('customsqlgraphitepaths', 'report_customsql'),
        get_string('customsqlgraphitepathsdesc', 'report_customsql')
    ));
    $selectedcategories = get_config('report_customsql', 'customsqlcategories');
    if (!empty($selectedcategories)) {
        foreach (explode(',', $selectedcategories) as $categoryid) {
            $categoryid = (int) $categoryid;
            if (isset($customsqlcategories[$categoryid])) {
                $categoryname = $customsqlcategories[$categoryid];
                $settings->add(new admin_setting_configtext(
                    'report_customsql/customsqlgraphitepath_' . $categoryid,
                    get_string('customsqlgraphitepath', 'report_customsql', $categoryname),
                    get_string('customsqlgraphitepathdesc', 'report_customsql', $categoryname),
                    'mebis.lern.count.',
                    PARAM_PATH
                ));
            }
        }
    }

    // Start of week, used for the day to run weekly reports.
    $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $days = array_map(function ($day) {
        return get_string($day, 'calendar');
    }, $days);

    $default = \core_calendar\type_factory::get_calendar_instance()->get_starting_weekday();

    // Setting this option to -1 will use the value from the site calendar.
    $options = [-1 => get_string('startofweek_default', 'report_customsql', $days[$default])] + $days;
    $settings->add(new admin_setting_configselect(
        'report_customsql/startwday',
        get_string('startofweek', 'report_customsql'),
        get_string('startofweek_desc', 'report_customsql'),
        -1,
        $options
    ));

    $settings->add(new admin_setting_configtext_with_maxlength(
        'report_customsql/querylimitdefault',
        get_string('querylimitdefault', 'report_customsql'),
        get_string('querylimitdefault_desc', 'report_customsql'),
        5000,
        PARAM_INT,
        null,
        10
    ));

    $settings->add(new admin_setting_configtext_with_maxlength(
        'report_customsql/querylimitmaximum',
        get_string('querylimitmaximum', 'report_customsql'),
        get_string('querylimitmaximum_desc', 'report_customsql'),
        5000,
        PARAM_INT,
        null,
        10
    ));

    // Background execution settings.
    $settings->add(new admin_setting_heading(
        'report_customsql/backgroundexecutionheading',
        get_string('backgroundexecutionsettings', 'report_customsql'),
        get_string('backgroundexecutionsettings_desc', 'report_customsql')
    ));

    $settings->add(new admin_setting_configtext(
        'report_customsql/maxconcurrentexecutions',
        get_string('maxconcurrentexecutions', 'report_customsql'),
        get_string('maxconcurrentexecutions_desc', 'report_customsql'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'report_customsql/maxuserexecutions',
        get_string('maxuserexecutions', 'report_customsql'),
        get_string('maxuserexecutions_desc', 'report_customsql'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'report_customsql/executionretentiondays',
        get_string('executionretentiondays', 'report_customsql'),
        get_string('executionretentiondays_desc', 'report_customsql'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_customsql/enablebackgroundexecution',
        get_string('enablebackgroundexecution', 'report_customsql'),
        get_string('enablebackgroundexecution_desc', 'report_customsql'),
        1
    ));
}

$ADMIN->add('reports', new admin_externalpage(
    'report_customsql',
    get_string('pluginname', 'report_customsql'),
    new moodle_url('/report/customsql/index.php'),
    'report/customsql:view'
));
