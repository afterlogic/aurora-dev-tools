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

	public $oP7PDO = false;
	public $oP7Settings = false;
	public $oP8PDO = false;
	public $oP8Settings = false;

	public $oP7ApiDomainsManager = null;
	public $oP7ApiUsersManager = null;
	public $oP7ApiContactsManagerFrom = null;
	public $oP7ApiContactsManager = null;
	public $oP7ApiSocial = null;

	public $oP8ContactsDecorator = null;
	public $oP8CoreDecorator = null;
	public $oP8MailModule = null;
	public $oP8MailModuleDecorator = null;
	public $oP8OAuthIntegratorWebclientModule = null;

	public $sMigrationLogFile = null;
	public $oMigrationLog = null;

	public $iUserCount = 0;
	public $bFindDomain = false;
	public $bFindUser = false;
	public $bFindAccount = false;
	public $bFindIdentity = false;
	public $bFindContact = false;
	public $bFindSocial = false;

	public function Init()
	{
		$this->oP7PDO = \CApi::GetPDO();
		$this->oP7Settings = \CApi::GetSettings();
		$this->oP8PDO = \Aurora\System\Api::GetPDO();
		$this->oP8Settings = \Aurora\System\Api::GetSettings();

		/* @var $oP7ApiDomainsManager CApiDomainsManager */
		$this->oP7ApiDomainsManager = \CApi::Manager('domains');
		/* @var $oP7ApiUsersManager CApiUsersManager */
		$this->oP7ApiUsersManager = \CApi::Manager('users');
		/* @var $oP7ApiContactsManagerFrom CApiContactsManager */
		$this->oP7ApiContactsManagerFrom = \CApi::Manager('contactsmain', 'db');
		/* @var $P7ApiContacts CApiContactsManager */
		$this->oP7ApiContactsManager = \CApi::Manager('contacts');
		/* @var $oP7ApiSocial \CApiSocialManager */
		$this->oP7ApiSocial = \CApi::Manager('social');
		/* @var $oP7ApiMail \CApiMailManager */
		$this->oP7ApiMail = \CApi::Manager('mail');

		$this->oP8ContactsDecorator = \Aurora\System\Api::GetModuleDecorator('Contacts');
		$this->oP8CoreDecorator = \Aurora\System\Api::GetModuleDecorator('Core');
		$oP8MailModule = \Aurora\System\Api::GetModule("Mail");
		$this->oP8MailModule = $oP8MailModule;
		$this->oP8MailModuleDecorator = $oP8MailModule::Decorator();
		$this->oP8OAuthIntegratorWebclientModule = \Aurora\System\Api::GetModule("OAuthIntegratorWebclient");

		if (!$this->oP8MailModule instanceof Aurora\Modules\Mail\Module)
		{
			exit("Error during migration process. For more details see log-file.");
		}

		$this->sMigrationLogFile = \Aurora\System\Api::DataPath() . '/migration';
		if (file_exists($this->sMigrationLogFile))
		{
			$this->oMigrationLog = json_decode(@file_get_contents($this->sMigrationLogFile));
		}
		
		if (!$this->oMigrationLog)
		{
			$this->oMigrationLog = (object) [
				'DBUpgraded' => 0,
				'CurDomainId' => -1,
				'CurUsersPage' => 1,
				'CurUserId' => 0,
				'CurAccountId' => 0,
				'NewAccountId' => 0,
				'CurIdentitiesId' => 0,
				'NewIdentitiesId' => 0,
				'CurContactId' => 0,
				'CurSocialAccountId' => 0,
				'NewSocialAccountId' => 0,
			];
		}
	}

	public function Start()
	{
		$this->Init();
		if (!$this->oMigrationLog->DBUpgraded)
		{
			if (!$this->UpgrateDB())
			{
				exit("Error during migration process. For more details see log-file.");
			}
			$this->oMigrationLog->DBUpgraded = 1;
			file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
		}

		if ($this->oMigrationLog->CurDomainId !== -1 && $this->oMigrationLog->CurUserId > 0)
		{
			echo "Continue migration from Domain: {$this->oMigrationLog->CurDomainId} UserId: {$this->oMigrationLog->CurUserId}\n";
			\Aurora\System\Api::Log("Continue migration from Domain: {$this->oMigrationLog->CurDomainId} UserId: {$this->oMigrationLog->CurUserId}", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		}
		else
		{
			echo "Start migration\n";
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
					exit("Error during migration process. For more details see log-file.");
				}
				$oServer = $this->oP8MailModuleDecorator->GetServer($iServerId);
				if (!$oServer instanceof \Aurora\Modules\Mail\Classes\Server)
				{
					\Aurora\System\Api::Log("Server not found. Server Id: " . $iServerId, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process. For more details see log-file.");
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
								exit("Error during migration process. For more details see log-file.");
							}
							$oP8User = $this->oP8CoreDecorator->GetUserByPublicId($sP7UserEmail);
							if (!$oP8User instanceof \Aurora\Modules\Core\Classes\User)
							{
								\Aurora\System\Api::Log("User not found: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process. For more details see log-file.");
							}

							$this->oMigrationLog->CurUserId = $iP7UserId;
							$this->oMigrationLog->CurAccountId = 0;
							$this->oMigrationLog->NewAccountId = 0;
							$this->oMigrationLog->CurIdentitiesId = 0;
							$this->oMigrationLog->NewIdentitiesId = 0;
							$this->oMigrationLog->CurSocialAccountId = 0;
							$this->oMigrationLog->NewSocialAccountId = 0;
							file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
						}
						if (!$this->UserP7ToP8($iP7UserId, $oP8User))
						{
							\Aurora\System\Api::Log("Error while User settings creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
							exit("Error during migration process. For more details see log-file.");
						}
						$this->iUserCount++;
						//DAV Calendars
						if (!$this->UpgradeDAVCalendar($oP8User))
						{
							\Aurora\System\Api::Log("Error while User calendars creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
							exit("Error during migration process. For more details see log-file.");
						}

						//ACCOUNTS
						$aAccountsId = $this->oP7ApiUsersManager->getAccountIdList($iP7UserId);
						foreach ($aAccountsId as $iP7AccountId)
						{
							if (!$this->bFindAccount && $this->oMigrationLog->CurAccountId !== 0 && $iP7AccountId < $this->oMigrationLog->CurAccountId)
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
								exit("Error during migration process. For more details see log-file.");
							}
							//SOCIAL ACCOUNTS
							if (!$this->SocialAccountsP7ToP8($iP7AccountId, $oP8User, $oServer))
							{
								\Aurora\System\Api::Log("Error while User social accounts creation: " . $sP7UserEmail, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process. For more details see log-file.");
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
							if (!$this->ContactP7ToP8($oListItem->IdUser, $oListItem->Id, $oP8User))
							{
								\Aurora\System\Api::Log("Error while Contact creation: " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
								exit("Error during migration process. For more details see log-file.");
							}
							else
							{
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
				exit("Error during migration process. For more details see log-file.");
			}
			$oServer = $this->oP8MailModuleDecorator->GetServer($iServerId);
		}

		$aServer = ['ServerId' => $oServer->EntityId];

		if ($this->oMigrationLog->CurAccountId === $iP7AccountId)
		{
			$oP8Account = $this->oP8MailModule->oApiAccountsManager->getAccountById($this->oMigrationLog->NewAccountId);
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
			$this->oMigrationLog->CurIdentitiesId = 0;
			$this->oMigrationLog->NewIdentitiesId = 0;
		}

		if ($oP8Account)
		{
			$sFolderOrders = '';
			$aFolderOrders = $this->oP7ApiMail->getFoldersOrder($oP7Account);
			if (is_array($aFolderOrders) && count($aFolderOrders) > 0)
			{
				$sFolderOrders = json_encode($aFolderOrders);
			}

			$oP8Account->IsDisabled = $oP7Account->IsDisabled;
			$oP8Account->UseToAuthorize = !$oP7Account->IsInternal;
			$oP8Account->Signature = $oP7Account->Signature;
			$oP8Account->UseSignature = $oP7Account->SignatureOptions;
			$oP8Account->UseThreading = $oServer->EnableThreading;
			$oP8Account->FoldersOrder = $sFolderOrders;

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

	public function SocialAccountsP7ToP8($iP7AccountId, \Aurora\Modules\Core\Classes\User $oP8User, $oServer)
	{
		$aSocials = $this->oP7ApiSocial->getSocials($iP7AccountId);
		if (is_array($aSocials))
		{
			foreach ($aSocials as $oSocial)
			{
				if (!$this->bFindSocial && $this->oMigrationLog->CurSocialAccountId !== 0 && $oSocial->Id <= $this->oMigrationLog->CurSocialAccountId)
				{
					//skip Social Account if already done
					\Aurora\System\Api::Log("Skip Social Account: " . $oSocial->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					continue;
				}
				else
				{
					$this->bFindSocial = true;
				}

				$oP8SocialAccount = new \Aurora\Modules\OAuthIntegratorWebclient\Classes\Account();
				$oP8SocialAccount->IdUser = $oP8User->EntityId;
				$oP8SocialAccount->IdSocial = $oSocial->IdSocial;
				$oP8SocialAccount->Type = $oSocial->TypeStr;
				$oP8SocialAccount->Name = $oSocial->Name;
				$oP8SocialAccount->Email = $oSocial->Email;
				$oP8SocialAccount->RefreshToken = $oSocial->RefreshToken;
				$oP8SocialAccount->Scopes = $oSocial->Scopes;
				$oP8SocialAccount->Disabled = $oSocial->Disabled;

				if (!$this->oP8OAuthIntegratorWebclientModule->oManager->createAccount($oP8SocialAccount))
				{
					\Aurora\System\Api::Log("Error while Social Account creation: " . $oSocial->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process. For more details see log-file.");
				}

				$oP8NewSocialAccount = $this->oP8OAuthIntegratorWebclientModule->oManager->getAccount($oP8User->EntityId, $oSocial->TypeStr);
				if (!$oP8NewSocialAccount && !$oP8NewSocialAccount instanceof \Aurora\Modules\OAuthIntegratorWebclient\Classes\Account)
				{
					\Aurora\System\Api::Log("Error while Social Account creation: " . $oSocial->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					exit("Error during migration process. For more details see log-file.");
				}
				$this->oMigrationLog->CurSocialAccountId = $oSocial->Id;
				$this->oMigrationLog->NewSocialAccountId = $oP8NewSocialAccount->EntityId;
				file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
			}
		}
		return true;
	}
	public function IdentitiesP7ToP8($iP7AccountId, $oP8Account)
	{
		$bResult = false;
		$aAccountIdentities = $this->oP7ApiUsersManager->getAccountIdentities($iP7AccountId);
		if (is_array($aAccountIdentities) && count($aAccountIdentities) > 0)
		{
			foreach ($aAccountIdentities as $oP7Identity)
			{
				if (!$this->bFindIdentity && $this->oMigrationLog->CurIdentitiesId !== 0 && $oP7Identity->IdIdentity < $this->oMigrationLog->CurIdentitiesId)
				{
					//skip Identity if already done
					\Aurora\System\Api::Log("Skip Identity " . $oListItem->Id, \Aurora\System\Enums\LogLevel::Full, 'migration-');
					continue;
				}
				else
				{
					$this->bFindIdentity = true;
				}
				if ($oP7Identity instanceof \CIdentity)
				{
					if ($oP7Identity->IdIdentity === $this->oMigrationLog->CurIdentitiesId)
					{
						$iP8EntityId = $this->oMigrationLog->NewIdentitiesId;
					}
					else
					{
						$iP8EntityId = $this->oP8MailModuleDecorator->CreateIdentity(
							$oP8Account->IdUser,
							$oP8Account->EntityId,
							$oP7Identity->FriendlyName,
							$oP7Identity->Email
						);
					}
					if (!$iP8EntityId)
					{
						\Aurora\System\Api::Log("Error while Identity creation: " . $oP7Identity->Email, \Aurora\System\Enums\LogLevel::Full, 'migration-');
						return false;
					}
					$this->oMigrationLog->CurIdentitiesId = $oP7Identity->IdIdentity;
					$this->oMigrationLog->NewIdentitiesId = $iP8EntityId;
					file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));

					if (isset($oP7Identity->UseSignature) && isset($oP7Identity->Signature))
					{
						$bResult = !!$this->oP8MailModule->oApiIdentitiesManager->updateIdentitySignature($iP8EntityId, $oP7Identity->UseSignature, $oP7Identity->Signature);
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

	public function ContactP7ToP8($iP7UserId, $iP7ContactId, \Aurora\Modules\Core\Classes\User $oP8User)
	{
		$aResult = false;
		$aContactOptions = [];
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

		$oP7Contact = $this->oP7ApiContactsManager->getContactById($iP7UserId, $iP7ContactId);
		if (!$oP7Contact instanceof \CContact)
		{
			return $aResult;
		}

		foreach ($aObgectFieldsConformity as $sPropertyNameP8 => $sPropertyNameP7)
		{
			$aContactOptions[$sPropertyNameP8] = $oP7Contact->$sPropertyNameP7;
		}

		if ($this->oP8ContactsDecorator->CreateContact($aContactOptions, $oP8User->EntityId))
		{
			$this->oMigrationLog->CurContactId = $iP7ContactId;
			file_put_contents($this->sMigrationLogFile, json_encode($this->oMigrationLog));
			$aResult = true;
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

	public function UpgrateDB()
	{
		$oP7DBLogin = $this->oP7Settings->GetConf('Common/DBLogin');
		$oP7DBPassword = $this->oP7Settings->GetConf('Common/DBPassword');
		$oP7DBName = $this->oP7Settings->GetConf('Common/DBName');
		$oP7DBPrefix = $this->oP7Settings->GetConf('Common/DBPrefix');
		$oP7DBHost = $this->oP7Settings->GetConf('Common/DBHost');

		$oP8DBLogin = $this->oP8Settings->GetConf('DBLogin');
		$oP8DBPassword = $this->oP8Settings->GetConf('DBPassword');
		$oP8DBName = $this->oP8Settings->GetConf('DBName');
		$oP8DBPrefix = $this->oP8Settings->GetConf('DBPrefix');
		$oP8DBHost = $this->oP8Settings->GetConf('DBHost');

		//Delete tables in P8
		$sDelTableQuery = "DROP TABLE IF EXISTS `{$oP8DBPrefix}adav_addressbookchanges`,
			`{$oP8DBPrefix}adav_addressbooks`,
			`{$oP8DBPrefix}adav_cache`,
			`{$oP8DBPrefix}adav_calendarchanges`,
			`{$oP8DBPrefix}adav_calendarobjects`,
			`{$oP8DBPrefix}adav_calendars`,
			`{$oP8DBPrefix}adav_calendarshares`,
			`{$oP8DBPrefix}adav_calendarsubscriptions`,
			`{$oP8DBPrefix}adav_cards`,
			`{$oP8DBPrefix}adav_groupmembers`,
			`{$oP8DBPrefix}adav_locks`,
			`{$oP8DBPrefix}adav_principals`,
			`{$oP8DBPrefix}adav_propertystorage`,
			`{$oP8DBPrefix}adav_reminders`,
			`{$oP8DBPrefix}adav_schedulingobjects`";

		try
		{
			$this->oP8PDO->exec($sDelTableQuery);
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		\Aurora\System\Api::Log("Delete tables in P8 DB: " . $sDelTableQuery, \Aurora\System\Enums\LogLevel::Full, 'migration-');
		 echo "Remove tables\n";

		//Move tables from P7 DB to P8  DB
		$aOutput = null;
		$iStatus = null;
		$sMoveTablesFromP7ToP8 = "mysqldump -u{$oP7DBLogin} " . ($oP7DBPassword ? "-p{$oP7DBPassword}" : "") . " -h{$oP7DBHost} {$oP7DBName} --tables "
			. $oP7DBPrefix . "adav_addressbooks "
			. $oP7DBPrefix . "adav_cache "
			. $oP7DBPrefix . "adav_calendarobjects "
			. $oP7DBPrefix . "adav_calendars "
			. $oP7DBPrefix . "adav_calendarshares "
			. $oP7DBPrefix . "adav_cards "
			. $oP7DBPrefix . "adav_groupmembers "
			. $oP7DBPrefix . "adav_locks "
			. $oP7DBPrefix . "adav_principals "
			. $oP7DBPrefix . "adav_reminders "
			."| mysql -u{$oP8DBLogin} " . ($oP8DBPassword ? "-p{$oP8DBPassword}" : "") . " -h{$oP8DBHost}  {$oP8DBName}";

		exec($sMoveTablesFromP7ToP8, $aOutput, $iStatus);

		if ($iStatus !== 0)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. Failed process of moving tables from p7 DB to p8 DB.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		\Aurora\System\Api::Log("Move tables from p7 DB to p8  DB: " . $sMoveTablesFromP7ToP8, \Aurora\System\Enums\LogLevel::Full, 'migration-');
		echo "Move tables from p7 DB to p8  DB";
		echo "\n-----------------------------------------------\n";
		//Rename tables before upgrading
		$sRenameTablesQuery = "RENAME TABLE {$oP7DBPrefix}adav_addressbooks TO addressbooks,
			{$oP7DBPrefix}adav_cache TO cache,
			{$oP7DBPrefix}adav_calendarobjects TO calendarobjects,
			{$oP7DBPrefix}adav_calendars TO calendars,
			{$oP7DBPrefix}adav_calendarshares TO calendarshares,
			{$oP7DBPrefix}adav_cards TO cards,
			{$oP7DBPrefix}adav_groupmembers TO groupmembers,
			{$oP7DBPrefix}adav_locks TO locks,
			{$oP7DBPrefix}adav_principals TO principals,
			{$oP7DBPrefix}adav_reminders TO reminders";

		try
		{
			$this->oP8PDO->exec($sRenameTablesQuery);
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}

		//Upgrade sabredav data from 1.8 to 3.0 version
		unset($aOutput);
		unset($iStatus);
		$sUpgrade18To20 = "php ../vendor/sabre/dav/bin/migrateto20.php \"mysql:host={$oP8DBHost};dbname={$oP8DBName}\" {$oP8DBLogin}" . ($oP8DBPassword ? " {$oP8DBPassword}" : "");
		exec($sUpgrade18To20, $aOutput, $iStatus);
		if ($iStatus !== 0)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. Failed migration from a pre-2.0 database to 2.0.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		\Aurora\System\Api::Log("Migrate from a pre-2.0 database to 2.0.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		echo  implode("\n", $aOutput);
		echo "\n-----------------------------------------------\n";

		unset($aOutput);
		unset($iStatus);
		$sUpgrade20To21 = "php ../vendor/sabre/dav/bin/migrateto21.php \"mysql:host={$oP8DBHost};dbname={$oP8DBName}\" {$oP8DBLogin}" . ($oP8DBPassword ? " {$oP8DBPassword}" : "");
		exec($sUpgrade20To21, $aOutput, $iStatus);
		if ($iStatus !== 0)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. Failed migration from a pre-2.1 database to 2.1.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		\Aurora\System\Api::Log("Migrate from a pre-2.1 database to 2.1.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		echo  implode("\n", $aOutput);
		echo "\n-----------------------------------------------\n";

		unset($aOutput);
		unset($iStatus);
		$sUpgrade21To30 = "php ../vendor/sabre/dav/bin/migrateto30.php \"mysql:host={$oP8DBHost};dbname={$oP8DBName}\" {$oP8DBLogin}" . ($oP8DBPassword ? " {$oP8DBPassword}" : "");
		exec($sUpgrade21To30, $aOutput, $iStatus);
		if ($iStatus !== 0)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. Failed migration from a pre-3.0 database to 3.0.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		\Aurora\System\Api::Log("Migrate from a pre-3.3 database to 3.0.", \Aurora\System\Enums\LogLevel::Full, 'migration-');
		echo  implode("\n", $aOutput);
		echo "\n-----------------------------------------------\n";

		//Add prefixes
		$sPrefix = $oP8DBPrefix . "adav_";
		$sAddPrefixQuery = "RENAME TABLE addressbooks TO {$sPrefix}addressbooks,
			cache TO {$sPrefix}cache,
			calendarobjects TO {$sPrefix}calendarobjects,
			calendars TO {$sPrefix}calendars,
			calendarshares TO {$sPrefix}calendarshares,
			cards TO {$sPrefix}cards,
			groupmembers TO {$sPrefix}groupmembers,
			locks TO {$sPrefix}locks,
			principals TO {$sPrefix}principals,
			reminders TO {$sPrefix}reminders,
			calendarchanges TO {$sPrefix}calendarchanges,
			calendarsubscriptions TO {$sPrefix}calendarsubscriptions,
			propertystorage TO {$sPrefix}propertystorage,
			schedulingobjects TO {$sPrefix}schedulingobjects,
			addressbookchanges TO {$sPrefix}addressbookchanges";

		try
		{
			$this->oP8PDO->exec($sAddPrefixQuery);
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}

		//Remove DAV contacts
		$sTruncateQuery = "TRUNCATE {$sPrefix}addressbooks; TRUNCATE {$sPrefix}cards;";
		try
		{
			$this->oP8PDO->exec($sTruncateQuery);
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during upgrade DB process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}

		echo  "DB upgraded\n";
		return true;
	}

	public function UpgradeDAVCalendar(\Aurora\Modules\Core\Classes\User $oP8User)
	{
		$oP8DBPrefix = $this->oP8Settings->GetConf('DBPrefix');
		try
		{
			$sCalendarUpdateQuery = "UPDATE `{$oP8DBPrefix}adav_calendars` 
					SET `principaluri`= 'principals/{$oP8User->UUID}'
					WHERE `principaluri` = 'principals/{$oP8User->PublicId}'";
			$stmt = $this->oP8PDO->prepare($sCalendarUpdateQuery);
			$stmt->execute();
			$stmt->closeCursor();
		}
		catch(Exception $e)
		{
			\Aurora\System\Api::Log("Error during calendars migration process. " .  $e->getMessage(), \Aurora\System\Enums\LogLevel::Full, 'migration-');
			return false;
		}
		return true;
	}
}

$oMigration = new P7ToP8Migration();
$oMigration->Start();
exit("Done");
