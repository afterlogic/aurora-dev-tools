<?php
/*
 * Update table `adav_calendarinstances` after updating dav from v3.0 to v3.2
 *  example "http://your-webmail-v8-domain/dev/update_dav.php?pass=12345"
 */
set_time_limit(0);
$sPassword = "";
if (!(isset($_GET['pass']) && $sPassword !== '' && $sPassword === $_GET['pass']))
{
	exit("Update script password is incorrect or not set.");
}
require_once "../system/autoload.php";
\Aurora\System\Api::Init(true);

class Update
{
	public $oP8PDO = false;
	public $oP8Settings = false;
	public $oP8CoreDecorator = null;

	public function Init()
	{
		$this->oP8PDO = \Aurora\System\Api::GetPDO();
		if (!$this->oP8PDO instanceof \PDO)
		{
			\Aurora\System\Api::Log("Error during connection to p8 DB.", \Aurora\System\Enums\LogLevel::Full);
			exit("Error during connection to 8 DB.");
		}
		$this->oP8Settings = \Aurora\System\Api::GetSettings();
		$this->oP8CoreDecorator = \Aurora\System\Api::GetModuleDecorator('Core');
	}

	public function Start()
	{
		$oP8DBPrefix = $this->oP8Settings->GetConf('DBPrefix');
		try
		{
			$sGetUsersPrincipalsQuery = "SELECT principaluri FROM `{$oP8DBPrefix}adav_calendarinstances`";
			$stmt = $this->oP8PDO->prepare($sGetUsersPrincipalsQuery);
			$stmt->execute();
			$aUsersPrincipals = $stmt->fetchAll(PDO::FETCH_COLUMN);
			$stmt->closeCursor();
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during calendars update process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'update-');
			return false;
		}
		foreach ($aUsersPrincipals as $UserPrincipal)
		{
			$UserUUID = str_replace('principals/', '', $UserPrincipal);
			$oUsers = $this->oP8CoreDecorator->GetUserByUUID($UserUUID);
			if ($oUsers instanceof \Aurora\Modules\Core\Classes\User)
			{
				$bResult = $this->UpgradeDAVCalendar($oUsers);
				if ($bResult)
				{
					echo "User calendars updated successfully. {$oUsers->PublicId}<br>";
					\Aurora\System\Api::Log("User calendars updated successfully. {$oUsers->PublicId}", \Aurora\System\Enums\LogLevel::Full, 'update-');
					ob_flush();
					flush();
				}
				else
				{
					echo "Error during update process. {$oUsers->PublicId}<br>";
					\Aurora\System\Api::Log("Error during update process. {$oUsers->PublicId}", \Aurora\System\Enums\LogLevel::Full, 'update-');
					ob_flush();
					flush();
				}
			}
			else
			{
				echo "Can't find user with UUID:  {$UserUUID}<br>";
				\Aurora\System\Api::Log("Can't find user with UUID:  {$UserUUID}", \Aurora\System\Enums\LogLevel::Full, 'update-');
				ob_flush();
				flush();
			}
		}
	}


	public function UpgradeDAVCalendar(\Aurora\Modules\Core\Classes\User $oUser)
	{
		$oP8DBPrefix = $this->oP8Settings->GetConf('DBPrefix');
		try
		{
			$sCalendarUpdateQuery = "UPDATE `{$oP8DBPrefix}adav_calendarinstances`
					SET `principaluri`= 'principals/{$oUser->PublicId}'
					WHERE `principaluri` = 'principals/{$oUser->UUID}'";
			$stmt = $this->oP8PDO->prepare($sCalendarUpdateQuery);
			$stmt->execute();
			$stmt->closeCursor();
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during calendars update process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'update-');
			return false;
		}
		return true;
	}
}
ob_start();
$oUpdate = new Update();

try
{
	$oUpdate->Init();
	$oUpdate->Start();
}
catch (Exception $e)
{
	\Aurora\System\Api::Log("Exception: " . $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'update-');
}

ob_end_flush();
exit("Done");
