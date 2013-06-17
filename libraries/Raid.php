<?php

/**
 * Raid class.
 *
 * @category   apps
 * @package    raid
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2011 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/raid/
 */

///////////////////////////////////////////////////////////////////////////////
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Lesser General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\raid;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('raid');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Configuration_File as Configuration_File;
use \clearos\apps\base\Engine as Engine;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Shell as Shell;
use \clearos\apps\mail_notification\Mail_Notification as Mail_Notification;
use \clearos\apps\network\Hostname as Hostname;
use \clearos\apps\tasks\Cron as Cron;

clearos_load_library('base/Configuration_File');
clearos_load_library('base/Engine');
clearos_load_library('base/File');
clearos_load_library('base/Shell');
clearos_load_library('mail_notification/Mail_Notification');
clearos_load_library('network/Hostname');
clearos_load_library('tasks/Cron');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\Validation_Exception as Validation_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/Validation_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Raid class.
 *
 * @category   apps
 * @package    raid
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2011 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/raid/
 */

class Raid extends Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const FILE_CONFIG = '/etc/clearos/raid.conf';
    const FILE_MDSTAT = '/proc/mdstat';
    const FILE_RAID_STATUS = 'raid.status';
    const FILE_CROND = "app-raid";
    const DEFAULT_CRONTAB_TIME = "0,30 * * * *";
    const CMD_MDADM = '/sbin/mdadm';
    const CMD_DD = '/bin/dd';
    const CMD_CAT = '/bin/cat';
    const CMD_DF = '/bin/df';
    const CMD_DIFF = '/usr/bin/diff';
    const CMD_FDISK = '/sbin/fdisk';
    const CMD_SFDISK = '/sbin/sfdisk';
    const CMD_SWAPON = '/sbin/swapon';
    const CMD_RAID_SCRIPT = '/usr/bin/raid-notification';
    const TYPE_UNKNOWN = 0;
    const TYPE_SOFTWARE = 1;
    const TYPE_3WARE = 2;
    const TYPE_LSI = 3;
    const STATUS_CLEAN = 0;
    const STATUS_DEGRADED = 1;
    const STATUS_SYNCING = 2;
    const STATUS_SYNC_PENDING = 3;
    const STATUS_REMOVED = 4;
    const STATUS_SPARE = 5;

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $mdstat = Array();
    protected $config = NULL;
    protected $type = NULL;
    protected $status = NULL;
    protected $is_loaded = FALSE;

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Raid constructor.
     */

    function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);
    }

    /**
     * Returns type of RAID.
     *
     * @return mixed type of software RAID (false if none)
     * @throws Engine_Exception
     */

    function get_level()
    {
        clearos_profile(__METHOD__, __LINE__);

	// Test for software RAID
        $shell = new Shell();
	$args = self::FILE_MDSTAT;
	$retval = $shell->execute(self::CMD_CAT, $args);

	if ($retval == 0) {
	    $lines = $shell->get_output();
	    foreach ($lines as $line) {
		if (preg_match("/^Personalities : (.*)$/", $line, $match)) {
			$unformatted = preg_replace('/\[|\]/', '', strtoupper($match[1]));
			if (preg_match("/^(RAID)(\d+)$/", $unformatted, $match))
			    return $match[1] . '-' . $match[2];
			else
			    return $unformatted;
		}
	    }
	}
	return FALSE;
    }

    /**
     * Returns the mount point.
     *
     * @param String $dev a device
     *
     * @return string the mount point
     * @throws Engine_Exception
     */

    function get_mount($dev)
    {
        clearos_profile(__METHOD__, __LINE__);

        $mount = '';
        $shell = new Shell();
        $args = $dev;
        $retval = $shell->execute(self::CMD_DF, $args);

        if ($retval != 0) {
            $errstr = $shell->get_last_output_line();
            throw new Engine_Exception($errstr, CLEAROS_WARNING);
        } else {
            $lines = $shell->get_output();
            foreach ($lines as $line) {
                if (preg_match("/^" . str_replace('/', "\\/", $dev) . ".*$/", $line)) {
                    $parts = preg_split("/\s+/", $line);
                    $mount = trim($parts[5]);
                    break;
                }
            }
        }

        return $mount;
    }

    /**
     * Get the notification email.
     *
     * @return String  notification email
     * @throws Engine_Exception
     */

    function get_email()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_config();

        return $this->config['email'];
    }

    /**
     * Get the monitor status.
     *
     * @return boolean TRUE if monitoring is enabled
     */

    function get_monitor()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $cron = new Cron();
            if ($cron->exists_configlet(self::FILE_CROND))
                return TRUE;
            return FALSE;
        } catch (Exception $e) {
            return FALSE;
        }
    }

    /**
     * Get the notify status.
     *
     * @return String  notification email
     * @throws Engine_Exception
     */

    function get_devices_in_use()
    {
        clearos_profile(__METHOD__, __LINE__);

        $devicesinuse = array();

        // Get all block devices in use

        $myarrays = $this->get_arrays();

        foreach ($myarrays as $array) {
            if (isset($array['devices']) && is_array($array['devices'])) {
                foreach ($array['devices'] as $device)
                $devicesinuse[] = $device['dev'];
            }
        }

        // Add swap
        try {
            $shell = new Shell();
            $args = '-s';
            $retval = $shell->execute(self::CMD_SWAPON, $args);

            if ($retval == 0) {
                $lines = $shell->get_output();
                foreach ($lines as $line) {
                    if (preg_match("/^\/dev\/(\S*).*$/", $line, $match))
                        $devicesinuse[] = $match[1];
                }
            }
        } catch (Exception $e) {
            // Ignore

        }

        return $devicesinuse;
    }

    /**
     * Get partition table.
     *
     * @param string $device RAID device
     *
     * @return String  $device  device
     * @throws Engine_Exception
     */

    function get_partition_table($device)
    {
        clearos_profile(__METHOD__, __LINE__);

        $table = array();

        try {
            $shell = new Shell();
            $args = '-d ' . $device;
            $options['env'] = "LANG=en_US";
            $retval = $shell->execute(self::CMD_SFDISK, $args, TRUE, $options);

            if ($retval != 0) {
                $errstr = $shell->get_last_output_line();
                throw new Engine_Exception($errstr, CLEAROS_WARNING);
            } else {
                $lines = $shell->get_output();
                $regex = "/^\/dev\/(\S+) : start=\s*(\d+), size=\s*(\d+), Id=(\S+)(,\s*.*$|$)/";
                foreach ($lines as $line) {
                    if (preg_match($regex, $line, $match)) {
                        $table[] = array(
                        'size' => $match[3],
                        'id' => $match[4],
                        'bootable' => ($match[5]) ? 1 : 0, 'raw' => $line
                        );
                    }
                }
            }

            return $table;
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e . " ($device)"), CLEAROS_ERROR);
        }
    }

    /**
     * Copy a partition table from one device to another.
     *
     * @param string $from from partition device
     * @param string $to   to partition device
     *
     * @return void
     * @throws Engine_Exception
     */

    function copy_partition_table($from, $to)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $shell = new Shell();
            $args = '-d ' . $from . ' > ' . CLEAROS_TEMP_DIR . '/pt.txt';
            $options['env'] = "LANG=en_US";
            $retval = $shell->execute(self::CMD_SFDISK, $args, TRUE, $options);

            if ($retval != 0) {
                $errstr = $shell->get_last_output_line();
                throw new Engine_Exception($errstr, CLEAROS_WARNING);
            }

            $args = '-f ' . $to . ' < ' . CLEAROS_TEMP_DIR . '/pt.txt';
            $options['env'] = "LANG=en_US";
            $retval = $shell->execute(self::CMD_SFDISK, $args, TRUE, $options);

            if ($retval != 0) {
                $errstr = $shell->get_last_output_line();
                throw new Engine_Exception($errstr, CLEAROS_WARNING);
            }
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    /**
     * Performs a sanity check on partition table to see it matches.
     *
     * @param string $array the array to find a device that is clean
     * @param string $check the device to check partition against
     *
     * @return array
     * @throws Engine_Exception
     */

    function sanity_check_partition($array, $check)
    {
        clearos_profile(__METHOD__, __LINE__);

        $partition_match = array('ok' => FALSE);

        try {
            $myarrays = $this->get_arrays();
            foreach ($myarrays as $dev => $myarray) {
                if ($dev != $array)
                    continue;

                if (isset($myarray['devices']) && is_array($myarray['devices'])) {
                    foreach ($myarray['devices'] as $device) {
                        // Make sure it is clean

                        if ($device['status'] != self::STATUS_CLEAN)
                            continue;

                        $partition_match['dev'] = preg_replace("/\d/", "", $device['dev']);
                        $good = $this->get_partition_table($partition_match['dev']);
                        $check = $this->get_partition_table(preg_replace("/\d/", "", $check));
                        $ok = TRUE;

                        // Check that the same number of partitions exist

                        if (count($good) != count($check))
                            $ok = FALSE;

                        $raw = array();

                        for ($index = 0; $index < count($good); $index++) {
                            if ($check[$index]['size'] < $good[$index]['size'])
                                $ok = FALSE;

                            if ($check[$index]['id'] != $good[$index]['id'])
                                $ok = FALSE;

                            if ($check[$index]['bootable'] != $good[$index]['bootable'])
                                $ok = FALSE;

                            $raw[] = $good[$index]['raw'];
                        }

                        $partition_match['table'] = $raw;

                        if ($ok) {
                            $partition_match['ok'] = TRUE;
                            break;
                        }
                    }
                }
            }

            return $partition_match;
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    /**
     * Checks the change of status of the RAID array.
     *
     * @return mixed array if RAID status has changed, NULL otherwise
     * @throws Engine_Exception
     */

    function check_status_change($force = FALSE)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
	    $lines = $this->_create_report();

            $file = new File(CLEAROS_TEMP_DIR . '/' . self::FILE_RAID_STATUS);

            $first_check = FALSE;
            if ($file->exists()) {
                $file->move_to(CLEAROS_TEMP_DIR . '/' . self::FILE_RAID_STATUS . '.orig');
                $file = new File(CLEAROS_TEMP_DIR . '/' . self::FILE_RAID_STATUS);
            } else {
                $first_check = TRUE;
            }

            $file->create("webconfig", "webconfig", 0644);
            $file->dump_contents_from_array($lines);

            // Diff files to see if notification should be sent
            $retval = -1;
            if (!$first_check) {
                $shell = new Shell();
                $args = CLEAROS_TEMP_DIR . '/raid.status ' . CLEAROS_TEMP_DIR . '/raid.status.orig';
                $retval = $shell->execute(self::CMD_DIFF, $args);
            }

            if ($retval != 0)
                $this->send_status_change_notification($lines);
            else if (!$force)
                return NULL;

            return $lines;
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    /**
     * Sends a status change notification to admin.
     *
     * @param string $lines the message content
     *
     * @return void
     * @throws Engine_Exception
     */

    function send_status_change_notification($lines)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            if (!$this->get_monitor() || $this->get_email() == '')
                return;

            $mailer = new Mail_Notification();
            $hostname = new Hostname();
            $subject = lang('raid_status') . ' - ' . $hostname->get();
            $body = "\n\n" . lang('raid_status') . ":\n";
            $body .= str_pad('', strlen(lang('raid_status') . ':'), '=') . "\n\n";

            $thedate = strftime("%b %e %Y");
            $thetime = strftime("%T %Z");
            $body .= str_pad(lang('base_date') . ':', 16) . "\t" . $thedate . ' ' . $thetime . "\n";
            $body .= str_pad(lang('base_status') . ':', 16) . "\t" . $this->status . "\n\n";
            foreach ($lines as $line)
                $body .= $line . "\n";
            $mailer->add_recipient($this->get_email());
            $mailer->set_message_subject($subject);
            $mailer->set_message_body($body);

            $mailer->set_sender($this->get_email());
            $mailer->send();
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    /**
     * Set the RAID notificatoin email.
     *
     * @param string $email a valid email
     *
     * @return void
     * @throws Engine_Exception Validation_Exception
     */

    function set_email($email)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_config();

        // Validation
        // ----------

        Validation_Exception::is_valid($this->validate_email($email));

        $this->_set_parameter('email', $email);
    }

    /**
     * Set RAID monitoring status.
     *
     * @param boolean $monitor toggles monitoring
     *
     * @return void
     * @throws Engine_Exception Validation_Exception
     */

    function set_monitor($monitor)
    {
        clearos_profile(__METHOD__, __LINE__);
        try {
            $cron = new Cron();
            if ($cron->exists_configlet(self::FILE_CROND) && $monitor) {
                return;
            } else if ($cron->exists_configlet(self::FILE_CROND) && !$monitor) {
                $cron->delete_configlet(self::FILE_CROND);
            } else if (!$cron->exists_configlet(self::FILE_CROND) && $monitor) {
                $payload  = "# Created by API\n";
                $payload .= self::DEFAULT_CRONTAB_TIME . " root " . self::CMD_RAID_SCRIPT . " >/dev/NULL 2>&1";
                $cron->add_configlet(self::FILE_CROND, $payload);
            }
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    /**
     * Set RAID notification.
     *
     * @param boolean $status toggles notification
     *
     * @return void
     * @throws Engine_Exception
     */

    function set_notify($status)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_config();

        $this->_set_parameter('notify', (isset($status) && $status ? 1 : 0));
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
    * Loads configuration files.
    *
    * @return void
    * @throws Engine_Exception
    */

    protected function _load_config()
    {
        clearos_profile(__METHOD__, __LINE__);

        $configfile = new Configuration_File(self::FILE_CONFIG);
            
        $this->config = $configfile->Load();

        $this->is_loaded = TRUE;
    }

    /**
     * Gets the status according to mdstat.
     *
     * @access private
     *
     * @return void
     * @throws Engine_Exception
     */
    private function _get_md_stat()
    {
        clearos_profile(__METHOD__, __LINE__);

        $shell = new Shell();
        $args = self::FILE_MDSTAT;
        $options['env'] = "LANG=en_US";
        $retval = $shell->execute(self::CMD_CAT, $args, FALSE, $options);
        if ($retval != 0) {
            $errstr = $shell->get_last_output_line();
            throw new Engine_Exception($errstr, COMMON_WARNING);
        } else {
            $this->mdstat = $shell->get_output();
        }
    }

    /**
     * Generic set routine.
     *
     * @param string $key   key name
     * @param string $value value for the key
     *
     * @return  void
     * @throws Engine_Exception
     */

    function _set_parameter($key, $value)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $file = new File(self::FILE_CONFIG, TRUE);
            $match = $file->replace_lines("/^$key\s*=\s*/", "$key = $value\n");

            if (!$match)
                $file->add_lines("$key = $value\n");
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }

        $this->is_loaded = FALSE;
    }

    /**
     * Report for software RAID.
     *
     * @return array
     * @throws Engine_Exception
     */

    function _create_report()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->status = lang('raid_clean');

        try {
            $padding = array(10, 10, 10, 10);
            $lines = array();
            $lines[] = str_pad(lang('raid_array'), $padding[0]) . "\t" .
                str_pad(lang('raid_size'), $padding[1]) . "\t" .
                str_pad(lang('raid_mount'), $padding[2]) . "\t" .
                str_pad(lang('raid_level'), $padding[3]) . "\t" .
                lang('base_status');
            $lines[] = str_pad('', strlen($lines[0]) + 4*4, '-');
            $myarrays = $this->get_arrays();
            foreach ($myarrays as $dev => $myarray) {
                $status = lang('raid_clean');
                $mount = $this->get_mount($dev);

                if ($myarray['status'] != self::STATUS_CLEAN) {
                    $status = lang('raid_degraded');
                    $this->status = lang('raid_degraded');
                }

                foreach ($myarray['devices'] as $index => $details) {
                    if ($details['status'] == self::STATUS_SYNCING) {
                        $status = lang('raid_syncing') . ' (' . $details['dev'] . ') - ' . $details['recovery'] . '%';
                        $this->status = lang('raid_syncing');
                    } else if ($details['status'] == self::STATUS_SYNC_PENDING) {
                        $status = lang('raid_sync_pending') . ' (' . $details['dev'] . ')';
                    } else if ($details['status'] == self::STATUS_DEGRADED) {
                        $status = lang('raid_degraded') . ' (' . $details['dev'] . ' ' . lang('raid_failed') . ')';
                    }
                }

		
                $lines[] = str_pad($dev, $padding[0]) . "\t" .
                    str_pad(intval(intval($myarray['size'])/(1024*2024)) . lang('base_megabytes'), $padding[1]) . "\t" .
                    str_pad($mount, $padding[2]) . "\t" . str_pad($myarray['level'], $padding[3]) . "\t" . $status;
            }

            return $lines;
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e), CLEAROS_ERROR);
        }
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   R O U T I N E S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for email
     *
     * @param string $email email
     *
     * @return boolean TRUE if email is valid
     */

    public function validate_email($email)
    {
        clearos_profile(__METHOD__, __LINE__);

        $notify = new Mail_Notification();

        try {
            Validation_Exception::is_valid($notify->validate_email($email));
        } catch (Validation_Exception $e) {
            return lang('raid_email_is_invalid');
        }
    }

    /**
     * Validation routine for monitor setting
     *
     * @param boolean $monitor monitor flag
     *
     * @return boolean TRUE if monitor is valid
     */

    public function validate_monitor($monitor)
    {
        clearos_profile(__METHOD__, __LINE__);
    }

    /**
     * Validation routine for notify setting
     *
     * @param boolean $notify notify flag
     *
     * @return boolean TRUE if notify is valid
     */

    public function validate_notify($notify)
    {
        clearos_profile(__METHOD__, __LINE__);
    }

    /**
     * Returns RAID arrays.
     *
     * @return Array
     * @throws Engine_Exception
     */

    function get_arrays()
    {

        clearos_profile(__METHOD__, __LINE__);

        $myarrays = Array();

        $this->_get_md_stat();

        $dev = '';
        $physical_devices = Array();
        $raid_level = 0;
        $clean_array = TRUE;
        foreach ($this->mdstat as $line) {
            if (preg_match("/^md([[:digit:]]+)[[:space:]]*:[[:space:]]*(.*)$/", $line, $match)) {
                $dev = '/dev/md' . $match[1];
                list($state, $level, $device_list) = explode(' ', $match[2], 3);
                // Always 'active' and not very useful
                $myarrays[$dev]['state'] = $state;
                $myarrays[$dev]['status'] = self::STATUS_CLEAN;
                $myarrays[$dev]['level'] = strtoupper($level);
                // Try to format for consistency (RAID-1, not RAID1)
                if (preg_match("/^RAID(\d+)$/", strtoupper($level), $match)) {
                    $myarrays[$dev]['level'] = 'RAID-' . $match[1];
                    $raid_level = $match[1];
                }
                
                $devices = explode(' ', $device_list);
                $members = Array();
                foreach ($devices as $device) {
                    if (preg_match("/^(.*)\\[([[:digit:]]+)\\](.*)$/", trim($device), $match))
                        $members[$match[2]] = preg_match("/^\\/dev\\//", $match[1]) ? $match[1] : '/dev/' . $match[1];
                }
                foreach ($members as $index => $member) {
                    $myarrays[$dev]['devices'][$index]['dev'] = $member;
                    
                    if (!in_array(preg_replace("/\d+/", "", $member), $physical_devices))
                        $physical_devices[] = preg_replace("/\d+/", "", $member);
                }
            } else if (preg_match("/^[[:space:]]*([[:digit:]]+)[[:space:]]*blocks[[:space:]]*.*\[(.*)\]$/", $line, $match)) {
                $myarrays[$dev]['size'] = $match[1]*1024;
                $clean_array = FALSE;
                if (preg_match("/.*_.*/", $match[2]))
                    $myarrays[$dev]['status'] = self::STATUS_DEGRADED;
                $status = str_split($match[2]);
                $myarrays[$dev]['number'] = count($status);
                $counter = 0;
                foreach ($myarrays[$dev]['devices'] as $index => $myarray) {
                    // If in degraded mode, any index greater than or equal to total disk has failed
                    if ($index >= $myarrays[$dev]['number']) {
                        $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_SPARE;
                        continue;
                    } else if ($status[$counter] == "_") {
                        $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_DEGRADED;
                    } else {
                        $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_CLEAN;
                    }
                    $counter++;
                }
            } else if (preg_match("/^[[:space:]]*(.*)recovery =[[:space:]]+([[:digit:]]+\\.[[:digit:]]+)%[[:space:]]*(.*)$/", $line, $match)) {
                $clean_array = FALSE;
                foreach ($myarrays[$dev]['devices'] as $index => $myarray) {
                    if ($myarrays[$dev]['devices'][$index]['status'] == self::STATUS_DEGRADED) {
                        $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_SYNCING;
                        $myarrays[$dev]['devices'][$index]['recovery'] = $match[2];
                    }
                }
            } else if (preg_match("/^[[:space:]]*(.*)resync =[[:space:]]+([[:digit:]]+\\.[[:digit:]]+)%[[:space:]]*(.*)$/", $line, $match)) {
                $clean_array = FALSE;
                $this->_set_parameter('copy_mbr', '0');
                foreach ($myarrays[$dev]['devices'] as $index => $myarray) {
                    $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_SYNCING;
                    $myarrays[$dev]['devices'][$index]['recovery'] = $match[2];
                }
            } else if (preg_match("/^.*resync=DELAYED.*$/", $line, $match)) {
                $clean_array = FALSE;
                foreach ($myarrays[$dev]['devices'] as $index => $myarray)
                    $myarrays[$dev]['devices'][$index]['status'] = self::STATUS_SYNC_PENDING;
            }
        }
        
        // TODO ksort($myarrays);
        //if ((!isset($this->config['copy_mbr']) || $this->config['copy_mbr'] == 0) && $raid_level == 1 && $clean_array) {
        if (FALSE) {
            sort($physical_devices);
            $is_first = TRUE;
            foreach ($physical_devices as $dev) {
                if ($is_first) {
                    $copy_from = $dev;
                    $is_first = FALSE;
                    continue;
                }
                $shell = new Shell();
                $args = 'if=' . $copy_from . ' of=' . $dev . ' bs=512 count=1';
                $retval = $shell->execute(self::CMD_DD, $args, TRUE);
            }
            $this->_set_parameter('copy_mbr', '1');
            $this->loaded = FALSE;
        }
        return $myarrays;
    }

    /**
     * Removes a device from the specified array.
     *
     * @param string $array  the array
     * @param string $device the device
     *
     * @return void
     * @throws Engine_Exception
     */

    function remove_device($array, $device)
    {
        clearos_profile(__METHOD__, __LINE__);

        $shell = new Shell();
        $args = '-r ' . $array . ' ' . $device;
        $options['env'] = "LANG=en_US";
        $retval = $shell->execute(self::CMD_MDADM, $args, TRUE, $options);
        if ($retval != 0) {
            $errstr = $shell->get_last_output_line();
            throw new Engine_Exception($errstr, COMMON_WARNING);
        } else {
            $this->mdstat = $shell->get_output();
        }
    }

    /**
     * Repair an array with the specified device.
     *
     * @param string $array  the array
     * @param string $device the device
     *
     * @return void
     * @throws Engine_Exception
     */

    function repair_array($array, $device)
    {
        clearos_profile(__METHOD__, __LINE__);

        $shell = new Shell();
        $args = '-a ' . $array . ' ' . $device;
        $options['env'] = "LANG=en_US";
        $retval = $shell->execute(self::CMD_MDADM, $args, TRUE, $options);
        if ($retval != 0) {
            $errstr = $shell->get_last_output_line();
            throw new Engine_Exception($errstr, COMMON_WARNING);
        } else {
            $this->mdstat = $shell->get_output();
        }
    }
}
