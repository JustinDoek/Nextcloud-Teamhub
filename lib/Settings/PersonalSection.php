<?php
declare(strict_types=1);

namespace OCA\TeamHub\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Registers the "TeamHub" section in NC Settings → Personal.
 * Appears alongside other apps' personal settings sections.
 */
class PersonalSection implements IIconSection {

    public function __construct(
        private IL10N         $l,
        private IURLGenerator $urlGenerator,
    ) {}

    public function getID(): string {
        return 'teamhub';
    }

    public function getName(): string {
        return $this->l->t('TeamHub');
    }

    public function getPriority(): int {
        return 55;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('teamhub', 'app-dark.svg');
    }
}
