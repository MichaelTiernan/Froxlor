<?php
namespace Froxlor\Frontend\Modules;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Florian Lippert <flo@syscp.org> (2003-2009)
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Panel
 *         
 */
use Froxlor\Api\Commands\Froxlor;
use Froxlor\Database\Database;
use Froxlor\Settings;
use Froxlor\Frontend\FeModule;

class AdminSettings extends FeModule
{

	public function overview()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}

		// get sql-root access data
		Database::needRoot(true);
		Database::needSqlData();
		$sql_root = Database::getSqlData();
		Database::needRoot(false);

		$settings_data = \Froxlor\PhpHelper::loadConfigArrayDir(\Froxlor\Froxlor::getInstallDir() . '/actions/admin/settings/');
		Settings::loadSettingsInto($settings_data);

		$part = isset($_GET['part']) ? $_GET['part'] : '';
		if ($part == '') {
			$part = isset($_POST['part']) ? $_POST['part'] : '';
		}

		if (isset($_POST['send']) && $_POST['send'] == 'send') {

			if ($part != '') {
				if ($part == 'all') {
					$settings_all = true;
					$settings_part = false;
				} else {
					$settings_all = false;
					$settings_part = true;
				}
				$only_enabledisable = false;
			} else {
				$settings_all = false;
				$settings_part = false;
				$only_enabledisable = true;
			}

			// check if the session timeout is too low #815
			if (isset($_POST['session_sessiontimeout']) && $_POST['session_sessiontimeout'] < 60) {
				\Froxlor\UI\Response::standard_error(array(
					\Froxlor\Frontend\UI::getLng('error.session_timeout'),
					\Froxlor\Frontend\UI::getLng('error.session_timeout_desc')
				));
			}

			if (\Froxlor\UI\Form::processFormEx($settings_data, $_POST, array(
				'filename' => 'index.php?module=AdminSettings&part=' . $part
			), $part, $settings_all, $settings_part, $only_enabledisable)) {
				\Froxlor\FroxlorLogger::getLog()->addInfo("rebuild configfiles due to changed setting");
				\Froxlor\System\Cronjob::inserttask('1');
				// Using nameserver, insert a task which rebuilds the server config
				\Froxlor\System\Cronjob::inserttask('4');
				// set quotas (if enabled)
				\Froxlor\System\Cronjob::inserttask('10');
				// cron.d file
				\Froxlor\System\Cronjob::inserttask('99');

				\Froxlor\UI\Response::standard_success('settingssaved', '', array(
					'filename' => 'index.php?module=AdminSettings&part=' . $part
				));
			}
		} else {

			$fields = \Froxlor\UI\Form::buildFormEx($settings_data, $part);

			\Froxlor\Frontend\UI::TwigBuffer('admin/settings/index.html.twig', array(
				'page_title' => \Froxlor\Frontend\UI::getLng('admin.serversettings'),
				'form_data' => $fields,
				'part' => $part
			));
		}
	}

	public function updatecounters()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}

		if (isset($_POST['send']) && $_POST['send'] == 'send') {

			\Froxlor\FroxlorLogger::getLog()->addInfo("updated resource-counters");
			$updatecounters = \Froxlor\User::updateCounters(true);

			\Froxlor\Frontend\UI::TwigBuffer('admin/settings/updatecounters.html.twig', array(
				'page_title' => $this->lng['admin']['updatecounters'],
				'counters' => $updatecounters
			));
		} else {
			\Froxlor\UI\HTML::askYesNo('admin_counters_reallyupdate', 'index.php?module=AdminSettings&view=' . __FUNCTION__);
		}
	}

	public function rebuildconfigs()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}

		if (isset($_POST['send']) && $_POST['send'] == 'send') {

			\Froxlor\FroxlorLogger::getLog()->addInfo("rebuild configfiles");
			\Froxlor\System\Cronjob::inserttask('1');
			\Froxlor\System\Cronjob::inserttask('10');
			// Using nameserver, insert a task which rebuilds the server config
			\Froxlor\System\Cronjob::inserttask('4');
			// cron.d file
			\Froxlor\System\Cronjob::inserttask('99');

			\Froxlor\UI\Response::standard_success('rebuildingconfigs', '', array(
				'filename' => 'index.php?module=AdminIndex'
			));
		} else {
			\Froxlor\UI\HTML::askYesNo('admin_configs_reallyrebuild', 'index.php?module=AdminSettings&view=' . __FUNCTION__);
		}
	}

	public function wipecleartextmailpws()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}

		if (isset($_POST['send']) && $_POST['send'] == 'send') {

			\Froxlor\FroxlorLogger::getLog()->addWarning("wiped all cleartext mail passwords");
			Database::query("UPDATE `" . TABLE_MAIL_USERS . "` SET `password` = '';");
			Database::query("UPDATE `" . TABLE_PANEL_SETTINGS . "` SET `value` = '0' WHERE `settinggroup` = 'system' AND `varname` = 'mailpwcleartext'");

			\Froxlor\UI\Response::standard_success('wipecleartextmailpws', '', array(
				'filename' => 'index.php?module=AdminIndex'
			));
		} else {
			\Froxlor\UI\HTML::askYesNo('admin_cleartextmailpws_reallywipe', 'index.php?module=AdminSettings&view=' . __FUNCTION__);
		}
	}

	/**
	 *
	 * @fixme get that back to the top-menu
	 */
	public function wipequotas()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}
		if (isset($_POST['send']) && $_POST['send'] == 'send') {

			\Froxlor\FroxlorLogger::getLog()->addWarning("wiped all mailquotas");
			// Set the quota to 0 which means unlimited
			Database::query("UPDATE `" . TABLE_MAIL_USERS . "` SET `quota` = '0';");
			Database::query("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `email_quota_used` = '0'");
			\Froxlor\UI\Response::standard_success('wipequotas', '', array(
				'filename' => 'index.php?module=AdminIndex'
			));
		} else {
			\Froxlor\UI\HTML::askYesNo('admin_quotas_reallywipe', 'index.php?module=AdminSettings&view=' . __FUNCTION__);
		}
	}

	public function export()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}
		// check for json-stuff
		if (! extension_loaded('json')) {
			\Froxlor\UI\Response::standard_error('jsonextensionnotfound');
		}
		// export
		try {
			$json_result = Froxlor::getLocal(\Froxlor\CurrentUser::getData())->exportSettings();
			$json_export = json_decode($json_result, true)['data'];
		} catch (\Exception $e) {
			\Froxlor\UI\Response::dynamic_error($e->getMessage());
		}
		header('Content-disposition: attachment; filename=Froxlor_settings-' . \Froxlor\Froxlor::VERSION . '-' . \Froxlor\Froxlor::DBVERSION . '_' . date('d.m.Y') . '.json');
		header('Content-type: application/json');
		echo $json_export;
		exit();
	}

	public function import()
	{
		if (\Froxlor\CurrentUser::getField('change_serversettings') != '1') {
			// not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}
		// check for json-stuff
		if (! extension_loaded('json')) {
			\Froxlor\UI\Response::standard_error('jsonextensionnotfound');
		}
		// import
		if (isset($_POST['send']) && $_POST['send'] == 'send') {
			// get uploaded file
			if (isset($_FILES["import_file"]["tmp_name"])) {
				$imp_content = file_get_contents($_FILES["import_file"]["tmp_name"]);
				try {
					Froxlor::getLocal(\Froxlor\CurrentUser::getData(), array(
						'json_str' => $imp_content
					))->importSettings();
				} catch (\Exception $e) {
					\Froxlor\UI\Response::dynamic_error($e->getMessage());
				}
				\Froxlor\UI\Response::standard_success('settingsimported', '', array(
					'filename' => 'index.php?module=AdminSettings'
				));
			}
			\Froxlor\UI\Response::dynamic_error("Upload failed<br>".var_export($_FILES, true));
		}
		\Froxlor\UI\Response::redirectTo("index.php?module=AdminSettings");
	}
}
/*
elseif ($page == 'phpinfo' && $userinfo['change_serversettings'] == '1') {
	ob_start();
	phpinfo();
	$phpinfo = array(
		'phpinfo' => array()
	);
	if (preg_match_all('#(?:<h2>(?:<a name=".*?">)?(.*?)(?:</a>)?</h2>)|(?:<tr(?: class=".*?")?><t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>)?)?</tr>)#s', ob_get_clean(), $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$end = array_keys($phpinfo);
			$end = end($end);
			if (strlen($match[1])) {
				$phpinfo[$match[1]] = array();
			} elseif (isset($match[3])) {
				$phpinfo[$end][$match[2]] = isset($match[4]) ? array(
					$match[3],
					$match[4]
				) : $match[3];
			} else {
				$phpinfo[$end][] = $match[2];
			}
		}
		$phpinfohtml = '';
		foreach ($phpinfo as $name => $section) {
			$phpinfoentries = "";
			foreach ($section as $key => $val) {
				if (is_array($val)) {
					eval("\$phpinfoentries .= \"" . \Froxlor\UI\Template::getTemplate("settings/phpinfo/phpinfo_3") . "\";");
				} elseif (is_string($key)) {
					eval("\$phpinfoentries .= \"" . \Froxlor\UI\Template::getTemplate("settings/phpinfo/phpinfo_2") . "\";");
				} else {
					eval("\$phpinfoentries .= \"" . \Froxlor\UI\Template::getTemplate("settings/phpinfo/phpinfo_1") . "\";");
				}
			}
			// first header -> show actual php version
			if (strtolower($name) == "phpinfo") {
				$name = "PHP " . PHP_VERSION;
			}
			eval("\$phpinfohtml .= \"" . \Froxlor\UI\Template::getTemplate("settings/phpinfo/phpinfo_table") . "\";");
		}
		$phpinfo = $phpinfohtml;
	} else {
		\Froxlor\UI\Response::standard_error($lng['error']['no_phpinfo']);
	}
	eval("echo \"" . \Froxlor\UI\Template::getTemplate("settings/phpinfo") . "\";");



} elseif ($page == 'enforcequotas' && $userinfo['change_serversettings'] == '1') {
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		// Fetch all accounts
		$result_stmt = Database::query("SELECT `quota`, `customerid` FROM `" . TABLE_MAIL_USERS . "`");

		if (Database::num_rows() > 0) {

			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
				`email_quota_used` = `email_quota_used` + :diff
				WHERE `customerid` = :customerid
			");

			while ($array = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
				$difference = Settings::Get('system.mail_quota') - $array['quota'];
				Database::pexecute($upd_stmt, array(
					'diff' => $difference,
					'customerid' => $customerid
				));
			}
		}

		// Set the new quota
		$upd_stmt = Database::prepare("
			UPDATE `" . TABLE_MAIL_USERS . "` SET `quota` = :quota
		");
		Database::pexecute($upd_stmt, array(
			'quota' => Settings::Get('system.mail_quota')
		));

		// Update the Customer, if the used quota is bigger than the allowed quota
		Database::query("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `email_quota` = `email_quota_used` WHERE `email_quota` < `email_quota_used`");
		\Froxlor\FroxlorLogger::getLog()->addWarning('enforcing mailquota to all customers: ' . Settings::Get('system.mail_quota') . ' MB');
		\Froxlor\UI\Response::redirectTo($filename, array(
			's' => $s
		));
	} else {
		\Froxlor\UI\HTML::askYesNo('admin_quotas_reallyenforce', $filename, array(
			'page' => $page
		));
	}
} elseif ($page == 'integritycheck' && $userinfo['change_serversettings'] == '1') {
	$integrity = new \Froxlor\Database\IntegrityCheck();
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		$integrity->fixAll();
	} elseif (isset($_GET['action']) && $_GET['action'] == "fix") {
		\Froxlor\UI\HTML::askYesNo('admin_integritycheck_reallyfix', $filename, array(
			'page' => $page
		));
	}

	$integritycheck = '';
	foreach ($integrity->available as $id => $check) {
		$displayid = $id + 1;
		$result = $integrity->$check();
		$checkdesc = $lng['integrity_check'][$check];
		eval("\$integritycheck.=\"" . \Froxlor\UI\Template::getTemplate("settings/integritycheck_row") . "\";");
	}
	eval("echo \"" . \Froxlor\UI\Template::getTemplate("settings/integritycheck") . "\";");

} elseif ($page == 'testmail') {
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		$test_addr = isset($_POST['test_addr']) ? $_POST['test_addr'] : null;

		// Initialize the mailingsystem
		$testmail = new \PHPMailer\PHPMailer\PHPMailer(true);
		$testmail->CharSet = "UTF-8";

		if (Settings::Get('system.mail_use_smtp')) {
			$testmail->isSMTP();
			$testmail->Host = Settings::Get('system.mail_smtp_host');
			$testmail->SMTPAuth = Settings::Get('system.mail_smtp_auth') == '1' ? true : false;
			$testmail->Username = Settings::Get('system.mail_smtp_user');
			$testmail->Password = Settings::Get('system.mail_smtp_passwd');
			if (Settings::Get('system.mail_smtp_usetls')) {
				$testmail->SMTPSecure = 'tls';
			} else {
				$testmail->SMTPAutoTLS = false;
			}
			$testmail->Port = Settings::Get('system.mail_smtp_port');
		}

		$_mailerror = false;
		if (\PHPMailer\PHPMailer\PHPMailer::ValidateAddress(Settings::Get('panel.adminmail')) !== false) {
			// set return-to address and custom sender-name, see #76
			$testmail->SetFrom(Settings::Get('panel.adminmail'), Settings::Get('panel.adminmail_defname'));
			if (Settings::Get('panel.adminmail_return') != '') {
				$testmail->AddReplyTo(Settings::Get('panel.adminmail_return'), Settings::Get('panel.adminmail_defname'));
			}

			try {
				$testmail->Subject = "Froxlor Test-Mail";
				$mail_body = "Yay, this worked :)";
				$testmail->AltBody = $mail_body;
				$testmail->MsgHTML(str_replace("\n", "<br />", $mail_body));
				$testmail->AddAddress($test_addr);
				$testmail->Send();
			} catch (\PHPMailer\PHPMailer\Exception $e) {
				$mailerr_msg = $e->errorMessage();
				$_mailerror = true;
			} catch (Exception $e) {
				$mailerr_msg = $e->getMessage();
				$_mailerror = true;
			}

			if (! $_mailerror) {
				// success
				$mail->ClearAddresses();
				\Froxlor\UI\Response::standard_success('testmailsent', '', array(
					'filename' => 'admin_settings.php',
					'page' => 'testmail'
				));
			}
		} else {
			// invalid sender e-mail
			$mailerr_msg = "Invalid sender e-mail address: " . Settings::Get('panel.adminmail');
			$_mailerror = true;
		}
	}

	$mail_smtp_user = Settings::Get('system.mail_smtp_user');
	$mail_smtp_host = Settings::Get('system.mail_smtp_host');
	$mail_smtp_port = Settings::Get('system.mail_smtp_port');

	eval("echo \"" . \Froxlor\UI\Template::getTemplate("settings/testmail") . "\";");
}
*/