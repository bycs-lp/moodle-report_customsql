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
     * Upgrade copies legacy settings without overwriting target settings.
     */
    public function test_upgrade_query_discovery_settings(): void {
        global $CFG;

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/report/customsql/db/upgrade.php');
        $this->resetAfterTest();
        set_config('version', 2025102102, 'report_customsql');
        set_config('customsqlcategories', '1,2', 'local_mbs');
        set_config('customsqlgraphitepath_1', 'legacy.first.', 'local_mbs');
        set_config('customsqlgraphitepath_2', 'legacy.second.', 'local_mbs');
        set_config('customsqlgraphitepath_1', 'existing.', 'report_customsql');
        set_config('unrelated', 'unchanged', 'local_mbs');

        $this->assertTrue(xmldb_report_customsql_upgrade(2025102102));
        $this->assertSame('1,2', get_config('report_customsql', 'customsqlcategories'));
        $this->assertSame('existing.', get_config('report_customsql', 'customsqlgraphitepath_1'));
        $this->assertSame('legacy.second.', get_config('report_customsql', 'customsqlgraphitepath_2'));
        $this->assertFalse(get_config('report_customsql', 'unrelated'));
        $this->assertSame('1,2', get_config('local_mbs', 'customsqlcategories'));
    }

    /**
     * Discovery only returns allowed categories and preserves Graphite paths.
     *
     * @runInSeparateProcess
     */
    public function test_get_queries(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $allowedid = $DB->insert_record('report_customsql_categories', (object) ['name' => 'Allowed']);
        $blockedid = $DB->insert_record('report_customsql_categories', (object) ['name' => 'Blocked']);
        $reportid = $this->create_a_database_row('Allowed query');
        $DB->set_field('report_customsql_queries', 'categoryid', $allowedid, ['id' => $reportid]);
        $reportid = $this->create_a_database_row('Blocked query');
        $DB->set_field('report_customsql_queries', 'categoryid', $blockedid, ['id' => $reportid]);
        set_config('customsqlcategories', $allowedid . ',999999', 'report_customsql');
        set_config('customsqlgraphitepath_' . $allowedid, 'mebis.lern.count.', 'report_customsql');
        set_config('customsqlcategories', $blockedid, 'local_mbs');

        $expected = [['displayname' => 'Allowed query', 'graphitepath' => 'mebis.lern.count.']];
        $result = \report_customsql_external::get_queries();
        $this->assertSame($expected, \report_customsql_external::clean_returnvalue(
            \report_customsql_external::get_queries_returns(),
            $result
        ));
        $this->assertSame($expected, \report_customsql_external::get_queries([$allowedid, $blockedid]));
        $this->assertSame([], \report_customsql_external::get_queries([$blockedid]));
        $this->assertSame([], \report_customsql_external::get_queries([999999]));
        unset_config('customsqlgraphitepath_' . $allowedid, 'report_customsql');
        $this->assertSame(
            [['displayname' => 'Allowed query', 'graphitepath' => '']],
            \report_customsql_external::get_queries([$allowedid])
        );
        set_config('customsqlcategories', '', 'report_customsql');
        $this->assertSame([], \report_customsql_external::get_queries());
    }

    /**
     * Query discovery requires its dedicated capability.
     *
     * @runInSeparateProcess
     */
    public function test_get_queries_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        \report_customsql_external::get_queries();
    }

    /**
     * A user with the discovery capability can list queries without admin access.
     *
     * @runInSeparateProcess
     */
    public function test_get_queries_with_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('report/customsql:usecustomsql', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);
        $this->assertSame([], \report_customsql_external::get_queries());
    }

    /**
     * Invalid category filters are rejected.
     *
     * @runInSeparateProcess
     */
    public function test_get_queries_invalid_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\invalid_parameter_exception::class);
        \report_customsql_external::get_queries(['invalid']);
    }

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
