<?php

namespace N98\Magento\Command\Database;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends AbstractDatabaseCommand
{
    protected function configure()
    {
        $this
            ->setName('db:import')
            ->addArgument('filename', InputArgument::OPTIONAL, 'Dump filename')
            ->addOption('compression', 'c', InputOption::VALUE_REQUIRED, 'The compression of the specified file')
            ->addOption('only-command', null, InputOption::VALUE_NONE, 'Print only mysql command. Do not execute')
            ->addOption('only-if-empty', null, InputOption::VALUE_NONE, 'Imports only if database is empty')
            ->addOption('optimize', null, InputOption::VALUE_NONE, 'Convert verbose INSERTs to short ones before import (not working with compression)')
            ->setDescription('Imports database with mysql cli client according to database defined in local.xml');

        $help = <<<HELP
Imports an SQL file with mysql cli client into current configured database.

You need to have MySQL client tools installed on your system.
HELP;
        $this->setHelp($help);

    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return function_exists('exec');
    }

    /**
     * Optimize a dump by converting single INSERTs per line to INSERTs with multiple lines
     * @param $fileName
     * @return string temporary filename
     */
    protected function optimize($fileName)
    {
        $in = fopen($fileName,'r');
        $result = tempnam(sys_get_temp_dir(),'dump') . '.sql';
        $out = fopen($result, 'w');

        $current_table = '';
        while($line = fgets($in)) {
            if (strtolower(substr($line, 0, 11)) == 'insert into') {
                preg_match('/^insert into `(.*)` \(.*\) values (.*);/i', $line, $m);
                $table = $m[1];
                $values = $m[2];

                if ($table != $current_table) {
                    if ($current_table != '') {
                        fwrite($out, ";\n\n");
                    }
                    $current_table = $table;
                    fwrite($out, 'INSERT INTO `' . $table . '` VALUES ' . $values);
                } else {
                    fwrite($out, ',' . $values);
                }
            } else {
                if ($current_table != '') {
                    fwrite($out, ";\n");
                    $current_table = '';
                }
                fwrite($out, $line);
            }

        }
        fclose($in);
        fclose($out);
        return $result;

    }
    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectDbSettings($output);
        $this->writeSection($output, 'Import MySQL Database');

        $fileName = $this->checkFilename($input);

        if ($input->getOption('optimize')) {
            if ($input->getOption('compression')) {
                throw new \Exception('Options --compression and --optimize are not compatible');
            }
            $output->writeln('<comment>Optimizing <info>' . $fileName . '</info> to temporary file');
            $fileName = $this->optimize($fileName);
        }

        $compressor = $this->getCompressor($input->getOption('compression'));

        // create import command
        $exec = $compressor->getDecompressingCommand(
            'mysql ' . $this->getHelper('database')->getMysqlClientToolConnectionString(),
            $fileName
        );

        if ($input->getOption('only-command')) {
            $output->writeln($exec);
        } else {
            if ($input->getOption('only-if-empty')
                && count($this->getHelper('database')->getTables()) > 0
            ) {
                $output->writeln('<comment>Skip import. Database is not empty</comment>');

                return;
            }

            $this->doImport($output, $fileName, $exec);
        }
        if ($input->getOption('optimize')) {
            unlink($fileName);
        }
    }

    public function asText() {
        return parent::asText() . "\n" .
            $this->getCompressionHelp();
    }

    /**
     * @param InputInterface $input
     *
     * @return mixed
     * @throws \InvalidArgumentException
     */
    protected function checkFilename(InputInterface $input)
    {
        $fileName = $input->getArgument('filename');
        if (!file_exists($fileName)) {
            throw new \InvalidArgumentException('File does not exist');
        }
        return $fileName;
    }

    /**
     * @param OutputInterface $output
     * @param string          $fileName
     * @param string          $exec
     *
     * @return void
     */
    protected function doImport(OutputInterface $output, $fileName, $exec)
    {
        $returnValue = null;
        $commandOutput = null;
        $output->writeln(
            '<comment>Importing SQL dump <info>' . $fileName . '</info> to database <info>'
            . $this->dbSettings['dbname'] . '</info>'
        );
        exec($exec, $commandOutput, $returnValue);
        if ($returnValue > 0) {
            $output->writeln('<error>' . implode(PHP_EOL, $commandOutput) . '</error>');
        }
        $output->writeln('<info>Finished</info>');
    }
}