<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: 1.8.3-b1.php 6478 2012-06-14 16:19:42Z JulienM $
 *
 * @category Piwik‚
 * @package Updates
 */

/**
 * @package Updates
 */
class Piwik_Updates_1_8_3_b1 extends Piwik_Updates
{

	static function update()
	{
		try {

			// Piwik_Common::prefixTable('pdf') has been heavily refactored to be more generic
			// The following actions are taken in this update script :
			// - create the new generic report table Piwik_Common::prefixTable('report')
			// - migrate previous reports, if any, from Piwik_Common::prefixTable('pdf') to Piwik_Common::prefixTable('report')
			// - delete Piwik_Common::prefixTable('pdf')
			Piwik_Query(
				'CREATE TABLE `'.Piwik_Common::prefixTable('report').'` (
					`idreport` INT(11) NOT NULL AUTO_INCREMENT,
					`idsite` INTEGER(11) NOT NULL,
					`login` VARCHAR(100) NOT NULL,
					`description` VARCHAR(255) NOT NULL,
					`period` VARCHAR(10) NOT NULL,
					`type` VARCHAR(10) NOT NULL,
					`format` VARCHAR(10) NOT NULL,
					`reports` TEXT NOT NULL,
					`parameters` TEXT NULL,
					`ts_created` TIMESTAMP NULL,
					`ts_last_sent` TIMESTAMP NULL,
					`deleted` tinyint(4) NOT NULL default 0,
					PRIMARY KEY (`idreport`)
				) DEFAULT CHARSET=utf8'
			);

			$reports = Piwik_FetchAll('SELECT * FROM `'. Piwik_Common::prefixTable('pdf') .'`');
			foreach($reports AS $report) {

				$idreport = $report['idreport'];
				$idsite = $report['idsite'];
				$login = $report['login'];
				$description = $report['description'];
				$period = $report['period'];
				$format = $report['format'];
				$display_format = $report['display_format'];
				$email_me = $report['email_me'];
				$additional_emails = $report['additional_emails'];
				$reports = $report['reports'];
				$ts_created = $report['ts_created'];
				$ts_last_sent = $report['ts_last_sent'];
				$deleted = $report['deleted'];

				$parameters = array();

				if(!is_null($additional_emails))
				{
					$parameters[Piwik_PDFReports::ADDITIONAL_EMAILS_PARAMETER] = preg_split('/,/', $additional_emails);
				}

				$parameters[Piwik_PDFReports::EMAIL_ME_PARAMETER] = is_null($email_me) ? Piwik_PDFReports::EMAIL_ME_PARAMETER_DEFAULT_VALUE : filter_var($email_me, FILTER_VALIDATE_BOOLEAN);
				$parameters[Piwik_PDFReports::DISPLAY_FORMAT_PARAMETER] = $display_format;

				Piwik_Query(
					'INSERT INTO `' . Piwik_Common::prefixTable('report') . '` SET
					idreport = ?, idsite = ?, login = ?, description = ?, period = ?,
					type = ?, format = ?, reports = ?, parameters = ?, ts_created = ?,
					ts_last_sent = ?, deleted = ?',
					array(
						$idreport,
						$idsite,
						$login,
						$description,
						is_null($period) ? Piwik_PDFReports::DEFAULT_PERIOD : $period,
						Piwik_PDFReports::EMAIL_TYPE,
						is_null($format) ? Piwik_PDFReports::DEFAULT_REPORT_FORMAT : $format,
						json_encode(preg_split('/,/', $reports)),
						json_encode($parameters),
						$ts_created,
						$ts_last_sent,
						$deleted
					)
				);
			}

			Piwik_Query('DROP TABLE `'. Piwik_Common::prefixTable('pdf') .'`');
		}
		catch(Exception $e){}
	}
}
