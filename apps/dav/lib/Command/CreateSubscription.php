<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Command;

use OC\KnownUser\KnownUserService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Proxy\ProxyMapper;
use OCA\DAV\CalDAV\Sharing\Backend;
use OCA\DAV\Connector\Sabre\Principal;
use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Xml\Property\Href;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSubscription extends Command {
	public function __construct(
		protected IUserManager $userManager,
		private IGroupManager $groupManager,
		protected IDBConnection $dbConnection,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('dav:create-subscription')
			->setDescription('Create a dav subscription')
			->addArgument('user',
				InputArgument::REQUIRED,
				'User for whom the subscription will be created')
			->addArgument('name',
				InputArgument::REQUIRED,
				'Name of the subscription to create')
			->addArgument('url',
				InputArgument::REQUIRED,
				'Source url of the subscription to create')
			->addArgument('color',
				InputArgument::OPTIONAL,
				'Hex color code for the calendar color');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getArgument('user');
		if (!$this->userManager->userExists($user)) {
			$output->writeln("<error>User <$user> in unknown.</error>");
			return self::FAILURE;
		}

		$name = $input->getArgument('name');
		$url = $input->getArgument('url');
		$color = $input->getArgument('color') ?? '#0082c9';

		$principalBackend = new Principal(
			$this->userManager,
			$this->groupManager,
			\OC::$server->get(IAccountManager::class),
			\OC::$server->getShareManager(),
			\OC::$server->getUserSession(),
			\OC::$server->getAppManager(),
			\OC::$server->query(ProxyMapper::class),
			\OC::$server->get(KnownUserService::class),
			\OC::$server->getConfig(),
			\OC::$server->getL10NFactory(),
		);
		$random = \OC::$server->getSecureRandom();
		$logger = \OC::$server->get(LoggerInterface::class);
		$dispatcher = \OC::$server->get(IEventDispatcher::class);
		$config = \OC::$server->get(IConfig::class);
		$caldav = new CalDavBackend(
			$this->dbConnection,
			$principalBackend,
			$this->userManager,
			$random,
			$logger,
			$dispatcher,
			$config,
			\OC::$server->get(Backend::class),
		);

		$subscriptions = $caldav->getSubscriptionsForUser("principals/users/$user");

		$exists = array_filter($subscriptions, function ($row) use ($url) {
			return $row['source'] === $url;
		});

		if (!empty($exists)) {
			$output->writeln("<error>Subscription for url <$url> already exists for this user.</error>");
			return self::FAILURE;
		}

		$urlProperty = new Href($url);
		$properties = ['{http://owncloud.org/ns}calendar-enabled' => 1,
			'{DAV:}displayname' => $name,
			'{http://apple.com/ns/ical/}calendar-color' => $color,
			'{http://calendarserver.org/ns/}source' => $urlProperty,
		];
		$caldav->createSubscription("principals/users/$user", $name, $properties);
		return self::SUCCESS;
	}

}
