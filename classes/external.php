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
 * Class of plugins external functions.
 *
 * @package   report_customsql
 * @copyright 2018 Andre Scherl, ISB Bayern
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->dirroot/report/customsql/locallib.php");

/**
 * External API for report_customsql webservices.
 *
 * @package   report_customsql
 * @copyright 2018 Andre Scherl, ISB Bayern
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_customsql_external extends core_external\external_api {
    /**
     * Describe the parameters for a complete query result.
     *
     * @return external_function_parameters
     */
    public static function get_query_result_parameters() {
        return new external_function_parameters([
            'queryname' => new external_value(PARAM_TEXT, 'Name of the query.'),
        ]);
    }

    /**
     * Execute a predefined query without the report display row limit.
     *
     * @param string $queryname Name of the saved query.
     * @return array Column names and rows containing nullable string values.
     */
    public static function get_query_result($queryname) {
        global $DB;

        $params = self::validate_parameters(self::get_query_result_parameters(), ['queryname' => $queryname]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('report/customsql:view', $context);

        $report = $DB->get_record('report_customsql_queries', ['displayname' => $params['queryname']]);
        if (!$report) {
            throw new \moodle_exception('invalidreportid', 'report_customsql');
        }
        if (!empty($report->capability)) {
            require_capability($report->capability, $context);
        }

        $queryparams = [];
        if (!empty($report->queryparams)) {
            $queryparams = json_decode($report->queryparams, true);
            if (!is_array($queryparams)) {
                $queryparams = unserialize($report->queryparams, ['allowed_classes' => false]);
            }
            if (!is_array($queryparams)) {
                throw new \invalid_parameter_exception('Invalid saved query parameters.');
            }
        }

        $sql = report_customsql_prepare_sql($report, time());
        $recordset = report_customsql_execute_query($sql, $queryparams, 0);
        $result = ['columns' => [], 'rows' => []];
        try {
            foreach ($recordset as $record) {
                if (!$result['columns']) {
                    $result['columns'] = array_keys((array) $record);
                }
                $values = [];
                foreach ($record as $value) {
                    $values[] = $value === null ? null : (string) $value;
                }
                $result['rows'][] = ['values' => $values];
            }
        } finally {
            $recordset->close();
        }
        return $result;
    }

    /**
     * Describe the complete query result.
     *
     * @return external_single_structure
     */
    public static function get_query_result_returns() {
        return new external_single_structure([
            'columns' => new external_multiple_structure(new external_value(PARAM_RAW, 'SQL column name.')),
            'rows' => new external_multiple_structure(new external_single_structure([
                'values' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Cell value as text, or null for SQL NULL.', VALUE_REQUIRED, null, NULL_ALLOWED)
                ),
            ])),
        ]);
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function get_simple_value_parameters() {

        return new external_function_parameters([
            'queryname' => new external_value(PARAM_TEXT, 'Name of the query.'),
        ]);
    }

    /**
     * Execute the (in admin panel) predefined query that returns one simple value.
     *
     * @param string $queryname
     * @return string
     */
    public static function get_simple_value($queryname) {
        global $DB, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::get_simple_value_parameters(), ['queryname' => $queryname]);
        $queryname = $params['queryname'];

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('report/customsql:view', $context);

        // Get the report and its settings.
        $report = $DB->get_record('report_customsql_queries', ['displayname' => $queryname]);
        if (!$report) {
            throw new \moodle_exception('invalidreportid', 'report_customsql');
        }

        // Check report-specific capability.
        if (!empty($report->capability)) {
            require_capability($report->capability, $context);
        }

        // Prepare and execute the query.
        $sql = report_customsql_prepare_sql($report, time());
        $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);
        $queryparams = !empty($report->queryparams) ? json_decode($report->queryparams, true) : [];
        if (!is_array($queryparams)) {
            // Fallback for legacy serialized data.
            $queryparams = !empty($report->queryparams) ? unserialize($report->queryparams) : [];
        }
        $querylimit  = !empty($report->querylimit) ? $report->querylimit : REPORT_CUSTOMSQL_MAX_RECORDS;
        $result = $DB->get_field_sql($sql, $queryparams);

        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function get_simple_value_returns() {

        return new external_value(PARAM_TEXT, 'Value parsed as text.');
    }
}
