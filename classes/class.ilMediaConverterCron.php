<?php

require_once './Services/Cron/classes/class.ilCronJob.php';
require_once './Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/class.ilMediaConverterResult.php';
require_once './Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/Media/class.mcPid.php';
require_once './Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/Media/class.mcMedia.php';
require_once './Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/Media/class.mcMediaState.php';
require_once './Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/Media/class.mcProcessedMedia.php';
require_once './Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/VideoManager/classes/Util/class.vmFFmpeg.php';
require_once './Services/Mail/classes/class.ilMimeMail.php';
require_once './Services/Link/classes/class.ilLink.php';
require_once './Services/Repository/classes/class.ilRepUtil.php';
require_once('./Customizing/global/plugins/Services/Cron/CronHook/MediaConverter/classes/class.mcLog.php');

/**
 * Class ilMediaConverterCron
 *
 * @author Fabian Schmid <fs@studer-raimann.ch>
 * @author Theodor Truffer <tt@studer-raimann.ch>
 */
class ilMediaConverterCron extends ilCronJob {

	const MAX = 3;
	const ID = 'media_conv';
	/**
	 * @var  ilMediaConverterPlugin
	 */
	protected $pl;
	/**
	 * @var  ilDB
	 */
	protected $db;
	/**
	 * @var  ilLog
	 */
	protected $ilLog;


	public function __construct() {
		global $ilDB, $ilLog;
		$this->db = $ilDB;
		$this->pl = ilMediaConverterPlugin::getInstance();
		$this->log = $ilLog;
	}


	/**
	 * @return string
	 */
	public function getId() {
		return self::ID;
	}


	/**
	 * @return bool
	 */
	public function hasAutoActivation() {
		return true;
	}


	/**
	 * @return bool
	 */
	public function hasFlexibleSchedule() {
		return true;
	}


	/**
	 * @return int
	 */
	public function getDefaultScheduleType() {
		return self::SCHEDULE_TYPE_IN_MINUTES;
	}


	/**
	 * @return array|int
	 */
	public function getDefaultScheduleValue() {
		return 1;
	}


	/**
	 * @return ilMediaConverterResult
	 */
	public function run() {
		try {
			$pid = getmypid();
			$user_pid_id = getmyuid();
			//look if the maximum number of jobs are reached
			//if this is so, don't start a new job
			//else start job
			if (mcPid::find($pid)) {
				$mcPid = new mcPid($pid);
				$mcPid->setPidUid($user_pid_id);
				$mcPid->update();
			} else {
				$mcPid = new mcPid();
				$mcPid->setPidId($pid);
				$mcPid->setPidUid($user_pid_id);
				$mcPid->create();
			}

			if ($mcPid->getNumberOfPids() <= 3) {
				foreach (mcMedia::getNextPendingMediaID() as $media) {
					if ($media->getStatusConvert() == mcMedia::STATUS_RUNNING) {
						mcLog::getInstance()->write('Skipping already running task');
						continue;
					}

					mcLog::getInstance()->write('Convert new Item: ' . $media->getFilename());

					$media->setStatusConvert(mcMedia::STATUS_RUNNING);
					$media->update();

					$arr_target_mime_types = array(mcMedia::ARR_TARGET_MIME_TYPE_M, mcMedia::ARR_TARGET_MIME_TYPE_W);
					foreach ($arr_target_mime_types as $mime_type) {
						if ($media->getSuffix() != substr($mime_type, 6)) {
							//create/update mediastate db entry
							if ($mediaState = mcMediaState::find($media->getId())) {
								$mediaState->setProcessStarted(date('Y-m-d'));
								$mediaState->update();
							} else {
								$mediaState = new mcMediaState();
								$mediaState->setId($media->getId());
								$mediaState->setProcessStarted(date('Y-m-d'));
								$mediaState->create();
							}
							mcLog::getInstance()->write('Convert type: ' . $mime_type);
							//convert file to targetdir
							$file = $media->getTempFilePath() . '/' . $media->getFilename() . '.' . $media->getSuffix();

							try {
								vmFFmpeg::convert($file, $mime_type, $media->getTargetDir(), $media->getFilename() . '.' . substr($mime_type, 6));
								mcLog::getInstance()->write('Convertion succeeded');
							} catch (ilFFmpegException $e) {
								$media->setStatusConvert(mcMedia::STATUS_FAILED);
								$media->update();

								mcLog::getInstance()->write('Convertion of Item failed: ' . $media->getFilename());
								mcLog::getInstance()->write('Exception message: ' . $e->getMessage());
								continue;
							}


//							mcLog::getInstance()->write('Updating DB..');
							//update media db entry
							$media->setDateConvert(date('Y-m-d'));
							$media->setStatusConvert(mcMedia::STATUS_FINISHED);
							$media->update();
//							mcLog::getInstance()->write('DB-Entry updated');

							//create mediaprocessed db entry
							$mcProcessedMedia = new mcProcessedMedia();
							//TODO id wird aufsteigend eingetragen, statt die vorgesehene
							$mcProcessedMedia->saveConvertedFile($media->getId(), date('Y-m-d'), substr($mime_type, 6));
						}
					}
					//delete temp file
//					mcLog::getInstance()->write('Deleting temporary File..');
					$media->deleteFile();
//					mcLog::getInstance()->write('Temporary File deleted');
				}
			}

			//cron result
			return new ilMediaConverterResult(ilMediaConverterResult::STATUS_OK, 'Cron job terminated successfully.');

		} catch (Exception $e) {

			//cron result
			return new ilMediaConverterResult(ilMediaConverterResult::STATUS_CRASHED, 'Cron job crashed: ' . $e->getMessage());

		}


	}
}

?>