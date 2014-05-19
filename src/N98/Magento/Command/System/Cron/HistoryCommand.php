<?php

namespace N98\Magento\Command\System\Cron;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use N98\Util\Console\Helper\Table\Renderer\RendererFactory;

class HistoryCommand extends AbstractMagentoCommand
{
    /**
     * @var array
     */
    protected $infos;

    protected function configure()
    {
        $this
            ->setName('sys:cron:history')
            ->setDescription('Last executed cronjobs with status.')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output Format. One of [' . implode(',', RendererFactory::getFormats()) . ']'
            )
        ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output, true);

        if ($input->getOption('format') === null) {
            $this->writeSection($output, 'Last executed jobs');
        }
        $this->initMagento();

        $collection = \Mage::getModel('cron/schedule')->getCollection();
        $collection->addFieldToFilter('status', array('neq' => \Mage_Cron_Model_Schedule::STATUS_PENDING))
                   ->addOrder('finished_at', \Varien_Data_Collection_Db::SORT_ORDER_DESC);

        $table = array();
        foreach ($collection as $job) {
            $table[] = array(
                $job->getJobCode(),
                $job->getStatus(),
                $job->getFinishedAt(),
            );
        }

        $this->getHelper('table')
            ->setHeaders(array('Job', 'Status', 'Finished'))
            ->renderByFormat($output, $table, $input->getOption('format'));
    }
}