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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Database\Capsule\Manager as Capsule;

Api::Init();

function logMessage($output, $message) {
    $output->writeln($message);
    Api::Log($message, \Aurora\System\Enums\LogLevel::Full, 'update-encryption-key-');
}

function updateEncryptedProp($class, $shortClassName, $propNames, $oldEncryptionKey, $newEncryptionKey, $count, $output) {
    $progressBar = new ProgressBar($output, $count);
    $progressBar->setFormat('verbose');
    $progressBar->setBarCharacter('<info>=</info>');

    $progressBar->start();
    $aSkipedProps = [];

    if (!is_array($propNames)) {
        $propNames = [$propNames];
    }
    $class::where('Properties->EncryptionKeyIsUpdated', false)->orWhere('Properties->EncryptionKeyIsUpdated', null)->chunk(10000, function ($items) use ($propNames, $oldEncryptionKey, $newEncryptionKey, $progressBar, &$aSkipedProps) {
        foreach ($items as $item) {
            foreach ($propNames as $propName) {
                Api::$sEncryptionKey = $oldEncryptionKey;
                $propValue = $item->{$propName};
                if ($propValue) {
                    $decryptedValue = \Aurora\System\Utils::DecryptValue($propValue);

                    Api::$sEncryptionKey = $newEncryptionKey;
                    if ($decryptedValue) {
                        $item->{$propName} = \Aurora\System\Utils::EncryptValue($decryptedValue);
                    } else {
                        $item->{$propName} = $propValue;
                    }
                } elseif ($propValue !== null) {
                    if (!isset($aSkipedProps[$propName])) {
                        $aSkipedProps[$propName] = [];
                    }
                    if (!in_array($item->Id, $aSkipedProps[$propName])) {
                        $aSkipedProps[$propName][] = $item->Id;
                    }
                }
            }
            $item->setExtendedProp('EncryptionKeyIsUpdated', true);
            if ($item->save()) {
                $progressBar->advance();
            } else {
                // log
            }
        }
    });
    $progressBar->finish();
    logMessage($output, '');
    if ($aSkipedProps) {
        foreach ($aSkipedProps as $propName => $ids) {
            if (count($ids) === 0) {
                unset($aSkipedProps[$propName]);
            }
        }
        logMessage($output, "Can't read encrypted property for $shortClassName");
        foreach ($aSkipedProps as $propName => $ids) {
            logMessage($output, 'Prop name: ' . $propName);
            logMessage($output, $shortClassName . ' ids: ' . implode(', ', $ids));
        }
    }
}

function updateEncryptedConfig($moduleName, $configName, $oldEncryptionKey, $newEncryptionKey, $output) {
    if (Api::$oModuleManager->isModuleLoaded($moduleName)) {
        $output->write("$moduleName->$configName: ");
        $configValue = Api::$oModuleManager->getModuleConfigValue($moduleName, $configName);
        if ($configValue) {
            Api::$sEncryptionKey = $oldEncryptionKey;
            $value = \Aurora\System\Utils::DecryptValue($configValue);

            if ($value) {
                Api::$sEncryptionKey = $newEncryptionKey;
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

function processObject($class, $props, $oldEncryptionKey, $newEncryptionKey, $input, $output, $helper, $force) {

    $classParts = explode('\\', $class);
    $shortClassName = end($classParts);

    logMessage($output, "Process $shortClassName objects");

    if (class_exists($class)) {
        $classTablename = with(new $class)->getTable();
        if (Capsule::schema()->hasTable($classTablename)) {
            if ($force) {
                $class::where('Properties->EncryptionKeyIsUpdated', true)->update(['Properties->EncryptionKeyIsUpdated' => false]);
            }

            $allObjectsCount = $class::count();
            $objectsCount = $class::where('Properties->EncryptionKeyIsUpdated', false)->orWhere('Properties->EncryptionKeyIsUpdated', null)->count();

            logMessage($output, $allObjectsCount . ' object(s) found, ' . $objectsCount . ' of them have not yet been updated');
            if ($objectsCount > 0) {
                $question = new ConfirmationQuestion('Update encrypted properties for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updateEncryptedProp($class, $shortClassName, $props, $oldEncryptionKey, $newEncryptionKey, $objectsCount, $output);
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
    ->setName('Update encryption key script') // Optional
    ->setVersion('1.0.0') // Optional
    ->addArgument('force', InputArgument::OPTIONAL, 'Force reset EncryptionKeyIsUpdated flag for all objects')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $force = $input->getArgument('force');

        $encryptionKeyPath = Api::GetEncryptionKeyPath();
        $pathInfo = pathinfo($encryptionKeyPath);
        $bakEncryptionKeyPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.bak.' . $pathInfo['extension'];

        if (file_exists($bakEncryptionKeyPath)) {
            $newEncryptionKey = Api::$sEncryptionKey;
            include $bakEncryptionKeyPath;
            $oldEncryptionKey = Api::$sEncryptionKey;
            include(Api::GetEncryptionKeyPath());
        } elseif (file_exists($encryptionKeyPath)) {
            $oldEncryptionKey = Api::$sEncryptionKey;

            $systemUser = fileowner($encryptionKeyPath);
            $systemUser = (is_numeric($systemUser) && function_exists('posix_getpwuid')) ? posix_getpwuid($systemUser)['name'] : $systemUser;
            $question = new Question('Please enter the owner name for the new encryption key file [' . $systemUser . ']:', $systemUser);
            $systemUser = $helper->ask($input, $output, $question);

            rename($encryptionKeyPath, $bakEncryptionKeyPath);
            Api::InitEncryptionKey();
            if ($systemUser !== '') {
                chown($encryptionKeyPath, $systemUser);
            }
            include($encryptionKeyPath);
            $newEncryptionKey = Api::$sEncryptionKey;
        } else {
            logMessage($output, 'Encryption key file not found');
        }

        logMessage($output, "Old encryption key: $oldEncryptionKey");
        logMessage($output, "New encryption key: $newEncryptionKey");
        logMessage($output, "");

        // update encrypted data for classes
        $objects = [
            "\Aurora\Modules\Mail\Models\MailAccount" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Fetcher" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Server" => ['SmtpPassword'],
            "\Aurora\Modules\StandardAuth\Models\Account" => ['Password'],
            "\Aurora\Modules\Core\Models\User" => ['TwoFactorAuth::BackupCodes', 'TwoFactorAuth::Secret', 'IframeAppWebclient::Password']
        ];

        foreach ($objects as $class => $props) {
            processObject($class, $props, $oldEncryptionKey, $newEncryptionKey, $input, $output, $helper, $force);
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
                updateEncryptedConfig($moduleName, $configName, $oldEncryptionKey, $newEncryptionKey, $output);
            }
            logMessage($output, "");
        }

        if (file_exists($bakEncryptionKeyPath)) {
            $question = new ConfirmationQuestion('Remove backup encryption key file? [no]', false);
            if ($helper->ask($input, $output, $question)) {
                unlink($bakEncryptionKeyPath);
                logMessage($output, "");
            }
        }

        $question = new ConfirmationQuestion('Update superadmin password? [no]', false);
        if ($helper->ask($input, $output, $question)) {
            $oSettings = &Api::GetSettings();
            $sSuperadminPassword = '';
            $question = new Question('Please enter the new superadmin password: ', $sSuperadminPassword);
            $question->setHidden(true)->setHiddenFallback(false);
            $sSuperadminPassword = $helper->ask($input, $output, $question);
            $oSettings->AdminPassword = password_hash(trim($sSuperadminPassword), PASSWORD_BCRYPT);

            if ($oSettings->Save()) {
                logMessage($output, 'Superadmin password was set successfully!');
            } else {
                logMessage($output, 'Can\'t save superadmin password.');
            }
        }
    })->run();