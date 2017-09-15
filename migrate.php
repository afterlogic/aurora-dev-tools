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

class P7ToP8Migration
{
	const ITEMS_PER_PAGE = 20;

	public $oP7ApiDomainsManager = null;
	public $oP7ApiUsersManager = null;
	public $oP7ApiContactsManagerFrom = null;
	public $oP7ApiContactsManager = null;

	public $oP8ContactsDecorator = null;
	public $oP8CoreDecorator = null;
	public $oP8MailModule = null;
	public $oP8MailModuleDecorator = null;

	public $sMigrationLogFile = null;
	public $oMigrationLog = null;

	public $iUserCount = 0;
	public $bFindDomain = false;
	public $bFindUser = false;
	public $bFindAccount = false;
	public $bFindContact = false;

	public function Init()
	{
		/* @var $oP7ApiDomainsManager CApiDomainsManager */
		$this->oP7ApiDomainsManager = \CApi::Manager('domains');
		/* @var $oP7ApiUsersManager CApiUsersManager */
		$this->oP7ApiUsersManager = \CApi::Manager('users');
		/* @var $oP7ApiContactsManagerFrom CApiContactsManager */
		$this->oP7ApiContactsManagerFrom = \CApi::Manager('contactsmain', 'db');
		/* @var $P7ApiContacts CApiContactsManager */
		$this->oP7ApiContactsManager = \CApi::Manager('contacts');

		$this->oP8ContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
		$this->oP8CoreDecorator = \Aurora\Modules\Core\Module::Decorator();
		$oP8MailModule = \Aurora\System\Api::GetModule("Mail");
		$this->oP8MailModule = $oP8MailModule;
		$this->oP8MailModuleDecorator = $oP8MailModule::Decorator();

		if (!$this->oP8MailModule instanceof Aurora\Modules\Mail\Module)
		{
			exit("Error during migration process");
		}

		$this->sMigrationLogFile = \Aurora\System\Api::DataPath() . '/migration';
		if (file_exists($this->sMigrationLogFile))
		{
			$this->oMigrationLog = json_decode(@file_get_contents($this->sMigrationLogFile));
		}
		
		if (!$this->oMigrationLog)
		{
			$this->oMigrationLog = (object) [
				'CurDomainId' => -1,
				'CurUsersPage' => 1,
				'CurUserId' => 0,
				'CurAccountId' => 0,
				'NewAccountId' => 0,
				'CurContactId' => 0
			];
		}
	}

	public function Start()
	{
		$this->Init();
		if ($this->oMigrationLog->CurDomainId !== -1 && $this->oMigrationLog->CurUserId > 0)
		{
			\Aurora\System\Api::Log("Continue migration from Domain: {$this->oMigrationLog->CurDomainId} UserId: {$this->oMigrationLog->CurUserId}", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		}
		else
		{
			\Aurora\System\Api::Log("Start migration", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		}
		//DOMAINS
		$aDomains = $this->oP7ApiDomainsManager->getFullDomainsList();
		$aDomains[0] = array(false, 'Default'); // Default Domain

		foreach ($aDomains as $iDomainId => $oDomainItem)
		{
			$sDomainName = $oDomainItem[1];
			if (!$this->bFindDomain && $this->oMigrationLog->CurDomainId !== -1 && $this->oMigrationLog->CurDomainId !== $iDomainId)
			{
				//skip Domain if already done
				\Aurora\System\Api::Log("Skip domain: " . $sDomainName, \Aurora\System\Enums\LogLevel::Full, 'migration-');
				continue;
			}
			else
			{
				$this->bFindDomain = true;
				$this->oMigrationLog->CurDomainId = $iDomainId;
				\Aurora\System\Api::Log("Process domain: " . $sDomainName, \Aurora\System\Enums\LogLevel::Full, 'migration-');
			}

			$oServer = $iDomainId !== 0 ? $this->GetServerByName($sDomainName) : false;

			if ($iDomainId !== 0 && !$oServer)
			{
				//create server if not exists and not default
				$iServerId = $this->DomainP7ToP8($this->oP7ApiDomainsManager->getDomainById($iDomainId));
				if (!$iServerId)
				{
					\Aurora\System\Api::Log("Error while Server creation: " . $sDomainName, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process");
				}
				$oServer = $this->oP8MailModuleDecorator->GetServer($iServerId);
				if (!$oServer instanceof \Aurora\Modules\Mail\Classes\Server)
				{
					\Aurora\System\Api::Log("Server not found. Server Id: " . $iServerId, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process");
				}
				file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
			}

			//USERS
			$iUsersCount = $this->oP7ApiUsersManager->getUsersCountForDomain($iDomainId);
			$iPageUserCount = ceil($iUsersCount / self::ITEMS_PER_PAGE);

			$aUsers = array();
			while ($this->oMigrationLog->CurUsersPage - 1 < $iPageUserCount)
			{
				$aUsers = $this->oP7ApiUsersManager->getUserList($iDomainId, $this->oMigrationLog->CurUsersPage, self::ITEMS_PER_PAGE);
				if (is_array($aUsers) && count($aUsers) > 0)
				{
					foreach ($aUsers as $aP7UserItem)
					{
						$iP7UserId = (int) $aP7UserItem[4];
						$sP7UserEmail = $aP7UserItem[1];
						if (!$this->bFindUser && $this->oMigrationLog->CurUserId !== 0 && $iP7UserId < $this->oMigrationLog->CurUserId)
						{
							//skip User if already done
							\Aurora\System\Api::Log("Skip user: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
							continue;
						}
						else
						{
							$this->bFindUser = true;
						}

						$oP8User = $this->oP8CoreDecorator->GetUserByPublicId($sP7UserEmail);
						if ($oP8User instanceof \Aurora\Modules\Core\Classes\User)
						{
							\Aurora\System\Api::Log("User already exists: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						}
						else
						{
							if (!$this->oP8CoreDecorator->CreateUser(0, $sP7UserEmail, \Aurora\System\Enums\UserRole::NormalUser, false))
							{
								\Aurora\System\Api::Log("Error while User creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process");
							}
							else
							{
								$oP8User = $this->oP8CoreDecorator->GetUserByPublicId($sP7UserEmail);
								if (!$oP8User instanceof \Aurora\Modules\Core\Classes\User)
								{
									\Aurora\System\Api::Log("User not found: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
									exit("Error during migration process");
								}
								$this->oMigrationLog->CurUserId = $iP7UserId;
								$this->oMigrationLog->CurAccountId = 0;
								$this->oMigrationLog->NewAccountId = 0;
								file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
							}
						}
						if (!$this->UserP7ToP8($iP7UserId, $oP8User))
						{
							\Aurora\System\Api::Log("Error while User settings creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
							exit("Error during migration process");
						}
						$this->iUserCount++;

						//ACCOUNTS
						$aAccountsId = $this->oP7ApiUsersManager->getAccountIdList($iP7UserId);
						foreach ($aAccountsId as $iP7AccountId)
						{
							if (!$this->bFindAccount && $this->oMigrationLog->CurAccountId !== 0 && $this->oMigrationLog->CurAccountId < $iP7AccountId)
							{
								//skip Account if already done
								\Aurora\System\Api::Log("Skip Account: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								continue;
							}
							else
							{
								$this->bFindAccount = true;
							}
							if (!$this->AccountP7ToP8($iP7AccountId, $oP8User, $oServer))
							{
								\Aurora\System\Api::Log("Error while User accounts creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process");
							}
						}

						//CONTACTS
						/* @var $aContactListItems array */
						$aContactListItems = $this->oP7ApiContactsManagerFrom->getContactItemsWithoutOrder($iP7UserId, 0, 9999);
						if (count($aContactListItems) === 0)
						{
							$this->oMigrationLog->CurContactId = 0;
							file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
						}
						$iContactsCount = 0;
						/* @var $oListItem CContactListItem */
						foreach ($aContactListItems as $oListItem)
						{
							if (!$this->bFindContact && $this->oMigrationLog->CurContactId !== 0 && $oListItem->Id <= $this->oMigrationLog->CurContactId)
							{
								//skip Contact if already done
								\Aurora\System\Api::Log("Skip contact " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								continue;
							}
							else
							{
								$this->bFindContact = true;
							}
							$oP7Contact = $this->oP7ApiContactsManager->getContactById($oListItem->IdUser, $oListItem->Id);
							$aContactOptions = $this->ContactP7ToP8($oP7Contact);
							if (!$contactResult = $this->oP8ContactsDecorator->CreateContact($aContactOptions, $oP8User->EntityId))
							{
								\Aurora\System\Api::Log("Error while Contact creation: " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process");
							}
							else
							{
								$this->oMigrationLog->CurContactId = $oListItem->Id;
								file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
								$iContactsCount++;
							}
						}
						\Aurora\System\Api::Log("User: $sP7UserEmail Contacts processed: " . $iContactsCount, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					}
				}
				$this->oMigrationLog->CurUsersPage++;
			}
			$this->oMigrationLog->CurUsersPage = 1;
		}
		\Aurora\System\Api::Log("Done. {$this->iUserCount} users processed", \Aurora\System\Enums\LogLevel::Full, 'migration-');
	}

	public function UserP7ToP8($iP7UserId, \Aurora\Modules\Core\Classes\User $oP8User)
	{
		$oP7User = $this->oP7ApiUsersManager->getUserById($iP7UserId);
		$iP7AccountId = $this->oP7ApiUsersManager->getDefaultAccountId($iP7UserId);
		$oP7Account = $this->oP7ApiUsersManager->getAccountById($iP7AccountId);
		$oP7UserCalendarSettings = $this->oP7ApiUsersManager->getCalUser($iP7UserId);

		if (!$oP7User instanceof \CUser)
		{
			return false;
		}
		
		$oP8User->IsDisabled = $oP7Account ? $oP7Account->IsDisabled : false;

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
		if ($oP7UserCalendarSettings)
		{
			$oP8User->{'Calendar::HighlightWorkingHours'} = $oP7UserCalendarSettings->ShowWorkDay;
			$oP8User->{'Calendar::HighlightWorkingDays'} = $oP7UserCalendarSettings->ShowWeekEnds;
			$oP8User->{'Calendar::WorkdayStarts'} = $oP7UserCalendarSettings->WorkDayStarts;
			$oP8User->{'Calendar::WorkdayEnds'} = $oP7UserCalendarSettings->WorkDayEnds;
			$oP8User->{'Calendar::WeekStartsOn'} = $oP7UserCalendarSettings->WeekStartsOn;
			$oP8User->{'Calendar::DefaultTab'} = $oP7UserCalendarSettings->DefaultTab;
		}

		//Other
		$oP8User->Question1 = $oP7User->Question1;
		$oP8User->Question2 = $oP7User->Question2;
		$oP8User->Answer1 = $oP7User->Answer1;
		$oP8User->Answer2 = $oP7User->Answer2;
		$oP8User->SipEnable = $oP7User->SipEnable;
		$oP8User->SipImpi = $oP7User->SipImpi;
		$oP8User->SipPassword = $oP7User->SipPassword;
		$oP8User->Capa = $oP7User->Capa;
		$oP8User->CustomFields = $oP7User->CustomFields;
		$oP8User->FilesEnable = $oP7User->FilesEnable;
		$oP8User->EmailNotification = $oP7User->EmailNotification;
		$oP8User->PasswordResetHash = $oP7User->PasswordResetHash;

		return $this->oP8CoreDecorator->UpdateUserObject($oP8User);
	}

	public function AccountP7ToP8($iP7AccountId, \Aurora\Modules\Core\Classes\User $oP8User, $oServer)
	{
		$bResult = false;
		$oP7Account = $this->oP7ApiUsersManager->getAccountById($iP7AccountId);
		if (!$oP7Account instanceof \CAccount)
		{
			return false;
		}

		if (!$oServer && $oP7Account->Domain->IdDomain === 0)
		{
			$iServerId = $this->DomainP7ToP8($oP7Account->Domain);
			if (!$iServerId)
			{
				\Aurora\System\Api::Log("Error while Server creation: " . $oP7Account->Domain->IncomingMailServer, \Aurora\System\Enums\LogLevel::Full, 'migration-');
				exit("Error during migration process");
			}
			$oServer = $this->oP8MailModuleDecorator->GetServer($iServerId);
		}

		$aServer = ['ServerId' => $oServer->EntityId];

		if ($this->oMigrationLog->CurAccountId === $iP7AccountId)
		{
			$oP8Account = $this->oP8MailModuleDecorator->GetAccount($this->oMigrationLog->NewAccountId);
		}
		else
		{
			$oP8Account = $this->oP8MailModuleDecorator->CreateAccount(
				$oP8User->EntityId,
				$oP7Account->FriendlyName,
				$oP7Account->Email,
				$oP7Account->IncomingMailLogin,
				$oP7Account->IncomingMailPassword,
				$aServer
			);
		}

		if ($oP8Account)
		{
			$oP8Account->IsDisabled = $oP7Account->IsDisabled;
			$oP8Account->UseToAuthorize = !$oP7Account->IsInternal;
			$oP8Account->Signature = $oP7Account->Signature;
			$oP8Account->UseSignature = $oP7Account->SignatureOptions;
			$oP8Account->UseThreading = $oServer->EnableThreading;
			$bResult = $this->oP8MailModule->oApiAccountsManager->updateAccount($oP8Account);
			
			$this->oMigrationLog->CurAccountId = $iP7AccountId;
			$this->oMigrationLog->NewAccountId = $oP8Account->EntityId;
			file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
		}
		if ($bResult)
		{
			$bResult = $this->IdentitiesP7ToP8($iP7AccountId, $oP8Account);
		}
		return $bResult;
	}

	public function IdentitiesP7ToP8($iP7AccountId, $oP8Account)
	{
		$bResult = false;
		$aAccountIdentities = $this->oP7ApiUsersManager->getAccountIdentities($iP7AccountId);
		if (is_array($aAccountIdentities) && count($aAccountIdentities) > 0)
		{
			foreach ($aAccountIdentities as $oP7Identity)
			{
				if ($oP7Identity instanceof \CIdentity)
				{
					$iEntityId = $this->oP8MailModuleDecorator->CreateIdentity(
						$oP8Account->IdUser,
						$oP8Account->EntityId,
						$oP7Identity->FriendlyName,
						$oP7Identity->Email
					);
					if (!$iEntityId)
					{
						\Aurora\System\Api::Log("Error while Identity creation: " . $oP7Identity->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						return false;
					}
//					if ($oP7Identity->IdAccount==104 && $oP7Identity->IdIdentity==3)
//					{
//						exit("3453453465463758");
//					}
					if (isset($oP7Identity->UseSignature) && isset($oP7Identity->Signature))
					{
						$bResult = !!$this->oP8MailModule->oApiIdentitiesManager->updateIdentitySignature($iEntityId, $oP7Identity->UseSignature, $oP7Identity->Signature);
						if (!$bResult)
						{
							\Aurora\System\Api::Log("Error while Signature creation: " . $oP7Identity->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
							return $bResult;
						}
					}
				}
			}
		}
		else
		{
			$bResult = true;
		}
		return $bResult;
	}

	public function ContactP7ToP8(\CContact $oContact)
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

	public function GetServerByName($sServerName)
	{
		$aServers = $this->oP8MailModuleDecorator->GetServers();
		foreach ($aServers as $oServer)
		{
			if ($oServer->Name === $sServerName)
			{
				return $oServer;
			}
		}
		return false;
	}

	public function DomainP7ToP8(\CDomain $oDomain)
	{
		$iServerId = $this->oP8MailModule->oApiServersManager->CreateServer(
			$oDomain->IdDomain === 0 ? $oDomain->IncomingMailServer : $oDomain->Name,
			$oDomain->IncomingMailServer,
			$oDomain->IncomingMailPort,
			$oDomain->IncomingMailUseSSL,
			$oDomain->OutgoingMailServer,
			$oDomain->OutgoingMailPort,
			$oDomain->OutgoingMailUseSSL,
			$oDomain->OutgoingMailAuth,
			$oDomain->IdDomain === 0 ? '*' : $oDomain->Name, //Domains
			true, //EnableThreading
			$oDomain->OutgoingMailLogin,
			$oDomain->OutgoingMailPassword,
			false, //EnableSieve
			2000, //SievePort
			$oDomain->IdDomain === 0 ? \Aurora\Modules\Mail\Enums\ServerOwnerType::Account : \Aurora\Modules\Mail\Enums\ServerOwnerType::SuperAdmin,
			0 //TenantId
		);
		return $iServerId ? $iServerId : false;
	}
}

$oMigration = new P7ToP8Migration();
$oMigration->Start();