<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\Core\Command\Maintenance;

use Exception;
use OC\Repair\Events\RepairAdvanceEvent;
use OC\Repair\Events\RepairErrorEvent;
use OC\Repair\Events\RepairFinishEvent;
use OC\Repair\Events\RepairInfoEvent;
use OC\Repair\Events\RepairStartEvent;
use OC\Repair\Events\RepairStepEvent;
use OC\Repair\Events\RepairWarningEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Repair extends Command {
	private ProgressBar $progress;
	private OutputInterface $output;
	protected bool $errored = false;

	public function __construct(
		protected \OC\Repair $repair,
		protected IConfig $config,
		private IEventDispatcher $dispatcher,
		private IAppManager $appManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('maintenance:repair')
			->setDescription('repair this installation')
			->addOption(
				'include-expensive',
				null,
				InputOption::VALUE_NONE,
				'Use this option when you want to include resource and load expensive tasks');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$repairSteps = $this->repair::getRepairSteps();

		if ($input->getOption('include-expensive')) {
			$repairSteps = array_merge($repairSteps, $this->repair::getExpensiveRepairSteps());
		}

		foreach ($repairSteps as $step) {
			$this->repair->addStep($step);
		}

		$apps = $this->appManager->getEnabledApps();
		foreach ($apps as $app) {
			if (!$this->appManager->isEnabledForUser($app)) {
				continue;
			}
			$info = $this->appManager->getAppInfo($app);
			if (!is_array($info)) {
				continue;
			}
			$this->appManager->loadApp($app);
			$steps = $info['repair-steps']['post-migration'];
			foreach ($steps as $step) {
				try {
					$this->repair->addStep($step);
				} catch (Exception $ex) {
					$output->writeln("<error>Failed to load repair step for $app: {$ex->getMessage()}</error>");
				}
			}
		}



		$maintenanceMode = $this->config->getSystemValueBool('maintenance');
		$this->config->setSystemValue('maintenance', true);

		$this->progress = new ProgressBar($output);
		$this->output = $output;
		$this->dispatcher->addListener(RepairStartEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairAdvanceEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairFinishEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairStepEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairInfoEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairWarningEvent::class, [$this, 'handleRepairFeedBack']);
		$this->dispatcher->addListener(RepairErrorEvent::class, [$this, 'handleRepairFeedBack']);

		$this->repair->run();

		$this->config->setSystemValue('maintenance', $maintenanceMode);
		return $this->errored ? 1 : 0;
	}

	public function handleRepairFeedBack(Event $event): void {
		if ($event instanceof RepairStartEvent) {
			$this->progress->start($event->getMaxStep());
		} elseif ($event instanceof RepairAdvanceEvent) {
			$this->progress->advance($event->getIncrement());
		} elseif ($event instanceof RepairFinishEvent) {
			$this->progress->finish();
			$this->output->writeln('');
		} elseif ($event instanceof RepairStepEvent) {
			$this->output->writeln('<info> - ' . $event->getStepName() . '</info>');
		} elseif ($event instanceof RepairInfoEvent) {
			$this->output->writeln('<info>     - ' . $event->getMessage() . '</info>');
		} elseif ($event instanceof RepairWarningEvent) {
			$this->output->writeln('<comment>     - WARNING: ' . $event->getMessage() . '</comment>');
		} elseif ($event instanceof RepairErrorEvent) {
			$this->output->writeln('<error>     - ERROR: ' . $event->getMessage() . '</error>');
			$this->errored = true;
		}
	}
}
