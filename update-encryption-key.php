<?php

if (PHP_SAPI !== 'cli') {
    exit("Use the console for running this script");
}

include_once 'system/autoload.php';

use Aurora\Api;
use Aurora\Modules\Mail\Models\Fetcher;
use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Models\Server;
use Aurora\Modules\StandardAuth\Models\Account;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Database\Capsule\Manager as Capsule;

Api::Init();

function updatePassword($class, $passwordPropName, $oldSalt, $newSalt, $count, $output) {
    $progressBar = new ProgressBar($output, $count);
    $progressBar->setFormat('verbose');
    $progressBar->setBarCharacter('<info>=</info>');

    $progressBar->start();
    $aSkipedAccounts = [];
    $class::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->chunk(10000, (function ($items) use ($passwordPropName, $oldSalt, $newSalt, $progressBar, &$aSkipedAccounts) {
        foreach ($items as $item) {
            Api::$sSalt = $oldSalt;
            $passsword = $item->{$passwordPropName};

            if ($passsword) {
                Api::$sSalt = $newSalt;
                $item->{$passwordPropName} = $passsword;

                $item->setExtendedProp('SaltIsUpdated', true);

                if ($item->save()) {
                    $progressBar->advance();
                } else {
                    // log
                }
            } else {
                $aSkipedAccounts[] = $item->Id;
            }
        }
    }));
    $progressBar->finish();
    $output->writeln('');
    if ($aSkipedAccounts) {
        $output->writeln('Can\'t read password for ' . $class . ': ' . implode(', ', $aSkipedAccounts));
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

        $output->writeln('Old salt: ' . $oldSalt);
        $output->writeln('New salt: ' . $newSalt);

        if (class_exists("\Aurora\Modules\Mail\Models\MailAccount")) {

            if ($force) {
                MailAccount::where('Properties->SaltIsUpdated', true)->update(['Properties->SaltIsUpdated' => false]);
            }

            $output->writeln('Process mail accounts');
            $allMailAccountsCount = MailAccount::count();
            $mailAccountsCount = MailAccount::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->count();
            
            $output->writeln($allMailAccountsCount . ' mail account(s) found, ' . $mailAccountsCount . ' of them have not yet been updated');
            if ($mailAccountsCount > 0) {
                $question = new ConfirmationQuestion('Update the password for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updatePassword(MailAccount::class, 'IncomingPassword', $oldSalt, $newSalt, $mailAccountsCount, $output);
                }
            } else {
                $output->writeln('No mail accounts found');
            }
        } else {
            $output->writeln('MailAccount class not found');
        }

        if (class_exists("\Aurora\Modules\Mail\Models\Fetcher") && Capsule::schema()->hasTable((new Fetcher())->getTable())) {
            if ($force) {
                Fetcher::where('Properties->SaltIsUpdated', true)->update(['Properties->SaltIsUpdated' => false]);
            }

            $output->writeln('Process fetchers');
            $allFetchersCount = Fetcher::count();
            $fetchersCount = Fetcher::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->count();
            $output->writeln($allFetchersCount . ' fetcher(s) found, ' . $fetchersCount . ' of them have not yet been updated');
            if ($fetchersCount > 0) {
                $question = new ConfirmationQuestion('Update the password for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updatePassword(Fetcher::class, 'IncomingPassword', $oldSalt, $newSalt, $fetchersCount, $output);
                }
            } else {
                $output->writeln('No fetchers found');
            }
        } else {
            $output->writeln('Fetcher class not found');
        }

        if (class_exists("\Aurora\Modules\Mail\Models\Server")) {

            if ($force) {
                Server::where('Properties->SaltIsUpdated', true)->update(['Properties->SaltIsUpdated' => false]);
            }

            $output->writeln('Process servers');
            $allServersCount = Server::count();
            $serversCount = Server::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->count();
            $output->writeln($allServersCount . ' server(s) found, ' . $serversCount . ' of them have not yet been updated');
            if ($serversCount > 0) {
                $question = new ConfirmationQuestion('Update the password for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updatePassword(Server::class, 'SmtpPassword', $oldSalt, $newSalt, $serversCount, $output);
                }
            } else {
                $output->writeln('No servers found');
            }
        } else {
            $output->writeln('Server class not found');
        }

        if (class_exists("\Aurora\Modules\StandardAuth\Models\Account")) {

            if ($force) {
                Account::where('Properties->SaltIsUpdated', true)->update(['Properties->SaltIsUpdated' => false]);
            }

            $output->writeln('Process standard auth accounts');
            $allAccountsCount = Account::count();
            $accountsCount = Account::where('Properties->SaltIsUpdated', false)->orWhere('Properties->SaltIsUpdated', null)->count();
            $output->writeln($allAccountsCount . ' account(s) found, ' . $accountsCount . ' of them have not yet been updated');
            if ($accountsCount > 0) {
                $question = new ConfirmationQuestion('Update the password for them? [yes]', true);
                if ($helper->ask($input, $output, $question)) {
                    updatePassword(Account::class, 'Password', $oldSalt, $newSalt, $accountsCount, $output);
                }
            } else {
                $output->writeln('No standard auth accounts found');
            }
        } else {
            $output->writeln('Account class not found');
        }

        if (file_exists($bakSaltPath)) {
            $question = new ConfirmationQuestion('Remove backup salt-file? [no]', false);
            if ($helper->ask($input, $output, $question)) {
                unlink($bakSaltPath);
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