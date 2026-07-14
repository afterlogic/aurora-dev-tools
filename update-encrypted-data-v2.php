<?php

if (PHP_SAPI !== 'cli') {
    exit("Use the console for running this script");
}

include_once '../system/autoload.php';

use Aurora\Api;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Database\Capsule\Manager as Capsule;

Api::Init();

abstract class Enums
{
    public const file = 1;
    public const console = 2;
    public const both = 3;
}
function logMessage($output, $message, $mode = Enums::both) {
    if ($mode === Enums::console || $mode === Enums::both) {
        $output->writeln($message);
    }
    if ($mode === Enums::file || $mode === Enums::both) {
        Api::Log($message, \Aurora\System\Enums\LogLevel::Full, 'update-encryption-key-');
    }
}

function logObjectResults($output, $data, $title) {
    logMessage($output, "  $title:");
    foreach ($data as $propName => $ids) {
        logMessage($output, "    Property: $propName");
        logMessage($output, "    Ids: " . implode(', ', $ids));
    }
    logMessage($output, "");
}

function updateEncryptedProp($class, $shortClassName, $propNames, $count, $output) {
    $progressBar = new ProgressBar($output, $count);
    $progressBar->setFormat('verbose');
    $progressBar->setBarCharacter('<info>=</info>');

    $progressBar->start();
    $aObjectResults= [
        'missing' => [],
        'updated' => [],
        'error' => [],
        'empty' => [],
    ];

    foreach ($propNames as $propName) {
        $aObjectResults['missing'][$propName] = [];
        $aObjectResults['updated'][$propName] = [];
        $aObjectResults['error'][$propName] = [];
        $aObjectResults['empty'][$propName] = [];
    }

    $class::where('Properties->EncryptionKeyIsUpdatedV2', false)->orWhere('Properties->EncryptionKeyIsUpdatedV2', null)->chunk(10000, function ($items) use ($propNames, $progressBar, &$aObjectResults, $output) {
        foreach ($items as $item) {
            $bObjectError = false;
            foreach ($propNames as $propName) {
                if (strpos($propName, '::') !== false) {
                    $propValue = $item->getExtendedProp($propName);

                    $decryptedValue = \Aurora\System\Utils::DecryptValue($propValue);
                            
                    if ($decryptedValue) {
                        $item->setExtendedProp($propName, \Aurora\System\Utils::EncryptValue($decryptedValue));
                        //store updated item id
                        $aObjectResults['updated'][$propName][] = $item->Id;
                    } else {
                        //store failed item id
                        $bObjectError = true;
                        $aObjectResults['error'][$propName][] = $item->Id;
                    }
                } else {
                    $rawValue = trim($item->getRawOriginal($propName));
                    if ($rawValue !== '') {
                        // Most of model properties are decrypted automatically when they are read.
                        $propValue = $item->{$propName};
    
                        if ($propValue) {
                            $item->{$propName} = $propValue;
                            //store updated item id
                            $aObjectResults['updated'][$propName][] = $item->Id;
                        } elseif ($propValue === false || $rawValue !== '' && trim($propValue) === '') {
                            // false means decryption error, but currently auto encrypted fields return empty strings when value cannot be decrypted
                            $bObjectError = true;
                            $aObjectResults['error'][$propName][] = $item->Id;
                        } elseif ($propValue === null) {
                            $aObjectResults['missing'][$propName][] = $item->Id;
                        } elseif (trim($propValue) === '') {
                            $aObjectResults['empty'][$propName][] = $item->Id;
                        }
                    } else {
                        $aObjectResults['empty'][$propName][] = $item->Id;
                    }
                }
            }
            $item->setExtendedProp('EncryptionKeyIsUpdatedV2', true);
            logMessage($output, "EncryptionKeyIsUpdatedV2: $item->Id: " . (!$bObjectError ? "true" : "false"));
            if ($item->save()) {
                $progressBar->advance();
            } else {
                logMessage($output, "Object saving error: $item->getName(): $item->Id");
            }
        }
    });

    $progressBar->finish();
    logMessage($output, "");
    logMessage($output, "'$shortClassName' objects updating results:");

    logObjectResults($output, $aObjectResults['updated'], "Updated");
    logObjectResults($output, $aObjectResults['error'], "Errors");
    logObjectResults($output, $aObjectResults['missing'], "Missing");
    logObjectResults($output, $aObjectResults['empty'], "Empty");
}

function updateEncryptedConfig($moduleName, $configName, $output) {
    if (Api::$oModuleManager->isModuleLoaded($moduleName)) {

        logMessage($output, "Processing $moduleName->$configName: ");

        $configValue = Api::$oModuleManager->getModuleConfigValue($moduleName, $configName);
        if ($configValue) {
            $value = \Aurora\System\Utils::DecryptValue($configValue);

            if ($value) {
                $value = \Aurora\System\Utils::EncryptValue($value);
                Api::$oModuleManager->setModuleConfigValue($moduleName, $configName, $value);
                Api::$oModuleManager->saveModuleConfigValue($moduleName);

                logMessage($output, "Config file updated");
            } else {
                logMessage($output, "Can't decrypt config value");
            }
        } else {
            logMessage($output, "Config value not found");
        }
    }
}

function processObject($class, $props, $input, $output, $helper, $force) {

    $classParts = explode('\\', $class);
    $shortClassName = end($classParts);

    logMessage($output, "Processing $class objects");

    if (class_exists($class)) {
        $classTablename = with(new $class)->getTable();
        if (Capsule::schema()->hasTable($classTablename)) {
            if ($force) {
                $class::where('Properties->EncryptionKeyIsUpdatedV2', true)->update(['Properties->EncryptionKeyIsUpdatedV2' => false]);
            }

            $allObjectsCount = $class::count();
            $objectsCount = $class::where('Properties->EncryptionKeyIsUpdatedV2', false)->orWhere('Properties->EncryptionKeyIsUpdatedV2', null)->count();

            logMessage($output, $allObjectsCount . ' object(s) found, ' . $objectsCount . ' of them have not yet been updated');
            if ($objectsCount > 0) {
                $question = new ConfirmationQuestion('Update encrypted properties for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updateEncryptedProp($class, $shortClassName, $props, $objectsCount, $output);
                }
            } else {
                logMessage($output, 'No objects found');
            }
        } else {
            logMessage($output, "$classTablename table not found");
        }
    } else {
        logMessage($output, "$shortClassName class not found");
    }
}

(new SingleCommandApplication())
    ->setName('Update encryption key script V2') // Optional
    ->setVersion('2.0.0') // Optional
    ->addArgument('force', InputArgument::OPTIONAL, 'Force reset EncryptionKeyIsUpdatedV2 flag for all objects')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $force = $input->getArgument('force');

        // update encrypted data for classes
        $objects = [
            "\Aurora\Modules\Mail\Models\MailAccount" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Fetcher" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Server" => ['SmtpPassword'],
            "\Aurora\Modules\StandardAuth\Models\Account" => ['Password'],
            "\Aurora\Modules\Core\Models\User" => ['TwoFactorAuth::BackupCodes', 'TwoFactorAuth::Secret', 'IframeAppWebclient::Password']
        ];

        foreach ($objects as $class => $props) {
            processObject($class, $props, $input, $output, $helper, $force);
            logMessage($output, "");
        }

        // update encrypted data in configs
        $question = new ConfirmationQuestion('Update encrypted data in config files? [no]', false);
        if ($helper->ask($input, $output, $question)) {
            $settings = [
                'CpanelIntegrator' => 'CpanelPassword',
                'LdapChangePasswordPlugin' => 'BindPassword',
                'MailChangePasswordFastpanelPlugin' => 'FastpanelAdminPass',
                'MailChangePasswordHmailserverPlugin' => 'AdminPass',
                'MailChangePasswordIredmailPlugin' => 'DbPass',
                'MailChangePasswordIspconfigPlugin' => 'DbPass',
                'MailChangePasswordIspmanagerPlugin' => 'ISPmanagerPass',
                'MailChangePasswordVirtualminPlugin' => 'VirtualminAdminPass',
                'MailSignupDirectadmin' => 'AdminPassword',
                'MailSignupFastpanel' => 'FastpanelAdminPass',
                'MailSignupPlesk' => 'PleskAdminPassword',
                'RocketChatWebclient' => 'AdminPassword',
                'StandardResetPassword' => 'NotificationPassword',
                'TeamContactsLdap' => 'BindPassword',
            ];

            foreach ($settings as $moduleName => $configName) {
                updateEncryptedConfig($moduleName, $configName, $output);
            }
            logMessage($output, "");
        }
    })->run();