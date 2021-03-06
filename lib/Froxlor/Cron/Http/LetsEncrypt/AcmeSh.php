<?php
namespace Froxlor\Cron\Http\LetsEncrypt;

use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\Database\Database;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2016 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Florian Aders <kontakt-froxlor@neteraser.de>
 * @author Froxlor team <team@froxlor.org> (2016-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Cron
 *         
 * @since 0.9.35
 *       
 */
class AcmeSh extends \Froxlor\Cron\FroxlorCron
{

	private static $apiserver = "";

	private static $acmesh = "/root/.acme.sh/acme.sh";

	/**
	 *
	 * @var \PDOStatement
	 */
	private static $updcert_stmt = null;

	/**
	 *
	 * @var \PDOStatement
	 */
	private static $upddom_stmt = null;

	private static $do_update = true;

	public static $no_inserttask = false;

	private static function needRenew()
	{
		$certificates_stmt = Database::query("
			SELECT
				domssl.`id`,
				domssl.`domainid`,
				domssl.`expirationdate`,
				domssl.`ssl_cert_file`,
				domssl.`ssl_key_file`,
				domssl.`ssl_ca_file`,
				domssl.`ssl_csr_file`,
				dom.`domain`,
				dom.`wwwserveralias`,
				dom.`documentroot`,
				dom.`id` AS 'domainid',
				dom.`ssl_redirect`,
				cust.`leprivatekey`,
				cust.`lepublickey`,
				cust.`leregistered`,
				cust.`customerid`,
				cust.`loginname`
			FROM
				`" . TABLE_PANEL_CUSTOMERS . "` AS cust,
				`" . TABLE_PANEL_DOMAINS . "` AS dom
			LEFT JOIN
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` AS domssl ON
					dom.`id` = domssl.`domainid`
			WHERE
				dom.`customerid` = cust.`customerid`
				AND cust.deactivated = 0
				AND dom.`letsencrypt` = 1
				AND dom.`aliasdomain` IS NULL
				AND dom.`iswildcarddomain` = 0
				AND (
					domssl.`expirationdate` < DATE_ADD(NOW(), INTERVAL 30 DAY)
					OR domssl.`expirationdate` IS NULL
				)
		");
		$customer_ssl = $certificates_stmt->fetchAll(\PDO::FETCH_ASSOC);
		if (! $customer_ssl) {
			$customer_ssl = array();
		}

		$froxlor_ssl = array();
		if (Settings::Get('system.le_froxlor_enabled') == '1') {
			$froxlor_ssl_settings_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
				WHERE `domainid` = '0' AND
				(`expirationdate` < DATE_ADD(NOW(), INTERVAL 30 DAY) OR `expirationdate` IS NULL)
			");
			$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
			if (! $froxlor_ssl) {
				$froxlor_ssl = array();
			}
		}

		if (count($customer_ssl) > 0 || count($froxlor_ssl) > 0) {
			return array(
				'customer_ssl' => $customer_ssl,
				'froxlor_ssl' => $froxlor_ssl
			);
		}
		return false;
	}

	public static function run($internal = false)
	{
		if (! defined('CRON_IS_FORCED') && ! defined('CRON_DEBUG_FLAG') && $internal == false) {
			// Let's Encrypt cronjob is combined with regeneration of webserver configuration files.
			// For debugging purposes you can use the --debug switch and the --force switch to run the cron manually.
			// check whether we MIGHT need to run although there is no task to regenerate config-files
			$needRenew = self::needRenew();
			if ($needRenew) {
				// insert task to generate certificates and vhost-configs
				\Froxlor\System\Cronjob::inserttask(1);
			}
			return 0;
		}

		self::checkInstall();

		self::$apiserver = 'https://acme-'.(Settings::Get('system.letsencryptca') == 'testing' ? 'staging-' : '').'v0' . \Froxlor\Settings::Get('system.leapiversion') . '.api.letsencrypt.org/directory';

		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Requesting/renewing Let's Encrypt certificates");

		$aliasdomains_stmt = Database::prepare("
			SELECT
				dom.`id` as domainid,
				dom.`domain`,
				dom.`wwwserveralias`
			FROM `" . TABLE_PANEL_DOMAINS . "` AS dom
			WHERE
				dom.`aliasdomain` = :id
				AND dom.`letsencrypt` = 1
				AND dom.`iswildcarddomain` = 0
		");

		self::$updcert_stmt = Database::prepare("
			REPLACE INTO
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
			SET
				`id` = :id,
				`domainid` = :domainid,
				`ssl_cert_file` = :crt,
				`ssl_key_file` = :key,
				`ssl_ca_file` = :ca,
				`ssl_cert_chainfile` = :chain,
				`ssl_csr_file` = :csr,
				`ssl_fullchain_file` = :fullchain,
				`expirationdate` = :expirationdate
		");

		self::$upddom_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `ssl_redirect` = '1' WHERE `id` = :domainid");

		// flag for re-generation of vhost files
		$changedetected = 0;

		$needRenew = self::needRenew();

		// first - generate LE for system-vhost if enabled
		if (Settings::Get('system.le_froxlor_enabled') == '1') {

			$certrow = array(
				'loginname' => 'froxlor.panel',
				'domain' => Settings::Get('system.hostname'),
				'domainid' => 0,
				'documentroot' => \Froxlor\Froxlor::getInstallDir(),
				'leprivatekey' => Settings::Get('system.leprivatekey'),
				'lepublickey' => Settings::Get('system.lepublickey'),
				'leregistered' => Settings::Get('system.leregistered'),
				'ssl_redirect' => Settings::Get('system.le_froxlor_redirect'),
				'expirationdate' => null,
				'ssl_cert_file' => null,
				'ssl_key_file' => null,
				'ssl_ca_file' => null,
				'ssl_csr_file' => null,
				'id' => null
			);

			$froxlor_ssl = $needRenew ? $needRenew['froxlor_ssl'] : array();

			$cert_mode = 'issue';
			if (count($froxlor_ssl) > 0) {
				$cert_mode = 'renew';
				$certrow['id'] = $froxlor_ssl['id'];
				$certrow['expirationdate'] = $froxlor_ssl['expirationdate'];
				$certrow['ssl_cert_file'] = $froxlor_ssl['ssl_cert_file'];
				$certrow['ssl_key_file'] = $froxlor_ssl['ssl_key_file'];
				$certrow['ssl_ca_file'] = $froxlor_ssl['ssl_ca_file'];
				$certrow['ssl_csr_file'] = $froxlor_ssl['ssl_csr_file'];
			} else {
				// check whether we have an entry with valid certificates which just does not need
				// updating yet, so we need to skip this here
				$froxlor_ssl_settings_stmt = Database::prepare("
					SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = '0'
				");
				$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
				if ($froxlor_ssl && ! empty($froxlor_ssl['ssl_cert_file'])) {
					$cert_mode = false;
				}
			}

			if ($cert_mode) {
				$domains = array(
					strtolower($certrow['domain'])
				);

				$froxlor_aliases = Settings::Get('system.froxloraliases');
				if (! empty($froxlor_aliases)) {
					$froxlor_aliases = explode(",", $froxlor_aliases);
					foreach ($froxlor_aliases as $falias) {
						if (\Froxlor\Validate\Validate::validateDomain(trim($falias))) {
							$domains[] = strtolower(trim($falias));
						}
					}
				}

				// Only renew let's encrypt certificate if no broken ssl_redirect is enabled
				// - this temp. deactivation of the ssl-redirect is handled by the webserver-cronjob
				$do_force = false;
				if ($cert_mode == 'renew') {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Updating certificate for " . $certrow['domain']);
				} else {
					$do_force = true;
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Creating certificate for " . $certrow['domain']);
				}

				$cronlog = FroxlorLogger::getInstanceOf(array(
					'loginname' => $certrow['loginname'],
					'adminsession' => 0
				));

				self::runAcmeSh($certrow, $domains, $cert_mode, $cronlog, $changedetected, $do_force);
			}
		}

		// customer domains
		$certrows = $needRenew ? $needRenew['customer_ssl'] : array();
		foreach ($certrows as $certrow) {

			// initialize mode to 'issue'
			$cert_mode = 'issue';

			// set logger to corresponding loginname for the log to appear in the users system-log
			$cronlog = FroxlorLogger::getInstanceOf(array(
				'loginname' => $certrow['loginname'],
				'adminsession' => 0
			));

			// Only renew let's encrypt certificate if no broken ssl_redirect is enabled
			if ($certrow['ssl_redirect'] != 2) {

				$do_force = false;
				if (! empty($certrow['ssl_cert_file']) && ! empty($certrow['expirationdate'])) {
					$cert_mode = 'renew';
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Updating certificate for " . $certrow['domain']);
				} else if (! empty($certrow['ssl_cert_file']) && empty($certrow['expirationdate'])) {
					// domain changed (SAN or similar)
					$do_force = true;
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Re-creating certificate for " . $certrow['domain']);
				} else {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Creating certificate for " . $certrow['domain']);
				}

				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . $certrow['domain']);
				$domains = array(
					strtolower($certrow['domain'])
				);
				// add www.<domain> to SAN list
				if ($certrow['wwwserveralias'] == 1) {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $certrow['domain']);
					$domains[] = strtolower('www.' . $certrow['domain']);
				}

				// add alias domains (and possibly www.<aliasdomain>) to SAN list
				Database::pexecute($aliasdomains_stmt, array(
					'id' => $certrow['domainid']
				));
				$aliasdomains = $aliasdomains_stmt->fetchAll(\PDO::FETCH_ASSOC);
				foreach ($aliasdomains as $aliasdomain) {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . $aliasdomain['domain']);
					$domains[] = strtolower($aliasdomain['domain']);
					if ($aliasdomain['wwwserveralias'] == 1) {
						$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $aliasdomain['domain']);
						$domains[] = strtolower('www.' . $aliasdomain['domain']);
					}
				}

				self::runAcmeSh($certrow, $domains, $cert_mode, $cronlog, $changedetected, $do_force);
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Skipping Let's Encrypt generation for " . $certrow['domain'] . " due to an enabled ssl_redirect");
			}
		}

		// If we have a change in a certificate, we need to update the webserver - configs
		// This is easiest done by just creating a new task ;)
		if ($changedetected) {
			if (self::$no_inserttask == false) {
				\Froxlor\System\Cronjob::inserttask(1);
			}
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Let's Encrypt certificates have been updated");
		} else {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "No new certificates or certificates due for renewal found");
		}
	}

	private static function runAcmeSh($certrow = array(), $domains = array(), $cert_mode = 'issue', &$cronlog = null, &$changedetected = 0, $force = false)
	{
		if (! empty($domains)) {

			if (self::$do_update) {
				self::checkUpgrade();
				self::$do_update = false;
			}

			$acmesh_cmd = self::$acmesh . " --auto-upgrade 0 --server " . self::$apiserver . " --" . $cert_mode . " -d " . implode(" -d ", $domains);

			if ($cert_mode == 'issue') {
				$acmesh_cmd .= " -w " . Settings::Get('system.letsencryptchallengepath');
			}
			if (Settings::Get('system.leecc') > 0) {
				$acmesh_cmd .= " --keylength ec-" . Settings::Get('system.leecc');
			} else {
				$acmesh_cmd .= " --keylength " . Settings::Get('system.letsencryptkeysize');
			}
			if (Settings::Get('system.letsencryptreuseold') != '1') {
				$acmesh_cmd .= " --always-force-new-domain-key";
			}
			if (Settings::Get('system.letsencryptca') == 'testing') {
				$acmesh_cmd .= " --staging";
			}
			if ($force) {
				$acmesh_cmd .= " --force";
			}
			if (defined('CRON_DEBUG_FLAG')) {
				$acmesh_cmd .= " --debug";
			}

			$acme_result = \Froxlor\FileDir::safe_exec($acmesh_cmd);
			// debug output of acme.sh run
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, implode("\n", $acme_result));

			$return = array();
			self::readCertificateToVar($certrow['domain'], $return);

			if (! empty($return['crt'])) {

				$newcert = openssl_x509_parse($return['crt']);

				if ($newcert) {
					// Store the new data
					Database::pexecute(self::$updcert_stmt, array(
						'id' => $certrow['id'],
						'domainid' => $certrow['domainid'],
						'crt' => $return['crt'],
						'key' => $return['key'],
						'ca' => $return['chain'],
						'chain' => $return['chain'],
						'csr' => $return['csr'],
						'fullchain' => $return['fullchain'],
						'expirationdate' => date('Y-m-d H:i:s', $newcert['validTo_time_t'])
					));

					if ($certrow['ssl_redirect'] == 3) {
						Database::pexecute(self::$upddom_stmt, array(
							'domainid' => $certrow['domainid']
						));
					}

					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Updated Let's Encrypt certificate for " . $certrow['domain']);
					$changedetected = 1;
				} else {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Got non-successful Let's Encrypt response for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
				}
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not get Let's Encrypt certificate for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
			}
		}
	}

	private static function readCertificateToVar($domain, &$return)
	{
		$certificate_folder = dirname(self::$acmesh) . "/" . $domain;
		if (Settings::Get('system.leecc') > 0) {
			$certificate_folder .= "_ecc";
		}
		$certificate_folder = \Froxlor\FileDir::makeCorrectDir($certificate_folder);

		if (is_dir($certificate_folder)) {
			foreach ([
				'crt' => $domain . '.cer',
				'key' => $domain . '.key',
				'chain' => 'ca.cer',
				'fullchain' => 'fullchain.cer',
				'csr' => $domain . '.csr'
			] as $index => $sslfile) {
				$ssl_file = \Froxlor\FileDir::makeCorrectFile($certificate_folder . '/' . $sslfile);
				if (file_exists($ssl_file)) {
					$return[$index] = file_get_contents($ssl_file);
				} else {
					$return[$index] = null;
				}
			}
		}
	}

	private static function checkInstall()
	{
		if (! file_exists(self::$acmesh)) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Could not find acme.sh - installing it to /root/.acme.sh/");
			$return = false;
			\Froxlor\FileDir::safe_exec("wget -O - https://get.acme.sh | sh", $return, array(
				'|'
			));
		}
	}

	private static function checkUpgrade()
	{
		$acmesh_result = \Froxlor\FileDir::safe_exec(self::$acmesh . " --upgrade");
		// check for activated cron (which is installed automatically) but we don't need it
		$acmesh_result2 = \Froxlor\FileDir::safe_exec(self::$acmesh . " --uninstall-cronjob");
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Checking for LetsEncrypt client upgrades before renewing certificates:\n" . implode("\n", $acmesh_result) . "\n" . implode("\n", $acmesh_result2));
	}
}
