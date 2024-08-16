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

Api::Init();

function updateEncryptedProp($class, $shortClassName, $propNames, $oldSalt, $newSalt, $count, $output) {
    $progressBar = new ProgressBar($output, $count);
    $progressBar->setFormat('verbose');
    $progressBar->setBarCharacter('<info>=</info>');

    $progressBar->start();
    $aSkipedProps = [];

    if (!is_array($propNames)) {
        $propNames = [$propNames];
    }
    $class::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->chunk(10000, function ($items) use ($propNames, $oldSalt, $newSalt, $progressBar, &$aSkipedProps) {
        foreach ($items as $item) {
            foreach ($propNames as $propName) {
                Api::$sSalt = $oldSalt;
                $propValue = $item->{$propName};
                if ($propValue) {
                    $decryptedValue = \Aurora\System\Utils::DecryptValue($propValue);

                    Api::$sSalt = $newSalt;
                    if ($decryptedValue) {
                        $item->{$propName} = \Aurora\System\Utils::EncryptValue($decryptedValue);
                    } else {
                        $item->{$propName} = $propValue;
                    }
                } else {
                    if (!isset($aSkipedProps[$propName])) {
                        $aSkipedProps[$propName] = [];
                    }
                    if (!in_array($item->Id, $aSkipedProps[$propName])) {
                        $aSkipedProps[$propName][] = $item->Id;
                    }
                }
            }
            $item->setExtendedProp('SaltIsUpdated', true);
            if ($item->save()) {
                $progressBar->advance();
            } else {
                // log
            }
        }
    });
    $progressBar->finish();
    $output->writeln('');
    if ($aSkipedProps) {
        foreach ($aSkipedProps as $propName => $ids) {
            if (count($ids) === 0) {
                unset($aSkipedProps[$propName]);
            }
        }
        $output->writeln("Can't read encrypted property for $shortClassName");
        foreach ($aSkipedProps as $propName => $ids) {
            $output->writeln('Prop name: ' . $propName);
            $output->writeln($shortClassName . ' ids: ' . implode(', ', $ids));
        }
    }
}

function updateEncryptedConfig($moduleName, $configName, $oldSalt, $newSalt, $output) {
    $output->write("$moduleName->$configName: ");
    $configValue = Api::$oModuleManager->getModuleConfigValue($moduleName, $configName);
    if ($configValue) {
        Api::$sSalt = $oldSalt;
        $value = \Aurora\System\Utils::DecryptValue($configValue);

        if ($value) {
            Api::$sSalt = $newSalt;
            $value = \Aurora\System\Utils::EncryptValue($value);
            Api::$oModuleManager->setModuleConfigValue($moduleName, $configName, $value);
            Api::$oModuleManager->saveModuleConfigValue($moduleName);

            $output->writeln("Config updated");
        } else {
            $output->writeln("Can't decrypt config value");
        }
    } else {
        $output->writeln("Config not found");
    }
}

function processObject($class, $props, $oldSalt, $newSalt, $input, $output, $helper, $force) {

    $shortClassName = (new \ReflectionClass($class))->getShortName();

    $output->writeln("Process $shortClassName objects");
    if (class_exists($class)) {
        if ($force) {
            $class::where('Properties->SaltIsUpdated', true)->update(['Properties->SaltIsUpdated' => false]);
        }

        $allObjectsCount = $class::count();
        $objectsCount = $class::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->count();

        $output->writeln($allObjectsCount . ' object(s) found, ' . $objectsCount . ' of them have not yet been updated');
        if ($objectsCount > 0) {
            $question = new ConfirmationQuestion('Update encrypted properties for them? [yes]', true);
            if ($helper->ask($input, $output, $question)) {
                updateEncryptedProp($class, $shortClassName, $props, $oldSalt, $newSalt, $objectsCount, $output);
            }
        } else {
            $output->writeln('No objects found');
        }
    } else {
        $output->writeln("$shortClassName class not found");
    }
}

(new SingleCommandApplication())
    ->setName('Update salt script') // Optional
    ->setVersion('1.0.0') // Optional
    ->addArgument('force', InputArgument::OPTIONAL, 'Force reset SaltIsUpdated flag for all objects')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $helper = $this->getHelper('question');
        $force = $input->getArgument('force');

        $bakSaltPath = Api::DataPath() . '/salt8.bak.php';
        if (file_exists($bakSaltPath)) {
            $newSalt = Api::$sSalt;
            include $bakSaltPath;
            $oldSalt = Api::$sSalt;
            include(Api::GetSaltPath());
        } elseif (file_exists(Api::GetSaltPath())) {
            $oldSalt = Api::$sSalt;

            $systemUser = fileowner(Api::GetSaltPath());
            $systemUser = is_numeric($systemUser) ? posix_getpwuid($systemUser)['name'] : $systemUser;        
            $question = new Question('Please enter the owner name for the new salt file [' . $systemUser . ']:', $systemUser);
            $systemUser = $helper->ask($input, $output, $question);

            rename(Api::GetSaltPath(), $bakSaltPath);
            Api::InitSalt();
            chown(Api::GetSaltPath(), $systemUser);
            include(Api::GetSaltPath());
            $newSalt = Api::$sSalt;
        } else {
            $output->writeln('Salt file not foud');
        }

        $output->writeln("Old salt: $oldSalt");
        $output->writeln("New salt: $newSalt");
        $output->writeln("");

        // update encrypted data for classes
        $objects = [
            "\Aurora\Modules\Mail\Models\MailAccount" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Fetcher" => ['IncomingPassword'],
            "\Aurora\Modules\Mail\Models\Server" => ['SmtpPassword'],
            "\Aurora\Modules\StandardAuth\Models\Account" => ['Password'],
            "\Aurora\Modules\Core\Models\User" => ['TwoFactorAuth::BackupCodes', 'TwoFactorAuth::Secret']
        ];

        foreach ($objects as $class => $props) {
            processObject($class, $props, $oldSalt, $newSalt, $input, $output, $helper, $force);
            $output->writeln("");
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
                updateEncryptedConfig($moduleName, $configName, $oldSalt, $newSalt, $output);
            }
            $output->writeln("");
        }

        if (file_exists($bakSaltPath)) {
            $question = new ConfirmationQuestion('Remove backup salt-file? [no]', false);
            if ($helper->ask($input, $output, $question)) {
                unlink($bakSaltPath);
                $output->writeln("");
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
                $output->writeln('Superadmin password was set successfully!');
            } else {
                $output->writeln('Can\'t save superadmin password.');
            }
        }
    })->run();