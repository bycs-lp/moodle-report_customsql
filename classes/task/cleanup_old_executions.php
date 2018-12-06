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
 * Scheduled task to clean up old background query executions.
 *
 * @package    report_customsql
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_customsql\task;

use core\task\scheduled_task;

/**
 * Scheduled task to clean up old background query executions.
 *
 * This task removes old completed, failed, and cancelled executions
 * based on the retention period configured in plugin settings.
 *
 * @package    report_customsql
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_old_executions extends scheduled_task {
    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanupoldexecutionstask', 'report_customsql');
    }

    /**
     * Execute the task.
     *
     * Deletes old executions and their associated files based on the
     * retention period setting.
     */
    public function execute() {
        global $DB;

        // Get retention period from settings (in days).
        $retentiondays = get_config('report_customsql', 'executionretentiondays');
        if (empty($retentiondays)) {
            // Default to 30 days if not configured.
            $retentiondays = 30;
        }

        $cutofftime = time() - ($retentiondays * DAYSECS);

        mtrace('Cleaning up executions older than ' . userdate($cutofftime));

        // Get old executions that are completed, failed, or cancelled.
        $sql = "SELECT e.*
                  FROM {report_customsql_executions} e
                 WHERE e.status IN ('completed', 'failed', 'cancelled')
                   AND e.timecreated < :cutofftime";

        $params = ['cutofftime' => $cutofftime];
        $executions = $DB->get_records_sql($sql, $params);

        if (empty($executions)) {
            mtrace('No old executions to clean up.');
            return;
        }

        mtrace('Found ' . count($executions) . ' old executions to clean up.');

        $deletedcount = 0;
        $errorcount = 0;

        require_once(dirname(__FILE__) . '/../../locallib.php');

        foreach ($executions as $execution) {
            try {
                // Delete the stored file if exists.
                if ($execution->filename) {
                    $fs = get_file_storage();
                    $context = \context_system::instance();

                    $file = $fs->get_file(
                        $context->id,
                        'report_customsql',
                        'execution',
                        $execution->id,
                        '/',
                        $execution->filename
                    );

                    if ($file) {
                        $file->delete();
                        mtrace("  Deleted file for execution {$execution->id}: {$execution->filename}");
                    }
                }

                // Delete the database record.
                $DB->delete_records('report_customsql_executions', ['id' => $execution->id]);

                $deletedcount++;
                mtrace("  Deleted execution {$execution->id} (query: {$execution->queryid}, " .
                       "status: {$execution->status}, created: " . userdate($execution->timecreated) . ")");
            } catch (\Exception $e) {
                $errorcount++;
                mtrace("  ERROR deleting execution {$execution->id}: " . $e->getMessage());
            }
        }

        mtrace("Cleanup complete: {$deletedcount} executions deleted, {$errorcount} errors.");

        // Also clean up orphaned files (files without database records).
        $this->cleanup_orphaned_files();
    }

    /**
     * Clean up orphaned execution files.
     *
     * Removes files in the execution filearea that don't have a corresponding
     * database record.
     */
    protected function cleanup_orphaned_files() {
        global $DB;

        mtrace('Checking for orphaned execution files...');

        $fs = get_file_storage();
        $context = \context_system::instance();

        // Get all files in the execution filearea.
        $files = $fs->get_area_files(
            $context->id,
            'report_customsql',
            'execution',
            false,
            'itemid',
            false
        );

        if (empty($files)) {
            mtrace('No execution files found.');
            return;
        }

        mtrace('Found ' . count($files) . ' execution files to check.');

        $deletedcount = 0;

        foreach ($files as $file) {
            $executionid = $file->get_itemid();

            // Check if execution record exists.
            if (!$DB->record_exists('report_customsql_executions', ['id' => $executionid])) {
                try {
                    $file->delete();
                    $deletedcount++;
                    mtrace("  Deleted orphaned file for non-existent execution {$executionid}: " . $file->get_filename());
                } catch (\Exception $e) {
                    mtrace("  ERROR deleting orphaned file: " . $e->getMessage());
                }
            }
        }

        if ($deletedcount > 0) {
            mtrace("Deleted {$deletedcount} orphaned files.");
        } else {
            mtrace('No orphaned files found.');
        }
    }
}
