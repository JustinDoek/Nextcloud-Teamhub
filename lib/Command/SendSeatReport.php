<?php
declare(strict_types=1);

namespace OCA\TeamHub\Command;

use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\SeatReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ teamhub:seat-report — send a metered licence's member count now.
 *
 * TeamHub's first occ command (v4.13.30). Registered in appinfo/info.xml
 * <commands>.
 *
 * WHY A COMMAND RATHER THAN A BUTTON
 * ----------------------------------
 * The License tab briefly had a "Send report now" button. Justin removed it
 * (2026-10-26): the member count is a billing matter between the instance's
 * operator and us, and a button inviting a team administrator to send it makes
 * it look like something they are meant to manage. The panel still *shows*
 * what was sent and when, because that is the assurance an administrator and
 * their DPO need, but triggering a send is an operator action and the shell is
 * where operator actions live.
 *
 * It exists at all because the alternative way to find out whether a metered
 * instance can reach the licensing service is to wait up to twelve hours for
 * SeatReportJob and then read a log — and the overdue ladder gives an
 * administrator 45 days before Advanced features lock.
 *
 * SAFE TO RUN REPEATEDLY
 * ----------------------
 * The receiver is idempotent per (licence, period) and answers
 * `already_recorded` rather than revising a figure, so running this twice in a
 * period changes nothing. "The month's latest snapshot" means the latest
 * period, not the latest POST.
 *
 * It does not bypass the licence conditions: a banded licence, an unlicensed
 * instance or one expired past its grace window is refused with the reason.
 */
class SendSeatReport extends Command {

	public function __construct(
		private SeatReportService $seatReportService,
		private LicenseService    $licenseService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('teamhub:seat-report')
			->setDescription('Send this instance\'s effective-member report to the TeamHub licensing service (metered licences only)')
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Show what would be sent and whether a report is due, without sending anything',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$status = $this->licenseService->getStatus();

		if (!LicenseService::isMetered($status['kind'] ?? null)) {
			// Not an error: a banded licence is a supported, sold product that
			// reports nothing. Saying so plainly stops an operator hunting for
			// a misconfiguration that is not there.
			$output->writeln('<comment>This instance does not have a metered licence, so it does not report member counts.</comment>');
			$output->writeln('  Licence kind: <info>' . ($status['kind'] ?? 'none') . '</info>');
			$output->writeln('  A banded licence is charged on its seat band and makes no outbound call.');
			return 0;
		}

		$output->writeln('Licence:        <info>' . ($status['licenseId'] ?? '-') . '</info>'
			. ($status['isTrial'] ? ' <comment>(trial)</comment>' : ''));
		$output->writeln('Instance:       <info>' . ($status['instanceUuid'] ?? '-') . '</info>');
		$output->writeln('Members now:    <info>' . ($status['seatsUsed'] ?? 0) . '</info>');
		$output->writeln('Period:         <info>' . SeatReportService::periodFor(time()) . '</info>');
		$output->writeln('Reporting:      <info>' . ($status['reportState'] ?? '-') . '</info>'
			. (($status['reportOverdueDays'] ?? 0) > 0
				? ' (' . $status['reportOverdueDays'] . ' days since the last accepted report)'
				: ''));
		if (!empty($status['reportLastPeriod'])) {
			$output->writeln('Last reported:  <info>' . $status['reportLastPeriod'] . '</info>');
		}
		if (!empty($status['reportLastError'])) {
			$output->writeln('Last error:     <comment>' . $status['reportLastError'] . '</comment>');
		}

		if ($input->getOption('dry-run')) {
			$due = ($status['reportLastPeriod'] ?? null) !== SeatReportService::periodFor(time());
			$output->writeln('');
			$output->writeln($due
				? '<comment>Dry run: a report is due for this period and nothing was sent.</comment>'
				: '<info>Dry run: this period has already been reported and nothing was sent.</info>');
			$output->writeln('Endpoint:       ' . $this->seatReportService->reportUrl());
			return 0;
		}

		$output->writeln('');
		$output->writeln('Sending to ' . $this->seatReportService->reportUrl() . ' ...');

		try {
			$fresh = $this->seatReportService->reportNow();
		} catch (\RuntimeException $e) {
			// reportNow() raises this only for "this licence may not report",
			// which the isMetered() check above already covers — so reaching
			// here means the licence is metered but no longer honoured.
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$error = $fresh['reportLastError'] ?? null;
		if ($error !== null) {
			// The transport failed. SeatReportService recorded the reason
			// rather than throwing, because a background send must never take
			// down the job runner — so the exit code is this method's job.
			$output->writeln('<error>The report was not accepted: ' . $error . '</error>');
			return 1;
		}

		$output->writeln('<info>Report accepted.</info>');
		$output->writeln('  Period:   <info>' . ($fresh['reportLastPeriod'] ?? '-') . '</info>');
		$payload = $fresh['reportLastPayload'] ?? null;
		if (is_array($payload)) {
			// Printed back verbatim, the way the License tab prints it: what
			// left the box is shown rather than described.
			$output->writeln('  Members:  <info>' . ($payload['effective_members'] ?? '-') . '</info>');
			$output->writeln('  Sent:     ' . (string)json_encode($payload));
		}
		if (!empty($fresh['reportNextDueAt'])) {
			$output->writeln('  Next due: ' . gmdate('Y-m-d', (int)$fresh['reportNextDueAt']) . ' (UTC)');
		}

		return 0;
	}
}
