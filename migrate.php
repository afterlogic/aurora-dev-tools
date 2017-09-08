<?php

// remove the following line for real use
exit('remove this line');

$sImportProductPath = "d:/web/OpenServer/domains/p7.dev";

$sImportApiPath = $sImportProductPath . '/libraries/afterlogic/api.php';

if (!file_exists($sImportApiPath))
{
	exit("Wrong path for import");
}

require_once $sImportApiPath;
require_once "../system/autoload.php";
\Aurora\System\Api::Init();

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
	\Aurora\System\Api::Log("Continue migration from Domain: {$oMigrationLog->CurDomainId} User: {$oMigrationLog->CurUserId}", \Aurora\System\Enums\LogLevel::Full, 'migration-');
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

	\Aurora\System\Api::skipCheckUserRole(true);

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
				if (!$bFindUser && $oMigrationLog->CurUserId !== 0 && $iUserId !== $oMigrationLog->CurUserId)
				{
					//skip User if already done
					\Aurora\System\Api::Log("Skip user: " . $aUserItem[1], \Aurora\System\Enums\LogLevel::Full, 'migration-');
					continue;
				}
				else
				{
					$bFindUser = true;
				}
				$oUser = $oCoreDecorator->GetUserByPublicId($aUserItem[1]);
				if ($oUser)
				{
					\Aurora\System\Api::Log("User already exists: " . $aUserItem[1], \Aurora\System\Enums\LogLevel::Full, 'migration-');
				}
				else
				{
					if (!$oCoreDecorator->CreateUser(0, $aUserItem[1], \Aurora\System\Enums\UserRole::NormalUser, false))
					{
						\Aurora\System\Api::Log("Error while User creation: " . $aUserItem[1], \Aurora\System\Enums\LogLevel::Full, 'migration-');
						break;
					}
					else
					{
						$oUser = $oCoreDecorator->GetUserByPublicId($aUserItem[1]);
					}
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
					if (!$bFindContact && $oMigrationLog->CurContactsId !== 0 && $oListItem->Id !== $oMigrationLog->CurContactsId)
					{
						//skip Contact if already done
						\Aurora\System\Api::Log("Skip contact " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						continue;
					}
					else if (!$bFindContact)
					{
						$bFindContact = true;
						continue;
					}
					$oContact = $oApiContacts->getContactById($oListItem->IdUser, $oListItem->Id);
					$aContactOptions = ContactP7ToP8($oContact);
					if (!$contactResult = $oContactsDecorator->CreateContact($aContactOptions, $oUser->EntityId))
					{
						\Aurora\System\Api::Log("Error while Contact creation: " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						break;
					}
					else
					{
						$oMigrationLog->CurContactsId = $oListItem->Id;
						file_put_contents($sMigrationLogFile, json_encode($oMigrationLog));
						$iContactsCount++;
					}
				}
				\Aurora\System\Api::Log("Contacts processed: " . $iContactsCount, \Aurora\System\Enums\LogLevel::Full, 'migration-');
			}
		}
		$oMigrationLog->CurUsersPage++;
	}
	$oMigrationLog->CurUsersPage = 0;

	\Aurora\System\Api::skipCheckUserRole(false);
}

\Aurora\System\Api::Log("Done. $iUserCount users processed", \Aurora\System\Enums\LogLevel::Full, 'migration-');

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