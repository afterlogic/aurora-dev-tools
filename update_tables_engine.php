<?php

if (PHP_SAPI !== 'cli') {
    exit("Use the console for running this script");
}

use Aurora\Api;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\SingleCommandApplication;
use Illuminate\Database\Capsule\Manager as Capsule;

include_once __DIR__ . '/../system/autoload.php';

Api::Init();

(new SingleCommandApplication('update-tables-engine'))
    ->setDescription('Converts all database tables to InnoDB engine')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $conn = Capsule::connection();
        $dbName = $conn->getDatabaseName();

        $tables = $conn->select(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
            [$dbName]
        );

        $needsFixing = [];
        foreach ($tables as $row) {
            if ($row->ENGINE !== 'InnoDB') {
                $needsFixing[] = $row->TABLE_NAME;
                $output->writeln(sprintf(
                    '  <comment>%s</comment> — currently <comment>%s</comment>',
                    $row->TABLE_NAME,
                    $row->ENGINE
                ));
            }
        }

        if (empty($needsFixing)) {
            $output->writeln('<info>All tables already use InnoDB engine.</info>');
            return 0;
        }

        $output->writeln(sprintf(
            '<comment>%d table(s) need ENGINE conversion to InnoDB.</comment>',
            count($needsFixing)
        ));

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            sprintf('Proceed with converting %d table(s) to InnoDB? [y/N] ', count($needsFixing)),
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Aborted.</comment>');
            return 0;
        }

        $failures = [];
        foreach ($needsFixing as $tableName) {
            $escaped = '`' . str_replace('`', '``', $tableName) . '`';
            try {
                $conn->statement("ALTER TABLE {$escaped} ENGINE = InnoDB");
                $output->writeln(sprintf('  <info>OK</info>  %s', $tableName));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>FAIL</error> %s — %s', $tableName, $e->getMessage()));
                $failures[] = $tableName;
            }
        }

        if (!empty($failures)) {
            $output->writeln(sprintf(
                '<error>%d table(s) failed to convert: %s</error>',
                count($failures),
                implode(', ', $failures)
            ));
            return 1;
        }

        $output->writeln('<info>All tables converted to InnoDB.</info>');
        return 0;
    })
    ->run();
