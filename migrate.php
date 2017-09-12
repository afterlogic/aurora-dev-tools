<?php

// remove the following line for real use
exit('remove this line');

$sP7ProductPath = "path to your p7 installation";

$sP7ApiPath = $sP7ProductPath . '/libraries/afterlogic/api.php';

if (!file_exists($sP7ApiPath))
{
	exit("Wrong path for import");
}

require_once $sP7ApiPath;
require_once "../system/autoload.php";
\Aurora\System\Api::Init(true);

/* @var $oApiDomainsManager CApiDomainsManager */
$oApiDomainsManager = \CApi::Manager('domains');

/* @var $oApiUsersManager CApiUsersManager */
$oApiUsersManager = \CApi::Manager('users');

/* @var $oApiContactsManagerFrom CApiContactsManager */
$oApiContactsManagerFrom = \CApi::Manager('contactsmain', 'db');

$iItemsPerPage = 20;

$sMigrationLogFile = \Aurora\System\Api::DataPath().'/migration';
if (file_exists($sMigrationLogFile))
{
	$oMigrationLog = json_decode(@file_get_contents($sMigrationLogFile));
}
if (isset($oMigrationLog->CurDomainId) && isset($oMigrationLog->CurUserId))
{
	\Aurora\System\Api::Log("Continue migration from Domain: {$oMigrationLog->CurDomainId} UserId: {$oMigrationLog->CurUserId}", \Aurora\System\Enums\LogLevel::Full, 'migration-');
}
else
{
	\Aurora\System\Api::Log("Start migration", \Aurora\System\Enums\LogLevel::Full, 'migration-');
}

if (!$oMigrationLog)
{
	$oMigrationLog = (object) [
		'CurDomainId' => -1,
		'CurUsersPage' => 1,
		'CurUserId' => 0,
		'CurContactsId' => 0
	];
}

$aDomains = $oApiDomainsManager->getFullDomainsList();
$aDomains[0] = array(false, 'Default'); // Default Domain
$iUserCount = 0;
$bFindDomain = false;
$bFindUser = false;
$bFindContact = false;

foreach ($aDomains as $iDomainId => $oDomainItem)
{
	if (!$bFindDomain && $oMigrationLog->CurDomainId !== -1 && $oMigrationLog->CurDomainId !== $iDomainId)
	{
		//skip Domain if already done
		\Aurora\System\Api::Log("Skip domain: " . $oDomainItem[1], \Aurora\System\Enums\LogLevel::Full, 'migration-');
		continue;
	}
	else
	{
		$bFindDomain = true;
		$oMigrationLog->CurDomainId = $iDomainId;
		\Aurora\System\Api::Log("Process domain: " . $oDomainItem[1], \Aurora\System\Enums\LogLevel::Full, 'migration-');
	}

	file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
	

	$iUsersCount = $oApiUsersManager->getUsersCountForDomain($iDomainId);
	$iPageUserCount = ceil($iUsersCount / $iItemsPerPage);

	$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
	$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();

	$oApiContacts = \CApi::Manager('contacts');
	
	$aUsers = array();
	while ($oMigrationLog->CurUsersPage - 1 < $iPageUserCount)
	{
		file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
		$aUsers = $oApiUsersManager->getUserList($iDomainId, $oMigrationLog->CurUsersPage, $iItemsPerPage);
		if ($aUsers)
		{
			foreach ($aUsers as $aUserItem)
			{
				$iUserId = (int) $aUserItem[4];
				$sUserEmail = $aUserItem[1];
				if (!$bFindUser && $oMigrationLog->CurUserId !== 0 && $iUserId < $oMigrationLog->CurUserId)
				{
					//skip User if already done
					\Aurora\System\Api::Log("Skip user: " . $sUserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					continue;
				}
				else
				{
					$bFindUser = true;
				}
				$oUser = $oCoreDecorator->GetUserByPublicId($sUserEmail);
				if ($oUser)
				{
					\Aurora\System\Api::Log("User already exists: " . $sUserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
				}
				else
				{
					if (!$oCoreDecorator->CreateUser(0, $sUserEmail, \Aurora\System\Enums\UserRole::NormalUser, false))
					{
						\Aurora\System\Api::Log("Error while User creation: " . $sUserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						exit("Error during migration process");
					}
					else
					{
						$oUser = $oCoreDecorator->GetUserByPublicId($sUserEmail);
					}
				}
				if (!UserP7ToP8($iUserId, $oApiUsersManager, $oUser, $oCoreDecorator))
				{
					\Aurora\System\Api::Log("Error while User settings creation: " . $sUserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process");
				}
				$iUserCount++;
				$oMigrationLog->CurUserId = $iUserId;
				file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
	
				/* @var $aUserListItems array */
				$aUserListItems = $oApiContactsManagerFrom->getContactItemsWithoutOrder($iUserId, 0, 9999);
				if (count($aUserListItems) === 0)
				{
					$oMigrationLog->CurContactsId = 0;
					file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
				}
				$iContactsCount = 0;
				/* @var $oListItem CContactListItem */
				foreach ($aUserListItems as $oListItem)
				{
					if (!$bFindContact && $oMigrationLog->CurContactsId !== 0 && $oListItem->Id <= $oMigrationLog->CurContactsId)
					{
						//skip Contact if already done
						\Aurora\System\Api::Log("Skip contact " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						continue;
					}
					else
					{
						$bFindContact = true;
					}
					$oContact = $oApiContacts->getContactById($oListItem->IdUser, $oListItem->Id);
					$aContactOptions = ContactP7ToP8($oContact);
					if (!$contactResult = $oContactsDecorator->CreateContact($aContactOptions, $oUser->EntityId))
					{
						\Aurora\System\Api::Log("Error while Contact creation: " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						exit("Error during migration process");
					}
					else
					{
						$oMigrationLog->CurContactsId = $oListItem->Id;
						file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
						$iContactsCount++;
					}
				}
				\Aurora\System\Api::Log("User: $sUserEmail Contacts processed: " . $iContactsCount, \Aurora\System\Enums\LogLevel::Full, 'migration-');
			}
		}
		$oMigrationLog->CurUsersPage++;
	}
	$oMigrationLog->CurUsersPage = 1;
}

\Aurora\System\Api::Log("Done. $iUserCount users processed", \Aurora\System\Enums\LogLevel::Full, 'migration-');

function UserP7ToP8($iUserId, $oApiUsersManager, Aurora\Modules\Core\Classes\User $oP8User, $oCoreDecorator)
{
	$oP7User = $oApiUsersManager->getUserById($iUserId);
	$iAccountId = $oApiUsersManager->getDefaultAccountId($iUserId);
	$oAccount = $oApiUsersManager->getAccountById($iAccountId);
	$oUserCalendarSettings = $oApiUsersManager->getCalUser($iUserId);

	$oP8User->IsDisabled = $oAccount->IsDisabled;

	//Common settings
	$oP8User->Language = $oP7User->DefaultLanguage;
	$oP8User->{'CoreWebclient::AutoRefreshIntervalMinutes'} = $oP7User->AutoCheckMailInterval;
	$oP8User->TimeFormat = $oP7User->DefaultTimeFormat;
	$oP8User->DateFormat = $oP7User->DefaultDateFormat;
	$oP8User->DesktopNotifications = $oP7User->DesktopNotifications;
	
	$oP8User->{'MailWebclient::MailsPerPage'} = $oP7User->MailsPerPage;
	$oP8User->{'MailWebclient::SaveRepliesToCurrFolder'} = $oP7User->SaveRepliedMessagesToCurrentFolder;
	
	$oP8User->{'Contacts::ContactsPerPage'} = $oP7User->ContactsPerPage;

	//Calendar
	$oP8User->{'Calendar::HighlightWorkingHours'} = $oUserCalendarSettings->ShowWorkDay;
	$oP8User->{'Calendar::HighlightWorkingDays'} = $oUserCalendarSettings->ShowWeekEnds;
	$oP8User->{'Calendar::WorkdayStarts'} = $oUserCalendarSettings->WorkDayStarts;
	$oP8User->{'Calendar::WorkdayEnds'} = $oUserCalendarSettings->WorkDayEnds;
	$oP8User->{'Calendar::WeekStartsOn'} = $oUserCalendarSettings->WeekStartsOn;
	$oP8User->{'Calendar::DefaultTab'} = $oUserCalendarSettings->DefaultTab;

	return $oCoreDecorator->UpdateUserObject($oP8User);
}

function ContactP7ToP8(\CContact $oContact)
{
	$aResult = [];
	$aObgectFieldsConformity = [
		"PrimaryEmail" => "PrimaryEmail",
		"FullName" => "FullName",
		"FirstName" => "FirstName",
		"LastName" => "LastName",
		"NickName" => "NickName",
		"ItsMe" => "ItsMe",
		"Skype" => "Skype",
		"Facebook" => "Facebook",
		"PersonalEmail" => "HomeEmail",
		"PersonalAddress" => "HomeStreet",
		"PersonalCity" => "HomeCity",
		"PersonalState" => "HomeState",
		"PersonalZip" => "HomeZip",
		"PersonalCountry" => "HomeCountry",
		"PersonalWeb" => "HomeWeb",
		"PersonalFax" => "HomeFax",
		"PersonalPhone" => "HomePhone",
		"PersonalMobile" => "HomeMobile",
		"BusinessEmail" => "BusinessEmail",
		"BusinessCompany" => "BusinessCompany",
		"BusinessJobTitle" => "BusinessJobTitle",
		"BusinessDepartment" => "BusinessDepartment",
		"BusinessOffice" => "BusinessOffice",
		"BusinessAddress" => "BusinessStreet",
		"BusinessCity" => "BusinessCity",
		"BusinessState" => "BusinessState",
		"BusinessZip" => "BusinessZip",
		"BusinessCountry" => "BusinessCountry",
		"BusinessFax" => "BusinessFax",
		"BusinessPhone" => "BusinessPhone",
		"BusinessWeb" => "BusinessWeb",
		"OtherEmail" => "OtherEmail",
		"Notes" => "Notes",
		"ETag" => "ETag",
		"BirthDay" => "BirthdayDay",
		"BirthMonth" => "BirthdayMonth",
		"BirthYear" => "BirthdayYear",
		"GroupUUIDs" => "GroupsIds"
	];

	foreach ($aObgectFieldsConformity as $sPropertyNameP8 => $sPropertyNameP7)
	{
		$aResult[$sPropertyNameP8] = $oContact->$sPropertyNameP7;
	}

	return $aResult;
}