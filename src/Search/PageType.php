<?php

namespace App\Search;

enum PageType: string
{
    case Pillar = 'pillar';

    case PillarCommercial = 'pillar_commercial';
    case Definition = 'definition';
    case HowTo = 'howto';
    case News = 'news';

    case Comparison = 'comparison';

    case Category = 'category';
    case Product = 'product';
    case Service = 'service';
    case Local = 'local';
    case Job = 'job';

    case MajorSection = 'major_section';

    case RegulationSection = 'regulation_section';

    case CommercialSection = 'commercial_section';
    case MinorCommercialSection = 'minor_commercial_section';
    case FaqQuestion = 'faq_question';
    case Mention = 'mention';

    /** @return 'informational'|'commercial'|'transactional'|'section' */
    public function getFamily(): string
    {
        return match ($this) {
            self::Pillar, self::Definition, self::HowTo, self::News, self::Job => 'informational',
            self::PillarCommercial, self::Comparison, self::CommercialSection, self::MinorCommercialSection => 'commercial',
            self::Category, self::Product, self::Service, self::Local => 'transactional',
            self::MajorSection, self::RegulationSection, self::FaqQuestion, self::Mention => 'section',
        };
    }

    public function getIcon(): string
    {
        return ['informational' => 'layers', 'commercial' => 'tag', 'transactional' => 'target', 'section' => 'list'][$this->getFamily()];
    }

    public function isMother(): bool
    {
        return \in_array($this, [self::Pillar, self::Job], true) || (!$this->isSection() && \in_array($this->getFamily(), ['commercial', 'transactional'], true));
    }

    public function isCompetitive(): bool
    {
        return $this->isMother() && 'informational' !== $this->getFamily();
    }

    public function isSection(): bool
    {
        return \in_array($this, [self::MajorSection, self::RegulationSection, self::CommercialSection, self::MinorCommercialSection, self::FaqQuestion, self::Mention], true);
    }
}
