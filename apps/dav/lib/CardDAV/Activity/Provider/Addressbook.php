<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\CardDAV\Activity\Provider;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IEventMerger;
use OCP\Activity\IManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class Addressbook extends Base {
	public const SUBJECT_ADD = 'addressbook_add';
	public const SUBJECT_UPDATE = 'addressbook_update';
	public const SUBJECT_DELETE = 'addressbook_delete';
	public const SUBJECT_SHARE_USER = 'addressbook_user_share';
	public const SUBJECT_SHARE_GROUP = 'addressbook_group_share';
	public const SUBJECT_UNSHARE_USER = 'addressbook_user_unshare';
	public const SUBJECT_UNSHARE_GROUP = 'addressbook_group_unshare';

	public function __construct(
		protected IFactory $languageFactory,
		IURLGenerator $url,
		protected IManager $activityManager,
		IUserManager $userManager,
		IGroupManager $groupManager,
		protected IEventMerger $eventMerger,
	) {
		parent::__construct($userManager, $groupManager, $url);
	}

	/**
	 * @param string $language
	 * @param IEvent $event
	 * @param IEvent|null $previousEvent
	 * @return IEvent
	 * @throws UnknownActivityException
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== 'dav' || $event->getType() !== 'contacts') {
			throw new UnknownActivityException();
		}

		$l = $this->languageFactory->get('dav', $language);

		if ($this->activityManager->getRequirePNG()) {
			$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/contacts-dark.png')));
		} else {
			$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/contacts.svg')));
		}

		if ($event->getSubject() === self::SUBJECT_ADD) {
			$subject = $l->t('{actor} created address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_ADD . '_self') {
			$subject = $l->t('You created address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_DELETE) {
			$subject = $l->t('{actor} deleted address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_DELETE . '_self') {
			$subject = $l->t('You deleted address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_UPDATE) {
			$subject = $l->t('{actor} updated address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_UPDATE . '_self') {
			$subject = $l->t('You updated address book {addressbook}');
		} elseif ($event->getSubject() === self::SUBJECT_SHARE_USER) {
			$subject = $l->t('{actor} shared address book {addressbook} with you');
		} elseif ($event->getSubject() === self::SUBJECT_SHARE_USER . '_you') {
			$subject = $l->t('You shared address book {addressbook} with {user}');
		} elseif ($event->getSubject() === self::SUBJECT_SHARE_USER . '_by') {
			$subject = $l->t('{actor} shared address book {addressbook} with {user}');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_USER) {
			$subject = $l->t('{actor} unshared address book {addressbook} from you');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_USER . '_you') {
			$subject = $l->t('You unshared address book {addressbook} from {user}');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_USER . '_by') {
			$subject = $l->t('{actor} unshared address book {addressbook} from {user}');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_USER . '_self') {
			$subject = $l->t('{actor} unshared address book {addressbook} from themselves');
		} elseif ($event->getSubject() === self::SUBJECT_SHARE_GROUP . '_you') {
			$subject = $l->t('You shared address book {addressbook} with group {group}');
		} elseif ($event->getSubject() === self::SUBJECT_SHARE_GROUP . '_by') {
			$subject = $l->t('{actor} shared address book {addressbook} with group {group}');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_GROUP . '_you') {
			$subject = $l->t('You unshared address book {addressbook} from group {group}');
		} elseif ($event->getSubject() === self::SUBJECT_UNSHARE_GROUP . '_by') {
			$subject = $l->t('{actor} unshared address book {addressbook} from group {group}');
		} else {
			throw new UnknownActivityException();
		}

		$parsedParameters = $this->getParameters($event, $l);
		$this->setSubjects($event, $subject, $parsedParameters);

		$event = $this->eventMerger->mergeEvents('addressbook', $event, $previousEvent);

		if ($event->getChildEvent() === null) {
			if (isset($parsedParameters['user'])) {
				// Couldn't group by calendar, maybe we can group by users
				$event = $this->eventMerger->mergeEvents('user', $event, $previousEvent);
			} elseif (isset($parsedParameters['group'])) {
				// Couldn't group by calendar, maybe we can group by groups
				$event = $this->eventMerger->mergeEvents('group', $event, $previousEvent);
			}
		}

		return $event;
	}

	protected function getParameters(IEvent $event, IL10N $l): array {
		$subject = $event->getSubject();
		$parameters = $event->getSubjectParameters();

		switch ($subject) {
			case self::SUBJECT_ADD:
			case self::SUBJECT_ADD . '_self':
			case self::SUBJECT_DELETE:
			case self::SUBJECT_DELETE . '_self':
			case self::SUBJECT_UPDATE:
			case self::SUBJECT_UPDATE . '_self':
			case self::SUBJECT_SHARE_USER:
			case self::SUBJECT_UNSHARE_USER:
			case self::SUBJECT_UNSHARE_USER . '_self':
				return [
					'actor' => $this->generateUserParameter($parameters['actor']),
					'addressbook' => $this->generateAddressbookParameter($parameters['addressbook'], $l),
				];
			case self::SUBJECT_SHARE_USER . '_you':
			case self::SUBJECT_UNSHARE_USER . '_you':
				return [
					'addressbook' => $this->generateAddressbookParameter($parameters['addressbook'], $l),
					'user' => $this->generateUserParameter($parameters['user']),
				];
			case self::SUBJECT_SHARE_USER . '_by':
			case self::SUBJECT_UNSHARE_USER . '_by':
				return [
					'actor' => $this->generateUserParameter($parameters['actor']),
					'addressbook' => $this->generateAddressbookParameter($parameters['addressbook'], $l),
					'user' => $this->generateUserParameter($parameters['user']),
				];
			case self::SUBJECT_SHARE_GROUP . '_you':
			case self::SUBJECT_UNSHARE_GROUP . '_you':
				return [
					'addressbook' => $this->generateAddressbookParameter($parameters['addressbook'], $l),
					'group' => $this->generateGroupParameter($parameters['group']),
				];
			case self::SUBJECT_SHARE_GROUP . '_by':
			case self::SUBJECT_UNSHARE_GROUP . '_by':
				return [
					'actor' => $this->generateUserParameter($parameters['actor']),
					'addressbook' => $this->generateAddressbookParameter($parameters['addressbook'], $l),
					'group' => $this->generateGroupParameter($parameters['group']),
				];
		}

		throw new \InvalidArgumentException();
	}
}
