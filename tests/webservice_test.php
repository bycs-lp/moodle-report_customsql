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
 * Unit tests for the webservice of the custom SQL report.
 *
 * @package report_customsql
 * @copyright 2018 Andre Scherl, ISB Bayern
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_customsql;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/../locallib.php');

/**
 * Unit tests for the webservice of the custom SQL report.
 *
 * @package report_customsql
 * @copyright 2018 Andre Scherl, ISB Bayern
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_customsql_external
 */
final class webservice_test extends \advanced_testcase {
    /**
     * All rows, duplicate first-column values and SQL NULL survive the external schema.
     *
     * @runInSeparateProcess
     */
    public function test_get_query_result(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->create_a_database_row();
        $DB->update_record('report_customsql_queries', (object) [
            'id' => $reportid,
            'querysql' => 'SELECT 1 AS repeated, :value AS content, NULL AS optionalvalue '
                . 'UNION ALL SELECT 1, :other, NULL',
            'queryparams' => serialize(['value' => '<b>First & second</b>', 'other' => '0']),
            'querylimit' => 1,
        ]);

        $result = \report_customsql_external::get_query_result('number_of_custom_sql_queries');
        $cleaned = \report_customsql_external::clean_returnvalue(
            \report_customsql_external::get_query_result_returns(),
            $result
        );
        $this->assertSame([
            'columns' => ['repeated', 'content', 'optionalvalue'],
            'rows' => [
                ['values' => ['1', '<b>First & second</b>', null]],
                ['values' => ['1', '0', null]],
            ],
        ], $cleaned);
    }

    /**
     * JSON parameters and the legacy table prefix work for an empty result.
     *
     * @runInSeparateProcess
     */
    public function test_get_query_result_empty(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->create_a_database_row();
        $DB->update_record('report_customsql_queries', (object) [
            'id' => $reportid,
            'querysql' => 'SELECT id FROM prefix_report_customsql_queries WHERE id = :reportid',
            'queryparams' => json_encode(['reportid' => -1]),
        ]);
        $this->assertSame(
            ['columns' => [], 'rows' => []],
            \report_customsql_external::get_query_result('number_of_custom_sql_queries')
        );
    }

    /**
     * Unknown queries are rejected.
     *
     * @runInSeparateProcess
     */
    public function test_get_query_result_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidreportid', 'report_customsql'));
        \report_customsql_external::get_query_result('missing');
    }

    /**
     * The global report capability is required before looking up a query.
     *
     * @runInSeparateProcess
     */
    public function test_get_query_result_requires_view(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        \report_customsql_external::get_query_result('missing');
    }

    /**
     * A reader still needs the report-specific capability.
     *
     * @runInSeparateProcess
     */
    public function test_get_query_result_requires_report_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('report/customsql:view', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $reportid = $this->create_a_database_row();
        $DB->set_field('report_customsql_queries', 'capability', 'moodle/site:config', ['id' => $reportid]);
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        \report_customsql_external::get_query_result('number_of_custom_sql_queries');
    }

    /**
     * Test getting a simple value via webservice.
     *
     * @runInSeparateProcess
     */
    public function test_get_simple_value(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $displayname = 'test_query_counting_itself';
        $description = 'Count queries with exactly this name. Should be one.';

        $this->create_a_database_row($displayname, $description);
        $result = \report_customsql_external::get_simple_value($displayname);

        $this->assertEquals("1", $result);
    }

    /**
     * Create an entry in 'report_customsql_queries' table and return the id.
     *
     * @param string $displayname
     * @param string $description
     *
     * @return int The new query id.
     */
    private function create_a_database_row(
        $displayname = 'number_of_custom_sql_queries',
        $description = 'Count the custom sql queries.'
    ) {
        global $DB;
        $report = new \stdClass();
        $report->displayname = $displayname;
        $report->description = $description;
        $report->querysql = "SELECT count(DISTINCT id) as simplevalue FROM {report_customsql_queries}
            WHERE displayname = '$displayname'";
        $report->capability = 'report/customsql:view';
        $report->lastexecutiontime = 1;
        $report->runable = 'manual';
        $report->categoryid = 1;

        return $DB->insert_record('report_customsql_queries', $report);
    }
}
